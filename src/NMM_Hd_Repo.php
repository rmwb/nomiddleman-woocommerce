<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Repository for hd mpk storage in WP Database
class NMM_Hd_Repo {

	private $mpk;
	private $tableName;
	private $cryptoId;
	private $hdMode;

	public function __construct($cryptoId, $mpk, $hdMode) {
		global $wpdb;
		$this->mpk = $mpk;
		$this->cryptoId = $cryptoId;
		$this->hdMode = $hdMode;
		$this->tableName = $wpdb->prefix . NMM_HD_TABLE;
	}

	public function insert($address, $mpk_index, $status) {
		NMM_Util::log(__FILE__, __LINE__, 'inserting ' . $address . ' into db as ' . $status);
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"INSERT INTO `$this->tableName`
				(`address`, `cryptocurrency`, `mpk`, `mpk_index`, `status`, `hd_mode`) VALUES
				(%s, %s, %s, %d, %s, %d)",
			$address, $this->cryptoId, $this->mpk, $mpk_index, $status, $this->hdMode
		));
	}

	public function count_ready() {
		//statuses
		//========
		//complete - used by us, never to be used again
		//ready - ready to be used
		//error - what happens if bad data is in the database (NOT USED)
		//other - when we a non-hd address (NOT USED)
		//assigned - order is assigned to this address
		//dirty - not used by us, but has been used before
		//underpaid - this was assigned by us but has not hit verified amount

		global $wpdb;

		$count = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM `$this->tableName`
			 WHERE `status` = 'ready'
			 AND `mpk` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d",
			$this->mpk, $this->cryptoId, $this->hdMode
		));

		return $count;
	}

	// Returns the largest index of an address that has received payment, to establish the start of the gap
	public function get_next_index() {
		global $wpdb;

		$largest = $wpdb->get_var($wpdb->prepare(
			"SELECT MAX(`mpk_index`) FROM `$this->tableName`
			 WHERE `mpk` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d",
			$this->mpk, $this->cryptoId, $this->hdMode
		));

		// start with third address to avoid messy logic
		if ($largest === NULL || $largest === 0 || $largest === 1) {
			return 2;
		}

		return $largest + 1;
	}

	public function get_oldest_ready() {
		global $wpdb;

		$address = $wpdb->get_var($wpdb->prepare(
			"SELECT `address` FROM `$this->tableName`
			 WHERE `mpk` = %s
			 AND `status` = 'ready'
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 ORDER BY `mpk_index`
			 LIMIT 1",
			$this->mpk, $this->cryptoId, $this->hdMode
		));
		NMM_Util::log(__FILE__, __LINE__, "Oldest ready address is: " . print_r($address, true));
		return $address;
	}

	public function get_pending() {
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT `order_id`, `address`, `order_amount`, `status`, `total_received` FROM `$this->tableName`
			 WHERE `mpk` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 AND (`status` = 'assigned' OR `status` = 'underpaid')",
			$this->mpk, $this->cryptoId, $this->hdMode
		), ARRAY_A);

		return $results;
	}

	public function get_assigned() {
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT `order_id`, `address`, `assigned_at`, `total_received` FROM `$this->tableName`
			 WHERE `mpk` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 AND `status` = 'assigned'",
			$this->mpk, $this->cryptoId, $this->hdMode
		), ARRAY_A);

		return $results;
	}

	public function set_total_received($address, $totalReceived) {
		global $wpdb;
		NMM_Util::log(__FILE__, __LINE__, 'Updating total received at ' . $address .' to: ' . $totalReceived);

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `total_received` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$totalReceived, $address, $this->cryptoId, $this->hdMode
		));
	}

	public function set_order_amount($address, $orderAmount) {
		global $wpdb;
		NMM_Util::log(__FILE__, __LINE__, 'Updating order amount at ' . $address . ' to: ' . $orderAmount);

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `order_amount` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$orderAmount, $address, $this->cryptoId, $this->hdMode
		));
	}

	public function set_status($address, $status) {
		global $wpdb;
		NMM_Util::log(__FILE__, __LINE__, 'Updating ' . $address . ' to ' . $status);
		if ($status === 'assigned') {
			$wpdb->query($wpdb->prepare(
				"UPDATE `$this->tableName` SET `status` = %s, `assigned_at` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
				$status, time(), $address, $this->cryptoId, $this->hdMode
			));
		}
		else {
			$wpdb->query($wpdb->prepare(
				"UPDATE `$this->tableName` SET `status` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
				$status, $address, $this->cryptoId, $this->hdMode
			));
		}
	}

	public function set_order_id($address, $orderId) {
		global $wpdb;
		NMM_Util::log(__FILE__, __LINE__, 'Setting address ' . $address . ' order id to: ' . $orderId);

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `order_id` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$orderId, $address, $this->cryptoId, $this->hdMode
		));
	}
}

?>
