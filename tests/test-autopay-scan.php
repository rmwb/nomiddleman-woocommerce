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
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_last_run');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_sweep_start');
delete_option('nmm_autopay_scan_dirty');

$GLOBALS['as_ok'] = true;
function sok($label, $cond, $extra = '') { printf("%-56s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['as_ok'] = false; } }

// One cron tick at the nominal 60s cadence. The budget is derived from the
// OBSERVED gap between runs, so sections asserting exact per-tick counts pin
// the gap to 60s - back-to-back in-test calls would clamp to 60s anyway, but
// a stalled CI runner must not be able to inflate a budget mid-section. The
// cadence behaviour itself is exercised in its own section below.
function as_tick($lifetime) {
	update_option('nmm_autopay_scan_last_run', time() - 60, false);
	NMM_Payment::check_all_addresses_for_matching_payment($lifetime);
}

// XMR's per-currency coverage stamp (0 when absent). Coverage is stamped per
// coin so one dead endpoint cannot freeze every currency's expirations; all
// the backlogs this file seeds are XMR.
function as_cov() {
	$m = get_option('nmm_autopay_scan_covered_at', array());
	return (is_array($m) && isset($m['XMR'])) ? (int) $m['XMR'] : 0;
}

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
// Cadence-aware planning (pure): the caller passes the OBSERVED cron interval,
// so a slow scheduler sweeps the backlog within the same wall-clock window by
// raising the per-tick budget. 100 addrs, baseline 10, lifetime 600s: at 60s
// ticks the sweep spreads over 5 ticks (budget 20); at 600s ticks a single
// tick must cover everything (budget 100).
$planFastCron = NMM_Payment::scan_plan(100, 10, 600, $dayCancel, 60);
sok('scan_plan 60s cadence: budget spread over ticks', $planFastCron['budget'] === 20, 'budget=' . $planFastCron['budget']);
$planSlowCron = NMM_Payment::scan_plan(100, 10, 600, $dayCancel, 600);
sok('scan_plan 600s cadence: one-tick sweep budget',   $planSlowCron['budget'] === 100, 'budget=' . $planSlowCron['budget']);
// Beyond the 600s planning clamp the BUDGET stays burst-capped, but the
// matching window must widen by the REAL cadence - with an hourly cron the
// sweep genuinely takes hours, and a window computed from the clamped 600s
// would let a payment age out unseen between two visits.
$planHourly = NMM_Payment::scan_plan(100, 10, 600, $dayCancel, 3600);
sok('scan_plan hourly: one-page budget unchanged',     $planHourly['budget'] === 100, 'budget=' . $planHourly['budget']);
sok('scan_plan hourly: window widened by REAL gap',    $planHourly['effective_lifetime'] === 600 + 3600, 'eff=' . $planHourly['effective_lifetime']);
$planHourlyBig = NMM_Payment::scan_plan(9000, 50, 3 * 3600, $dayCancel, 3600);
sok('scan_plan hourly big: burst stays capped',        $planHourlyBig['budget'] === 1000, 'budget=' . $planHourlyBig['budget']);
sok('scan_plan hourly big: window covers real sweep',  $planHourlyBig['effective_lifetime'] === 10800 + 9 * 3600, 'eff=' . $planHourlyBig['effective_lifetime']);

// page_possibly_truncated (pure): the cutoff comparison must be INCLUSIVE -
// the matcher accepts a transaction dated exactly at the window boundary
// (only strictly older ones are rejected) and several transactions can share
// one block timestamp, so a full page whose oldest entry sits exactly on the
// cutoff may still hide eligible same-second history below it.
$ptNow = time();
$ptLife = 3 * 3600;
$ptPage = function ($count, $oldest) { return array('transactions' => array(), 'page' => array($count, $oldest)); };
sok('trunc: full page, oldest AT cutoff = truncated',   NMM_Payment::page_possibly_truncated('BTC', $ptPage(25, $ptNow - $ptLife), $ptLife, $ptNow) === true);
sok('trunc: oldest strictly past cutoff = complete',    NMM_Payment::page_possibly_truncated('BTC', $ptPage(25, $ptNow - $ptLife - 1), $ptLife, $ptNow) === false);
sok('trunc: short page = complete',                     NMM_Payment::page_possibly_truncated('BTC', $ptPage(24, $ptNow - 60), $ptLife, $ptNow) === false);
sok('trunc: full page, unknown oldest = truncated',     NMM_Payment::page_possibly_truncated('BTC', $ptPage(25, null), $ptLife, $ptNow) === true);
sok('trunc: capless coin never truncated',              NMM_Payment::page_possibly_truncated('XMR', $ptPage(5000, $ptNow - 60), $ptLife, $ptNow) === false);

// Seed N unpaid Monero addresses. XMR RPC is unconfigured in CI, so the batch
// fetch returns an error (no network) and no order is ever matched - the unpaid
// set stays at N across ticks, which is exactly what we want for a coverage test.
// All backlog seeds in this file are backdated 2h so none qualifies for the
// priority lane - only rows a section deliberately creates fresh do.
$N = 100;
$budget = 30;
$rows = array();
for ($i = 0; $i < $N; $i++) {
	$rows[] = $wpdb->prepare("('xmraddr_%03d','XMR','unpaid',%d,%d,'0.00100000',0)", $i, time() - 2 * HOUR_IN_SECONDS, 700000 + $i);
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
delete_option('nmm_autopay_scan_covered_at');

for ($t = 0; $t < $ticksNeeded; $t++) {
	$GLOBALS['as_checked'] = array();
	$GLOBALS['as_fetches'] = 0;

	as_tick($lifetime);

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
sok('coverage stamped once the sweep wraps',      as_cov() > 0);

// Budget larger than the backlog: single tick covers everything, still one fetch.
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
$small = array();
for ($i = 0; $i < 5; $i++) { $small[] = $wpdb->prepare("('xmrsmall_%d','XMR','unpaid',%d,%d,'0.00100000',0)", $i, time() - 2 * HOUR_IN_SECONDS, 710000 + $i); }
$wpdb->query("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES " . implode(',', $small));
$GLOBALS['as_checked'] = array();
$GLOBALS['as_fetches'] = 0;
as_tick($lifetime);
sok('small backlog: checks all in one tick',      count($GLOBALS['as_checked']) === 5, 'checked=' . count($GLOBALS['as_checked']));
sok('small backlog: still one XMR fetch',         $GLOBALS['as_fetches'] === 1, 'fetches=' . $GLOBALS['as_fetches']);
sok('single-page tick stamps coverage',           as_cov() > 0);

// Empty backlog: no fetch, no work, no crash.
$wpdb->query("DELETE FROM `$pt`");
$GLOBALS['as_checked'] = array();
$GLOBALS['as_fetches'] = 0;
as_tick($lifetime);
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
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrc_%d', $i), time() - 2 * HOUR_IN_SECONDS, 730000 + $i));
}

delete_option('nmm_autopay_scan_covered_at');
$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // tick 1 -> xmrc_0,1,2
sok('no coverage stamp mid-sweep',              as_cov() === 0);
$tick1 = $GLOBALS['as_checked'];
$lastScanned = end($tick1);
$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE address=%s", $lastScanned)); // as if it got paid

$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // tick 2
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
for ($i = 0; $i < $bigN; $i++) { $bigRows[] = $wpdb->prepare("('xmrbig_%03d','XMR','unpaid',%d,%d,'0.00100000',0)", $i, time() - 2 * HOUR_IN_SECONDS, 720000 + $i); }
$wpdb->query("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES " . implode(',', $bigRows));

$shortLifetime = 600; // 10 minutes
$GLOBALS['as_checked'] = array();
as_tick($shortLifetime);
sok('budget rises above baseline to cover lifetime', count($GLOBALS['as_checked']) === 20, 'per-tick=' . count($GLOBALS['as_checked']) . ' (baseline 10)');

$adaptiveCovered = array();
foreach ($GLOBALS['as_checked'] as $a) { $adaptiveCovered[$a] = true; }
for ($t = 1; $t < 5; $t++) {
	$GLOBALS['as_checked'] = array();
	as_tick($shortLifetime);
	foreach ($GLOBALS['as_checked'] as $a) { $adaptiveCovered[$a] = true; }
}
sok('adaptive: full sweep within the lifetime window', count($adaptiveCovered) === $bigN, 'covered=' . count($adaptiveCovered) . '/' . $bigN);

// --- adaptive interval: the budget must follow the OBSERVED cron cadence ----
// Same 100-address backlog, baseline 10, lifetime 600s. Action Scheduler /
// WP-Cron are traffic-driven: a quiet store's "per-minute" cron may really tick
// every several minutes, and planning around an assumed 60s would silently
// stretch the sweep past the matching lifetime and lose payments. At an
// observed 60s gap the sweep spreads over 5 ticks (budget 20); at an observed
// 600s gap one tick must cover the whole backlog (budget 100).
update_option('nmm_autopay_scan_last_run', time() - 600, false);
$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
$slowTickCount = count($GLOBALS['as_checked']);
sok('slow cron: whole backlog in one tick',      $slowTickCount === 100, 'checked=' . $slowTickCount);

update_option('nmm_autopay_scan_last_run', time() - 60, false);
$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
$fastTickCount = count($GLOBALS['as_checked']);
sok('fast cron: budget spread across the sweep', $fastTickCount === 20, 'checked=' . $fastTickCount);
sok('slow cron raises the sweep budget',         $slowTickCount > $fastTickCount, "slow=$slowTickCount fast=$fastTickCount");

// Clamp floor: back-to-back runs (a 5s gap) must not compute a huge tick count
// from a tiny gap and shrink the budget below the nominal-60s assumption.
update_option('nmm_autopay_scan_last_run', time() - 5, false);
$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
sok('clamp floor: <60s gap behaves as 60s',      count($GLOBALS['as_checked']) === $fastTickCount, 'checked=' . count($GLOBALS['as_checked']));

// Clamp ceiling: one huge gap (host asleep for a day) raises the budget only
// as far as the 600s cap - bounding the explorer-request burst.
update_option('nmm_autopay_scan_last_run', time() - 86400, false);
$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
sok('clamp ceiling: huge gap behaves as 600s',   count($GLOBALS['as_checked']) === $slowTickCount, 'checked=' . count($GLOBALS['as_checked']));

// First run ever (no stored timestamp) assumes the nominal 60s.
delete_option('nmm_autopay_scan_last_run');
$GLOBALS['as_checked'] = array();
NMM_Payment::check_all_addresses_for_matching_payment($shortLifetime);
sok('first run defaults to the 60s assumption',  count($GLOBALS['as_checked']) === $fastTickCount, 'checked=' . count($GLOBALS['as_checked']));

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
delete_option('nmm_autopay_scan_covered_at');
for ($i = 0; $i < 9; $i++) {
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrf_%d', $i), time() - 2 * HOUR_IN_SECONDS, 740000 + $i));
}

$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // tick 1: xmrf_0,1,2 all fail
sok('failed fetches recorded in retry set',     count(get_option('nmm_autopay_scan_retry', array())) === 3, 'retry=' . count(get_option('nmm_autopay_scan_retry', array())));

$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // tick 2: retries 0,1,2 + sweeps 3,4,5
$tick2 = $GLOBALS['as_checked'];
sok('failed keys re-checked the very next tick', in_array('xmrf_0', $tick2, true) && in_array('xmrf_1', $tick2, true) && in_array('xmrf_2', $tick2, true), 'tick2=' . implode(',', $tick2));
sok('retry set stays bounded',                  count(get_option('nmm_autopay_scan_retry', array())) <= 9);

// A retry key whose payment is no longer unpaid (paid/cancelled/deleted while the
// endpoint stays down) must be dropped, not queried forever.
$retryNow = get_option('nmm_autopay_scan_retry', array());
$goneKey = $retryNow[0]; // e.g. 'XMR|xmrf_0'
$goneParts = explode('|', $goneKey, 2);
$wpdb->query($wpdb->prepare("DELETE FROM `$pt` WHERE address=%s", $goneParts[1])); // as if paid/removed
as_tick(3 * 3600); // still failing fetches; this tick wraps past the list end
sok('stale retry key dropped once not unpaid',  !in_array($goneKey, get_option('nmm_autopay_scan_retry', array()), true), 'gone=' . $goneKey);
sok('no coverage stamp while fetches fail',     as_cov() === 0);

// Once fetches succeed again, the retry set drains.
remove_all_filters('nmm_xmr_account_transactions');
add_filter('nmm_xmr_account_transactions', function () { return array('result' => 'success', 'by_address' => array()); });
as_tick(3 * 3600);
as_tick(3 * 3600);
sok('retry set drains after fetches succeed',    count(get_option('nmm_autopay_scan_retry', array())) === 0, 'retry=' . count(get_option('nmm_autopay_scan_retry', array())));
// A few more clean ticks guarantee a failure-free wrap, which may stamp again.
for ($t = 0; $t < 4; $t++) { as_tick(3 * 3600); }
sok('coverage resumes once fetches succeed',     as_cov() > 0);

// --- coverage stamp semantics: sweep START time, and stale-cursor wraps ----
// The stamp must be the completed sweep's start, not its wrap time: a row
// checked early in a multi-tick sweep whose window closed before the wrap
// must not count as "checked after expiry". Pin the recorded sweep start to
// a sentinel and assert the wrap stamps exactly that value.
remove_all_filters('nmm_autopay_scan_budget');
add_filter('nmm_autopay_scan_budget', function () { return 3; });
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_covered_at');
for ($i = 0; $i < 9; $i++) {
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrsw_%d', $i), time() - 2 * HOUR_IN_SECONDS, 760000 + $i));
}
$sweepSentinel = time() - 500;
update_option('nmm_autopay_scan_sweep_start', $sweepSentinel, false);
as_tick(3 * 3600); // xmrsw_0,1,2
as_tick(3 * 3600); // xmrsw_3,4,5
as_tick(3 * 3600); // xmrsw_6,7,8 - exact end of list, wrap not yet detected
sok('multi-tick sweep: no stamp before the wrap', as_cov() === 0);
as_tick(3 * 3600); // wraps
sok('wrap stamps the sweep START, not wrap time', as_cov() === $sweepSentinel, 'covered=' . as_cov() . ' sentinel=' . $sweepSentinel);

// A stale cursor beyond every row (backlog churn / long outage) makes one
// head page look like a wrap. That must not certify a full sweep: the stamp
// is still the OLD sweep start, so rows that expired after it - including
// the unscanned tail - stay protected until a genuinely complete sweep.
delete_option('nmm_autopay_scan_covered_at');
update_option('nmm_autopay_scan_cursor', 'XMR|zzzz_stale', false);
$staleSentinel = time() - 400;
update_option('nmm_autopay_scan_sweep_start', $staleSentinel, false);
as_tick(3 * 3600); // after-page empty -> head page only, flagged as a wrap
sok('stale-cursor wrap stamps only the old start', as_cov() === $staleSentinel, 'covered=' . as_cov() . ' sentinel=' . $staleSentinel);

// --- retry-cap overflow: dropped failures must dirty the coverage stamp ----
// When the bounded retry set overflows, dropped keys are never retried even
// though the cursor already passed them. If the endpoint then recovers, the
// next wrap would look clean - but those addresses were never verified, so
// the wrap must NOT stamp their currency. The one after (a fully re-swept,
// genuinely clean pass) may. Cap pinned to 2 so 3 failures overflow.
add_filter('nmm_autopay_scan_retry_cap', function () { return 2; });
remove_all_filters('nmm_xmr_account_transactions');
add_filter('nmm_xmr_account_transactions', function () { return array('result' => 'error'); });
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
for ($i = 0; $i < 9; $i++) {
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrd_%d', $i), time() - 2 * HOUR_IN_SECONDS, 770000 + $i));
}
as_tick(3 * 3600); // xmrd_0,1,2 fail; cap 2 keeps 0,1 and DROPS 2 -> XMR dirty
sok('overflowed retry set is capped',             count(get_option('nmm_autopay_scan_retry', array())) === 2, 'retry=' . count(get_option('nmm_autopay_scan_retry', array())));
remove_all_filters('nmm_xmr_account_transactions');
add_filter('nmm_xmr_account_transactions', function () { return array('result' => 'success', 'by_address' => array()); });
as_tick(3 * 3600); // recovery: retries 0,1 + sweep 3,4,5 all clean
as_tick(3 * 3600); // 6,7,8 - end of list
as_tick(3 * 3600); // wraps clean, but xmrd_2 was dropped unverified -> no stamp
sok('dropped failure blocks the recovery wrap',   as_cov() === 0, 'covered=' . as_cov());
as_tick(3 * 3600); // fresh sweep re-visits everything: 3,4,5
as_tick(3 * 3600); // 6,7,8
as_tick(3 * 3600); // wraps - genuinely clean now
sok('next fully-clean sweep stamps coverage',     as_cov() > 0, 'covered=' . as_cov());
remove_all_filters('nmm_autopay_scan_retry_cap');

// --- partial Solana history sweep must not certify coverage ----------------
// A busy/dusted SOL address takes several ticks to inspect (<=25 detail
// lookups per tick, durable internal cursor). get_sol_address_transactions()
// returns success for such a partial pass, so the verifier must consult
// sol_address_fully_swept() and dirty the coin - a payment could still sit
// below the internal cursor while cancel_expired_payments() runs.
$solAddr = 'SCOV1111111111111111111111111111111111111111';
$GLOBALS['scov_sigs'] = array();
$GLOBALS['scov_addr'] = $solAddr;
$GLOBALS['scov_sigs_fail'] = false; // getSignaturesForAddress returns HTTP 500
$GLOBALS['scov_tx_credit'] = 0;     // lamports credited to our address per getTransaction
$solMock = function ($pre, $args, $url) {
	if (strpos($url, 'api.mainnet-beta.solana.com') === false) { return $pre; }
	$req = json_decode($args['body'], true);
	if ($req['method'] === 'getSignaturesForAddress') {
		if ($GLOBALS['scov_sigs_fail']) {
			// 200 + RPC error object, NOT an HTTP 5xx: a 5xx would trip the
			// plugin's per-host backoff transient and poison later tests that
			// share this WordPress (the sol-retry suite runs next in CI).
			return array('response' => array('code' => 200), 'body' => json_encode(array('error' => array('code' => -32000, 'message' => 'mock outage'))));
		}
		$opts = isset($req['params'][1]) ? $req['params'][1] : array();
		$limit = isset($opts['limit']) ? (int) $opts['limit'] : 1000;
		$sigs = $GLOBALS['scov_sigs'];
		$start = 0;
		if (isset($opts['before'])) {
			foreach ($sigs as $i => $s) { if ($s['signature'] === $opts['before']) { $start = $i + 1; break; } }
		}
		$out = array_slice($sigs, $start, $limit);
		foreach ($out as &$o) { $o['err'] = null; }
		return array('response' => array('code' => 200), 'body' => json_encode(array('result' => $out)));
	}
	// getTransaction: inspected fine; credits our address scov_tx_credit lamports
	$credit = (int) $GLOBALS['scov_tx_credit'];
	$pubkey = ($credit > 0) ? $GLOBALS['scov_addr'] : 'SomeoneElse';
	return array('response' => array('code' => 200), 'body' => json_encode(array('result' => array(
		'blockTime' => time(),
		'transaction' => array('message' => array('accountKeys' => array(array('pubkey' => $pubkey)))),
		'meta' => array('preBalances' => array(1000), 'postBalances' => array(1000 + $credit)),
	))));
};
add_filter('pre_http_request', $solMock, 10, 3);

$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
delete_transient('nmm_sol_cursor_' . md5($solAddr));
$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'SOL','unpaid',%d,%d,'0.10000000',0)", $solAddr, time() - 2 * HOUR_IN_SECONDS, 780000));
// 30 in-window signatures > the 25-per-tick inspection budget -> partial pass.
for ($i = 0; $i < 30; $i++) { $GLOBALS['scov_sigs'][] = array('signature' => sprintf('scov_sig_%02d', $i), 'blockTime' => time() - 60 - $i); }

as_tick(3 * 3600); // single-page backlog: wrap-equivalent, but SOL is partial
$covMapSol = get_option('nmm_autopay_scan_covered_at', array());
sok('partial SOL sweep does not certify coverage', !is_array($covMapSol) || !isset($covMapSol['SOL']), 'map=' . (is_array($covMapSol) ? implode(',', array_keys($covMapSol)) : '(scalar)'));

as_tick(3 * 3600); // remaining 5 signatures: internal sweep completes mid-tick
$covMapSol = get_option('nmm_autopay_scan_covered_at', array());
sok('completed SOL sweep certifies coverage',      is_array($covMapSol) && isset($covMapSol['SOL']) && (int) $covMapSol['SOL'] > 0, 'map=' . (is_array($covMapSol) ? implode(',', array_keys($covMapSol)) : '(scalar)'));

// Mixed state: a due retry recovers a (non-matching) payment while the
// signature-page fetch fails on a fresh cursor. The fetch reports SUCCESS
// (the recovered transaction must never be discarded) and leaves neither a
// cursor nor a queue row behind - but the history was never paged this tick,
// so the sweep is incomplete and must NOT certify SOL coverage.
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
$srt = $wpdb->prefix . NMM_SOL_RETRY_TABLE;
$wpdb->query($wpdb->prepare("DELETE FROM `$srt` WHERE address=%s", $solAddr));
$wpdb->query($wpdb->prepare("INSERT INTO `$srt` (address, signature, first_failed_at, attempts, next_retry_at, block_time) VALUES (%s,'screc_sig',%d,1,%d,%d)", $solAddr, time() - 300, time() - 10, time() - 300));
$GLOBALS['scov_sigs_fail'] = true;
$GLOBALS['scov_tx_credit'] = 5; // recovered, but nowhere near the order amount
as_tick(3 * 3600);
$covMapSol = get_option('nmm_autopay_scan_covered_at', array());
sok('recovered retry + failed page never certifies', !is_array($covMapSol) || !isset($covMapSol['SOL']), 'map=' . (is_array($covMapSol) ? implode(',', array_keys($covMapSol)) : '(scalar)'));
$GLOBALS['scov_sigs_fail'] = false;
$GLOBALS['scov_tx_credit'] = 0;
$wpdb->query($wpdb->prepare("DELETE FROM `$srt` WHERE address=%s", $solAddr));
// Belt-and-braces: clear any per-host backoff state for the mocked RPC host
// so a run that DID trip it can never poison the suites that follow.
delete_transient('nmm_backoff_' . md5('api.mainnet-beta.solana.com'));
delete_transient('nmm_apifail_' . md5('api.mainnet-beta.solana.com'));

remove_filter('pre_http_request', $solMock, 10);
delete_transient('nmm_sol_cursor_' . md5($solAddr));

// --- fixed-depth adapters: a full in-window page must not certify ----------
// Explorers return only the newest page (BTC/mempool.space: 25). Under the
// bounded sweep an address is revisited less often than every tick, so a
// payment can be buried under a burst of later activity between visits -
// master's every-tick scan would have matched it first. A FULL page whose
// oldest entry is still inside the matching window may therefore hide
// in-window history below it and must dirty the coin; a page reaching PAST
// the window, or a short page, proves everything relevant was visible.
$GLOBALS['btr_txs'] = array();
$btcMockTrunc = function ($pre, $args, $url) {
	if (strpos($url, 'mempool.space/api/blocks/tip/height') !== false) {
		return array('response' => array('code' => 200), 'body' => '105', 'headers' => array(), 'cookies' => array());
	}
	if (strpos($url, 'mempool.space/api/address/') !== false) {
		return array('response' => array('code' => 200), 'body' => json_encode($GLOBALS['btr_txs']), 'headers' => array(), 'cookies' => array());
	}
	return $pre;
};
$btrPage = function ($count, $oldestAge) {
	$txs = array();
	for ($i = 0; $i < $count; $i++) {
		// newest-first; the last entry carries $oldestAge
		$age = ($count > 1) ? (int) (120 + ($oldestAge - 120) * $i / ($count - 1)) : $oldestAge;
		$txs[] = array(
			'txid' => sprintf('btr_tx_%03d', $i),
			'status' => array('confirmed' => true, 'block_height' => 100, 'block_time' => time() - $age),
			'vout' => array(array('scriptpubkey_address' => 'btctrunc_addr', 'value' => 1000)),
		);
	}
	return $txs;
};
add_filter('pre_http_request', $btcMockTrunc, 10, 3);
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES ('btctrunc_addr','BTC','unpaid',%d,%d,'0.00100000',0)", time() - 2 * HOUR_IN_SECONDS, 785000));

$GLOBALS['btr_txs'] = $btrPage(25, 1800); // FULL page, oldest entry well inside the window
as_tick(3 * 3600);
$covMapB = get_option('nmm_autopay_scan_covered_at', array());
sok('full in-window page does not certify',        !is_array($covMapB) || !isset($covMapB['BTC']), 'map=' . (is_array($covMapB) ? implode(',', array_keys($covMapB)) : '(scalar)'));

$GLOBALS['btr_txs'] = $btrPage(25, 10 * 24 * 3600); // full page reaching PAST the window
delete_option('nmm_autopay_scan_dirty');
as_tick(3 * 3600);
$covMapB = get_option('nmm_autopay_scan_covered_at', array());
sok('page spanning past the window certifies',     is_array($covMapB) && isset($covMapB['BTC']), 'map=' . (is_array($covMapB) ? implode(',', array_keys($covMapB)) : '(scalar)'));

$GLOBALS['btr_txs'] = $btrPage(3, 1800); // short page: nothing below it
delete_option('nmm_autopay_scan_covered_at');
as_tick(3 * 3600);
$covMapB = get_option('nmm_autopay_scan_covered_at', array());
sok('short page certifies',                        is_array($covMapB) && isset($covMapB['BTC']), 'map=' . (is_array($covMapB) ? implode(',', array_keys($covMapB)) : '(scalar)'));

// Mixed directions: fullness must be judged on the RAW page, not the
// incoming-filtered result. 25 raw in-window entries of which only ONE pays
// our address filters down to a single transaction - but the raw page is
// full, so an older in-window payment may still be hidden below it.
$btrMixed = $btrPage(25, 1800);
for ($i = 1; $i < 25; $i++) { $btrMixed[$i]['vout'] = array(array('scriptpubkey_address' => 'someone_else', 'value' => 1000)); }
$GLOBALS['btr_txs'] = $btrMixed;
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
as_tick(3 * 3600);
$covMapB = get_option('nmm_autopay_scan_covered_at', array());
sok('full raw page, one incoming: not certified',  !is_array($covMapB) || !isset($covMapB['BTC']), 'map=' . (is_array($covMapB) ? implode(',', array_keys($covMapB)) : '(scalar)'));
remove_filter('pre_http_request', $btcMockTrunc, 10);
delete_option('nmmpro_BTC_transactions_consumed_for_btctrunc_addr');

// --- partial detail bodies: an uninspectable tx must not certify ------------
// The per-tx-detail adapters (Iquidus and kin) fetch each transaction
// individually. An HTTP 200 whose body is truncated (empty vout - impossible
// on a real transaction) means the transaction was NOT inspected: the visit
// must fail (nothing collected) and never certify coverage. Once the body is
// well-formed again, the short raw page certifies normally.
$GLOBALS['blk_tx_body'] = array('vout' => array()); // truncated detail body
$blkMock = function ($pre, $args, $url) {
	if (strpos($url, 'explorer.blackcoin.nl/ext/getaddress/') !== false) {
		return array('response' => array('code' => 200), 'body' => json_encode(array(
			'last_txs' => array(array('addresses' => 'blk_tx1', 'type' => 'vout')),
		)), 'headers' => array(), 'cookies' => array());
	}
	if (strpos($url, 'explorer.blackcoin.nl/api/getrawtransaction') !== false) {
		return array('response' => array('code' => 200), 'body' => json_encode($GLOBALS['blk_tx_body']), 'headers' => array(), 'cookies' => array());
	}
	return $pre;
};
add_filter('pre_http_request', $blkMock, 10, 3);
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES ('blk_cov_addr','BLK','unpaid',%d,%d,'0.00100000',0)", time() - 2 * HOUR_IN_SECONDS, 795000));
as_tick(3 * 3600);
$covMapK = get_option('nmm_autopay_scan_covered_at', array());
sok('truncated detail body does not certify',      !is_array($covMapK) || !isset($covMapK['BLK']), 'map=' . (is_array($covMapK) ? implode(',', array_keys($covMapK)) : '(scalar)'));
sok('truncated detail body goes to the retry set', in_array('BLK|blk_cov_addr', get_option('nmm_autopay_scan_retry', array()), true), 'retry=' . implode(',', get_option('nmm_autopay_scan_retry', array())));

$GLOBALS['blk_tx_body'] = array('vout' => array(array('value' => 1.0, 'scriptPubKey' => array('addresses' => array('someone_else')))), 'confirmations' => 100, 'time' => time() - 600, 'txid' => 'blk_tx1');
as_tick(3 * 3600);
$covMapK = get_option('nmm_autopay_scan_covered_at', array());
sok('well-formed detail body certifies',           is_array($covMapK) && isset($covMapK['BLK']), 'map=' . (is_array($covMapK) ? implode(',', array_keys($covMapK)) : '(scalar)'));
remove_filter('pre_http_request', $blkMock, 10);

// --- unknown ticker: cannot be verified, must never be certified -----------
// A row whose coin was removed from the registry is skipped by the scan; it
// must be marked dirty rather than silently certified, or expiry would cancel
// its orders without any payment check ever being possible.
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_dirty');
$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES ('zombie_addr','ZZZFAKE','unpaid',%d,%d,'0.00100000',0)", time() - 2 * HOUR_IN_SECONDS, 790000));
as_tick(3 * 3600);
$covMapZ = get_option('nmm_autopay_scan_covered_at', array());
sok('unknown ticker never certified covered',      !is_array($covMapZ) || !isset($covMapZ['ZZZFAKE']), 'map=' . (is_array($covMapZ) ? implode(',', array_keys($covMapZ)) : '(scalar)'));

// --- priority lane: a fresh order is checked on the very next tick ---------
// With budget 3 and a 12-address backlog, park the cursor mid-list, then create
// a NEW unpaid order whose address sorts BEFORE the cursor - the fair sweep
// alone would not reach it until wrap-around, while the customer watches the
// thank-you page's 15s poller. The lane must check it THIS tick, additively
// (the sweep keeps its full budget) and without moving the cursor.
remove_all_filters('nmm_autopay_scan_budget');
add_filter('nmm_autopay_scan_budget', function () { return 3; });
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
for ($i = 0; $i < 12; $i++) {
	$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'XMR','unpaid',%d,%d,'0.00100000',0)", sprintf('xmrp_%02d', $i), time() - 2 * HOUR_IN_SECONDS, 750000 + $i));
}

as_tick(3 * 3600); // xmrp_00,01,02
as_tick(3 * 3600); // xmrp_03,04,05
sok('lane setup: cursor parked mid-list',        get_option('nmm_autopay_scan_cursor') === 'XMR|xmrp_05', 'cursor=' . get_option('nmm_autopay_scan_cursor'));

$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES ('xmrp_02_fresh','XMR','unpaid',%d,%d,'0.00100000',0)", time(), 750100));
$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // lane: xmrp_02_fresh ; sweep: xmrp_06,07,08
sok('fresh order checked on the very next tick', in_array('xmrp_02_fresh', $GLOBALS['as_checked'], true), 'checked=' . implode(',', $GLOBALS['as_checked']));
sok('lane is additive to the sweep budget',      count($GLOBALS['as_checked']) === 4, 'checked=' . implode(',', $GLOBALS['as_checked']));
sok('lane does not advance the cursor',          get_option('nmm_autopay_scan_cursor') === 'XMR|xmrp_08', 'cursor=' . get_option('nmm_autopay_scan_cursor'));

// Dedupe: a fresh address that is ALSO in this tick's sweep page is scanned
// once, not twice, and the cursor still advances over the full sweep page.
$wpdb->query("DELETE FROM `$pt` WHERE address='xmrp_02_fresh'");
$wpdb->query($wpdb->prepare("INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES ('xmrp_09','XMR','unpaid',%d,%d,'0.00100000',0)", time(), 750101));
$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // lane: xmrp_09 ; sweep page: xmrp_09,10,11
$laneCounts = array_count_values($GLOBALS['as_checked']);
sok('lane+sweep overlap scanned exactly once',   isset($laneCounts['xmrp_09']) && $laneCounts['xmrp_09'] === 1, 'checked=' . implode(',', $GLOBALS['as_checked']));
sok('overlap does not inflate the tick',         count($GLOBALS['as_checked']) === 3, 'checked=' . implode(',', $GLOBALS['as_checked']));
sok('cursor still advances over the sweep page', get_option('nmm_autopay_scan_cursor') === 'XMR|xmrp_11', 'cursor=' . get_option('nmm_autopay_scan_cursor'));

// Filtering the window to 0 disables the lane entirely: the recent xmrp_09 row
// is NOT re-checked; only the wrapped sweep page runs.
add_filter('nmm_autopay_priority_window', '__return_zero');
$GLOBALS['as_checked'] = array();
as_tick(3 * 3600); // wraps to xmrp_00,01,02
sok('window 0 disables the lane',                count($GLOBALS['as_checked']) === 3 && !in_array('xmrp_09', $GLOBALS['as_checked'], true), 'checked=' . implode(',', $GLOBALS['as_checked']));
remove_all_filters('nmm_autopay_priority_window');

remove_all_filters('nmm_xmr_account_transactions');
remove_all_filters('nmm_autopay_scan_budget');
remove_all_actions('nmm_autopay_address_checked');
remove_all_actions('nmm_xmr_account_fetch');
$wpdb->query("DELETE FROM `$pt`");
delete_option('nmm_autopay_scan_cursor');
delete_option('nmm_autopay_scan_retry');
delete_option('nmm_autopay_scan_last_run');
delete_option('nmm_autopay_scan_covered_at');
delete_option('nmm_autopay_scan_sweep_start');
delete_option('nmm_autopay_scan_dirty');

echo $GLOBALS['as_ok'] ? "\nAUTOPAY-SCAN CHECKS PASSED\n" : "\nAUTOPAY-SCAN CHECKS FAILED\n";
