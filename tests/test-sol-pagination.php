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
	'src/NMM_Blockchain.php',
));

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

// ---- Scenario D: payment's first getTransaction fails, next tick succeeds ----
// The payment sits near the top (pos 2) of a large history, so if a failed
// detail lookup were only retried on sweep restart it would take ~28 ticks;
// the retry queue must instead recover it on the very next tick.
$ADDR4 = 'RETRY44444444444444444444444444444444444444';
$PAYSIG = 'sig00002';
$now = time();
$ledgerD = array();
for ($i = 0; $i < 700; $i++) {
	$ledgerD[] = array('sig' => sprintf('sig%05d', $i), 'blockTime' => $now - 5 - $i, 'delta' => ($i === 2) ? 4200000 : 0);
}
$idxD = array();
foreach ($ledgerD as $n => $r) { $idxD[$r['sig']] = $n; }
$GLOBALS['nmm_gettx_counts'] = array();

$GLOBALS['nmm_http_handler'] = function ($url, $method, $postBody, $headers) use ($ADDR4, $ledgerD, $idxD, $PAYSIG) {
	$req = json_decode($postBody, true);
	$m = $req['method'];
	if ($m === 'getSignaturesForAddress') {
		$GLOBALS['nmm_getsig_calls']++;
		$opts = $req['params'][1];
		$limit = (int) $opts['limit'];
		$start = (isset($opts['before']) && isset($idxD[$opts['before']])) ? $idxD[$opts['before']] + 1 : 0;
		$out = array();
		for ($n = $start; $n < count($ledgerD) && count($out) < $limit; $n++) {
			$out[] = array('signature' => $ledgerD[$n]['sig'], 'blockTime' => $ledgerD[$n]['blockTime'], 'err' => null);
		}
		return array('body' => json_encode(array('result' => $out)), 'response' => array('code' => 200));
	}
	$sig = $req['params'][0];
	$GLOBALS['nmm_gettx_calls'][] = $sig;
	$GLOBALS['nmm_gettx_counts'][$sig] = (isset($GLOBALS['nmm_gettx_counts'][$sig]) ? $GLOBALS['nmm_gettx_counts'][$sig] : 0) + 1;
	// Fail the payment's FIRST detail lookup with an incomplete (not-yet-
	// available) response - retryable, and does not trip the host backoff a
	// 429/5xx would, so we can assert the retry-queue timing directly.
	if ($sig === $PAYSIG && $GLOBALS['nmm_gettx_counts'][$sig] === 1) {
		return array('body' => json_encode(array('result' => null)), 'response' => array('code' => 200));
	}
	$delta = isset($idxD[$sig]) ? $ledgerD[$idxD[$sig]]['delta'] : 0;
	$body = array('result' => array(
		'blockTime' => $ledgerD[$idxD[$sig]]['blockTime'],
		'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => $ADDR4)))),
		'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000 + $delta)),
	));
	return array('body' => json_encode($body), 'response' => array('code' => 200));
};

$foundTickD = -1;
$budgetOkD = true;
for ($t = 1; $t <= 5; $t++) {
	$r = tick($ADDR4, $LIFE);
	if (count($GLOBALS['nmm_gettx_calls']) > 25) { $budgetOkD = false; }
	foreach ($r['transactions'] as $tx) {
		if ($tx->get_hash() === $PAYSIG) { $foundTickD = $t; break; }
	}
	if ($foundTickD > 0) { break; }
}
ok('payment whose 1st detail lookup failed is recovered', $foundTickD > 0, '(tick ' . $foundTickD . ')');
ok('  recovered on the next tick via retry queue (not sweep restart)', $foundTickD === 2, '(tick ' . $foundTickD . ')');
ok('  payment not found on tick 1 (its lookup failed then)', $foundTickD !== 1);
ok('  per-tick budget held during retry', $budgetOkD);

// ---- Scenario E: 25 permanently failing signatures + a valid new payment ----
// Positions 0-24 always return an (unavailable) retryable result; a real payment
// sits at position 25. The failing 25 must never starve the sweep - the payment
// has to be inspected within a couple of ticks, budget held every tick.
$ADDR5 = 'STARVE555555555555555555555555555555555555555';
$PAYSIG5 = 'sig00025';
$now = time();
$ledgerE = array();
for ($i = 0; $i < 120; $i++) {
	$ledgerE[] = array('sig' => sprintf('sig%05d', $i), 'blockTime' => $now - 5 - $i, 'delta' => ($i === 25) ? 6600000 : 0);
}
$idxE = array();
foreach ($ledgerE as $n => $r) { $idxE[$r['sig']] = $n; }

$GLOBALS['nmm_http_handler'] = function ($url, $method, $postBody, $headers) use ($ADDR5, $ledgerE, $idxE, $now) {
	$req = json_decode($postBody, true);
	if ($req['method'] === 'getSignaturesForAddress') {
		$GLOBALS['nmm_getsig_calls']++;
		$opts = $req['params'][1];
		$limit = (int) $opts['limit'];
		$start = (isset($opts['before']) && isset($idxE[$opts['before']])) ? $idxE[$opts['before']] + 1 : 0;
		$out = array();
		for ($n = $start; $n < count($ledgerE) && count($out) < $limit; $n++) {
			$out[] = array('signature' => $ledgerE[$n]['sig'], 'blockTime' => $ledgerE[$n]['blockTime'], 'err' => null);
		}
		return array('body' => json_encode(array('result' => $out)), 'response' => array('code' => 200));
	}
	$sig = $req['params'][0];
	$GLOBALS['nmm_gettx_calls'][] = $sig;
	$pos = isset($idxE[$sig]) ? $idxE[$sig] : -1;
	if ($pos >= 0 && $pos <= 24) {
		// permanently unavailable => retryable failure, forever
		return array('body' => json_encode(array('result' => null)), 'response' => array('code' => 200));
	}
	$delta = ($pos >= 0) ? $ledgerE[$pos]['delta'] : 0;
	$body = array('result' => array(
		'blockTime' => $now,
		'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => $ADDR5)))),
		'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000 + $delta)),
	));
	return array('body' => json_encode($body), 'response' => array('code' => 200));
};

$foundTickE = -1;
$budgetOkE = true;
for ($t = 1; $t <= 6; $t++) {
	$r = tick($ADDR5, $LIFE);
	if (count($GLOBALS['nmm_gettx_calls']) > 25) { $budgetOkE = false; }
	foreach ($r['transactions'] as $tx) {
		if ($tx->get_hash() === $PAYSIG5) { $foundTickE = $t; break; }
	}
	if ($foundTickE > 0) { break; }
}
ok('valid payment found despite 25 permanently-failing retries', $foundTickE > 0, '(tick ' . $foundTickE . ')');
ok('  found quickly (sweep not starved by retries)', $foundTickE > 0 && $foundTickE <= 3, '(tick ' . $foundTickE . ')');
ok('  per-tick budget held with a full failing queue', $budgetOkE);

exit($failed ? 1 : 0);
