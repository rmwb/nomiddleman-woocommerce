<?php
/**
 * Offline test: the read-only status screen (NMM_Dashboard) escapes every value
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
if (!defined('NMM_REDUX_ID')) { define('NMM_REDUX_ID', 'nmmpro_redux_options'); }
if (!defined('NMM_REDUX_SLUG')) { define('NMM_REDUX_SLUG', 'nmmpro_options'); }
if (!defined('NMM_PAYMENT_TABLE')) { define('NMM_PAYMENT_TABLE', 'nmmpro_payments'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// The two hostile strings. Both reach the screen from storage, not from code.
define('NMM_TEST_BAD_CRYPTO', '<script>alert(1)</script>');
define('NMM_TEST_BAD_MESSAGE', 'explorer said: <img src=x onerror=alert(2)> & "quoted"');
define('NMM_TEST_MPK', 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz');

// --- WordPress stubs --------------------------------------------------------
$GLOBALS['nmm_test_can'] = true;

function current_user_can($capability) { return (bool) $GLOBALS['nmm_test_can']; }
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
function wp_next_scheduled($hook) { return time() + 30; }
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration) { return true; }

function get_option($key, $default = array()) {
	$options = array(
		NMM_REDUX_ID => array(
			'crypto_select' => array('BTC', 'LTC'),
			'BTC_mode'      => '1',
			'BTC_addresses' => array('1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'),
			'LTC_mode'      => '2',
			'LTC_hd_mpk'    => NMM_TEST_MPK,
		),
		'nmm_autopay_scan_last_run'    => time() - 90,
		'nmm_autopay_scan_sweep_start' => time() - 300,
		'nmm_autopay_scan_retry'       => array('BTC|1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'),
		'nmm_autopay_scan_covered_at'  => array('BTC' => time() - 60),
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
class NMM_Test_Wpdb {
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

	public function get_results($query, $output = null) {
		if (strpos($query, 'woocommerce_log') !== false) {
			return array(
				array('timestamp' => '2026-08-22 01:02:03', 'level' => 400, 'message' => NMM_TEST_BAD_MESSAGE),
			);
		}

		// Unpaid backlog: one enabled coin, plus a row whose stored currency is
		// junk (and therefore not an enabled coin, so it lands in the
		// "no longer enabled" table).
		return array(
			array('cryptocurrency' => 'BTC', 'unpaid_count' => '3', 'oldest_ordered_at' => (string) (time() - 7200)),
			array('cryptocurrency' => NMM_TEST_BAD_CRYPTO, 'unpaid_count' => '1', 'oldest_ordered_at' => (string) (time() - 60)),
		);
	}
}

$GLOBALS['wpdb'] = new NMM_Test_Wpdb();

$root = dirname(__DIR__);
require $root . '/src/NMM_Util.php';
require $root . '/src/NMM_Cryptocurrency.php';
require $root . '/src/NMM_Cryptocurrencies.php';
require $root . '/src/NMM_Settings.php';
require $root . '/src/NMM_Payment_Repo.php';
require $root . '/src/NMM_Log_Repo.php';
require $root . '/src/NMM_Dashboard.php';

$failed = false;
function dok($label, $pass, $extra = '') {
	global $failed;
	printf("%-66s %s%s\n", $label, $pass ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$pass) { $failed = true; }
}

// --- render as an administrator --------------------------------------------
ob_start();
NMM_Dashboard::render_page();
$html = ob_get_clean();

dok('screen rendered something', strlen($html) > 500, 'len=' . strlen($html));

// The hostile currency value must appear ONLY in escaped form.
dok('hostile stored currency is escaped',
	strpos($html, NMM_TEST_BAD_CRYPTO) === false);
dok('hostile stored currency is still displayed (escaped)',
	strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);

// Log messages are arbitrary third-party text.
dok('hostile log message is escaped',
	strpos($html, NMM_TEST_BAD_MESSAGE) === false);
dok('hostile log message is still displayed (escaped)',
	strpos($html, '&lt;img src=x onerror=alert(2)&gt;') !== false);
dok('quotes and ampersands in a log message are escaped',
	strpos($html, '&amp; &quot;quoted&quot;') !== false);

// No unbalanced markup can have come from data: every < in the output must
// start a real tag or an HTML comment.
dok('no data-derived tag survived into the markup',
	preg_match('/<(?!\/?(?:div|h1|h2|p|a|table|thead|tbody|tr|th|td|br|strong|code|em|span|!--)\b)/i', $html) === 0);

// Secrets: the screen reports that an MPK exists, never what it is.
dok('the master public key is never printed',
	strpos($html, NMM_TEST_MPK) === false && strpos($html, 'Master public key set') !== false);

// --- render without the capability ------------------------------------------
$GLOBALS['nmm_test_can'] = false;
ob_start();
NMM_Dashboard::render_page();
$denied = ob_get_clean();
$GLOBALS['nmm_test_can'] = true;

dok('nothing renders without manage_options', trim($denied) === '', 'len=' . strlen($denied));

echo $failed ? "\nDASHBOARD ESCAPING CHECKS FAILED\n" : "\nDASHBOARD ESCAPING CHECKS PASSED\n";
if ($failed) { exit(1); }

}
