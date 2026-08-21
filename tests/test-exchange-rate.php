<?php
/**
 * Offline test: NMM_Exchange agrees the USD rate that sets what a customer is
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

// NMM_Util::log() routes to WooCommerce's logger when one exists; give it one
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
$GLOBALS['nmm_http_handler'] = function ($url, $method = 'GET', $body = null, $headers = array()) {
	$GLOBALS['xr_http_calls']++;
	$GLOBALS['xr_http_urls'][] = $url;
	return new WP_Error_Stub();
};
$GLOBALS['xr_http_urls'] = array();

nmm_test_require_plugin(array('src/NMM_Util.php', 'src/NMM_Exchange.php'));

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
	$GLOBALS['nmm_test_transients'] = array();
	$GLOBALS['xr_log'] = array();
	$GLOBALS['xr_filters'] = array();
}

function xseed($coin, $prices) {
	foreach ($prices as $source => $value) {
		set_transient($source . '_' . $coin . '_price', $value, 600);
	}
}

echo "\n--- median helper ---\n";

xok('median of 3 is the middle value', xnear(NMM_Exchange::median(array(3, 1, 2)), 2));
xok('median of 4 is the midpoint of the two centres', xnear(NMM_Exchange::median(array(1, 2, 3, 4)), 2.5));
xok('median of 5 is the middle value', xnear(NMM_Exchange::median(array(9, 1, 5, 2, 7)), 5));
xok('median of nothing is 0.0', NMM_Exchange::median(array()) === 0.0);
xok('median ignores zero and negative quotes', xnear(NMM_Exchange::median(array(0, -1, 5, 7)), 6));

echo "\n--- consensus: 3+ sources (median, then outlier rejection) ---\n";

$c3 = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 102.0, 'c' => 104.0));
xok('3 sources: median used', xnear($c3['price'], 102), 'got=' . $c3['price']);
xok('3 sources: status ok, all kept', $c3['status'] === 'ok' && count($c3['kept']) === 3 && count($c3['rejected']) === 0);

$c4 = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 102.0, 'c' => 104.0, 'd' => 106.0));
xok('4 sources: median of the two centres', xnear($c4['price'], 103), 'got=' . $c4['price']);

$c5 = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'd' => 103.0, 'e' => 104.0));
xok('5 sources: median used', xnear($c5['price'], 102), 'got=' . $c5['price']);

$agree = NMM_Exchange::consensus_price(array('a' => 200.0, 'b' => 200.0, 'c' => 200.0));
xok('all-agree case is unchanged by the new logic', xnear($agree['price'], 200)
	&& count($agree['rejected']) === 0 && count($agree['warnings']) === 0);

$high = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'hijacked' => 500.0));
xok('one wild HIGH outlier is discarded', xnear($high['price'], 101), 'got=' . $high['price']);
xok('the discarded source is named', $high['rejected'] === array('hijacked'));
xok('discarding an outlier is warned about', in_array('outlier_rejected', xcodes($high['warnings']), true));
xok('the mean would have been badly wrong', !xnear($high['price'], (100 + 101 + 102 + 500) / 4));

$low = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'c' => 102.0, 'stale' => 10.0));
xok('one wild LOW outlier is discarded', xnear($low['price'], 101), 'got=' . $low['price']);

// 3 sources with one outlier leaves 2 survivors, whose median is their midpoint
// - documented, and still nothing like the mean (233.67) the old code charged.
$threeOut = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 101.0, 'hijacked' => 500.0));
xok('3 sources, 1 outlier: the 2 survivors set the price', xnear($threeOut['price'], 100.5)
	&& $threeOut['rejected'] === array('hijacked'), 'got=' . $threeOut['price']);

$split = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 200.0, 'c' => 300.0));
xok('a 3-way split leaves nothing corroborated', $split['price'] === null && $split['status'] === 'disagree');
xok('the 3-way split is warned about', in_array('no_consensus', xcodes($split['warnings']), true));

$tight = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 102.0, 'c' => 104.0), 0.005);
xok('a tighter tolerance is honoured', $tight['price'] === null && $tight['status'] === 'disagree');

echo "\n--- consensus: small-n rules ---\n";

$one = NMM_Exchange::consensus_price(array('CoinGecko' => 100.0));
xok('1 source: the price is still used', xnear($one['price'], 100) && $one['status'] === 'single');
xok('1 source: warned loudly', in_array('single_source', xcodes($one['warnings']), true));

$two = NMM_Exchange::consensus_price(array('CoinGecko' => 100.0, 'HitBTC' => 103.0));
xok('2 agreeing sources: the LOWER is used', xnear($two['price'], 100) && $two['status'] === 'ok', 'got=' . $two['price']);

$twoRev = NMM_Exchange::consensus_price(array('CoinGecko' => 103.0, 'HitBTC' => 100.0));
xok('2 agreeing sources: order does not matter', xnear($twoRev['price'], 100));

$edge = NMM_Exchange::consensus_price(array('a' => 100.0, 'b' => 105.0));
xok('2 sources just inside the tolerance still agree', xnear($edge['price'], 100), 'spread=4.88%');

$twoBad = NMM_Exchange::consensus_price(array('CoinGecko' => 100.0, 'HitBTC' => 130.0));
xok('2 disagreeing sources: neither is trusted', $twoBad['price'] === null && $twoBad['status'] === 'disagree');
xok('2 disagreeing sources: warned', in_array('two_source_disagree', xcodes($twoBad['warnings']), true));

$none = NMM_Exchange::consensus_price(array());
xok('0 sources: null, never 0', $none['price'] === null && $none['status'] === 'none' && $none['sources'] === 0);

$dead = NMM_Exchange::consensus_price(array('a' => 0, 'b' => -3, 'c' => 100.0));
xok('dead sources (0 / negative) do not count as sources', $dead['sources'] === 1 && xnear($dead['price'], 100));

echo "\n--- movement guard against the last-known-good rate ---\n";

$now = 1700000000;
$fresh = array('price' => 100.0, 'time' => $now);

$noAnchor = NMM_Exchange::evaluate_rate(array('CoinGecko' => 100.0), null, null, $now, array());
xok('no anchor yet: the price is accepted', xnear($noAnchor['price'], 100) && $noAnchor['pending'] === null);

$small = NMM_Exchange::evaluate_rate(array('CoinGecko' => 105.0), $fresh, null, $now, array());
xok('a move inside the threshold is accepted', xnear($small['price'], 105));

$jump = NMM_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh, null, $now, array());
xok('a 100% jump from ONE source is rejected', $jump['price'] === null && $jump['status'] === 'jump_rejected');
xok('the rejected jump is warned about', in_array('jump_rejected', xcodes($jump['warnings']), true));
xok('the rejected level is remembered as pending',
	is_array($jump['pending']) && xnear($jump['pending']['price'], 200) && $jump['pending']['first_seen'] === $now);

$jump2 = NMM_Exchange::evaluate_rate(array('CoinGecko' => 200.0, 'HitBTC' => 202.0), $fresh, null, $now, array());
xok('the SAME jump corroborated by a 2nd source is accepted', xnear($jump2['price'], 200), 'got=' . $jump2['price']);
xok('the corroborated jump is warned about', in_array('jump_corroborated', xcodes($jump2['warnings']), true));

$jump3 = NMM_Exchange::evaluate_rate(array('a' => 200.0, 'b' => 201.0, 'c' => 202.0), $fresh, null, $now, array());
xok('the same jump corroborated by 3 sources is accepted', xnear($jump3['price'], 201));

$held = NMM_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh,
	array('price' => 199.0, 'first_seen' => $now - 1000), $now, array());
xok('a single source that HELD the new level past the window is accepted', xnear($held['price'], 200));
xok('time-corroboration is warned about', in_array('jump_confirmed_over_time', xcodes($held['warnings']), true));
xok('the pending record is cleared once promoted', $held['pending'] === null);

$young = NMM_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh,
	array('price' => 199.0, 'first_seen' => $now - 100), $now, array());
xok('a pending move younger than the window is still rejected', $young['price'] === null);
xok('the corroboration clock is not reset each tick', $young['pending']['first_seen'] === $now - 100);

$moved = NMM_Exchange::evaluate_rate(array('CoinGecko' => 200.0), $fresh,
	array('price' => 150.0, 'first_seen' => $now - 1000), $now, array());
xok('a pending move to a DIFFERENT level restarts the clock',
	$moved['price'] === null && $moved['pending']['first_seen'] === $now);

$staleAnchor = NMM_Exchange::evaluate_rate(array('CoinGecko' => 200.0),
	array('price' => 100.0, 'time' => $now - 4000), null, $now, array());
xok('an anchor older than the stale window does not gate the price', xnear($staleAnchor['price'], 200));

$deadTick = NMM_Exchange::evaluate_rate(array(), $fresh,
	array('price' => 200.0, 'first_seen' => $now - 100), $now, array());
xok('a tick that reached nobody keeps the pending move alive',
	$deadTick['price'] === null && $deadTick['pending']['first_seen'] === $now - 100);

$loose = NMM_Exchange::evaluate_rate(array('CoinGecko' => 140.0), $fresh, null, $now, array('jump' => 0.5));
xok('the jump threshold is configurable', xnear($loose['price'], 140));

echo "\n--- shared entry point: get_average_usd_price (checkout + cache warmer) ---\n";

xreset();
xseed('TA', array('coingecko' => 100.0, 'hitbtc' => 101.0, 'binance' => 102.0, 'gateio' => 500.0));
$ta = NMM_Exchange::get_average_usd_price('TA', 600, array('0', '1', '2', '3'));
xok('end to end: the hijacked source is discarded', xnear($ta, 101), 'got=' . $ta);
$goodTA = get_transient('nmm_rate_good_TA');
xok('end to end: the agreed rate is stored as last-known-good',
	is_array($goodTA) && xnear($goodTA['price'], 101) && $goodTA['time'] > 0);
$taAgain = NMM_Exchange::get_average_usd_price('TA', 600, array('0', '1', '2', '3'));
xok('the warmer and checkout share one entry point and one answer', xnear($taAgain, $ta));

xreset();
xseed('TB', array('coingecko' => 100.0));
$tb = NMM_Exchange::get_average_usd_price('TB', 600, array('0'));
xok('1 selected API: the price is used', xnear($tb, 100));
xok('1 selected API: logged at WARNING level', xlogged('single price source', 'warning'));

xreset();
xseed('TC', array('coingecko' => 0));
$threw = false; $message = '';
try { NMM_Exchange::get_average_usd_price('TC', 600, array('0')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('0 sources and no history: throws, never returns 0', $threw);
xok('0 sources: the customer-facing message is the unreachable one',
	strpos($message, 'No cryptocurrency exchanges could be reached') !== false);

xreset();
xseed('TD', array('coingecko' => 0));
set_transient('nmm_rate_good_TD', array('price' => 123.45, 'time' => time() - 600), 86400);
$td = NMM_Exchange::get_average_usd_price('TD', 600, array('0'));
xok('all fetches dead: the last-known-good rate is served inside the max age', xnear($td, 123.45));
xok('serving a stale rate is logged at WARNING level', xlogged('Serving last-known-good', 'warning'));

xreset();
xseed('TE', array('coingecko' => 0));
set_transient('nmm_rate_good_TE', array('price' => 123.45, 'time' => time() - 3600), 86400);
$threw = false; $message = '';
try { NMM_Exchange::get_average_usd_price('TE', 600, array('0')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('a last-known-good rate past the max age is NOT charged', $threw, $message);

xreset();
xseed('TF', array('coingecko' => 0));
set_transient('nmm_rate_good_TF', array('price' => 123.45, 'time' => time() - 600), 86400);
$GLOBALS['xr_filters']['nmm_rate_max_stale_seconds'] = function ($seconds) { return 60; };
$threw = false;
try { NMM_Exchange::get_average_usd_price('TF', 600, array('0')); }
catch (\Exception $e) { $threw = true; }
xok('nmm_rate_max_stale_seconds can shorten the stale tier', $threw);

xreset();
xseed('TG', array('coingecko' => 0));
set_transient('nmm_rate_good_TG', array('price' => 123.45, 'time' => time() - 600), 86400);
$GLOBALS['xr_filters']['nmm_rate_max_stale_seconds'] = function ($seconds) { return 0; };
$threw = false;
try { NMM_Exchange::get_average_usd_price('TG', 600, array('0')); }
catch (\Exception $e) { $threw = true; }
xok('nmm_rate_max_stale_seconds => 0 disables the stale tier', $threw);

xreset();
xseed('TH', array('coingecko' => 200.0));
set_transient('nmm_rate_good_TH', array('price' => 100.0, 'time' => time()), 86400);
$th = NMM_Exchange::get_average_usd_price('TH', 600, array('0'));
xok('end to end: an uncorroborated jump is not charged; the anchor is', xnear($th, 100), 'got=' . $th);
$pendingTH = get_transient('nmm_rate_pending_TH');
xok('end to end: the rejected level is persisted as pending',
	is_array($pendingTH) && xnear($pendingTH['price'], 200));
$goodTH = get_transient('nmm_rate_good_TH');
xok('end to end: a rejected jump does not overwrite last-known-good', xnear($goodTH['price'], 100));

xreset();
xseed('TI', array('coingecko' => 200.0, 'hitbtc' => 201.0));
set_transient('nmm_rate_good_TI', array('price' => 100.0, 'time' => time()), 86400);
$ti = NMM_Exchange::get_average_usd_price('TI', 600, array('0', '1'));
xok('end to end: the same jump corroborated by a 2nd source IS charged', xnear($ti, 200), 'got=' . $ti);
xok('end to end: last-known-good follows the corroborated move',
	xnear(get_transient('nmm_rate_good_TI')['price'], 200));

xreset();
xseed('TJ', array('coingecko' => 100.0, 'hitbtc' => 130.0));
$threw = false; $message = '';
try { NMM_Exchange::get_average_usd_price('TJ', 600, array('0', '1')); }
catch (\Exception $e) { $threw = true; $message = $e->getMessage(); }
xok('end to end: two irreconcilable sources fail checkout', $threw);
xok('end to end: the unconfirmed-rate message is distinct',
	strpos($message, 'could not be confirmed') !== false, $message);

echo "\n--- public API other callers depend on ---\n";

foreach (array('get_order_total_in_usd', 'get_average_usd_price', 'get_coingecko_price', 'get_cryptocompare_price',
	'get_hitbtc_price', 'get_gateio_price', 'get_binance_price', 'get_poloniex_price') as $method) {
	xok('NMM_Exchange::' . $method . '() still exists', method_exists('NMM_Exchange', $method));
}
$sig = new ReflectionMethod('NMM_Exchange', 'get_average_usd_price');
xok('get_average_usd_price() keeps its 3-argument signature',
	$sig->isStatic() && $sig->isPublic() && $sig->getNumberOfParameters() === 3);

xok('no HTTP request was made', $GLOBALS['xr_http_calls'] === 0, implode(' ', $GLOBALS['xr_http_urls']));

printf("\n%d checks run\n", $GLOBALS['xr_checks']);
echo $GLOBALS['xr_ok'] ? "EXCHANGE-RATE CHECKS PASSED\n" : "EXCHANGE-RATE CHECKS FAILED\n";

if (!$GLOBALS['xr_ok']) { exit(1); }
