<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Repository for hd mpk storage in WP Database
class NMM_Hd_Repo {

	// Outcomes of claim_for_complete(). Tri-state for the same reason Autopay's
	// claim is (see NMM_Payment_Repo): a genuine race loss (CLAIM_ALREADY) is
	// conclusive, whereas a transient database failure (CLAIM_DB_ERROR) must be
	// retried rather than treated as settled.
	const CLAIM_CLAIMED = 'claimed';
	const CLAIM_ALREADY = 'already';
	const CLAIM_DB_ERROR = 'db_error';

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

	/**
	 * Atomically claim the oldest ready address for an order and return it.
	 *
	 * Selection + status flip + order binding happen as a guarded UPDATE, so
	 * two concurrent checkouts can never be handed the same address: the
	 * WHERE status = 'ready' clause means only one UPDATE affects a given row.
	 * Returns the claimed address string, or null if none is available.
	 */
	public function claim_oldest_ready($orderId, $orderAmount) {
		global $wpdb;

		// A few attempts absorbs the rare case where our chosen id was claimed
		// by a competing request between our SELECT and our UPDATE.
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$id = $wpdb->get_var($wpdb->prepare(
				"SELECT `id` FROM `$this->tableName`
				 WHERE `status` = 'ready'
				 AND `mpk` = %s
				 AND `cryptocurrency` = %s
				 AND `hd_mode` = %d
				 ORDER BY `mpk_index`
				 LIMIT 1",
				$this->mpk, $this->cryptoId, $this->hdMode
			));

			if ($id === null) {
				return null; // no ready addresses left
			}

			$affected = $wpdb->query($wpdb->prepare(
				"UPDATE `$this->tableName`
				 SET `status` = 'assigned', `assigned_at` = %d, `order_id` = %d, `order_amount` = %s
				 WHERE `id` = %d AND `status` = 'ready'",
				time(), $orderId, $orderAmount, $id
			));

			if ($affected === 1) {
				return $wpdb->get_var($wpdb->prepare(
					"SELECT `address` FROM `$this->tableName` WHERE `id` = %d",
					$id
				));
			}
			// affected === 0: another request claimed this id first; retry.
		}

		NMM_Util::log(__FILE__, __LINE__, 'claim_oldest_ready exhausted retries for ' . $this->cryptoId, 'warning');
		return null;
	}

	/**
	 * Atomically claim a payable row so exactly one worker can complete its
	 * order. Only 'assigned'/'underpaid' rows - the two states get_pending()
	 * returns - can be claimed, so a row already carried to 'complete' or
	 * quarantined/retired by the reconcile pass yields CLAIM_ALREADY and the
	 * caller must not call payment_complete().
	 *
	 * Without this, two overlapping verifier runs that both saw the same
	 * confirmed payment would each complete the order.
	 *
	 * @return string One of the CLAIM_* constants.
	 */
	public function claim_for_complete($address) {
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = 'complete'
			 WHERE `address` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 AND `mpk` = %s
			 AND (`status` = 'assigned' OR `status` = 'underpaid')",
			$address, $this->cryptoId, $this->hdMode, $this->mpk
		));

		if ($affected === false) {
			NMM_Util::log(__FILE__, __LINE__, 'claim_for_complete DB error for ' . $this->cryptoId . ' address ' . $address . ': ' . $wpdb->last_error, 'error');
			return self::CLAIM_DB_ERROR;
		}

		return $affected > 0 ? self::CLAIM_CLAIMED : self::CLAIM_ALREADY;
	}

	/**
	 * Hand a claimed row back to $toStatus after the completion attempt that
	 * claimed it failed, so a later sweep retries it rather than leaving a
	 * 'complete' row above an order that was never paid.
	 *
	 * Deliberately does not touch assigned_at, which set_status('assigned')
	 * would refresh: the address was assigned when it was assigned, and
	 * restarting that clock would push out the reconcile pass's expiry checks.
	 */
	public function release_claim($address, $toStatus) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = %s
			 WHERE `address` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 AND `mpk` = %s
			 AND `status` = 'complete'",
			$toStatus, $address, $this->cryptoId, $this->hdMode, $this->mpk
		));
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

	/**
	 * Rows the reconcile pass must settle against their live order: every state
	 * in which an address is still held for an order and awaiting payment.
	 *
	 * 'underpaid' belongs here as much as 'assigned' does. An address that took
	 * a part payment for an order that is later cancelled is just as dead as one
	 * that took nothing, and if the reconcile pass cannot see it, nothing else
	 * ever will - the verifier keeps polling it on every sweep, forever, and it
	 * is never retired as dirty.
	 */
	public function get_reconcilable() {
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT `order_id`, `address`, `assigned_at`, `total_received` FROM `$this->tableName`
			 WHERE `mpk` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 AND (`status` = 'assigned' OR `status` = 'underpaid')",
			$this->mpk, $this->cryptoId, $this->hdMode
		), ARRAY_A);

		return $results;
	}

	// The oldest-due batch of addresses awaiting quarantine verification. Ordered
	// by last_checked so the most-overdue are processed first, and LIMITed so a
	// large abandonment burst cannot fire thousands of explorer requests (each
	// row costs one) in a single cron tick under the global lock.
	public function get_quarantined($limit = 25) {
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT `order_id`, `address`, `status`, `last_checked`, `total_received` FROM `$this->tableName`
			 WHERE `mpk` = %s
			 AND `cryptocurrency` = %s
			 AND `hd_mode` = %d
			 AND (`status` = 'quarantine' OR `status` = 'quarantine_verified')
			 ORDER BY `last_checked` ASC
			 LIMIT %d",
			$this->mpk, $this->cryptoId, $this->hdMode, $limit
		), ARRAY_A);

		return $results;
	}

	// Set a quarantine status and stamp the time of this check (last_checked),
	// so the cron can space successive fresh explorer checks apart in time.
	public function set_quarantine($address, $status, $checkedAt) {
		global $wpdb;
		$wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `status` = %s, `last_checked` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$status, $checkedAt, $address, $this->cryptoId, $this->hdMode
		));
	}

	// Atomically return a verified-clean quarantined address to the ready pool,
	// clearing every stale assignment field in the SAME guarded UPDATE. The
	// `status` = 'quarantine_verified' guard means that if anything else has
	// already moved this row on (or a checkout claimed it in a race after it
	// became ready), this UPDATE matches nothing and cannot clobber the new
	// order's amount/id. Returns the number of rows changed (1 if recycled).
	public function recycle_quarantined($address) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = 'ready', `order_amount` = 0, `order_id` = NULL, `assigned_at` = 0, `total_received` = 0, `last_checked` = 0
			 WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `status` = 'quarantine_verified'",
			$address, $this->cryptoId, $this->hdMode
		));
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
