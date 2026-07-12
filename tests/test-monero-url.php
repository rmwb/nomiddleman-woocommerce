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

// --- validate_rpc_url now reports literal / private shape ---
$GLOBALS['nmm_is_ms'] = false;
$pubLiteral = NMM_Monero::validate_rpc_url('http://8.8.8.8:18082');
mok('public literal: is_literal true',  $pubLiteral['is_literal'] === true);
mok('public literal: is_private false', $pubLiteral['is_private'] === false);
mok('public literal: ip pinned',        $pubLiteral['ip'] === '8.8.8.8');
$loopLiteral = NMM_Monero::validate_rpc_url('http://127.0.0.1:18082');
mok('loopback literal: is_literal true',  $loopLiteral['is_literal'] === true);
mok('loopback literal: is_private true',  $loopLiteral['is_private'] === true);

// --- plan_request: transport must never resolve away from the vetted address ---
// plan($target, $hasCurl, $canPin, $hasCreds)
function plan($target, $curl, $canPin, $creds) { return NMM_Monero::plan_request($target, $curl, $canPin, $creds); }

$publicLiteral  = array('host' => '8.8.8.8',            'port' => 18082, 'ip' => '8.8.8.8',       'is_literal' => true,  'is_private' => false);
$publicHost     = array('host' => 'wallet.example.com','port' => 443,   'ip' => '93.184.216.34', 'is_literal' => false, 'is_private' => false);
$privateLiteral = array('host' => '127.0.0.1',         'port' => 18082, 'ip' => '127.0.0.1',     'is_literal' => true,  'is_private' => true);
$privateHost    = array('host' => 'localhost',         'port' => 18082, 'ip' => '127.0.0.1',     'is_literal' => false, 'is_private' => true);
$unresolvable   = array('host' => 'nope.invalid',      'port' => 80,    'ip' => '',              'is_literal' => false, 'is_private' => true);

// cURL that can pin: pin every host, public or private.
mok('curl pins public literal',      plan($publicLiteral, true, true, false)['transport'] === 'curl');
mok('curl pins public hostname',     plan($publicHost,    true, true, false)['transport'] === 'curl');
mok('curl pins private hostname',    plan($privateHost,   true, true, false)['transport'] === 'curl');
mok('curl digest off without creds', plan($publicLiteral, true, true, false)['digest'] === false);
mok('curl digest on with creds',     plan($publicLiteral, true, true, true)['digest']  === true);

// cURL present but the build CANNOT pin (no CURLOPT_RESOLVE): a hostname must be
// refused (it would send unpinned), while an IP literal is still safe over cURL
// - no DNS to rebind - and keeps digest auth working.
mok('curl no-pin public host -> reject',   plan($publicHost,    true, false, false)['transport'] === 'reject');
mok('curl no-pin private host -> reject',  plan($privateHost,   true, false, false)['transport'] === 'reject');
mok('curl no-pin ip literal -> curl',      plan($publicLiteral, true, false, false)['transport'] === 'curl');
mok('curl no-pin ip literal keeps digest', plan($privateLiteral, true, false, true)['digest'] === true);

// No cURL: only IP literals are safe (no DNS to rebind).
mok('no-curl ip literal -> wp_remote',      plan($publicLiteral,  false, false, false)['transport'] === 'wp_remote');
mok('no-curl private literal -> wp_remote', plan($privateLiteral, false, false, false)['transport'] === 'wp_remote');

// No cURL: EVERY hostname is refused - a public host is just as rebindable as a
// private one because the resolved IP at connect time cannot be pinned.
mok('no-curl public host -> reject',     plan($publicHost,     false, false, false)['transport'] === 'reject');
mok('no-curl private host -> reject',    plan($privateHost,    false, false, false)['transport'] === 'reject');
mok('no-curl unresolvable -> reject',    plan($unresolvable,   false, false, false)['transport'] === 'reject');
// Can pin in principle, but no ip to pin and not a literal: must not fall through.
mok('curl but no ip -> reject',          plan($unresolvable,   true,  true,  false)['transport'] === 'reject');

// --- explicit opt-in constant permits private on multisite ---
$GLOBALS['nmm_is_ms'] = true;
define('NMM_XMR_ALLOW_PRIVATE_RPC', true);
mok('constant opt-in allows private on multisite', allowed('http://127.0.0.1:18082/json_rpc'));

exit($failed ? 1 : 0);
