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
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry enqueue failed for ' . $signature . ': ' . $wpdb->last_error, 'error');
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
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry reschedule failed for ' . $signature . ': ' . $wpdb->last_error, 'error');
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
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry remove failed for ' . $signature . ': ' . $wpdb->last_error, 'error');
			return false;
		}
		return true;
	}

	// Delete entries for an address that are conclusively outside the matching
	// period. Run as TWO range deletes so each uses its own composite index
	// instead of scanning every queued row for the address (an OR across two
	// ranges cannot): one on (address, block_time) for signatures whose block
	// time is older than the payment window, one on (address, first_failed_at)
	// as the retention safety net. Entries with an unknown block time (0) are
	// never expired on the block-time basis - only by retention - so a still-live
	// payment whose block time we never learned is not dropped early. Each delete
	// is LIMIT-bounded so a huge backlog is cleared over several ticks rather than
	// in one long statement. Returns rows removed (-1 on database error).
	public static function delete_expired($address, $windowCutoffBlockTime, $retentionCutoffFirstFailed, $limit = 500) {
		if (!self::available()) {
			return 0;
		}
		global $wpdb;
		$t = self::table();

		$byBlock = $wpdb->query($wpdb->prepare(
			"DELETE FROM `$t` WHERE `address` = %s AND `block_time` > 0 AND `block_time` < %d LIMIT %d",
			$address, (int) $windowCutoffBlockTime, (int) $limit
		));
		if ($byBlock === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry block-time expiry failed for ' . $address . ': ' . $wpdb->last_error, 'error');
			return -1;
		}

		$byRetention = $wpdb->query($wpdb->prepare(
			"DELETE FROM `$t` WHERE `address` = %s AND `first_failed_at` < %d LIMIT %d",
			$address, (int) $retentionCutoffFirstFailed, (int) $limit
		));
		if ($byRetention === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry retention expiry failed for ' . $address . ': ' . $wpdb->last_error, 'error');
			return -1;
		}

		return (int) $byBlock + (int) $byRetention;
	}

	// Global cleanup, independent of any configured address: remove entries whose
	// first failure is older than a conservative global retention, in a bounded
	// batch. This reclaims rows for addresses that are no longer scanned at all -
	// e.g. after SOL Autopay is disabled, or a carousel address is removed or
	// replaced - which per-address expiry would otherwise never revisit. Uses the
	// (first_failed_at) index. Returns rows removed (-1 on database error).
	public static function delete_stale_globally($cutoffFirstFailed, $limit) {
		if (!self::available()) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM `$t` WHERE `first_failed_at` < %d LIMIT %d",
			(int) $cutoffFirstFailed, (int) $limit
		));
		if ($result === false) {
			NMM_Util::log(__FILE__, __LINE__, 'Solana retry global cleanup failed: ' . $wpdb->last_error, 'error');
			return -1;
		}
		return (int) $result;
	}

	// Run the global cleanup: clamp the retention to a safe minimum (so a filter
	// can never delete a still-live entry), then drain stale rows in consecutive
	// bounded batches, stopping once a batch is short (backlog cleared) or the
	// per-run batch cap is reached (bounding how long the cron lock is held).
	// Returns the total rows removed.
	public static function run_global_cleanup($retentionSeconds, $minRetentionSeconds, $batchSize = 500, $maxBatches = 40) {
		$retentionSeconds = max((int) $retentionSeconds, (int) $minRetentionSeconds);
		$cutoff = time() - $retentionSeconds;

		$total = 0;
		$batches = 0;
		do {
			$removed = self::delete_stale_globally($cutoff, $batchSize);
			if ($removed < 0) {
				break; // database error (already logged)
			}
			$total += $removed;
			$batches++;
		} while ($removed === $batchSize && $batches < $maxBatches);

		return $total;
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
