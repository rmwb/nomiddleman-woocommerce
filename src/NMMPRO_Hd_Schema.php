<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy Mode (HD) schema 1.5: attributable payment evidence.
 *
 * Before 1.5, a HD address row carried only the address's LIFETIME receipts,
 * and the verifier compared that total with the order - so funds received
 * years before the order existed could pay it (the September 2026 incident).
 * 1.5 adds what automatic verification needs instead: a validated,
 * permanently bound assignment with an authoritative time boundary, a
 * per-output evidence table, and an explicit review state for rows whose
 * history cannot be trusted.
 *
 * The upgrade follows the repository's verify-before-version-bump pattern.
 * Every column, the storage engine of each table that must commit together,
 * the evidence table and the legacy policy are confirmed before the version
 * option moves, so a partial or refused migration leaves the version where it
 * was and retries on the next load. Automatic HD processing is gated on the
 * version (ready()), so nothing reaches the payment path on a half-migrated
 * schema.
 *
 * The functions take table names rather than deriving them, so the upgrade
 * can be exercised on scratch tables in the test suite.
 */
class NMMPRO_Hd_Schema {

	const VERSION = '1.5';
	const VERSION_OPTION = 'nmmpro_hd_table_version';
	const EVIDENCE_TABLE = 'nmmpro_hd_evidence';

	// Recorded once, the first time the legacy policy moves rows, so the admin
	// report and the audit can say what the upgrade did on this site.
	const LEGACY_REPORT_OPTION = 'nmmpro_hd_legacy_migration';

	// Review reasons (machine-readable; the admin wording is derived from them).
	const REASON_LEGACY_POOL = 'legacy_pool';
	const REASON_LEGACY_QUARANTINE = 'legacy_quarantine';
	const REASON_LEGACY_UNVALIDATED = 'legacy_unvalidated';

	/**
	 * Columns 1.5 adds to the HD address table. Every one is nullable with a
	 * NULL default: an existing row is "unvalidated" until the new allocator
	 * binds it, and the migration never back-fills these from legacy data (an
	 * old assigned_at is not a trustworthy assignment boundary, and an old
	 * cached zero is not proof an address was never issued).
	 */
	public static function hd_columns() {
		return array(
			// Evidence format of a validated assignment. NULL = legacy/unvalidated.
			'assignment_version'  => 'tinyint unsigned NULL DEFAULT NULL',
			// Set when the new allocator derived the row. A 'ready' row without
			// it may have been issued and recycled by an older release.
			'pool_version'        => 'tinyint unsigned NULL DEFAULT NULL',
			// Authoritative assignment time (database UTC clock), the boundary
			// before which receipts cannot belong to the order.
			'bound_at'            => 'bigint NULL DEFAULT NULL',
			// When the address was last proven clean, and the chain tip then.
			'validated_at'        => 'bigint NULL DEFAULT NULL',
			'validated_height'    => 'bigint NULL DEFAULT NULL',
			// Temporary allocation reservation, distinct from the completion lease.
			'reservation_token'   => 'char(32) NULL DEFAULT NULL',
			'reservation_expires' => 'bigint NULL DEFAULT NULL',
			// Confirmed amount attributable to THIS order, in smallest units.
			// total_received stays as the diagnostic lifetime observation.
			'credited_units'      => 'varchar(40) NULL DEFAULT NULL',
			// Resumable scan progress (JSON).
			'scan_state'          => 'text NULL',
			// Why the row needs a human (see the REASON_ constants).
			'review_reason'       => 'varchar(48) NULL DEFAULT NULL',
		);
	}

	public static function evidence_table() {
		global $wpdb;
		return $wpdb->prefix . self::EVIDENCE_TABLE;
	}

	/** @phpstan-impure */
	public static function ready() {
		return NMMPRO_Compat::get_option(self::VERSION_OPTION, '1.0') === self::VERSION;
	}

	/**
	 * One row per incoming output observed for an assigned address. The unique
	 * key makes a repeated observation (an overlapping page, a re-scan) land on
	 * the same row instead of counting twice; a duplicate that disagrees is a
	 * conflict, recorded in `state`. InnoDB, because evidence and the claim
	 * that credits it must commit or roll back together.
	 *
	 * first_seen_at / first_seen_pending record when this plugin's own scan
	 * first saw the output, and whether it was still unconfirmed then: an
	 * output first seen in the mempool after the address was proven clean
	 * cannot have existed when it was assigned, whatever its block's
	 * (miner-set) timestamp later says.
	 *
	 * tx_hash and amount_units are ASCII: every supported chain's transaction
	 * id is hex, and a single-byte column keeps the unique key within the
	 * index-length limit of older InnoDB row formats.
	 */
	public static function create_evidence_table($table) {
		global $wpdb;
		$sql = "CREATE TABLE IF NOT EXISTS `$table` (
			`id` bigint unsigned NOT NULL AUTO_INCREMENT,
			`hd_id` bigint unsigned NOT NULL,
			`order_id` bigint unsigned NOT NULL DEFAULT 0,
			`coin` varchar(32) NOT NULL,
			`address` varchar(199) NOT NULL,
			`tx_hash` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`output_index` int NOT NULL,
			`amount_units` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`confirmations` bigint unsigned NULL DEFAULT NULL,
			`block_height` bigint unsigned NULL DEFAULT NULL,
			`block_time` bigint unsigned NULL DEFAULT NULL,
			`source` varchar(32) NOT NULL,
			`first_seen_at` bigint unsigned NOT NULL,
			`first_seen_pending` tinyint unsigned NOT NULL DEFAULT 0,
			`observed_at` bigint unsigned NOT NULL,
			`state` varchar(24) NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `output_identity` (`hd_id`, `tx_hash`, `output_index`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB " . $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL built only from wpdb's prefix, this class's fixed table suffix and get_charset_collate(); a table name cannot be a prepare() placeholder and no request data reaches it.
		return $wpdb->query($sql) !== false;
	}

	/**
	 * Bring $hdTable to 1.5 and confirm it. Returns true only when everything
	 * the new path depends on is verifiably in place; the caller bumps the
	 * version only then. Idempotent: every step checks before it acts.
	 */
	public static function upgrade($hdTable, $evidenceTable) {
		global $wpdb;

		// 1. Columns. One ALTER for whatever is missing, then re-read.
		$missing = self::missing_columns($hdTable);
		if ($missing === null) {
			return self::fail('could not read the HD table columns');
		}
		if ($missing !== array()) {
			$columns = self::hd_columns();
			$adds = array();
			foreach ($missing as $name) {
				$adds[] = 'ADD `' . $name . '` ' . $columns[$name];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL from wpdb's prefix, a plugin constant and this class's fixed column definitions; no request data.
			$wpdb->query("ALTER TABLE `$hdTable` " . implode(', ', $adds));
			$missing = self::missing_columns($hdTable);
			if ($missing !== array()) {
				return self::fail('columns still missing after ALTER: ' . ($missing === null ? '(unreadable)' : implode(', ', $missing)));
			}
		}

		// 2. Storage engines. A claim writes the HD row, its evidence and the
		// shared consumed-transaction rows in one transaction; that is only a
		// transaction if every table is transactional. A legacy MyISAM HD table
		// is converted (it is the plugin's own table); if conversion is refused,
		// the new path stays closed rather than promising a rollback it cannot
		// deliver.
		if (self::engine($hdTable) !== 'innodb') {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL from wpdb's prefix and a plugin constant; no request data.
			$wpdb->query("ALTER TABLE `$hdTable` ENGINE=InnoDB");
			if (self::engine($hdTable) !== 'innodb') {
				return self::fail('the HD address table is not InnoDB and could not be converted');
			}
		}

		if (!self::create_evidence_table($evidenceTable)) {
			return self::fail('could not create the evidence table');
		}
		if (self::engine($evidenceTable) !== 'innodb') {
			return self::fail('the evidence table is not InnoDB');
		}
		$identityKey = $wpdb->get_results("SHOW INDEX FROM `$evidenceTable` WHERE Key_name = 'output_identity'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb's prefix and a plugin constant.
		if (empty($identityKey)) {
			return self::fail('the evidence table has no output_identity key');
		}
		$evidenceColumns = (array) $wpdb->get_col("SHOW COLUMNS FROM `$evidenceTable`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb's prefix and a plugin constant.
		if (array_diff(array('first_seen_at', 'first_seen_pending', 'observed_at', 'state'), $evidenceColumns) !== array()) {
			return self::fail('the evidence table is missing columns');
		}

		try {
			NMMPRO_Consumed_Repo::init();
		}
		catch (\Throwable $t) {
			return self::fail('the consumed-transaction table is unavailable: ' . $t->getMessage());
		}
		if (self::engine(NMMPRO_Consumed_Repo::table()) !== 'innodb') {
			return self::fail('the consumed-transaction table is not InnoDB');
		}

		// 3. Legacy rows, before the version moves: nothing old may reach the
		// new automatic path.
		if (!self::apply_legacy_policy($hdTable, true)) {
			return self::fail('the legacy row policy did not complete');
		}

		return true;
	}

	/**
	 * The conservative policy for rows written by releases that trusted
	 * lifetime totals:
	 *
	 *  - 'ready', 'quarantine', 'quarantine_verified' -> 'retired'. Their issue
	 *    history may already have been erased by recycling (an empty
	 *    all_order_ids is not proof of never being issued), so none may be
	 *    handed to a new order. Rows and indexes are kept; derivation continues
	 *    forward from the highest index.
	 *  - 'assigned', 'underpaid', 'completing' without a validated assignment
	 *    -> 'review'. Order, address, amount and assignment time are kept, but
	 *    there is no trustworthy baseline to attribute receipts against, so a
	 *    human decides. A legacy 'completing' flag is not proof of payment.
	 *  - 'complete' and 'dirty' are left alone (audit only).
	 *
	 * Runs in the upgrade and again on every cron tick, so rows an older
	 * release writes after a downgrade-and-re-upgrade are caught too. Every
	 * statement is conditional and idempotent. Returns true once no row in an
	 * unsafe legacy state remains.
	 */
	public static function apply_legacy_policy($hdTable, $recordReport = false) {
		global $wpdb;

		$moves = array(
			'retired_pool' => $wpdb->prepare(
				"UPDATE `$hdTable` SET `status` = 'retired', `review_reason` = COALESCE(`review_reason`, %s)
				 WHERE `status` = 'ready' AND `pool_version` IS NULL",
				self::REASON_LEGACY_POOL),
			'retired_quarantine' => $wpdb->prepare(
				"UPDATE `$hdTable` SET `status` = 'retired', `review_reason` = COALESCE(`review_reason`, %s)
				 WHERE `status` IN ('quarantine', 'quarantine_verified')",
				self::REASON_LEGACY_QUARANTINE),
			'review_unvalidated' => $wpdb->prepare(
				"UPDATE `$hdTable` SET `status` = 'review', `review_reason` = COALESCE(`review_reason`, %s)
				 WHERE `status` IN ('assigned', 'underpaid', 'completing') AND `assignment_version` IS NULL",
				self::REASON_LEGACY_UNVALIDATED),
		);

		$moved = array();
		foreach ($moves as $key => $sql) {
			$affected = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared immediately above.
			if ($affected === false) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode legacy policy: ' . $key . ' failed: ' . $wpdb->last_error, 'error');
				return false;
			}
			$moved[$key] = (int) $affected;
		}

		$remaining = $wpdb->get_var(
			"SELECT COUNT(*) FROM `$hdTable`
			 WHERE (`status` = 'ready' AND `pool_version` IS NULL)
			    OR `status` IN ('quarantine', 'quarantine_verified')
			    OR (`status` IN ('assigned', 'underpaid', 'completing') AND `assignment_version` IS NULL)"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb's prefix and a plugin constant.
		if ($remaining === null || (int) $remaining !== 0) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode legacy policy: ' . ($remaining === null ? 'the check for remaining legacy rows failed' : (int) $remaining . ' legacy rows remain unsafe') . ' after the policy ran.', 'error');
			return false;
		}

		if (array_sum($moved) > 0) {
			NMMPRO_Util::log(__FILE__, __LINE__, sprintf(
				'Privacy Mode legacy policy: retired %d ready and %d quarantined address(es); moved %d in-flight assignment(s) to manual review.',
				$moved['retired_pool'], $moved['retired_quarantine'], $moved['review_unvalidated']), 'warning');

			if ($recordReport && NMMPRO_Compat::get_option(self::LEGACY_REPORT_OPTION, null) === null) {
				NMMPRO_Compat::update_option(self::LEGACY_REPORT_OPTION, array(
					'at'                 => time(),
					'retired_pool'       => $moved['retired_pool'],
					'retired_quarantine' => $moved['retired_quarantine'],
					'review_unvalidated' => $moved['review_unvalidated'],
				), false);
			}
		}

		return true;
	}

	/**
	 * Names of 1.5 columns absent from $table, or null if the table's columns
	 * could not be read (a missing table, a database error).
	 *
	 * @phpstan-impure
	 */
	public static function missing_columns($table) {
		global $wpdb;
		$present = $wpdb->get_col("SHOW COLUMNS FROM `$table`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb's prefix and a plugin constant.
		if (!is_array($present) || $present === array()) {
			return null;
		}
		return array_values(array_diff(array_keys(self::hd_columns()), $present));
	}

	/**
	 * Lower-cased storage engine of $table, or '' when it cannot be read.
	 *
	 * @phpstan-impure
	 */
	public static function engine($table) {
		global $wpdb;
		$engine = $wpdb->get_var($wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		));
		return strtolower((string) $engine);
	}

	private static function fail($why) {
		global $wpdb;
		NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode schema 1.5 not complete (' . $why . ($wpdb->last_error !== '' ? '; ' . $wpdb->last_error : '') . '); automatic HD verification stays off and the upgrade retries on the next load.', 'error');
		return false;
	}
}
