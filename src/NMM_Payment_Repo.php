<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Payment_Repo {
	private $tableName;

	public function __construct() {
		global $wpdb;

		$this->tableName = $wpdb->prefix . NMM_PAYMENT_TABLE;
	}

	public function insert($address, $cryptocurrency, $orderId, $paymentAmount, $status, $hdAddress = '0') {
		NMM_Util::log(__FILE__, __LINE__, 'inserting ' . $address . ' into db as ' . $status . ' with order amount of: ' . $paymentAmount);
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"INSERT INTO `$this->tableName`
				(`address`, `cryptocurrency`, `order_id`, `order_amount`, `status`, `ordered_at`, `hd_address`) VALUES
				(%s, %s, %d, %s, %s, %d, %s)",
			$address, $cryptocurrency, $orderId, $paymentAmount, $status, time(), $hdAddress
		));
	}

	/**
	 * Unpaid payment rows. When $orderedBefore is given (a unix timestamp), only
	 * rows older than it are returned: WHERE status='unpaid' AND ordered_at < cutoff,
	 * which the unpaid_expiry(status, ordered_at) index serves as a range scan so
	 * the expiry cron never has to transfer and iterate every recent unpaid
	 * checkout. Passing null keeps the old "every unpaid row" behaviour.
	 */
	public function get_unpaid($orderedBefore = null) {
		global $wpdb;

		$select = "SELECT `address`,
						  `cryptocurrency`,
						  `order_id`,
						  `order_amount`,
						  `status`,
						  `ordered_at`
				   FROM `$this->tableName`
				   WHERE `status` = 'unpaid'";

		if ($orderedBefore === null) {
			return $wpdb->get_results($select, ARRAY_A);
		}

		return $wpdb->get_results($wpdb->prepare(
			$select . " AND `ordered_at` < %d",
			(int) $orderedBefore
		), ARRAY_A);
	}

	// Distinct cryptocurrencies among the unpaid rows. Cheap (index-only on the
	// status prefix, few distinct values) and lets the expiry cron compute the
	// shortest cancellation window it must consider before querying.
	public function get_distinct_unpaid_cryptos() {
		global $wpdb;

		return $wpdb->get_col("SELECT DISTINCT `cryptocurrency` FROM `$this->tableName` WHERE `status` = 'unpaid'");
	}

	public function get_distinct_unpaid_addresses() {
		global $wpdb;

		$results = $wpdb->get_results("SELECT DISTINCT `address`, `cryptocurrency` FROM `$this->tableName` WHERE `status` = 'unpaid'", ARRAY_A);

		if (!is_array($results)) {
			return array();
		}

		// Sort in PHP, byte-wise, so the order the cron's persisted fair-sweep
		// cursor relies on exactly matches the strcmp() comparison it resumes with
		// (see NMM_Payment::check_all_addresses_for_matching_payment). Doing it here
		// rather than in SQL avoids two traps: an `ORDER BY BINARY <expr>` not in
		// the SELECT DISTINCT list is rejected by MySQL 8 (error 3065), and a
		// collation-dependent SQL sort would not match the cursor comparison anyway.
		usort($results, function ($a, $b) {
			$cryptoCmp = strcmp($a['cryptocurrency'], $b['cryptocurrency']);

			return $cryptoCmp !== 0 ? $cryptoCmp : strcmp($a['address'], $b['address']);
		});

		return $results;
	}

	public function get_unpaid_for_address($cryptoId, $address) {
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT `cryptocurrency`,
					`order_id`,
					`order_amount`,
					`status`,
					`ordered_at`
			 FROM `$this->tableName`
			 WHERE `status` = 'unpaid'
			 AND `address` = %s
			 AND `cryptocurrency` = %s",
			$address, $cryptoId
		), ARRAY_A);

		return $results;
	}

	public function set_status($orderId, $orderAmount, $status) {
		global $wpdb;
		NMM_Util::log(__FILE__, __LINE__, 'updating ' . $orderId . ' to ' . $status);

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = %s
			 WHERE `order_amount` = %s
			 AND `order_id` = %d",
			$status, $orderAmount, $orderId
		));
	}

	// Tri-state result of a conditional claim. CLAIMED: this call moved the row.
	// ALREADY: the row was conclusively transitioned out of 'unpaid' by another
	// worker (cancelled or paid) - a definite, retry-free outcome. DB_ERROR: the
	// UPDATE failed, so the row state is UNKNOWN (it may well still be unpaid) and
	// the caller must not treat it as settled - retry next tick.
	const CLAIM_CLAIMED = 'claimed';
	const CLAIM_ALREADY = 'already';
	const CLAIM_DB_ERROR = 'db_error';

	/**
	 * Atomically transition a payment row out of 'unpaid' to $toStatus, only
	 * WHERE it is still 'unpaid'. The expiry cron (unpaid -> cancelled) and the
	 * payment verifier (unpaid -> paid) race for the same row; because BOTH go
	 * through this conditional update, exactly one wins. Returns a tri-state so a
	 * genuine race loss (CLAIM_ALREADY) is never confused with a transient
	 * database failure (CLAIM_DB_ERROR): the former is conclusive, the latter
	 * must be retried rather than treated as settled.
	 */
	private function claim_from_unpaid($orderId, $orderAmount, $toStatus) {
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = %s
			 WHERE `order_amount` = %s
			 AND `order_id` = %d
			 AND `status` = 'unpaid'",
			$toStatus, $orderAmount, $orderId
		));

		if ($affected === false) {
			NMM_Util::log(__FILE__, __LINE__, 'claim to ' . $toStatus . ' DB error for order ' . $orderId . ': ' . $wpdb->last_error, 'error');
			return self::CLAIM_DB_ERROR;
		}

		return $affected > 0 ? self::CLAIM_CLAIMED : self::CLAIM_ALREADY;
	}

	// Expiry cron's side of the race: claim the row for cancellation. Returns one
	// of the CLAIM_* constants.
	public function claim_for_cancellation($orderId, $orderAmount) {
		return $this->claim_from_unpaid($orderId, $orderAmount, 'cancelled');
	}

	// Verifier's side of the race: claim the row for payment. Returns one of the
	// CLAIM_* constants; only CLAIM_CLAIMED means this caller may complete the
	// order. On CLAIM_ALREADY the row was cancelled/paid elsewhere; on
	// CLAIM_DB_ERROR the outcome is unknown and must be retried.
	public function claim_for_payment($orderId, $orderAmount) {
		return $this->claim_from_unpaid($orderId, $orderAmount, 'paid');
	}

	public function set_hash($orderId, $orderAmount, $hash) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `tx_hash` = %s
			 WHERE `order_amount` = %s
			 AND `order_id` = %d",
			$hash, $orderAmount, $orderId
		));
	}

	/**
	 * Record the received tx hash on a row we could not complete because the
	 * expiry race cancelled it, for manual reconciliation. Scoped to a genuinely
	 * cancelled row that has no hash yet, so it can never clobber the hash of a
	 * row that a concurrent verifier legitimately marked paid.
	 */
	public function set_hash_on_cancelled($orderId, $orderAmount, $hash) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `tx_hash` = %s
			 WHERE `order_amount` = %s
			 AND `order_id` = %d
			 AND `status` = 'cancelled'
			 AND (`tx_hash` IS NULL OR `tx_hash` = '')",
			$hash, $orderAmount, $orderId
		));
	}

	public function set_ordered_at($orderId, $orderAmount, $orderedAt) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `ordered_at` = %d
			 WHERE `order_amount` = %s
			 AND `order_id` = %d",
			$orderedAt, $orderAmount, $orderId
		));
	}
}

?>
