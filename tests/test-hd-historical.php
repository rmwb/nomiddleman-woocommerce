<?php
/**
 * Live-DB regression: historical receipts on a Privacy Mode (HD) address must
 * never pay a new order, and a ready-pool address that has chain history must
 * never be shown to a customer.
 *
 * Reproduces the September 2026 incident: an order owing 456.80863668 DOGE was
 * marked paid because the address's LIFETIME receipts (3800 DOGE, received
 * years before the order existed) cleared the threshold. The real order and
 * address are not recorded in this repository; every fixture here uses a
 * synthetic identity, and the fixture's dates and receipts are invented.
 *
 * Every assertion states the REQUIRED behaviour. Before the HD safety patch
 * the incident and ready-pool assertions fail; that failure is the recorded
 * reproduction (docs/HD-PAYMENT-SAFETY-REPORT.md, Step A). Do not weaken an
 * assertion to make the old behaviour pass.
 *
 * BlockCypher is answered offline through pre_http_request with fixed UTC
 * times, amounts, transaction ids and output indexes. Any request the mock
 * does not recognise is refused and recorded, so no test reaches a network.
 * Requires WordPress + WooCommerce + a database; destructive fixtures, so use
 * an isolated installation. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-hd-historical.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !defined('NMMPRO_HD_TABLE') || !function_exists('wc_create_order') || !class_exists('NMMPRO_Gateway')) {
	echo "test-hd-historical: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];

$GLOBALS['hh_ok']       = true;
$GLOBALS['hh_table']    = $wpdb->prefix . NMMPRO_HD_TABLE;
$GLOBALS['hh_mpk']      = 'test_mpk_hd_historical';
$GLOBALS['hh_ledger']   = array();   // address => list of BlockCypher txrefs
$GLOBALS['hh_requests'] = array();   // every URL the mock was asked for
$GLOBALS['hh_unmocked'] = array();   // URLs the mock refused

// A synthetic, valid xpub (the derivation suite's fixture): the checkout path
// derives real DOGE addresses from it, and nothing ever pays them.
const HH_XPUB = 'xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ';

// Fixed chain state. Dogecoin produces a block a minute; the heights are only
// required to be mutually consistent with the confirmation counts.
const HH_TIP_HEIGHT = 5900000;
const HH_ASSIGNED_AT_UTC = '2026-09-22T07:30:00Z';   // order's address assignment
const HH_VERIFIED_AT_UTC = '2026-09-22T07:40:45Z';   // the incident's "verified" note

function hh_ok($label, $cond, $extra = '') {
	printf("%-70s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['hh_ok'] = false; }
}

function hh_ts($isoUtc) {
	$dt = new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
	return $dt->getTimestamp();
}

function hh_hash($label) {
	return hash('sha256', 'nmmpro-hd-historical|' . $label);
}

// One incoming output to $address. $value is in koinu (1e-8 DOGE), as an
// integer, exactly as BlockCypher reports it.
function hh_receive($address, $label, $outputIndex, $value, $height, $confirmedIso, $spent = false) {
	$GLOBALS['hh_ledger'][$address][] = array(
		'tx_hash'       => hh_hash($label),
		'block_height'  => $height,
		'tx_input_n'    => -1,
		'tx_output_n'   => $outputIndex,
		'value'         => $value,
		'spent'         => $spent,
		'confirmations' => HH_TIP_HEIGHT - $height + 1,
		'confirmed'     => $confirmedIso,
		'double_spend'  => false,
	);
}

// One outgoing input spending from $address.
function hh_spend($address, $label, $inputIndex, $value, $height, $confirmedIso) {
	$GLOBALS['hh_ledger'][$address][] = array(
		'tx_hash'       => hh_hash($label),
		'block_height'  => $height,
		'tx_input_n'    => $inputIndex,
		'tx_output_n'   => -1,
		'value'         => $value,
		'spent'         => false,
		'confirmations' => HH_TIP_HEIGHT - $height + 1,
		'confirmed'     => $confirmedIso,
		'double_spend'  => false,
	);
}

function hh_address_summary($address) {
	$refs = isset($GLOBALS['hh_ledger'][$address]) ? $GLOBALS['hh_ledger'][$address] : array();
	$received = 0;
	$sent = 0;
	$txs = array();
	foreach ($refs as $ref) {
		if ($ref['tx_input_n'] === -1) { $received += $ref['value']; } else { $sent += $ref['value']; }
		$txs[$ref['tx_hash']] = true;
	}
	// Newest first, as BlockCypher orders txrefs.
	usort($refs, function ($a, $b) { return $b['block_height'] - $a['block_height']; });
	return array(
		'address'             => $address,
		'total_received'      => $received,
		'total_sent'          => $sent,
		'balance'             => $received - $sent,
		'unconfirmed_balance' => 0,
		'final_balance'       => $received - $sent,
		'n_tx'                => count($txs),
		'unconfirmed_n_tx'    => 0,
		'final_n_tx'          => count($txs),
		'txrefs'              => $refs,
	);
}

function hh_json($data) {
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode($data), 'headers' => array(), 'cookies' => array());
}

// BlockCypher DOGE, answered from the in-memory ledger. Address endpoints
// return a valid empty history for an address with no ledger entries - that is
// a real, successful answer, not a failure.
$GLOBALS['hh_mock'] = function ($pre, $args, $url) {
	$GLOBALS['hh_requests'][] = $url;
	$parts = wp_parse_url($url);
	$host = isset($parts['host']) ? $parts['host'] : '';
	$path = isset($parts['path']) ? $parts['path'] : '';

	if ($host === 'api.blockcypher.com') {
		if ($path === '/v1/doge/main') {
			return hh_json(array('name' => 'DOGE.main', 'height' => HH_TIP_HEIGHT));
		}
		if (preg_match('#^/v1/doge/main/addrs/([^/]+)/balance$#', $path, $m)) {
			$summary = hh_address_summary(rawurldecode($m[1]));
			unset($summary['txrefs']);
			return hh_json($summary);
		}
		if (preg_match('#^/v1/doge/main/addrs/([^/]+)$#', $path, $m)) {
			$summary = hh_address_summary(rawurldecode($m[1]));
			if ($summary['txrefs'] === array()) {
				unset($summary['txrefs']); // BlockCypher omits the key for an empty history
			}
			return hh_json($summary);
		}
	}

	$GLOBALS['hh_unmocked'][] = $url;
	return new WP_Error('hh_unmocked', 'test-hd-historical: unmocked request ' . $url);
};

function hh_requested($address) {
	foreach ($GLOBALS['hh_requests'] as $url) {
		if (strpos($url, '/addrs/' . rawurlencode($address)) !== false) {
			return true;
		}
	}
	return false;
}

function hh_row($address) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$GLOBALS['hh_table']}` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address), ARRAY_A);
}

function hh_notes($orderId) {
	$text = '';
	foreach (wc_get_order_notes(array('order_id' => $orderId, 'limit' => 100)) as $note) {
		$text .= ' ' . $note->content;
	}
	return $text;
}

function hh_order_status($orderId) {
	$order = wc_get_order($orderId);
	return $order ? $order->get_status() : '(gone)';
}

function hh_clear_backoff() {
	global $wpdb;
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
	wp_cache_flush();
}

// Assign a Privacy Mode address to an order the way the plugin records it.
// Kept in one place so later schema steps can extend the assignment record:
// with schema 1.5 that is a validated binding (assignment version, database
// boundary, proven-clean time) plus the order's own record of its gateway,
// coin and address - exactly what the checkout writes.
function hh_assign($address, $orderId, $orderAmount, $assignedAtIso) {
	global $wpdb;
	$table = $GLOBALS['hh_table'];
	$at = hh_ts($assignedAtIso);
	$wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address));
	if (class_exists('NMMPRO_Hd_Schema') && NMMPRO_Hd_Schema::ready()) {
		$wpdb->query($wpdb->prepare(
			"INSERT INTO `$table` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`total_received`,`assigned_at`,
			                      `assignment_version`,`pool_version`,`bound_at`,`validated_at`,`validated_height`)
			 VALUES (%s,'DOGE',%s,%d,'assigned',0,%d,%s,'0',%d,1,1,%d,%d,%d)",
			$address, $GLOBALS['hh_mpk'], 1000 + $orderId % 100000, $orderId, $orderAmount, $at, $at, $at - 5, HH_TIP_HEIGHT - 10
		));
	}
	else {
		$wpdb->query($wpdb->prepare(
			"INSERT INTO `$table` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`total_received`,`assigned_at`)
			 VALUES (%s,'DOGE',%s,%d,'assigned',0,%d,%s,'0',%d)",
			$address, $GLOBALS['hh_mpk'], 1000 + $orderId % 100000, $orderId, $orderAmount, $at
		));
	}
	$order = wc_get_order($orderId);
	$order->set_payment_method('nmm_gateway');
	$order->update_meta_data('crypto_type_id', 'DOGE');
	$order->update_meta_data('wallet_address', $address);
	$order->update_meta_data('crypto_amount', $orderAmount);
	$order->save();
}

function hh_mkorder($status) {
	$order = wc_create_order();
	$order->set_total('100.00');
	$order->update_meta_data('crypto_type_id', 'DOGE');
	$order->save();
	$order->update_status($status);
	return $order->get_id();
}

$GLOBALS['hh_payment_complete_calls'] = array();
add_action('woocommerce_payment_complete', function ($orderId) {
	$GLOBALS['hh_payment_complete_calls'][] = (int) $orderId;
}, 10, 1);

function hh_verify_sweep($requiredConfirmations = 2, $percent = '0.99') {
	add_filter('pre_http_request', $GLOBALS['hh_mock'], 10, 3);
	try {
		NMMPRO_Hd::reset_observed_totals();
		NMMPRO_Hd::check_all_pending_addresses_for_payment('DOGE', $GLOBALS['hh_mpk'], $requiredConfirmations, $percent, 0);
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['hh_mock'], 10);
	}
}

// The shared transaction ledger outlives orders by design (a transaction a
// deleted order owned can never pay another), so a previous run's synthetic
// entries must be cleared or they would legitimately block this run.
function hh_clear_ledger() {
	global $wpdb;
	if (class_exists('NMMPRO_Consumed_Repo')) {
		$wpdb->query("DELETE FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE `address` LIKE 'hh\\_synthetic\\_%'");
	}
	if (class_exists('NMMPRO_Hd_Schema') && NMMPRO_Hd_Schema::ready()) {
		$wpdb->query("DELETE e FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` e WHERE e.`address` LIKE 'hh\\_synthetic\\_%'");
	}
}

$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hh_table']}` WHERE `mpk` = %s", $GLOBALS['hh_mpk']));
hh_clear_ledger();
hh_clear_backoff();

// =====================================================================
// 1. The reported incident, through the real HD verifier.
//
// An on-hold DOGE order owes 456.80863668. Its address received 2000 DOGE in
// May 2021 (since spent) and 1800 DOGE in January 2022 - lifetime receipts of
// 3800, every one confirmed years before the address was assigned on
// 2026-09-22. Nothing has arrived since.
// =====================================================================
echo "--- 1. historical receipts (the reported incident) ---\n";

$incidentAddr = 'hh_synthetic_incident_addr';
hh_receive($incidentAddr, 'old-2021', 0, 200000000000, 3740512, '2021-05-08T14:22:17Z', true);
hh_spend($incidentAddr, 'old-2021-spend', 0, 200000000000, 3741006, '2021-05-08T22:41:09Z');
hh_receive($incidentAddr, 'old-2022', 1, 180000000000, 4055873, '2022-01-19T03:05:41Z');

$incidentSummary = hh_address_summary($incidentAddr);
hh_ok('fixture: lifetime receipts are 3800 DOGE',             $incidentSummary['total_received'] === 380000000000, 'koinu=' . $incidentSummary['total_received']);
hh_ok('fixture: every receipt predates the assignment',        hh_ts('2022-01-19T03:05:41Z') < hh_ts(HH_ASSIGNED_AT_UTC));

$incidentOrder = hh_mkorder('on-hold');
hh_assign($incidentAddr, $incidentOrder, '456.80863668', HH_ASSIGNED_AT_UTC);
$GLOBALS['hh_payment_complete_calls'] = array();
hh_verify_sweep();

$incidentRow = hh_row($incidentAddr);
hh_ok('the incident order is NOT marked paid',                  hh_order_status($incidentOrder) === 'on-hold', 'status=' . hh_order_status($incidentOrder));
hh_ok('  payment_complete() never ran for it',                 !in_array($incidentOrder, $GLOBALS['hh_payment_complete_calls'], true), 'calls=' . count($GLOBALS['hh_payment_complete_calls']));
hh_ok('  no payment-verified note was written',                strpos(hh_notes($incidentOrder), 'verified at') === false);
hh_ok('  its address row is not settled or completing',        $incidentRow && !in_array($incidentRow['status'], array('complete', 'completing'), true), 'row=' . ($incidentRow ? $incidentRow['status'] : '(missing)'));
hh_ok('  the order carries no transaction id',                 (string) wc_get_order($incidentOrder)->get_transaction_id() === '');
hh_ok('  no old transaction was recorded as consumed',         !NMMPRO_Consumed_Repo::contains('DOGE', $incidentAddr, hh_hash('old-2021')) && !NMMPRO_Consumed_Repo::contains('DOGE', $incidentAddr, hh_hash('old-2022')));

// A second sweep must not change that - the verifier runs every minute.
hh_verify_sweep();
hh_ok('  a repeat sweep still leaves it unpaid',               hh_order_status($incidentOrder) === 'on-hold', 'status=' . hh_order_status($incidentOrder));

// Control: the fixture and mock can complete an order. A genuinely new
// payment of the full amount, confirmed after assignment, pays. Without this,
// the incident assertions above could pass merely because the mock or the
// fixture never let anything through.
echo "--- 1b. control: a real post-assignment payment ---\n";
$controlAddr = 'hh_synthetic_control_addr';
hh_receive($controlAddr, 'new-control', 0, 45680863668, HH_TIP_HEIGHT - 5, '2026-09-22T07:36:12Z');
$controlOrder = hh_mkorder('on-hold');
hh_assign($controlAddr, $controlOrder, '456.80863668', HH_ASSIGNED_AT_UTC);
hh_verify_sweep();
hh_ok('control: a new, confirmed, full payment completes',     in_array(hh_order_status($controlOrder), array('processing', 'completed'), true), 'status=' . hh_order_status($controlOrder));
hh_ok('control: the mock refused no request',                  $GLOBALS['hh_unmocked'] === array(), implode(' ', array_slice($GLOBALS['hh_unmocked'], 0, 2)));

// =====================================================================
// 2. A ready-pool address that already has chain history, through the real
// checkout initializer.
//
// The lowest ready row (index 2 of a synthetic xpub) has received funds. The
// customer must never be shown it: the checkout has to check it on chain,
// reject it, retire it permanently, and issue a clean address instead.
// =====================================================================
echo "--- 2. stale ready-pool address at checkout ---\n";

$settingsBefore = get_option(NMMPRO_REDUX_ID, null);
$settings = is_array($settingsBefore) ? $settingsBefore : array();
$settings['crypto_select'] = array('DOGE');
$settings['DOGE_mode'] = '2';
$settings['DOGE_hd_mpk'] = HH_XPUB;
$settings['DOGE_hd_required_confirmations'] = '2';
$settings['DOGE_hd_percent_to_process'] = '0.99';
$settings['selected_price_apis'] = array('0');
update_option(NMMPRO_REDUX_ID, $settings);

// A fixed, cached DOGE price so checkout never calls a price API.
set_transient('nmmpro_rate_coingecko_DOGE', 0.25, HOUR_IN_SECONDS);
set_transient('nmmpro_rate_good_DOGE', array('price' => 0.25, 'time' => time()), HOUR_IN_SECONDS);
delete_transient('nmmpro_rate_pending_DOGE');

$checkoutSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));
hh_ok('fixture: DOGE Privacy Mode is enabled for checkout',     $checkoutSettings->hd_enabled('DOGE') && $checkoutSettings->get_mpk('DOGE') === HH_XPUB);

$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hh_table']}` WHERE `cryptocurrency` = 'DOGE' AND `mpk` = %s", HH_XPUB));
$staleAddr = NMMPRO_Hd::create_hd_address('DOGE', HH_XPUB, 2, '0');
$nextAddr  = NMMPRO_Hd::create_hd_address('DOGE', HH_XPUB, 3, '0');
hh_ok('fixture: derived two distinct DOGE addresses',           is_string($staleAddr) && is_string($nextAddr) && $staleAddr !== $nextAddr && $staleAddr[0] === 'D', $staleAddr . ' / ' . $nextAddr);
$stockRepo = new NMMPRO_Hd_Repo('DOGE', HH_XPUB, '0');
// A pool row as the running release derives it (the incident's shape: an
// address derived clean that received funds while it waited in the pool).
// Before the HD safety work there is only the plain 'ready' insert.
$pooled = method_exists($stockRepo, 'insert_pool') ? $stockRepo->insert_pool($staleAddr, 2) : $stockRepo->insert($staleAddr, 2, 'ready');
hh_ok('fixture: the stale address is in the ready pool',        $pooled === true);

// Historical receipts on the pooled address; index 3 onwards is clean.
hh_receive($staleAddr, 'pool-old-2023', 0, 5000000000, 4690233, '2023-04-02T11:17:50Z');

$checkoutOrder = wc_create_order();
$checkoutOrder->set_total('100.00');
$checkoutOrder->save();
$checkoutId = $checkoutOrder->get_id();

$GLOBALS['hh_requests'] = array();
add_filter('pre_http_request', $GLOBALS['hh_mock'], 10, 3);
try {
	$gateway = new NMMPRO_Gateway();
	$init = $gateway->initialize_order_payment($checkoutId, 'DOGE');
}
finally {
	remove_filter('pre_http_request', $GLOBALS['hh_mock'], 10);
}
$outcome = isset($init['outcome']) ? $init['outcome'] : '?';

$shown = wc_get_order($checkoutId);
$shown->read_meta_data(true);
$shownAddr = (string) $shown->get_meta('wallet_address');
$staleRow = hh_row($staleAddr);

echo "  checkout outcome: $outcome; shown address: " . ($shownAddr === '' ? '(none)' : $shownAddr) . "\n";
hh_ok('the stale address was checked on chain before exposure',  hh_requested($staleAddr));
hh_ok('the customer is NOT shown the stale address',            $shownAddr !== $staleAddr, 'shown=' . $shownAddr);
hh_ok('  it is not in the order notes either',                  strpos(hh_notes($checkoutId), $staleAddr) === false);
hh_ok('  its row is not assigned to this order',                $staleRow && (int) $staleRow['order_id'] !== $checkoutId, 'order_id=' . ($staleRow ? var_export($staleRow['order_id'], true) : '(missing)'));
hh_ok('  and it has left the ready pool for good',              $staleRow && !in_array($staleRow['status'], array('ready', 'assigned', 'quarantine', 'quarantine_verified'), true), 'row=' . ($staleRow ? $staleRow['status'] : '(missing)'));
hh_ok('checkout still issues an address',                       $outcome === 'initialized' && $shownAddr !== '', 'outcome=' . $outcome);
hh_ok('  that address was checked on chain first',              $shownAddr !== '' && hh_requested($shownAddr));
hh_ok('  and has no chain history',                             $shownAddr !== '' && !isset($GLOBALS['hh_ledger'][$shownAddr]));
hh_ok('the mock refused no request',                            $GLOBALS['hh_unmocked'] === array(), implode(' ', array_slice($GLOBALS['hh_unmocked'], 0, 2)));

// 2b. A pool row an older release left behind, with chain history. It must
// never reach a customer either, whether or not it is ever checked.
echo "--- 2b. legacy pool row at checkout ---\n";
// Index 1, below every other row: first in line if it were ever a candidate.
$legacyAddr = NMMPRO_Hd::create_hd_address('DOGE', HH_XPUB, 1, '0');
$stockRepo->insert($legacyAddr, 1, 'ready');
hh_receive($legacyAddr, 'legacy-pool-old', 0, 2500000000, 4201177, '2022-06-30T08:12:40Z');
$legacyOrder = wc_create_order();
$legacyOrder->set_total('100.00');
$legacyOrder->save();
add_filter('pre_http_request', $GLOBALS['hh_mock'], 10, 3);
try {
	$legacyInit = (new NMMPRO_Gateway())->initialize_order_payment($legacyOrder->get_id(), 'DOGE');
}
finally {
	remove_filter('pre_http_request', $GLOBALS['hh_mock'], 10);
}
$legacyShown = wc_get_order($legacyOrder->get_id());
$legacyShown->read_meta_data(true);
$legacyRow = hh_row($legacyAddr);
hh_ok('a legacy pool row with history is not shown',            (string) $legacyShown->get_meta('wallet_address') !== $legacyAddr, 'shown=' . $legacyShown->get_meta('wallet_address'));
hh_ok('  nor assigned to the order',                            $legacyRow && (int) $legacyRow['order_id'] !== $legacyOrder->get_id());
$legacyOrder->delete(true);

// --- cleanup: the harness database is shared between suites ---
$checkoutOrder = wc_get_order($checkoutId);
if ($checkoutOrder) { $checkoutOrder->delete(true); }
foreach (array($incidentOrder, $controlOrder) as $oid) {
	$o = wc_get_order($oid);
	if ($o) { $o->delete(true); }
}
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hh_table']}` WHERE `mpk` IN (%s, %s)", $GLOBALS['hh_mpk'], HH_XPUB));
hh_clear_ledger();
if ($settingsBefore === null) { delete_option(NMMPRO_REDUX_ID); } else { update_option(NMMPRO_REDUX_ID, $settingsBefore); }
delete_transient('nmmpro_rate_coingecko_DOGE');
delete_transient('nmmpro_rate_good_DOGE');
delete_transient('nmmpro_rate_reference_DOGE');
hh_clear_backoff();

echo $GLOBALS['hh_ok'] ? "\nHD-HISTORICAL CHECKS PASSED\n" : "\nHD-HISTORICAL CHECKS FAILED\n";
