<?php
/**
 * Offline test: NMM_Settings clamps merchant-supplied numeric settings to their
 * documented ranges. The settings screen only ever applied HTML min/max, which a
 * crafted POST, a hand-edited row, a bad import or a damaged option bypasses -
 * and the resulting values are not cosmetic: a negative confirmation
 * requirement accepts unconfirmed payments, a zero cancellation timer cancels
 * orders on sight, and a low processing percentage settles orders for less than
 * was owed.
 *
 *   Run:  php tests/test-settings-bounds.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
if (!defined('NMM_REDUX_ID')) { define('NMM_REDUX_ID', 'nmmpro_redux_options'); }

function get_option($key, $default = array()) { return $default; }
function apply_filters($tag, $value) { return $value; }

require dirname(__DIR__) . '/src/NMM_Util.php';
require dirname(__DIR__) . '/src/NMM_Settings.php';

$failed = false;
function sok($label, $pass, $extra = '') {
	global $failed;
	printf("%-62s %s%s\n", $label, $pass ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$pass) { $failed = true; }
}

// Build a settings object holding one raw stored value for BTC.
function with($key, $value) {
	return new NMM_Settings(array('BTC' . $key => $value));
}

// --- confirmations: a negative requirement would accept unconfirmed payments ---
sok('negative autopay confirmations clamp to 0',
	(float) with('_autopayment_required_confirmations', '-5')->get_autopay_required_confirmations('BTC') === 0.0);
sok('absurd autopay confirmations clamp to 100',
	(float) with('_autopayment_required_confirmations', '99999')->get_autopay_required_confirmations('BTC') === 100.0);
sok('in-range autopay confirmations pass through',
	(float) with('_autopayment_required_confirmations', '6')->get_autopay_required_confirmations('BTC') === 6.0);
sok('negative HD confirmations clamp to 0',
	(float) with('_hd_required_confirmations', '-1')->get_hd_required_confirmations('BTC') === 0.0);

// --- cancellation timers: 0 would cancel an order the instant it is placed ---
sok('zero autopay cancellation time clamps to the 0.01h floor',
	(float) with('_autopayment_order_cancellation_time_hr', '0')->get_autopay_cancellation_time('BTC') === 0.01);
sok('negative autopay cancellation time clamps to the floor',
	(float) with('_autopayment_order_cancellation_time_hr', '-48')->get_autopay_cancellation_time('BTC') === 0.01);
sok('over-long autopay cancellation time clamps to 168h',
	(float) with('_autopayment_order_cancellation_time_hr', '10000')->get_autopay_cancellation_time('BTC') === 168.0);
sok('in-range autopay cancellation time passes through',
	(float) with('_autopayment_order_cancellation_time_hr', '1.5')->get_autopay_cancellation_time('BTC') === 1.5);
sok('zero HD cancellation time clamps to the floor',
	(float) with('_hd_order_cancellation_time_hr', '0')->get_hd_cancellation_time('BTC') === 0.01);

// --- processing percent: too low settles an order for less than was owed ---
sok('tiny autopay percent clamps to the 0.985 floor',
	(float) with('_autopayment_percent_to_process', '0.01')->get_autopay_processing_percent('BTC') === 0.985);
sok('negative autopay percent clamps to the floor',
	(float) with('_autopayment_percent_to_process', '-1')->get_autopay_processing_percent('BTC') === 0.985);
sok('autopay percent above 1 clamps to 1',
	(float) with('_autopayment_percent_to_process', '5')->get_autopay_processing_percent('BTC') === 1.0);
sok('in-range autopay percent passes through',
	(float) with('_autopayment_percent_to_process', '0.999')->get_autopay_processing_percent('BTC') === 0.999);
sok('tiny HD percent clamps to the 0.8 floor',
	(float) with('_hd_percent_to_process', '0.01')->get_hd_processing_percent('BTC') === 0.8);

// --- markup: -100% or worse would make the order free ---
sok('markup below -99.9 clamps to -99.9',
	(float) with('_markup', '-100')->get_markup('BTC') === -99.9);
sok('markup above 100 clamps to 100',
	(float) with('_markup', '500')->get_markup('BTC') === 100.0);
sok('in-range markup passes through',
	(float) with('_markup', '4.8')->get_markup('BTC') === 4.8);
sok('markup keeps its stored precision',
	with('_markup', '4.80')->get_markup('BTC') === '4.80', 'got=' . with('_markup', '4.80')->get_markup('BTC'));
sok('markup is still trimmed',
	with('_markup', '  4.8 ')->get_markup('BTC') === '4.8');

// --- junk: not clampable, so fall back to the documented default ---
sok('non-numeric confirmations fall back to the default',
	(float) with('_autopayment_required_confirmations', 'lots')->get_autopay_required_confirmations('BTC') === 2.0);
sok('blank cancellation time falls back to the default',
	(float) with('_autopayment_order_cancellation_time_hr', '')->get_autopay_cancellation_time('BTC') === 24.0);
sok('array-valued percent falls back to the default (no TypeError)',
	(float) with('_autopayment_percent_to_process', array('x'))->get_autopay_processing_percent('BTC') === 0.999);
sok('null markup falls back to the default',
	(float) with('_markup', null)->get_markup('BTC') === 0.0);

// --- absent keys keep the historical defaults: a store that never saved the
// field must not change behaviour on upgrade ---
$empty = new NMM_Settings(array());
sok('absent autopay cancellation time keeps the 24h default',
	(float) $empty->get_autopay_cancellation_time('BTC') === 24.0);
sok('absent autopay percent keeps the 0.999 default',
	(float) $empty->get_autopay_processing_percent('BTC') === 0.999);
sok('absent confirmations keep the default of 2',
	(float) $empty->get_autopay_required_confirmations('BTC') === 2.0);
sok('absent markup keeps 0.0',
	(float) $empty->get_markup('BTC') === 0.0);
sok('non-array settings do not fatal',
	(float) (new NMM_Settings(null))->get_autopay_cancellation_time('BTC') === 24.0);

// --- every default the getters fall back to must itself be inside the bounds,
// or a store with no saved value would run on an out-of-range setting ---
$bounds = NMM_Settings::NUMERIC_BOUNDS;
$defaults = array(
	'_markup'                                 => (float) $empty->get_markup('BTC'),
	'_hd_percent_to_process'                  => (float) $empty->get_hd_processing_percent('BTC'),
	'_hd_required_confirmations'              => (float) $empty->get_hd_required_confirmations('BTC'),
	'_hd_order_cancellation_time_hr'          => (float) $empty->get_hd_cancellation_time('BTC'),
	'_autopayment_percent_to_process'         => (float) $empty->get_autopay_processing_percent('BTC'),
	'_autopayment_required_confirmations'     => (float) $empty->get_autopay_required_confirmations('BTC'),
	'_autopayment_order_cancellation_time_hr' => (float) $empty->get_autopay_cancellation_time('BTC'),
);
foreach ($defaults as $suffix => $value) {
	sok('default for ' . $suffix . ' is within bounds',
		$value >= $bounds[$suffix]['min'] && $value <= $bounds[$suffix]['max'],
		'default=' . $value);
}

// Every bounds entry must be sane in itself.
foreach ($bounds as $suffix => $b) {
	sok('bounds for ' . $suffix . ' are ordered', $b['min'] < $b['max'], $b['min'] . '..' . $b['max']);
}

echo $failed ? "\nSETTINGS-BOUNDS CHECKS FAILED\n" : "\nSETTINGS-BOUNDS CHECKS PASSED\n";
if ($failed) { exit(1); }
