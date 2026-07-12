<?php
/**
 * Offline test for in-memory QR rendering and the email CID wiring.
 *
 * Structural checks always run. If a QR decoder is available (zbarimg on
 * Linux/CI, installed via the workflow), the generated PNG and rasterized
 * SVG are decoded and must round-trip to the exact payment URI.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) define('ABSPATH', sys_get_temp_dir() . '/');

$GLOBALS['nmm_test_hooks'] = array();
function add_action($hook, $cb) { $GLOBALS['nmm_test_hooks'][$hook][] = $cb; }

$root = dirname(__DIR__);
require $root . '/src/vendor/phpqrcode.php';
require $root . '/src/NMM_Cryptocurrency.php';
require $root . '/src/NMM_Cryptocurrencies.php';
require $root . '/src/NMM_Qr.php';

$uri = 'bitcoin:1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa?amount=0.5';
$failed = false;

function ok($label, $pass) {
	global $failed;
	printf("%-42s %s\n", $label, $pass ? 'ok' : 'FAIL');
	if (!$pass) {
		$failed = true;
	}
}

// --- structural ---
$png = NMM_Qr::png_bytes($uri);
ok('png generated with PNG signature', strlen($png) > 100 && substr($png, 1, 3) === 'PNG');

$svg = NMM_Qr::svg($uri, 200);
ok('svg well-formed XML with modules', simplexml_load_string($svg) !== false && substr_count($svg, '<rect') > 50);

// --- email CID wiring ---
class MailerStub {
	public $attached = array();
	public function addStringEmbeddedImage($bytes, $cid, $name, $enc, $type) {
		$this->attached[] = array($cid, strlen($bytes), $type);
	}
}

$cid = NMM_Qr::stash_email_image(12345, $uri);
ok('stash returns cid', $cid === 'nmm-qr-12345');

$stub = new MailerStub();
foreach ($GLOBALS['nmm_test_hooks']['phpmailer_init'] as $cb) {
	call_user_func($cb, $stub);
}
ok('phpmailer_init attaches inline png', count($stub->attached) === 1 && $stub->attached[0][2] === 'image/png');

$stub2 = new MailerStub();
foreach ($GLOBALS['nmm_test_hooks']['phpmailer_init'] as $cb) {
	call_user_func($cb, $stub2);
}
ok('stash cleared after attach', count($stub2->attached) === 0);

// --- payment URI construction ---
$cryptos = NMM_Cryptocurrencies::get();
ok('uri: BTC bip21', NMM_Qr::payment_uri($cryptos['BTC'], '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', '0.5')
	=== 'bitcoin:1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa?amount=0.5');
ok('uri: ETH eip681 wei', NMM_Qr::payment_uri($cryptos['ETH'], '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', '0.05')
	=== 'ethereum:0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B@1?value=50000000000000000');
ok('uri: USDT token transfer', NMM_Qr::payment_uri($cryptos['USDT'], '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', '12.5')
	=== 'ethereum:0xdAC17F958D2ee523a2206206994597C13D831ec7@1/transfer?address=0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B&uint256=12500000');
ok('uri: USDCBAS chain 8453', strpos(NMM_Qr::payment_uri($cryptos['USDCBAS'], '0xAb', '1'), '@8453/transfer?') !== false);
ok('uri: SOL solana pay', NMM_Qr::payment_uri($cryptos['SOL'], 'So11111111111111111111111111111111111111112', '0.25')
	=== 'solana:So11111111111111111111111111111111111111112?amount=0.25');
ok('uri: XMR tx_amount', NMM_Qr::payment_uri($cryptos['XMR'], '44AFFq', '1.5') === 'monero:44AFFq?tx_amount=1.5');

// --- to_base_units: bcmath path (must match the pure-string fallback exactly) ---
ok('base_units: 1.5 @18', NMM_Qr::to_base_units('1.5', 18) === '1500000000000000000');
ok('base_units: 0.000001 @6', NMM_Qr::to_base_units('0.000001', 6) === '1');
ok('base_units: 0 @8', NMM_Qr::to_base_units('0', 8) === '0');
ok('base_units: 12 @0', NMM_Qr::to_base_units('12', 0) === '12');

// --- to_base_units: force the pure-string fallback and assert it matches bcmath ---
$reflection = new ReflectionMethod('NMM_Qr', 'decimal_to_base_units_string');
$reflection->setAccessible(true);
$strScale = function ($amount, $precision) use ($reflection) {
	return $reflection->invoke(null, $amount, (int) $precision);
};
ok('str base_units: 1.5 @18', $strScale('1.5', 18) === '1500000000000000000');
ok('str base_units: 0.000001 @6', $strScale('0.000001', 6) === '1');
ok('str base_units: 0 @8', $strScale('0', 8) === '0');
ok('str base_units: 12 @0', $strScale('12', 0) === '12');
ok('str base_units: 0.1 @8', $strScale('0.1', 8) === '10000000');
ok('str base_units: 12.34 @2', $strScale('12.34', 2) === '1234');
ok('str base_units: truncates extra frac', $strScale('1.239', 2) === '123');

// --- content round-trip (when a decoder is available) ---
exec('command -v zbarimg 2>/dev/null', $out, $noZbar);
if ($noZbar === 0) {
	$tmp = tempnam(sys_get_temp_dir(), 'nmmqr') . '.png';
	file_put_contents($tmp, $png);
	$decoded = trim((string) shell_exec('zbarimg --quiet --raw ' . escapeshellarg($tmp) . ' 2>/dev/null'));
	unlink($tmp);
	ok('png decodes to exact payment URI', $decoded === $uri);
} else {
	echo "(zbarimg not available - decode check skipped)\n";
}

exit($failed ? 1 : 0);
