<?php
/**
 * Live-DB test: the per-order initialization lock (NMM_Util::acquire/release_
 * order_init_lock) serializes two concurrent first loads of the thank-you page
 * so they cannot both allocate a payment address for the same order, and is
 * scoped per order so distinct orders never block each other. Also checks that
 * the payment table's UNIQUE(order_id, order_amount) constraint keeps exactly
 * one payment record per order even if a second worker slipped through.
 * Requires WordPress + a database. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-order-init-lock.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
	echo "test-order-init-lock: skipped (needs WordPress + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$pt = $wpdb->prefix . NMM_PAYMENT_TABLE;

$GLOBALS['ol_ok'] = true;
function lok($label, $cond, $extra = '') { printf("%-56s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['ol_ok'] = false; } }

$orderA = 8100001;
$orderB = 8100002;

// A second, independent DB connection to stand in for a concurrent request:
// GET_LOCK is owned per-connection, so a lock held on $wpdb2 blocks the main
// connection exactly as two PHP workers would contend.
$wpdb2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb2->suppress_errors(true);
$main = $GLOBALS['wpdb'];

// Worker A (connection 2) acquires the init lock for order A.
$GLOBALS['wpdb'] = $wpdb2;
$aHeld = NMM_Util::acquire_order_init_lock($orderA, 0);
$GLOBALS['wpdb'] = $main;
lok('worker A acquires order-init lock',        $aHeld === '1', 'got=' . var_export($aHeld, true));

// Worker B (main connection) must NOT get the same order's lock (0s timeout).
$bSameOrder = NMM_Util::acquire_order_init_lock($orderA, 0);
lok('worker B blocked on the SAME order',       $bSameOrder === '0', 'got=' . var_export($bSameOrder, true));

// A different order is independently lockable - scoping works, no false sharing.
$bOtherOrder = NMM_Util::acquire_order_init_lock($orderB, 0);
lok('a DIFFERENT order is not blocked',         $bOtherOrder === '1', 'got=' . var_export($bOtherOrder, true));
if ($bOtherOrder === '1') { NMM_Util::release_order_init_lock($orderB); }

// A releases; the same order becomes lockable again.
$GLOBALS['wpdb'] = $wpdb2;
NMM_Util::release_order_init_lock($orderA);
$GLOBALS['wpdb'] = $main;
$bAfterRelease = NMM_Util::acquire_order_init_lock($orderA, 0);
lok('same order lockable after A releases',     $bAfterRelease === '1', 'got=' . var_export($bAfterRelease, true));
if ($bAfterRelease === '1') { NMM_Util::release_order_init_lock($orderA); }

// The lock name must be order-specific even if DB_NAME is long: two different
// orders must never collide on one truncated 64-char lock name. Prove it by
// holding both at once on connection 2.
$GLOBALS['wpdb'] = $wpdb2;
$h1 = NMM_Util::acquire_order_init_lock($orderA, 0);
$h2 = NMM_Util::acquire_order_init_lock($orderB, 0);
lok('two distinct orders hold locks at once',   $h1 === '1' && $h2 === '1', "$h1,$h2");
NMM_Util::release_order_init_lock($orderA);
NMM_Util::release_order_init_lock($orderB);
$GLOBALS['wpdb'] = $main;

// Even if two workers both reached the insert, UNIQUE(order_id, order_amount)
// permits only one payment record for the order - so there can never be two
// competing monitored rows for one order+amount.
if (defined('NMM_PAYMENT_TABLE')) {
	$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $orderA));
	$repo = new NMM_Payment_Repo();
	$repo->insert('addr_worker_A', 'BTC', $orderA, '0.00100000', 'unpaid');
	$wpdb->suppress_errors(true);
	$repo->insert('addr_worker_B', 'BTC', $orderA, '0.00100000', 'unpaid'); // duplicate order+amount
	$wpdb->suppress_errors(false);
	$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$pt` WHERE order_id=%d AND order_amount='0.00100000'", $orderA));
	$addr = $wpdb->get_var($wpdb->prepare("SELECT address FROM `$pt` WHERE order_id=%d", $orderA));
	lok('exactly one payment row for the order',    $count === 1, 'count=' . $count);
	lok('the first worker\'s address is the one kept', $addr === 'addr_worker_A', 'addr=' . $addr);
	$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $orderA));
}

$wpdb2->close();

echo $GLOBALS['ol_ok'] ? "\nORDER-INIT-LOCK CHECKS PASSED\n" : "\nORDER-INIT-LOCK CHECKS FAILED\n";
