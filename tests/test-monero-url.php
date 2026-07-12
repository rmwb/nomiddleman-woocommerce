<?php
/**
 * Offline test for NMM_Monero::validate_rpc_url() - the SSRF guard on the
 * merchant-configured Monero wallet RPC URL. Uses IP literals so no DNS is
 * needed. Self-contained stubs let it toggle single-site vs multisite.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }

class WP_Error { public $code; public $message; public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; } }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_parse_url($url) { return parse_url($url); }
function apply_filters($tag, $value) { return $value; } // passthrough
$GLOBALS['nmm_is_ms'] = false;
function is_multisite() { return $GLOBALS['nmm_is_ms']; }

require dirname(__DIR__) . '/src/NMM_Monero.php';

$failed = false;
function mok($label, $pass) { global $failed; printf("%-58s %s\n", $label, $pass ? 'ok' : 'FAIL'); if (!$pass) { $failed = true; } }
function allowed($url) { return !is_wp_error(NMM_Monero::validate_rpc_url($url)); }

// --- scheme / shape ---
mok('rejects ftp scheme',            !allowed('ftp://8.8.8.8:18082/json_rpc'));
mok('rejects file scheme',           !allowed('file:///etc/passwd'));
mok('rejects gopher scheme',         !allowed('gopher://8.8.8.8:70'));
mok('rejects malformed url',         !allowed('not a url'));
mok('rejects empty',                 !allowed(''));

// --- public host is always fine ---
mok('allows public http host',       allowed('http://8.8.8.8:18082/json_rpc'));
mok('allows public https host',      allowed('https://8.8.8.8/json_rpc'));

// --- private / loopback / reserved: single-site allows, multisite blocks ---
$GLOBALS['nmm_is_ms'] = false;
mok('single-site allows loopback',      allowed('http://127.0.0.1:18082/json_rpc'));
mok('single-site allows localhost',     allowed('http://localhost:18082/json_rpc'));
mok('single-site allows private 10.x',  allowed('http://10.1.2.3:18082'));
mok('single-site allows private 192.x', allowed('http://192.168.0.9:18082'));

$GLOBALS['nmm_is_ms'] = true;
mok('multisite blocks loopback',        !allowed('http://127.0.0.1:18082/json_rpc'));
mok('multisite blocks localhost',       !allowed('http://localhost:18082/json_rpc'));
mok('multisite blocks private 10.x',    !allowed('http://10.1.2.3:18082'));
mok('multisite blocks private 172.16',  !allowed('http://172.16.5.5:18082'));
mok('multisite blocks link-local meta', !allowed('http://169.254.169.254/latest/meta-data/'));
mok('multisite blocks IPv6 loopback',   !allowed('http://[::1]:18082'));
mok('multisite still allows public',    allowed('http://8.8.8.8:18082'));

// --- explicit opt-in constant permits private on multisite ---
define('NMM_XMR_ALLOW_PRIVATE_RPC', true);
mok('constant opt-in allows private on multisite', allowed('http://127.0.0.1:18082/json_rpc'));

exit($failed ? 1 : 0);
