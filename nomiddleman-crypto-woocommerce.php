<?php
/*
WC requires at least: 3.0.0
WC tested up to: 10.8
Plugin Name: Nomiddleman Bitcoin and Crypto Payments for WooCommerce
Plugin URI:  https://wordpress.org/plugins/nomiddleman-crypto-payments-for-woocommerce/
Description: WooCommerce Bitcoin and Cryptocurrency Payment Gateway
Author: nomiddleman
Author URI: https://github.com/rmwb/nomiddleman-woocommerce
Version: 2.10.0
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

// Register class autoloading first, before anything else in this file, so that
// every later hook callback - including the activation, deactivation and
// uninstall hooks, which run without WooCommerce and therefore without
// NMM_init_gateways ever executing - can resolve NMM_Settings and the rest of
// the plugin's classes on demand. See src/NMM_Autoloader.php for what stays an
// explicit require and why.
require_once plugin_dir_path(__FILE__) . 'src/NMM_Autoloader.php';
NMM_Autoloader::register(plugin_dir_path(__FILE__) . 'src');

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

    // Not autoloaded: NMM_Blocks_Support extends AbstractPaymentMethodType, so
    // it can only be loaded once WooCommerce Blocks is present - which is what
    // the guard above establishes. Keeping the require here means the file is
    // never reachable through a class_exists() on a request without Blocks.
    require_once plugin_dir_path(__FILE__) . 'src/NMM_Blocks_Support.php';

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
// Directory signature the cached extension list in NMM_EXTENSION_KEY was built
// from; see NMM_Register_Extensions.
define('NMM_EXTENSION_SIGNATURE_KEY', 'nmm_extensions_signature');

// NMM_Settings was required here, outside NMM_init_gateways, because the
// activation and uninstall hooks run without WooCommerce and so never reach
// that function. The autoloader registered at the top of this file covers that
// - and every other NMM_ class - on demand, from an absolute path.

function NMM_init_gateways(){

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };

    define('NMM_PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)));    
    define('NMM_PLUGIN_FILE', __FILE__);
    define('NMM_ABS_PATH', dirname(NMM_PLUGIN_FILE));

    define('NMM_VERSION', '2.10.0');
    
    define('NMM_REDUX_SLUG', 'nmmpro_options');

    $pluginDir = plugin_dir_path(__FILE__);

    // Vendor. Never autoloaded: these bundled libraries do not use the NMM_
    // prefix, several files define many classes at once or a class whose name
    // does not match the file (phpqrcode.php defines QRinput/QRcode/..., and
    // CashAddress.php defines the namespaced \CashAddress\CashAddress), and
    // HdHelper.php has load-time side effects the siblings depend on - it
    // define()s USE_EXT, which CurveFp and NumberTheory read. None of that can
    // be mapped from a class name to a file, so it stays explicit, guarded by
    // class_exists() so a host that already ships one of these wins, and in
    // exactly this order.
    if (!class_exists('bcmath_Utils')) {
        require_once $pluginDir . 'src/vendor/bcmath_Utils.php';
    }
    if (!class_exists('CurveFp')) {
        require_once $pluginDir . 'src/vendor/CurveFp.php';
    }
    if (!class_exists('HdHelper')) {
        require_once $pluginDir . 'src/vendor/HdHelper.php';
    }
    if (!class_exists('gmp_Utils')) {
        require_once $pluginDir . 'src/vendor/gmp_Utils.php';
    }
    if (!class_exists('NumberTheory')) {
        require_once $pluginDir . 'src/vendor/NumberTheory.php';
    }
    if (!class_exists('Point')) {
        require_once $pluginDir . 'src/vendor/Point.php';
    }
    if (!class_exists('\CashAddress\CashAddress')) {
        require_once $pluginDir . 'src/vendor/CashAddress.php';
    }
    if (!class_exists('QRinput')) {
        require_once $pluginDir . 'src/vendor/phpqrcode.php';
    }

    // Everything else in src/ is one NMM_-prefixed class in a file of the same
    // name, so NMM_Autoloader (registered at the top of this file) resolves it
    // on first use and there is no load order left to maintain - NMM_Address
    // before NMM_Validation, NMM_Cryptocurrency before NMM_Cryptocurrencies and
    // the rest are now guaranteed by construction. Only the files below cannot
    // be autoloaded, and they keep their original position and order:
    //
    //  - NMM_Hooks.php / NMM_Cron.php define plain functions, not classes; the
    //    hook registrations right below reference those functions by name.
    //  - NMM_Admin.php calls NMM_Admin::init() at file scope, so nothing would
    //    ever reference the class to trigger an autoload and the admin menu,
    //    settings and notice hooks would silently never be registered.
    //  - NMM_Gateway.php extends WC_Payment_Gateway. Required here, after the
    //    WC_Payment_Gateway guard at the top of this function and before
    //    WooCommerce runs the woocommerce_payment_gateways filter, so the class
    //    can never be pulled in on a request where its parent does not exist.
    require_once $pluginDir . 'src/NMM_Hooks.php';
    require_once $pluginDir . 'src/NMM_Cron.php';
    require_once $pluginDir . 'src/NMM_Admin.php';
    require_once $pluginDir . 'src/NMM_Gateway.php';

    add_filter ('cron_schedules', 'NMM_add_interval');

    add_action('NMM_cron_hook', 'NMM_do_cron_job');
    add_action('woocommerce_order_status_changed', 'NMM_update_database_when_admin_changes_order_status', 10, 3);
    
    if (is_admin()) {
        add_action('wp_ajax_firstmpkaddress', 'NMM_first_mpk_address_ajax');
        add_filter('site_status_tests', 'NMM_register_site_health_test');
        add_action('admin_init', 'NMM_verify_site_tables');
    }

    // thank-you page payment status poller (guests included)
    add_action('wp_ajax_nmm_order_status', 'NMM_order_status_ajax');
    add_action('wp_ajax_nopriv_nmm_order_status', 'NMM_order_status_ajax');

    // Order emails resent from admin or dispatched by cron/WP-CLI can render
    // before anything has initialized the payment gateways, so NMM_Gateway's
    // constructor - which hooks additional_email_details at priority 10 on
    // this same hook - would never run and the email would lose its payment
    // details. Prime the gateways singleton at priority 5; WordPress still
    // executes callbacks added to a later priority of the hook being run.
    add_action('woocommerce_email_order_details', 'NMM_load_gateways_for_email', 5);

    // The order-pay endpoint (WooCommerce's "Pay" link on a pending order)
    // never passes through the thank-you hook, so a customer returning to an
    // unpaid order previously had no way to see their payment address again.
    // Registered here rather than in the gateway constructor because the
    // receipt template's do_action fires without anything instantiating the
    // gateways first - the callback resolves (and thereby constructs) the
    // gateway itself.
    add_action('woocommerce_receipt_nmmpro_gateway', 'NMM_render_order_receipt');

    NMM_Register_Extensions();
    NMM_update_hd_table();
    NMM_maybe_create_sol_retry_table();
    NMM_maybe_add_payment_indexes();

    add_action('init', 'NMM_schedule_payment_checks');
    add_action('admin_init', 'NMM_cleanup_legacy_qr_files');
}

// See the woocommerce_email_order_details registration in NMM_init_gateways:
// instantiating the gateways singleton runs NMM_Gateway::__construct(), which
// registers the email payment-details callback. Idempotent - WooCommerce only
// ever builds the singleton (and each gateway) once per request.
function NMM_load_gateways_for_email() {
    if (function_exists('WC') && WC() && is_callable(array(WC(), 'payment_gateways'))) {
        WC()->payment_gateways();
    }
}

// Renders the payment details on the order-pay receipt page. See the
// woocommerce_receipt_nmmpro_gateway registration in NMM_init_gateways.
function NMM_render_order_receipt($order_id) {
    if (!function_exists('WC') || !WC() || !is_callable(array(WC(), 'payment_gateways'))) {
        return;
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    if (isset($gateways['nmmpro_gateway'])) {
        $gateways['nmmpro_gateway']->thank_you_page($order_id);
    }
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

// register_activation_hook passes whether the plugin was network activated.
// On a network activation the tables are per site (each blog has its own
// prefix), so create them on every existing site; a plain activation only
// touches the current site, exactly as before.
function NMM_activate($networkWide = false) {
    if (is_multisite() && $networkWide) {
        NMM_for_each_site('NMM_activate_site');
        return;
    }

    NMM_activate_site();
}

// Everything activation needs for ONE site (the current blog). Also invoked
// for sub-sites created after a network activation (NMM_initialize_new_site)
// and by the self-heal check (NMM_verify_site_tables), so it must stay
// idempotent: the CREATEs are IF NOT EXISTS and the migrations are versioned.
function NMM_activate_site() {
    // scheduling happens on init via NMM_schedule_payment_checks
    NMM_create_hd_mpk_address_table();
    // remove leftovers from the retired flash-notice queue
    delete_option('my_flash_notices');
    delete_option('nmm_flash_notices');
    NMM_create_payment_table();
    NMM_create_carousel_table();
    NMM_maybe_create_sol_retry_table();
    NMM_maybe_add_payment_indexes();
    // Activation is the one moment we know the plugin directory was just
    // written to, so rebuild the cached extension list here rather than leaving
    // it to the directory-signature check on the next front-end request.
    NMM_refresh_extension_cache();
}

// Run a callable once per site. On multisite it visits every blog (the same
// switch_to_blog loop NMM_drop_sol_retry_table and NMM_delete_scan_options
// established); on single site it simply invokes the callable for the one site.
function NMM_for_each_site($callback) {
    if (is_multisite()) {
        global $wpdb;
        $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        foreach ($blogIds as $blogId) {
            switch_to_blog($blogId);
            call_user_func($callback);
            restore_current_blog();
        }
        return;
    }

    call_user_func($callback);
}

// A sub-site created AFTER the plugin was network activated never went through
// NMM_activate, so build its tables here or its checkout would fail with raw
// database errors. Only when the plugin is network active: a per-site
// activation should not leak tables into unrelated new sites.
function NMM_initialize_new_site($newSite) {
    $sitewidePlugins = (array) get_site_option('active_sitewide_plugins', array());
    if (!isset($sitewidePlugins[plugin_basename(__FILE__)])) {
        return;
    }

    switch_to_blog($newSite->blog_id);
    NMM_activate_site();
    restore_current_blog();
}
// Priority 100: core populates the new site's tables/options at priority 10,
// and ours must not run before the blog's options table exists.
add_action('wp_initialize_site', 'NMM_initialize_new_site', 100);

// Self-heal for sites that missed activation (e.g. a sub-site created after
// network activation on a version without the wp_initialize_site hook above).
// Admin-only and transient-gated, so the SHOW TABLES probes do not run on
// every load. Repairs by re-running the idempotent per-site activation.
function NMM_verify_site_tables() {
    if (get_transient('nmm_tables_verified')) {
        return;
    }

    global $wpdb;

    // Each table's schema/version bookkeeping. Recreating a missing table from
    // its BASE definition while a stale version option still says "current"
    // would permanently skip the gated verify-then-record migrations that add
    // later columns/indexes (the base HD table deliberately lacks hd_mode, for
    // example), so clear the bookkeeping for any missing table first and let
    // the shipped migrations rebuild it to the current schema.
    $schemaOptions = array(
        $wpdb->prefix . NMM_HD_TABLE        => array('nmm_hd_table_version'),
        $wpdb->prefix . NMM_PAYMENT_TABLE   => array('nmm_payment_index_version'),
        $wpdb->prefix . NMM_CAROUSEL_TABLE  => array(),
        $wpdb->prefix . NMM_SOL_RETRY_TABLE => array('nmm_sol_retry_schema', 'nmm_sol_retry_table_created'),
    );

    $missing = array();
    foreach (array_keys($schemaOptions) as $requiredTable) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $requiredTable)) !== $requiredTable) {
            $missing[] = $requiredTable;
        }
    }

    if (!empty($missing)) {
        NMM_Util::log(__FILE__, __LINE__, 'Plugin tables missing for this site (' . implode(', ', $missing) . '); recreating them. This site likely never ran activation (e.g. created after a network activation).', 'warning');

        foreach ($missing as $missingTable) {
            foreach ($schemaOptions[$missingTable] as $schemaOption) {
                delete_option($schemaOption);
            }
        }

        NMM_activate_site();

        // The HD migrations normally run from NMM_init_gateways on
        // plugins_loaded, which already fired this request, so run them now:
        // a recreated base HD table must gain hd_mode (and the composite
        // indexes that reference it) immediately, not on the next load.
        NMM_update_hd_table();

        foreach ($missing as $missingTable) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $missingTable)) !== $missingTable) {
                // Leave the transient unset so the next admin load retries.
                NMM_Util::log(__FILE__, __LINE__, 'Could not create table ' . $missingTable . ' (' . $wpdb->last_error . '); will retry on the next admin load.', 'error');
                return;
            }
        }
    }

    set_transient('nmm_tables_verified', 1, DAY_IN_SECONDS);
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
    NMM_delete_scan_options();
}

// The Autopay scan state is per site (options live in each blog's options
// table), so on a network uninstall delete them per site alongside the per-site
// tables; otherwise sub-sites would retain orphaned cursor/coverage state.
function NMM_delete_scan_options() {
    NMM_for_each_site(function () {
        $scanOptions = array(
            'nmm_autopay_scan_cursor',
            'nmm_autopay_scan_retry',
            'nmm_autopay_scan_last_run',
            'nmm_autopay_scan_covered_at',
            'nmm_autopay_scan_sweep_start',
            'nmm_autopay_scan_dirty',
            'nmm_autopay_scan_incomplete',
            'nmm_autopay_scan_incomplete_next',
        );

        foreach ($scanOptions as $scanOption) {
            delete_option($scanOption);
        }
    });
}

// All plugin tables are created per site (each blog has its own prefix), so
// drop them per site on a network uninstall - along with each table's per-site
// schema-version options, so a later reinstall re-runs its migrations instead
// of trusting a stale version - otherwise sub-site tables would be orphaned.
function NMM_drop_sol_retry_table() {
    NMM_for_each_site(function () {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `" . $wpdb->prefix . NMM_SOL_RETRY_TABLE . "`");
        delete_option('nmm_sol_retry_schema');
        delete_option('nmm_sol_retry_table_created');
    });
}

function NMM_drop_mpk_address_table() {
    NMM_for_each_site(function () {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `" . $wpdb->prefix . NMM_HD_TABLE . "`");
        delete_option('nmm_hd_table_version');
    });
}

function NMM_drop_payment_table() {
    NMM_for_each_site(function () {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `" . $wpdb->prefix . NMM_PAYMENT_TABLE . "`");
        delete_option('nmm_payment_index_version');
    });
}

function NMM_drop_carousel_table() {
    NMM_for_each_site(function () {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `" . $wpdb->prefix . NMM_CAROUSEL_TABLE . "`");
        delete_transient('nmm_tables_verified');
    });
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

    // This runs from activation, where WooCommerce may be absent and
    // NMM_init_gateways therefore never ran, so the classes below used to be
    // required explicitly here. The autoloader registered at the top of this
    // file loads each of them (NMM_Carousel_Repo, NMM_Cryptocurrencies,
    // NMM_Cryptocurrency, NMM_Address, NMM_Util, NMM_Settings) on first use.
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

// Absolute path to the extensions directory, with a trailing slash. Uses
// plugin_dir_path(__FILE__) rather than NMM_ABS_PATH because that constant is
// only defined inside NMM_init_gateways, which never runs when WooCommerce is
// inactive - and activation still has to be able to refresh the cache.
function NMM_extensions_dir() {
    return plugin_dir_path(__FILE__) . 'src/extensions/';
}

// A cheap stand-in for "the extensions directory listing". One stat() instead
// of a scandir(): a directory's mtime moves whenever an entry inside it is
// added, removed or renamed, which is exactly when the cached list can go
// stale - including an extension dropped in over FTP that never runs
// activation. Changes *within* an extension's own folder do not move it, and
// do not need to: the cache only stores the folder names.
function NMM_extensions_dir_signature() {
    $extensionsDir = NMM_extensions_dir();

    if (!is_dir($extensionsDir)) {
        return 'missing';
    }

    $mtime = filemtime($extensionsDir);

    return ($mtime === false) ? 'unknown' : (string) $mtime;
}

// Rescan src/extensions/ and record the directory listing in the long-standing
// NMM_EXTENSION_KEY option (extensions themselves read it, so its shape - a
// list of directory names - must not change) together with the signature it
// was built from. Returns the list. Deliberately includes nothing, so it is
// safe to call from activation, where WooCommerce may be absent.
function NMM_refresh_extension_cache() {
    $extensionsDir = NMM_extensions_dir();
    $entries = is_dir($extensionsDir) ? scandir($extensionsDir) : false;

    // Same guard as the pre-cache version: a scandir() that fails must not be
    // allowed to rewrite - and thereby wipe - the registered-extensions option.
    // Fall back to the last known-good list so a paid extension keeps loading.
    if (!is_array($entries)) {
        return (array) get_option(NMM_EXTENSION_KEY, array());
    }

    $extensionsToLoad = array();
    foreach ($entries as $entry) {
        if ( $entry === '.' || $entry === '..' || ! is_dir( $extensionsDir . $entry ) || substr( $entry, 0, 1 ) === '.' || substr( $entry, 0, 1 ) === '@' ) {
            continue;
        }

        $extensionsToLoad[] = $entry;
    }

    if (get_option(NMM_EXTENSION_KEY) !== $extensionsToLoad) {
        update_option(NMM_EXTENSION_KEY, $extensionsToLoad);
    }

    $signature = NMM_extensions_dir_signature();
    if (get_option(NMM_EXTENSION_SIGNATURE_KEY, null) !== $signature) {
        update_option(NMM_EXTENSION_SIGNATURE_KEY, $signature);
    }

    return $extensionsToLoad;
}

// Load any extension installed as src/extensions/<name>/NMM_<Name>.php - the
// legacy paid "Privacy extension" pathway - and keep NMM_EXTENSION_KEY in sync.
//
// This used to scandir() the directory on every single request. The listing is
// now cached in that same option and rebuilt only when it can actually have
// changed: when the directory signature moves, when nothing is cached yet, on
// wp-admin page loads (excluding admin-ajax, which the thank-you page polls
// every 15 seconds), and from activation via NMM_refresh_extension_cache().
function NMM_Register_Extensions() {
    $extensionsDir = NMM_extensions_dir();

    $rescan = (is_admin() && !wp_doing_ajax())
        || get_option(NMM_EXTENSION_SIGNATURE_KEY, null) !== NMM_extensions_dir_signature();

    $extensionsToLoad = $rescan
        ? NMM_refresh_extension_cache()
        : (array) get_option(NMM_EXTENSION_KEY, array());

    foreach ($extensionsToLoad as $extension) {
        // The names come from scandir(), but they round-trip through an option,
        // so re-check that nothing can escape the extensions directory before
        // building a path that is about to be include()d.
        if (!is_string($extension) || $extension === '' || strpbrk($extension, '/\\') !== false || strpos($extension, '..') !== false) {
            continue;
        }

        $extensionFile = $extensionsDir . $extension . '/NMM_' . ucfirst($extension) . '.php';

        // Was @include_once: a missing or unreadable extension file silently
        // did nothing, so a half-installed paid extension was indistinguishable
        // from one that had simply been switched off. Log it instead
        // (NMM_Util::log de-duplicates, so this cannot flood the log) and carry
        // on - one broken extension must not take the gateway down with it.
        if (!is_readable($extensionFile)) {
            NMM_Util::log(__FILE__, __LINE__, 'Extension "' . $extension . '" is registered but ' . $extensionFile . ' is missing or unreadable; skipping it.', 'warning');
            continue;
        }

        include_once($extensionFile);
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
    $tests['direct']['nmm_db_tables'] = array(
        'label' => __('Nomiddleman database tables', 'nomiddleman-crypto-payments-for-woocommerce'),
        'test'  => 'NMM_site_health_db_tables',
    );
    return $tests;
}

// Site Health test: all plugin tables must exist for the current site. A site
// can miss them if it was created after a network activation on an older
// version - checkout then fails with raw database errors.
function NMM_site_health_db_tables() {
    global $wpdb;

    $result = array(
        'label'       => __('The Nomiddleman database tables are present', 'nomiddleman-crypto-payments-for-woocommerce'),
        'status'      => 'good',
        'badge'       => array(
            'label' => __('Nomiddleman Crypto', 'nomiddleman-crypto-payments-for-woocommerce'),
            'color' => 'blue',
        ),
        'description' => '<p>' . esc_html__('All database tables the plugin needs for this site exist, so address assignment and payment tracking can work.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>',
        'test'        => 'nmm_db_tables',
    );

    $requiredTables = array(
        $wpdb->prefix . NMM_HD_TABLE,
        $wpdb->prefix . NMM_PAYMENT_TABLE,
        $wpdb->prefix . NMM_CAROUSEL_TABLE,
        $wpdb->prefix . NMM_SOL_RETRY_TABLE,
    );

    $missing = array();
    foreach ($requiredTables as $requiredTable) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $requiredTable)) !== $requiredTable) {
            $missing[] = $requiredTable;
        }
    }

    if (empty($missing)) {
        return $result;
    }

    $result['status']      = 'critical';
    $result['label']       = __('Nomiddleman database tables are missing for this site', 'nomiddleman-crypto-payments-for-woocommerce');
    $result['description'] = '<p>' . sprintf(
        /* translators: %s: comma-separated list of missing database table names */
        esc_html__('The following plugin tables do not exist for this site: %s. Checkout with the crypto gateway will fail until they are created. This usually means the site was created after the plugin was network activated on an older plugin version. Deactivating and reactivating the plugin recreates them; the plugin also attempts an automatic repair on admin page loads.', 'nomiddleman-crypto-payments-for-woocommerce'),
        esc_html(implode(', ', $missing))
    ) . '</p>';

    return $result;
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