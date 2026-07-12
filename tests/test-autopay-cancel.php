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

$pass = true;
function aok($label, $cond, $extra = '') { global $pass; printf("%-52s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $pass = false; } }

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

$wpdb->query("DELETE FROM `$pt`");
echo $pass ? "\nAUTOPAY-CANCEL CHECKS PASSED\n" : "\nAUTOPAY-CANCEL CHECKS FAILED\n";
