<?php
/**
 * Offline regression test for HD address version-byte mapping
 * (HdHelper::hash_160_to_bc_address).
 *
 * Two things are pinned here:
 *
 *  1. Fail closed. The version-byte mapping is an if-chain; before the guard
 *     under test, a coin/type pair with no branch left $addrtype unset and the
 *     function happily base58-encoded hash160 + checksum with NO version byte
 *     - a syntactically plausible address that no wallet controls. A customer
 *     paying it would lose the funds. Unknown pairs must now throw.
 *
 *  2. Known bytes stay known. For every coin the registry
 *     (NMMPRO_Cryptocurrencies) reports as HD-capable, encoding a fixed hash160
 *     (20 zero bytes) must still produce the exact address it always has -
 *     which pins the version byte itself, since the address embeds it. The
 *     expected strings were independently verified (base58check-decoded, the
 *     version byte, zero hash160 and checksum each confirmed) against the
 *     bytes the chain has always assigned: BTC 0, LTC 48, QTUM 58, DOGE 30,
 *     DASH 76, XMY 50, BTX 3; p2sh BTC 5, LTC 50, QTUM 50. The registry drives
 *     the loop so a newly added HD coin without a mapping fails this test
 *     instead of failing a customer.
 *
 * Usage: php tests/test-hd-addrtype.php          (runs both math backends)
 *        php tests/test-hd-addrtype.php GMP      (single backend)
 */

// Every currently supported coin/type -> the address its version byte yields
// for a hash160 of 20 zero bytes. p2pkh is what the plugin derives for every
// order (pubkey_to_bc_address); p2sh is reachable only through the p
// extension, hence the p_enabled() stub below returns true so those branches
// are exercised too.
const NMMPRO_ADDRTYPE_EXPECTED_P2PKH = array(
	'BTC'  => '1111111111111111111114oLvT2',
	'LTC'  => 'LKDxGDJq5fF4FohAB8zJH24mDDNHDNtqsE',
	'QTUM' => 'QLbz7JHiBTspS962RLKV8GndWFwiJNvEPz',
	'DOGE' => 'D596YFweJQuHY1BbjazZYmAbt8jJPbKehC',
	'DASH' => 'XagqqFetxiDb9wbartKDrXgnqLah6SqX2S',
	'XMY'  => 'M7uAERuQW2AotfyLDyewFGcLUDtAYu9v5V',
	'BTX'  => '2D1oxKts8YPdTJRG5FzxTNpMtWmqBnjrht',
);

const NMMPRO_ADDRTYPE_EXPECTED_P2SH = array(
	'BTC'  => '31h1vYVSYuKP6AhS86fbRdMw9XHieotbST',
	'LTC'  => 'M7uAERuQW2AotfyLDyewFGcLUDtAYu9v5V',
	'QTUM' => 'M7uAERuQW2AotfyLDyewFGcLUDtAYu9v5V',
);

if (!isset($argv[1])) {
	// orchestrate: run each backend in its own process (USE_EXT is a constant)
	$failures = 0;
	foreach (array('GMP', 'BCMATH') as $backend) {
		passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' ' . $backend, $exitCode);
		$failures += ($exitCode === 0) ? 0 : 1;
	}
	exit($failures === 0 ? 0 : 1);
}

define('USE_EXT', $argv[1]);

require __DIR__ . '/wp-stubs.php';

// p_enabled() true so the p2sh branches (gated on the extension) are reachable
// and their version bytes pinned; with it false they throw by design, which
// would make the p2sh expectations untestable.
class NMMPRO_Util {
	public static function p_enabled() { return true; }
	public static function log($f, $l, $m) {}
}

$root = dirname(__DIR__);
require $root . '/src/vendor/bcmath_Utils.php';
require $root . '/src/vendor/gmp_Utils.php';
require $root . '/src/vendor/CurveFp.php';
require $root . '/src/vendor/Point.php';
require $root . '/src/vendor/NumberTheory.php';
require $root . '/src/vendor/HdHelper.php';
require $root . '/src/NMMPRO_Cryptocurrency.php';
require $root . '/src/NMMPRO_Cryptocurrencies.php';

$failed = false;

function aok($label, $cond, $extra = '') {
	global $failed;
	printf("%-7s %-58s %s%s\n", USE_EXT, $label, $cond ? 'ok' : 'FAIL', ($cond || $extra === '') ? '' : "  ($extra)");
	if (!$cond) {
		$failed = true;
	}
}

$h160 = str_repeat("\x00", 20);

// The registry is the authority on which coins are HD-capable: every one of
// them must have a pinned p2pkh expectation, or a merchant could enable a coin
// whose derivation cannot finish.
$hdCoins = array();
foreach (NMMPRO_Cryptocurrencies::get() as $crypto) {
	if ($crypto->has_hd()) {
		$hdCoins[] = $crypto->get_id();
	}
}
// Compare as sets: registry declaration order is presentation, not contract.
$registrySet = $hdCoins;
$pinnedSet = array_keys(NMMPRO_ADDRTYPE_EXPECTED_P2PKH);
sort($registrySet);
sort($pinnedSet);
aok('registry HD coins all have pinned version bytes',
	$registrySet === $pinnedSet,
	'registry: ' . implode(',', $hdCoins));

foreach ($hdCoins as $cryptoId) {
	if (!array_key_exists($cryptoId, NMMPRO_ADDRTYPE_EXPECTED_P2PKH)) {
		continue; // already reported above; nothing to compare against
	}
	try {
		$actual = HdHelper::hash_160_to_bc_address($h160, $cryptoId, 'p2pkh');
		aok($cryptoId . ' p2pkh version byte unchanged', $actual === NMMPRO_ADDRTYPE_EXPECTED_P2PKH[$cryptoId], $actual);
	}
	catch (\Exception $e) {
		aok($cryptoId . ' p2pkh version byte unchanged', false, 'threw: ' . $e->getMessage());
	}
}

foreach (NMMPRO_ADDRTYPE_EXPECTED_P2SH as $cryptoId => $expected) {
	try {
		$actual = HdHelper::hash_160_to_bc_address($h160, $cryptoId, 'p2sh');
		aok($cryptoId . ' p2sh version byte unchanged', $actual === $expected, $actual);
	}
	catch (\Exception $e) {
		aok($cryptoId . ' p2sh version byte unchanged', false, 'threw: ' . $e->getMessage());
	}
}

// Unmapped pairs must throw - never emit a versionless address.
foreach (array(array('ETH', 'p2pkh'), array('BTC', 'p2wpkh'), array('DOGE', 'p2sh'), array('', '')) as $pair) {
	$threw = false;
	try {
		HdHelper::hash_160_to_bc_address($h160, $pair[0], $pair[1]);
	}
	catch (\Exception $e) {
		$threw = true;
	}
	aok('unmapped ' . ($pair[0] === '' ? '(empty)' : $pair[0]) . '/' . $pair[1] . ' fails closed', $threw);
}

exit($failed ? 1 : 0);
