<?php
/**
 * Offline test for the Solana Autopay fetcher's bounded, resumable sweep.
 *
 * Drives NMM_Blockchain::get_sol_address_transactions() against a scripted RPC
 * (no network) to prove: a per-tick getTransaction budget, monotonic forward
 * progress via a persisted cursor so a payment buried far beyond one tick's
 * batch is still reached within one sweep (the case a fixed-size dedup cache
 * could not guarantee), prompt detection of a payment near the top, that
 * out-of-window signatures are never fetched, and a hard per-tick bound under a
 * dust flood.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/wp-stubs.php';

nmm_test_require_plugin(array(
	'src/NMM_Util.php',
	'src/NMM_Transaction.php',
	'src/NMM_Sol_Retry_Repo.php',
	'src/NMM_Blockchain.php',
));

// No $wpdb offline: the durable retry store no-ops, so these sweep scenarios
// (which never fail a detail lookup) exercise the cursor logic without a DB.
// The retry queue itself is covered by the live-DB test tests/test-sol-retry.php.
if (!defined('NMM_SOL_RETRY_TABLE')) { define('NMM_SOL_RETRY_TABLE', 'nmmpro_sol_retry'); }

$failed = false;
function ok($label, $pass, $extra = '') {
	global $failed;
	printf("%-56s %s%s\n", $label, $pass ? 'ok' : 'FAIL', $extra !== '' ? '  ' . $extra : '');
	if (!$pass) { $failed = true; }
}

// Build a scripted ledger for one address: newest-first, index 0 = newest.
// $paymentPos gets a positive lamport delta; everything else is dust (delta 0).
// A final entry sits outside the window and must never be fetched.
function make_handler($addr, $count, $paymentPos, $paymentLamports, $lifetime) {
	$now = time();
	$ledger = array();
	for ($i = 0; $i < $count; $i++) {
		$ledger[] = array(
			'sig'       => sprintf('sig%05d', $i),
			'blockTime' => $now - 5 - $i,                       // all within window
			'delta'     => ($i === $paymentPos) ? $paymentLamports : 0,
		);
	}
	$ledger[] = array('sig' => 'sigOLD', 'blockTime' => $now - ($lifetime + 5000), 'delta' => 9999999);
	$idx = array();
	foreach ($ledger as $n => $r) { $idx[$r['sig']] = $n; }

	return function ($url, $method, $postBody, $headers) use ($addr, $ledger, $idx) {
		$req = json_decode($postBody, true);
		$m = isset($req['method']) ? $req['method'] : '';
		if ($m === 'getSignaturesForAddress') {
			$GLOBALS['nmm_getsig_calls']++;
			$opts = isset($req['params'][1]) ? $req['params'][1] : array();
			$limit = isset($opts['limit']) ? (int) $opts['limit'] : 1000;
			$start = (isset($opts['before']) && isset($idx[$opts['before']])) ? $idx[$opts['before']] + 1 : 0;
			$out = array();
			for ($n = $start; $n < count($ledger) && count($out) < $limit; $n++) {
				$out[] = array('signature' => $ledger[$n]['sig'], 'blockTime' => $ledger[$n]['blockTime'], 'err' => null);
			}
			return array('body' => json_encode(array('result' => $out)), 'response' => array('code' => 200));
		}
		if ($m === 'getTransaction') {
			$sig = $req['params'][0];
			$GLOBALS['nmm_gettx_calls'][] = $sig;
			$delta = isset($idx[$sig]) ? $ledger[$idx[$sig]]['delta'] : 0;
			$body = array('result' => array(
				'blockTime' => $ledger[$idx[$sig]]['blockTime'],
				'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => $addr)))),
				'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000 + $delta)),
			));
			return array('body' => json_encode($body), 'response' => array('code' => 200));
		}
		return array('body' => json_encode(array('result' => null)), 'response' => array('code' => 200));
	};
}

function tick($addr, $lifetime) {
	$GLOBALS['nmm_gettx_calls'] = array();
	$GLOBALS['nmm_getsig_calls'] = 0;
	return NMM_Blockchain::get_sol_address_transactions($addr, $lifetime);
}

// ---- Scenario A: payment buried at position 650 among 700 in-window sigs ----
$LIFE = 100000;
$ADDR = 'BURIED11111111111111111111111111111111111111';
$PAYPOS = 650;
$PAYLAMPORTS = 7000000;
$GLOBALS['nmm_http_handler'] = make_handler($ADDR, 700, $PAYPOS, $PAYLAMPORTS, $LIFE);

$foundTick = -1;
$budgetOk = true;
$pagesOk = true;
$sigOldFetched = false;
$maxTicks = 40;
for ($t = 1; $t <= $maxTicks; $t++) {
	$r = tick($ADDR, $LIFE);
	if (count($GLOBALS['nmm_gettx_calls']) > 25) { $budgetOk = false; }
	if ($GLOBALS['nmm_getsig_calls'] > 5) { $pagesOk = false; }
	if (in_array('sigOLD', $GLOBALS['nmm_gettx_calls'], true)) { $sigOldFetched = true; }
	foreach ($r['transactions'] as $tx) {
		if ($tx->get_hash() === sprintf('sig%05d', $PAYPOS)) { $foundTick = $t; break; }
	}
	if ($foundTick > 0) { break; }
}

ok('buried payment at pos 650 is eventually found', $foundTick > 0, '(tick ' . $foundTick . ')');
ok('found within one sweep (<= ceil(700/25)+2 ticks)', $foundTick > 0 && $foundTick <= 30, '(tick ' . $foundTick . ')');
ok('per-tick getTransaction budget held every tick (<=25)', $budgetOk);
ok('per-tick getSignatures pages held every tick (<=5)', $pagesOk);
ok('out-of-window signature never fetched', !$sigOldFetched);

// ---- Scenario B: payment near the top is found promptly (tick 1) ----
$ADDR2 = 'PROMPT22222222222222222222222222222222222222';
$GLOBALS['nmm_http_handler'] = make_handler($ADDR2, 300, 2, 3000000, $LIFE);
$r1 = tick($ADDR2, $LIFE);
$prompt = false;
foreach ($r1['transactions'] as $tx) { if ($tx->get_hash() === 'sig00002') { $prompt = true; } }
ok('payment near the top found on tick 1', $prompt);
ok('  amount correct', $prompt && (int) $r1['transactions'][0]->get_amount() === 3000000);

// ---- Scenario C: dust flood still bounds a single tick ----
$GLOBALS['nmm_http_handler'] = function ($url, $method, $postBody, $headers) {
	$req = json_decode($postBody, true);
	if ($req['method'] === 'getSignaturesForAddress') {
		$GLOBALS['nmm_getsig_calls']++;
		$opts = $req['params'][1];
		$limit = (int) $opts['limit'];
		$start = isset($opts['before']) ? ((int) substr($opts['before'], 4)) + 1 : 0;
		$out = array();
		$now = time();
		for ($n = $start; $n < 5000 && count($out) < $limit; $n++) {
			$out[] = array('signature' => sprintf('dust%05d', $n), 'blockTime' => $now - 10, 'err' => null);
		}
		return array('body' => json_encode(array('result' => $out)), 'response' => array('code' => 200));
	}
	$GLOBALS['nmm_gettx_calls'][] = $req['params'][0];
	return array('body' => json_encode(array('result' => array(
		'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => 'X')))),
		'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000)),
	))), 'response' => array('code' => 200));
};
$GLOBALS['nmm_gettx_calls'] = array();
$GLOBALS['nmm_getsig_calls'] = 0;
NMM_Blockchain::get_sol_address_transactions('DUST3333333333333333333333333333333333333333', 3600);
ok('dust flood: <=25 getTransaction in one tick', count($GLOBALS['nmm_gettx_calls']) <= 25, '(' . count($GLOBALS['nmm_gettx_calls']) . ')');
ok('dust flood: <=5 getSignatures pages in one tick', $GLOBALS['nmm_getsig_calls'] <= 5, '(' . $GLOBALS['nmm_getsig_calls'] . ')');

exit($failed ? 1 : 0);
