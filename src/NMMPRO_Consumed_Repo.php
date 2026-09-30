<?php
if (!defined('ABSPATH')) { exit; }

/** Durable consumed identities. No count-based or time-based eviction. */
class NMMPRO_Consumed_Repo {
    const TABLE = 'nmmpro_consumed_transactions';
    private static $ready = array();
    public static function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }
    private static function identity($coin, $address, $hash) {
        return hash('sha256', json_encode(array((string) $coin, (string) $address, (string) $hash)));
    }
    public static function init() {
        global $wpdb;
        $table = self::table();
        if (isset(self::$ready[$table])) { return; }
        // ASCII digest avoids binary-string conversion by wpdb's charset checks.
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            identity char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            transaction_hash varchar(255) NOT NULL,
            address varchar(199) NOT NULL,
            coin varchar(32) NOT NULL,
            order_id bigint unsigned NOT NULL DEFAULT 0,
            created_at bigint unsigned NOT NULL,
            PRIMARY KEY (identity), KEY order_id (order_id)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL uses only wpdb's table prefix, this class's fixed table suffix and get_charset_collate(); no request data or arbitrary identifier enters it.
        if ($wpdb->query($sql) === false) { throw new RuntimeException('Unable to create consumed history'); }
        self::$ready[$table] = true;
    }
    /**
     * $strict: a plain INSERT that FAILS on an identity already recorded, for
     * payment claims. Otherwise an idempotent upsert, for history import and
     * for consuming a hash nobody is credited with. A claim must never rely on
     * the upsert: under READ COMMITTED a prior "is it recorded?" read takes no
     * gap lock, so two claims could both see nothing and both upsert.
     */
    private static function write($coin, $address, $hash, $orderId = 0, $strict = false) {
        global $wpdb;
        if (!is_string($hash) || $hash === '' || strlen($hash) > 255 || strlen($address) > 199 || strlen($coin) > 32) {
            throw new InvalidArgumentException('Invalid consumed identity');
        }
        $table = self::table();
        $identity = self::identity($coin, $address, $hash);
        $now = time();
        if ($strict) {
            $sql = $wpdb->prepare("INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)
                VALUES (%s,%s,%s,%s,%d,%d)", $identity, $hash, $address, $coin, $orderId, $now);
        }
        else {
            $sql = $wpdb->prepare("INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)
                VALUES (%s,%s,%s,%s,%d,%d) ON DUPLICATE KEY UPDATE identity=VALUES(identity)", $identity, $hash, $address, $coin, $orderId, $now);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared immediately above; the table is only wpdb's prefix plus this class's fixed suffix.
        if ($wpdb->query($sql) === false) { throw new RuntimeException('Unable to record consumed transaction'); }
    }
    /** @phpstan-impure */
    private static function import_done($marker) { return get_option($marker) === '1'; }
    public static function prepare($coin, $address) {
        self::init();
        global $wpdb;
        $marker = 'nmmpro_history_import_' . hash('sha256', $coin . '|' . $address);
        if (self::import_done($marker)) { return; }
        $legacy = NMMPRO_Compat::get_option('nmmpro_' . $coin . '_transactions_consumed_for_' . $address, array());
        if (!is_array($legacy)) { throw new RuntimeException('Malformed legacy consumed history'); }
        foreach ($legacy as $hash) { self::write($coin, $address, $hash); }
        $payments = $wpdb->prefix . NMMPRO_PAYMENT_TABLE;
        // Recover identities already evicted from the old option where a payment
        // row still records them. Never import unpaid rows as paid transactions.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT order_id,tx_hash FROM `$payments`
            WHERE cryptocurrency=%s AND address=%s AND status<>'unpaid' AND tx_hash<>''", $coin, $address), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) { throw new RuntimeException('Unable to import payment history'); }
        foreach ($rows as $row) {
            $hashes = explode(',', $row['tx_hash']);
            // A 255-character list may have a truncated last element. Earlier
            // complete elements remain useful; never invent the missing suffix.
            if (strlen($row['tx_hash']) === 255 && count($hashes) > 1) { array_pop($hashes); }
            foreach ($hashes as $hash) { if ($hash !== '') { self::write($coin, $address, $hash, $row['order_id']); } }
        }
        NMMPRO_Compat::update_option($marker, '1', false);
        if (!self::import_done($marker)) { throw new RuntimeException('Unable to finish history import'); }
    }
    public static function contains($coin, $address, $hash) {
        self::prepare($coin, $address);
        global $wpdb;
        $table = self::table();
        $found = $wpdb->get_var($wpdb->prepare("SELECT identity FROM `$table` WHERE identity=%s", self::identity($coin, $address, $hash)));
        if ($wpdb->last_error !== '') { throw new RuntimeException('Unable to read consumed history'); }
        return $found !== null;
    }
    public static function add($coin, $address, $hash) {
        self::prepare($coin, $address);
        self::write($coin, $address, $hash);
        return true;
    }
    public static function add_many($coin, $address, $hashes) {
        self::prepare($coin, $address);
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Unable to start history transaction'); }
        try {
            foreach ($hashes as $hash) { self::write($coin, $address, $hash); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Unable to commit history'); }
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        return true;
    }
    /** Caller holds the per-address advisory lock. Claim + history commit together. */
    public static function claim($repo, $coin, $address, $orderId, $amount, $hashes) {
        global $wpdb;
        try {
            self::prepare($coin, $address);
            // Payment rows must also be transactional; do not silently promise
            // rollback on a legacy MyISAM installation.
            $payments = $wpdb->prefix . NMMPRO_PAYMENT_TABLE;
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $payments));
            if (strtolower((string) $engine) !== 'innodb') { throw new RuntimeException('Payment table requires InnoDB'); }
            if (!is_array($hashes) || count($hashes) === 0) { throw new InvalidArgumentException('A payment claim needs at least one transaction'); }
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Unable to start payment claim'); }
            // The per-address lock and the matcher's consumed check normally keep
            // an already-recorded hash out. This read only gives that case a
            // clear error; the guarantee is the strict INSERT below, which fails
            // on a recorded identity at any isolation level - a plain read takes
            // no gap lock under READ COMMITTED, and FOR UPDATE could deadlock.
            $table = self::table();
            $identities = array();
            foreach ($hashes as $hash) { $identities[] = self::identity($coin, $address, $hash); }
            $placeholders = implode(',', array_fill(0, count($identities), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table is wpdb's prefix plus this class's fixed suffix, and $placeholders is only literal %s markers; every identity is bound by prepare().
            $sql = "SELECT COUNT(*) FROM `$table` WHERE identity IN ($placeholders)";
            $owned = $wpdb->get_var($wpdb->prepare($sql, $identities)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared in this call; see the note above.
            if ($owned === null || $wpdb->last_error !== '') { throw new RuntimeException('Unable to check consumed history before claiming'); }
            if ((int) $owned !== 0) { throw new RuntimeException('A transaction in this claim is already recorded as consumed'); }
            $claim = $repo->claim_for_payment($orderId, $amount);
            if ($claim === NMMPRO_Payment_Repo::CLAIM_DB_ERROR) { throw new RuntimeException('Payment claim failed'); }
            if ($claim === NMMPRO_Payment_Repo::CLAIM_ALREADY) {
                // Losing the row is only conclusive when it is TERMINAL. An
                // expiry holding a 'cancelling' lease may still hand it back to
                // 'unpaid' (WooCommerce refused the cancellation), and a hash
                // consumed now could then never pay it. Consume nothing; retry.
                $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM `$payments` WHERE order_id=%d AND order_amount=%s FOR UPDATE", $orderId, $amount));
                // null is a failed read (or a row that vanished): unknown, so
                // retry rather than consume.
                if ($current === null) { throw new RuntimeException('Unable to read the lost payment row'); }
                if ($current === 'cancelling') { throw new RuntimeException('Payment row is mid-cancellation; claim retried later'); }
            }
            foreach ($hashes as $hash) { self::write($coin, $address, $hash, $orderId, true); }
            if ($claim === NMMPRO_Payment_Repo::CLAIM_CLAIMED) {
                $stored = substr(implode(',', $hashes), 0, 255);
                if ($wpdb->query($wpdb->prepare("UPDATE `$payments` SET status='completing',tx_hash=%s WHERE order_id=%d AND order_amount=%s AND status='paid'", $stored, $orderId, $amount)) !== 1) {
                    throw new RuntimeException('Unable to record recoverable completion');
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Payment claim commit failed'); }
            return $claim;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            NMMPRO_Util::log(__FILE__, __LINE__, $e->getMessage(), 'error');
            return NMMPRO_Payment_Repo::CLAIM_DB_ERROR;
        }
    }
    /**
     * Ownership of each transaction identity, for Privacy Mode: the order id
     * that consumed it (0 = consumed by an older release or imported history,
     * owner unknown), or null when nobody has. Any read failure throws: an
     * unknown owner must never be read as "unclaimed".
     *
     * @param string[] $hashes
     * @return array<string, int|null> hash => owner
     */
    public static function owners($coin, $address, $hashes) {
        self::prepare($coin, $address);
        global $wpdb;
        $table = self::table();
        $out = array();
        foreach ($hashes as $hash) {
            $owner = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM `$table` WHERE identity=%s", self::identity($coin, $address, $hash)));
            if ($wpdb->last_error !== '') { throw new RuntimeException('Unable to read consumed history'); }
            $out[$hash] = $owner === null ? null : (int) $owner;
        }
        return $out;
    }

    /**
     * Privacy Mode claim: in ONE InnoDB transaction, record this order as the
     * owner of every contributing transaction, mark the contributing evidence
     * credited, and move the address row to 'completing' - or do none of it.
     *
     * A transaction this order already owns (a completion that failed after
     * an earlier claim) is kept, not re-inserted, so a same-order retry works.
     * Every other identity is written with the strict INSERT, which fails on an
     * identity recorded meanwhile by anyone - another order, Autopay, or an
     * import - at any isolation level; the whole claim then rolls back. The
     * address row moves only from an open state and only for this order.
     *
     * Caller holds the per-address match lock and has already excluded
     * transactions owned by anyone else.
     *
     * @param string[] $hashes       contributing transaction hashes
     * @param int[]    $evidenceIds  contributing evidence row ids
     * @return string NMMPRO_Hd_Repo::CLAIM_* constant
     */
    public static function claim_hd($coin, $address, $orderId, $hdId, $hashes, $evidenceIds, $creditedUnits) {
        global $wpdb;
        try {
            self::prepare($coin, $address);
            $hdTable = $wpdb->prefix . NMMPRO_HD_TABLE;
            $evidenceTable = NMMPRO_Hd_Schema::evidence_table();
            foreach (array($hdTable, $evidenceTable, self::table()) as $tableName) {
                if (NMMPRO_Hd_Schema::engine($tableName) !== 'innodb') { throw new RuntimeException('Privacy Mode claim requires InnoDB tables'); }
            }
            if (count($hashes) === 0 || count($evidenceIds) === 0) {
                throw new InvalidArgumentException('A payment claim needs at least one transaction');
            }
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Unable to start payment claim'); }

            $owners = self::owners($coin, $address, $hashes);
            foreach ($owners as $hash => $owner) {
                if ($owner === (int) $orderId) {
                    continue;
                }
                if ($owner !== null) {
                    throw new RuntimeException(esc_html('Transaction ' . $hash . ' is already owned by ' . ($owner === 0 ? 'an unknown earlier owner' : 'order ' . $owner)));
                }
                self::write($coin, $address, $hash, $orderId, true);
            }

            $placeholders = implode(',', array_fill(0, count($evidenceIds), '%d'));
            $args = array_merge(array(NMMPRO_Hd_Evidence_Repo::CREDITED, (int) $hdId), array_map('intval', $evidenceIds));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table is wpdb's prefix plus a plugin constant, and $placeholders is only literal %d markers; every value is bound by prepare().
            $sql = "UPDATE `$evidenceTable` SET `state` = %s WHERE `hd_id` = %d AND `state` <> 'conflict' AND `id` IN ($placeholders)";
            $marked = $wpdb->query($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared in this call; see above.
            if ($marked === false) { throw new RuntimeException('Unable to mark evidence credited'); }

            $moved = $wpdb->query($wpdb->prepare(
                "UPDATE `$hdTable` SET `status` = 'completing', `last_checked` = UNIX_TIMESTAMP(), `credited_units` = %s
                 WHERE `id` = %d AND `order_id` = %d AND `assignment_version` IS NOT NULL
                 AND `status` IN ('assigned', 'underpaid', 'completing')",
                $creditedUnits, $hdId, $orderId
            ));
            if ($moved === false) { throw new RuntimeException('Unable to record recoverable completion'); }
            if ($moved !== 1) {
                // MySQL counts only CHANGED rows: resuming a 'completing' row of
                // this order within the same second changes nothing. What
                // matters is the row's state now, read under the row lock.
                $now = $wpdb->get_row($wpdb->prepare("SELECT `status`, `order_id` FROM `$hdTable` WHERE `id` = %d FOR UPDATE", $hdId), ARRAY_A);
                if (!is_array($now) || $now['status'] !== 'completing' || (int) $now['order_id'] !== (int) $orderId) {
                    $wpdb->query('ROLLBACK');
                    return NMMPRO_Hd_Repo::CLAIM_ALREADY;
                }
            }

            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Payment claim commit failed'); }
            return NMMPRO_Hd_Repo::CLAIM_CLAIMED;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode claim for order ' . (int) $orderId . ' rolled back: ' . $e->getMessage(), 'error');
            return NMMPRO_Hd_Repo::CLAIM_DB_ERROR;
        }
    }

    /**
     * Manual reconciliation: record, in one transaction, that $orderId owns
     * each of $hashes (verified on chain by a person). Identities this order
     * already owns are kept; one owned by any other order - or by an unknown
     * earlier owner - refuses the whole call, so a manual action can never
     * move funds between orders. Strict inserts arbitrate any race.
     *
     * @return array{recorded: string[], already: string[]}
     * @throws RuntimeException When refused or on a database failure (nothing recorded).
     */
    public static function record_manual($coin, $address, $orderId, $hashes) {
        global $wpdb;
        self::prepare($coin, $address);
        if (NMMPRO_Hd_Schema::engine(self::table()) !== 'innodb') { throw new RuntimeException('The consumed-transaction table must be InnoDB'); }
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Unable to start the reconciliation'); }
        try {
            $out = array('recorded' => array(), 'already' => array());
            foreach (self::owners($coin, $address, $hashes) as $hash => $owner) {
                if ($owner === (int) $orderId) {
                    $out['already'][] = $hash;
                    continue;
                }
                if ($owner !== null) {
                    throw new RuntimeException(esc_html('Transaction ' . $hash . ' is already recorded against ' . ($owner === 0 ? 'an unknown earlier owner' : 'order ' . $owner) . '; nothing was recorded.'));
                }
                self::write($coin, $address, $hash, $orderId, true);
                $out['recorded'][] = $hash;
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Unable to commit the reconciliation'); }
            return $out;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new RuntimeException(esc_html($e->getMessage()));
        }
    }

    public static function drop() {
        global $wpdb;
        $table = self::table(); unset(self::$ready[$table]);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('nmmpro_history_import_') . '%'));
        return $wpdb->query("DROP TABLE IF EXISTS `$table`") !== false;
    }
}
