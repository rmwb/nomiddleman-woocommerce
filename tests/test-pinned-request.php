<?php
/**
 * Offline test for NMM_Util::post_with_curl_options() - the transport that
 * replaced the plugin's hand-rolled cURL call for the Monero wallet RPC.
 *
 * The security property under test is fail-closed behaviour: the extra cURL
 * options (the pin to the validated IP, the protocol restriction, and the
 * digest credentials) are installed from WordPress's http_api_curl action,
 * which only fires when WordPress selects its cURL transport. If that action
 * never fires, the request must be reported as unsent rather than treated as
 * a successful unpinned request - and the options, which carry credentials,
 * must never be installed on another plugin's request that happens to be
 * dispatched inside the same window.
 *
 * Self-contained WordPress stubs: no network, no database.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }

class WP_Error {
	public $code; public $message;
	public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

// Minimal hook system: enough to register, fire and remove our callback.
$GLOBALS['nmm_actions'] = array();
$GLOBALS['nmm_filters'] = array();
function add_action($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['nmm_actions'][$tag][$prio][] = $cb; }
function remove_action($tag, $cb, $prio = 10) { unset($GLOBALS['nmm_actions'][$tag][$prio]); }
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['nmm_filters'][$tag][$prio][] = $cb; }
function remove_filter($tag, $cb, $prio = 10) { unset($GLOBALS['nmm_filters'][$tag][$prio]); }
function do_action_ref_array($tag, $args) {
	if (empty($GLOBALS['nmm_actions'][$tag])) { return; }
	foreach ($GLOBALS['nmm_actions'][$tag] as $callbacks) {
		foreach ($callbacks as $cb) { call_user_func_array($cb, $args); }
	}
}
function __return_false() { return false; }

// wp_remote_post stand-in. $GLOBALS['nmm_fire_curl_hook'] decides whether the
// "transport" fires http_api_curl - i.e. whether WordPress picked cURL.
// $GLOBALS['nmm_hook_url'] / ['nmm_hook_args'] let a test fire the action as
// though it belonged to a DIFFERENT request.
//
// The handle is a REAL cURL handle (the extension is loaded, so
// curl_setopt_array cannot be stubbed). Whether the options were installed is
// then read back from the handle itself: the options under test set
// CURLOPT_URL, which curl_getinfo reports as the effective URL. No request is
// ever executed - curl_exec is never called.
function wp_remote_post($url, $args = array()) {
	if ($GLOBALS['nmm_fire_curl_hook']) {
		$handle = curl_init(NMM_TEST_UNSET_URL);
		$GLOBALS['nmm_handle'] = $handle;
		$hookUrl  = $GLOBALS['nmm_hook_url']  !== null ? $GLOBALS['nmm_hook_url']  : $url;
		$hookArgs = $GLOBALS['nmm_hook_args'] !== null ? $GLOBALS['nmm_hook_args'] : $args;
		do_action_ref_array('http_api_curl', array(&$handle, $hookArgs, $hookUrl));
	}
	return array('body' => '{"result":"ok"}', 'response' => array('code' => 200));
}

define('NMM_TEST_UNSET_URL', 'http://unset.example/');
define('NMM_TEST_PINNED_URL', 'http://pinned.example/');

// True when the options under test reached the handle.
function options_installed() {
	if (empty($GLOBALS['nmm_handle'])) { return false; }
	return curl_getinfo($GLOBALS['nmm_handle'], CURLINFO_EFFECTIVE_URL) === NMM_TEST_PINNED_URL;
}

function wp_remote_retrieve_response_code($r) { return isset($r['response']['code']) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body($r) { return isset($r['body']) ? $r['body'] : ''; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function wc_get_logger() { return null; }

require dirname(__DIR__) . '/src/NMM_Util.php';

$failed = false;
function ok($label, $pass) {
	global $failed;
	printf("%-64s %s\n", $label, $pass ? 'ok' : 'FAIL');
	if (!$pass) { $failed = true; }
}
function reset_state() {
	$GLOBALS['nmm_handle'] = null;
	$GLOBALS['nmm_actions'] = array();
	$GLOBALS['nmm_filters'] = array();
	$GLOBALS['nmm_hook_url'] = null;
	$GLOBALS['nmm_hook_args'] = null;
}

$url = 'http://198.51.100.7:18083/json_rpc';
// CURLOPT_URL stands in for the real options (pin, protocols, digest
// credentials): it is the one setting a test can read back off the handle.
$options = array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_URL => NMM_TEST_PINNED_URL);

// --- 1. cURL transport selected: options installed, response returned ---
reset_state();
$GLOBALS['nmm_fire_curl_hook'] = true;
$response = NMM_Util::post_with_curl_options($url, array('body' => '{}'), $options);
ok('cURL transport: request succeeds',            !is_wp_error($response));
ok('cURL transport: options installed on the handle', options_installed());

// --- 2. Action never fires (streams transport): fail closed ---
reset_state();
$GLOBALS['nmm_fire_curl_hook'] = false;
$response = NMM_Util::post_with_curl_options($url, array('body' => '{}'), $options);
ok('no cURL transport: returns an error, not the response', is_wp_error($response));
ok('no cURL transport: error code is nmm_pin_unavailable',
	is_wp_error($response) && $response->get_error_code() === 'nmm_pin_unavailable');
ok('no cURL transport: no handle was ever configured', !options_installed());

// --- 3. A different request in the same window must not get our options ---
reset_state();
$GLOBALS['nmm_fire_curl_hook'] = true;
$GLOBALS['nmm_hook_url'] = 'https://example.com/somewhere-else';
$response = NMM_Util::post_with_curl_options($url, array('body' => '{}'), $options);
ok('foreign URL: options never installed', !options_installed());
ok('foreign URL: reported as unpinned rather than sent pinned',
	is_wp_error($response) && $response->get_error_code() === 'nmm_pin_unavailable');

// --- 4. Matching URL but no/foreign token: still refused ---
reset_state();
$GLOBALS['nmm_fire_curl_hook'] = true;
$GLOBALS['nmm_hook_args'] = array('nmm_pinned_token' => 'someone-elses-token');
$response = NMM_Util::post_with_curl_options($url, array('body' => '{}'), $options);
ok('foreign token: options never installed', !options_installed());
ok('foreign token: reported as unpinned', is_wp_error($response) && $response->get_error_code() === 'nmm_pin_unavailable');

// --- 5. Redirects are refused for every pinned request ---
reset_state();
$GLOBALS['nmm_fire_curl_hook'] = true;
add_action('http_api_curl', function ($h, $args, $u) { $GLOBALS['nmm_captured_args'] = $args; }, 20, 3);
NMM_Util::post_with_curl_options($url, array('body' => '{}', 'redirection' => 5), $options);
ok('redirection is overridden to 0',
	isset($GLOBALS['nmm_captured_args']['redirection']) && $GLOBALS['nmm_captured_args']['redirection'] === 0);

echo "\n" . ($failed ? 'PINNED-REQUEST CHECKS FAILED' : 'PINNED-REQUEST CHECKS PASSED') . "\n";
exit($failed ? 1 : 0);
