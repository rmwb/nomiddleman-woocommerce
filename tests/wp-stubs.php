<?php
/**
 * Minimal WordPress stubs so plugin classes can run standalone in tests.
 * Not loaded by the plugin itself.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) define('ABSPATH', sys_get_temp_dir() . '/');

if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('NMM_REDUX_ID')) define('NMM_REDUX_ID', 'nmmpro_redux_options');

if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value) { return $value; }
}

// Translation and escaping helpers. The plugin reaches for these on its ERROR
// paths (exception messages, admin notices), so without them a suite that
// provokes a failure - two dead fiat-rate services, say - dies on an undefined
// function inside the very code path it set out to exercise, and takes the
// harness down before it can report. Escaping is a no-op here: nothing in the
// offline suites renders HTML.
if (!function_exists('__')) {
	function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_attr__')) {
	function esc_attr__($text, $domain = 'default') { return $text; }
}
if (!function_exists('_x')) {
	function _x($text, $context, $domain = 'default') { return $text; }
}
if (!function_exists('_e')) {
	function _e($text, $domain = 'default') { echo $text; }
}
if (!function_exists('esc_html_e')) {
	function esc_html_e($text, $domain = 'default') { echo $text; }
}
if (!function_exists('esc_html')) {
	function esc_html($text) { return $text; }
}
if (!function_exists('esc_attr')) {
	function esc_attr($text) { return $text; }
}
if (!function_exists('esc_textarea')) {
	function esc_textarea($text) { return $text; }
}
if (!function_exists('esc_url')) {
	function esc_url($url) { return $url; }
}

class WP_Error_Stub {}

function is_wp_error($thing) {
	return $thing instanceof WP_Error_Stub;
}

function nmm_test_http($url, $method = 'GET', $postBody = null, $headers = array(), $ua = 'nmm-test-suite') {
	// Offline tests can install a fixture handler to answer HTTP without a
	// network. Default behaviour (real curl) is unchanged when none is set.
	if (isset($GLOBALS['nmm_http_handler']) && is_callable($GLOBALS['nmm_http_handler'])) {
		return call_user_func($GLOBALS['nmm_http_handler'], $url, $method, $postBody, $headers);
	}

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 25);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_USERAGENT, $ua);
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if ($body === false) {
		return new WP_Error_Stub();
	}
	return array('body' => $body, 'response' => array('code' => $code));
}

function wp_remote_get($url, $args = array()) {
	return nmm_test_http($url, 'GET', null, array(),
		isset($args['user-agent']) ? $args['user-agent'] : 'nmm-test-suite');
}

function wp_remote_post($url, $args = array()) {
	$headers = array();
	foreach ((array) (isset($args['headers']) ? $args['headers'] : array()) as $k => $v) {
		$headers[] = $k . ': ' . $v;
	}
	return nmm_test_http($url, 'POST', isset($args['body']) ? $args['body'] : null, $headers);
}

// stateful in-process transients so the plugin's backoff layer behaves normally
$GLOBALS['nmm_test_transients'] = array();

function get_transient($key) {
	$row = isset($GLOBALS['nmm_test_transients'][$key]) ? $GLOBALS['nmm_test_transients'][$key] : null;
	if ($row === null || $row['expires'] < time()) {
		return false;
	}
	return $row['value'];
}

function set_transient($key, $value, $expiration) {
	$GLOBALS['nmm_test_transients'][$key] = array('value' => $value, 'expires' => time() + $expiration);
	return true;
}

function delete_transient($key) {
	unset($GLOBALS['nmm_test_transients'][$key]);
	return true;
}

function get_option($key, $default = array()) {
	return $default;
}

function nmm_test_require_plugin($files) {
	$root = dirname(__DIR__);
	foreach ($files as $f) {
		require_once $root . '/' . $f;
	}
}
