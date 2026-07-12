<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable store for Solana signatures whose getTransaction detail lookup failed
 * and must be retried. Keyed by (address, signature). Because it is backed by a
 * database table rather than a bounded transient, no in-window signature is ever
 * evicted for lack of space, so the sweep never has to pause - the cursor can
 * always advance past a failure, having stored it here first.
 *
 * All methods no-op (returning empty) when $wpdb is unavailable, so the offline
 * sweep tests can exercise NMM_Blockchain without a database. In WordPress $wpdb
 * is always present.
 */
class NMM_Sol_Retry_Repo {

	private static function available() {
		return isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']);
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . NMM_SOL_RETRY_TABLE;
	}

	// The oldest-due signatures for an address (next_retry_at <= now), bounded so
	// per-tick work stays fixed no matter how large the queue grows. Ordered by
	// (next_retry_at, id) - which matches the addr_due index (secondary indexes
	// carry the PK), so it is deterministic even when next_retry_at ties, with no
	// filesort.
	public static function get_due($address, $limit, $now) {
		if (!self::available()) {
			return array();
		}
		global $wpdb;
		$t = self::table();
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT `signature`, `first_failed_at`, `attempts`, `block_time` FROM `$t`
			 WHERE `address` = %s AND `next_retry_at` <= %d
			 ORDER BY `next_retry_at` ASC, `id` ASC
			 LIMIT %d",
			$address, $now, $limit
		), ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	// Record a newly failed signature (from the sweep). If it is already tracked,
	// only refresh its block_time - do not disturb its existing retry schedule.
	// Returns true only if the row is durably stored (used to gate the cursor).
	public static function enqueue($address, $signature, $blockTime, $now) {
		if (!self::available()) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		$result = $wpdb->query($wpdb->prepare(
			"INSERT INTO `$t` (`address`, `signature`, `first_failed_at`, `attempts`, `next_retry_at`, `block_time`)
			 VALUES (%s, %s, %d, 1, %d, %d)
			 ON DUPLICATE KEY UPDATE `block_time` = VALUES(`block_time`)",
			$address, $signature, $now, $now, (int) $blockTime
		));
		if ($result === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry enqueue failed for ' . $signature . ': ' . $wpdb->last_error);
			return false;
		}
		return true;
	}

	// Update the attempt count and next retry time after a failed retry.
	public static function reschedule($address, $signature, $attempts, $nextRetryAt) {
		if (!self::available()) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		$result = $wpdb->query($wpdb->prepare(
			"UPDATE `$t` SET `attempts` = %d, `next_retry_at` = %d WHERE `address` = %s AND `signature` = %s",
			$attempts, $nextRetryAt, $address, $signature
		));
		if ($result === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry reschedule failed for ' . $signature . ': ' . $wpdb->last_error);
			return false;
		}
		return true;
	}

	// Drop a signature once it is conclusively resolved (successfully inspected).
	public static function remove($address, $signature) {
		if (!self::available()) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM `$t` WHERE `address` = %s AND `signature` = %s",
			$address, $signature
		));
		if ($result === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry remove failed for ' . $signature . ': ' . $wpdb->last_error);
			return false;
		}
		return true;
	}

	// Delete entries conclusively outside the matching period: their block time is
	// older than the payment window. Entries with an unknown block time (0) are
	// NOT expired on that basis - only the retention safety net (failing longer
	// than the retention bound) can remove them, so a still-live payment whose
	// block time we never learned is never dropped early. Returns the number of
	// rows removed so the caller can log a final expiry (-1 on database error).
	public static function delete_expired($address, $windowCutoffBlockTime, $retentionCutoffFirstFailed) {
		if (!self::available()) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM `$t`
			 WHERE `address` = %s
			 AND ( (`block_time` > 0 AND `block_time` < %d) OR `first_failed_at` < %d )",
			$address, (int) $windowCutoffBlockTime, (int) $retentionCutoffFirstFailed
		));
		if ($result === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry expiry sweep failed for ' . $address . ': ' . $wpdb->last_error);
			return -1;
		}
		return (int) $result;
	}

	// Count of queued signatures for an address (used by tests/diagnostics).
	public static function count_for($address) {
		if (!self::available()) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM `$t` WHERE `address` = %s",
			$address
		));
	}
}
