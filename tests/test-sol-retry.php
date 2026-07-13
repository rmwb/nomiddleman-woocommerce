<?php
/**
 * Live-DB test for the durable Solana retry queue (NMM_Sol_Retry_Repo) and the
 * fetcher's use of it. Requires WordPress + a database and mocks the Solana RPC
 * via the pre_http_request filter.
 *
 *   Run:  wp eval-file tests/test-sol-retry.php
 *
 * When invoked standalone (php tests/test-sol-retry.php) there is no $wpdb, so
 * it skips - the offline sweep logic is covered by tests/test-sol-pagination.php.
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
	echo "test-sol-retry: skipped (needs WordPress + DB; run via `wp eval-file tests/test-sol-retry.php`)\n";
	return;
}

// The classes are only auto-loaded when WooCommerce is active; require them
// directly so this test runs against just WordPress + a database.
$nmmSrc = dirname(__DIR__) . '/src/';
require_once $nmmSrc . 'NMM_Util.php';
require_once $nmmSrc . 'NMM_Transaction.php';
require_once $nmmSrc . 'NMM_Sol_Retry_Repo.php';
require_once $nmmSrc . 'NMM_Blockchain.php';

$wpdb = $GLOBALS['wpdb'];
$t = $wpdb->prefix . NMM_SOL_RETRY_TABLE;
NMM_create_sol_retry_table();
$wpdb->query("DELETE FROM `$t`");

// wp eval-file runs this file in function scope, so a top-level `$pass` is not
// the `global $pass` rok() writes; track through $GLOBALS so the banner (which
// CI greps) can never print PASSED while a check failed.
$GLOBALS['sr_ok'] = true;
function rok($label, $cond, $extra = '') { printf("%-56s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['sr_ok'] = false; } }

$LIFE = 100000;
$now = time();

// Mock the Solana RPC. getSignaturesForAddress serves $GLOBALS['sol_sigs']
// (newest-first {signature, blockTime}); getTransaction consults
// $GLOBALS['sol_tx'][sig]: 'fail' => retryable null result, else integer lamport
// delta credited to the queried address.
$GLOBALS['sol_sigs'] = array();
$GLOBALS['sol_tx'] = array();
$GLOBALS['sol_gettx'] = array();
add_filter('pre_http_request', function ($pre, $args, $url) {
	if (strpos($url, 'api.mainnet-beta.solana.com') === false) { return $pre; }
	$req = json_decode($args['body'], true);
	$m = $req['method'];
	if ($m === 'getSignaturesForAddress') {
		$opts = isset($req['params'][1]) ? $req['params'][1] : array();
		$limit = isset($opts['limit']) ? (int) $opts['limit'] : 1000;
		$sigs = $GLOBALS['sol_sigs'];
		$start = 0;
		if (isset($opts['before'])) {
			foreach ($sigs as $i => $s) { if ($s['signature'] === $opts['before']) { $start = $i + 1; break; } }
		}
		$out = array_slice($sigs, $start, $limit);
		foreach ($out as &$o) { $o['err'] = null; }
		return array('response' => array('code' => 200), 'body' => json_encode(array('result' => $out)));
	}
	// getTransaction
	$sig = $req['params'][0];
	$GLOBALS['sol_gettx'][] = $sig;
	$addr = $GLOBALS['sol_addr'];
	$beh = isset($GLOBALS['sol_tx'][$sig]) ? $GLOBALS['sol_tx'][$sig] : 0;
	if ($beh === 'fail') {
		return array('response' => array('code' => 200), 'body' => json_encode(array('result' => null)));
	}
	return array('response' => array('code' => 200), 'body' => json_encode(array('result' => array(
		'blockTime' => time(),
		'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => $addr)))),
		'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000 + (int) $beh)),
	))));
}, 10, 3);

function run_tick($addr, $life) {
	$GLOBALS['sol_addr'] = $addr;
	$GLOBALS['sol_gettx'] = array();
	delete_transient('nmm_sol_cursor_' . md5($addr)); // fresh sweep each tick for determinism
	return NMM_Blockchain::get_sol_address_transactions($addr, $life);
}

// ---------- 1. Recovery: fail then succeed via the durable queue ----------
$A = 'RREC1111111111111111111111111111111111111111';
$wpdb->query("DELETE FROM `$t`");
$GLOBALS['sol_sigs'] = array(array('signature' => 'rec_pay', 'blockTime' => $now - 10));
$GLOBALS['sol_tx'] = array('rec_pay' => 'fail');           // 1st lookup fails
$r1 = run_tick($A, $LIFE);
$queuedAfterFail = NMM_Sol_Retry_Repo::count_for($A);
$GLOBALS['sol_tx'] = array('rec_pay' => 4200000);          // now succeeds
$r2 = run_tick($A, $LIFE);
$found = false; foreach ($r2['transactions'] as $tx) { if ($tx->get_hash() === 'rec_pay') { $found = (int) $tx->get_amount(); } }
rok('failed lookup is stored durably in the queue', $queuedAfterFail === 1, "($queuedAfterFail)");
rok('recovered on a later tick from the durable queue', $found === 4200000, $found ? "amt=$found" : '');
rok('resolved entry removed from the queue', NMM_Sol_Retry_Repo::count_for($A) === 0);

// ---------- 2. Durability: 100 pending failures, new payment still collected ----------
$B = 'RDUR2222222222222222222222222222222222222222';
$wpdb->query("DELETE FROM `$t`");
// Seed 100 not-yet-due failures directly (simulate a large pending backlog).
for ($i = 0; $i < 100; $i++) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$t` (address, signature, first_failed_at, attempts, next_retry_at, block_time) VALUES (%s,%s,%d,3,%d,%d)",
		$B, sprintf('pend%03d', $i), $now - 50, $now + 99999, $now - 20));
}
// A brand-new payment sits at the very top of the signature history.
$GLOBALS['sol_sigs'] = array(array('signature' => 'newpay', 'blockTime' => $now - 5));
$GLOBALS['sol_tx'] = array('newpay' => 5500000);
$rB = run_tick($B, $LIFE);
$newFound = false; foreach ($rB['transactions'] as $tx) { if ($tx->get_hash() === 'newpay') { $newFound = true; } }
rok('new payment collected despite 100 pending failures (no pause)', $newFound);
rok('all 100 pending failures retained (durable, no eviction)', NMM_Sol_Retry_Repo::count_for($B) === 100, '(' . NMM_Sol_Retry_Repo::count_for($B) . ')');

// ---------- 3. Bounded due batch + backoff ----------
$C = 'RBAT3333333333333333333333333333333333333333';
$wpdb->query("DELETE FROM `$t`");
for ($i = 0; $i < 40; $i++) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$t` (address, signature, first_failed_at, attempts, next_retry_at, block_time) VALUES (%s,%s,%d,1,%d,%d)",
		$C, sprintf('due%03d', $i), $now - 50, $now - 10, $now - 20)); // all due
	$GLOBALS['sol_tx'][sprintf('due%03d', $i)] = 'fail';
}
$GLOBALS['sol_sigs'] = array(); // no sweep work
$before = $wpdb->get_var($wpdb->prepare("SELECT next_retry_at FROM `$t` WHERE address=%s AND signature='due000'", $C));
run_tick($C, $LIFE);
$retryCalls = count($GLOBALS['sol_gettx']);
$after = $wpdb->get_var($wpdb->prepare("SELECT next_retry_at FROM `$t` WHERE address=%s AND signature='due000'", $C));
$attemptsAfter = $wpdb->get_var($wpdb->prepare("SELECT attempts FROM `$t` WHERE address=%s AND signature='due000'", $C));
rok('per-tick retries bounded to half budget (<=12) of 40 due', $retryCalls <= 12, "($retryCalls)");
rok('failed retry backs off (next_retry_at advanced)', (int) $after > (int) $before);
rok('failed retry increments attempts', (int) $attemptsAfter === 2, "($attemptsAfter)");

// ---------- 4. Expiry: outside the window / past retention ----------
$D = 'REXP4444444444444444444444444444444444444444';
$wpdb->query("DELETE FROM `$t`");
$wpdb->query($wpdb->prepare("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES (%s,'exp_blk',%d,5,%d,%d)", $D, $now - 200, $now - 10, $now - ($LIFE + 500))); // block_time outside window
$wpdb->query($wpdb->prepare("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES (%s,'exp_ret',%d,9,%d,0)", $D, $now - ($LIFE + 5000), $now - 10));          // no block_time, past retention
$wpdb->query($wpdb->prepare("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES (%s,'keep_in',%d,2,%d,%d)", $D, $now - 50, $now + 99999, $now - 20));           // in-window, keep
$GLOBALS['sol_sigs'] = array();
run_tick($D, $LIFE);
rok('entry with block_time outside window expired', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$t` WHERE address=%s AND signature='exp_blk'", $D)) == 0);
rok('entry past retention safety net expired', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$t` WHERE address=%s AND signature='exp_ret'", $D)) == 0);
rok('in-window entry retained through expiry', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$t` WHERE address=%s AND signature='keep_in'", $D)) == 1);

// ---------- 5. Queue-first, cursor-second: enqueue failure holds the cursor ----------
// sigA (success), sigB (fails inspection AND its enqueue fails because the table
// is gone), sigC. The persisted cursor must stop at sigA - never advance past
// sigB - so sigB is re-collected next tick (at-least-once).
$E = 'RINV5555555555555555555555555555555555555555';
delete_transient('nmm_sol_cursor_' . md5($E));
$GLOBALS['sol_addr'] = $E;
$GLOBALS['sol_sigs'] = array(
	array('signature' => 'sigA', 'blockTime' => $now - 5),
	array('signature' => 'sigB', 'blockTime' => $now - 6),
	array('signature' => 'sigC', 'blockTime' => $now - 7),
);
$GLOBALS['sol_tx'] = array('sigA' => 0, 'sigB' => 'fail', 'sigC' => 0);
$GLOBALS['sol_addr'] = $E;

// Tick 1: table dropped so sigB's enqueue fails; no payment was recovered, so
// the cycle is incomplete -> error result, and the cursor must stop at sigA.
$wpdb->suppress_errors(true);                             // the dropped-table errors below are expected
$wpdb->query("DROP TABLE IF EXISTS `$t`");
$GLOBALS['sol_gettx'] = array();
$rE1 = NMM_Blockchain::get_sol_address_transactions($E, $LIFE);
$heldCursor = get_transient('nmm_sol_cursor_' . md5($E));
rok('enqueue failure with no payment returns an error', $rE1['result'] === 'error');
rok('cursor holds at last safely-handled sig (before failed enqueue)', $heldCursor === 'sigA', var_export($heldCursor, true));

// Tick 2: table restored; resuming from sigA must re-encounter sigB and now
// durably store it - proving the failed signature is not lost.
NMM_create_sol_retry_table();
$wpdb->query("DELETE FROM `$t`");
$wpdb->suppress_errors(false);
$GLOBALS['sol_gettx'] = array();
NMM_Blockchain::get_sol_address_transactions($E, $LIFE); // cursor still points at sigA
rok('failed signature is re-encountered on the next tick', in_array('sigB', $GLOBALS['sol_gettx'], true));
rok('  and is now durably enqueued', NMM_Sol_Retry_Repo::count_for($E) >= 1);

// ---------- 5b. Enqueue failure AFTER a payment was recovered -> success ----------
// sigP (a real payment, resolved before the failure), then sigQ (fails inspection
// and its enqueue fails). The tick must return success WITH the payment, and the
// cursor must still hold behind sigQ.
$F = 'RINV6666666666666666666666666666666666666666';
delete_transient('nmm_sol_cursor_' . md5($F));
$GLOBALS['sol_addr'] = $F;
$GLOBALS['sol_sigs'] = array(
	array('signature' => 'sigP', 'blockTime' => $now - 5),
	array('signature' => 'sigQ', 'blockTime' => $now - 6),
);
$GLOBALS['sol_tx'] = array('sigP' => 6100000, 'sigQ' => 'fail');
$wpdb->suppress_errors(true);
$wpdb->query("DROP TABLE IF EXISTS `$t`");                 // make sigQ's enqueue fail
$rF = NMM_Blockchain::get_sol_address_transactions($F, $LIFE);
$heldF = get_transient('nmm_sol_cursor_' . md5($F));
NMM_create_sol_retry_table();
$wpdb->query("DELETE FROM `$t`");
$wpdb->suppress_errors(false);
$payFound = false; foreach ($rF['transactions'] as $tx) { if ($tx->get_hash() === 'sigP') { $payFound = (int) $tx->get_amount(); } }
rok('enqueue failure after a recovered payment returns success', $rF['result'] === 'success');
rok('  the recovered payment is included', $payFound === 6100000, $payFound ? "amt=$payFound" : '');
rok('  cursor still held behind the unstored signature', $heldF === 'sigP', var_export($heldF, true));

// ---------- 6. Schema repair: maybe_create adds missing indexes ----------
$wpdb->suppress_errors(true);
$wpdb->query("ALTER TABLE `$t` DROP INDEX addr_due");
delete_option('nmm_sol_retry_schema');
NMM_maybe_create_sol_retry_table();
rok('missing addr_due index is repaired', count($wpdb->get_results("SHOW INDEX FROM `$t` WHERE Key_name='addr_due'")) > 0);
rok('schema version recorded only after repair completes', get_option('nmm_sol_retry_schema') === '2');
rok('new expiry indexes present after repair', count($wpdb->get_results("SHOW INDEX FROM `$t` WHERE Key_name IN ('addr_block_time','addr_first_failed','first_failed')")) >= 3);

// Unique key repair must de-duplicate first (a table that ran without it could
// have accumulated duplicate (address, signature) rows).
$wpdb->query("ALTER TABLE `$t` DROP INDEX addr_sig");
$wpdb->query("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES ('RREP','dup',1,1,1,0),('RREP','dup',1,1,1,0)");
delete_option('nmm_sol_retry_schema');
NMM_maybe_create_sol_retry_table();
$dupCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE address='RREP' AND signature='dup'");
rok('unique key repaired after de-duplicating', count($wpdb->get_results("SHOW INDEX FROM `$t` WHERE Key_name='addr_sig'")) > 0 && $dupCount === 1, "dups=$dupCount");
$wpdb->suppress_errors(false);

// ---------- 7. Global cleanup reclaims rows for no-longer-scanned addresses ----------
$wpdb->query("DELETE FROM `$t`");
$stale = $now - (10 * 24 * 60 * 60); // 10 days ago (past a 7-day global retention)
$wpdb->query($wpdb->prepare("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES ('GONEADDR','oldsig',%d,3,%d,0)", $stale, $stale));
$wpdb->query($wpdb->prepare("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES ('LIVEADDR','freshsig',%d,1,%d,0)", $now - 60, $now));
$removed = NMM_Sol_Retry_Repo::delete_stale_globally($now - (7 * 24 * 60 * 60), 500);
rok('global cleanup removes stale row for unscanned address', $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE address='GONEADDR'") == 0, "removed=$removed");
rok('global cleanup keeps a recent row', $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE address='LIVEADDR'") == 1);

// ---------- 7b. run_global_cleanup: retention clamp protects live entries ----------
$wpdb->query("DELETE FROM `$t`");
// A row that first failed 1h ago - well inside a 3h payment window + margin.
$wpdb->query($wpdb->prepare("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES ('WINDOW','recent',%d,2,%d,0)", $now - 3600, $now));
// Even if a filter asks for 0s retention, the clamp keeps it >= lifetime+30m.
NMM_Sol_Retry_Repo::run_global_cleanup(0, 3 * 60 * 60 + 30 * 60);
rok('clamp keeps an in-window entry despite a 0s retention filter', $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE address='WINDOW'") == 1);

// ---------- 7c. run_global_cleanup drains a backlog in bounded batches ----------
$wpdb->query("DELETE FROM `$t`");
$vals = array();
for ($i = 0; $i < 1200; $i++) { $vals[] = "('DRAIN','d$i'," . ($stale) . ",1," . ($stale) . ",0)"; }
$wpdb->query("INSERT INTO `$t` (address,signature,first_failed_at,attempts,next_retry_at,block_time) VALUES " . implode(',', $vals));
$drained = NMM_Sol_Retry_Repo::run_global_cleanup(7 * 24 * 60 * 60, 0, 500, 40);
rok('drains a 1200-row backlog across bounded batches', (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE address='DRAIN'") === 0, "drained=$drained");

$wpdb->query("DELETE FROM `$t`");
echo $GLOBALS['sr_ok'] ? "\nSOL-RETRY (durable queue) CHECKS PASSED\n" : "\nSOL-RETRY CHECKS FAILED\n";
