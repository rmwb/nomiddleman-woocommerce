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
    private static function write($coin, $address, $hash, $orderId = 0) {
        global $wpdb;
        if (!is_string($hash) || $hash === '' || strlen($hash) > 255 || strlen($address) > 199 || strlen($coin) > 32) {
            throw new InvalidArgumentException('Invalid consumed identity');
        }
        $table = self::table();
        $sql = $wpdb->prepare("INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)
            VALUES (%s,%s,%s,%s,%d,%d) ON DUPLICATE KEY UPDATE identity=VALUES(identity)",
            self::identity($coin, $address, $hash), $hash, $address, $coin, $orderId, time());
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
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Unable to start payment claim'); }
            $claim = $repo->claim_for_payment($orderId, $amount);
            if ($claim === NMMPRO_Payment_Repo::CLAIM_DB_ERROR) { throw new RuntimeException('Payment claim failed'); }
            foreach ($hashes as $hash) { self::write($coin, $address, $hash, $orderId); }
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
    public static function drop() {
        global $wpdb;
        $table = self::table(); unset(self::$ready[$table]);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('nmmpro_history_import_') . '%'));
        return $wpdb->query("DROP TABLE IF EXISTS `$table`") !== false;
    }
}
