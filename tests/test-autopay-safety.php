<?php
/**
 * Live-DB test: the Autopay safety properties ported from the 3.9 work onto the
 * 2.12.0 claim design. Each section names the defect it guards against and was
 * checked to FAIL with its fix reverted.
 *
 *   1. A claim that hits a database error must stop its address being
 *      certified, or expiry can cancel the very order being paid.
 *   2. A claim refuses a transaction already recorded as consumed.
 *   3. An order-status change cannot overwrite a 'completing' row, so a
 *      verified payment on an order someone cancels is flagged for review.
 *   4. Cancellation is a lease: the payment record is not terminal until
 *      WooCommerce has really cancelled the order, and a left-over lease is
 *      settled from the order.
 *   5. A cron pass that is not exclusive certifies and cancels nothing.
 *
 * Requires WordPress + WooCommerce + a database. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-autopay-safety.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !function_exists('wc_create_order')) {
	echo "test-autopay-safety: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

// wp eval-file runs this in function scope: track results through $GLOBALS so
// helper functions and the final banner see the same state.
$GLOBALS['as_ok'] = true;
$GLOBALS['as_count'] = 0;
function asok($label, $cond, $extra = '') {
	$GLOBALS['as_count']++;
	printf("%-66s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['as_ok'] = false; }
}
function as_row($orderId) {
	global $wpdb;
	return $wpdb->get_var($wpdb->prepare('SELECT status FROM `' . $wpdb->prefix . NMMPRO_PAYMENT_TABLE . '` WHERE order_id=%d', $orderId));
}
function as_order_status($orderId) {
	$order = wc_get_order($orderId);
	return $order ? $order->get_status() : '(gone)';
}

$wpdb = $GLOBALS['wpdb'];
$pt = $wpdb->prefix . NMMPRO_PAYMENT_TABLE;
$prefix = 'safety_' . wp_generate_password(10, false);
$coin = NMMPRO_Cryptocurrencies::get()['ETH'];
$repo = new NMMPRO_Payment_Repo();
$stg = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));
$oneEth = '1000000000000000000';
$expiredAt = time() - 30 * DAY_IN_SECONDS; // far past the 24h default window

$make = function ($suffix, $status, $orderedAt) use ($wpdb, $pt, $prefix) {
	$order = wc_create_order();
	$order->set_payment_method('nmm_gateway');
	$order->set_status($status);
	$order->update_meta_data('crypto_amount', '1');
	$order->save();
	$address = $prefix . $suffix;
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'ETH','unpaid',%d,%d,'1',0)", $address, $orderedAt, $order->get_id()));
	return array($order->get_id(), $address);
};

add_filter('nmmpro_autopay_percent', function () { return '1'; });
// Coverage fresh enough that every expired ETH row is eligible for expiry, so
// only the protections under test stand between an order and cancellation.
$savedCovered = get_option('nmmpro_autopay_scan_covered_at', null);
$savedActive = get_option('nmmpro_autopay_scan_incomplete', null);
$covered = is_array($savedCovered) ? $savedCovered : array();
$covered['ETH'] = time();
update_option('nmmpro_autopay_scan_covered_at', $covered, false);

// --- 1. claim database error -> address unverified -------------------------
list($o1, $a1) = $make('_claimerr', 'pending', $expiredAt);
list($o1ctl, $a1ctl) = $make('_claimerr_ctl', 'pending', $expiredAt);
$tx1 = new NMMPRO_Transaction($oneEth, 999, time(), $prefix . '_claimerr_tx');
$breakClaim = function ($sql) use ($o1) {
	return (strpos($sql, "SET `status` = 'paid'") !== false && strpos($sql, '`order_id` = ' . $o1) !== false && strpos($sql, "`status` = 'unpaid'") !== false)
		? 'INVALID INJECTED CLAIM FAILURE' : $sql;
};
add_filter('query', $breakClaim);
$was = $wpdb->suppress_errors(true);
$visit = NMMPRO_Payment::process_address_transactions($coin, $a1, array($tx1), 3600);
$wpdb->suppress_errors($was);
remove_filter('query', $breakClaim);
$deferral = get_option('nmmpro_defer_' . md5('ETH|' . $a1), null);
asok('claim DB error: visit reported incomplete', $visit === false);
asok('  order not completed, row still unpaid', !wc_get_order($o1)->is_paid() && as_row($o1) === 'unpaid');
asok('  transaction left unconsumed for retry', !$stg->tx_already_consumed('ETH', $a1, $prefix . '_claimerr_tx'));
asok('  address is deferred from expiry immediately (its own row)', is_array($deferral) && isset($deferral['at']) && (int) $deferral['at'] >= time() - 60);
// The database answers again by the time expiry runs in the same tick.
NMMPRO_Payment::cancel_expired_payments();
asok('expiry does NOT cancel the order whose claim failed', as_order_status($o1) === 'pending' && as_row($o1) === 'unpaid');
asok('  control: an expired order on a healthy address is cancelled', as_order_status($o1ctl) === 'cancelled' && as_row($o1ctl) === 'cancelled');
NMMPRO_Payment::process_address_transactions($coin, $a1, array($tx1), 3600);
asok('  the retry credits the order once the claim succeeds', wc_get_order($o1)->is_paid() && as_row($o1) === 'paid');

// --- 2. claim refuses a transaction already consumed ------------------------
list($o2, $a2) = $make('_owned', 'on-hold', time());
$ownedHash = $prefix . '_owned_tx';
$stg->add_consumed_tx('ETH', $a2, $ownedHash);
$claim = NMMPRO_Consumed_Repo::claim($repo, 'ETH', $a2, $o2, '1', array($ownedHash));
asok('claim over an already-consumed hash is refused', $claim === NMMPRO_Payment_Repo::CLAIM_DB_ERROR, 'got ' . var_export($claim, true));
asok('  and the row is left unpaid', as_row($o2) === 'unpaid');
asok('claim with no transactions is refused', NMMPRO_Consumed_Repo::claim($repo, 'ETH', $a2, $o2, '1', array()) === NMMPRO_Payment_Repo::CLAIM_DB_ERROR && as_row($o2) === 'unpaid');

// --- 3. order-status change cannot clobber 'completing' ---------------------
list($o3, $a3) = $make('_completing', 'on-hold', time());
$repo->set_status($o3, '1', 'completing');
$order3 = wc_get_order($o3);
$order3->update_status('cancelled'); // an admin cancels while completion is pending
asok('admin cancel leaves a completing row alone', as_row($o3) === 'completing', 'row=' . as_row($o3));
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
asok('  recovery then holds the verified payment for review', as_row($o3) === 'review');
$notes3 = wc_get_order_notes(array('order_id' => $o3));
$flagged = false;
foreach ($notes3 as $note) { if (strpos($note->content, 'requires manual reconciliation') !== false) { $flagged = true; } }
asok('  and says so on the order', $flagged);
list($o3b) = $make('_completing_paid', 'on-hold', time());
$repo->set_status($o3b, '1', 'completing');
wc_get_order($o3b)->update_status('processing');
asok('an admin marking the order paid leaves the lease to its owner', as_row($o3b) === 'completing');
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
asok('  which settles it paid from the order', as_row($o3b) === 'paid');

// 3b. WooCommerce fails to SAVE the completed order. It catches the exception
// and still fires the status hooks, so the in-memory order says paid while the
// database says pending. The record must stay recoverable, not become 'paid'.
list($o3d, $a3d) = $make('_savefail', 'on-hold', time());
$failPaid = function ($order) use ($o3d) {
	// An order with nothing to ship completes straight to 'completed'.
	if ($order->get_id() === $o3d && in_array($order->get_status(), array('processing', 'completed'), true)) { throw new Exception('Injected order save failure'); }
};
add_action('woocommerce_before_order_object_save', $failPaid);
NMMPRO_Payment::process_address_transactions($coin, $a3d, array(new NMMPRO_Transaction($oneEth, 999, time(), $prefix . '_savefail_tx')), 3600);
remove_action('woocommerce_before_order_object_save', $failPaid);
asok('failed order save: order still awaiting payment', !WC_Order_Factory::get_order($o3d)->is_paid());
asok('  its verified payment stays recoverable, not marked paid', as_row($o3d) === 'completing', 'row=' . as_row($o3d));
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
asok('  recovery completes the order', WC_Order_Factory::get_order($o3d)->is_paid() && as_row($o3d) === 'paid');
list($o3c) = $make('_plain_cancel', 'on-hold', time());
wc_get_order($o3c)->update_status('cancelled');
asok('  and still retires an ordinary unpaid row on cancel', as_row($o3c) === 'cancelled');

// --- 4. cancellation lease ---------------------------------------------------
// 4a. WooCommerce fails to save the cancellation (it catches the exception
// and returns false): the record must not be left terminal under an order
// still awaiting payment.
list($o4, $a4) = $make('_cancelfail', 'pending', $expiredAt);
$failSave = function ($order) use ($o4) {
	if ($order->get_id() === $o4 && $order->get_status() === 'cancelled') { throw new Exception('Injected order save failure'); }
};
add_action('woocommerce_before_order_object_save', $failSave);
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $failSave);
asok('failed cancellation: order still awaiting payment', as_order_status($o4) === 'pending');
asok('  its record is back to unpaid, not stranded as cancelled', as_row($o4) === 'unpaid', 'row=' . as_row($o4));
NMMPRO_Payment::cancel_expired_payments();
asok('  the next pass cancels it for real', as_order_status($o4) === 'cancelled' && as_row($o4) === 'cancelled');

// 4b. The request dies mid-cancellation (a throwing integration): the lease
// stays, invisible to matching, and recovery settles it from the order.
list($o5, $a5) = $make('_cancelthrow', 'pending', $expiredAt);
$throwCancel = function ($orderId) use ($o5) { if ((int) $orderId === $o5) { throw new RuntimeException('Injected cancellation crash'); } };
add_action('nmmpro_before_autopay_cancel', $throwCancel);
$escaped = false;
try { NMMPRO_Payment::cancel_expired_payments(); } catch (RuntimeException $e) { $escaped = true; }
remove_action('nmmpro_before_autopay_cancel', $throwCancel);
asok('interrupted cancellation does not escape the expiry pass', !$escaped);
asok('  its record is a lease, not terminal', as_row($o5) === 'cancelling', 'row=' . as_row($o5));
asok('  the lease is invisible to the matcher', count($repo->get_unpaid_for_address('ETH', $a5)) === 0);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  recovery returns it to unpaid while the order is payable', as_row($o5) === 'unpaid' && as_order_status($o5) === 'pending');
NMMPRO_Payment::process_address_transactions($coin, $a5, array(new NMMPRO_Transaction($oneEth, 999, time(), $prefix . '_late_tx')), 3600);
asok('  and a payment arriving afterwards is credited', wc_get_order($o5)->is_paid() && as_row($o5) === 'paid');

// 4c. Recovery lets the order decide.
list($o6) = $make('_lease_cancelled', 'cancelled', $expiredAt);
$repo->set_status($o6, '1', 'cancelling');
list($o7) = $make('_lease_paid', 'processing', $expiredAt);
$repo->set_status($o7, '1', 'cancelling');
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('recovery: lease under a cancelled order settles cancelled', as_row($o6) === 'cancelled');
asok('recovery: lease under a paid order settles paid', as_row($o7) === 'paid');

// --- 5. cron fence ------------------------------------------------------------
list($o8) = $make('_unfenced', 'pending', $expiredAt);
asok('fence: outside a cron pass nothing changes', NMMPRO_Util::cron_fence_held() === true);
asok('fence: no advisory locks -> unavailable', NMMPRO_Util::begin_cron_fence(null) === 'unavailable' && NMMPRO_Util::cron_fence_held() === false);
NMMPRO_Payment::cancel_expired_payments();
NMMPRO_Util::end_cron_fence();
asok('  and an unfenced pass cancels nothing', as_order_status($o8) === 'pending' && as_row($o8) === 'unpaid');

$lockName = NMMPRO_Util::cron_lock_name();
$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
asok('fence: held when this connection owns the cron lock', NMMPRO_Util::begin_cron_fence('1') === 'held' && NMMPRO_Util::cron_fence_held());
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
asok('  lost the moment the lock is gone', NMMPRO_Util::cron_fence_held() === false);
$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
asok('  and stays lost for the pass even if the lock comes back', NMMPRO_Util::cron_fence_held() === false);
NMMPRO_Util::end_cron_fence();

// A server that drops the first named lock when a second is taken (MySQL
// before 5.7.5): simulated by making the ownership probe report it gone.
$dropFirst = function ($sql) { return strpos($sql, 'IS_USED_LOCK(') !== false ? 'SELECT 0' : $sql; };
add_filter('query', $dropFirst);
$probe = NMMPRO_Util::begin_cron_fence('1');
remove_filter('query', $dropFirst);
asok('fence: single-lock server detected by probe', $probe === 'single-lock' && NMMPRO_Util::cron_fence_held() === false);
NMMPRO_Payment::cancel_expired_payments();
asok('  and it cancels nothing', as_order_status($o8) === 'pending');
NMMPRO_Util::end_cron_fence();
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));

// An unfenced sweep keeps its own cursor and retry set and leaves every
// option that authorises cancellation untouched. Explorers are refused so the
// pass stays offline; every visit fails, which is exactly what must not leak
// into the certified state.
$certified = array('nmmpro_autopay_scan_cursor', 'nmmpro_autopay_scan_retry', 'nmmpro_autopay_scan_covered_at', 'nmmpro_autopay_scan_sweep_start', 'nmmpro_autopay_scan_dirty', 'nmmpro_autopay_scan_incomplete', 'nmmpro_autopay_scan_incomplete_next');
$before = array();
foreach ($certified as $name) { $before[$name] = get_option($name, '__absent__'); }
delete_option('nmmpro_autopay_scan_cursor_unfenced');
$offline = function () { return new WP_Error('offline', 'test-autopay-safety keeps explorers offline'); };
add_filter('pre_http_request', $offline, 10, 3);
NMMPRO_Util::begin_cron_fence(null);
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
NMMPRO_Util::end_cron_fence();
remove_filter('pre_http_request', $offline, 10);
$changed = array();
foreach ($certified as $name) { if (get_option($name, '__absent__') !== $before[$name]) { $changed[] = $name; } }
asok('unfenced sweep leaves certified scan state untouched', empty($changed), implode(',', $changed));
asok('  and advances its own cursor', get_option('nmmpro_autopay_scan_cursor_unfenced', '') !== '');

// A pass that STARTS fenced and loses the lock mid-sweep writes nothing at
// all - not even the certified cursor - so the next fenced tick re-walks the
// page instead of skipping addresses whose failures went unrecorded. Seed a
// sweep start so the pass's legitimate first-run initialisation - made while
// it still holds the lock - is not mistaken for a write after the loss.
if ((int) get_option('nmmpro_autopay_scan_sweep_start', 0) < 1) { update_option('nmmpro_autopay_scan_sweep_start', time() - 60, false); }
foreach ($certified as $name) { $before[$name] = get_option($name, '__absent__'); }
delete_option('nmmpro_autopay_scan_cursor_unfenced');
$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
$fenceAtStart = NMMPRO_Util::begin_cron_fence('1');
$dropLock = function () use ($wpdb, $lockName) { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName)); };
add_action('nmmpro_autopay_address_checked', $dropLock);
add_filter('pre_http_request', $offline, 10, 3);
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
remove_filter('pre_http_request', $offline, 10);
remove_action('nmmpro_autopay_address_checked', $dropLock);
NMMPRO_Util::end_cron_fence();
$changed = array();
foreach ($certified as $name) { if (get_option($name, '__absent__') !== $before[$name]) { $changed[] = $name; } }
asok('fence lost mid-sweep: pass started fenced', $fenceAtStart === 'held');
asok('  and then wrote nothing it could certify or skip with', empty($changed) && get_option('nmmpro_autopay_scan_cursor_unfenced', '') === '', implode(',', $changed));

// =============================================================================
// Review round 2 - the interleavings and failure paths Codex found.
// =============================================================================

// Private helpers the tests need to name the same lock or option the code uses.
$lockNameFor = function ($crypto, $address) {
	$m = new ReflectionMethod('NMMPRO_Util', 'address_match_lock_name');
	$m->setAccessible(true);
	return $m->invoke(null, $crypto, $address);
};
// Start a new cron tick's in-memory state (the sweep does this at its top):
// a deferral made in-process lasts for the rest of its tick, and a failed
// deferral write stops expiry for that tick, so sections must not inherit
// either from the one before.
$newTick = function () {
	foreach (array('deferredThisTick' => array(), 'deferralWriteFailed' => false) as $prop => $value) {
		$r = new ReflectionProperty('NMMPRO_Payment', $prop);
		$r->setAccessible(true);
		$r->setValue(null, $value);
	}
};
// A second, independent database connection: another worker.
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$other->suppress_errors(true);

// --- 6. deferrals: per-address rows, lapse, and a failed deferral write ----
// 6a. Two addresses deferred by two failed claims both stay deferred: no
// shared read-modify-write can drop one.
list($o9a, $a9a) = $make('_defer_a', 'pending', $expiredAt);
list($o9b, $a9b) = $make('_defer_b', 'pending', $expiredAt);
$breakBoth = function ($sql) use ($o9a, $o9b) {
	return (strpos($sql, "SET `status` = 'paid'") !== false && (strpos($sql, '`order_id` = ' . $o9a) !== false || strpos($sql, '`order_id` = ' . $o9b) !== false))
		? 'INVALID INJECTED CLAIM FAILURE' : $sql;
};
add_filter('query', $breakBoth);
$was = $wpdb->suppress_errors(true);
NMMPRO_Payment::process_address_transactions($coin, $a9a, array(new NMMPRO_Transaction($oneEth, 999, time(), $prefix . '_defer_a_tx')), 3600);
NMMPRO_Payment::process_address_transactions($coin, $a9b, array(new NMMPRO_Transaction($oneEth, 999, time(), $prefix . '_defer_b_tx')), 3600);
$wpdb->suppress_errors($was);
remove_filter('query', $breakBoth);
asok('two failed claims: both addresses keep their own deferral', get_option('nmmpro_defer_' . md5('ETH|' . $a9a)) !== false && get_option('nmmpro_defer_' . md5('ETH|' . $a9b)) !== false);
// 6b. A deferral lapses once a sweep that started after it has certified the
// coin, and the expiry pass then purges it and expires normally.
$newTick();
$covered['ETH'] = time() + 5;
update_option('nmmpro_autopay_scan_covered_at', $covered, false);
NMMPRO_Payment::cancel_expired_payments();
asok('a deferral lapses once coverage postdates it', as_order_status($o9a) === 'cancelled' && get_option('nmmpro_defer_' . md5('ETH|' . $a9a)) === false);
$covered['ETH'] = time();
update_option('nmmpro_autopay_scan_covered_at', $covered, false);
// 6c. If the deferral itself cannot be stored, NO order is expired that tick.
list($o10, $a10) = $make('_deferfail', 'pending', $expiredAt);
list($o10ctl) = $make('_deferfail_ctl', 'pending', $expiredAt);
$breakClaim10 = function ($sql) use ($o10) {
	return (strpos($sql, "SET `status` = 'paid'") !== false && strpos($sql, '`order_id` = ' . $o10) !== false) ? 'INVALID INJECTED CLAIM FAILURE' : $sql;
};
$refuseDeferral = function ($value, $old) { return $old; }; // update_option() then stores nothing
add_filter('query', $breakClaim10);
add_filter('pre_update_option_nmmpro_defer_' . md5('ETH|' . $a10), $refuseDeferral, 10, 2);
$was = $wpdb->suppress_errors(true);
$newTick();
NMMPRO_Payment::process_address_transactions($coin, $a10, array(new NMMPRO_Transaction($oneEth, 999, time(), $prefix . '_deferfail_tx')), 3600);
$wpdb->suppress_errors($was);
remove_filter('query', $breakClaim10);
remove_filter('pre_update_option_nmmpro_defer_' . md5('ETH|' . $a10), $refuseDeferral, 10);
NMMPRO_Payment::cancel_expired_payments();
asok('unstorable deferral: no order is expired this tick', as_order_status($o10) === 'pending' && as_order_status($o10ctl) === 'pending');
$newTick();
NMMPRO_Payment::cancel_expired_payments();
asok('  control: the next tick expires the healthy order again', as_order_status($o10ctl) === 'cancelled');

// --- 7. split-payment claim failure also defers -----------------------------
$xmr = NMMPRO_Cryptocurrencies::get()['XMR'];
$splitOrder = wc_create_order(); $splitOrder->set_payment_method('nmm_gateway'); $splitOrder->set_status('pending'); $splitOrder->update_meta_data('crypto_amount', '1'); $splitOrder->save();
$o11 = $splitOrder->get_id(); $a11 = $prefix . '_xmrsplit';
$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'1',0)", $a11, time(), $o11));
$breakSplit = function ($sql) use ($o11) {
	return (strpos($sql, "SET `status` = 'paid'") !== false && strpos($sql, '`order_id` = ' . $o11) !== false) ? 'INVALID INJECTED CLAIM FAILURE' : $sql;
};
add_filter('query', $breakSplit);
$was = $wpdb->suppress_errors(true);
$splitVisit = NMMPRO_Payment::process_address_transactions($xmr, $a11, array(
	new NMMPRO_Transaction('600000000000', 999, time(), $prefix . '_xs1'),
	new NMMPRO_Transaction('400000000000', 999, time(), $prefix . '_xs2'),
), 3600);
$wpdb->suppress_errors($was);
remove_filter('query', $breakSplit);
asok('split-payment claim error: visit incomplete and address deferred', $splitVisit === false && get_option('nmmpro_defer_' . md5('XMR|' . $a11)) !== false && as_row($o11) === 'unpaid');

// --- 8. claims: strict insert, and never consume against a 'cancelling' row --
// 8a. The consumed check sees nothing (READ COMMITTED, broken lock) yet the
// hash IS recorded: the strict insert must still refuse the claim.
list($o12, $a12) = $make('_strict', 'on-hold', time());
$strictHash = $prefix . '_strict_tx';
$stg->add_consumed_tx('ETH', $a12, $strictHash);
$blindCheck = function ($sql) { return (strpos($sql, 'SELECT COUNT(*) FROM `') === 0 && strpos($sql, 'WHERE identity IN (') !== false) ? 'SELECT 0' : $sql; };
add_filter('query', $blindCheck);
$was = $wpdb->suppress_errors(true);
$claim12 = NMMPRO_Consumed_Repo::claim($repo, 'ETH', $a12, $o12, '1', array($strictHash));
$wpdb->suppress_errors($was);
remove_filter('query', $blindCheck);
asok('strict insert refuses a recorded hash the check missed', $claim12 === NMMPRO_Payment_Repo::CLAIM_DB_ERROR && as_row($o12) === 'unpaid', 'got ' . var_export($claim12, true));
// 8b. Losing to a 'cancelling' row consumes nothing: that row may yet go back
// to unpaid, and this payment must still be able to pay it.
list($o13, $a13) = $make('_vs_cancelling', 'pending', time());
$repo->set_status($o13, '1', 'cancelling');
$claim13 = NMMPRO_Consumed_Repo::claim($repo, 'ETH', $a13, $o13, '1', array($prefix . '_vs_cancelling_tx'));
asok('claim losing to a cancelling row consumes nothing', $claim13 === NMMPRO_Payment_Repo::CLAIM_DB_ERROR && !$stg->tx_already_consumed('ETH', $a13, $prefix . '_vs_cancelling_tx'), 'got ' . var_export($claim13, true));
$repo->set_status($o13, '1', 'cancelled');

// --- 9. authoritative reads ---------------------------------------------------
// The factory returns false for an order that exists (any exception does
// that). Recovery must keep the lease, not mistake it for a deleted order.
list($o14) = $make('_unreadable', 'pending', $expiredAt);
$repo->set_status($o14, '1', 'cancelling');
$unreadable = function ($class, $type, $id) use ($o14) { return (int) $id === $o14 ? 'NMMPRO_Test_No_Such_Order_Class' : $class; };
add_filter('woocommerce_order_class', $unreadable, 10, 3);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
remove_filter('woocommerce_order_class', $unreadable, 10);
asok('unreadable order: recovery keeps the lease', as_row($o14) === 'cancelling', 'row=' . as_row($o14));
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  and settles it once the order reads again', as_row($o14) === 'unpaid');
// A genuinely deleted order is still recognised as gone.
list($o15) = $make('_deleted', 'pending', $expiredAt);
$repo->set_status($o15, '1', 'cancelling');
wc_get_order($o15)->delete(true);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('deleted order: recovery settles the lease cancelled', as_row($o15) === 'cancelled');

// A stale cached order: the order WAS saved as paid, but this process still
// holds a pending snapshot (a save that persisted and then failed before
// WooCommerce cleared its cache). Recovery must read storage, settle the row
// paid, and NOT run payment_complete() - stock, emails - a second time.
list($o21) = $make('_stale_cache', 'on-hold', time());
$repo->set_status($o21, '1', 'completing');
wc_get_order($o21); // primes the order and post caches with 'on-hold'
$ordersUtil = '\Automattic\WooCommerce\Utilities\OrderUtil';
if (class_exists($ordersUtil) && $ordersUtil::custom_orders_table_usage_is_enabled()) {
	$wpdb->update($ordersUtil::get_table_for_orders(), array('status' => 'wc-completed'), array('id' => $o21));
	$storage = 'HPOS';
}
else {
	$wpdb->update($wpdb->posts, array('post_status' => 'wc-completed'), array('ID' => $o21));
	$storage = 'CPT';
}
asok('stale-cache setup: the cached order still says awaiting payment (' . $storage . ')', !wc_get_order($o21)->is_paid());
$GLOBALS['as_completions'] = 0;
$countCompletion = function ($id) use ($o21) { if ((int) $id === $o21) { $GLOBALS['as_completions']++; } };
add_action('woocommerce_pre_payment_complete', $countCompletion);
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
remove_action('woocommerce_pre_payment_complete', $countCompletion);
asok('  recovery reads storage: row paid, payment_complete not re-run', as_row($o21) === 'paid' && $GLOBALS['as_completions'] === 0, 'row=' . as_row($o21) . ' completions=' . $GLOBALS['as_completions']);

// --- 10. order events during settlement are replayed, not lost ------------------
// 10a. Recovery decides "cancelled", an admin reopens the order before the
// write lands: the row must end up payable, with a fresh payment window.
list($o16) = $make('_reopen', 'cancelled', $expiredAt);
$repo->set_status($o16, '1', 'cancelling');
$GLOBALS['as_reopened'] = false;
$reopen = function ($orderId, $lease, $status) use ($o16) {
	if ((int) $orderId === $o16 && !$GLOBALS['as_reopened']) { $GLOBALS['as_reopened'] = true; wc_get_order($o16)->update_status('pending'); }
};
add_action('nmmpro_before_lease_settle', $reopen, 10, 3);
NMMPRO_Payment::recover_interrupted_cancellations();
remove_action('nmmpro_before_lease_settle', $reopen, 10);
$ordered16 = (int) $wpdb->get_var($wpdb->prepare("SELECT ordered_at FROM `$pt` WHERE order_id=%d", $o16));
asok('admin reopen mid-settlement wins: row back to unpaid', $GLOBALS['as_reopened'] && as_row($o16) === 'unpaid', 'row=' . as_row($o16));
asok('  with a fresh payment window', $ordered16 > time() - 120);
// 10b. The same for a completion: settled paid, but the admin reopened the
// order for payment in between.
list($o17) = $make('_reopen_paid', 'processing', time());
$repo->set_status($o17, '1', 'completing');
$GLOBALS['as_reopened'] = false;
$reopenPaid = function ($orderId, $lease, $status) use ($o17) {
	if ((int) $orderId === $o17 && !$GLOBALS['as_reopened']) { $GLOBALS['as_reopened'] = true; wc_get_order($o17)->update_status('pending'); }
};
add_action('nmmpro_before_lease_settle', $reopenPaid, 10, 3);
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
remove_action('nmmpro_before_lease_settle', $reopenPaid, 10);
asok('admin reopen mid-completion wins: row unpaid, not paid', $GLOBALS['as_reopened'] && as_row($o17) === 'unpaid', 'row=' . as_row($o17));

// --- 11. one worker per address: no takeover of a live lease -------------------
// 11a. Another worker holds the address: recovery leaves its lease alone.
list($o18, $a18) = $make('_busy', 'pending', $expiredAt);
$repo->set_status($o18, '1', 'cancelling');
$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockNameFor('ETH', $a18)));
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('recovery skips a lease whose address another worker holds', as_row($o18) === 'cancelling');
// 11b. ...and expiry will not start a cancellation on a busy address.
list($o19, $a19) = $make('_busy_expiry', 'pending', $expiredAt);
list($o19ctl) = $make('_busy_expiry_ctl', 'pending', $expiredAt);
$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockNameFor('ETH', $a19)));
$newTick();
NMMPRO_Payment::cancel_expired_payments();
asok('expiry does not cancel on an address another worker holds', as_order_status($o19) === 'pending' && as_row($o19) === 'unpaid');
asok('  control: the same pass cancels a free address', as_order_status($o19ctl) === 'cancelled');
$other->query('SELECT RELEASE_ALL_LOCKS()');
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  and recovery settles it once the address is free', as_row($o18) === 'unpaid');
// 11c. The canceller loses its address lock after claiming: it must not
// cancel (another worker may be crediting the order) and leaves the lease.
list($o20, $a20) = $make('_stolen', 'pending', $expiredAt);
$steal = function ($orderId) use ($o20, $a20, $wpdb, $other, $lockNameFor) {
	if ((int) $orderId !== $o20) { return; }
	$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockNameFor('ETH', $a20)));
	$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockNameFor('ETH', $a20)));
};
add_action('nmmpro_before_autopay_cancel', $steal);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('nmmpro_before_autopay_cancel', $steal);
asok('canceller that lost its address does not cancel', as_order_status($o20) === 'pending' && as_row($o20) === 'cancelling', 'row=' . as_row($o20));
$other->query('SELECT RELEASE_ALL_LOCKS()');
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  recovery then returns it to unpaid', as_row($o20) === 'unpaid');

// --- 12. fence lost DURING publication -------------------------------------------
// 12a. The fenced write itself: once another connection owns the cron lock, a
// write that CHANGES the value is refused inside the statement, and latches.
$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
NMMPRO_Util::begin_cron_fence('1');
update_option('nmmpro_test_fenced_probe', 'before', false);
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
$fencedResult = NMMPRO_Util::fenced_update_option('nmmpro_test_fenced_probe', 'after');
$fencedState = NMMPRO_Util::cron_fence_state();
NMMPRO_Util::end_cron_fence();
$other->query('SELECT RELEASE_ALL_LOCKS()');
wp_cache_delete('nmmpro_test_fenced_probe', 'options');
asok('fenced write refused while another connection owns the lock', $fencedResult === false && get_option('nmmpro_test_fenced_probe') === 'before');
asok('  and the refusal latches the fence lost', $fencedState === 'lost');
delete_option('nmmpro_test_fenced_probe');
// 12b. A whole wrap:
// A fenced pass wraps (budget covers the whole backlog; explorers offline) and
// its lock is lost right after it promotes the exclusions: coverage must not
// be stamped. Control run first proves this setup does stamp coverage.
$bigBudget = function () { return 100000; };
add_filter('nmmpro_autopay_scan_budget', $bigBudget);
$offline12 = function () { return new WP_Error('offline', 'offline'); };
add_filter('pre_http_request', $offline12, 10, 3);
$covered12 = get_option('nmmpro_autopay_scan_covered_at', array());
$covered12['ETH'] = 1;
update_option('nmmpro_autopay_scan_covered_at', $covered12, false);
$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
NMMPRO_Util::begin_cron_fence('1');
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
NMMPRO_Util::end_cron_fence();
$stampControl = get_option('nmmpro_autopay_scan_covered_at', array());
asok('publication control: a fenced wrap stamps coverage', isset($stampControl['ETH']) && (int) $stampControl['ETH'] > 1);
$covered12 = get_option('nmmpro_autopay_scan_covered_at', array());
$covered12['ETH'] = 1;
update_option('nmmpro_autopay_scan_covered_at', $covered12, false);
// Seed the builder so every write after the promotion CHANGES its value: an
// unchanged value would be caught by the zero-rows ownership re-check instead,
// and this would not test the in-statement fence at all.
update_option('nmmpro_autopay_scan_incomplete_next', array('ETH|' . $prefix . '_seed' => true), false);
update_option('nmmpro_autopay_scan_sweep_start', time() - 3600, false);
$GLOBALS['as_lost_after_promote'] = false;
$loseAfterPromote = function ($sql) {
	if ($GLOBALS['as_lost_after_promote']) {
		// Every later ownership test now answers "not ours".
		return str_replace(array('WHERE IS_USED_LOCK(', 'SELECT IS_USED_LOCK('), array('WHERE 0 AND IS_USED_LOCK(', 'SELECT 0 AND IS_USED_LOCK('), $sql);
	}
	if (strpos($sql, 'INSERT INTO `') === 0 && strpos($sql, "'nmmpro_autopay_scan_incomplete'") !== false) {
		$GLOBALS['as_lost_after_promote'] = true;
	}
	return $sql;
};
add_filter('query', $loseAfterPromote);
NMMPRO_Util::begin_cron_fence('1');
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
$stateAfter = NMMPRO_Util::cron_fence_state();
NMMPRO_Util::end_cron_fence();
remove_filter('query', $loseAfterPromote);
remove_filter('pre_http_request', $offline12, 10);
remove_filter('nmmpro_autopay_scan_budget', $bigBudget);
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
$stampLost = get_option('nmmpro_autopay_scan_covered_at', array());
asok('lock lost mid-publication: exclusions promoted, coverage NOT stamped', $GLOBALS['as_lost_after_promote'] && isset($stampLost['ETH']) && (int) $stampLost['ETH'] === 1, 'ETH=' . (isset($stampLost['ETH']) ? $stampLost['ETH'] : '-'));
asok('  and the pass knows its fence was lost', $stateAfter === 'lost');

// --- restore -------------------------------------------------------------------
if ($savedCovered === null) { delete_option('nmmpro_autopay_scan_covered_at'); } else { update_option('nmmpro_autopay_scan_covered_at', $savedCovered, false); }
if ($savedActive === null) { delete_option('nmmpro_autopay_scan_incomplete'); } else { update_option('nmmpro_autopay_scan_incomplete', $savedActive, false); }
foreach (array($o1, $o1ctl, $o2, $o3, $o3b, $o3c, $o3d, $o4, $o5, $o6, $o7, $o8, $o9a, $o9b, $o10, $o10ctl, $o11, $o12, $o13, $o14, $o15, $o16, $o17, $o18, $o19, $o19ctl, $o20, $o21) as $id) { $wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $id)); }
delete_option('nmmpro_autopay_scan_cursor_unfenced');
delete_option('nmmpro_autopay_scan_retry_unfenced');
foreach (array('ETH|' . $a1, 'ETH|' . $a9a, 'ETH|' . $a9b, 'ETH|' . $a10, 'XMR|' . $a11) as $deferKey) { delete_option('nmmpro_defer_' . md5($deferKey)); }

echo $GLOBALS['as_ok']
	? "\nAUTOPAY-SAFETY CHECKS PASSED (" . $GLOBALS['as_count'] . ")\n"
	: "\nAUTOPAY-SAFETY CHECKS FAILED\n";
