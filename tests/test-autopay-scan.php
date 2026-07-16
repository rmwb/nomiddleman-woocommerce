<?php
/**
 * Live-DB test: NMM_Payment::check_all_addresses_for_matching_payment() must
 * bound the addresses it checks per cron tick, do at most one Monero wallet-RPC
 * fetch per tick regardless of how many XMR addresses are pending, and still
 * cover every address within a few ticks via a persisted fair cursor. Requires
 * WordPress + WooCommerce + a database. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-autopay-scan.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !function_exists('wc_create_order')) {
	echo "test-autopay-scan: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$pt = $wpdb->prefix . NMM_PAYMENT_TABLE;
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');

$GLOBALS['as_ok'] = true;
function sok($label, $cond, $extra = '') { printf("%-56s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['as_ok'] = false; } }

// scan_plan (pure): budget/window sizing. Small store stays at baseline with the
// window nudged one tick; a large backlog raises the budget AND widens the
// matching window by the sweep period so a payment can't age out unseen between
// two far-apart visits (3h lifetime, ~1 tick/min, sweep target = lifetime/2).
$dayCancel = 24 * 3600; // default cancellation window; lifetime bound governs
$planSmall = NMM_Payment::scan_plan(50, 50, 3 * 3600, $dayCancel);
sok('scan_plan small: budget stays baseline',   $planSmall['budget'] === 50, 'budget=' . $planSmall['budget']);
sok('scan_plan small: window = base + 1 tick',  $planSmall['effective_lifetime'] === 10860, 'eff=' . $planSmall['effective_lifetime']);
$planBig = NMM_Payment::scan_plan(9000, 50, 3 * 3600, $dayCancel);
sok('scan_plan big: budget scales up',          $planBig['budget'] === 100, 'budget=' . $planBig['budget']);
sok('scan_plan big: window widened by sweep',   $planBig['effective_lifetime'] === 16200, 'eff=' . $planBig['effective_lifetime']);
sok('scan_plan big: window exceeds base life',  $planBig['effective_lifetime'] > 3 * 3600);
// A short (1h) cancellation window tightens the sweep so no order can expire
// before its first check: target = 30min => 30 ticks => budget = ceil(9000/30) = 300.
$planCancel = NMM_Payment::scan_plan(9000, 50, 3 * 3600, 3600);
sok('scan_plan: short cancel window raises budget', $planCancel['budget'] === 300, 'budget=' . $planCancel['budget']);
sok('scan_plan: sweep window <= half cancel window', ($planCancel['effective_lifetime'] - 3 * 3600) <= 1800, 'sweep=' . ($planCancel['effective_lifetime'] - 3 * 3600));

// Seed N unpaid Monero addresses. XMR RPC is unconfigured in CI, so the batch
// fetch returns an error (no network) and no order is ever matched - the unpaid
// set stays at N across ticks, which is exactly what we want for a coverage test.
$N = 100;
$budget = 30;
$rows = array();
for ($i = 0; $i < $N; $i++) {
	$rows[] = $wpdb->prepare("('xmraddr_%03d','XMR','unpaid',%d,%d,'0.00100000',0)", $i, time(), 700000 + $i);
}
$wpdb->query("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES " . implode(',', $rows));

// Make the Monero batch fetch "succeed" (empty) via the seam so the budget/cursor
// assertions below are not perturbed by failed-fetch retries (exercised separately).
add_filter('nmm_xmr_account_transactions', function () { return array('result' => 'success', 'by_address' => array()); });

// DB retrieval must itself be bounded: the count is a single scalar and each
// keyset page returns only its LIMIT, never the whole backlog.
$repo0 = new NMM_Payment_Repo();
sok('count_distinct is a scalar over backlog', $repo0->count_distinct_unpaid_addresses() === $N, 'count=' . $repo0->count_distinct_unpaid_addresses());
sok('keyset after-cursor returns only LIMIT',  count($repo0->get_unpaid_addresses_after('', '', $budget)) === $budget, 'rows=' . count($repo0->get_unpaid_addresses_after('', '', $budget)));
sok('keyset from-start returns only LIMIT',    count($repo0->get_unpaid_addresses_from_start(7)) === 7);
$afterMid = $repo0->get_unpaid_addresses_after('XMR', 'xmraddr_049', $budget);
sok('keyset resumes strictly after the cursor', isset($afterMid[0]) && $afterMid[0]['address'] === 'xmraddr_050', 'first=' . (isset($afterMid[0]) ? $afterMid[0]['address'] : '(none)'));

add_filter('nmm_autopay_scan_budget', function () use ($budget) { return $budget; });

$GLOBALS['as_checked'] = array();
$GLOBALS['as_fetches'] = 0;
add_action('nmm_autopay_address_checked', function ($cryptoId, $address) { $GLOBALS['as_checked'][] = $address; }, 10, 2);
add_action('nmm_xmr_account_fetch', function () { $GLOBALS['as_fetches']++; });

$lifetime = 3 * 60 * 60;
$ticksNeeded = (int) ceil($N / $budget); // 4
$perTickCounts = array();
$perTickFetches = array();
$covered = array();
$firstAddrPerTick = array();

for ($t = 0; $t < $ticksNeeded; $t++) {
	$GLOBALS['as_checked'] = array();
	$GLOBALS['as_fetches'] = 0;

	NMM_Payment::check_all_addresses_for_matching_payment($lifetime);

	$perTickCounts[] = count($GLOBALS['as_checked']);
	$perTickFetches[] = $GLOBALS['as_fetches'];
	$firstAddrPerTick[] = isset($GLOBALS['as_checked'][0]) ? $GLOBALS['as_checked'][0] : '(none)';
	foreach ($GLOBALS['as_checked'] as $a) { $covered[$a] = true; }
}

sok('each tick checks exactly the budget',        array_unique($perTickCounts) === array($budget), 'counts=' . implode(',', $perTickCounts));
sok('each tick does exactly ONE XMR fetch',       array_unique($perTickFetches) === array(1), 'fetches=' . implode(',', $perTickFetches));
sok('cursor advances (ticks start elsewhere)',    count(array_unique($firstAddrPerTick)) === $ticksNeeded, 'firsts=' . implode(',', $firstAddrPerTick));
sok('all N addresses covered within ceil(N/b)',   count($covered) === $N, 'covered=' . count($covered) . '/' . $N);
sok('sweep cursor persisted',                     get_option('nmm_autopay_scan_cursor', '') !== '');

// Budget larger than the backlog: single tick covers everything, still one fetch.
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
$small = array();
for ($i = 0; $i < 5; $i++) { $small[] = $wpdb->prepare("('xmrsmall_%d','XMR','unpaid',%d,%d,'0.00100000',0)", $i, time(), 710000 + $i); }
$wpdb->query("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES " . implode(',', $small));
$GLOBALS['as_checked'] = array();
$GLOBALS['as_fetches'] = 0;
NMM_Payment::check_all_addresses_for_matching_payment($lifetime);
sok('small backlog: checks all in one tick',      count($GLOBALS['as_checked']) === 5, 'checked=' . count($GLOBALS['as_checked']));
sok('small backlog: still one XMR fetch',         $GLOBALS['as_fetches'] === 1, 'fetches=' . $GLOBALS['as_fetches']);

// Empty backlog: no fetch, no work, no crash.
$wpdb->query("DELETE FROM `$pt`");
$GLOBALS['as_checked'] = array();
$GLOBALS['as_fetches'] = 0;
NMM_Payment::check_all_addresses_for_matching_payment($lifetime);
sok('empty backlog: no addresses checked',        count($GLOBALS['as_checked']) === 0);
sok('empty backlog: no XMR fetch',                $GLOBALS['as_fetches'] === 0);

// Cursor resilience: when the row the cursor points at is removed between ticks
// (its order got paid), the sweep must resume at the NEXT address, not restart at
// the top. Otherwise removing the last-scanned row every tick would rescan the
// beginning forever and starve later addresses past the matching window.
remove_all_filters('nmm_autopay_scan_budget');
add_filter('nmm_autopay_scan_budget', function () { return 3; });
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
for ($i = 0; $i < 9; $i++) {
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrc_%d', $i), time(), 730000 + $i));
}

$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600); // tick 1 -> xmrc_0,1,2
$tick1 = $GLOBALS['as_checked'];
$lastScanned = end($tick1);
$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE address=%s", $lastScanned)); // as if it got paid

$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600); // tick 2
$tick2 = $GLOBALS['as_checked'];
sok('resumes after a removed cursor row',       isset($tick2[0]) && $tick2[0] === 'xmrc_3', 'tick2 first=' . (isset($tick2[0]) ? $tick2[0] : '(none)') . ', removed=' . $lastScanned);
sok('does not restart at the top',              !in_array('xmrc_0', $tick2, true), 'tick2=' . implode(',', $tick2));

// Adaptive budget: when the backlog is large enough that a full sweep at the
// baseline would exceed the matching lifetime, the budget rises so every address
// is still re-checked within the lifetime - otherwise a payment arriving just
// after its address was scanned would be older than the lifetime when revisited
// and rejected forever. Baseline 10, lifetime 600s -> sweepTicks = floor(300/60)
// = 5, so budget = max(10, ceil(100/5)) = 20.
remove_all_filters('nmm_autopay_scan_budget');
add_filter('nmm_autopay_scan_budget', function () { return 10; });
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
$bigN = 100;
$bigRows = array();
for ($i = 0; $i < $bigN; $i++) { $bigRows[] = $wpdb->prepare("('xmrbig_%03d','XMR','unpaid',%d,%d,'0.00100000',0)", $i, time(), 720000 + $i); }
$wpdb->query("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES " . implode(',', $bigRows));

$shortLifetime = 600; // 10 minutes
$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
sok('budget rises above baseline to cover lifetime', count($GLOBALS['as_checked']) === 20, 'per-tick=' . count($GLOBALS['as_checked']) . ' (baseline 10)');

$adaptiveCovered = array();
foreach ($GLOBALS['as_checked'] as $a) { $adaptiveCovered[$a] = true; }
for ($t = 1; $t < 5; $t++) {
	$GLOBALS['as_checked'] = array();
	NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
	foreach ($GLOBALS['as_checked'] as $a) { $adaptiveCovered[$a] = true; }
}
sok('adaptive: full sweep within the lifetime window', count($adaptiveCovered) === $bigN, 'covered=' . count($adaptiveCovered) . '/' . $bigN);

// Failed fetches are retried on the NEXT tick, not left until a whole sweep
// later. With the Monero batch fetch forced to error, addresses checked this
// tick are retained in a bounded retry set and re-checked immediately next tick.
remove_all_filters('nmm_xmr_account_transactions');
add_filter('nmm_xmr_account_transactions', function () { return array('result' => 'error'); });
remove_all_filters('nmm_autopay_scan_budget');
add_filter('nmm_autopay_scan_budget', function () { return 3; });
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
for ($i = 0; $i < 9; $i++) {
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrf_%d', $i), time(), 740000 + $i));
}

$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600); // tick 1: xmrf_0,1,2 all fail
sok('failed fetches recorded in retry set',     count(get_option('nmm_autopay_scan_retry', array())) === 3, 'retry=' . count(get_option('nmm_autopay_scan_retry', array())));

$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600); // tick 2: retries 0,1,2 + sweeps 3,4,5
$tick2 = $GLOBALS['as_checked'];
sok('failed keys re-checked the very next tick', in_array('xmrf_0', $tick2, true) && in_array('xmrf_1', $tick2, true) && in_array('xmrf_2', $tick2, true), 'tick2=' . implode(',', $tick2));
sok('retry set stays bounded',                  count(get_option('nmm_autopay_scan_retry', array())) <= 9);

// A retry key whose payment is no longer unpaid (paid/cancelled/deleted while the
// endpoint stays down) must be dropped, not queried forever.
$retryNow = get_option('nmm_autopay_scan_retry', array());
$goneKey = $retryNow[0]; // e.g. 'XMR|xmrf_0'
$goneParts = explode('|', $goneKey, 2);
$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE address=%s", $goneParts[1])); // as if paid/removed
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600); // still failing fetches
sok('stale retry key dropped once not unpaid',  !in_array($goneKey, get_option('nmm_autopay_scan_retry', array()), true), 'gone=' . $goneKey);

// Once fetches succeed again, the retry set drains.
remove_all_filters('nmm_xmr_account_transactions');
add_filter('nmm_xmr_account_transactions', function () { return array('result' => 'success', 'by_address' => array()); });
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600);
NMM_Payment::check_all_addresses_for_matching_payment(3 * 3600);
sok('retry set drains after fetches succeed',    count(get_option('nmm_autopay_scan_retry', array())) === 0, 'retry=' . count(get_option('nmm_autopay_scan_retry', array())));

remove_all_filters('nmm_xmr_account_transactions');
remove_all_filters('nmm_autopay_scan_budget');
remove_all_actions('nmm_autopay_address_checked');
remove_all_actions('nmm_xmr_account_fetch');
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');

echo $GLOBALS['as_ok'] ? "\nAUTOPAY-SCAN CHECKS PASSED\n" : "\nAUTOPAY-SCAN CHECKS FAILED\n";
