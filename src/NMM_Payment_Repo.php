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

	// Number of distinct unpaid (cryptocurrency, address) pairs. A single scalar,
	// so the cron can size its per-tick budget without loading the whole backlog
	// into PHP.
	public function count_distinct_unpaid_addresses() {
		global $wpdb;

		return (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT `cryptocurrency`, `address` FROM `$this->tableName` WHERE `status` = 'unpaid') t");
	}

	/**
	 * One budgeted page of distinct unpaid (cryptocurrency, address) rows, ordered
	 * by (cryptocurrency, address), starting strictly AFTER the given cursor
	 * (keyset pagination). Only $limit rows are read, so a large backlog never
	 * loads or sorts in full each tick; the (status, cryptocurrency, address)
	 * index serves both the range and the ordering. ORDER BY columns are in the
	 * SELECT DISTINCT list (MySQL 8 safe), and the keyset comparison uses the same
	 * collation as the sort so pages never skip or repeat a row. An empty cursor
	 * ('' / '') sorts before everything and returns the first page.
	 */
	public function get_unpaid_addresses_after($cursorCrypto, $cursorAddress, $limit) {
		global $wpdb;
		$limit = max(1, (int) $limit);

		return $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT `cryptocurrency`, `address`
			 FROM `$this->tableName`
			 WHERE `status` = 'unpaid'
			 AND (`cryptocurrency` > %s OR (`cryptocurrency` = %s AND `address` > %s))
			 ORDER BY `cryptocurrency`, `address`
			 LIMIT %d",
			$cursorCrypto, $cursorCrypto, $cursorAddress, $limit
		), ARRAY_A);
	}

	// The first $limit distinct unpaid (cryptocurrency, address) rows in order, to
	// wrap the sweep back to the top when a page runs off the end of the list.
	public function get_unpaid_addresses_from_start($limit) {
		global $wpdb;
		$limit = max(1, (int) $limit);

		return $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT `cryptocurrency`, `address`
			 FROM `$this->tableName`
			 WHERE `status` = 'unpaid'
			 ORDER BY `cryptocurrency`, `address`
			 LIMIT %d",
			$limit
		), ARRAY_A);
	}

	/**
	 * Distinct unpaid (cryptocurrency, address) pairs whose payment record was
	 * created within the last $windowSeconds, newest first - the "priority lane"
	 * so a fresh customer's first check never waits behind a backlog sweep.
	 * GROUP BY + MAX keeps the ordering ONLY_FULL_GROUP_BY-safe; the
	 * unpaid_expiry (status, ordered_at) index serves the range predicate.
	 */
	public function get_recent_unpaid_addresses($windowSeconds, $limit) {
		global $wpdb;
		$limit = max(1, (int) $limit);
		$since = time() - max(0, (int) $windowSeconds);

		return $wpdb->get_results($wpdb->prepare(
			"SELECT `cryptocurrency`, `address`, MAX(`ordered_at`) AS `latest_ordered_at`
			 FROM `$this->tableName`
			 WHERE `status` = 'unpaid'
			 AND `ordered_at` >= %d
			 GROUP BY `cryptocurrency`, `address`
			 ORDER BY `latest_ordered_at` DESC
			 LIMIT %d",
			$since, $limit
		), ARRAY_A);
	}

	/**
	 * Given a bounded list of ['cryptocurrency'=>.., 'address'=>..] pairs, return
	 * the subset that still has an unpaid row, as a set keyed by "crypto|address".
	 * One indexed query for the whole list, so the cron can drop failed-fetch
	 * retries whose order was since paid/cancelled/deleted without re-querying a
	 * dead address forever.
	 */
	public function filter_unpaid_pairs($pairs) {
		global $wpdb;

		if (empty($pairs) || !is_array($pairs)) {
			return array();
		}

		$clauses = array();
		$args = array();
		foreach ($pairs as $pair) {
			$clauses[] = '(`cryptocurrency` = %s AND `address` = %s)';
			$args[] = $pair['cryptocurrency'];
			$args[] = $pair['address'];
		}

		$sql = "SELECT DISTINCT `cryptocurrency`, `address` FROM `$this->tableName` WHERE `status` = 'unpaid' AND (" . implode(' OR ', $clauses) . ")";
		$rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

		$live = array();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$live[$row['cryptocurrency'] . '|' . $row['address']] = true;
			}
		}

		return $live;
	}

	/**
	 * Remove unpaid payment rows left behind by an initialization attempt that
	 * inserted a row and then failed before persisting the order's wallet_address.
	 * A fresh attempt must clear them, or UNIQUE(order_id, order_amount) would
	 * silently reject its insert and the customer would be shown an address that
	 * nobody is monitoring. Only 'unpaid' rows are removed - a paid or cancelled
	 * row is a real record and is never touched.
	 */
	public function delete_unpaid_for_order($orderId) {
		global $wpdb;

		return $wpdb->query($wpdb->prepare(
			"DELETE FROM `$this->tableName` WHERE `order_id` = %d AND `status` = 'unpaid'",
			$orderId
		));
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
