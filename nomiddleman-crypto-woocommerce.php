<?php
/*
WC requires at least: 3.0.0
WC tested up to: 10.8
Plugin Name: Nomiddleman Bitcoin and Crypto Payments for WooCommerce
Plugin URI:  https://wordpress.org/plugins/nomiddleman-crypto-payments-for-woocommerce/
Description: WooCommerce Bitcoin and Cryptocurrency Payment Gateway
Author: nomiddleman
Author URI: https://github.com/rmwb/nomiddleman-woocommerce
Version: 2.9.6
Requires PHP: 7.4
Text Domain: nomiddleman-crypto-payments-for-woocommerce
Domain Path: /languages
Copyright: © 2020 Nomiddleman Crypto, © 2026 rmwb
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('init', 'NMM_load_textdomain');
add_action('plugins_loaded', 'NMM_init_gateways');
add_action('before_woocommerce_init', 'NMM_declare_wc_feature_compatibility');
add_action('woocommerce_blocks_loaded', 'NMM_register_blocks_support');

function NMM_load_textdomain() {
    load_plugin_textdomain('nomiddleman-crypto-payments-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

function NMM_declare_wc_feature_compatibility() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

function NMM_register_blocks_support() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once(plugin_basename('src/NMM_Blocks_Support.php'));

    add_action('woocommerce_blocks_payment_method_type_registration', function($payment_method_registry) {
        $payment_method_registry->register(new NMM_Blocks_Support());
    });
}
register_activation_hook(__FILE__, 'NMM_activate');
register_deactivation_hook(__FILE__, 'NMM_deactivate');
register_uninstall_hook(__FILE__, 'NMM_uninstall');
define('NMM_HD_TABLE', 'nmmpro_hd_addresses');
define('NMM_PAYMENT_TABLE', 'nmmpro_payments');
define('NMM_CAROUSEL_TABLE', 'nmmpro_carousel');
define('NMM_SOL_RETRY_TABLE', 'nmmpro_sol_retry');
define('NMM_LOGFILE_NAME', 'nmm.log');
define('NMM_REDUX_ID', 'nmmpro_redux_options');
define('NMM_EXTENSION_KEY', 'nmm_registered_extensions');

require_once(plugin_basename('src/NMM_Settings.php'));

function NMM_init_gateways(){

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };

    define('NMM_PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)));    
    define('NMM_PLUGIN_FILE', __FILE__);
    define('NMM_ABS_PATH', dirname(NMM_PLUGIN_FILE));

    define('NMM_VERSION', '2.9.6');
    
    define('NMM_REDUX_SLUG', 'nmmpro_options');

    // Vendor
    if (!class_exists('bcmath_Utils')) {
        require_once(plugin_basename('src/vendor/bcmath_Utils.php'));
    }
    if (!class_exists('CurveFp')) {
        require_once(plugin_basename('src/vendor/CurveFp.php'));
    }
    if (!class_exists('HdHelper')) {
        require_once(plugin_basename('src/vendor/HdHelper.php'));
    }
    if (!class_exists('gmp_Utils')) {
        require_once(plugin_basename('src/vendor/gmp_Utils.php'));
    }
    if (!class_exists('NumberTheory')) {
        require_once(plugin_basename('src/vendor/NumberTheory.php'));
    }
    if (!class_exists('Point')) {
        require_once(plugin_basename('src/vendor/Point.php'));
    }
    if (!class_exists('\CashAddress\CashAddress')) {
        require_once(plugin_basename('src/vendor/CashAddress.php'));
    }
    if (!class_exists('QRinput')) {
        require_once(plugin_basename('src/vendor/phpqrcode.php'));
    }

    // Http
    require_once(plugin_basename('src/NMM_Exchange.php'));
    require_once(plugin_basename('src/NMM_Blockchain.php'));

    // Database
    require_once(plugin_basename('src/NMM_Carousel_Repo.php'));
    require_once(plugin_basename('src/NMM_Hd_Repo.php'));
    require_once(plugin_basename('src/NMM_Payment_Repo.php'));
    require_once(plugin_basename('src/NMM_Sol_Retry_Repo.php'));

    // Simple Objects
    require_once(plugin_basename('src/NMM_Cryptocurrency.php'));
    require_once(plugin_basename('src/NMM_Transaction.php'));
    
    // Business Logic
    require_once(plugin_basename('src/NMM_Cryptocurrencies.php'));
    require_once(plugin_basename('src/NMM_Carousel.php'));
    require_once(plugin_basename('src/NMM_Hd.php'));    
    require_once(plugin_basename('src/NMM_Payment.php'));

    // Misc
    require_once(plugin_basename('src/NMM_Qr.php'));
    require_once(plugin_basename('src/NMM_Monero.php'));
    require_once(plugin_basename('src/NMM_Util.php'));
    require_once(plugin_basename('src/NMM_Hooks.php'));
    require_once(plugin_basename('src/NMM_Cron.php'));
    require_once(plugin_basename('src/NMM_Admin.php'));
    require_once(plugin_basename('src/NMM_Settings.php'));
    
    require_once(plugin_basename('src/NMM_Validation.php'));

    // Core
    require_once(plugin_basename('src/NMM_Gateway.php'));
    
    add_filter ('cron_schedules', 'NMM_add_interval');

    add_action('NMM_cron_hook', 'NMM_do_cron_job');
    add_action('woocommerce_order_status_changed', 'NMM_update_database_when_admin_changes_order_status', 10, 3);
    
    if (is_admin()) {
        add_action('wp_ajax_firstmpkaddress', 'NMM_first_mpk_address_ajax');
        add_filter('site_status_tests', 'NMM_register_site_health_test');
    }

    // thank-you page payment status poller (guests included)
    add_action('wp_ajax_nmm_order_status', 'NMM_order_status_ajax');
    add_action('wp_ajax_nopriv_nmm_order_status', 'NMM_order_status_ajax');

    NMM_Register_Extensions();
    NMM_update_hd_table();
    NMM_maybe_create_sol_retry_table();
    NMM_maybe_add_payment_indexes();

    add_action('init', 'NMM_schedule_payment_checks');
    add_action('admin_init', 'NMM_cleanup_legacy_qr_files');
}

// QR codes used to be written to the plugin dir as tmp{orderId}_qrcode.png -
// world-readable at guessable URLs, one per order, never deleted. They are
// now rendered in memory; sweep any leftovers from older versions once.
function NMM_cleanup_legacy_qr_files() {
    if (get_option('nmm_legacy_qr_files_cleaned')) {
        return;
    }

    $files = glob(NMM_ABS_PATH . '/assets/img/tmp*_qrcode.png');

    if (is_array($files)) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    update_option('nmm_legacy_qr_files_cleaned', 1);
}

// Prefer Action Scheduler (bundled with WooCommerce) over WP-Cron: it runs
// reliably in the background, survives object caches, and has an admin UI.
function NMM_schedule_payment_checks() {
    if (function_exists('as_schedule_recurring_action') && function_exists('as_next_scheduled_action')) {
        // migrate any legacy WP-Cron schedule
        if (wp_next_scheduled('NMM_cron_hook')) {
            wp_clear_scheduled_hook('NMM_cron_hook');
        }

        if (false === as_next_scheduled_action('NMM_cron_hook', array(), 'nomiddleman')) {
            as_schedule_recurring_action(time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, 'NMM_cron_hook', array(), 'nomiddleman');
        }

        return;
    }

    if (!wp_next_scheduled('NMM_cron_hook')) {
        wp_schedule_event(time(), 'minutes_1', 'NMM_cron_hook');
    }
}

function NMM_add_interval ($schedules)
{
    $schedules['seconds_5'] = array('interval'=>5, 'display'=>'debug');
    $schedules['seconds_30'] = array('interval'=>30, 'display'=>'Bi-minutely');
    $schedules['minutes_1'] = array('interval'=>60, 'display'=>'Once every 1 minute');
    $schedules['minutes_2'] = array('interval'=>120, 'display'=>'Once every 2 minutes');

    return $schedules;
}

function NMM_activate() {
    // scheduling happens on init via NMM_schedule_payment_checks
    NMM_create_hd_mpk_address_table();
    // remove leftovers from the retired flash-notice queue
    delete_option('my_flash_notices');
    delete_option('nmm_flash_notices');
    NMM_create_payment_table();
    NMM_create_carousel_table();
    NMM_maybe_create_sol_retry_table();
    NMM_maybe_add_payment_indexes();
}

// Create/repair the durable Solana retry-queue table (gated by a schema version
// so it does not run on every load, but DOES re-run to add new indexes when the
// schema is bumped), confirming columns and indexes before recording success.
function NMM_maybe_create_sol_retry_table() {
    $schemaVersion = '2'; // bump when the retry table's columns/indexes change
    if (get_option('nmm_sol_retry_schema') === $schemaVersion) {
        return;
    }

    global $wpdb;
    NMM_create_sol_retry_table();

    $tableName = $wpdb->prefix . NMM_SOL_RETRY_TABLE;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) !== $tableName) {
        NMM_Util::log(__FILE__, __LINE__, 'Solana retry table not created (' . $wpdb->last_error . '); will retry next load.', 'error');
        return;
    }

    // CREATE TABLE IF NOT EXISTS will not repair a pre-existing table that is
    // missing a column or index, so check and fix those explicitly. A missing
    // addr_sig unique key in particular would break the idempotent upsert.
    $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM `$tableName`", 0); // Field
    $requiredColumns = array('id', 'address', 'signature', 'first_failed_at', 'attempts', 'next_retry_at', 'block_time');
    $columnsOk = count(array_intersect($requiredColumns, $columns)) === count($requiredColumns);

    // Add any missing required index. For the unique key, collapse any duplicate
    // (address, signature) rows first (a table that ran without it could have
    // accumulated them), keeping the lowest id, or the ADD would fail.
    $indexDefs = array(
        'addr_sig'          => 'ADD UNIQUE KEY `addr_sig` (`address`, `signature`)',
        'addr_due'          => 'ADD KEY `addr_due` (`address`, `next_retry_at`)',
        'addr_block_time'   => 'ADD KEY `addr_block_time` (`address`, `block_time`)',
        'addr_first_failed' => 'ADD KEY `addr_first_failed` (`address`, `first_failed_at`)',
        'first_failed'      => 'ADD KEY `first_failed` (`first_failed_at`)',
    );
    $present = (array) $wpdb->get_col("SHOW INDEX FROM `$tableName`", 2); // Key_name
    foreach ($indexDefs as $name => $def) {
        if (in_array($name, $present, true)) {
            continue;
        }
        if ($name === 'addr_sig') {
            $wpdb->query(
                "DELETE t1 FROM `$tableName` t1
                 INNER JOIN `$tableName` t2
                 ON t1.address = t2.address AND t1.signature = t2.signature AND t1.id > t2.id"
            );
        }
        $wpdb->query("ALTER TABLE `$tableName` $def");
    }

    $present = (array) $wpdb->get_col("SHOW INDEX FROM `$tableName`", 2);
    $indexesOk = count(array_intersect(array_keys($indexDefs), $present)) === count($indexDefs);

    // Record success only once the schema is fully present, so a partial or
    // failed repair retries next load instead of being masked as complete.
    if ($columnsOk && $indexesOk) {
        update_option('nmm_sol_retry_schema', $schemaVersion);
        delete_option('nmm_sol_retry_table_created'); // retire the pre-versioned flag
    }
    else {
        NMM_Util::log(__FILE__, __LINE__, 'Solana retry schema incomplete (' . $wpdb->last_error . '); will retry next load.', 'error');
    }
}

function NMM_create_sol_retry_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . NMM_SOL_RETRY_TABLE;

    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `address` char(64) NOT NULL,
            `signature` char(96) NOT NULL,
            `first_failed_at` bigint(20) NOT NULL DEFAULT '0',
            `attempts` int(11) NOT NULL DEFAULT '0',
            `next_retry_at` bigint(20) NOT NULL DEFAULT '0',
            `block_time` bigint(20) NOT NULL DEFAULT '0',

            PRIMARY KEY (`id`),
            UNIQUE KEY `addr_sig` (`address`, `signature`),
            KEY `addr_due` (`address`, `next_retry_at`),
            KEY `addr_block_time` (`address`, `block_time`),
            KEY `addr_first_failed` (`address`, `first_failed_at`),
            KEY `first_failed` (`first_failed_at`)
        );";

    $wpdb->query($query);
}

function NMM_deactivate() {
    wp_clear_scheduled_hook('NMM_cron_hook');

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('NMM_cron_hook', array(), 'nomiddleman');
    }
}

function NMM_uninstall() {
    NMM_drop_mpk_address_table();
    NMM_drop_payment_table();
    NMM_drop_carousel_table();
    NMM_drop_sol_retry_table();
    delete_option('nmm_autopay_scan_cursor');
    delete_option('nmm_autopay_scan_retry');
    delete_option('nmm_autopay_scan_last_run');
    delete_option('nmm_autopay_scan_covered_at');
    delete_option('nmm_autopay_scan_sweep_start');
    delete_option('nmm_autopay_scan_dirty');
}

// The retry table is created per site (each blog has its own prefix), so drop it
// per site on a network uninstall; otherwise sub-site tables would be orphaned.
function NMM_drop_sol_retry_table() {
    global $wpdb;

    if (is_multisite()) {
        $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        foreach ($blogIds as $blogId) {
            switch_to_blog($blogId);
            $wpdb->query("DROP TABLE IF EXISTS `" . $wpdb->prefix . NMM_SOL_RETRY_TABLE . "`");
            delete_option('nmm_sol_retry_schema');
            delete_option('nmm_sol_retry_table_created');
            restore_current_blog();
        }
        return;
    }

    $wpdb->query("DROP TABLE IF EXISTS `" . $wpdb->prefix . NMM_SOL_RETRY_TABLE . "`");
    delete_option('nmm_sol_retry_schema');
    delete_option('nmm_sol_retry_table_created');
}

function NMM_drop_mpk_address_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . NMM_HD_TABLE;
    
    $query = "DROP TABLE IF EXISTS `$tableName`";
    $wpdb->query($query);
}

function NMM_drop_payment_table() {
    global $wpdb;    
    $tableName = $wpdb->prefix . NMM_PAYMENT_TABLE;    
    
    $query = "DROP TABLE IF EXISTS `$tableName`";
    $wpdb->query($query);
}

function NMM_drop_carousel_table() {
    global $wpdb;    
    $tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;    
    
    $query = "DROP TABLE IF EXISTS `$tableName`";
    $wpdb->query($query);
}

function NMM_create_hd_mpk_address_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . NMM_HD_TABLE;
    
    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
            `mpk` char(150) NOT NULL,
            `mpk_index` bigint(20) NOT NULL DEFAULT '0',
            `address` char(199) NOT NULL,
            `cryptocurrency` char(7) NOT NULL,
            `status` char(24)  NOT NULL DEFAULT 'error',
            `total_received` decimal( 16, 8 ) NOT NULL DEFAULT '0.00000000',
            `last_checked` bigint(20) NOT NULL DEFAULT '0',
            `assigned_at` bigint(20) NOT NULL DEFAULT '0',
            `order_id` bigint(10) NULL,            
            `order_amount` decimal(16, 8) NOT NULL DEFAULT '0.00000000',
            `all_order_ids` text NULL,
    
            PRIMARY KEY (`id`),
            UNIQUE KEY `hd_address` (`cryptocurrency`, `address`),
            KEY `status` (`status`),
            KEY `status_checked` (`status`, `last_checked`),
            KEY `mpk_index` (`mpk_index`),
            KEY `mpk` (`mpk`),
            KEY `order_lookup` (`order_id`, `id`)
            /* hd_mode is added by NMM_update_hd_table (1.0->1.1), so the
               composite indexes that reference it are added there too (1.3->1.4). */
        );";

    $wpdb->query($query);
}

function NMM_update_hd_table() {
    global $wpdb;

    $tableName = $wpdb->prefix . NMM_HD_TABLE;

    // 1.0 -> 1.1: add the hd_mode column. Advance the version only after
    // confirming the column exists, so a failed ALTER (timeout, privileges)
    // retries on the next run instead of being masked by a bumped version.
    if (get_option('nmm_hd_table_version', '1.0') === '1.0') {
        $hasColumn = $wpdb->get_results("SHOW COLUMNS FROM `$tableName` LIKE 'hd_mode'");
        if (empty($hasColumn)) {
            $wpdb->query("ALTER TABLE `$tableName` ADD `hd_mode` bigint(10) NOT NULL default '0'");
        }

        $confirmColumn = $wpdb->get_results("SHOW COLUMNS FROM `$tableName` LIKE 'hd_mode'");
        if (!empty($confirmColumn)) {
            update_option('nmm_hd_table_version', '1.1');
        }
        else {
            NMM_Util::log(__FILE__, __LINE__, 'HD hd_mode migration did not complete (' . $wpdb->last_error . '); leaving version at 1.0 to retry.', 'error');
        }
    }

    // 1.1 -> 1.2: guarantee no two rows share a (cryptocurrency, address) pair.
    // Older installs predate the UNIQUE KEY now in NMM_create_hd_mpk_address_table();
    // without it, a concurrent-derivation race could insert the same derived
    // address twice and hand it to two different orders.
    if (get_option('nmm_hd_table_version', '1.0') === '1.1') {
        // Collapse any pre-existing duplicates before adding the constraint.
        // Do NOT blindly keep the lowest id: a higher-id duplicate may be the
        // one actually assigned to a live order or holding received funds.
        // Reconcile each duplicate group and keep the most operationally
        // important row (funds first, then an active/assigned row, then order
        // association, then most recent), logging any dropped row that carried
        // an order or funds so a human can follow up.
        NMM_reconcile_duplicate_hd_addresses($tableName);

        // Add the unique key only if it is not already present.
        $existing = $wpdb->get_results("SHOW INDEX FROM `$tableName` WHERE Key_name = 'hd_address'");
        if (empty($existing)) {
            $wpdb->query("ALTER TABLE `$tableName` ADD UNIQUE KEY `hd_address` (`cryptocurrency`, `address`)");
        }

        // Advance the version only once the unique key is actually present, so
        // a failed dedupe/ALTER (timeout, privileges, a duplicate left behind
        // by an error) retries next run instead of permanently recording
        // success and leaving the concurrency guarantee unenforced.
        $confirmIndex = $wpdb->get_results("SHOW INDEX FROM `$tableName` WHERE Key_name = 'hd_address'");
        if (!empty($confirmIndex)) {
            update_option('nmm_hd_table_version', '1.2');
        }
        else {
            NMM_Util::log(__FILE__, __LINE__, 'HD unique-key migration did not complete (' . $wpdb->last_error . '); leaving version at 1.1 to retry.', 'error');
        }
    }

    // 1.2 -> 1.3: add a (status, last_checked) index so the quarantine batch
    // query - WHERE status IN (...) ORDER BY last_checked LIMIT N - stays fast
    // when a burst of abandoned checkouts leaves many rows awaiting re-checks.
    if (get_option('nmm_hd_table_version', '1.0') === '1.2') {
        $existing = $wpdb->get_results("SHOW INDEX FROM `$tableName` WHERE Key_name = 'status_checked'");
        if (empty($existing)) {
            $wpdb->query("ALTER TABLE `$tableName` ADD KEY `status_checked` (`status`, `last_checked`)");
        }

        $confirm = $wpdb->get_results("SHOW INDEX FROM `$tableName` WHERE Key_name = 'status_checked'");
        if (!empty($confirm)) {
            update_option('nmm_hd_table_version', '1.3');
        }
        else {
            NMM_Util::log(__FILE__, __LINE__, 'HD status_checked index migration did not complete (' . $wpdb->last_error . '); leaving version at 1.2 to retry.', 'error');
        }
    }

    // 1.3 -> 1.4: composite indexes for the hot HD queries. order_lookup serves
    // the 15s customer status poll (WHERE order_id = ? ORDER BY id); hd_pool and
    // hd_wallet_status serve the cron's pool/claim/pending/assigned queries that
    // filter by cryptocurrency + hd_mode + status (and order by mpk_index or
    // filter by mpk). These reference hd_mode, which only exists after 1.0->1.1.
    if (get_option('nmm_hd_table_version', '1.0') === '1.3') {
        $wanted = array(
            'order_lookup'     => 'ADD KEY `order_lookup` (`order_id`, `id`)',
            'hd_pool'          => 'ADD KEY `hd_pool` (`cryptocurrency`, `hd_mode`, `status`, `mpk_index`)',
            'hd_wallet_status' => 'ADD KEY `hd_wallet_status` (`cryptocurrency`, `hd_mode`, `status`, `mpk`)',
        );
        $present = (array) $wpdb->get_col("SHOW INDEX FROM `$tableName`", 2);
        foreach ($wanted as $name => $def) {
            if (!in_array($name, $present, true)) {
                $wpdb->query("ALTER TABLE `$tableName` $def");
            }
        }

        $present = (array) $wpdb->get_col("SHOW INDEX FROM `$tableName`", 2);
        if (count(array_intersect(array_keys($wanted), $present)) === count($wanted)) {
            update_option('nmm_hd_table_version', '1.4');
        }
        else {
            NMM_Util::log(__FILE__, __LINE__, 'HD composite-index migration did not complete (' . $wpdb->last_error . '); leaving version at 1.3 to retry.', 'error');
        }
    }

}

// Collapse duplicate (cryptocurrency, address) rows down to one, choosing the
// keeper by operational importance rather than by id, so the migration to a
// UNIQUE KEY can never silently discard the row an order actually depends on.
function NMM_reconcile_duplicate_hd_addresses($tableName) {
    global $wpdb;

    $dupes = $wpdb->get_results(
        "SELECT cryptocurrency, address FROM `$tableName`
         GROUP BY cryptocurrency, address HAVING COUNT(*) > 1"
    );

    foreach ((array) $dupes as $dupe) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, order_id, total_received FROM `$tableName`
             WHERE cryptocurrency = %s AND address = %s",
            $dupe->cryptocurrency, $dupe->address
        ));

        if (count($rows) < 2) {
            continue;
        }

        // Rank so the most important row sorts first: funded, then an active
        // status (assigned/underpaid/complete), then any order association,
        // then the most recently derived (highest id).
        usort($rows, 'NMM_compare_hd_rows_for_keep');
        $keeper = array_shift($rows);

        foreach ($rows as $loser) {
            if (!empty($loser->order_id) || (float) $loser->total_received > 0) {
                NMM_Util::log(__FILE__, __LINE__, sprintf(
                    'HD dedupe: dropping duplicate %s address %s row id %d (order %s, received %s) in favour of row id %d; manual review may be needed.',
                    $dupe->cryptocurrency, $dupe->address, $loser->id,
                    $loser->order_id, $loser->total_received, $keeper->id
                ));
            }
            $wpdb->delete($tableName, array('id' => $loser->id), array('%d'));
        }
    }
}

// Sort comparator: returns the more important HD row first.
function NMM_compare_hd_rows_for_keep($a, $b) {
    $rank = function ($row) {
        return array(
            ((float) $row->total_received > 0) ? 1 : 0,                              // funded wins
            in_array($row->status, array('assigned', 'underpaid', 'complete'), true) ? 1 : 0, // active state
            !empty($row->order_id) ? 1 : 0,                                          // has an order
            (int) $row->id,                                                          // most recent
        );
    };
    $ra = $rank($a);
    $rb = $rank($b);
    foreach ($ra as $i => $va) {
        if ($va !== $rb[$i]) {
            return ($va > $rb[$i]) ? -1 : 1; // higher rank sorts first
        }
    }
    return 0;
}

function NMM_create_payment_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . NMM_PAYMENT_TABLE;
    
    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
            `address` char(199) NOT NULL,
            `cryptocurrency` char(7) NOT NULL,
            `status` char(24)  NOT NULL DEFAULT 'error',
            `ordered_at` bigint(20) NOT NULL DEFAULT '0',
            `order_id` bigint(10) NOT NULL DEFAULT '0',
            `order_amount` decimal(32, 18) NOT NULL DEFAULT '0.000000000000000000',
            `tx_hash` char(255) NULL,
            `hd_address` tinyint(4) NOT NULL DEFAULT '0',

    
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_payment` (`order_id`, `order_amount`),
            KEY `status` (`status`),
            KEY `unpaid_address` (`status`, `cryptocurrency`, `address`),
            KEY `unpaid_expiry` (`status`, `ordered_at`)
        );";

    $wpdb->query($query);
}

// Add the composite indexes the Autopay hot paths need to existing payment
// tables (verify-then-record, like the other schema migrations). The matcher
// queries WHERE status='unpaid' AND cryptocurrency=? AND address=?, and expiry
// scans WHERE status='unpaid' ordered by ordered_at.
function NMM_maybe_add_payment_indexes() {
    if (get_option('nmm_payment_index_version') === '1') {
        return;
    }

    global $wpdb;
    $tableName = $wpdb->prefix . NMM_PAYMENT_TABLE;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) !== $tableName) {
        return; // table not created yet; nothing to do
    }

    $wanted = array(
        'unpaid_address' => 'ADD KEY `unpaid_address` (`status`, `cryptocurrency`, `address`)',
        'unpaid_expiry'  => 'ADD KEY `unpaid_expiry` (`status`, `ordered_at`)',
    );
    $present = (array) $wpdb->get_col("SHOW INDEX FROM `$tableName`", 2);
    foreach ($wanted as $name => $def) {
        if (!in_array($name, $present, true)) {
            $wpdb->query("ALTER TABLE `$tableName` $def");
        }
    }

    $present = (array) $wpdb->get_col("SHOW INDEX FROM `$tableName`", 2);
    if (count(array_intersect(array_keys($wanted), $present)) === count($wanted)) {
        update_option('nmm_payment_index_version', '1');
    }
    else {
        NMM_Util::log(__FILE__, __LINE__, 'Payment index migration did not complete (' . $wpdb->last_error . '); will retry next load.', 'error');
    }
}

function NMM_create_carousel_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . NMM_CAROUSEL_TABLE;    

    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
            `cryptocurrency` char(12) NOT NULL,
            `current_index` bigint(20) NOT NULL DEFAULT '0',
            `buffer` text NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `cryptocurrency` (`cryptocurrency`)
        );";

    $wpdb->query($query);

    require_once(plugin_basename('src/NMM_Cryptocurrency.php'));
    require_once(plugin_basename('src/NMM_Carousel_Repo.php'));
    require_once(plugin_basename('src/NMM_Util.php'));
    require_once(plugin_basename('src/NMM_Cryptocurrencies.php'));
    
    NMM_Carousel_Repo::init();

    $cryptos = NMM_Cryptocurrencies::get();

    $reduxOptions = get_option(NMM_REDUX_ID, array());

    if (!empty($reduxOptions)) {
        $nmmSettings = new NMM_Settings($reduxOptions);

        foreach ($cryptos as $crypto) {
            $addresses = $nmmSettings->get_addresses($crypto->get_id());
            if (!empty($addresses)) {
                $carouselRepo = new NMM_Carousel_Repo();
                $carouselRepo->set_buffer($crypto->get_id(), $addresses);
            }
        }
    }
}

function NMM_Register_Extensions() {    
    $extensionsDir = NMM_ABS_PATH . '/src/extensions/';
    $extensions = scandir($extensionsDir);
    $extensionsToLoad = [];
    if (!is_array($extensions)) {
        return;
    }
    foreach ($extensions as $extension) {
        if ( $extension === '.' || $extension === '..' || ! is_dir( $extensionsDir . $extension ) || substr( $extension, 0, 1 ) === '.' || substr( $extension, 0, 1 ) === '@' ) {
            continue;
        }

        $extensionsToLoad[] = $extension;
        @include_once(plugin_basename('src/extensions/' . $extension . '/NMM_' . ucfirst($extension) . '.php'));
    }

    if (get_option(NMM_EXTENSION_KEY) !== $extensionsToLoad) {
        update_option(NMM_EXTENSION_KEY, $extensionsToLoad);
    }
}

// Site Health test: Privacy Mode (HD wallets) needs the gmp or bcmath PHP
// extension. Without one, address derivation fails with a misleading
// "check your MPK" error, so surface the real cause under Tools > Site Health.
function NMM_register_site_health_test($tests) {
    $tests['direct']['nmm_hd_math'] = array(
        'label' => __('Nomiddleman Privacy Mode math extension', 'nomiddleman-crypto-payments-for-woocommerce'),
        'test'  => 'NMM_site_health_hd_math',
    );
    return $tests;
}

function NMM_site_health_hd_math() {
    $result = array(
        'label'       => __('The PHP extension for Privacy Mode is available', 'nomiddleman-crypto-payments-for-woocommerce'),
        'status'      => 'good',
        'badge'       => array(
            'label' => __('Nomiddleman Crypto', 'nomiddleman-crypto-payments-for-woocommerce'),
            'color' => 'blue',
        ),
        'description' => '<p>' . esc_html__('The gmp or bcmath PHP extension is enabled, so Privacy Mode (HD wallet) address generation will work.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>',
        'test'        => 'nmm_hd_math',
    );

    if (NMM_Util::hd_math_available()) {
        return $result;
    }

    // Only Privacy-capable coins need this; check whether one is configured
    // so we escalate the severity when it is actually in use.
    $privacyInUse = false;
    $settings = new NMM_Settings(get_option(NMM_REDUX_ID, array()));
    foreach (NMM_Cryptocurrencies::get() as $crypto) {
        if ($crypto->has_hd() && $settings->crypto_selected($crypto->get_id()) && $settings->hd_enabled($crypto->get_id())) {
            $privacyInUse = true;
            break;
        }
    }

    $result['status']      = $privacyInUse ? 'critical' : 'recommended';
    $result['label']       = $privacyInUse
        ? __('Privacy Mode is enabled but its PHP extension is missing', 'nomiddleman-crypto-payments-for-woocommerce')
        : __('Privacy Mode needs the gmp or bcmath PHP extension', 'nomiddleman-crypto-payments-for-woocommerce');
    $result['description'] = '<p>' . esc_html__('Nomiddleman Privacy Mode generates a fresh HD wallet address for each order using elliptic-curve math that PHP cannot do on its own. Neither the gmp nor the bcmath PHP extension is enabled, so Privacy Mode address generation will fail with a misleading "check your MPK" error. Ask your host to enable the gmp extension (preferred) or bcmath, then retry. Coins set to Classic or Autopay Mode are unaffected.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';

    return $result;
}

add_filter('woocommerce_payment_gateways', 'NMM_filter_gateways');

// Allow the wallet URI schemes the plugin emits so esc_url() does not strip
// them from "open in wallet" links (solana:, ethereum:, monero:, bitcoin: ...).
function NMM_allowed_uri_protocols($protocols) {
    foreach (array('bitcoin', 'litecoin', 'ethereum', 'monero', 'solana', 'dogecoin', 'bitcoincash') as $scheme) {
        if (!in_array($scheme, $protocols, true)) {
            $protocols[] = $scheme;
        }
    }
    return $protocols;
}
add_filter('kses_allowed_protocols', 'NMM_allowed_uri_protocols');

?>