<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persisted Privacy Mode payment evidence: one row per incoming output an
 * assignment's scans have seen (table nmmpro_hd_evidence, schema 1.5).
 *
 * The rows are the durable record of which transactions belong to an order.
 * They outlive the explorer's newest page, so a partial payment stays counted
 * after later activity pushes it out of view, and they carry everything the
 * verifier's eligibility decision needs (amount, confirmations, block time,
 * and when this plugin first saw the output). Every write is keyed by the
 * assignment row id and the output identity.
 */
class NMMPRO_Hd_Evidence_Repo {

	const OBSERVED = 'observed';
	const CREDITED = 'credited';
	const CONFLICT = 'conflict';
	const VANISHED = 'vanished';

	const RECORD_OK = 'ok';
	const RECORD_CONFLICT = 'conflict';
	const RECORD_ERROR = 'error';

	public static function table() {
		return NMMPRO_Hd_Schema::evidence_table();
	}

	/**
	 * Record a scan's outputs for assignment $hdId. A new output is inserted
	 * with when (and in what state) it was first seen; a known one has its
	 * confirmations, block and last-observed time refreshed (a reorg may move
	 * it to another block). An output whose AMOUNT disagrees with the record is
	 * a conflict: the row is marked and the caller must not act on it.
	 *
	 * @return string One of the RECORD_* constants.
	 */
	public static function record($hdId, $orderId, $coin, $address, $source, $outputs, $observedAt) {
		global $wpdb;
		$table = self::table();

		foreach ($outputs as $output) {
			$existing = $wpdb->get_row($wpdb->prepare(
				"SELECT `id`, `amount_units`, `state` FROM `$table` WHERE `hd_id` = %d AND `tx_hash` = %s AND `output_index` = %d",
				$hdId, $output['tx_hash'], $output['output_index']
			), ARRAY_A);
			if ($existing === null && $wpdb->last_error !== '') {
				return self::RECORD_ERROR;
			}

			if ($existing === null) {
				// Two literal statements: a confirmed output carries its block, an
				// unconfirmed one has none (NULL), and prepare() cannot bind NULL.
				if ($output['confirmed']) {
					$inserted = $wpdb->query($wpdb->prepare(
						"INSERT INTO `$table`
							(`hd_id`, `order_id`, `coin`, `address`, `tx_hash`, `output_index`, `amount_units`, `confirmations`,
							 `block_height`, `block_time`, `source`, `first_seen_at`, `first_seen_pending`, `observed_at`, `state`)
						 VALUES (%d, %d, %s, %s, %s, %d, %s, %d, %d, %d, %s, %d, 0, %d, %s)",
						$hdId, $orderId, $coin, $address, $output['tx_hash'], $output['output_index'], $output['amount_units'],
						$output['confirmations'], $output['block_height'], $output['block_time'], $source, $observedAt, $observedAt, self::OBSERVED
					));
				}
				else {
					$inserted = $wpdb->query($wpdb->prepare(
						"INSERT INTO `$table`
							(`hd_id`, `order_id`, `coin`, `address`, `tx_hash`, `output_index`, `amount_units`, `confirmations`,
							 `block_height`, `block_time`, `source`, `first_seen_at`, `first_seen_pending`, `observed_at`, `state`)
						 VALUES (%d, %d, %s, %s, %s, %d, %s, %d, NULL, NULL, %s, %d, 1, %d, %s)",
						$hdId, $orderId, $coin, $address, $output['tx_hash'], $output['output_index'], $output['amount_units'],
						$output['confirmations'], $source, $observedAt, $observedAt, self::OBSERVED
					));
				}
				if ($inserted !== 1) {
					return self::RECORD_ERROR;
				}
				continue;
			}

			if ($existing['amount_units'] !== $output['amount_units']) {
				$wpdb->query($wpdb->prepare("UPDATE `$table` SET `state` = %s WHERE `id` = %d", self::CONFLICT, $existing['id']));
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: conflicting evidence for ' . $coin . ' output ' . $output['tx_hash'] . ':' . $output['output_index'] . ' (recorded ' . $existing['amount_units'] . ', now ' . $output['amount_units'] . ').', 'error');
				return self::RECORD_CONFLICT;
			}

			// Refresh the observation. A row seen again after it had vanished (a
			// reorg that came back) returns to observed; credited and conflict
			// rows keep their state.
			$state = $existing['state'] === self::VANISHED ? self::OBSERVED : $existing['state'];
			if ($output['confirmed']) {
				$updated = $wpdb->query($wpdb->prepare(
					"UPDATE `$table` SET `confirmations` = %d, `block_height` = %d, `block_time` = %d, `observed_at` = %d, `source` = %s, `state` = %s
					 WHERE `id` = %d",
					$output['confirmations'], $output['block_height'], $output['block_time'], $observedAt, $source, $state, $existing['id']
				));
			}
			else {
				$updated = $wpdb->query($wpdb->prepare(
					"UPDATE `$table` SET `confirmations` = %d, `block_height` = NULL, `block_time` = NULL, `observed_at` = %d, `source` = %s, `state` = %s
					 WHERE `id` = %d",
					$output['confirmations'], $observedAt, $source, $state, $existing['id']
				));
			}
			if ($updated === false) {
				return self::RECORD_ERROR;
			}
		}

		return self::RECORD_OK;
	}

	/**
	 * After a COMPLETE fresh scan, outputs recorded earlier but no longer
	 * reported have left the chain (a reorg, or a transaction dropped from the
	 * mempool). They stop counting: marked vanished, never deleted.
	 *
	 * @param string[] $seenKeys "tx_hash:output_index" of every output the scan reported
	 * @return bool
	 */
	public static function mark_vanished($hdId, $seenKeys) {
		global $wpdb;
		$table = self::table();
		$rows = self::rows($hdId);
		if ($rows === false) {
			return false;
		}
		$seen = array_flip($seenKeys);
		foreach ($rows as $row) {
			if ($row['state'] === self::VANISHED || isset($seen[$row['tx_hash'] . ':' . $row['output_index']])) {
				continue;
			}
			if ($wpdb->query($wpdb->prepare("UPDATE `$table` SET `state` = %s WHERE `id` = %d", self::VANISHED, $row['id'])) === false) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Bring every confirmed output's confirmation count up to the chain tip a
	 * scan just read. A scan that resumes from saved progress re-reads only
	 * the newest part of the history, so an older output would otherwise keep
	 * the count it had when last read - and never reach the merchant's
	 * requirement. Its block height is known, so tip - height + 1 is exact.
	 * (Whether the output is still on chain is re-checked by the full
	 * re-validation before anything is claimed.)
	 *
	 * @return bool
	 */
	public static function refresh_confirmations($hdId, $tipHeight) {
		global $wpdb;
		$table = self::table();
		return $wpdb->query($wpdb->prepare(
			"UPDATE `$table` SET `confirmations` = %d - `block_height` + 1
			 WHERE `hd_id` = %d AND `state` = %s AND `block_height` IS NOT NULL AND `block_height` <= %d",
			$tipHeight, $hdId, self::OBSERVED, $tipHeight
		)) !== false;
	}

	/** @return array[]|false Every evidence row for assignment $hdId. */
	public static function rows($hdId) {
		global $wpdb;
		$table = self::table();
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT `id`, `tx_hash`, `output_index`, `amount_units`, `confirmations`, `block_height`, `block_time`,
			        `first_seen_at`, `first_seen_pending`, `state`
			 FROM `$table` WHERE `hd_id` = %d ORDER BY `id`",
			$hdId
		), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			return false;
		}
		foreach ($rows as &$row) {
			$row['output_index'] = (int) $row['output_index'];
			$row['confirmations'] = $row['confirmations'] === null ? 0 : (int) $row['confirmations'];
			$row['block_height'] = $row['block_height'] === null ? null : (int) $row['block_height'];
			$row['block_time'] = $row['block_time'] === null ? null : (int) $row['block_time'];
			$row['first_seen_at'] = (int) $row['first_seen_at'];
			$row['first_seen_pending'] = (int) $row['first_seen_pending'] === 1;
		}
		unset($row);
		return $rows;
	}
}
