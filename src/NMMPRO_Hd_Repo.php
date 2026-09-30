<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Repository for hd mpk storage in WP Database
class NMMPRO_Hd_Repo {

	// Outcomes of a Privacy Mode claim (NMMPRO_Consumed_Repo::claim_hd()).
	// Tri-state for the same reason Autopay's
	// claim is (see NMMPRO_Payment_Repo): a genuine race loss (CLAIM_ALREADY) is
	// conclusive, whereas a transient database failure (CLAIM_DB_ERROR) must be
	// retried rather than treated as settled.
	const CLAIM_CLAIMED = 'claimed';
	const CLAIM_ALREADY = 'already';
	const CLAIM_DB_ERROR = 'db_error';

	// Outcomes of bind_reserved(). A zero-row UPDATE is not success: it means
	// the reservation was lost (another worker took over an expired one), and a
	// database failure leaves the row's state unknown.
	const BIND_BOUND = 'bound';
	const BIND_CONFLICT = 'conflict';
	const BIND_DB_ERROR = 'db_error';

	// Evidence format of assignments made by NMMPRO_Hd_Allocator.
	const ASSIGNMENT_VERSION = 1;
	const POOL_VERSION = 1;

	private $mpk;
	private $tableName;
	private $cryptoId;
	private $hdMode;

	public function __construct($cryptoId, $mpk, $hdMode) {
		global $wpdb;
		$this->mpk = $mpk;
		$this->cryptoId = $cryptoId;
		$this->hdMode = $hdMode;
		$this->tableName = $wpdb->prefix . NMMPRO_HD_TABLE;
	}

	/**
	 * Record a derived HD address. Returns true only once the row is committed.
	 *
	 * The caller MUST NOT treat the address as stocked when this returns false:
	 * this table is the ready pool, so a row that never landed means
	 * count_ready() stays short and the checkout has nothing to claim. The
	 * insert can fail for reasons derivation cannot foresee - a damaged schema,
	 * a dropped connection, or the UNIQUE(cryptocurrency, address) constraint
	 * rejecting a re-derivation of an address already on file. Previously the
	 * result was discarded, so those failures were completely silent: the
	 * customer just saw a generic "no address" error with nothing in the logs.
	 *
	 * @return bool
	 */
	public function insert($address, $mpk_index, $status) {
		NMMPRO_Util::log(__FILE__, __LINE__, 'inserting ' . $address . ' into db as ' . $status);
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"INSERT INTO `$this->tableName`
				(`address`, `cryptocurrency`, `mpk`, `mpk_index`, `status`, `hd_mode`) VALUES
				(%s, %s, %s, %d, %s, %d)",
			$address, $this->cryptoId, $this->mpk, $mpk_index, $status, $this->hdMode
		));

		if ($affected === false) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Failed to insert HD address row for ' . $this->cryptoId . ' ' . $address . ' (index ' . $mpk_index . ', status ' . $status . '): ' . $wpdb->last_error, 'error');
			return false;
		}

		return $affected > 0;
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
			 AND `pool_version` IS NOT NULL
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

	/**
	 * Add a freshly derived address to the ready pool. pool_version marks it as
	 * derived by this release, so the legacy policy never retires it; it is
	 * still checked on chain again before it is ever issued.
	 *
	 * @return bool
	 */
	public function insert_pool($address, $mpk_index) {
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"INSERT INTO `$this->tableName`
				(`address`, `cryptocurrency`, `mpk`, `mpk_index`, `status`, `hd_mode`, `pool_version`) VALUES
				(%s, %s, %s, %d, 'ready', %d, %d)",
			$address, $this->cryptoId, $this->mpk, $mpk_index, $this->hdMode, self::POOL_VERSION
		));

		if ($affected === false) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Failed to add ' . $this->cryptoId . ' HD address ' . $address . ' (index ' . $mpk_index . ') to the pool: ' . $wpdb->last_error, 'error');
			return false;
		}

		return $affected > 0;
	}

	/**
	 * Reserve the lowest-index candidate for a fresh chain check. A candidate
	 * is a pool row this release derived, or a reservation whose holder never
	 * bound it before it expired (a crashed checkout, or a candidate deferred
	 * after an inconclusive check). A reservation is NOT an assignment: the
	 * address has not been shown to anyone, and nothing verifies or expires it.
	 * The token makes every later step conditional on still holding it.
	 *
	 * @return array|null|false The row (id, address, mpk_index), null when there
	 *                          is no candidate, false on a database error.
	 */
	public function reserve_candidate($token, $ttlSec) {
		global $wpdb;

		$eligible = "((`status` = 'ready' AND `pool_version` IS NOT NULL)
			OR (`status` = 'reserved' AND `reservation_expires` < UNIX_TIMESTAMP()))";

		for ($attempt = 0; $attempt < 5; $attempt++) {
			$id = $wpdb->get_var($wpdb->prepare(
				"SELECT `id` FROM `$this->tableName`
				 WHERE `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND $eligible
				 ORDER BY `mpk_index`
				 LIMIT 1",
				$this->mpk, $this->cryptoId, $this->hdMode
			));

			if ($id === null) {
				return $wpdb->last_error !== '' ? false : null;
			}

			$affected = $wpdb->query($wpdb->prepare(
				"UPDATE `$this->tableName`
				 SET `status` = 'reserved', `reservation_token` = %s, `reservation_expires` = UNIX_TIMESTAMP() + %d
				 WHERE `id` = %d AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND $eligible",
				$token, $ttlSec, $id, $this->mpk, $this->cryptoId, $this->hdMode
			));

			if ($affected === false) {
				return false;
			}
			if ($affected === 1) {
				$row = $wpdb->get_row($wpdb->prepare(
					"SELECT `id`, `address`, `mpk_index` FROM `$this->tableName`
					 WHERE `id` = %d AND `status` = 'reserved' AND `reservation_token` = %s",
					$id, $token
				), ARRAY_A);
				return is_array($row) ? $row : false;
			}
			// Another checkout reserved it between our read and our write; next.
		}

		return null;
	}

	/**
	 * Bind a reserved, freshly validated address to an order - permanently.
	 * Only the holder of the reservation can bind it. The assignment boundary
	 * (bound_at) and the display/expiry clock (assigned_at) both come from the
	 * database clock, once; nothing later replaces them. $validatedHeight is
	 * the chain height read just before binding: the block boundary.
	 *
	 * @return string One of the BIND_* constants.
	 */
	public function bind_reserved($id, $token, $orderId, $orderAmount, $validatedAt, $validatedHeight) {
		global $wpdb;

		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = 'assigned', `assignment_version` = %d, `bound_at` = UNIX_TIMESTAMP(), `assigned_at` = UNIX_TIMESTAMP(),
				 `validated_at` = %d, `validated_height` = %d, `order_id` = %d, `order_amount` = %s, `total_received` = 0, `credited_units` = NULL,
				 `scan_state` = NULL, `review_reason` = NULL, `last_checked` = 0,
				 `reservation_token` = NULL, `reservation_expires` = NULL
			 WHERE `id` = %d AND `status` = 'reserved' AND `reservation_token` = %s
			 AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			self::ASSIGNMENT_VERSION, $validatedAt, $validatedHeight, $orderId, $orderAmount, $id, $token, $this->mpk, $this->cryptoId, $this->hdMode
		));

		if ($affected === false) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Binding ' . $this->cryptoId . ' HD row ' . $id . ' to order ' . $orderId . ' failed: ' . $wpdb->last_error, 'error');
			return self::BIND_DB_ERROR;
		}
		if ($affected !== 1) {
			return self::BIND_CONFLICT;
		}

		// Confirm what landed rather than trusting the affected-row count.
		$bound = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM `$this->tableName`
			 WHERE `id` = %d AND `status` = 'assigned' AND `order_id` = %d AND `assignment_version` = %d AND `bound_at` IS NOT NULL",
			$id, $orderId, self::ASSIGNMENT_VERSION
		));
		if ($bound === null) {
			return self::BIND_DB_ERROR;
		}
		return (int) $bound === 1 ? self::BIND_BOUND : self::BIND_CONFLICT;
	}

	/**
	 * Retire a reserved candidate for good (chain history found). Only the
	 * holder of the reservation can. Returns true when this call retired it.
	 */
	public function retire_reserved($id, $token, $reason) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `status` = 'retired', `review_reason` = %s, `reservation_token` = NULL, `reservation_expires` = NULL
			 WHERE `id` = %d AND `status` = 'reserved' AND `reservation_token` = %s
			 AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$reason, $id, $token, $this->mpk, $this->cryptoId, $this->hdMode
		)) === 1;
	}

	/**
	 * Set aside a candidate whose check was inconclusive. It stays reserved,
	 * with no holder, until $seconds pass; then it is a candidate again and
	 * gets a fresh check. It is never recorded as clean.
	 */
	public function defer_reserved($id, $token, $seconds) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName`
			 SET `reservation_token` = NULL, `reservation_expires` = UNIX_TIMESTAMP() + %d
			 WHERE `id` = %d AND `status` = 'reserved' AND `reservation_token` = %s
			 AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$seconds, $id, $token, $this->mpk, $this->cryptoId, $this->hdMode
		)) === 1;
	}

	/**
	 * Every open, validated assignment of $orderId in this site's table, in
	 * any wallet or coin (a retried checkout may have switched coin).
	 *
	 * @return array|false
	 */
	public static function order_assignments($orderId) {
		global $wpdb;
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT `id`, `address`, `cryptocurrency`, `mpk`, `hd_mode`, `status`, `order_amount`, `credited_units`
			 FROM `$table`
			 WHERE `order_id` = %d AND `assignment_version` IS NOT NULL AND `status` IN ('assigned', 'underpaid', 'completing')
			 ORDER BY `id`",
			$orderId
		), ARRAY_A);
		return ($rows === null || $wpdb->last_error !== '') ? false : (array) $rows;
	}

	/**
	 * Re-price an assignment that has received nothing, for a retried checkout
	 * of the same order. Never touches bound_at or assigned_at.
	 */
	public function reprice_assignment($id, $orderId, $orderAmount) {
		global $wpdb;
		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `order_amount` = %s
			 WHERE `id` = %d AND `order_id` = %d AND `status` = 'assigned' AND `credited_units` IS NULL
			 AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$orderAmount, $id, $orderId, $this->mpk, $this->cryptoId, $this->hdMode
		));
		if ($affected === false) {
			return false;
		}
		return $wpdb->get_var($wpdb->prepare(
			"SELECT `order_amount` FROM `$this->tableName` WHERE `id` = %d AND `order_id` = %d AND `status` = 'assigned'",
			$id, $orderId
		)) !== null;
	}

	/**
	 * Retire an open assignment of $orderId by row id, in whichever wallet it
	 * lives: an address the order's retried checkout replaced (it was never
	 * shown: the order carried no address), or one whose order has ended.
	 */
	public static function retire_assignment($id, $orderId, $reason) {
		global $wpdb;
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$table` SET `status` = 'retired', `review_reason` = %s
			 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('assigned', 'underpaid', 'completing')",
			$reason, $id, $orderId
		)) === 1;
	}

	/**
	 * Retire this wallet's address once its order has ended. Status-guarded,
	 * so a row that has already moved on (completed, reviewed) is untouched.
	 */
	public function retire_address($address, $reason) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `status` = 'retired', `review_reason` = %s
			 WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `mpk` = %s
			 AND `status` IN ('assigned', 'underpaid', 'completing')",
			$reason, $address, $this->cryptoId, $this->hdMode, $this->mpk
		)) === 1;
	}

	// =====================================================================
	// Validated assignments: what the evidence-based verifier and expiry use.
	// Every write is guarded by row id, order and the expected state, and
	// reports whether it landed.
	// =====================================================================

	/** Open, validated assignments of this wallet. @return array[]|false */
	public function open_assignments() {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT `id`, `address`, `order_id`, `order_amount`, `status`, `bound_at`, `validated_at`, `validated_height`, `assigned_at`, `scan_state`, `credited_units`, `review_reason`, `last_checked` FROM `$this->tableName`
			 WHERE `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d
			 AND `status` IN ('assigned', 'underpaid', 'completing') AND `assignment_version` IS NOT NULL AND `bound_at` IS NOT NULL
			 ORDER BY `last_checked`, `id`",
			$this->mpk, $this->cryptoId, $this->hdMode
		), ARRAY_A);
		return (!is_array($rows) || $wpdb->last_error !== '') ? false : $rows;
	}

	/** A fresh read of one of this wallet's rows. @return array|null|false */
	public function row($id) {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT `id`, `address`, `order_id`, `order_amount`, `status`, `bound_at`, `validated_at`, `validated_height`, `assigned_at`, `scan_state`, `credited_units`, `review_reason`, `last_checked` FROM `$this->tableName`
			 WHERE `id` = %d AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$id, $this->mpk, $this->cryptoId, $this->hdMode
		), ARRAY_A);
		if ($row === null && $wpdb->last_error !== '') {
			return false;
		}
		return $row;
	}

	/**
	 * Retired and held assignments still worth watching for late payments:
	 * validated, bound to an order within the last $days, and not checked in
	 * the last $intervalSec. At most $limit, round-robin by id: those after
	 * $afterId first, then from the start. A row that was busy or failed
	 * stays due but does not hold its place.
	 *
	 * @return array[]|false
	 */
	public function monitored_rows($days, $intervalSec, $limit, $afterId = 0) {
		global $wpdb;
		$window = (int) $days * DAY_IN_SECONDS;
		$after = $wpdb->get_results($wpdb->prepare(
			"SELECT `id`, `address`, `order_id`, `order_amount`, `status`, `bound_at`, `validated_at`, `validated_height`, `assigned_at`, `scan_state`, `credited_units`, `review_reason`, `last_checked`
			 FROM `$this->tableName`
			 WHERE `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d
			 AND `status` IN ('retired', 'review') AND `order_id` IS NOT NULL AND `assignment_version` IS NOT NULL
			 AND `bound_at` > UNIX_TIMESTAMP() - %d AND `last_checked` < UNIX_TIMESTAMP() - %d AND `id` > %d
			 ORDER BY `id` LIMIT %d",
			$this->mpk, $this->cryptoId, $this->hdMode, $window, (int) $intervalSec, (int) $afterId, (int) $limit
		), ARRAY_A);
		if (!is_array($after) || self::db_failed()) {
			return false;
		}
		$remaining = (int) $limit - count($after);
		if ($remaining <= 0) {
			return $after;
		}
		$wrapped = $wpdb->get_results($wpdb->prepare(
			"SELECT `id`, `address`, `order_id`, `order_amount`, `status`, `bound_at`, `validated_at`, `validated_height`, `assigned_at`, `scan_state`, `credited_units`, `review_reason`, `last_checked`
			 FROM `$this->tableName`
			 WHERE `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d
			 AND `status` IN ('retired', 'review') AND `order_id` IS NOT NULL AND `assignment_version` IS NOT NULL
			 AND `bound_at` > UNIX_TIMESTAMP() - %d AND `last_checked` < UNIX_TIMESTAMP() - %d AND `id` <= %d
			 ORDER BY `id` LIMIT %d",
			$this->mpk, $this->cryptoId, $this->hdMode, $window, (int) $intervalSec, (int) $afterId, $remaining
		), ARRAY_A);
		if (!is_array($wrapped) || self::db_failed()) {
			return false;
		}
		return array_merge($after, $wrapped);
	}

	/**
	 * Whether the connection's latest query failed.
	 *
	 * @phpstan-impure Each query changes the answer.
	 */
	private static function db_failed() {
		global $wpdb;
		return $wpdb->last_error !== '';
	}

	/** Record a monitoring check of a retired/held row (and its scan progress). */
	public function save_monitor_state($id, $orderId, $state) {
		global $wpdb;
		if (is_array($state)) {
			return $wpdb->query($wpdb->prepare(
				"UPDATE `$this->tableName` SET `scan_state` = %s, `last_checked` = UNIX_TIMESTAMP()
				 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('retired', 'review')",
				wp_json_encode($state), $id, $orderId
			)) !== false;
		}
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `last_checked` = UNIX_TIMESTAMP()
			 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('retired', 'review')",
			$id, $orderId
		)) !== false;
	}

	/** Save resumable scan progress and the time of this check. */
	/**
	 * Mark an open assignment as attempted now, whatever the attempt came to,
	 * so a pass that bails out early on it (a busy address, an unreadable
	 * order) moves on to the others next cycle.
	 */
	public function touch_checked($id, $orderId) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `last_checked` = UNIX_TIMESTAMP()
			 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('assigned', 'underpaid', 'completing')",
			$id, $orderId
		)) !== false;
	}

	public function save_scan_state($id, $orderId, $state) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `scan_state` = %s, `last_checked` = UNIX_TIMESTAMP()
			 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('assigned', 'underpaid', 'completing')",
			wp_json_encode($state), $id, $orderId
		)) !== false;
	}

	/**
	 * Record the amount attributable to the order (smallest units) and the
	 * diagnostic lifetime total observed at the address. Only the former is
	 * ever used for a decision.
	 */
	public function save_amounts($id, $orderId, $creditedUnits, $observedTotal) {
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$this->tableName` SET `credited_units` = %s, `total_received` = %s
			 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('assigned', 'underpaid', 'completing')",
			$creditedUnits, $observedTotal, $id, $orderId
		)) !== false;
	}

	/**
	 * Move an open assignment to $to ('underpaid', 'assigned', 'complete',
	 * 'retired' or 'review') from one of $from. Returns true only when this
	 * call moved it.
	 */
	public function transition($id, $orderId, array $from, $to, $reason = null) {
		global $wpdb;
		$placeholders = implode(',', array_fill(0, count($from), '%s'));
		// prepare() would turn a null reason into '', so the reason is only
		// written when there is one.
		$set = $reason === null ? '`status` = %s' : '`status` = %s, `review_reason` = %s';
		$args = array_merge($reason === null ? array($to) : array($to, $reason), array($id, $orderId, $this->mpk, $this->cryptoId, $this->hdMode), $from);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb's prefix and a plugin constant; $set and $placeholders are only fixed column names and literal placeholders, and every value is bound by prepare().
		$sql = "UPDATE `$this->tableName` SET $set
			 WHERE `id` = %d AND `order_id` = %d AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `status` IN ($placeholders)";
		return $wpdb->query($wpdb->prepare($sql, $args)) === 1; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared in this call; see above.
	}

	/**
	 * Derivation facts for wallet recovery: the highest index ever derived and
	 * the highest ever issued to an order. A wallet restored from its seed must
	 * scan at least to the highest issued index (see docs/HD-WALLET-RECOVERY.md).
	 *
	 * @return array{derived: int|null, issued: int|null, unused_issued: int}|null
	 */
	public function index_summary() {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT MAX(`mpk_index`) AS derived,
			        MAX(CASE WHEN `order_id` IS NOT NULL THEN `mpk_index` END) AS issued,
			        SUM(CASE WHEN `status` = 'retired' AND `total_received` = 0 AND `order_id` IS NOT NULL THEN 1 ELSE 0 END) AS unused_issued
			 FROM `$this->tableName`
			 WHERE `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d",
			$this->mpk, $this->cryptoId, $this->hdMode
		), ARRAY_A);
		if (!is_array($row)) {
			return null;
		}
		return array(
			'derived'       => $row['derived'] === null ? null : (int) $row['derived'],
			'issued'        => $row['issued'] === null ? null : (int) $row['issued'],
			'unused_issued' => (int) $row['unused_issued'],
		);
	}
}

?>
