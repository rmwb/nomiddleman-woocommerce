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
// Two concurrent requests on the SAME site share the table prefix; a fresh wpdb
// does not initialize it, so mirror the main connection's so the per-site lock
// name matches (the lock is scoped by DB_NAME + prefix).
$wpdb2->prefix = $main->prefix;

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

// Multisite scoping: the SAME order id on a DIFFERENT site (different table
// prefix) must NOT contend - those are unrelated orders that merely share
// DB_NAME and an id. The main site holds order A; a second site's request for
// "order A" should acquire freely.
$mainHold = NMM_Util::acquire_order_init_lock($orderA, 0);
$wpdb2->prefix = $main->prefix . 's2_'; // pretend a second network site
$GLOBALS['wpdb'] = $wpdb2;
$site2 = NMM_Util::acquire_order_init_lock($orderA, 0);
if ($site2 === '1') { NMM_Util::release_order_init_lock($orderA); }
$GLOBALS['wpdb'] = $main;
$wpdb2->prefix = $main->prefix; // restore for the remaining checks
lok('same order id on a DIFFERENT site is free', $mainHold === '1' && $site2 === '1', "$mainHold,$site2");
if ($mainHold === '1') { NMM_Util::release_order_init_lock($orderA); }

// Cron lock: scoped per site the same way - a second subsite's cron must not
// contend with the main site's (each subsite has its own tables and backlog),
// and the hashed name can never truncate into a collision.
$mainCron = NMM_Util::cron_lock_name();
$wpdb2->prefix = $main->prefix . 's2_';
$GLOBALS['wpdb'] = $wpdb2;
$site2Cron = NMM_Util::cron_lock_name();
$GLOBALS['wpdb'] = $main;
$wpdb2->prefix = $main->prefix;
lok('cron lock differs across subsites',        $mainCron !== $site2Cron, "$mainCron vs $site2Cron");
lok('cron lock stable for the same site',       $mainCron === NMM_Util::cron_lock_name());
lok('cron lock stays under 64 chars',           strlen($mainCron) < 64, 'len=' . strlen($mainCron));

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

	// A failed attempt can leave an unpaid row behind (inserted, then threw before
	// wallet_address was persisted). A retry must clear it, or its own insert would
	// be silently rejected by UNIQUE(order_id, order_amount) and it would display an
	// address nobody monitors. Paid rows must survive.
	$repo->insert('addr_stale_attempt', 'BTC', $orderA, '0.00100000', 'unpaid');
	$repo->delete_unpaid_for_order($orderA);
	lok('stale unpaid row from a failed attempt cleared', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$pt` WHERE order_id=%d", $orderA)) === 0);
	$repo->insert('addr_retry', 'BTC', $orderA, '0.00100000', 'unpaid'); // retry now inserts cleanly
	$retryAddr = $wpdb->get_var($wpdb->prepare("SELECT address FROM `$pt` WHERE order_id=%d", $orderA));
	lok('retry row is the monitored one',           $retryAddr === 'addr_retry', 'addr=' . $retryAddr);

	// A settled (paid) row is a real record and must never be cleared.
	$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $orderA));
	$repo->insert('addr_paid', 'BTC', $orderA, '0.00200000', 'paid');
	$repo->delete_unpaid_for_order($orderA);
	lok('paid row is never cleared',                (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$pt` WHERE order_id=%d AND status='paid'", $orderA)) === 1);
	$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $orderA));

	// insert() must REPORT whether the row landed. The row is the monitoring, so
	// checkout uses this return value to decide whether it may show the address
	// at all: a silent failure here would send the customer to an address
	// Autopay never sweeps, and the funds would never credit the order.
	lok('insert() reports success',                 $repo->insert('addr_ok', 'BTC', $orderA, '0.00300000', 'unpaid') === true);
	$wpdb->suppress_errors(true);
	$dupe = $repo->insert('addr_dupe', 'BTC', $orderA, '0.00300000', 'unpaid'); // UNIQUE(order_id, order_amount)
	$wpdb->suppress_errors(false);
	lok('insert() reports a rejected duplicate',    $dupe === false, 'got=' . var_export($dupe, true));
	lok('the rejected address was never recorded',  (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$pt` WHERE address=%s", 'addr_dupe')) === 0);
	$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE order_id=%d", $orderA));
}

// While another worker holds the lock mid-initialization, a duplicate checkout
// submission naming a DIFFERENT coin must bail 'busy' and write no coin meta at
// all. Committing the coin before taking the lock (as process_payment used to)
// let B relabel an order whose address, amount and monitoring row A was already
// allocating for A's coin - the customer then gets instructions and a QR for a
// currency the order was never priced in. Must run while connection 2 is still
// open (it is closed just below), since that is what makes the lock contend.
if (class_exists('NMM_Gateway') && function_exists('wc_create_order')) {
	$gwRace = new NMM_Gateway();
	$contended = wc_create_order();
	$contended->update_meta_data('nmm_chosen_crypto_id', 'BTC');
	$contended->save();
	$contendedId = $contended->get_id();

	$GLOBALS['wpdb'] = $wpdb2;                                   // stand-in worker A
	$holderLock = NMM_Util::acquire_order_init_lock($contendedId, 0);
	$GLOBALS['wpdb'] = $main;

	$busyResult = $gwRace->initialize_order_payment($contendedId, 'ETH');
	$afterBusy = wc_get_order($contendedId);
	$afterBusy->read_meta_data(true);
	lok('contended duplicate bails busy', $holderLock === '1' && isset($busyResult['outcome']) && $busyResult['outcome'] === 'busy',
		'lock=' . var_export($holderLock, true) . ' outcome=' . (isset($busyResult['outcome']) ? $busyResult['outcome'] : '?'));
	lok('contended duplicate wrote no coin meta', $afterBusy->get_meta('nmm_chosen_crypto_id') === 'BTC',
		'got=' . $afterBusy->get_meta('nmm_chosen_crypto_id'));
	lok('contended duplicate allocated no address', empty($afterBusy->get_meta('wallet_address')),
		'got=' . var_export($afterBusy->get_meta('wallet_address'), true));

	$GLOBALS['wpdb'] = $wpdb2;
	if ($holderLock === '1') { NMM_Util::release_order_init_lock($contendedId); }
	$GLOBALS['wpdb'] = $main;
	$contended->delete(true);
}

$wpdb2->close();

// Post-lock recheck must see a wallet_address committed by another worker after
// this request first read the order (which caches an empty value). The gateway
// forces a fresh meta read under the lock; assert that a forced re-read reflects
// a value written behind a previously-read order object's back.
if (function_exists('wc_create_order')) {
	$o = wc_create_order();
	$o->save();
	$oid = $o->get_id();

	$staleOrder = wc_get_order($oid);
	$staleOrder->get_meta('wallet_address'); // populate this object's meta cache (empty)

	// Simulate the lock holder committing the address via a separate load.
	$holder = wc_get_order($oid);
	$holder->update_meta_data('wallet_address', 'ADDR_FROM_HOLDER');
	$holder->save();

	$staleOrder->read_meta_data(true); // exactly what the gateway does post-lock
	lok('forced re-read sees the committed address', $staleOrder->get_meta('wallet_address') === 'ADDR_FROM_HOLDER', 'got=' . $staleOrder->get_meta('wallet_address'));

	$holder->delete(true);
}

// The chosen coin must be committed UNDER the init lock, not before it. Two
// submissions of the same order carrying different coins used to race: B could
// overwrite nmm_chosen_crypto_id after A had already read it, leaving the order
// labelled with B's coin while the address, amount and monitoring row were all
// allocated for A's. Emails and the QR then advertise the wrong currency.
if (class_exists('NMM_Gateway') && function_exists('wc_create_order')) {
	$gw = new NMM_Gateway();

	// (1) An order already initialized: a late duplicate submission naming a
	// different coin must return 'already' and leave the winner's coin alone.
	$won = wc_create_order();
	$won->update_meta_data('nmm_chosen_crypto_id', 'BTC');
	$won->update_meta_data('wallet_address', 'ADDR_ALREADY_COMMITTED');
	$won->save();
	$wonId = $won->get_id();

	$lateResult = $gw->initialize_order_payment($wonId, 'ETH');
	$reread = wc_get_order($wonId);
	$reread->read_meta_data(true);
	lok('late duplicate with another coin: outcome already', isset($lateResult['outcome']) && $lateResult['outcome'] === 'already',
		'got=' . (isset($lateResult['outcome']) ? $lateResult['outcome'] : '?'));
	lok('late duplicate does not relabel the coin', $reread->get_meta('nmm_chosen_crypto_id') === 'BTC',
		'got=' . $reread->get_meta('nmm_chosen_crypto_id'));
	$won->delete(true);

}

// ---------------------------------------------------------------------
// FUND SAFETY: a FAILED order that never got an address must stay re-payable.
//
// When initialization throws - an exchange-rate API blip, the Monero RPC down,
// the carousel exhausted - this gateway's own catch marks the order wc-failed.
// WooCommerce supports paying for a failed order (its default payable statuses
// are pending AND failed, and the order-pay flow re-checks stock for exactly
// this case). A blanket "must be awaiting payment" guard therefore killed the
// retry: the customer was left holding a dead order, saw nothing, and the sale
// was gone. order_can_initialize() carves out precisely that state.
//
// The carve-out is deliberately narrow: a failed order that DOES carry an
// address stays refused, because that address may since have been recycled to
// another order, and re-displaying it would credit a stranger's payment.
// Cancelled and refunded are refused outright - they are not retry states.
// ---------------------------------------------------------------------
if (class_exists('NMM_Gateway') && function_exists('wc_create_order') && class_exists('NMM_Carousel_Repo')) {
	$gwFailed = new NMM_Gateway();

	// Give the configured coin a usable carousel seat so a permitted retry can
	// actually run to completion; the harness DB persists between runs, so the
	// merchant's real buffer and index are captured and put back below.
	$carRepo      = new NMM_Carousel_Repo();
	$btcBufOrig   = $carRepo->get_buffer('BTC');
	$btcIndexOrig = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT `current_index` FROM `{$wpdb->prefix}" . NMM_CAROUSEL_TABLE . "` WHERE `cryptocurrency` = %s", 'BTC'));
	$carRepo->set_buffer('BTC', array('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'));

	// (1) failed + NO address: the retry must be let through.
	$retryOrder = wc_create_order();
	$retryOrder->update_meta_data('nmm_chosen_crypto_id', 'BTC');
	$retryOrder->set_total('10.00');
	$retryOrder->save();
	$retryOrder->update_status('wc-failed');
	$retryId = $retryOrder->get_id();
	$retryResult = $gwFailed->initialize_order_payment($retryId);
	$retryOutcome = isset($retryResult['outcome']) ? $retryResult['outcome'] : '?';
	$retryMessage = isset($retryResult['message']) ? $retryResult['message'] : '';

	// The load-bearing assertion: whatever else happens, the status guard must
	// not have refused the order. 'not_payable' is the pre-fix behaviour.
	lok('failed order with no address is NOT refused', $retryOutcome !== 'not_payable',
		'outcome=' . $retryOutcome);

	// Which success looks like depends on what this harness can complete. With
	// the seat set above, allocation runs end to end and the outcome is
	// 'initialized' - that is the variant asserted here, and the one observed.
	// The 'failed' arm is kept as an accepted alternative rather than dropped:
	// initialization also reaches out to a price API, so on a host with no
	// network (or an expired price transient) the same permitted retry ends in
	// 'failed' with a customer-facing message. Both prove the guard let the
	// order through; only 'not_payable' means it did not.
	lok('failed order with no address is re-initialized',
		$retryOutcome === 'initialized' || ($retryOutcome === 'failed' && $retryMessage !== ''),
		'outcome=' . $retryOutcome . ' message=' . substr($retryMessage, 0, 40));

	$retryAfter = wc_get_order($retryId);
	$retryAfter->read_meta_data(true);
	if ($retryOutcome === 'initialized') {
		lok('the permitted retry actually allocated an address', !empty($retryAfter->get_meta('wallet_address')),
			'addr=' . var_export($retryAfter->get_meta('wallet_address'), true));
		lok('and the retried order is awaiting payment again',   $retryAfter->has_status('on-hold'),
			'status=' . $retryAfter->get_status());
	}
	$retryOrder->delete(true);

	// (2) failed + an address ALREADY on it: never re-initialized. That address
	// may since have been recycled, so a second display could credit someone
	// else's order. Either the status guard ('not_payable') or the already-
	// initialized fast path ('already') may answer - what must never happen is
	// a NEW address being allocated.
	$staleAddrOrder = wc_create_order();
	$staleAddrOrder->update_meta_data('nmm_chosen_crypto_id', 'BTC');
	$staleAddrOrder->update_meta_data('wallet_address', 'ADDR_MAY_BE_RECYCLED');
	$staleAddrOrder->set_total('10.00');
	$staleAddrOrder->save();
	$staleAddrOrder->update_status('wc-failed');
	$staleAddrId = $staleAddrOrder->get_id();
	$staleResult = $gwFailed->initialize_order_payment($staleAddrId);
	$staleOutcome = isset($staleResult['outcome']) ? $staleResult['outcome'] : '?';
	$staleAfter = wc_get_order($staleAddrId);
	$staleAfter->read_meta_data(true);
	lok('failed order WITH an address is not re-initialized',
		$staleOutcome === 'not_payable' || $staleOutcome === 'already', 'outcome=' . $staleOutcome);
	lok('and no new address was allocated for it',
		$staleAfter->get_meta('wallet_address') === 'ADDR_MAY_BE_RECYCLED',
		'addr=' . var_export($staleAfter->get_meta('wallet_address'), true));
	lok('and it was not revived to on-hold', $staleAfter->has_status('failed'),
		'status=' . $staleAfter->get_status());
	$staleAddrOrder->delete(true);

	// (3) cancelled and refunded are dead, not retryable - no address, ever.
	foreach (array('wc-cancelled' => 'cancelled', 'wc-refunded' => 'refunded') as $wcStatus => $label) {
		$deadOrder = wc_create_order();
		$deadOrder->update_meta_data('nmm_chosen_crypto_id', 'BTC');
		$deadOrder->set_total('10.00');
		$deadOrder->save();
		$deadOrder->update_status($wcStatus);
		$deadId = $deadOrder->get_id();
		$deadResult = $gwFailed->initialize_order_payment($deadId);
		$deadOutcome = isset($deadResult['outcome']) ? $deadResult['outcome'] : '?';
		$deadAfter = wc_get_order($deadId);
		$deadAfter->read_meta_data(true);
		lok('a ' . $label . ' order is refused as not_payable', $deadOutcome === 'not_payable',
			'outcome=' . $deadOutcome);
		lok('a ' . $label . ' order gets no address at all', empty($deadAfter->get_meta('wallet_address')),
			'addr=' . var_export($deadAfter->get_meta('wallet_address'), true));
		$deadOrder->delete(true);
	}

	// Put the merchant's carousel state back exactly as found.
	$carRepo->set_buffer('BTC', is_array($btcBufOrig) ? $btcBufOrig : array());
	$wpdb->query($wpdb->prepare(
		"UPDATE `{$wpdb->prefix}" . NMM_CAROUSEL_TABLE . "` SET `current_index` = %d WHERE `cryptocurrency` = %s",
		$btcIndexOrig, 'BTC'));
	lok('carousel buffer restored', $carRepo->get_buffer('BTC') === (is_array($btcBufOrig) ? $btcBufOrig : array()));
}

// A deleted (or never-existing) order must not fatal the thank-you page:
// wc_get_order() returns false, and calling get_meta() on it would throw an
// Error that the page's \Exception handlers do not catch (a 500 for the
// customer). The gateway must return silently, rendering nothing - matching
// how WooCommerce's own templates behave when an order is missing.
if (class_exists('NMM_Gateway') && function_exists('WC')) {
	$gw = new NMM_Gateway();
	ob_start();
	$ghostThrew = false;
	try {
		$gw->thank_you_page(999999999);
	} catch (\Throwable $t) {
		$ghostThrew = true;
	}
	$ghostOut = ob_get_clean();
	lok('missing order: thank-you page does not throw', !$ghostThrew);
	lok('missing order: renders no payment html',       trim($ghostOut) === '', 'out=' . substr(trim($ghostOut), 0, 60));
}

echo $GLOBALS['ol_ok'] ? "\nORDER-INIT-LOCK CHECKS PASSED\n" : "\nORDER-INIT-LOCK CHECKS FAILED\n";
