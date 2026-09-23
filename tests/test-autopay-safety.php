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
$active = get_option('nmmpro_autopay_scan_incomplete', array());
asok('claim DB error: visit reported incomplete', $visit === false);
asok('  order not completed, row still unpaid', !wc_get_order($o1)->is_paid() && as_row($o1) === 'unpaid');
asok('  transaction left unconsumed for retry', !$stg->tx_already_consumed('ETH', $a1, $prefix . '_claimerr_tx'));
asok('  address is in the ACTIVE exclusion set immediately', is_array($active) && isset($active['ETH|' . $a1]));
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

// --- restore -------------------------------------------------------------------
if ($savedCovered === null) { delete_option('nmmpro_autopay_scan_covered_at'); } else { update_option('nmmpro_autopay_scan_covered_at', $savedCovered, false); }
if ($savedActive === null) { delete_option('nmmpro_autopay_scan_incomplete'); } else { update_option('nmmpro_autopay_scan_incomplete', $savedActive, false); }
foreach (array($o1, $o1ctl, $o2, $o3, $o3b, $o3c, $o3d, $o4, $o5, $o6, $o7, $o8) as $id) { $wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $id)); }
delete_option('nmmpro_autopay_scan_cursor_unfenced');
delete_option('nmmpro_autopay_scan_retry_unfenced');

echo $GLOBALS['as_ok']
	? "\nAUTOPAY-SAFETY CHECKS PASSED (" . $GLOBALS['as_count'] . ")\n"
	: "\nAUTOPAY-SAFETY CHECKS FAILED\n";
