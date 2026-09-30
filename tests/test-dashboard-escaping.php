<?php
/**
 * Offline test: the read-only status screen (NMMPRO_Dashboard) escapes every value
 * it prints, and refuses to render at all without the capability.
 *
 * Two of the things this screen displays are NOT under the plugin's control.
 * The cryptocurrency column of the payment table is whatever was written there
 * (including by an older version, a botched import, or a hand-edited row), and
 * a log message is arbitrary text - much of it copied verbatim out of a
 * third-party API response. Printed unescaped, either one turns a page only
 * administrators can reach into stored XSS, which is also exactly the class of
 * bug that gets a plugin pulled from the directory.
 *
 * WordPress is stubbed here with REAL escaping (htmlspecialchars), not the
 * identity helpers in wp-stubs.php - an identity esc_html would make this suite
 * pass no matter what the screen does.
 *
 *   Run:  php tests/test-dashboard-escaping.php
 */

namespace Automattic\WooCommerce\Utilities {
	// Stub of WooCommerce's logging-settings accessor, answering "database
	// logging is on" so the log branch of the screen is the one exercised.
	class LoggingUtil {
		public static function logging_is_enabled() { return true; }
		public static function get_default_handler() { return 'WC_Log_Handler_DB'; }
	}
}

namespace {

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
if (!defined('NMMPRO_REDUX_ID')) { define('NMMPRO_REDUX_ID', 'nmmpro_redux_options'); }
if (!defined('NMMPRO_REDUX_SLUG')) { define('NMMPRO_REDUX_SLUG', 'nmmpro_options'); }
if (!defined('NMMPRO_PAYMENT_TABLE')) { define('NMMPRO_PAYMENT_TABLE', 'nmmpro_payments'); }
if (!defined('NMMPRO_HD_TABLE')) { define('NMMPRO_HD_TABLE', 'nmmpro_hd_addresses'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }

// The two hostile strings. Both reach the screen from storage, not from code.
define('NMMPRO_TEST_BAD_CRYPTO', '<script>alert(1)</script>');
define('NMMPRO_TEST_BAD_MESSAGE', 'explorer said: <img src=x onerror=alert(2)> & "quoted"');
define('NMMPRO_TEST_MPK', 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz');

// --- WordPress stubs --------------------------------------------------------
$GLOBALS['nmmpro_test_can'] = true;
$GLOBALS['nmmpro_test_unfenced'] = false;
$GLOBALS['nmmpro_test_lane'] = 'certified';

function current_user_can($capability) { return (bool) $GLOBALS['nmmpro_test_can']; }
function apply_filters($tag, $value) { return $value; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
function esc_html_e($text, $domain = 'default') { echo esc_html($text); }
function __($text, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return esc_html($text); }
function _n($single, $plural, $number, $domain = 'default') { return $number === 1 ? $single : $plural; }
function number_format_i18n($number) { return number_format((float) $number); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_date($format, $timestamp = null) { return gmdate($format, (int) $timestamp); }
function mysql2date($format, $date) { return (string) $date; }
function human_time_diff($from, $to) { return max(1, (int) round(abs($to - $from) / 60)) . ' mins'; }
// Scheduler answers are switchable so the "not scheduled" banner can be
// checked against Action Scheduler, WP-Cron and neither.
$GLOBALS['nmmpro_test_as_next'] = false;
$GLOBALS['nmmpro_test_cron_next'] = time() + 30;
function wp_next_scheduled($hook) { return $hook === 'NMMPRO_cron_hook' ? $GLOBALS['nmmpro_test_cron_next'] : false; }
function as_next_scheduled_action($hook, $args = array(), $group = '') {
	return ($hook === 'NMMPRO_cron_hook' && $group === 'nomiddleman') ? $GLOBALS['nmmpro_test_as_next'] : false;
}
function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') { return true; }
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration) { return true; }

function get_option($key, $default = array()) {
	$options = array(
		NMMPRO_REDUX_ID => array(
			'crypto_select' => array('BTC', 'LTC'),
			'BTC_mode'      => '1',
			'BTC_addresses' => array('1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'),
			'LTC_mode'      => '2',
			'LTC_hd_mpk'    => NMMPRO_TEST_MPK,
		),
		'nmmpro_autopay_scan_last_run'    => time() - 90,
		'nmmpro_autopay_scan_sweep_start' => time() - 300,
		'nmmpro_autopay_scan_retry'       => array('BTC|1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'),
		'nmmpro_autopay_scan_retry_unfenced' => array('BTC|a', 'BTC|b', 'BTC|c', 'BTC|d', 'BTC|e', 'BTC|f', 'BTC|g'),
		'nmmpro_autopay_scan_covered_at'  => array('BTC' => time() - 60),
		'nmmpro_autopay_unfenced'         => $GLOBALS['nmmpro_test_unfenced'],
		'nmmpro_autopay_scan_lane'        => $GLOBALS['nmmpro_test_lane'],
		// Privacy Mode schema in place, so the wallet-recovery section renders.
		'nmmpro_hd_table_version'         => '1.5',
		'date_format'                  => 'Y-m-d',
		'time_format'                  => 'H:i',
	);

	return array_key_exists($key, $options) ? $options[$key] : $default;
}

// --- WooCommerce stubs ------------------------------------------------------
function wc_get_logger() { return null; }

class WC_Log_Handler_DB {}

class WC_Log_Levels {
	public static function get_level_severity($level) { return 400; }
	public static function get_severity_level($severity) { return 'warning'; }
}

// --- $wpdb stub -------------------------------------------------------------
// Answers only what the two repository classes ask for. Deliberately dumb: the
// point of the suite is what the SCREEN does with the rows, not the SQL.
class NMMPRO_Test_Wpdb {
	public $prefix = 'wp_';

	public function prepare($query, ...$args) {
		foreach ($args as $arg) {
			$query = preg_replace('/%[sd]/', is_numeric($arg) ? (string) $arg : "'" . $arg . "'", $query, 1);
		}
		return $query;
	}

	public function get_var($query) {
		return (strpos($query, 'SHOW TABLES') !== false) ? $this->prefix . 'woocommerce_log' : null;
	}

	// The Privacy Mode wallet-recovery summary (NMMPRO_Hd_Repo::index_summary).
	public function get_row($query, $output = null) {
		if (strpos($query, 'MAX(`mpk_index`) AS derived') !== false) {
			return array('derived' => '41', 'issued' => '37', 'unused_issued' => '12');
		}
		return null;
	}

	public function get_results($query, $output = null) {
		if (strpos($query, 'woocommerce_log') !== false) {
			return array(
				array('timestamp' => '2026-08-22 01:02:03', 'level' => 400, 'message' => NMMPRO_TEST_BAD_MESSAGE),
			);
		}

		// Unpaid backlog: one enabled coin, plus a row whose stored currency is
		// junk (and therefore not an enabled coin, so it lands in the
		// "no longer enabled" table).
		return array(
			array('cryptocurrency' => 'BTC', 'unpaid_count' => '3', 'oldest_ordered_at' => (string) (time() - 7200)),
			array('cryptocurrency' => NMMPRO_TEST_BAD_CRYPTO, 'unpaid_count' => '1', 'oldest_ordered_at' => (string) (time() - 60)),
		);
	}
}

$GLOBALS['wpdb'] = new NMMPRO_Test_Wpdb();

$root = dirname(__DIR__);
require $root . '/src/NMMPRO_Compat.php';
require $root . '/src/NMMPRO_Util.php';
require $root . '/src/NMMPRO_Cryptocurrency.php';
require $root . '/src/NMMPRO_Cryptocurrencies.php';
require $root . '/src/NMMPRO_Settings.php';
require $root . '/src/NMMPRO_Payment_Repo.php';
require $root . '/src/NMMPRO_Log_Repo.php';
require $root . '/src/NMMPRO_Hd_Schema.php';
require $root . '/src/NMMPRO_Hd_Evidence.php';
require $root . '/src/NMMPRO_Hd_Repo.php';
require $root . '/src/NMMPRO_Dashboard.php';

$failed = false;
function dok($label, $pass, $extra = '') {
	global $failed;
	printf("%-66s %s%s\n", $label, $pass ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$pass) { $failed = true; }
}

// --- render as an administrator --------------------------------------------
ob_start();
NMMPRO_Dashboard::render_page();
$html = ob_get_clean();

dok('screen rendered something', strlen($html) > 500, 'len=' . strlen($html));

// The hostile currency value must appear ONLY in escaped form.
dok('hostile stored currency is escaped',
	strpos($html, NMMPRO_TEST_BAD_CRYPTO) === false);
dok('hostile stored currency is still displayed (escaped)',
	strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);

// Log messages are arbitrary third-party text.
dok('hostile log message is escaped',
	strpos($html, NMMPRO_TEST_BAD_MESSAGE) === false);
dok('hostile log message is still displayed (escaped)',
	strpos($html, '&lt;img src=x onerror=alert(2)&gt;') !== false);
dok('quotes and ampersands in a log message are escaped',
	strpos($html, '&amp; &quot;quoted&quot;') !== false);

// No unbalanced markup can have come from data: every < in the output must
// start a real tag or an HTML comment.
dok('no data-derived tag survived into the markup',
	preg_match('/<(?!\/?(?:div|h1|h2|p|a|table|thead|tbody|tr|th|td|br|strong|code|em|span|!--)\b)/i', $html) === 0);

// Privacy Mode wallet recovery: the scan range is shown for the HD coin.
dok('the wallet-recovery scan range is shown',
	strpos($html, 'Privacy Mode wallet recovery') !== false && strpos($html, '<td>37</td>') !== false && strpos($html, '<td>41</td>') !== false);

// Secrets: the screen reports that an MPK exists, never what it is.
dok('the master public key is never printed',
	strpos($html, NMMPRO_TEST_MPK) === false && strpos($html, 'Master public key set') !== false);

// --- background-job scheduling ---------------------------------------------
// NMMPRO_schedule_payment_checks() prefers Action Scheduler and clears the
// WP-Cron event once an action exists, so a screen that asked only WP-Cron
// would call a healthy WooCommerce store's job "not scheduled".
$notScheduled = 'The background job is not scheduled';
dok('WP-Cron-scheduled job is not reported as unscheduled',
	strpos($html, $notScheduled) === false);

$GLOBALS['nmmpro_test_cron_next'] = false;
$GLOBALS['nmmpro_test_as_next'] = time() + 45;
ob_start();
NMMPRO_Dashboard::render_page();
$asOnly = ob_get_clean();
dok('Action-Scheduler-only job is not reported as unscheduled',
	strpos($asOnly, $notScheduled) === false && strpos($asOnly, 'Not scheduled') === false);

$GLOBALS['nmmpro_test_as_next'] = true;
ob_start();
NMMPRO_Dashboard::render_page();
$running = ob_get_clean();
dok('a running Action Scheduler job is not reported as unscheduled',
	strpos($running, $notScheduled) === false);

$GLOBALS['nmmpro_test_as_next'] = false;
ob_start();
NMMPRO_Dashboard::render_page();
$none = ob_get_clean();
dok('a job neither scheduler holds IS reported as unscheduled',
	strpos($none, $notScheduled) !== false);
$GLOBALS['nmmpro_test_cron_next'] = time() + 30;

// Switching a coin off does not stop its existing orders being checked; only a
// ticker the plugin no longer supports cannot be checked.
dok('switched-off coins are not claimed to be unmonitored', strpos($html, 'no longer being checked') === false && strpos($html, 'keeps checking these orders') !== false);
dok('an unsupported ticker is marked as not checkable', strpos($html, 'Not possible - no longer supported') !== false);

// --- degraded mode: automatic expiry paused ---------------------------------
$paused = 'Automatic cancellation of expired Autopay orders is paused';
dok('no paused-expiry notice on a fenced store', strpos($html, $paused) === false);
$GLOBALS['nmmpro_test_unfenced'] = array('at' => time() - 30, 'reason' => 'single-lock');
$GLOBALS['nmmpro_test_lane'] = 'unfenced';
ob_start();
NMMPRO_Dashboard::render_page();
$degraded = ob_get_clean();
dok('paused-expiry notice names the old-MySQL cause', strpos($degraded, $paused) !== false && strpos($degraded, 'MySQL before 5.7.5') !== false);
// The retry count shown is the lane the job recorded using: 7 in the
// matching-only lane here, 1 in the certified lane on a fenced store.
$GLOBALS['nmmpro_test_lane'] = 'certified';
dok('retry count follows the lane the job is using', preg_match('#Addresses queued for retry</th>\s*<td>\s*7\b#', $degraded) === 1 && preg_match('#Addresses queued for retry</th>\s*<td>\s*1\b#', $html) === 1);
$GLOBALS['nmmpro_test_unfenced'] = array('at' => time() - 30, 'reason' => 'lost');
ob_start();
NMMPRO_Dashboard::render_page();
$lostHtml = ob_get_clean();
dok('paused-expiry notice explains a lock lost mid-run', strpos($lostHtml, $paused) !== false && strpos($lostHtml, 'lost its database lock part-way') !== false);
$GLOBALS['nmmpro_test_unfenced'] = array('at' => time() - 2 * 3600, 'reason' => 'unavailable');
ob_start();
NMMPRO_Dashboard::render_page();
$stale = ob_get_clean();
dok('a stale degraded record is not shown', strpos($stale, $paused) === false);
$GLOBALS['nmmpro_test_unfenced'] = false;

// --- render without the capability ------------------------------------------
$GLOBALS['nmmpro_test_can'] = false;
ob_start();
NMMPRO_Dashboard::render_page();
$denied = ob_get_clean();
$GLOBALS['nmmpro_test_can'] = true;

dok('nothing renders without manage_options', trim($denied) === '', 'len=' . strlen($denied));

echo $failed ? "\nDASHBOARD ESCAPING CHECKS FAILED\n" : "\nDASHBOARD ESCAPING CHECKS PASSED\n";
if ($failed) { exit(1); }

}
