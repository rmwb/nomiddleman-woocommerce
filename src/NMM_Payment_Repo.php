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

	public function get_unpaid() {
		global $wpdb;

		$query = "SELECT `address`,
						 `cryptocurrency`,
						 `order_id`,
						 `order_amount`,
						 `status`,
						 `ordered_at`
				  FROM `$this->tableName`
				  WHERE `status` = 'unpaid'";

		$results = $wpdb->get_results($query, ARRAY_A);

		return $results;
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
	 * Atomically claim an unpaid payment row for cancellation. Flips the row to
	 * 'cancelled' only WHERE it is still 'unpaid', so the expiry cron and the
	 * payment verifier (which flips 'unpaid' -> 'paid') cannot both act on the
	 * same row. Returns true only if this call was the one that transitioned the
	 * row; false if another worker already moved it out of 'unpaid' (or on a DB
	 * error, which is logged), in which case the caller must not cancel the order.
	 */
	public function claim_for_cancellation($orderId, $orderAmount) {
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = 'cancelled'
			 WHERE `order_amount` = %s
			 AND `order_id` = %d
			 AND `status` = 'unpaid'",
			$orderAmount, $orderId
		));

		if ($affected === false) {
			NMM_Util::log(__FILE__, __LINE__, 'claim_for_cancellation DB error for order ' . $orderId . ': ' . $wpdb->last_error, 'error');
			return false;
		}

		return $affected > 0;
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
