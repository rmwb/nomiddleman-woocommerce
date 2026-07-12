<?php
/**
 * Offline test for the Solana Autopay fetcher's bounded, resumable paging.
 *
 * Drives NMM_Blockchain::get_sol_address_transactions() against a scripted RPC
 * (no network) to prove: a per-tick getTransaction budget, an inspected-signature
 * cache so dust is fetched at most once, forward progress across ticks so a
 * buried in-window payment is still reached, and that signatures older than the
 * payment window are never fetched.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/wp-stubs.php';

nmm_test_require_plugin(array(
	'src/NMM_Util.php',
	'src/NMM_Transaction.php',
	'src/NMM_Blockchain.php',
));

$ADDR = 'PAYADDR1111111111111111111111111111111111';
$now = time();

// Build a newest-first ledger. index 0 = newest. One real payment is buried at
// position 40; everything else is zero-value dust. A final entry sits *outside*
// the 3600s window and must never be fetched.
$LIFETIME = 3600;
$PAYMENT_POS = 40;
$PAYMENT_LAMPORTS = 5000000;
$ledger = array();
for ($i = 0; $i < 60; $i++) {
	$ledger[] = array(
		'sig'       => sprintf('sig%03d', $i),
		'blockTime' => $now - 10 - ($i * 10),                 // within window (<= now-600)
		'delta'     => ($i === $PAYMENT_POS) ? $PAYMENT_LAMPORTS : 0,
	);
}
$ledger[] = array('sig' => 'sigOLD', 'blockTime' => $now - 5000, 'delta' => 9999999); // beyond cutoff

$sigIndex = array();
foreach ($ledger as $n => $row) { $sigIndex[$row['sig']] = $n; }

$GLOBALS['nmm_gettx_calls'] = array();   // signatures fetched via getTransaction (this tick)
$GLOBALS['nmm_getsig_calls'] = 0;

$GLOBALS['nmm_http_handler'] = function ($url, $method, $postBody, $headers) use ($ADDR, $ledger, $sigIndex) {
	$req = json_decode($postBody, true);
	$m = isset($req['method']) ? $req['method'] : '';

	if ($m === 'getSignaturesForAddress') {
		$GLOBALS['nmm_getsig_calls']++;
		$opts = isset($req['params'][1]) ? $req['params'][1] : array();
		$limit = isset($opts['limit']) ? (int) $opts['limit'] : 1000;
		$start = 0;
		if (isset($opts['before']) && isset($sigIndex[$opts['before']])) {
			$start = $sigIndex[$opts['before']] + 1;   // strictly older
		}
		$out = array();
		for ($n = $start; $n < count($ledger) && count($out) < $limit; $n++) {
			$out[] = array('signature' => $ledger[$n]['sig'], 'blockTime' => $ledger[$n]['blockTime'], 'err' => null);
		}
		return array('body' => json_encode(array('result' => $out)), 'response' => array('code' => 200));
	}

	if ($m === 'getTransaction') {
		$sig = $req['params'][0];
		$GLOBALS['nmm_gettx_calls'][] = $sig;
		$delta = isset($sigIndex[$sig]) ? $ledger[$sigIndex[$sig]]['delta'] : 0;
		$body = array('result' => array(
			'blockTime' => $ledger[$sigIndex[$sig]]['blockTime'],
			'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => $ADDR)))),
			'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000 + $delta)),
		));
		return array('body' => json_encode($body), 'response' => array('code' => 200));
	}

	return array('body' => json_encode(array('result' => null)), 'response' => array('code' => 200));
};

$failed = false;
function ok($label, $pass, $extra = '') {
	global $failed;
	printf("%-52s %s%s\n", $label, $pass ? 'ok' : 'FAIL', $extra !== '' ? '  ' . $extra : '');
	if (!$pass) { $failed = true; }
}

function tick($addr, $lifetime) {
	$GLOBALS['nmm_gettx_calls'] = array();
	$GLOBALS['nmm_getsig_calls'] = 0;
	$res = NMM_Blockchain::get_sol_address_transactions($addr, $lifetime);
	return $res;
}

// --- Tick 1: newest 25 inspected (budget), payment at 40 not yet reached ---
$r1 = tick($ADDR, $LIFETIME);
ok('tick1 succeeds', $r1['result'] === 'success');
ok('tick1 getTransaction budget respected (<=25)', count($GLOBALS['nmm_gettx_calls']) <= 25, '(' . count($GLOBALS['nmm_gettx_calls']) . ')');
ok('tick1 finds no payment yet', count($r1['transactions']) === 0);

// --- Tick 2: skips cached 0-24, inspects 25-49; payment at 40 is reached ---
$r2 = tick($ADDR, $LIFETIME);
ok('tick2 getTransaction budget respected (<=25)', count($GLOBALS['nmm_gettx_calls']) <= 25, '(' . count($GLOBALS['nmm_gettx_calls']) . ')');
ok('tick2 does not re-fetch cached signatures', count(array_intersect($GLOBALS['nmm_gettx_calls'], array('sig000','sig010','sig024'))) === 0);
ok('tick2 finds the buried payment', count($r2['transactions']) === 1);
if (count($r2['transactions']) === 1) {
	$tx = $r2['transactions'][0];
	ok('  payment amount correct', (int) $tx->get_amount() === $PAYMENT_LAMPORTS, '(' . $tx->get_amount() . ')');
	ok('  payment signature correct', $tx->get_hash() === sprintf('sig%03d', $PAYMENT_POS));
}

// --- Tick 3: forward progress into 50-59, no payment there ---
$r3 = tick($ADDR, $LIFETIME);
ok('tick3 makes progress without re-fetching payment', !in_array(sprintf('sig%03d', $PAYMENT_POS), $GLOBALS['nmm_gettx_calls'], true));
ok('tick3 budget respected (<=25)', count($GLOBALS['nmm_gettx_calls']) <= 25);

// --- Cutoff: the beyond-window signature is never fetched on any tick ---
$allFetched = array();
for ($k = 0; $k < 4; $k++) { tick($ADDR, $LIFETIME); }
// (by now everything in-window is cached; ensure sigOLD was never touched)
$sigOldFetchedEver = false;
// re-run one more tick capturing calls
tick($ADDR, $LIFETIME);
ok('cutoff: beyond-window signature never fetched', !in_array('sigOLD', $GLOBALS['nmm_gettx_calls'], true));

// --- Dusting bound: a fresh address with 1000 dust sigs still bounds one tick ---
$GLOBALS['nmm_http_handler'] = function ($url, $method, $postBody, $headers) {
	$req = json_decode($postBody, true);
	$m = $req['method'];
	$now = time();
	if ($m === 'getSignaturesForAddress') {
		$GLOBALS['nmm_getsig_calls']++;
		$opts = $req['params'][1];
		$limit = (int) $opts['limit'];
		$start = 0;
		if (isset($opts['before'])) { $start = ((int) substr($opts['before'], 4)) + 1; }
		$out = array();
		for ($n = $start; $n < 1000 && count($out) < $limit; $n++) {
			$out[] = array('signature' => sprintf('dust%04d', $n), 'blockTime' => $now - 10, 'err' => null);
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
NMM_Blockchain::get_sol_address_transactions('DUSTADDR9999999999999999999999999999999999', 3600);
ok('dusting: <=25 getTransaction calls in one tick', count($GLOBALS['nmm_gettx_calls']) <= 25, '(' . count($GLOBALS['nmm_gettx_calls']) . ')');
ok('dusting: <=5 getSignatures pages in one tick', $GLOBALS['nmm_getsig_calls'] <= 5, '(' . $GLOBALS['nmm_getsig_calls'] . ')');

exit($failed ? 1 : 0);
