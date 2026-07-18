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

// The expiry pass now re-checks the chain before cancelling a zero-balance
// expired row, so it makes an explorer call too - mock it.
function hd_cancel_expired($mock, $cancelSec) {
	add_filter('pre_http_request', $mock, 10, 3);
	NMM_Hd::cancel_expired_addresses('BTC', $GLOBALS['hd_mpk'], $cancelSec, $GLOBALS['hd_mode'], 1);
	remove_filter('pre_http_request', $mock, 10);
}

function hd_set_last_checked($address, $ts) {
	global $wpdb;
	$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hd_table']}` SET `last_checked` = %d WHERE `address` = %s", $ts, $address));
}

function hd_backdate_assigned($address, $secondsAgo) {
	global $wpdb;
	$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hd_table']}` SET `assigned_at` = %d WHERE `address` = %s", time() - $secondsAgo, $address));
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

// --- the reconcile pass must be able to see every held address ---
// Now that a late payment no longer (wrongly) completes a dead order, an
// 'underpaid' row on a cancelled order has to be reconciled somewhere, or the
// verifier polls that address on every sweep forever and never retires it. The
// pass looked at 'assigned' rows only.
$underCancelled = hd_mkorder('cancelled');
hd_row('hd_addr_under_cancelled', $underCancelled, 'underpaid', '0.10000000');
$assignedCancelled = hd_mkorder('cancelled');
hd_row('hd_addr_assigned_cancelled', $assignedCancelled, 'assigned', '0.00000000');
// A refunded order is a non-payable terminal state the verifier's gate also
// rejects; its row must be retired, not left assigned and swept forever.
$refundedHeld = hd_mkorder('refunded');
hd_row('hd_addr_refunded_held', $refundedHeld, 'assigned', '1.00000000');
$liveOnHold = hd_mkorder('on-hold');
hd_row('hd_addr_live_onhold', $liveOnHold, 'underpaid', '0.10000000');

NMM_Hd::cancel_expired_addresses('BTC', $GLOBALS['hd_mpk'], 3600, $GLOBALS['hd_mode']);

hok('an underpaid row on a cancelled order is retired',   hd_status('hd_addr_under_cancelled') === 'dirty', 'row=' . hd_status('hd_addr_under_cancelled'));
hok('an assigned row on a cancelled order is quarantined', hd_status('hd_addr_assigned_cancelled') === 'quarantine', 'row=' . hd_status('hd_addr_assigned_cancelled'));
hok('a row on a REFUNDED order is retired (not swept forever)', hd_status('hd_addr_refunded_held') === 'dirty', 'row=' . hd_status('hd_addr_refunded_held'));
hok('an underpaid row on a LIVE order is left alone',     hd_status('hd_addr_live_onhold') === 'underpaid', 'row=' . hd_status('hd_addr_live_onhold'));
hok('  and its live order is not cancelled',              hd_order_status($liveOnHold) === 'on-hold', 'status=' . hd_order_status($liveOnHold));

// --- a failed completion must not strand the payment OR cancel the order ---
// A hook on woocommerce_pre_payment_complete throws BEFORE WooCommerce sets the
// order paid, so payment_complete() returns false with the order genuinely still
// on-hold. This is the hard case: the row is mid-completion, the money is real,
// and the expiry pass runs later in the SAME cron cycle. The claim must be
// released to a swept state, the observed total must NOT be rolled back (that is
// what stops the expiry pass cancelling a just-paid order), and a later sweep
// must still complete it.
$boomOrder = hd_mkorder('on-hold');
hd_row('hd_addr_boom', $boomOrder);
// Backdate the assignment so the expiry pass below considers it expired.
$wpdb->query($wpdb->prepare("UPDATE `$ht` SET `assigned_at` = %d WHERE `address` = %s", time() - 48 * 3600, 'hd_addr_boom'));
$boom = function ($orderId) { throw new \RuntimeException('a third-party hook exploded'); };
add_action('woocommerce_pre_payment_complete', $boom, 10, 1);
hd_sweep($paidMock);
remove_action('woocommerce_pre_payment_complete', $boom, 10);
hok('a failed completion releases the claim to a swept state', hd_status('hd_addr_boom') === 'assigned', 'row=' . hd_status('hd_addr_boom'));
hok('  the observed total is NOT rolled back',             hd_total('hd_addr_boom') === 1.0, 'total=' . hd_total('hd_addr_boom'));
hok('  and the order is still awaiting payment',           hd_order_status($boomOrder) === 'on-hold', 'status=' . hd_order_status($boomOrder));

// The critical same-cron interaction (this was a real regression): the expiry
// pass runs right after the verifier. With the total left cached it must NOT
// cancel this order, even though its assignment is long expired.
NMM_Hd::cancel_expired_addresses('BTC', $GLOBALS['hd_mpk'], 3600, $GLOBALS['hd_mode']);
hok('the expiry pass does NOT cancel the just-verified order', hd_order_status($boomOrder) === 'on-hold', 'status=' . hd_order_status($boomOrder));
hok('  and does not retire its address',                   hd_status('hd_addr_boom') === 'assigned', 'row=' . hd_status('hd_addr_boom'));

// With the hook gone, the next verifier sweep completes it - exactly once.
hd_sweep($paidMock);
hok('  the next sweep completes the recovered order',      in_array(hd_order_status($boomOrder), array('processing', 'completed'), true), 'status=' . hd_order_status($boomOrder));
hok('  and settles the row',                               hd_status('hd_addr_boom') === 'complete', 'row=' . hd_status('hd_addr_boom'));

// A crash mid-completion leaves a 'completing' row. get_pending() returns it, so
// the next sweep resumes and finishes it rather than stranding the order.
$stuckOrder = hd_mkorder('on-hold');
hd_row('hd_addr_stuck', $stuckOrder, 'completing', '1.00000000');
hd_sweep($paidMock);
hok('an interrupted (completing) row is resumed and settled', hd_status('hd_addr_stuck') === 'complete', 'row=' . hd_status('hd_addr_stuck'));
hok('  and its order is completed',                        in_array(hd_order_status($stuckOrder), array('processing', 'completed'), true), 'status=' . hd_order_status($stuckOrder));

// A 'completing' row whose order died mid-completion must be retired by the
// reconcile pass, not polled forever.
$stuckDead = hd_mkorder('cancelled');
hd_row('hd_addr_stuck_dead', $stuckDead, 'completing', '1.00000000');
NMM_Hd::cancel_expired_addresses('BTC', $GLOBALS['hd_mpk'], 3600, $GLOBALS['hd_mode']);
hok('a completing row on a dead order is retired',         hd_status('hd_addr_stuck_dead') === 'dirty', 'row=' . hd_status('hd_addr_stuck_dead'));

// --- the expiry pass re-checks the chain before cancelling ---
// An expired, zero-balance row is only cancelled after a final on-chain check
// confirms no funds. A late payment that landed after the last verifier check -
// or a payment the verifier could not record - must abort the cancellation.
// Reset the per-run observation cache so these first tests exercise the
// fetch-it-ourselves path (no verifier observation available).
NMM_Hd::reset_observed_totals();
$expiredZero = hd_mkorder('on-hold');
hd_row('hd_addr_expired_zero', $expiredZero, 'assigned', '0.00000000');
hd_backdate_assigned('hd_addr_expired_zero', 48 * 3600);
$zeroMock = function ($pre, $args, $url) {
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => '0', 'headers' => array(), 'cookies' => array());
};
hd_cancel_expired($zeroMock, 3600);
hok('an expired, genuinely-empty order IS cancelled',     hd_order_status($expiredZero) === 'cancelled', 'status=' . hd_order_status($expiredZero));

$expiredButPaid = hd_mkorder('on-hold');
hd_row('hd_addr_expired_paid', $expiredButPaid, 'assigned', '0.00000000');
hd_backdate_assigned('hd_addr_expired_paid', 48 * 3600);
// The chain now shows a full balance the verifier never recorded (its cache
// write failed, or the payment landed a moment ago). The expiry pass must NOT
// cancel it.
hd_cancel_expired($paidMock, 3600);
hok('an expired order with a fresh on-chain balance is NOT cancelled', hd_order_status($expiredButPaid) === 'on-hold', 'status=' . hd_order_status($expiredButPaid));
hok('  and the discovered funds are cached for the verifier', hd_total('hd_addr_expired_paid') === 1.0, 'total=' . hd_total('hd_addr_expired_paid'));

// If the final check cannot be made (explorer down), do NOT cancel on an
// unverified assumption - try again next cycle.
$expiredExplorerDown = hd_mkorder('on-hold');
hd_row('hd_addr_expired_down', $expiredExplorerDown, 'assigned', '0.00000000');
hd_backdate_assigned('hd_addr_expired_down', 48 * 3600);
$downMock = function ($pre, $args, $url) {
	return array('response' => array('code' => 500, 'message' => 'Server Error'), 'body' => '', 'headers' => array(), 'cookies' => array());
};
hd_cancel_expired($downMock, 3600);
hok('an expired order is NOT cancelled when the re-check fails', hd_order_status($expiredExplorerDown) === 'on-hold', 'status=' . hd_order_status($expiredExplorerDown));

// The 500s above trip NMM_Blockchain's per-host backoff (nmm_backoff_/nmm_apifail_/
// nmm_cooldown_ transients). Those persist in the shared test DB and would
// short-circuit later suites' real explorer calls (mempool/blockcypher), so a
// full front-to-back run would see autopay-cancel/scan misfire. Clear them here
// so this suite leaves no backoff pollution behind.
$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmm_backoff%' OR `option_name` LIKE '%nmm_apifail%' OR `option_name` LIKE '%nmm_cooldown%'");

// --- the expiry pass reuses the verifier's same-run observation ---
// In a real cron cycle the verifier runs immediately before the expiry pass and
// has already fetched every reconcilable address's balance. The expiry pass
// must use THAT observation rather than fetch again: a second fetch doubles the
// explorer load under a backlog and livelocks on per-host-cooldown explorers
// (chainz/BTX - the verifier's own call starts the cooldown, so the re-fetch is
// refused every cycle and an abandoned order is never cancelled). Prove it by
// having the verifier observe ZERO and then answering any (wrong) re-fetch at
// expiry time with a full balance: cancellation must happen anyway, because the
// cached zero observation - not the fetch - is what the pass consults.
NMM_Hd::reset_observed_totals();
$sameRun = hd_mkorder('on-hold');
hd_row('hd_addr_same_run', $sameRun, 'assigned', '0.00000000');
hd_backdate_assigned('hd_addr_same_run', 48 * 3600);
hd_sweep($zeroMock); // verifier observes 0 for this address in this "cron run"
hd_cancel_expired($paidMock, 3600); // a re-fetch would see 1.0 and refuse to cancel
hok('expiry uses the verifier\'s same-run observation',   hd_order_status($sameRun) === 'cancelled', 'status=' . hd_order_status($sameRun));
NMM_Hd::reset_observed_totals();

// --- the completion lease (crash recovery vs. a live concurrent worker) ---
$repoLease = new NMM_Hd_Repo('BTC', $GLOBALS['hd_mpk'], $GLOBALS['hd_mode']);

// A 'completing' row a live worker is holding right now (fresh lease) must NOT
// be stolen by another run - that would double-fire completion.
hd_row('hd_addr_lease_fresh', hd_mkorder('on-hold'), 'completing', '1.00000000');
hd_set_last_checked('hd_addr_lease_fresh', time());
hok('a fresh completing claim is not stealable',          $repoLease->claim_for_complete('hd_addr_lease_fresh') === NMM_Hd_Repo::CLAIM_ALREADY);

// A 'completing' row abandoned by a crashed run (lease long expired) IS taken
// over, so the order is recovered rather than stranded.
hd_row('hd_addr_lease_stale', hd_mkorder('on-hold'), 'completing', '1.00000000');
hd_set_last_checked('hd_addr_lease_stale', time() - (NMM_Hd_Repo::COMPLETING_LEASE_SEC + 60));
hok('an abandoned completing claim is taken over',        $repoLease->claim_for_complete('hd_addr_lease_stale') === NMM_Hd_Repo::CLAIM_CLAIMED);

// set_total_received reports success so the verifier can trust the funds landed.
hd_row('hd_addr_settot', hd_mkorder('on-hold'));
hok('set_total_received reports success',                 $repoLease->set_total_received('hd_addr_settot', '0.50000000') === true);
hok('  and the value is stored',                          hd_total('hd_addr_settot') === 0.5, 'total=' . hd_total('hd_addr_settot'));

// --- the claim itself ---
$repo = new NMM_Hd_Repo('BTC', $GLOBALS['hd_mpk'], $GLOBALS['hd_mode']);

hd_row('hd_addr_claim', hd_mkorder('on-hold'));
$first  = $repo->claim_for_complete('hd_addr_claim');
$second = $repo->claim_for_complete('hd_addr_claim');
hok('the first worker claims the row',                     $first === NMM_Hd_Repo::CLAIM_CLAIMED, 'got=' . $first);
hok('a second worker is refused - no double completion',   $second === NMM_Hd_Repo::CLAIM_ALREADY, 'got=' . $second);
hok('  the claim moves the row to the intermediate state', hd_status('hd_addr_claim') === 'completing', 'row=' . hd_status('hd_addr_claim'));
hok('  release_claim hands it back to assigned',           (function () use ($repo) { $repo->release_claim('hd_addr_claim'); return hd_status('hd_addr_claim'); })() === 'assigned');

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
