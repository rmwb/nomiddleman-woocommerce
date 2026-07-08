<?php
/**
 * Offline regression test for HD (BIP32 xpub) address derivation.
 *
 * Derives external-chain addresses 0-2 from a public BIP32 test xpub on
 * both math backends and compares them to known-good addresses that were
 * cross-verified against an independent BIP32 implementation (see
 * tests/verify_bip32.py) validated with published secp256k1 test values.
 *
 * Usage: php tests/test-hd-derivation.php          (runs both backends)
 *        php tests/test-hd-derivation.php GMP      (single backend)
 */

// BIP32 test vector 1 chain m/0'/1 xpub
const NMM_TEST_XPUB = 'xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ';

const NMM_TEST_EXPECTED = array(
	0 => '1BiCdXSDHyeXSzmx2paVPFVTrmyx7BeCGD',
	1 => '132EVXhbnaeF2U9ZkakX6FJWqLzmPazQA7',
	2 => '1DfqqdJJfsTAy5qnz4LnSdkycQVWrnQR9Y',
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

class NMM_Util {
	public static function p_enabled() { return false; }
	public static function log($f, $l, $m) {}
}

$root = dirname(__DIR__);
require $root . '/src/vendor/bcmath_Utils.php';
require $root . '/src/vendor/gmp_Utils.php';
require $root . '/src/vendor/CurveFp.php';
require $root . '/src/vendor/Point.php';
require $root . '/src/vendor/NumberTheory.php';
require $root . '/src/vendor/HdHelper.php';

$failed = false;

foreach (NMM_TEST_EXPECTED as $index => $expected) {
	$actual = HdHelper::mpk_to_bc_address('BTC', NMM_TEST_XPUB, $index, 2, false);
	$ok = ($actual === $expected);
	printf("%-7s m/0/%d  %-36s %s\n", USE_EXT, $index, $actual, $ok ? 'ok' : "FAIL (expected $expected)");
	if (!$ok) {
		$failed = true;
	}
}

exit($failed ? 1 : 0);
