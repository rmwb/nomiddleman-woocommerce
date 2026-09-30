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
// The order's status as STORED, read past every cache and object.
function as_stored($orderId) {
	global $wpdb;
	$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	if (class_exists($util) && $util::custom_orders_table_usage_is_enabled()) {
		return (string) $wpdb->get_var($wpdb->prepare('SELECT `status` FROM `' . $util::get_table_for_orders() . '` WHERE `id` = %d', $orderId));
	}
	return (string) $wpdb->get_var($wpdb->prepare("SELECT `post_status` FROM `{$wpdb->posts}` WHERE `ID` = %d", $orderId));
}

// Set (or, for null, remove) the lease migration's option in the table AND the
// object cache. delete_option() alone clears the cache only when it deletes a
// row, so a flag written inside a transaction that was rolled back would
// survive in the cache and hide what the code under test does.
function as_set_lease_flag($value) {
	global $wpdb;
	$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s", 'nmmpro_payment_lease_schema'));
	wp_cache_delete('nmmpro_payment_lease_schema', 'options');
	wp_cache_delete('alloptions', 'options');
	wp_cache_delete('notoptions', 'options');
	if ($value !== null) { add_option('nmmpro_payment_lease_schema', $value); }
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
asok('  the next pass cancels it for real', as_order_status($o4) === 'cancelled' && as_row($o4) === 'cancelled', 'order=' . as_order_status($o4) . ' stored=' . as_stored($o4) . ' row=' . as_row($o4));

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
NMMPRO_Compat::update_option('nmmpro_deferral_purge_cursor', 0, false);
NMMPRO_Payment::purge_lapsed_deferrals();
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
// 10b. A completion: about to settle paid when an admin reopens the order.
// The settlement must not write 'paid' over that change; the row stays a
// recoverable 'completing' lease (the verified payment decides from there).
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
asok('admin reopen mid-completion: paid is NOT written over it', $GLOBALS['as_reopened'] && as_row($o17) === 'completing', 'row=' . as_row($o17));

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

// =============================================================================
// Review round 3.
// =============================================================================

// Section 12 deliberately leaves ETH's coverage stamp at 1 ("not stamped");
// re-seed fresh coverage so expired ETH rows are eligible again, or the
// expiry assertions below would pass because nothing was ever examined.
$covered = get_option('nmmpro_autopay_scan_covered_at', array());
$covered['ETH'] = time();
update_option('nmmpro_autopay_scan_covered_at', $covered, false);

// Change an order's stored status behind WooCommerce, as another request's
// save would, and drop this process's cached copies of it.
$setStoredStatus = function ($orderId, $status) use ($wpdb) {
	$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	if (class_exists($util) && $util::custom_orders_table_usage_is_enabled()) {
		$wpdb->update($util::get_table_for_orders(), array('status' => $status), array('id' => $orderId));
		if ($util::orders_cache_usage_is_enabled()) { wc_get_container()->get('\\Automattic\\WooCommerce\\Caches\\OrderCache')->remove($orderId); }
		WC_Data_Store::load('order')->__call('clear_cached_data', array(array($orderId)));
	}
	else {
		$wpdb->update($wpdb->posts, array('post_status' => $status), array('ID' => $orderId));
	}
	clean_post_cache($orderId);
};

// --- 13. lease_gen: one statement per order event -------------------------------
list($o22) = $make('_gen', 'on-hold', time());
$repo->set_status($o22, '1', 'cancelling');
$gen0 = $repo->lease_state($o22, '1');
$repo->set_status_from_order_event($o22, '1', 'unpaid');
$gen1 = $repo->lease_state($o22, '1');
asok('order event on a lease: status kept, generation bumped', $gen1['status'] === 'cancelling' && $gen1['gen'] === $gen0['gen'] + 1);
$repo->set_status($o22, '1', 'unpaid');
$repo->set_status_from_order_event($o22, '1', 'paid');
$gen2 = $repo->lease_state($o22, '1');
asok('order event on an ordinary row: applied and bumped', $gen2['status'] === 'paid' && $gen2['gen'] === $gen1['gen'] + 1);
// The order is stored exactly as observed (on-hold), so the generation alone decides.
asok('a stale-generation settlement matches nothing', $repo->settle_lease($o22, '1', 'paid', $gen1['gen'], 'cancelled', 'on-hold') === 0 && as_row($o22) === 'paid');
asok('  control: the current generation, same order, settles', $repo->settle_lease($o22, '1', 'paid', $gen2['gen'], 'cancelled', 'on-hold') === 1 && as_row($o22) === 'cancelled');
$repo->set_status($o22, '1', 'paid');
$gen3 = $repo->lease_state($o22, '1');
asok('  an order stored differently from what was read matches nothing', $repo->settle_lease($o22, '1', 'paid', $gen3['gen'], 'cancelled', 'processing') === 0 && as_row($o22) === 'paid');

// An order event mid-settlement, then the re-read FAILS: nothing terminal may
// have been written - the row stays a lease recovery will select.
list($o23) = $make('_gen_readfail', 'cancelled', $expiredAt);
$repo->set_status($o23, '1', 'cancelling');
$GLOBALS['as_seam'] = 0;
$unreadable23 = function ($class, $type, $id) use ($o23) { return (int) $id === $o23 ? 'NMMPRO_Test_No_Such_Order_Class' : $class; };
$eventThenBreak = function ($orderId) use ($o23, $repo, $unreadable23, $setStoredStatus) {
	if ((int) $orderId !== $o23 || $GLOBALS['as_seam']++ > 0) { return; }
	$setStoredStatus($o23, 'wc-pending');                          // an admin reopens the order...
	$repo->set_status_from_order_event($o23, '1', 'unpaid');      // ...its event lands...
	add_filter('woocommerce_order_class', $unreadable23, 10, 3);    // ...and the next read fails
};
add_action('nmmpro_before_lease_settle', $eventThenBreak, 10, 1);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
remove_action('nmmpro_before_lease_settle', $eventThenBreak, 10);
remove_filter('woocommerce_order_class', $unreadable23, 10);
asok('event then failed re-read: row stays a recoverable lease', $GLOBALS['as_seam'] > 0 && as_row($o23) === 'cancelling', 'row=' . as_row($o23) . ' seam=' . $GLOBALS['as_seam']);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  and the next recovery settles it from the reopened order: unpaid', as_row($o23) === 'unpaid' && as_stored($o23) === 'wc-pending', 'row=' . as_row($o23) . ' stored=' . as_stored($o23));

// --- 14. the cancellation is fenced INSIDE WooCommerce's save ---------------------
// 14a. The lock is lost after every pre-save check has passed - during the
// save itself. The order must not be saved cancelled.
list($o24, $a24) = $make('_savefence', 'pending', $expiredAt);
$stealDuringSave = function ($order) use ($o24, $a24, $wpdb, $other, $lockNameFor) {
	if ($order->get_id() !== $o24 || $order->get_status() !== 'cancelled') { return; }
	$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockNameFor('ETH', $a24)));
	$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockNameFor('ETH', $a24)));
};
add_action('woocommerce_before_order_object_save', $stealDuringSave, 1);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $stealDuringSave, 1);
asok('lock lost during the save: the order is NOT cancelled', as_order_status($o24) === 'pending' && as_row($o24) === 'cancelling', 'order=' . as_order_status($o24) . ' row=' . as_row($o24));
$other->query('SELECT RELEASE_ALL_LOCKS()');
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  recovery returns it to unpaid', as_row($o24) === 'unpaid');
// 14b. Same connection, nested: while this process holds an address, a
// recovery reached from inside that work must treat it as busy (named locks
// are recursive, so the database alone would let it in).
list($o25, $a25) = $make('_reentry', 'pending', $expiredAt);
$repo->set_status($o25, '1', 'cancelling');
$held = NMMPRO_Util::acquire_address_match_lock('ETH', $a25);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
$rowWhileHeld = as_row($o25);
NMMPRO_Util::release_address_match_lock('ETH', $a25);
asok('recovery nested inside this process\'s own address work is refused', $held === '1' && $rowWhileHeld === 'cancelling', 'row=' . $rowWhileHeld);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  and runs once the outer work has released it', as_row($o25) === 'unpaid');

// --- 15. expiry re-judges the row once it holds the address -----------------------
// 15a. Another worker defers the address between expiry's snapshot check and
// expiry getting the lock (simulated as the lock is taken).
list($o26, $a26) = $make('_late_defer', 'pending', $expiredAt);
$GLOBALS['as_deferred26'] = false;
$deferAtLock = function ($sql) use ($a26, $lockNameFor) {
	if (!$GLOBALS['as_deferred26'] && strpos($sql, 'GET_LOCK(') !== false && strpos($sql, $lockNameFor('ETH', $a26)) !== false) {
		$GLOBALS['as_deferred26'] = true;
		update_option('nmmpro_defer_' . md5('ETH|' . $a26), array('crypto' => 'ETH', 'address' => $a26, 'at' => time()), false);
	}
	return $sql;
};
add_filter('query', $deferAtLock);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_filter('query', $deferAtLock);
asok('deferral arriving before the lock is honoured under it', $GLOBALS['as_deferred26'] && as_order_status($o26) === 'pending' && as_row($o26) === 'unpaid');
delete_option('nmmpro_defer_' . md5('ETH|' . $a26));
// 15b. An admin reopens the order (fresh payment window) in the same gap.
list($o27, $a27) = $make('_late_reopen', 'pending', $expiredAt);
$GLOBALS['as_reopened27'] = false;
$reopenAtLock = function ($sql) use ($a27, $o27, $lockNameFor, $pt) {
	global $wpdb;
	if (!$GLOBALS['as_reopened27'] && strpos($sql, 'GET_LOCK(') !== false && strpos($sql, $lockNameFor('ETH', $a27)) !== false) {
		$GLOBALS['as_reopened27'] = true;
		$wpdb->query($wpdb->prepare("UPDATE `$pt` SET ordered_at=%d WHERE order_id=%d", time(), $o27));
	}
	return $sql;
};
add_filter('query', $reopenAtLock);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_filter('query', $reopenAtLock);
asok('fresh payment window arriving before the lock is honoured', $GLOBALS['as_reopened27'] && as_order_status($o27) === 'pending' && as_row($o27) === 'unpaid');

// --- 16. a direct sweep certifies nothing after a failed safety write ---------------
$bigBudget16 = function () { return 100000; };
$offline16 = function () { return new WP_Error('offline', 'offline'); };
add_filter('nmmpro_autopay_scan_budget', $bigBudget16);
add_filter('pre_http_request', $offline16, 10, 3);
update_option('nmmpro_autopay_scan_retry', array('ETH|' . $prefix . '_stale_retry'), false);
$covered16 = get_option('nmmpro_autopay_scan_covered_at', array());
$covered16['ETH'] = 1;
update_option('nmmpro_autopay_scan_covered_at', $covered16, false);
$refuseRetry = function ($value, $old) { return $old; };
add_filter('pre_update_option_nmmpro_autopay_scan_retry', $refuseRetry, 10, 2);
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
remove_filter('pre_update_option_nmmpro_autopay_scan_retry', $refuseRetry, 10);
$after16 = get_option('nmmpro_autopay_scan_covered_at', array());
asok('direct sweep: failed retry write -> coverage NOT stamped', isset($after16['ETH']) && (int) $after16['ETH'] === 1, 'ETH=' . (isset($after16['ETH']) ? $after16['ETH'] : '-'));
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
$after16b = get_option('nmmpro_autopay_scan_covered_at', array());
asok('  control: the same sweep stamps once the write lands', isset($after16b['ETH']) && (int) $after16b['ETH'] > 1);
remove_filter('pre_http_request', $offline16, 10);
remove_filter('nmmpro_autopay_scan_budget', $bigBudget16);

// --- 17. the pause switch stops the background job before it does anything ---------
// A sentinel, not "whatever it was": a pass run in the same second as an
// earlier one would write the same value and hide that it ran.
update_option('nmmpro_autopay_scan_last_run', 1, false);
$lastRun17 = get_option('nmmpro_autopay_scan_last_run');
update_option('nmmpro_background_paused', 1, false);
// Offline, so a regression that ignores the pause cannot reach real explorers.
$offline17 = function () { return new WP_Error('offline', 'offline'); };
add_filter('pre_http_request', $offline17, 10, 3);
NMMPRO_do_cron_job();
remove_filter('pre_http_request', $offline17, 10);
$pausedLastRun = get_option('nmmpro_autopay_scan_last_run');
delete_option('nmmpro_background_paused');
asok('paused: the cron job returns without running a pass', (int) $pausedLastRun === 1 && NMMPRO_Util::cron_pass_running() === false, 'last_run=' . $pausedLastRun);

// --- 18. deferral purge pages past long-lived deferrals ----------------------------
$future = array();
for ($i = 0; $i < 201; $i++) {
	$k = 'ETH|' . $prefix . '_live_' . $i;
	$future[] = $k;
	update_option('nmmpro_defer_' . md5($k), array('crypto' => 'ETH', 'address' => $k, 'at' => time() + 86400), false);
}
$lapsedKey = 'ETH|' . $prefix . '_lapsed';
update_option('nmmpro_defer_' . md5($lapsedKey), array('crypto' => 'ETH', 'address' => $lapsedKey, 'at' => 5), false);
NMMPRO_Compat::update_option('nmmpro_deferral_purge_cursor', 0, false);
$passes = 0;
while ($passes < 10 && get_option('nmmpro_defer_' . md5($lapsedKey)) !== false) {
	wp_cache_delete('nmmpro_defer_' . md5($lapsedKey), 'options');
	NMMPRO_Payment::purge_lapsed_deferrals();
	$passes++;
	wp_cache_delete('nmmpro_defer_' . md5($lapsedKey), 'options');
}
asok('purge reaches a lapsed deferral behind 201 live ones', get_option('nmmpro_defer_' . md5($lapsedKey)) === false && $passes >= 2, 'passes=' . $passes);
foreach ($future as $k) { delete_option('nmmpro_defer_' . md5($k)); }

// =============================================================================
// Review round 4.
// =============================================================================
$covered = get_option('nmmpro_autopay_scan_covered_at', array());
$covered['ETH'] = time();
update_option('nmmpro_autopay_scan_covered_at', $covered, false);

// --- 19. generation read BEFORE the order (M3a) ----------------------------------
// A payment lands between the canceller's two reads. Read in the right order,
// the canceller sees the paid order; read the wrong way round it would pair a
// new generation with a stale pending order and cancel a paid order.
list($o28, $a28) = $make('_between_reads', 'pending', $expiredAt);
$GLOBALS['as_armed28'] = false; $GLOBALS['as_fired28'] = false;
$arm28 = function ($orderId) use ($o28) { if ((int) $orderId === $o28) { $GLOBALS['as_armed28'] = true; } };
$payBetweenReads = function ($sql) use ($o28, $repo, $setStoredStatus) {
	if ($GLOBALS['as_armed28'] && !$GLOBALS['as_fired28'] && strpos($sql, 'SELECT `status`, `lease_gen`') !== false && strpos($sql, '`order_id` = ' . $o28) !== false) {
		$GLOBALS['as_fired28'] = true;
		$setStoredStatus($o28, 'wc-processing');                // the payment is saved...
		$repo->set_status_from_order_event($o28, '1', 'paid');   // ...and its event recorded
	}
	return $sql;
};
add_action('nmmpro_before_autopay_cancel', $arm28);
add_filter('query', $payBetweenReads);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_filter('query', $payBetweenReads);
remove_action('nmmpro_before_autopay_cancel', $arm28);
asok('payment between the canceller\'s reads: the paid order is NOT cancelled', $GLOBALS['as_fired28'] && as_order_status($o28) === 'processing', 'order=' . as_order_status($o28));
asok('  and its record settles paid', as_row($o28) === 'paid', 'row=' . as_row($o28));

// --- 20. a refused cancellation fires no cancellation hooks (M3c) ----------------
list($o29, $a29) = $make('_quiet_refusal', 'pending', $expiredAt);
list($o29c) = $make('_quiet_control', 'pending', $expiredAt);
$GLOBALS['as_cancel_hooks29'] = 0; $GLOBALS['as_cancel_hooks29c'] = 0; $GLOBALS['as_attempt29'] = false;
$countCancelHooks = function ($orderId) use ($o29, $o29c) {
	if ((int) $orderId === $o29) { $GLOBALS['as_cancel_hooks29']++; }
	if ((int) $orderId === $o29c) { $GLOBALS['as_cancel_hooks29c']++; }
};
add_action('woocommerce_order_status_cancelled', $countCancelHooks);
add_action('woocommerce_order_status_pending_to_cancelled', $countCancelHooks);
$steal29 = function ($order) use ($o29, $a29, $wpdb, $other, $lockNameFor) {
	if ($order->get_id() !== $o29 || $order->get_status() !== 'cancelled') { return; }
	$GLOBALS['as_attempt29'] = true;                     // the cancellation save was attempted
	$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockNameFor('ETH', $a29)));
	$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockNameFor('ETH', $a29)));
};
add_action('woocommerce_before_order_object_save', $steal29, 1);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $steal29, 1);
remove_action('woocommerce_order_status_cancelled', $countCancelHooks);
remove_action('woocommerce_order_status_pending_to_cancelled', $countCancelHooks);
$other->query('SELECT RELEASE_ALL_LOCKS()');
asok('refused cancellation: order not cancelled and lease kept', $GLOBALS['as_attempt29'] && as_stored($o29) === 'wc-pending' && as_row($o29) === 'cancelling', 'stored=' . as_stored($o29) . ' row=' . as_row($o29));
asok('  and NO cancellation hooks fired', $GLOBALS['as_cancel_hooks29'] === 0, 'hooks=' . $GLOBALS['as_cancel_hooks29']);
asok('  control: the same pass cancels an unfenced order, hooks and all', as_stored($o29c) === 'wc-cancelled' && $GLOBALS['as_cancel_hooks29c'] > 0, 'stored=' . as_stored($o29c) . ' hooks=' . $GLOBALS['as_cancel_hooks29c']);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();

// --- 21. an admin change whose event write failed still wins (M2) ----------------
// Settlement decides "cancelled"; the admin reopens the order, but the event's
// payment-row update is lost (no generation bump). The order's stored status
// must stop the stale write.
list($o30) = $make('_lost_event', 'cancelled', $expiredAt);
$repo->set_status($o30, '1', 'cancelling');
$GLOBALS['as_fired30'] = false;
$reopenWithoutEvent = function ($orderId, $lease, $status) use ($o30, $setStoredStatus) {
	if ((int) $orderId === $o30 && !$GLOBALS['as_fired30']) { $GLOBALS['as_fired30'] = true; $setStoredStatus($o30, 'wc-pending'); }
};
add_action('nmmpro_before_lease_settle', $reopenWithoutEvent, 10, 3);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
remove_action('nmmpro_before_lease_settle', $reopenWithoutEvent, 10);
asok('lost event: stale "cancelled" not written over a reopened order', $GLOBALS['as_fired30'] && as_row($o30) === 'unpaid', 'row=' . as_row($o30));

// --- 22. the first sweep-start write must land (M5) ------------------------------
delete_option('nmmpro_autopay_scan_sweep_start');
update_option('nmmpro_autopay_scan_cursor', 'ETH|' . $prefix . '_cursor_sentinel', false);
$covered22 = get_option('nmmpro_autopay_scan_covered_at', array());
$refuseStart = function ($value, $old) { return $old; };
$tinyBudget = function () { return 1; };
$offline22 = function () { return new WP_Error('offline', 'offline'); };
add_filter('pre_update_option_nmmpro_autopay_scan_sweep_start', $refuseStart, 10, 2);
add_filter('nmmpro_autopay_scan_budget', $tinyBudget);
add_filter('pre_http_request', $offline22, 10, 3);
NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
remove_filter('pre_http_request', $offline22, 10);
remove_filter('nmmpro_autopay_scan_budget', $tinyBudget);
remove_filter('pre_update_option_nmmpro_autopay_scan_sweep_start', $refuseStart, 10);
wp_cache_delete('nmmpro_autopay_scan_cursor', 'options');
asok('unstored sweep start: the certified cursor does not advance', get_option('nmmpro_autopay_scan_cursor') === 'ETH|' . $prefix . '_cursor_sentinel', 'cursor=' . get_option('nmmpro_autopay_scan_cursor'));
asok('  and no coverage is stamped', get_option('nmmpro_autopay_scan_covered_at', array()) === $covered22);

// --- 23. a pass that waited for the lock re-checks the pause (M6) ----------------
update_option('nmmpro_autopay_scan_last_run', 1, false);
$GLOBALS['as_paused23'] = false;
$pauseWhileWaiting = function ($sql) use ($wpdb) {
	if (!$GLOBALS['as_paused23'] && strpos($sql, 'GET_LOCK(') !== false && strpos($sql, "'nmm_cron_") !== false) {
		$GLOBALS['as_paused23'] = true;
		update_option('nmmpro_background_paused', 1, false); // the operator pauses now
	}
	return $sql;
};
$offline23 = function () { return new WP_Error('offline', 'offline'); };
add_filter('pre_http_request', $offline23, 10, 3);
add_filter('query', $pauseWhileWaiting);
NMMPRO_do_cron_job();
remove_filter('query', $pauseWhileWaiting);
remove_filter('pre_http_request', $offline23, 10);
delete_option('nmmpro_background_paused');
asok('pause set while a pass waited for the lock: it does no work', $GLOBALS['as_paused23'] && (int) get_option('nmmpro_autopay_scan_last_run') === 1 && NMMPRO_Util::cron_pass_running() === false);

// --- 24. events still apply without the lease_gen column (N1) --------------------
list($o31) = $make('_no_column', 'cancelled', time());
$repo->set_status($o31, '1', 'cancelled');
$noColumn = function ($sql) { return strpos($sql, '`lease_gen` = `lease_gen` + 1') !== false ? str_replace('`lease_gen`', '`lease_gen_missing`', $sql) : $sql; };
add_filter('query', $noColumn);
$was = $wpdb->suppress_errors(true);
$repo->set_status_from_order_event($o31, '1', 'unpaid');
$wpdb->suppress_errors($was);
remove_filter('query', $noColumn);
asok('missing lease_gen column: an ordinary order event still applies', as_row($o31) === 'unpaid', 'row=' . as_row($o31));

// --- 25. no nested cancellation erases the outer fence (N2) ----------------------
list($o32, $a32) = $make('_outer', 'pending', $expiredAt);
list($o33, $a33) = $make('_inner', 'pending', $expiredAt);
$GLOBALS['as_inner33'] = null;
$nestAndSteal = function ($order) use ($o32, $o33, $a32, $wpdb, $other, $lockNameFor) {
	if ($order->get_id() !== $o32 || $order->get_status() !== 'cancelled') { return; }
	// Whatever the outer pass has or has not done to the inner order yet, the
	// NESTED call must not change it: compare its state across that call.
	$before = as_row($o33) . '/' . as_order_status($o33);
	NMMPRO_Payment::cancel_expired_payments();   // an integration re-enters expiry
	$GLOBALS['as_inner33'] = array($before, as_row($o33) . '/' . as_order_status($o33));
	$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockNameFor('ETH', $a32)));
	$other->get_var($other->prepare('SELECT GET_LOCK(%s, 5)', $lockNameFor('ETH', $a32)));
};
add_action('woocommerce_before_order_object_save', $nestAndSteal, 1);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $nestAndSteal, 1);
$other->query('SELECT RELEASE_ALL_LOCKS()');
asok('nested expiry is refused: an eligible inner order is left alone', is_array($GLOBALS['as_inner33']) && $GLOBALS['as_inner33'][0] === 'unpaid/pending' && $GLOBALS['as_inner33'][1] === 'unpaid/pending', 'inner=' . json_encode($GLOBALS['as_inner33']));
asok('  and the outer fence still refuses its stale cancellation', as_order_status($o32) === 'pending' && as_row($o32) === 'cancelling', 'outer order=' . as_order_status($o32) . ' row=' . as_row($o32));
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();

// =============================================================================
// Review round 5 (Codex round 4 findings).
// =============================================================================
$covered = get_option('nmmpro_autopay_scan_covered_at', array());
$covered['ETH'] = time();
update_option('nmmpro_autopay_scan_covered_at', $covered, false);

// --- 26. a payment saved, its event lost, while the cancellation saves (M3) -------
// The generation cannot move (the payment record's event write failed), so the
// fence must check the order's STORED status itself.
list($o36, $a36) = $make('_paid_event_lost', 'pending', $expiredAt);
$GLOBALS['as_fired36'] = false;
$payEventLost = function ($order) use ($o36, $setStoredStatus) {
	if ($order->get_id() !== $o36 || $order->get_status() !== 'cancelled' || $GLOBALS['as_fired36']) { return; }
	$GLOBALS['as_fired36'] = true;
	$setStoredStatus($o36, 'wc-processing');   // another request saved the payment; no event recorded
};
add_action('woocommerce_before_order_object_save', $payEventLost, 1);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $payEventLost, 1);
asok('paid while cancelling, event lost: the paid order is NOT cancelled', $GLOBALS['as_fired36'] && as_stored($o36) === 'wc-processing', 'stored=' . as_stored($o36));
asok('  its lease is left for recovery', as_row($o36) === 'cancelling', 'row=' . as_row($o36));
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  which settles it paid', as_row($o36) === 'paid', 'row=' . as_row($o36));

// --- 27. a refusal writes nothing, not an older status (R4-N1) --------------------
// Another request saves the payment AND its event lands: the fence refuses.
// It must not then save the canceller's older 'pending' over 'processing'.
list($o37, $a37) = $make('_refusal_no_write', 'pending', $expiredAt);
$GLOBALS['as_fired37'] = false; $GLOBALS['as_hooks37'] = 0;
$payWithEvent = function ($order) use ($o37, $repo, $setStoredStatus) {
	if ($order->get_id() !== $o37 || $order->get_status() !== 'cancelled' || $GLOBALS['as_fired37']) { return; }
	$GLOBALS['as_fired37'] = true;
	$setStoredStatus($o37, 'wc-processing');
	$repo->set_status_from_order_event($o37, '1', 'paid');
};
$count37 = function ($orderId) use ($o37) { if ((int) $orderId === $o37) { $GLOBALS['as_hooks37']++; } };
add_action('woocommerce_before_order_object_save', $payWithEvent, 1);
add_action('woocommerce_order_status_changed', $count37);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_order_status_changed', $count37);
remove_action('woocommerce_before_order_object_save', $payWithEvent, 1);
asok('refused cancellation writes nothing: the order stays processing', $GLOBALS['as_fired37'] && as_stored($o37) === 'wc-processing', 'stored=' . as_stored($o37));
asok('  and no status-change hooks fired for it', $GLOBALS['as_hooks37'] === 0, 'hooks=' . $GLOBALS['as_hooks37']);
NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
NMMPRO_Payment::recover_interrupted_cancellations();
asok('  recovery settles its record paid', as_row($o37) === 'paid', 'row=' . as_row($o37));

// --- 28. completion leases settle for custom statuses (R4-N2) ---------------------
register_post_status('wc-nmm-verifying', array('label' => 'Verifying', 'public' => false, 'exclude_from_search' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true));
$customStatus = function ($statuses) { $statuses['wc-nmm-verifying'] = 'Verifying'; return $statuses; };
add_filter('wc_order_statuses', $customStatus);
list($o38) = $make('_custom_review', 'on-hold', time());
$repo->set_status($o38, '1', 'completing');
$setStoredStatus($o38, 'wc-nmm-verifying');                // an integration's own non-paid status
list($o39) = $make('_custom_paid', 'on-hold', time());
$repo->set_status($o39, '1', 'completing');
$setStoredStatus($o39, 'wc-nmm-verifying');
$paidByFilter = function ($paid, $order) use ($o39) { return $order->get_id() === $o39 ? true : $paid; };
add_filter('woocommerce_order_is_paid', $paidByFilter, 10, 2);  // paid-ness decided per order
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
remove_filter('woocommerce_order_is_paid', $paidByFilter, 10);
$notes38 = wc_get_order_notes(array('order_id' => $o38));
$flagged38 = false;
foreach ($notes38 as $note) { if (strpos($note->content, 'requires manual reconciliation') !== false) { $flagged38 = true; } }
asok('verified payment on a custom non-paid status: held for review', as_row($o38) === 'review', 'row=' . as_row($o38));
asok('  with the manual-reconciliation note', $flagged38);
asok('an order paid by a per-order filter settles its lease paid', as_row($o39) === 'paid', 'row=' . as_row($o39));
remove_filter('wc_order_statuses', $customStatus);

// --- 29. order events never run DDL (R4-N3) ---------------------------------------
// With the lease_gen migration pending, an order event inside a caller's
// transaction must neither run the migration (ALTER TABLE commits implicitly)
// nor survive the caller's rollback.
$savedLeaseSchema = get_option('nmmpro_payment_lease_schema', null);
list($o40) = $make('_txn_ordinary', 'cancelled', time());
$repo->set_status($o40, '1', 'cancelled');
list($o41) = $make('_txn_lease', 'pending', time());
$repo->set_status($o41, '1', 'cancelling');
as_set_lease_flag(null);
$GLOBALS['as_ddl'] = 0;
$countDdl = function ($sql) { if (preg_match('/^\s*(ALTER|CREATE|DROP)\s/i', $sql)) { $GLOBALS['as_ddl']++; } return $sql; };
add_filter('query', $countDdl);
$wpdb->query('START TRANSACTION');
$repo->set_status_from_order_event($o40, '1', 'unpaid');
$repo->set_status_from_order_event($o41, '1', 'unpaid');
$inTxn40 = as_row($o40);
$flagInTxn = get_option('nmmpro_payment_lease_schema', null);
$wpdb->query('ROLLBACK');
remove_filter('query', $countDdl);
as_set_lease_flag($savedLeaseSchema);
asok('migration pending: an order event runs no DDL and no migration', $GLOBALS['as_ddl'] === 0 && $flagInTxn === null, 'ddl=' . $GLOBALS['as_ddl'] . ' flag=' . var_export($flagInTxn, true));
asok('  it still applies to an ordinary row', $inTxn40 === 'unpaid', 'row=' . $inTxn40);
asok('  and the caller\'s rollback undoes it (nothing committed early)', as_row($o40) === 'cancelled', 'row=' . as_row($o40));
asok('  a leased row waits for the migration', as_row($o41) === 'cancelling', 'row=' . as_row($o41));

// The same with the column really missing, so a migration run from the event
// would have to ALTER TABLE - and that would commit the caller's transaction.
$hasLeaseGen = function () use ($wpdb, $pt) { return (bool) $wpdb->get_results("SHOW COLUMNS FROM `$pt` LIKE 'lease_gen'"); };
list($o42) = $make('_txn_nocol_ordinary', 'cancelled', time());
$repo->set_status($o42, '1', 'cancelled');
list($o43) = $make('_txn_nocol_lease', 'pending', time());
$repo->set_status($o43, '1', 'cancelling');
$wpdb->query("ALTER TABLE `$pt` DROP COLUMN `lease_gen`");
as_set_lease_flag(null);
$droppedCol = !$hasLeaseGen();
$GLOBALS['as_ddl'] = 0;
add_filter('query', $countDdl);
$was = $wpdb->suppress_errors(true);
$wpdb->query('START TRANSACTION');
$repo->set_status_from_order_event($o42, '1', 'unpaid');
$repo->set_status_from_order_event($o43, '1', 'unpaid');
$inTxn42 = as_row($o42);
$wpdb->query('ROLLBACK');
$wpdb->suppress_errors($was);
remove_filter('query', $countDdl);
$colAfter = $hasLeaseGen();
$flagAfter = get_option('nmmpro_payment_lease_schema', null);
asok('column missing: the fixture really lacks lease_gen', $droppedCol);
asok('  an order event runs no DDL, adds no column, records no migration', $GLOBALS['as_ddl'] === 0 && !$colAfter && $flagAfter === null, 'ddl=' . $GLOBALS['as_ddl'] . ' column=' . var_export($colAfter, true) . ' flag=' . var_export($flagAfter, true));
asok('  it applied to the ordinary row inside the transaction', $inTxn42 === 'unpaid', 'row=' . $inTxn42);
asok('  and the rollback undid it (no implicit commit)', as_row($o42) === 'cancelled', 'row=' . as_row($o42));
asok('  a leased row waits for the migration', as_row($o43) === 'cancelling', 'row=' . as_row($o43));
NMMPRO_maybe_add_payment_lease_gen();                       // the load-time migration restores it
asok('  the load-time migration then adds the column and records itself', $hasLeaseGen() && get_option('nmmpro_payment_lease_schema') === '1');
as_set_lease_flag($savedLeaseSchema);

// --- 30. a failed initial sweep start stays failed through the chain (M5) -------
// One page, every fetch failing, a retry cap of 1: dropped retries make an
// incomplete address, so the builder write runs - and must not "repair" the
// refused start.
$m5Run = function ($refuse) use ($prefix) {
	delete_option('nmmpro_autopay_scan_sweep_start');
	update_option('nmmpro_autopay_scan_cursor', 'ETH|' . $prefix . '_m5_sentinel', false);
	delete_option('nmmpro_autopay_scan_incomplete_next');
	$GLOBALS['as_refused_start'] = 0;
	$refuse30 = function ($value, $old) { $GLOBALS['as_refused_start']++; return $old; };
	$budget30 = function () { return 3; };
	$cap30 = function () { return 1; };
	$offline30 = function () { return new WP_Error('offline', 'offline'); };
	if ($refuse) { add_filter('pre_update_option_nmmpro_autopay_scan_sweep_start', $refuse30, 10, 2); }
	add_filter('nmmpro_autopay_scan_budget', $budget30);
	add_filter('nmmpro_autopay_scan_retry_cap', $cap30);
	add_filter('pre_http_request', $offline30, 10, 3);
	NMMPRO_Payment::check_all_addresses_for_matching_payment(3 * HOUR_IN_SECONDS);
	remove_filter('pre_http_request', $offline30, 10);
	remove_filter('nmmpro_autopay_scan_retry_cap', $cap30);
	remove_filter('nmmpro_autopay_scan_budget', $budget30);
	remove_filter('pre_update_option_nmmpro_autopay_scan_sweep_start', $refuse30, 10);
	wp_cache_delete('nmmpro_autopay_scan_cursor', 'options');
	wp_cache_delete('nmmpro_autopay_scan_incomplete_next', 'options');
	return array(get_option('nmmpro_autopay_scan_cursor'), get_option('nmmpro_autopay_scan_incomplete_next', array()), $GLOBALS['as_refused_start']);
};
list($ctlCursor, $ctlBuilder) = $m5Run(false);
asok('control: the page reaches the builder and the cursor', $ctlCursor !== 'ETH|' . $prefix . '_m5_sentinel' && is_array($ctlBuilder) && count($ctlBuilder) > 0, 'cursor=' . $ctlCursor . ' builder=' . count((array) $ctlBuilder));
list($m5Cursor, $m5Builder, $m5Refused) = $m5Run(true);
asok('refused start + incomplete page: the cursor does not advance', $m5Refused > 0 && $m5Cursor === 'ETH|' . $prefix . '_m5_sentinel', 'refused=' . $m5Refused . ' cursor=' . $m5Cursor);
asok('  and nothing further is written', $m5Builder === array(), 'builder=' . json_encode($m5Builder));
delete_option('nmmpro_autopay_scan_incomplete_next');

// --- 31. a failed pause read is not "not paused" (M6) -----------------------------
$cronLock = NMMPRO_Util::cron_lock_name();
update_option('nmmpro_autopay_scan_last_run', 1, false);
$GLOBALS['as_after_lock'] = false; $GLOBALS['as_broke_pause'] = false;
$breakPauseRead = function ($sql) {
	if (strpos($sql, 'GET_LOCK(') !== false && strpos($sql, "'nmm_cron_") !== false) { $GLOBALS['as_after_lock'] = true; return $sql; }
	if ($GLOBALS['as_after_lock'] && !$GLOBALS['as_broke_pause'] && strpos($sql, "'nmmpro_background_paused'") !== false && strpos($sql, 'SELECT `option_value`') !== false) {
		$GLOBALS['as_broke_pause'] = true;
		return 'SELECT `option_value` FROM `no_such_options_table` WHERE 0';
	}
	return $sql;
};
$offline31 = function () { return new WP_Error('offline', 'offline'); };
add_filter('pre_http_request', $offline31, 10, 3);
add_filter('query', $breakPauseRead);
$was = $wpdb->suppress_errors(true);
NMMPRO_do_cron_job();
$wpdb->suppress_errors($was);
remove_filter('query', $breakPauseRead);
asok('failed pause read after the lock: the pass does no work', $GLOBALS['as_broke_pause'] && (int) get_option('nmmpro_autopay_scan_last_run') === 1 && NMMPRO_Util::cron_pass_running() === false, 'broke=' . var_export($GLOBALS['as_broke_pause'], true) . ' last_run=' . get_option('nmmpro_autopay_scan_last_run'));
asok('  and the cron lock is released', $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $cronLock)) === '1');
// A stale cache that says "not paused" cannot let a paused pass through.
$wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE `option_value` = '1'", 'nmmpro_background_paused'));
$notoptions = wp_cache_get('notoptions', 'options');
$notoptions = is_array($notoptions) ? $notoptions : array();
$notoptions['nmmpro_background_paused'] = true;
wp_cache_set('notoptions', $notoptions, 'options');
wp_cache_delete('nmmpro_background_paused', 'options');
$GLOBALS['as_after_lock'] = false; $GLOBALS['as_reread31'] = false;
$seeReread = function ($sql) {
	if (strpos($sql, 'GET_LOCK(') !== false && strpos($sql, "'nmm_cron_") !== false) { $GLOBALS['as_after_lock'] = true; }
	elseif ($GLOBALS['as_after_lock'] && strpos($sql, "'nmmpro_background_paused'") !== false && strpos($sql, 'SELECT `option_value`') !== false) { $GLOBALS['as_reread31'] = true; }
	return $sql;
};
add_filter('query', $seeReread);
NMMPRO_do_cron_job();
remove_filter('query', $seeReread);
remove_filter('pre_http_request', $offline31, 10);
delete_option('nmmpro_background_paused');
$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s", 'nmmpro_background_paused'));
wp_cache_delete('notoptions', 'options');
asok('paused in the table, "not paused" in the cache: no work', (int) get_option('nmmpro_autopay_scan_last_run') === 1 && NMMPRO_Util::cron_pass_running() === false, 'last_run=' . get_option('nmmpro_autopay_scan_last_run'));
asok('  the pass got past the cache and re-read the table after the lock', $GLOBALS['as_after_lock'] && $GLOBALS['as_reread31']);

// --- 32. no lease is settled or started before the migration records itself -----
// The column exists but the migration's option does not (it failed to save,
// or is not yet visible): order events do not bump the generation, so a
// generation check would prove nothing. Leases wait; expiry starts nothing.
$savedLeaseSchema32 = get_option('nmmpro_payment_lease_schema', null);
list($o44) = $make('_flag_missing_lease', 'on-hold', time());
$repo->set_status($o44, '1', 'completing');
$setStoredStatus($o44, 'wc-processing');                      // completed; only settlement is left
list($o45) = $make('_flag_missing_expiry', 'pending', $expiredAt);
as_set_lease_flag(null);
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
$newTick();
NMMPRO_Payment::cancel_expired_payments();
$row44 = as_row($o44); $row45 = as_row($o45); $stored45 = as_stored($o45);
as_set_lease_flag($savedLeaseSchema32);
asok('migration not recorded: a completion lease is not settled', $row44 === 'completing', 'row=' . $row44);
asok('  and expiry does not start a cancellation', $row45 === 'unpaid' && $stored45 === 'wc-pending', 'row=' . $row45 . ' stored=' . $stored45);
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
NMMPRO_Payment::resume_verified_orders();
$newTick();
NMMPRO_Payment::cancel_expired_payments();
asok('  once recorded, the lease settles paid', as_row($o44) === 'paid', 'row=' . as_row($o44));
asok('  and the expired order is cancelled', as_row($o45) === 'cancelled' && as_stored($o45) === 'wc-cancelled', 'row=' . as_row($o45) . ' stored=' . as_stored($o45));

// --- 33. saves after the approved cancellation are not refused ------------------
// Once the fence has passed the cancellation and WooCommerce is writing it,
// the order may be saved again inside the same update_status(): WooCommerce
// itself saves a second copy under HPOS (coupon usage bookkeeping on
// woocommerce_order_status_cancelled), and an integration may re-save the
// object it was handed. Both see the order already stored as cancelled; the
// fence must not read that as "the order changed" and refuse.
$as_meta = function ($orderId, $key) use ($wpdb) {
	$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	if (class_exists($util) && $util::custom_orders_table_usage_is_enabled()) {
		return $wpdb->get_var($wpdb->prepare('SELECT `meta_value` FROM `' . $wpdb->prefix . 'wc_orders_meta` WHERE `order_id` = %d AND `meta_key` = %s', $orderId, $key));
	}
	return $wpdb->get_var($wpdb->prepare("SELECT `meta_value` FROM `{$wpdb->postmeta}` WHERE `post_id` = %d AND `meta_key` = %s", $orderId, $key));
};
list($o46) = $make('_resave_copy', 'pending', $expiredAt);
list($o47) = $make('_resave_same', 'pending', $expiredAt);
$GLOBALS['as_resaved'] = array();
$resave = function ($orderId, $order) use ($o46, $o47) {
	if ((int) $orderId === $o46) {
		$copy = new WC_Order($o46);                 // a second copy, as WooCommerce's coupon bookkeeping does
		$copy->update_meta_data('_nmm_resaved', 'copy');
		$copy->save();
		$GLOBALS['as_resaved'][] = $o46;
	}
	if ((int) $orderId === $o47) {
		$order->update_meta_data('_nmm_resaved', 'same');  // the very object being cancelled
		$order->save();
		$GLOBALS['as_resaved'][] = $o47;
	}
};
add_action('woocommerce_order_status_cancelled', $resave, 10, 2);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_order_status_cancelled', $resave, 10);
foreach (array(array($o46, 'copy', 'a second copy'), array($o47, 'same', 'the same object')) as $case) {
	list($id, $meta, $label) = $case;
	$told = false; $refusedNote = false;
	foreach (wc_get_order_notes(array('order_id' => $id)) as $note) {
		if (strpos($note->content, 'unable to pay') !== false) { $told = true; }
		if (strpos($note->content, 'refused to save') !== false) { $refusedNote = true; }
	}
	asok('re-saved as ' . $label . ' after the cancellation: cancelled, settled', in_array($id, $GLOBALS['as_resaved'], true) && as_stored($id) === 'wc-cancelled' && as_row($id) === 'cancelled', 'stored=' . as_stored($id) . ' row=' . as_row($id));
	asok('  the re-save was stored, not refused', $as_meta($id, '_nmm_resaved') === $meta && !$refusedNote, 'meta=' . var_export($as_meta($id, '_nmm_resaved'), true) . ' refused-note=' . var_export($refusedNote, true));
	asok('  and the customer was told it was cancelled', $told);
}

// --- 34. only the canceller's own save approves; later saves stay checked -------
// (Codex round 6, M1.) Approval belongs to the save the canceller made, and a
// later cancelled save is allowed only while the order is stored as cancelled.
$recoverCancel = function () {
	NMMPRO_Compat::update_option('nmmpro_cancellation_cursor', 0, false);
	NMMPRO_Payment::recover_interrupted_cancellations();
};

// 34a. Before the canceller's own save is judged, a listener saves a cancelled
// copy (which must not approve anything) and then a payment is saved.
list($o48) = $make('_copy_then_paid', 'pending', $expiredAt);
$GLOBALS['as_fired48'] = false;
$copyThenPaid = function ($order) use ($o48, $setStoredStatus) {
	if ($order->get_id() !== $o48 || $order->get_status() !== 'cancelled' || $GLOBALS['as_fired48']) { return; }
	$GLOBALS['as_fired48'] = true;
	$copy = new WC_Order($o48);
	$copy->set_status('cancelled');
	$copy->save();
	$setStoredStatus($o48, 'wc-processing');   // another request saves the payment
};
add_action('woocommerce_before_order_object_save', $copyThenPaid, 1);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $copyThenPaid, 1);
asok('a copy cancelled, then paid, before our save: payment not overwritten', $GLOBALS['as_fired48'] && as_stored($o48) === 'wc-processing', 'stored=' . as_stored($o48));
asok('  its lease is left for recovery', as_row($o48) === 'cancelling', 'row=' . as_row($o48));
$recoverCancel();
asok('  which settles it paid', as_row($o48) === 'paid', 'row=' . as_row($o48));

// 34b. Our cancellation is written; during its status hooks another request
// saves the payment, then a stale copy saves 'cancelled' again.
list($o49) = $make('_paid_then_stale', 'pending', $expiredAt);
$GLOBALS['as_fired49'] = false;
$paidThenStale = function ($orderId) use ($o49, $setStoredStatus) {
	if ((int) $orderId !== $o49 || $GLOBALS['as_fired49']) { return; }
	$GLOBALS['as_fired49'] = true;
	$setStoredStatus($o49, 'wc-processing');   // another request saves the payment
	$stale = new WC_Order($o49);
	$stale->set_status('cancelled');            // a stale cancellation of it
	$stale->save();
};
add_action('woocommerce_order_status_cancelled', $paidThenStale, 20);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_order_status_cancelled', $paidThenStale, 20);
asok('paid after our cancellation, then a stale cancel: payment kept', $GLOBALS['as_fired49'] && as_stored($o49) === 'wc-processing', 'stored=' . as_stored($o49));
asok('  its lease is left for recovery', as_row($o49) === 'cancelling', 'row=' . as_row($o49));
$recoverCancel();
asok('  which settles it paid', as_row($o49) === 'paid', 'row=' . as_row($o49));

// 34c. A cancelled copy saved before our own save, and nothing else: the copy
// is judged but approves nothing, so our own save finds the order changed.
list($o50) = $make('_copy_only', 'pending', $expiredAt);
$GLOBALS['as_fired50'] = false;
$copyOnly = function ($order) use ($o50) {
	if ($order->get_id() !== $o50 || $order->get_status() !== 'cancelled' || $GLOBALS['as_fired50']) { return; }
	$GLOBALS['as_fired50'] = true;
	$copy = new WC_Order($o50);
	$copy->set_status('cancelled');
	$copy->save();
};
add_action('woocommerce_before_order_object_save', $copyOnly, 1);
$newTick();
NMMPRO_Payment::cancel_expired_payments();
remove_action('woocommerce_before_order_object_save', $copyOnly, 1);
asok('a cancelled copy saved first does not approve our own save', $GLOBALS['as_fired50'] && as_row($o50) === 'cancelling', 'row=' . as_row($o50) . ' stored=' . as_stored($o50));
$recoverCancel();
asok('  recovery settles it from the stored order', as_row($o50) === 'cancelled' && as_stored($o50) === 'wc-cancelled', 'row=' . as_row($o50) . ' stored=' . as_stored($o50));

// --- restore -------------------------------------------------------------------
if ($savedCovered === null) { delete_option('nmmpro_autopay_scan_covered_at'); } else { update_option('nmmpro_autopay_scan_covered_at', $savedCovered, false); }
if ($savedActive === null) { delete_option('nmmpro_autopay_scan_incomplete'); } else { update_option('nmmpro_autopay_scan_incomplete', $savedActive, false); }
foreach (array($o1, $o1ctl, $o2, $o3, $o3b, $o3c, $o3d, $o4, $o5, $o6, $o7, $o8, $o9a, $o9b, $o10, $o10ctl, $o11, $o12, $o13, $o14, $o15, $o16, $o17, $o18, $o19, $o19ctl, $o20, $o21, $o22, $o23, $o24, $o25, $o26, $o27, $o28, $o29, $o29c, $o30, $o31, $o32, $o33, $o36, $o37, $o38, $o39, $o40, $o41, $o42, $o43, $o44, $o45, $o46, $o47, $o48, $o49, $o50) as $id) { $wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $id)); }
delete_option('nmmpro_autopay_scan_cursor_unfenced');
delete_option('nmmpro_autopay_scan_retry_unfenced');
foreach (array('ETH|' . $a1, 'ETH|' . $a9a, 'ETH|' . $a9b, 'ETH|' . $a10, 'XMR|' . $a11) as $deferKey) { delete_option('nmmpro_defer_' . md5($deferKey)); }

echo $GLOBALS['as_ok']
	? "\nAUTOPAY-SAFETY CHECKS PASSED (" . $GLOBALS['as_count'] . ")\n"
	: "\nAUTOPAY-SAFETY CHECKS FAILED\n";
