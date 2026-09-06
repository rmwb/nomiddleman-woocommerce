<?php
/**
 * Offline test: NMMPRO_Exchange agrees the USD rate that sets what a customer is
 * actually charged.
 *
 * Before 2.11.0 the rate was the arithmetic MEAN of whichever price APIs the
 * merchant ticked, so one wrong-but-nonzero source (stale cache, wrong pair,
 * hijacked endpoint) dragged the order total in proportion to how wrong it was
 * - and with the shipped default of one selected API there was no cross-check
 * at all. This suite pins the replacement: median + outlier rejection, the
 * explicit small-n rules, the movement guard against the last-known-good rate,
 * and the bounded stale tier (serve the last good rate briefly, then fail
 * checkout rather than charge a price nobody can vouch for).
 *
 * Fully offline and deterministic: every price is injected, either straight
 * into the pure evaluators or through the per-source transients the fetchers
 * short-circuit on. Any HTTP attempt fails the run.
 *
 *   Run:  php tests/test-exchange-rate.php
 *   CI:   php -d error_reporting=24575 tests/test-exchange-rate.php
 */

// --- stubs that must be installed BEFORE wp-stubs.php claims the names ------

$GLOBALS['xr_filters'] = array();

if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value) {
		if (isset($GLOBALS['xr_filters'][$tag])) {
			return call_user_func($GLOBALS['xr_filters'][$tag], $value);
		}
		return $value;
	}
}

// NMMPRO_Util::log() routes to WooCommerce's logger when one exists; give it one
// so the suite can assert the loud warnings instead of spraying stderr.
$GLOBALS['xr_log'] = array();

class XR_Logger {
	public function log($level, $entry, $context = array()) {
		$GLOBALS['xr_log'][] = array('level' => $level, 'entry' => $entry);
	}
}

if (!function_exists('wc_get_logger')) {
	function wc_get_logger() { return new XR_Logger(); }
}

require_once __DIR__ . '/wp-stubs.php';

// Hard guarantee of "no network": every fetcher must be served from a seeded
// transient. A request escaping to HTTP is a test bug, and is recorded as one.
$GLOBALS['xr_http_calls'] = 0;
$GLOBALS['nmmpro_http_handler'] = function ($url, $method = 'GET', $body = null, $headers = array()) {
	$GLOBALS['xr_http_calls']++;
	$GLOBALS['xr_http_urls'][] = $url;
	return new WP_Error_Stub();
};
$GLOBALS['xr_http_urls'] = array();

nmmpro_test_require_plugin(array('src/NMMPRO_Util.php', 'src/NMMPRO_Exchange.php'));

// wp eval-file runs this file in FUNCTION scope, so a top-level `$pass` is not
// the `global $pass` a helper would write; track through $GLOBALS so the banner
// (which CI greps) can never print PASSED while a check failed.
$GLOBALS['xr_ok'] = true;
$GLOBALS['xr_checks'] = 0;

function xok($label, $cond, $extra = '') {
	$GLOBALS['xr_checks']++;
	printf("%-64s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['xr_ok'] = false; }
}

function xnear($actual, $expected, $eps = 0.000000001) {
	return is_numeric($actual) && abs((float) $actual - (float) $expected) < $eps;
}

function xcodes($warnings) {
	$codes = array();
	foreach ($warnings as $w) { $codes[] = $w['code']; }
	return $codes;
}

function xlogged($needle, $level = 'warning') {
	foreach ($GLOBALS['xr_log'] as $row) {
		if ($row['level'] === $level && strpos($row['entry'], $needle) !== false) { return true; }
	}
	return false;
}

// Fresh world: no transients (per-source prices, last-known-good, pending
// moves, log de-duplication keys), no captured log, no filters.
function xreset() {
	$GLOBALS['nmmpro_test_transients'] = array();
	$GLOBALS['xr_log'] = array();
	$GLOBALS['xr_filters'] = array();
}

function xseed($coin, $prices) {
	foreach ($prices as $source => $value) {
		// Prefixed cache keys (nmmpro_rate_<source>_<coin>) - see NMMPRO_Exchange.
		set_transient('nmmpro_rate_' . $source . '_' . $coin, $value, 600);
	}
}

echo "\n--- median helper ---\n";

xok('median of 3 is the middle value', xnear(NMMPRO_Exchange::median(array(3, 1, 2)), 2));
xok('median of 4 is the midpoint of the two centres', xnear(NMMPRO_Exchange::median(array(1, 2, 3, 4)), 2.5));
xok('median of 5 is the middle value', xnear(NMMPRO_Exchange::median(array(9, 1, 5, 2, 7)), 5));
xok('median of nothing is 0.0', NMMPRO_Exchange::median(array()) === 0.0);
xok('median ignores zero and negative quotes', xnear(NMMPRO_Exchange::median(array(0, -1, 5, 7)), 6));

echo "\n--- consensus: 3+ sources (median, then outlier rejection) ---\n";

$c3 = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 102.0, 'c' => 104.0));
xok('3 sources: median used', xnear($c3['price'], 102), 'got=' . $c3['price']);
xok('3 sources: status ok, all kept', $c3['status'] === 'ok' && count($c3['kept']) === 3 && count($c3['rejected']) === 0);

$c4 = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 102.0, 'c' => 104.0, 'd' => 106.0));
xok('4 sources: median of the two centres', xnear($c4['price'], 103), 'got=' . $c4['price']);

$c5 = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'd' => 103.0, 'e' => 104.0));
xok('5 sources: median used', xnear($c5['price'], 102), 'got=' . $c5['price']);

$agree = NMMPRO_Exchange::consensus_price(array('a' => 200.0, 'b' => 200.0, 'c' => 200.0));
xok('all-agree case is unchanged by the new logic', xnear($agree['price'], 200)
	&& count($agree['rejected']) === 0 && count($agree['warnings']) === 0);

$high = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'hijacked' => 500.0));
xok('one wild HIGH outlier is discarded', xnear($high['price'], 101), 'got=' . $high['price']);
xok('the discarded source is named', $high['rejected'] === array('hijacked'));
xok('discarding an outlier is warned about', in_array('outlier_rejected', xcodes($high['warnings']), true));
xok('the mean would have been badly wrong', !xnear($high['price'], (100 + 101 + 102 + 500) / 4));

$low = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'stale' => 10.0));
xok('one wild LOW outlier is discarded', xnear($low['price'], 101), 'got=' . $low['price']);

// 3 sources with one outlier leaves 2 survivors, whose median is their midpoint
// - documented, and still nothing like the mean (233.67) the old code charged.
$threeOut = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'hijacked' => 500.0));
xok('3 sources, 1 outlier: the 2 survivors set the price', xnear($threeOut['price'], 100.5)
	&& $threeOut['rejected'] === array('hijacked'), 'got=' . $threeOut['price']);

$split = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 200.0, 'c' => 300.0));
xok('a 3-way split leaves nothing corroborated', $split['price'] === null && $split['status'] === 'disagree');
xok('the 3-way split is warned about', in_array('no_consensus', xcodes($split['warnings']), true));

$tight = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 102.0, 'c' => 104.0), 0.005);
xok('a tighter tolerance is honoured', $tight['price'] === null && $tight['status'] === 'disagree');

echo "\n--- consensus: small-n rules ---\n";

$one = NMMPRO_Exchange::consensus_price(array('CoinGecko' => 100.0));
xok('1 source: the price is still used', xnear($one['price'], 100) && $one['status'] === 'single');
xok('1 source: warned loudly', in_array('single_source', xcodes($one['warnings']), true));

$two = NMMPRO_Exchange::consensus_price(array('CoinGecko' => 100.0, 'HitBTC' => 103.0));
xok('2 agreeing sources: the LOWER is used', xnear($two['price'], 100) && $two['status'] === 'ok', 'got=' . $two['price']);

$twoRev = NMMPRO_Exchange::consensus_price(array('CoinGecko' => 103.0, 'HitBTC' => 100.0));
xok('2 agreeing sources: order does not matter', xnear($twoRev['price'], 100));

$edge = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 105.0));
xok('2 sources just inside the tolerance still agree', xnear($edge['price'], 100), 'spread=4.88%');

$twoBad = NMMPRO_Exchange::consensus_price(array('CoinGecko' => 100.0, 'HitBTC' => 130.0));
xok('2 disagreeing sources: neither is trusted', $twoBad['price'] === null && $twoBad['status'] === 'disagree');
xok('2 disagreeing sources: warned', in_array('two_source_disagree', xcodes($twoBad['warnings']), true));

$none = NMMPRO_Exchange::consensus_price(array());
xok('0 sources: null, never 0', $none['price'] === null && $none['status'] === 'none' && $none['sources'] === 0);

$dead = NMMPRO_Exchange::consensus_price(array('a' => 0, 'b' => -3, 'c' => 100.0));
xok('dead sources (0 / negative) do not count as sources', $dead['sources'] === 1 && xnear($dead['price'], 100));

echo "\n--- implausible quotes: a finite but absurd price is not a price ---\n";

// Why this matters, stated as arithmetic rather than prose: the gateway charges
// round($usdTotal / $price, 8) (18 for ETH-like coins). A consensus of 1e308 is
// finite, positive, numeric and passes every is_finite() check - and turns a
// $100 order into ZERO crypto, which Privacy Mode reads as "received >=
// expected" and completes for nothing.
xok('a $100 order at 1e308/unit rounds to zero at 8 decimals', round(100 / 1e308, 8) === 0.0);
xok('...and at 18 decimals too', round(100 / 1e308, 18) === 0.0);

$absurd = NMMPRO_Exchange::consensus_price(array('a' => 1e308, 'b' => 1e308, 'c' => 1e308, 'd' => 1e308));
xok('four sources AGREEING on 1e308 do not make a price',
	$absurd['price'] === null && $absurd['status'] === 'none', 'got=' . var_export($absurd['price'], true));
xok('the absurd quotes are not counted as usable sources', $absurd['sources'] === 0);
xok('rejecting them takes the "no sources" path, never a 0 price', $absurd['price'] !== 0 && $absurd['price'] !== 0.0);
xok('ignoring an implausible quote is warned about', in_array('implausible_price', xcodes($absurd['warnings']), true));
xok('every implausible source is named', strpos($absurd['warnings'][0]['message'], 'a, b, c, d') !== false,
	$absurd['warnings'][0]['message']);

$inf = NMMPRO_Exchange::consensus_price(array('a' => 1e309, 'b' => 1e309, 'c' => 1e309, 'd' => 1e309));
xok('1e309 (INF) is still refused', $inf['price'] === null && $inf['sources'] === 0);

$nan = NMMPRO_Exchange::consensus_price(array('a' => NAN, 'b' => 100.0, 'c' => 101.0, 'd' => 102.0));
xok('NAN never survives to the median', $nan['sources'] === 3 && xnear($nan['price'], 101));

// A real price, at the top of what the most expensive supported asset has ever
// been worth, is untouched.
$real = NMMPRO_Exchange::consensus_price(array('a' => 120000.0, 'b' => 120500.0, 'c' => 121000.0));
xok('a realistic BTC price is still accepted', xnear($real['price'], 120500) && $real['status'] === 'ok');
xok('a realistic price raises no implausibility warning', !in_array('implausible_price', xcodes($real['warnings']), true));

// Bound-adjacent behaviour, pinning where the line actually sits: the bound is
// inclusive, so exactly $10,000,000/unit is accepted and a dollar more is not.
$atBound = NMMPRO_Exchange::consensus_price(array('a' => 10000000.0, 'b' => 10000000.0, 'c' => 10000000.0));
xok('a quote exactly ON the bound is accepted', xnear($atBound['price'], 10000000.0) && $atBound['status'] === 'ok',
	'got=' . var_export($atBound['price'], true));
$overBound = NMMPRO_Exchange::consensus_price(array('a' => 10000001.0, 'b' => 10000001.0, 'c' => 10000001.0));
xok('a quote one dollar OVER the bound is refused', $overBound['price'] === null && $overBound['sources'] === 0);

// An implausible source is dropped like any other unusable one: the sane
// sources it was mixed with still price the order.
$mixed = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'hijacked' => 1e308));
xok('an absurd source among sane ones is dropped, not averaged in',
	xnear($mixed['price'], 101) && $mixed['sources'] === 3, 'got=' . $mixed['price']);
xok('the absurd source is not counted towards the 2-survivor rule', count($mixed['kept']) === 3);

// Dropping it can leave too few sources to corroborate - which must land on the
// ordinary untrusted path, not on a price.
$mixedThin = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'hijacked' => 1e308));
xok('dropping an absurd quote can demote a 2-source store to single-source',
	xnear($mixedThin['price'], 100) && $mixedThin['status'] === 'single' && $mixedThin['sources'] === 1);

// The bound is a parameter, and it is what actually decides.
$tightBound = NMMPRO_Exchange::consensus_price(array('a' => 5000.0, 'b' => 5000.0, 'c' => 5000.0), 0.05, 1000.0);
xok('a tighter plausibility bound is honoured', $tightBound['price'] === null && $tightBound['sources'] === 0);
$nonsenseBound = NMMPRO_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0), 0.05, 0);
xok('a zero/garbage bound falls back to the default rather than rejecting everything',
	xnear($nonsenseBound['price'], 101));

echo "\n--- movement guard against the last-known-good rate ---\n";

$now = 1700000000;
$fresh = array('price' => 100.0, 'time' => $now);

$noAnchor = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 100.0), null, null, $now, array());
xok('no anchor yet: the price is accepted', xnear($noAnchor['price'], 100) && $noAnchor['pending'] === null);

$small = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 105.0), $fresh, null, $now, array());
xok('a move inside the threshold is accepted', xnear($small['price'], 105));

$jump = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh, null, $now, array());
xok('a 100% jump from ONE source is rejected', $jump['price'] === null && $jump['status'] === 'jump_rejected');
xok('the rejected jump is warned about', in_array('jump_rejected', xcodes($jump['warnings']), true));
xok('the rejected level is remembered as pending',
	is_array($jump['pending']) && xnear($jump['pending']['price'], 200) && $jump['pending']['first_seen'] === $now);

$jump2 = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0, 'HitBTC' => 202.0), $fresh, null, $now, array());
xok('the SAME jump corroborated by a 2nd source is accepted', xnear($jump2['price'], 200), 'got=' . $jump2['price']);
xok('the corroborated jump is warned about', in_array('jump_corroborated', xcodes($jump2['warnings']), true));

$jump3 = NMMPRO_Exchange::evaluate_rate(array('a' => 200.0, 'b' => 201.0, 'c' => 202.0), $fresh, null, $now, array());
xok('the same jump corroborated by 3 sources is accepted', xnear($jump3['price'], 201));

// 100 -> 130 is well past the 10% jump threshold but inside the cumulative
// drift limit, so this exercises time-corroboration and nothing else. (It used
// to read 200; a doubling in one reference window is now refused outright to a
// single source no matter how long it holds - covered further down.)
$held = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 130.0), $fresh,
	array('price' => 129.0, 'first_seen' => $now - 1000), $now, array());
xok('a single source that HELD the new level past the window is accepted', xnear($held['price'], 130), 'got=' . $held['price']);
xok('time-corroboration is warned about', in_array('jump_confirmed_over_time', xcodes($held['warnings']), true));
xok('the pending record is cleared once promoted', $held['pending'] === null);

$young = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh,
	array('price' => 199.0, 'first_seen' => $now - 100), $now, array());
xok('a pending move younger than the window is still rejected', $young['price'] === null);
xok('the corroboration clock is not reset each tick', $young['pending']['first_seen'] === $now - 100);

$moved = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh,
	array('price' => 150.0, 'first_seen' => $now - 1000), $now, array());
xok('a pending move to a DIFFERENT level restarts the clock',
	$moved['price'] === null && $moved['pending']['first_seen'] === $now);

$staleAnchor = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0),
	array('price' => 100.0, 'time' => $now - 4000), null, $now, array());
xok('an anchor older than the stale window does not gate the price', xnear($staleAnchor['price'], 200));

$deadTick = NMMPRO_Exchange::evaluate_rate(array(), $fresh,
	array('price' => 200.0, 'first_seen' => $now - 100), $now, array());
xok('a tick that reached nobody keeps the pending move alive',
	$deadTick['price'] === null && $deadTick['pending']['first_seen'] === $now - 100);

$loose = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 140.0), $fresh, null, $now, array('jump' => 0.5));
xok('the jump threshold is configurable', xnear($loose['price'], 140));

echo "\n--- cumulative drift guard (the sub-threshold ratchet) ---\n";

/**
 * Drives evaluate_rate exactly the way get_average_usd_price does: threading the
 * last-known-good anchor, the pending move and the slow reference from one tick
 * to the next, and refreshing the anchor only on an ACCEPTED tick (a rejected
 * one leaves the stored anchor, and its timestamp, alone).
 *
 * Returns the last price the merchant would actually have charged.
 */
function xwalk($quotes, $sourceCount = 1, $start = 100.0, $gap = 300, $options = array()) {
	$t = 1700000000;
	$good = array('price' => $start, 'time' => $t);
	$ref = null;
	$pending = null;
	$accepted = $start;
	$state = array('price' => null, 'status' => 'none', 'warnings' => array());
	$rejects = 0;
	$codes = array();
	$firstReject = '';

	foreach ($quotes as $quote) {
		$t += $gap;
		$candidates = array();

		for ($i = 0; $i < $sourceCount; $i++) {
			$candidates['src' . $i] = $quote;
		}

		$state = NMMPRO_Exchange::evaluate_rate($candidates, $good, $pending, $t, $options, $ref);
		$pending = $state['pending'];
		$codes = array_merge($codes, xcodes($state['warnings']));

		if (is_array($state['reference'])) { $ref = $state['reference']; }

		if ($state['price'] !== null) {
			$accepted = (float) $state['price'];
			$good = array('price' => $accepted, 'time' => $t);
		}
		else {
			$rejects++;
			if ($firstReject === '') { $firstReject = $state['status']; }
		}
	}

	return array('accepted' => $accepted, 'state' => $state, 'rejects' => $rejects,
		'reference' => $ref, 'codes' => $codes, 'first_reject' => $firstReject);
}

// The exact reported attack: ten successive 9.9% increases. Every one of them
// is UNDER the 10% per-tick jump threshold, so the jump guard never fires once;
// before the cumulative bound they walked the charged rate from 100 to 257, and
// a $100 order from 1 unit down to 0.389 - the merchant underpaid by ~61%.
$ratchetQuotes = array();
$p = 100.0;
for ($i = 0; $i < 10; $i++) { $p *= 1.099; $ratchetQuotes[] = $p; }

xok('the ratchet really is made of sub-threshold steps', $ratchetQuotes[0] / 100.0 - 1 < 0.10);
xok('...and really would reach ~257 unbounded', xnear(end($ratchetQuotes), 257.0, 0.6), 'got=' . end($ratchetQuotes));

$ratchet = xwalk($ratchetQuotes);
xok('a single source can no longer walk the anchor past the drift limit',
	$ratchet['accepted'] <= 140.0, 'walked to ' . $ratchet['accepted']);
xok('the walk is stopped, not merely slowed', $ratchet['rejects'] > 0 && $ratchet['state']['price'] === null);
xok('the stop is the cumulative guard, not the per-tick one',
	$ratchet['state']['status'] === 'drift_rejected', 'status=' . $ratchet['state']['status']);
xok('the cumulative rejection is warned about',
	in_array('reference_drift_rejected', xcodes($ratchet['state']['warnings']), true));
// The walk is stopped BY the cumulative guard: every step was small enough that
// the per-tick guard would have waved it through. (Once the anchor is frozen by
// the first refusal the quote runs away from it and the jump guard starts firing
// too - which is why the FIRST refusal is the one that proves which guard bit.)
xok('the cumulative guard, not the per-tick one, is what stopped the walk',
	$ratchet['first_reject'] === 'drift_rejected', 'first refusal was ' . $ratchet['first_reject']);
xok('a $100 order still buys a sane amount', 100 / $ratchet['accepted'] > 0.7,
	'units=' . round(100 / $ratchet['accepted'], 4));
xok('the reference itself never moved during the walk', xnear($ratchet['reference']['price'], 100));

// ...and the honest case. The SAME ten steps reported by two agreeing sources
// track all the way, because >= 2 surviving sources is the corroboration
// standard used everywhere else in this file. Multi-source stores are
// unaffected in practice.
$corroboratedRun = xwalk($ratchetQuotes, 2);
xok('the same sustained move corroborated by 2 sources IS accepted in full',
	xnear($corroboratedRun['accepted'], 257.0, 0.6), 'got=' . $corroboratedRun['accepted']);
xok('a corroborated run is never rejected', $corroboratedRun['rejects'] === 0);
xok('a corroborated breach of the limit is warned about, not waved through silently',
	in_array('reference_drift_corroborated', $corroboratedRun['codes'], true),
	implode(',', array_unique($corroboratedRun['codes'])));
xok('a corroborated run never once hit the per-tick jump guard either',
	!in_array('jump_rejected', $corroboratedRun['codes'], true));

// A legitimate 30% day for a single-source store must NOT be broken. Crypto
// really does move like this, so the limit is sized above it: three 9% steps
// inside one reference window take the price to ~129.5 and every one is charged.
$realDay = xwalk(array(109.0, 118.81, 129.5));
xok('a legitimate ~30% single-source move is charged in full', xnear($realDay['accepted'], 129.5),
	'got=' . $realDay['accepted']);
xok('a legitimate ~30% move is never rejected', $realDay['rejects'] === 0);

// A move BEYOND the limit inside one window degrades safely: the rate is not
// trusted (the caller serves the anchor and then fails checkout), never charged.
$crash = xwalk(array(95.0, 90.0, 82.0, 75.0, 68.0, 62.0, 56.0));
xok('a >40% single-source move inside one window is not charged',
	$crash['state']['price'] === null && $crash['state']['status'] === 'drift_rejected',
	'status=' . $crash['state']['status']);
xok('the drift guard bounds falls as well as rises', $crash['accepted'] >= 60.0,
	'fell only to ' . $crash['accepted']);

// An ELAPSED window does not excuse the price from the check - that was the
// whole ratchet on a slower clock: hold a doubled price, wait out one window,
// collect. What the window buys is that the REFERENCE may follow the market by
// one limit-sized step, so an honest store whose market genuinely ran away is
// caught up over successive windows instead of being refused forever.
$aged = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0), array('price' => 195.0, 'time' => $now),
	null, $now, array(), array('price' => 100.0, 'time' => $now - 21600));
xok('an elapsed window does NOT hand a lone source a 2x price',
	$aged['price'] === null && $aged['status'] === 'drift_rejected', 'status=' . $aged['status']);
xok('  but the reference follows by one limit-sized step',
	xnear($aged['reference']['price'], 140) && $aged['reference']['time'] === $now,
	'ref=' . $aged['reference']['price']);

// A move INSIDE the limit is still accepted the moment the window turns over,
// and re-bases onto the accepted price - the honest case must stay cheap.
$agedOk = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 130.0), array('price' => 128.0, 'time' => $now),
	null, $now, array(), array('price' => 100.0, 'time' => $now - 21600));
xok('an in-limit move after the window is accepted', xnear($agedOk['price'], 130));
xok('  and re-bases onto the price just accepted',
	xnear($agedOk['reference']['price'], 130) && $agedOk['reference']['time'] === $now);

$notAged = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0), array('price' => 195.0, 'time' => $now),
	null, $now, array(), array('price' => 100.0, 'time' => $now - 21599));
xok('one second before the window is up it is still refused',
	$notAged['price'] === null && $notAged['status'] === 'drift_rejected');
xok('a refused tick does not re-base or re-time the reference',
	xnear($notAged['reference']['price'], 100) && $notAged['reference']['time'] === $now - 21599);

// Time-corroboration unlocks the per-tick jump guard but deliberately NOT the
// cumulative one: letting a single source through by waiting would rebuild the
// ratchet with extra steps.
$heldTooFar = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh,
	array('price' => 199.0, 'first_seen' => $now - 100000), $now, array());
xok('holding a level cannot buy a single source past the cumulative limit',
	$heldTooFar['price'] === null && $heldTooFar['status'] === 'drift_rejected');
xok('a drift rejection keeps the jump clock rather than resetting it',
	is_array($heldTooFar['pending']) && $heldTooFar['pending']['first_seen'] === $now - 100000);

// Within the limit, the reference keeps its ORIGINAL clock - otherwise it would
// never age out, and a bound that never re-bases is a bound that deadlocks.
$inLimit = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 105.0), $fresh, null, $now, array(),
	array('price' => 100.0, 'time' => $now - 5000));
xok('an accepted in-limit tick does not re-time the reference',
	xnear($inLimit['price'], 105) && $inLimit['reference']['time'] === $now - 5000
	&& xnear($inLimit['reference']['price'], 100));

// With no reference and no anchor at all there is nothing to drift from; the
// first price seen becomes the reference.
$firstEver = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 100.0), null, null, $now, array(), null);
xok('the first price ever seen seeds the reference', xnear($firstEver['price'], 100)
	&& xnear($firstEver['reference']['price'], 100) && $firstEver['reference']['time'] === $now);

// An anchor too stale to gate a jump is too stale to seed the bound either.
$staleSeed = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 300.0),
	array('price' => 100.0, 'time' => $now - 4000), null, $now, array(), null);
xok('a stale anchor does not seed the reference', xnear($staleSeed['price'], 300));

// Both knobs actually decide.
$looseDrift = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 190.0), array('price' => 180.0, 'time' => $now),
	null, $now, array('drift' => 0.95), array('price' => 100.0, 'time' => $now));
xok('the drift limit is configurable', xnear($looseDrift['price'], 190));

// A shorter window makes the reference follow SOONER, but each step is still
// capped by the drift limit - the window controls the clock, not the size.
$shortWindow = NMMPRO_Exchange::evaluate_rate(array('CoinGecko' => 190.0), array('price' => 180.0, 'time' => $now),
	null, $now, array('reference_window' => 60), array('price' => 100.0, 'time' => $now - 61));
xok('a short window still refuses an over-limit move',
	$shortWindow['price'] === null && $shortWindow['status'] === 'drift_rejected');
xok('the reference window is configurable (it follows at once)',
	xnear($shortWindow['reference']['price'], 140) && $shortWindow['reference']['time'] === $now,
	'ref=' . $shortWindow['reference']['price']);

echo "\n--- shared entry point: get_average_usd_price (checkout + cache warmer) ---\n";

xreset();
xseed('TA', array('coingecko' => 100.0, 'hitbtc' => 101.0, 'binance' => 102.0, 'gateio' => 500.0));
$ta = NMMPRO_Exchange::get_average_usd_price('TA', 600, array('0', '1', '2', '3'));
xok('end to end: the hijacked source is discarded', xnear($ta, 101), 'got=' . $ta);
$goodTA = get_transient('nmmpro_rate_good_TA');
xok('end to end: the agreed rate is stored as last-known-good',
	is_array($goodTA) && xnear($goodTA['price'], 101) && $goodTA['time'] > 0);
$taAgain = NMMPRO_Exchange::get_average_usd_price('TA', 600, array('0', '1', '2', '3'));
xok('the warmer and checkout share one entry point and one answer', xnear($taAgain, $ta));

xreset();
xseed('TB', array('coingecko' => 100.0));
$tb = NMMPRO_Exchange::get_average_usd_price('TB', 600, array('0'));
xok('1 selected API: the price is used', xnear($tb, 100));
xok('1 selected API: logged at WARNING level', xlogged('single price source', 'warning'));

xreset();
xseed('TC', array('coingecko' => 0));
$threw = false; $message = '';
try { NMMPRO_Exchange::get_average_usd_price('TC', 600, array('0')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('0 sources and no history: throws, never returns 0', $threw);
xok('0 sources: the customer-facing message is the unreachable one',
	strpos($message, 'No cryptocurrency exchanges could be reached') !== false);

xreset();
xseed('TD', array('coingecko' => 0));
set_transient('nmmpro_rate_good_TD', array('price' => 123.45, 'time' => time() - 600), 86400);
$td = NMMPRO_Exchange::get_average_usd_price('TD', 600, array('0'));
xok('all fetches dead: the last-known-good rate is served inside the max age', xnear($td, 123.45));
xok('serving a stale rate is logged at WARNING level', xlogged('Serving last-known-good', 'warning'));

xreset();
xseed('TE', array('coingecko' => 0));
set_transient('nmmpro_rate_good_TE', array('price' => 123.45, 'time' => time() - 3600), 86400);
$threw = false; $message = '';
try { NMMPRO_Exchange::get_average_usd_price('TE', 600, array('0')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('a last-known-good rate past the max age is NOT charged', $threw, $message);

xreset();
xseed('TF', array('coingecko' => 0));
set_transient('nmmpro_rate_good_TF', array('price' => 123.45, 'time' => time() - 600), 86400);
$GLOBALS['xr_filters']['nmmpro_rate_max_stale_seconds'] = function ($seconds) { return 60; };
$threw = false;
try { NMMPRO_Exchange::get_average_usd_price('TF', 600, array('0')); }
catch (\Exception $e) { $threw = true; }
xok('nmmpro_rate_max_stale_seconds can shorten the stale tier', $threw);

xreset();
xseed('TG', array('coingecko' => 0));
set_transient('nmmpro_rate_good_TG', array('price' => 123.45, 'time' => time() - 600), 86400);
$GLOBALS['xr_filters']['nmmpro_rate_max_stale_seconds'] = function ($seconds) { return 0; };
$threw = false;
try { NMMPRO_Exchange::get_average_usd_price('TG', 600, array('0')); }
catch (\Exception $e) { $threw = true; }
xok('nmmpro_rate_max_stale_seconds => 0 disables the stale tier', $threw);

xreset();
xseed('TH', array('coingecko' => 200.0));
set_transient('nmmpro_rate_good_TH', array('price' => 100.0, 'time' => time()), 86400);
$th = NMMPRO_Exchange::get_average_usd_price('TH', 600, array('0'));
xok('end to end: an uncorroborated jump is not charged; the anchor is', xnear($th, 100), 'got=' . $th);
$pendingTH = get_transient('nmmpro_rate_pending_TH');
xok('end to end: the rejected level is persisted as pending',
	is_array($pendingTH) && xnear($pendingTH['price'], 200));
$goodTH = get_transient('nmmpro_rate_good_TH');
xok('end to end: a rejected jump does not overwrite last-known-good', xnear($goodTH['price'], 100));

xreset();
xseed('TI', array('coingecko' => 200.0, 'hitbtc' => 201.0));
set_transient('nmmpro_rate_good_TI', array('price' => 100.0, 'time' => time()), 86400);
$ti = NMMPRO_Exchange::get_average_usd_price('TI', 600, array('0', '1'));
xok('end to end: the same jump corroborated by a 2nd source IS charged', xnear($ti, 200), 'got=' . $ti);
xok('end to end: last-known-good follows the corroborated move',
	xnear(get_transient('nmmpro_rate_good_TI')['price'], 200));

xreset();
xseed('TJ', array('coingecko' => 100.0, 'hitbtc' => 130.0));
$threw = false; $message = '';
try { NMMPRO_Exchange::get_average_usd_price('TJ', 600, array('0', '1')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('end to end: two irreconcilable sources fail checkout', $threw);
xok('end to end: the unconfirmed-rate message is distinct',
	strpos($message, 'could not be confirmed') !== false, $message);

xreset();
xseed('TK', array('coingecko' => 1e308, 'hitbtc' => 1e308, 'gateio' => 1e308, 'binance' => 1e308));
$threw = false; $message = ''; $returned = 'never returned';
try { $returned = NMMPRO_Exchange::get_average_usd_price('TK', 600, array('0', '1', '2', '3')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('end to end: four sources agreeing on 1e308 fail checkout', $threw, 'returned ' . var_export($returned, true));
xok('end to end: it is the "no sources" path, not a 0 price',
	strpos($message, 'No cryptocurrency exchanges could be reached') !== false, $message);
xok('end to end: an absurd rate is never stored as last-known-good', get_transient('nmmpro_rate_good_TK') === false);
xok('end to end: the absurd quotes are logged at WARNING level', xlogged('that is not a price', 'warning'));

xreset();
xseed('TL', array('coingecko' => 1e308, 'hitbtc' => 100.0, 'gateio' => 101.0, 'binance' => 102.0));
$tl = NMMPRO_Exchange::get_average_usd_price('TL', 600, array('0', '1', '2', '3'));
xok('end to end: one absurd source does not stop the other three', xnear($tl, 101), 'got=' . $tl);

xreset();
xseed('TM', array('coingecko' => 5000.0, 'hitbtc' => 5000.0));
$GLOBALS['xr_filters']['nmmpro_rate_max_plausible_price'] = function ($price) { return 1000.0; };
$threw = false;
try { NMMPRO_Exchange::get_average_usd_price('TM', 600, array('0', '1')); }
catch (\Exception $e) { $threw = true; }
xok('nmmpro_rate_max_plausible_price can tighten the bound', $threw);

xreset();
xseed('TN', array('coingecko' => 1e308, 'hitbtc' => 1e308));
$GLOBALS['xr_filters']['nmmpro_rate_max_plausible_price'] = function ($price) { return 1e300; };
$threw = false;
try { NMMPRO_Exchange::get_average_usd_price('TN', 600, array('0', '1')); }
catch (\Exception $e) { $threw = true; }
xok('no filter can widen the bound back into the zero-rounding range', $threw);

// The ratchet, driven through the real entry point and the real transients.
xreset();
set_transient('nmmpro_rate_good_TO', array('price' => 100.0, 'time' => time()), 86400);
$charged = 100.0;
$quote = 100.0;
for ($i = 0; $i < 10; $i++) {
	$quote *= 1.099;
	set_transient('nmmpro_rate_coingecko_TO', $quote, 600);
	try { $charged = NMMPRO_Exchange::get_average_usd_price('TO', 600, array('0')); }
	catch (\Exception $e) { $charged = -1.0; break; }
}
xok('end to end: ten sub-threshold steps cannot walk the charged rate to 257',
	$charged > 0 && $charged <= 140.0, 'charged=' . $charged);
xok('end to end: the drifted price never becomes last-known-good',
	get_transient('nmmpro_rate_good_TO')['price'] <= 140.0);
xok('end to end: the stored reference stayed put', xnear(get_transient('nmmpro_rate_reference_TO')['price'], 100));
xok('end to end: the cumulative rejection is logged at WARNING level',
	xlogged('per reference window', 'warning'));

// When the bound trips it degrades to "not trusted": the anchor is served while
// the stale tier lasts, and checkout errors afterwards. It never charges the
// drifted price.
xreset();
set_transient('nmmpro_rate_good_TP', array('price' => 130.0, 'time' => time()), 86400);
set_transient('nmmpro_rate_reference_TP', array('price' => 100.0, 'time' => time()), 86400);
xseed('TP', array('coingecko' => 142.0));
$tp = NMMPRO_Exchange::get_average_usd_price('TP', 600, array('0'));
xok('end to end: a drift-rejected tick serves the anchor, not the drifted price', xnear($tp, 130), 'got=' . $tp);
xok('end to end: 142 is under the 10% jump threshold from 130, so only the cumulative guard could have caught it',
	abs(142.0 - 130.0) / 130.0 < 0.10);
xok('end to end: the reference is not re-based by a rejected tick',
	xnear(get_transient('nmmpro_rate_reference_TP')['price'], 100));

xreset();
set_transient('nmmpro_rate_good_TQ', array('price' => 130.0, 'time' => time()), 86400);
set_transient('nmmpro_rate_reference_TQ', array('price' => 100.0, 'time' => time()), 86400);
xseed('TQ', array('coingecko' => 142.0));
$GLOBALS['xr_filters']['nmmpro_rate_max_stale_seconds'] = function ($seconds) { return 0; };
$threw = false; $message = '';
try { NMMPRO_Exchange::get_average_usd_price('TQ', 600, array('0')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('end to end: with no stale tier a drift rejection errors rather than charging', $threw);
xok('end to end: the customer sees the unconfirmed-rate message',
	strpos($message, 'could not be confirmed') !== false, $message);

// ...and the same tick with a second agreeing source is charged normally.
xreset();
set_transient('nmmpro_rate_good_TR', array('price' => 130.0, 'time' => time()), 86400);
set_transient('nmmpro_rate_reference_TR', array('price' => 100.0, 'time' => time()), 86400);
xseed('TR', array('coingecko' => 142.0, 'hitbtc' => 142.0));
$tr = NMMPRO_Exchange::get_average_usd_price('TR', 600, array('0', '1'));
xok('end to end: the same drift corroborated by a 2nd source IS charged', xnear($tr, 142), 'got=' . $tr);
xok('end to end: a corroborated drift re-bases the reference',
	xnear(get_transient('nmmpro_rate_reference_TR')['price'], 142));

xreset();
set_transient('nmmpro_rate_good_TS', array('price' => 130.0, 'time' => time()), 86400);
set_transient('nmmpro_rate_reference_TS', array('price' => 100.0, 'time' => time()), 86400);
xseed('TS', array('coingecko' => 142.0));
$GLOBALS['xr_filters']['nmmpro_rate_reference_drift_limit'] = function ($fraction) { return 0.95; };
$ts = NMMPRO_Exchange::get_average_usd_price('TS', 600, array('0'));
xok('nmmpro_rate_reference_drift_limit can loosen the bound', xnear($ts, 142), 'got=' . $ts);

xreset();
set_transient('nmmpro_rate_good_TU', array('price' => 130.0, 'time' => time()), 86400);
set_transient('nmmpro_rate_reference_TU', array('price' => 100.0, 'time' => time() - 7200), 86400);
// 135 is +35%: inside the 40% limit, so the shortened window lets it through
// and re-bases. (142 would be +42% and is refused however old the reference is
// - an elapsed window changes WHEN the reference may follow, never whether the
// price itself has to pass the limit.)
xseed('TU', array('coingecko' => 135.0));
$GLOBALS['xr_filters']['nmmpro_rate_reference_window_seconds'] = function ($seconds) { return 3600; };
$tu = NMMPRO_Exchange::get_average_usd_price('TU', 600, array('0'));
xok('nmmpro_rate_reference_window_seconds can shorten the window', xnear($tu, 135), 'got=' . $tu);
xok('a shortened window re-bases the reference onto the accepted price',
	xnear(get_transient('nmmpro_rate_reference_TU')['price'], 135));

echo "\n--- public API other callers depend on ---\n";

foreach (array('get_order_total_in_usd', 'get_average_usd_price', 'get_coingecko_price', 'get_cryptocompare_price',
	'get_hitbtc_price', 'get_gateio_price', 'get_binance_price', 'get_poloniex_price') as $method) {
	xok('NMMPRO_Exchange::' . $method . '() still exists', method_exists('NMMPRO_Exchange', $method));
}
$sig = new ReflectionMethod('NMMPRO_Exchange', 'get_average_usd_price');
xok('get_average_usd_price() keeps its 3-argument signature',
	$sig->isStatic() && $sig->isPublic() && $sig->getNumberOfParameters() === 3);

xok('no HTTP request was made', $GLOBALS['xr_http_calls'] === 0, implode(' ', $GLOBALS['xr_http_urls']));

printf("\n%d checks run\n", $GLOBALS['xr_checks']);
echo $GLOBALS['xr_ok'] ? "EXCHANGE-RATE CHECKS PASSED\n" : "EXCHANGE-RATE CHECKS FAILED\n";

if (!$GLOBALS['xr_ok']) { exit(1); }
