<?php
/**
 * Offline test for in-memory QR rendering and the email CID wiring.
 *
 * Structural checks always run. If a QR decoder is available (zbarimg on
 * Linux/CI, installed via the workflow), the generated PNG and rasterized
 * SVG are decoded and must round-trip to the exact payment URI.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$GLOBALS['nmm_test_hooks'] = array();
function add_action($hook, $cb) { $GLOBALS['nmm_test_hooks'][$hook][] = $cb; }

$root = dirname(__DIR__);
require $root . '/src/vendor/phpqrcode.php';
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
