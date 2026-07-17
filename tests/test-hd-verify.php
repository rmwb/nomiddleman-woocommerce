<?php
/**
 * Live-DB test: the Privacy Mode (HD) verifier only ever completes an order
 * that is genuinely still awaiting payment, and claims the address row
 * atomically so two overlapping background runs cannot both complete it.
 *
 * The verifier used to load the order and call payment_complete() with no
 * regard for its live status: a late payment to an order the customer or the
 * merchant had already cancelled would quietly resurrect it, because the
 * verifier runs BEFORE the pass that reconciles cancelled addresses. Autopay
 * has guarded against this for some time (NMM_Payment::process_address_
 * transactions); this is the same contract for HD.
 *
 * BTC's explorer is answered offline via pre_http_request, the repo's
 * established technique. Requires WordPress + WooCommerce + a database.
 * Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-hd-verify.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !defined('NMM_HD_TABLE') || !function_exists('wc_create_order')) {
	echo "test-hd-verify: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

// wp eval-file runs this file inside a function scope, so file-scope variables
// are not globals - the helpers below reach for these through $GLOBALS.
$GLOBALS['hd_ok']    = true;
$GLOBALS['hd_mpk']   = 'test_mpk_hd_verify';
$GLOBALS['hd_mode']  = 0;
$GLOBALS['hd_table'] = $GLOBALS['wpdb']->prefix . NMM_HD_TABLE;

$wpdb = $GLOBALS['wpdb'];
$ht = $GLOBALS['hd_table'];

function hok($label, $cond, $extra = '') {
	printf("%-62s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['hd_ok'] = false; }
}

function hd_mkorder($status) {
	$order = wc_create_order();
	$order->set_total('100.00');
	$order->update_status($status);
	$order->save();
	return $order->get_id();
}

// One HD address row for $orderId, owing 1.00000000 BTC.
function hd_row($address, $orderId, $status = 'assigned', $totalReceived = '0.00000000') {
	global $wpdb;
	$table = $GLOBALS['hd_table'];
	$wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE `address` = %s", $address));
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$table` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`total_received`,`assigned_at`)
		 VALUES (%s,'BTC',%s,0,%s,%d,%d,'1.00000000',%s,%d)",
		$address, $GLOBALS['hd_mpk'], $status, $GLOBALS['hd_mode'], $orderId, $totalReceived, time()
	));
}

function hd_status($address) {
	global $wpdb;
	$table = $GLOBALS['hd_table'];
	return $wpdb->get_var($wpdb->prepare("SELECT `status` FROM `$table` WHERE `address` = %s", $address));
}

function hd_total($address) {
	global $wpdb;
	$table = $GLOBALS['hd_table'];
	return (float) $wpdb->get_var($wpdb->prepare("SELECT `total_received` FROM `$table` WHERE `address` = %s", $address));
}

function hd_order_status($orderId) {
	$order = wc_get_order($orderId);
	return $order ? $order->get_status() : '(gone)';
}

function hd_notes($orderId) {
	$text = '';
	foreach (wc_get_order_notes(array('order_id' => $orderId, 'limit' => 50)) as $note) {
		$text .= ' ' . $note->content;
	}
	return $text;
}

function hd_sweep($mock) {
	add_filter('pre_http_request', $mock, 10, 3);
	NMM_Hd::check_all_pending_addresses_for_payment('BTC', $GLOBALS['hd_mpk'], 1, 0.99, $GLOBALS['hd_mode']);
	remove_filter('pre_http_request', $mock, 10);
}

// blockchain.info's endpoint answers in satoshis: 1.0 BTC received against a
// 1.00000000 order clears the 0.99 processing percentage used above.
$paidMock = function ($pre, $args, $url) {
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => '100000000', 'headers' => array(), 'cookies' => array());
};
// 0.1 BTC - a real payment, but short of the 0.99 required.
$underMock = function ($pre, $args, $url) {
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => '10000000', 'headers' => array(), 'cookies' => array());
};

$wpdb->query($wpdb->prepare("DELETE FROM `$ht` WHERE `mpk` IN (%s, %s)", $GLOBALS['hd_mpk'], 'a_different_mpk'));

// --- the happy path still works ---
$onHold = hd_mkorder('on-hold');
hd_row('hd_addr_onhold', $onHold);
hd_sweep($paidMock);
hok('an on-hold order with a confirmed payment completes', in_array(hd_order_status($onHold), array('processing', 'completed'), true), 'status=' . hd_order_status($onHold));
hok('  and its address row is marked complete',            hd_status('hd_addr_onhold') === 'complete', 'row=' . hd_status('hd_addr_onhold'));
hok('  and the payment is noted on the order',             strpos(hd_notes($onHold), 'verified at') !== false);

$pending = hd_mkorder('pending');
hd_row('hd_addr_pending', $pending);
hd_sweep($paidMock);
hok('a pending order with a confirmed payment completes',  in_array(hd_order_status($pending), array('processing', 'completed'), true), 'status=' . hd_order_status($pending));

// --- THE regression: a late payment must not resurrect a dead order ---
$cancelled = hd_mkorder('cancelled');
hd_row('hd_addr_cancelled', $cancelled);
hd_sweep($paidMock);
hok('a cancelled order is NOT completed by a late payment', hd_order_status($cancelled) === 'cancelled', 'status=' . hd_order_status($cancelled));
hok('  its row is left for the reconcile pass to retire',   hd_status('hd_addr_cancelled') === 'assigned', 'row=' . hd_status('hd_addr_cancelled'));
hok('  the late payment is recorded for reconciliation',    strpos(hd_notes($cancelled), 'Late payment') !== false);
hok('  the observed total is still cached on the row',      hd_total('hd_addr_cancelled') === 1.0, 'total=' . hd_total('hd_addr_cancelled'));

$failed = hd_mkorder('failed');
hd_row('hd_addr_failed', $failed);
hd_sweep($paidMock);
hok('a failed order is NOT completed by a late payment',   hd_order_status($failed) === 'failed', 'status=' . hd_order_status($failed));

$refunded = hd_mkorder('refunded');
hd_row('hd_addr_refunded', $refunded);
hd_sweep($paidMock);
hok('a refunded order is NOT completed by a late payment', hd_order_status($refunded) === 'refunded', 'status=' . hd_order_status($refunded));

// A repeat sweep must not keep re-noting a payment it has already seen: the
// cached total_received means there is no NEW payment to report.
$notesBefore = strlen(hd_notes($cancelled));
hd_sweep($paidMock);
hok('a repeat sweep does not re-note the same late payment', strlen(hd_notes($cancelled)) === $notesBefore);
hok('  and still does not complete the order',              hd_order_status($cancelled) === 'cancelled');

// --- an order that vanished must not abort the sweep for everyone behind it ---
$ghost = hd_mkorder('on-hold');
wc_get_order($ghost)->delete(true);
hd_row('hd_addr_ghost', $ghost);
$behind = hd_mkorder('on-hold');
hd_row('hd_addr_behind_ghost', $behind);
$ghostThrew = false;
try { hd_sweep($paidMock); } catch (\Throwable $t) { $ghostThrew = true; }
hok('a deleted order does not throw out of the sweep',     !$ghostThrew);
hok('  and the address behind it is still verified',       in_array(hd_order_status($behind), array('processing', 'completed'), true), 'status=' . hd_order_status($behind));

// The same on the underpayment branch, where new WC_Order() would have thrown.
$underGhost = hd_mkorder('on-hold');
wc_get_order($underGhost)->delete(true);
hd_row('hd_addr_under_ghost', $underGhost);
$underThrew = false;
try { hd_sweep($underMock); } catch (\Throwable $t) { $underThrew = true; }
hok('a deleted order on the underpayment branch is safe',  !$underThrew);

// --- the claim itself ---
$repo = new NMM_Hd_Repo('BTC', $GLOBALS['hd_mpk'], $GLOBALS['hd_mode']);

hd_row('hd_addr_claim', hd_mkorder('on-hold'));
$first  = $repo->claim_for_complete('hd_addr_claim');
$second = $repo->claim_for_complete('hd_addr_claim');
hok('the first worker claims the row',                     $first === NMM_Hd_Repo::CLAIM_CLAIMED, 'got=' . $first);
hok('a second worker is refused - no double completion',   $second === NMM_Hd_Repo::CLAIM_ALREADY, 'got=' . $second);
hok('  the claim marks the row complete',                  hd_status('hd_addr_claim') === 'complete');

// An underpaid row is still payable once topped up; a retired one is not.
hd_row('hd_addr_underpaid_claim', hd_mkorder('on-hold'), 'underpaid', '0.10000000');
hok('an underpaid row is claimable once topped up',        $repo->claim_for_complete('hd_addr_underpaid_claim') === NMM_Hd_Repo::CLAIM_CLAIMED);

hd_row('hd_addr_dirty_claim', hd_mkorder('on-hold'), 'dirty', '1.00000000');
hok('a retired row cannot be claimed',                     $repo->claim_for_complete('hd_addr_dirty_claim') === NMM_Hd_Repo::CLAIM_ALREADY);

hd_row('hd_addr_quarantined_claim', hd_mkorder('on-hold'), 'quarantined');
hok('a quarantined row cannot be claimed',                 $repo->claim_for_complete('hd_addr_quarantined_claim') === NMM_Hd_Repo::CLAIM_ALREADY);

// HD rows are scoped by wallet: another mpk's repo must not touch this one.
hd_row('hd_addr_other_wallet', hd_mkorder('on-hold'));
$otherRepo = new NMM_Hd_Repo('BTC', 'a_different_mpk', $GLOBALS['hd_mode']);
hok('another wallet\'s row is not claimable',              $otherRepo->claim_for_complete('hd_addr_other_wallet') === NMM_Hd_Repo::CLAIM_ALREADY);
hok('  and it is left untouched',                          hd_status('hd_addr_other_wallet') === 'assigned');

$wpdb->query($wpdb->prepare("DELETE FROM `$ht` WHERE `mpk` IN (%s, %s)", $GLOBALS['hd_mpk'], 'a_different_mpk'));

echo $GLOBALS['hd_ok'] ? "\nHD-VERIFY CHECKS PASSED\n" : "\nHD-VERIFY CHECKS FAILED\n";
