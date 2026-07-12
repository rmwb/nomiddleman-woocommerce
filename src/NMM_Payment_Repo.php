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

		$query = "SELECT DISTINCT `address`, `cryptocurrency` FROM `$this->tableName` WHERE `status` = 'unpaid'";

		$results = $wpdb->get_results($query, ARRAY_A);

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

	/**
	 * Atomically transition a payment row out of 'unpaid' to $toStatus, only
	 * WHERE it is still 'unpaid'. The expiry cron (unpaid -> cancelled) and the
	 * payment verifier (unpaid -> paid) race for the same row; because BOTH go
	 * through this conditional update, exactly one wins and the loser sees zero
	 * affected rows and must not apply its side effect (cancel the order, or
	 * complete it). Returns true only if this call was the one that moved the
	 * row; false if another worker already moved it, or on a logged DB error.
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
			return false;
		}

		return $affected > 0;
	}

	// Expiry cron's side of the race: claim the row for cancellation.
	public function claim_for_cancellation($orderId, $orderAmount) {
		return $this->claim_from_unpaid($orderId, $orderAmount, 'cancelled');
	}

	// Verifier's side of the race: claim the row for payment. If this returns
	// false the row was already cancelled/paid by another worker and the caller
	// must NOT complete the order.
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
