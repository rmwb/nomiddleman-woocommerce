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
	// per-tick work stays fixed no matter how large the queue grows.
	public static function get_due($address, $limit, $now) {
		if (!self::available()) {
			return array();
		}
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results($wpdb->prepare(
			"SELECT `signature`, `first_failed_at`, `attempts`, `block_time` FROM `$t`
			 WHERE `address` = %s AND `next_retry_at` <= %d
			 ORDER BY `next_retry_at` ASC
			 LIMIT %d",
			$address, $now, $limit
		), ARRAY_A);
	}

	// Record a newly failed signature (from the sweep). If it is already tracked,
	// only refresh its block_time - do not disturb its existing retry schedule.
	public static function enqueue($address, $signature, $blockTime, $now) {
		if (!self::available()) {
			return;
		}
		global $wpdb;
		$t = self::table();
		$wpdb->query($wpdb->prepare(
			"INSERT INTO `$t` (`address`, `signature`, `first_failed_at`, `attempts`, `next_retry_at`, `block_time`)
			 VALUES (%s, %s, %d, 1, %d, %d)
			 ON DUPLICATE KEY UPDATE `block_time` = VALUES(`block_time`)",
			$address, $signature, $now, $now, (int) $blockTime
		));
	}

	// Update the attempt count and next retry time after a failed retry.
	public static function reschedule($address, $signature, $attempts, $nextRetryAt) {
		if (!self::available()) {
			return;
		}
		global $wpdb;
		$t = self::table();
		$wpdb->query($wpdb->prepare(
			"UPDATE `$t` SET `attempts` = %d, `next_retry_at` = %d WHERE `address` = %s AND `signature` = %s",
			$attempts, $nextRetryAt, $address, $signature
		));
	}

	// Drop a signature once it is conclusively resolved (successfully inspected).
	public static function remove($address, $signature) {
		if (!self::available()) {
			return;
		}
		global $wpdb;
		$t = self::table();
		$wpdb->query($wpdb->prepare(
			"DELETE FROM `$t` WHERE `address` = %s AND `signature` = %s",
			$address, $signature
		));
	}

	// Delete entries conclusively outside the matching period: their block time is
	// older than the payment window, or (as a safety net when block_time is
	// unknown) they have been failing longer than the retention bound. Returns the
	// number of rows removed so the caller can log a final expiry.
	public static function delete_expired($address, $windowCutoffBlockTime, $retentionCutoffFirstFailed) {
		if (!self::available()) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->query($wpdb->prepare(
			"DELETE FROM `$t`
			 WHERE `address` = %s
			 AND ( (`block_time` > 0 AND `block_time` < %d) OR `first_failed_at` < %d )",
			$address, (int) $windowCutoffBlockTime, (int) $retentionCutoffFirstFailed
		));
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
