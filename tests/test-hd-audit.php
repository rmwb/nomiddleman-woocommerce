<?php
/**
 * Live-DB test: the read-only Privacy Mode audit and manual reconciliation
 * (HD safety Step H).
 *
 * Audit (NMMPRO_Hd_Audit, `wp nmmpro-hd audit`):
 *   - reports versions, the upgrade's legacy counts, coin capabilities and
 *     state totals;
 *   - flags each address record: missing provenance, reused addresses,
 *     missing orders, record/order disagreement, paid without evidence;
 *   - with chain checks, separates confirmed false positives (every receipt
 *     predates the order - the incident's shape) from paid orders with
 *     absent or insufficient evidence and from credited evidence that has
 *     vanished; flags unpaid-but-funded orders and pool rows with history;
 *     reports explorer failures as unknown and respects its budget;
 *   - writes nothing, pages with a cursor, scopes by order and address, and
 *     prints no customer details.
 * Reconcile (NMMPRO_Hd_Reconcile, `wp nmmpro-hd reconcile`):
 *   - dry run by default; records ownership before acknowledging payment;
 *     refuses a transaction owned elsewhere or a malformed id; completes only
 *     an order awaiting payment; idempotent; never returns an address to the
 *     pool.
 *
 * DOGE via an offline BlockCypher fake. Requires WordPress + WooCommerce + a
 * database (and WP-CLI for the command check); destructive fixtures, so use
 * an isolated installation.
 *
 *   Run:  wp eval-file tests/test-hd-audit.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !class_exists('NMMPRO_Hd_Audit') || !function_exists('wc_create_order')) {
	echo "test-hd-audit: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$GLOBALS['hau_ok'] = true;
$GLOBALS['hau_table'] = $wpdb->prefix . NMMPRO_HD_TABLE;
$GLOBALS['hau_mpk'] = 'test_mpk_hd_audit';
$GLOBALS['hau_orders'] = array();
$GLOBALS['hau_book'] = array();
$GLOBALS['hau_fail'] = array();
$GLOBALS['hau_requests'] = array();
$GLOBALS['hau_created'] = time() - DAY_IN_SECONDS;     // every fixture order was placed a day ago
const HAU_TIP = 5000000;

function hau_ok($label, $cond, $extra = '') {
	printf("%-74s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['hau_ok'] = false; }
}
function hau_hash($label) { return hash('sha256', 'nmmpro-hd-audit|' . $label); }
function hau_pay($address, $label, $units, $time) {
	$GLOBALS['hau_book'][$address][] = array('label' => $label, 'value' => $units, 'time' => $time);
}

$GLOBALS['hau_mock'] = function ($pre, $args, $url) {
	$GLOBALS['hau_requests'][] = $url;
	$parts = wp_parse_url($url);
	if ($parts['host'] !== 'api.blockcypher.com' || !preg_match('#^/v1/doge/main/addrs/([^/]+?)(/balance)?$#', $parts['path'], $m)) {
		return new WP_Error('hau_unmocked', 'unmocked ' . $url);
	}
	$address = rawurldecode($m[1]);
	if (!empty($GLOBALS['hau_fail'][$address])) {
		return array('response' => array('code' => 500, 'message' => ''), 'body' => '', 'headers' => array(), 'cookies' => array());
	}
	$book = isset($GLOBALS['hau_book'][$address]) ? $GLOBALS['hau_book'][$address] : array();
	if (!empty($m[2])) {
		$total = 0;
		foreach ($book as $o) { $total += (int) $o['value']; }
		$n = count($book);
		$body = array('address' => $address, 'total_received' => $total, 'n_tx' => $n, 'unconfirmed_n_tx' => 0, 'final_n_tx' => $n, 'unconfirmed_balance' => 0);
	}
	else {
		$body = array('address' => $address);
		$i = 0;
		foreach ($book as $o) {
			$body['txrefs'][] = array('tx_hash' => hau_hash($o['label']), 'block_height' => HAU_TIP - 100 - $i, 'tx_input_n' => -1, 'tx_output_n' => 0,
				'value' => $o['value'], 'spent' => false, 'confirmations' => 101 + $i, 'confirmed' => gmdate('Y-m-d\TH:i:s\Z', $o['time']), 'double_spend' => false);
			$i++;
		}
		// As BlockCypher: the address's transaction counters.
		$body['n_tx'] = isset($body['txrefs']) ? count(array_unique(array_column($body['txrefs'], 'tx_hash'))) : 0;
		$body['unconfirmed_n_tx'] = isset($body['unconfirmed_txrefs']) ? count(array_unique(array_column($body['unconfirmed_txrefs'], 'tx_hash'))) : 0;
	}
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode($body), 'headers' => array(), 'cookies' => array());
};

function hau_order($status) {
	$o = wc_create_order();
	$o->set_total('100.00');
	$o->set_billing_email('hau.customer.private@example.test');
	$o->set_billing_first_name('Privatename');
	$o->set_date_created($GLOBALS['hau_created']);
	$o->save();
	$o->update_status($status);
	$GLOBALS['hau_orders'][] = $o->get_id();
	return $o->get_id();
}

/**
 * An address record. $validated adds a 1.5 binding (bound when the order was
 * placed); otherwise it is a legacy row with no provenance.
 */
function hau_row($address, $status, $orderId, $validated, $extra = array()) {
	global $wpdb;
	$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hau_table']}` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address));
	$cols = array(
		'address' => $address, 'cryptocurrency' => 'DOGE', 'mpk' => $GLOBALS['hau_mpk'], 'mpk_index' => 300000 + crc32($address) % 100000,
		'status' => $status, 'hd_mode' => 0, 'order_id' => $orderId, 'order_amount' => '10.00000000', 'assigned_at' => $GLOBALS['hau_created'],
	);
	if ($validated) {
		$cols += array('assignment_version' => 1, 'pool_version' => 1, 'bound_at' => $GLOBALS['hau_created'], 'validated_at' => $GLOBALS['hau_created'] - 5);
	}
	$cols = array_merge($cols, $extra);
	$wpdb->insert($GLOBALS['hau_table'], $cols);
	return (int) $wpdb->insert_id;
}

function hau_find($page, $address) {
	foreach ($page['rows'] as $r) { if ($r['address'] === $address) { return $r; } }
	return null;
}

function hau_clear_backoff() {
	global $wpdb;
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
	wp_cache_flush();
}

function hau_audit($args) {
	hau_clear_backoff();
	$GLOBALS['hau_requests'] = array();
	add_filter('pre_http_request', $GLOBALS['hau_mock'], 10, 3);
	try {
		return NMMPRO_Hd_Audit::rows($args + array('coin' => 'DOGE', 'limit' => 1000));
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['hau_mock'], 10);
	}
}

// Snapshot of everything the audit must not change.
function hau_snapshot() {
	global $wpdb;
	$orders = array();
	foreach ($GLOBALS['hau_orders'] as $id) {
		$o = wc_get_order($id);
		$orders[$id] = $o ? $o->get_status() . '|' . wp_json_encode($o->get_meta(NMMPRO_Hd_Verifier::TX_META)) : 'gone';
	}
	return md5(wp_json_encode(array(
		$wpdb->get_results("SELECT * FROM `{$GLOBALS['hau_table']}` ORDER BY `id`", ARRAY_A),
		$wpdb->get_results("SELECT * FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` ORDER BY `id`", ARRAY_A),
		$wpdb->get_results("SELECT * FROM `" . NMMPRO_Consumed_Repo::table() . "` ORDER BY `identity`", ARRAY_A),
		$wpdb->get_results("SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name LIKE 'nmmpro%' AND option_name NOT LIKE '%transient%' AND option_name NOT LIKE '%backoff%' AND option_name NOT LIKE '%apifail%' AND option_name NOT LIKE '%cooldown%' ORDER BY option_name", ARRAY_A),
		$orders,
	)));
}

$clean = function () use ($wpdb) {
	$ids = $wpdb->get_col($wpdb->prepare("SELECT `id` FROM `{$GLOBALS['hau_table']}` WHERE `mpk` = %s", $GLOBALS['hau_mpk']));
	if ($ids) { $wpdb->query("DELETE FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` IN (" . implode(',', array_map('intval', $ids)) . ")"); }
	$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hau_table']}` WHERE `mpk` = %s", $GLOBALS['hau_mpk']));
	$wpdb->query("DELETE FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE `address` LIKE 'hau\\\\_%'");
};
$clean();
$wpdb->suppress_errors(true);

// =====================================================================
// Fixtures.
// =====================================================================
$old = strtotime('2021-05-08 14:22:17 UTC');
$afterOrder = $GLOBALS['hau_created'] + 3600;

// 1. The incident's shape: a legacy row, paid, every receipt years older than the order.
$oInc = hau_order('processing');
hau_row('hau_incident', 'complete', $oInc, false, array('total_received' => '3800.00000000'));
hau_pay('hau_incident', 'inc-1', '200000000000', $old);
hau_pay('hau_incident', 'inc-2', '180000000000', $old + 86400);
// 2. Paid, nothing ever received.
$oAbs = hau_order('processing');
hau_row('hau_absent', 'complete', $oAbs, false);
// 3. Paid, less than due arrived after the order.
$oUnd = hau_order('completed');
hau_row('hau_under', 'complete', $oUnd, false);
hau_pay('hau_under', 'under-1', '500000000', $afterOrder);
// 4. Validated, paid, credited evidence still on chain: nothing to report.
$oOk = hau_order('processing');
$rOk = hau_row('hau_ok', 'complete', $oOk, true, array('credited_units' => '1000000000'));
hau_pay('hau_ok', 'ok-1', '1000000000', $afterOrder);
NMMPRO_Hd_Evidence_Repo::record($rOk, $oOk, 'DOGE', 'hau_ok', 'blockcypher', array(array('tx_hash' => hau_hash('ok-1'), 'output_index' => 0, 'amount_units' => '1000000000', 'confirmed' => true, 'confirmations' => 101, 'block_height' => 1, 'block_time' => $afterOrder)), time());
$wpdb->query($wpdb->prepare("UPDATE `" . NMMPRO_Hd_Schema::evidence_table() . "` SET `state` = 'credited' WHERE `hd_id` = %d", $rOk));
// 5. Validated, paid, but the credited transaction is gone from the chain.
$oVan = hau_order('processing');
$rVan = hau_row('hau_vanished', 'complete', $oVan, true, array('credited_units' => '1000000000'));
hau_pay('hau_vanished', 'van-other', '1000000000', $afterOrder);
NMMPRO_Hd_Evidence_Repo::record($rVan, $oVan, 'DOGE', 'hau_vanished', 'blockcypher', array(array('tx_hash' => hau_hash('van-gone'), 'output_index' => 0, 'amount_units' => '1000000000', 'confirmed' => true, 'confirmations' => 101, 'block_height' => 1, 'block_time' => $afterOrder)), time());
$wpdb->query($wpdb->prepare("UPDATE `" . NMMPRO_Hd_Schema::evidence_table() . "` SET `state` = 'credited' WHERE `hd_id` = %d", $rVan));
// 6. Held (legacy), not paid, but fully funded after the order.
$oFund = hau_order('on-hold');
hau_row('hau_funded', 'review', $oFund, false, array('review_reason' => 'legacy_unvalidated'));
hau_pay('hau_funded', 'funded-1', '1000000000', $afterOrder);
// 7. A record listing two orders.
$oReu = hau_order('on-hold');
hau_row('hau_reused', 'review', $oReu, false, array('all_order_ids' => '111,' . $oReu, 'review_reason' => 'legacy_unvalidated'));
// 8. An unissued (legacy) pool row with chain history.
hau_row('hau_pool', 'ready', null, false);
hau_pay('hau_pool', 'pool-1', '100000000', $old);
// 9. The order is gone.
$oGone = hau_order('on-hold');
hau_row('hau_ghost', 'review', $oGone, false, array('review_reason' => 'legacy_unvalidated'));
wc_get_order($oGone)->delete(true);
// 10. Record says paid, order does not.
$oDis = hau_order('on-hold');
hau_row('hau_disagree', 'complete', $oDis, false);
// 12. Validated, paid, no credited evidence recorded.
$oNoEv = hau_order('processing');
hau_row('hau_noev', 'complete', $oNoEv, true);
hau_pay('hau_noev', 'noev-1', '1000000000', $afterOrder);
// 13. The explorer fails. Last on purpose: after a failure the plugin backs
// off from that explorer host for a while, so every later check in the same
// run is (correctly) reported as unknown too.
$oDown = hau_order('processing');
hau_row('hau_down', 'complete', $oDown, false);
$GLOBALS['hau_fail']['hau_down'] = true;

// Counting checks start at this suite's first record and count only its own
// addresses, so other DOGE records in the database cannot skew them.
$firstId = (int) $wpdb->get_var($wpdb->prepare("SELECT MIN(`id`) FROM `{$GLOBALS['hau_table']}` WHERE `mpk` = %s", $GLOBALS['hau_mpk']));
$ours = function ($rows) { return array_values(array_filter($rows, function ($r) { return strpos($r['address'], 'hau_') === 0; })); };

// =====================================================================
echo "--- 1. findings, with chain checks ---\n";
$before = hau_snapshot();
$page = hau_audit(array('chain' => true));
$after = hau_snapshot();
$expect = array(
	'hau_incident' => array('missing_provenance', 'false_positive_confirmed'),
	'hau_absent'   => array('missing_provenance', 'paid_evidence_absent'),
	'hau_under'    => array('missing_provenance', 'paid_underfunded'),
	'hau_ok'       => array(),
	'hau_vanished' => array('credited_evidence_missing'),
	'hau_funded'   => array('missing_provenance', 'unpaid_but_funded'),
	'hau_reused'   => array('missing_provenance', 'reused_address'),
	'hau_pool'     => array('pool_row_has_history'),
	'hau_ghost'    => array('missing_provenance', 'order_missing', 'chain_unknown'),
	'hau_disagree' => array('missing_provenance', 'row_order_disagree'),
	'hau_down'     => array('missing_provenance', 'chain_unknown'),
	'hau_noev'     => array('paid_no_evidence'),
);
foreach ($expect as $address => $want) {
	$r = hau_find($page, $address);
	$got = $r ? $r['findings'] : null;
	if (is_array($got)) { sort($got); }
	sort($want);
	hau_ok(sprintf('%-13s %s', $address, $want === array() ? '(nothing to report)' : implode(', ', $want)), $got === $want, 'got=' . (is_array($got) ? implode(',', $got) : 'missing'));
}
$inc = hau_find($page, 'hau_incident');
hau_ok('the incident row reports what arrived before and after the order', strpos($inc['chain'], 'after order: 0') !== false && strpos($inc['chain'], 'before order: 3800') !== false, $inc['chain']);
hau_ok('  alongside the legacy lifetime total, for contrast',              $inc['lifetime'] === '3800' && $inc['credited'] === '');
hau_ok('the audit changed nothing (tables, ledger, options, orders)',      $before === $after);

// =====================================================================
echo "--- 2. without chain checks ---\n";
$page = hau_audit(array());
hau_ok('no explorer request is made',                                      $GLOBALS['hau_requests'] === array());
hau_ok('only stored facts are reported',                                   hau_find($page, 'hau_incident')['findings'] === array('missing_provenance') && hau_find($page, 'hau_noev')['findings'] === array('paid_no_evidence'));

// =====================================================================
echo "--- 3. paging, scope, budget ---\n";
$seen = array();
$cursor = $firstId - 1;
$pages = 0;
do {
	$p = hau_audit(array('limit' => 5, 'after' => $cursor));
	foreach ($ours($p['rows']) as $r) { $seen[] = $r['row']; }
	$cursor = $p['next'];
	$pages++;
} while ($cursor !== null && $pages < 20);
hau_ok('pages of 5 cover every record exactly once',                       count($seen) === count($expect) && count(array_unique($seen)) === count($seen), count($seen) . ' records in ' . $pages . ' pages');
$scoped = hau_audit(array('order' => $oInc));
hau_ok('--order scopes to that order',                                     count($scoped['rows']) === 1 && $scoped['rows'][0]['address'] === 'hau_incident');
$scoped = hau_audit(array('address' => 'hau_funded'));
hau_ok('--address scopes to that address',                                 count($scoped['rows']) === 1 && $scoped['rows'][0]['order'] === $oFund);
$budget = hau_audit(array('chain' => true, 'max_scans' => 2, 'after' => $firstId - 1));
$skipped = 0;
foreach ($ours($budget['rows']) as $r) { if (in_array('chain_skipped', $r['findings'], true)) { $skipped++; } }
hau_ok('the explorer budget is respected; the rest are marked skipped',   $budget['scans'] === 2 && $skipped === count($expect) - 2, 'scans=' . $budget['scans'] . ' skipped=' . $skipped);

// =====================================================================
echo "--- 4. the report ---\n";
$summary = NMMPRO_Hd_Audit::summary();
hau_ok('the summary states the schema and every coin\'s capability',       $summary['schema_ready'] === true && isset($summary['coins']['DOGE'], $summary['coins']['BTX']) && $summary['coins']['BTX']['automatic'] === false);
$total = 0;
foreach ($summary['states'] as $st) { if ($st['coin'] === 'DOGE') { $total += $st['count']; } }
hau_ok('  and counts address records by state and reason',                 $total >= count($expect));
$json = wp_json_encode(array($summary, hau_audit(array('chain' => true))));
hau_ok('no customer details appear in the report',                         strpos($json, 'hau.customer.private@example.test') === false && strpos($json, 'Privatename') === false);

if (class_exists('WP_CLI')) {
	hau_clear_backoff();
	add_filter('pre_http_request', $GLOBALS['hau_mock'], 10, 3);
	$cli = WP_CLI::runcommand('nmmpro-hd audit --order=' . $oInc . ' --chain --format=json', array('return' => true, 'launch' => false, 'exit_error' => false));
	remove_filter('pre_http_request', $GLOBALS['hau_mock'], 10);
	$decoded = json_decode($cli, true);
	hau_ok('`wp nmmpro-hd audit --format=json` reports the same finding',   is_array($decoded) && isset($decoded['records'][0]) && in_array('false_positive_confirmed', $decoded['records'][0]['findings'], true) && isset($decoded['legend']['false_positive_confirmed']), substr((string) $cli, 0, 80));
}

// =====================================================================
echo "--- 5. manual reconciliation ---\n";
$oRec = hau_order('on-hold');
hau_row('hau_reconcile', 'review', $oRec, true, array('review_reason' => 'timestamp_ambiguous'));
$tx = hau_hash('rec-1');
$owner = function ($hash) { return NMMPRO_Consumed_Repo::owners('DOGE', 'hau_reconcile', array($hash))[$hash]; };

$plan = NMMPRO_Hd_Reconcile::run($oRec, array($tx), true, false);
hau_ok('a dry run changes nothing',                                        $plan['applied'] === false && $owner($tx) === null && wc_get_order($oRec)->get_status() === 'on-hold');
hau_ok('  and says what it would do',                                      count($plan['actions']) === 3 && strpos($plan['actions'][0], 'record ' . $tx) === 0);

$threw = function ($fn) { try { $fn(); return ''; } catch (\Throwable $t) { return $t->getMessage(); } };
hau_ok('a malformed transaction id is refused',                            $threw(function () use ($oRec) { NMMPRO_Hd_Reconcile::run($oRec, array('not-a-tx'), false, true); }) !== '');
$foreign = hau_hash('rec-foreign');
NMMPRO_Consumed_Repo::record_manual('DOGE', 'hau_reconcile', 424242, array($foreign));
$msg = $threw(function () use ($oRec, $foreign, $tx) { NMMPRO_Hd_Reconcile::run($oRec, array($tx, $foreign), true, true); });
hau_ok('a transaction owned by another order is refused',                  strpos($msg, 'order 424242') !== false && $owner($tx) === null && wc_get_order($oRec)->get_status() === 'on-hold', $msg);

$res = NMMPRO_Hd_Reconcile::run($oRec, array($tx . ':1'), false, true);
hau_ok('ownership is recorded before any acknowledgement',                 $res['applied'] && $owner($tx) === $oRec && wc_get_order($oRec)->get_status() === 'on-hold');
$res = NMMPRO_Hd_Reconcile::run($oRec, array($tx . ':1'), true, true);
$rowRec = $wpdb->get_row($wpdb->prepare("SELECT `status` FROM `{$GLOBALS['hau_table']}` WHERE `address` = %s", 'hau_reconcile'), ARRAY_A);
hau_ok('--complete then completes the order and settles its record',       in_array(wc_get_order($oRec)->get_status(), array('processing', 'completed'), true) && $rowRec['status'] === 'complete');
hau_ok('  idempotently (the transaction is already this order\'s)',        $res['result']['already'] === array($tx) && $res['result']['recorded'] === array());
hau_ok('  with the transaction on the order and a note',                   in_array($tx . ':1', (array) wc_get_order($oRec)->get_meta(NMMPRO_Hd_Verifier::TX_META), true)
	&& count(wc_get_order_notes(array('order_id' => $oRec, 'type' => 'internal'))) > 0);

$oCan = hau_order('cancelled');
hau_row('hau_reconcile_cancelled', 'retired', $oCan, true, array('review_reason' => 'order_cancelled'));
$txc = hau_hash('rec-cancelled');
$msg = $threw(function () use ($oCan, $txc) { NMMPRO_Hd_Reconcile::run($oCan, array($txc), true, true); });
hau_ok('a cancelled order is not completed by reconciliation',             $msg !== '' && wc_get_order($oCan)->get_status() === 'cancelled'
	&& NMMPRO_Consumed_Repo::owners('DOGE', 'hau_reconcile_cancelled', array($txc))[$txc] === null, $msg);
$res = NMMPRO_Hd_Reconcile::run($oCan, array($txc), false, true);
hau_ok('  its funds can still be recorded against it; the address stays retired',
	NMMPRO_Consumed_Repo::owners('DOGE', 'hau_reconcile_cancelled', array($txc))[$txc] === $oCan
	&& $wpdb->get_var($wpdb->prepare("SELECT `status` FROM `{$GLOBALS['hau_table']}` WHERE `address` = %s", 'hau_reconcile_cancelled')) === 'retired');

if (class_exists('WP_CLI')) {
	$cli = WP_CLI::runcommand('nmmpro-hd reconcile --order=' . $oFund . ' --tx=' . hau_hash('funded-1'), array('return' => 'all', 'launch' => false, 'exit_error' => false));
	hau_ok('`wp nmmpro-hd reconcile` without --yes is a dry run',           strpos($cli->stdout . $cli->stderr, 'Dry run') !== false
		&& NMMPRO_Consumed_Repo::owners('DOGE', 'hau_funded', array(hau_hash('funded-1')))[hau_hash('funded-1')] === null);
}

// --- cleanup ---
$wpdb->suppress_errors(false);
$clean();
foreach ($GLOBALS['hau_orders'] as $oid) { $o = wc_get_order($oid); if ($o) { $o->delete(true); } }

echo $GLOBALS['hau_ok'] ? "\nHD-AUDIT CHECKS PASSED\n" : "\nHD-AUDIT CHECKS FAILED\n";
