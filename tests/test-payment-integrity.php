<?php
if (!isset($GLOBALS['wpdb']) || !function_exists('wc_create_order')) { echo "test-payment-integrity: skipped (requires WordPress/WooCommerce DB)\n"; return; }
$wpdb = $GLOBALS['wpdb'];
$GLOBALS['nmmpro_integrity_checks'] = 0;
function nmmpro_integrity_ok($ok, $label) {
    $GLOBALS['nmmpro_integrity_checks']++;
    if (!$ok) { throw new RuntimeException($label); }
    echo "ok: $label\n";
}
$prefix = 'integrity_' . wp_generate_password(12, false);
$coin = NMMPRO_Cryptocurrencies::get()['ETH'];
$repo = new NMMPRO_Payment_Repo();
$stg = new NMMPRO_Settings(get_option(NMMPRO_REDUX_ID));
$make = function ($suffix, $amount = '1') use ($wpdb, $prefix) {
    $order = wc_create_order(); $order->set_payment_method('nmm_gateway'); $order->set_status('on-hold'); $order->save();
    $address = $prefix . $suffix;
    $table = $wpdb->prefix . NMMPRO_PAYMENT_TABLE;
    $wpdb->query($wpdb->prepare("INSERT INTO `$table` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'ETH','unpaid',%d,%d,%s,0)", $address, time(), $order->get_id(), $amount));
    return array($order->get_id(), $address);
};
$status = function ($id) use ($wpdb) { return $wpdb->get_var($wpdb->prepare('SELECT status FROM `' . $wpdb->prefix . NMMPRO_PAYMENT_TABLE . '` WHERE order_id=%d', $id)); };
add_filter('nmmpro_autopay_percent', function () { return '1'; });
list($first, $address) = $make('_replay');
$tx = new NMMPRO_Transaction('1000000000000000000', 999, time(), $prefix . '_paid');
NMMPRO_Payment::process_address_transactions($coin, $address, array($tx), 3600);
nmmpro_integrity_ok(wc_get_order($first)->is_paid(), 'first payment credited');
for ($i = 0; $i < 250; $i++) { $stg->add_consumed_tx('ETH', $address, $prefix . '_later_' . $i); }
list($second) = $make('_replay');
NMMPRO_Payment::process_address_transactions($coin, $address, array($tx), 3600);
nmmpro_integrity_ok(!wc_get_order($second)->is_paid() && $status($second) === 'unpaid', 'credited transaction cannot pay a second order after 250 later hashes');
list($exact, $exactAddress) = $make('_exact');
NMMPRO_Payment::process_address_transactions($coin, $exactAddress, array(new NMMPRO_Transaction('999999999999999999', 999, time(), $prefix . '_short')), 3600);
nmmpro_integrity_ok(!wc_get_order($exact)->is_paid(), 'one wei short does not meet 100 percent');
NMMPRO_Payment::process_address_transactions($coin, $exactAddress, array(new NMMPRO_Transaction('1000000000000000000', 999, time(), $prefix . '_exact')), 3600);
nmmpro_integrity_ok(wc_get_order($exact)->is_paid(), 'exactly the requested amount completes');
list($crash, $crashAddress) = $make('_crash');
$throw = function ($id) use ($crash) { if ((int) $id === $crash) { throw new RuntimeException('Injected payment hook failure'); } };
add_action('woocommerce_pre_payment_complete', $throw);
try { NMMPRO_Payment::process_address_transactions($coin, $crashAddress, array(new NMMPRO_Transaction('1000000000000000000', 999, time(), $prefix . '_crash')), 3600); }
catch (RuntimeException $e) { if ($e->getMessage() !== 'Injected payment hook failure') { throw $e; } }
remove_action('woocommerce_pre_payment_complete', $throw);
nmmpro_integrity_ok($status($crash) === 'completing', 'interrupted completion remains recoverable');
NMMPRO_Payment::resume_verified_orders();
nmmpro_integrity_ok(wc_get_order($crash)->is_paid() && $status($crash) === 'paid', 'next recovery pass completes the paid order');
list($fail, $failAddress) = $make('_writefail');
NMMPRO_Consumed_Repo::prepare('ETH', $failAddress);
$deny = function ($sql) { return strpos($sql, 'INSERT INTO `') === 0 && strpos($sql, NMMPRO_Consumed_Repo::TABLE) !== false ? 'INVALID INJECTED LEDGER WRITE' : $sql; };
add_filter('query', $deny);
$was = $wpdb->suppress_errors(true);
$failedTx = new NMMPRO_Transaction('1000000000000000000', 999, time(), $prefix . '_writefail');
NMMPRO_Payment::process_address_transactions($coin, $failAddress, array($failedTx), 3600);
remove_filter('query', $deny); $wpdb->suppress_errors($was);
nmmpro_integrity_ok($status($fail) === 'unpaid' && !wc_get_order($fail)->is_paid(), 'ledger write failure rolls back the order claim');
NMMPRO_Payment::process_address_transactions($coin, $failAddress, array($failedTx), 3600);
nmmpro_integrity_ok(wc_get_order($fail)->is_paid(), 'failed ledger write can retry');
// Retained legacy options are imported without losing their original records.
$legacyAddress = $prefix . '_legacy';
update_option('nmmpro_ETH_transactions_consumed_for_' . $legacyAddress, array($prefix . '_legacyhash'), false);
nmmpro_integrity_ok(NMMPRO_Consumed_Repo::contains('ETH', $legacyAddress, $prefix . '_legacyhash'), 'legacy consumed option imports');
list($cancelled, $cancelledAddress) = $make('_cancelled');
$cancelledOrder = wc_get_order($cancelled); $cancelledOrder->set_status('cancelled'); $cancelledOrder->save();
NMMPRO_Payment::process_address_transactions($coin, $cancelledAddress, array(new NMMPRO_Transaction('1000000000000000000', 999, time(), $prefix . '_cancelled')), 3600);
nmmpro_integrity_ok(wc_get_order($cancelled)->has_status('cancelled') && $status($cancelled) === 'review', 'cancelled order is held for reconciliation');
// A full batch of failing hooks must not starve the next verified order.
NMMPRO_Compat::update_option('nmmpro_completion_cursor', 0, false);
$blocked = array();
for ($i = 0; $i < 26; $i++) {
    list($queuedId) = $make('_queue_' . $i);
    $repo->set_status($queuedId, '1', 'completing');
    if ($i < 25) { $blocked[] = $queuedId; } else { $laterId = $queuedId; }
}
$blockHooks = function ($id) use ($blocked) { if (in_array((int) $id, $blocked, true)) { throw new RuntimeException('Injected stuck completion'); } };
add_action('woocommerce_pre_payment_complete', $blockHooks);
NMMPRO_Payment::resume_verified_orders();
NMMPRO_Payment::resume_verified_orders();
remove_action('woocommerce_pre_payment_complete', $blockHooks);
nmmpro_integrity_ok(wc_get_order($laterId)->is_paid(), 'later verified order completes despite a full batch of failing hooks');
foreach ($blocked as $id) { $repo->set_status($id, '1', 'review'); }
echo 'PAYMENT-INTEGRITY CHECKS PASSED (' . $GLOBALS['nmmpro_integrity_checks'] . ")\n";
