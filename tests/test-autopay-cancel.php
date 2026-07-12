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

$wpdb->query("DELETE FROM `$pt`");
echo $pass ? "\nAUTOPAY-CANCEL CHECKS PASSED\n" : "\nAUTOPAY-CANCEL CHECKS FAILED\n";
