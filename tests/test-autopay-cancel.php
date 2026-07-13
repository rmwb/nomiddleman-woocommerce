<?php
/**
 * Live-DB test: NMM_Payment::cancel_expired_payments() must never cancel an
 * order that has been paid or is no longer awaiting payment (the legacy Autopay
 * analogue of the HD cancellation race). Requires WordPress + WooCommerce + a
 * database. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-autopay-cancel.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !function_exists('wc_create_order')) {
	echo "test-autopay-cancel: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$pt = $wpdb->prefix . NMM_PAYMENT_TABLE;
$wpdb->query("DELETE FROM `$pt`");

// NOTE: wp eval-file runs this file in function scope, so a plain top-level
// `$pass` is NOT the same variable a `global $pass` inside aok() would write.
// Track pass/fail through $GLOBALS explicitly so the final banner is accurate
// (otherwise a failing check could still print PASSED and slip through CI).
$GLOBALS['ac_ok'] = true;
function aok($label, $cond, $extra = '') { printf("%-52s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['ac_ok'] = false; } }

$crypto = 'BTC';                 // default autopay cancellation window is 24h
$expired = time() - (25 * 3600); // past the window
$fresh   = time();               // within the window

function mkorder($status) { $o = wc_create_order(); $o->set_payment_method('nmmpro_gateway'); $o->set_status($status); $o->save(); return $o->get_id(); }
$ins = function($orderId, $orderedAt) use ($wpdb, $pt, $crypto) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address)
		 VALUES ('addr_$orderId',%s,'unpaid',%d,%d,'0.00100000',0)",
		$crypto, $orderedAt, $orderId));
};
function rec_status($wpdb, $pt, $orderId) { return $wpdb->get_var($wpdb->prepare("SELECT status FROM `$pt` WHERE order_id=%d", $orderId)); }
function ord_status($orderId) { $o = wc_get_order($orderId); return $o ? $o->get_status() : '(gone)'; }

$oProcessing = mkorder('processing'); $ins($oProcessing, $expired); // paid
$oCompleted  = mkorder('completed');  $ins($oCompleted,  $expired); // paid
$oCancelled  = mkorder('cancelled');  $ins($oCancelled,  $expired); // terminal non-paid
$ins(950001, $expired);                                             // deleted order (no WC_Order)
$oFresh      = mkorder('pending');     $ins($oFresh, $fresh);        // not expired yet
$oExpPending = mkorder('pending');     $ins($oExpPending, $expired); // expired, awaiting payment
$oExpOnHold  = mkorder('on-hold');     $ins($oExpOnHold, $expired);  // expired, awaiting payment

NMM_Payment::cancel_expired_payments();

// Paid orders: never cancelled; record reconciled to paid.
aok('paid (processing) order NOT cancelled',   ord_status($oProcessing) === 'processing');
aok('  its payment record reconciled to paid', rec_status($wpdb,$pt,$oProcessing) === 'paid');
aok('paid (completed) order NOT cancelled',    ord_status($oCompleted) === 'completed');
aok('  its payment record reconciled to paid', rec_status($wpdb,$pt,$oCompleted) === 'paid');

// Terminal non-paid: left alone, record reconciled to cancelled.
aok('already-cancelled order left cancelled',  ord_status($oCancelled) === 'cancelled');
aok('  its record reconciled to cancelled',    rec_status($wpdb,$pt,$oCancelled) === 'cancelled');

// Deleted order: record retired without a fatal.
aok('deleted order record retired (cancelled)', rec_status($wpdb,$pt,950001) === 'cancelled');

// Fresh pending: untouched.
aok('fresh (unexpired) order untouched',       ord_status($oFresh) === 'pending');
aok('  its record still unpaid',               rec_status($wpdb,$pt,$oFresh) === 'unpaid');

// Expired + awaiting payment: cancelled.
aok('expired pending order cancelled',         ord_status($oExpPending) === 'cancelled');
aok('  its record cancelled',                  rec_status($wpdb,$pt,$oExpPending) === 'cancelled');
aok('expired on-hold order cancelled',         ord_status($oExpOnHold) === 'cancelled');
aok('  its record cancelled',                  rec_status($wpdb,$pt,$oExpOnHold) === 'cancelled');

// --- Concurrency: order completes AFTER the claim, right before cancellation ---
// The nmm_before_autopay_cancel hook fires immediately after the row is claimed
// and before the final re-fetch. Completing the order there simulates a merchant
// or verifier winning the race in that window; the re-fetch must catch it and
// reconcile to paid instead of cancelling a now-paid order.
$wpdb->query("DELETE FROM `$pt`");
$oRace = mkorder('pending'); $ins($oRace, $expired);
$raceFired = 0;
$raceHook = function($orderId, $cryptoId, $address) use ($oRace, &$raceFired) {
	if ($orderId != $oRace) { return; }
	$raceFired++;
	$o = wc_get_order($orderId);   // complete the order mid-transition
	$o->payment_complete();
	$o->save();
};
add_action('nmm_before_autopay_cancel', $raceHook, 10, 3);
NMM_Payment::cancel_expired_payments();
remove_action('nmm_before_autopay_cancel', $raceHook, 10);

aok('race hook fired for claimed order',        $raceFired === 1, 'fired=' . $raceFired);
aok('order paid mid-transition NOT cancelled',  ord_status($oRace) !== 'cancelled', 'status=' . ord_status($oRace));
aok('  its record reconciled to paid',          rec_status($wpdb,$pt,$oRace) === 'paid');

// --- Conditional claim: a row already moved out of 'unpaid' is not re-cancelled ---
// Simulates the verifier having flipped the row to 'paid' before the cron runs;
// claim_for_cancellation must report zero rows and the order must be left alone.
$wpdb->query("DELETE FROM `$pt`");
$oClaimed = mkorder('pending'); $ins($oClaimed, $expired);
$wpdb->query($wpdb->prepare("UPDATE `$pt` SET status='paid' WHERE order_id=%d", $oClaimed));
$repo = new NMM_Payment_Repo();
aok('claim on already-paid row returns false',  $repo->claim_for_cancellation($oClaimed, '0.00100000') === false);
NMM_Payment::cancel_expired_payments(); // no unpaid rows -> order stays pending
aok('order left pending (claim lost)',          ord_status($oClaimed) === 'pending');
aok('  record still paid, not cancelled',       rec_status($wpdb,$pt,$oClaimed) === 'paid');

// --- Mutual exclusion: both sides of the race use the same conditional claim ---
// The verifier's claim_for_payment and the cron's claim_for_cancellation both
// transition only WHERE status='unpaid'. Exactly one can win a given row.
$wpdb->query("DELETE FROM `$pt`");
$oRow = mkorder('pending'); $ins($oRow, $expired);
$rp = new NMM_Payment_Repo();
aok('verifier claim_for_payment wins unpaid row', $rp->claim_for_payment($oRow, '0.00100000') === true);
aok('  row is now paid',                          rec_status($wpdb,$pt,$oRow) === 'paid');
aok('cron claim then loses (row not unpaid)',     $rp->claim_for_cancellation($oRow, '0.00100000') === false);

// Opposite order: cron wins first, verifier must lose and would abort completion.
$wpdb->query("DELETE FROM `$pt`");
$oRow2 = mkorder('pending'); $ins($oRow2, $expired);
aok('cron claim_for_cancellation wins unpaid row', $rp->claim_for_cancellation($oRow2, '0.00100000') === true);
aok('  row is now cancelled',                      rec_status($wpdb,$pt,$oRow2) === 'cancelled');
aok('verifier then loses (row not unpaid)',        $rp->claim_for_payment($oRow2, '0.00100000') === false);

// --- Index: the expiry query must range-scan unpaid_expiry, not read every row ---
// Seed a realistic distribution - many recent unpaid checkouts, a few old ones -
// and assert the exact production query (WHERE status='unpaid' AND ordered_at <
// cutoff) uses the unpaid_expiry(status, ordered_at) index rather than scanning
// all unpaid rows. This is what the coarse cutoff in cancel_expired_payments()
// buys: O(expired) transferred to PHP, not O(all unpaid).
$wpdb->query("DELETE FROM `$pt`");
$now = time();
$fresh24 = $now - (1 * 3600);    // within the 24h BTC window
$old48   = $now - (48 * 3600);   // past it
$rows = array();
for ($i = 0; $i < 400; $i++) { $rows[] = $wpdb->prepare("('addr_f_%d','BTC','unpaid',%d,%d,'0.00100000',0)", $i, $fresh24, 800000 + $i); }
for ($i = 0; $i < 12;  $i++) { $rows[] = $wpdb->prepare("('addr_o_%d','BTC','unpaid',%d,%d,'0.00100000',0)", $i, $old48,  900000 + $i); }
$wpdb->query("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES " . implode(',', $rows));
$wpdb->query("ANALYZE TABLE `$pt`");

$cutoff = $now - (24 * 3600);
$explainQuery = "SELECT `address`,`cryptocurrency`,`order_id`,`order_amount`,`status`,`ordered_at` FROM `$pt` WHERE `status` = 'unpaid' AND `ordered_at` < " . (int) $cutoff;
$plan = $wpdb->get_results("EXPLAIN $explainQuery", ARRAY_A);
$planKey  = isset($plan[0]['key'])  ? $plan[0]['key']  : '(none)';
$planType = isset($plan[0]['type']) ? $plan[0]['type'] : '(none)';
$planRows = isset($plan[0]['rows']) ? (int) $plan[0]['rows'] : -1;

aok('expiry query uses unpaid_expiry index',   $planKey === 'unpaid_expiry', 'key=' . $planKey);
aok('  as a range scan',                       $planType === 'range', 'type=' . $planType);
aok('  examines far fewer than 412 rows',      $planRows > 0 && $planRows <= 100, 'rows=' . $planRows);

// And the cutoff query itself returns only the old rows (correctness of the filter).
$repo2 = new NMM_Payment_Repo();
aok('cutoff returns only the 12 expired rows', count($repo2->get_unpaid($cutoff)) === 12, 'got=' . count($repo2->get_unpaid($cutoff)));
aok('no-cutoff still returns all 412 rows',    count($repo2->get_unpaid()) === 412, 'got=' . count($repo2->get_unpaid()));

// --- End-to-end: cancellation wins the race, reused address must not re-match ---
// Drive the real matcher with an injected in-window transaction. A hook fires in
// the exact pre-claim window and simulates the expiry cron winning (unpaid ->
// cancelled), so the verifier's claim loses. The verified tx MUST still be
// consumed, or the same still-in-window tx could later complete a *new* order on
// the reused static/carousel address (payment misattribution).
$wpdb->query("DELETE FROM `$pt`");
$cryptos = NMM_Cryptocurrencies::get();
$btc = $cryptos['BTC'];
$reuseAddr = 'addr_reuse_e2e';
$reuseAmt  = '0.00100000';
$txHash    = 'TXREUSEe2e';
$lifetime  = 3600;
$txUnits   = $reuseAmt * (10 ** $btc->get_round_precision()); // smallest-unit amount
$tx = new NMM_Transaction($txUnits, 999 /*confirmations*/, time() /*in window*/, $txHash);
$rpe = new NMM_Payment_Repo();
$stg = new NMM_Settings(get_option(NMM_REDUX_ID));
// Consumed-tx state lives in a per-address option that outlives the table wipe;
// clear it so this test is deterministic across repeated runs.
delete_option('nmmpro_BTC_transactions_consumed_for_' . $reuseAddr);

// Order A on the reused address, unpaid.
$oA = mkorder('pending');
$wpdb->query($wpdb->prepare(
	"INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'BTC','unpaid',%d,%d,%s,0)",
	$reuseAddr, time(), $oA, $reuseAmt));

$loseHook = function($orderId, $cryptoId, $address, $hash) use ($oA, $reuseAmt, $rpe) {
	if ($orderId == $oA) { $rpe->claim_for_cancellation($oA, $reuseAmt); } // cron wins the row
};
add_action('nmm_before_autopay_complete', $loseHook, 10, 4);
NMM_Payment::process_address_transactions($btc, $reuseAddr, array($tx), $lifetime);
remove_action('nmm_before_autopay_complete', $loseHook, 10);

aok('lost race: order A record cancelled',      rec_status($wpdb,$pt,$oA) === 'cancelled');
aok('lost race: order A not completed',         !wc_get_order($oA)->has_status(array('processing','completed')), 'status=' . ord_status($oA));
aok('lost race: tx recorded as consumed',       $stg->tx_already_consumed('BTC', $reuseAddr, $txHash) === true);
aok('lost race: hash persisted on cancelled row', $wpdb->get_var($wpdb->prepare("SELECT tx_hash FROM `$pt` WHERE order_id=%d", $oA)) === $txHash);

// Reuse the address for a NEW order B, same amount; the same tx must be skipped.
$oB = mkorder('pending');
$wpdb->query($wpdb->prepare(
	"INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'BTC','unpaid',%d,%d,%s,0)",
	$reuseAddr, time(), $oB, $reuseAmt));
NMM_Payment::process_address_transactions($btc, $reuseAddr, array($tx), $lifetime);

aok('reused address: new order B NOT paid',     rec_status($wpdb,$pt,$oB) === 'unpaid');
aok('reused address: order B still pending',    ord_status($oB) === 'pending');

// Sanity: a fresh, unconsumed tx on the reused address DOES complete a new order.
// Clear the address's rows first so only order C is unpaid on it (order B lingers
// unpaid by design, which would otherwise make C an ambiguous collision).
$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE address=%s", $reuseAddr));
$oC = mkorder('pending');
$wpdb->query($wpdb->prepare(
	"INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'BTC','unpaid',%d,%d,%s,0)",
	$reuseAddr, time(), $oC, $reuseAmt));
$tx2 = new NMM_Transaction($txUnits, 999, time(), 'TXFRESHe2e');
NMM_Payment::process_address_transactions($btc, $reuseAddr, array($tx2), $lifetime);
aok('control: fresh tx DOES pay a new order',   rec_status($wpdb,$pt,$oC) === 'paid');

$wpdb->query("DELETE FROM `$pt`");
echo $GLOBALS['ac_ok'] ? "\nAUTOPAY-CANCEL CHECKS PASSED\n" : "\nAUTOPAY-CANCEL CHECKS FAILED\n";
