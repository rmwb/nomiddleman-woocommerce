<?php
/**
 * Live-DB test: the Privacy Mode (HD) verifier and expiry pass, from
 * attributable transaction evidence (NMMPRO_Hd_Verifier).
 *
 * An order is paid only by confirmed incoming outputs to its own address that
 * arrived after the address was bound to it, are owned by no other order, and
 * were seen with complete coverage; completion is claimed atomically first and
 * recovered after any failure. This suite keeps every safety property the
 * lifetime-total verifier was tested for (no resurrection of dead orders, no
 * aborted sweep on a deleted order, no stranded payment after a failed
 * completion, no cancellation over funds in flight) and adds the HD safety
 * regression matrix:
 *
 *   real payment, historical amounts, mixed history, timing, confirmations,
 *   exact amounts, split payments, output identity, replay, verification
 *   races, failures, recovery, order states, reorg, expiry and presentation.
 *
 * DOGE via an offline BlockCypher fake (plus one BTC Esplora case). A second
 * database connection plays the other worker. Requires WordPress +
 * WooCommerce + a database; destructive fixtures, so use an isolated
 * installation.
 *
 *   Run:  wp eval-file tests/test-hd-verify.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !class_exists('NMMPRO_Hd_Verifier') || !function_exists('wc_create_order')) {
	echo "test-hd-verify: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$GLOBALS['hv_ok'] = true;
$GLOBALS['hv_table'] = $wpdb->prefix . NMMPRO_HD_TABLE;
$GLOBALS['hv_mpk'] = 'test_mpk_hd_verify';
$GLOBALS['hv_orders'] = array();
$GLOBALS['hv_tip'] = 5000000;
$GLOBALS['hv_book'] = array();     // address => list of outputs (see hv_pay)
$GLOBALS['hv_fail'] = array();     // address => 'http' | 'conflict' | 'degraded' (an answer naming only the address)
$GLOBALS['hv_requests'] = array();

function hv_ok($label, $cond, $extra = '') {
	printf("%-74s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['hv_ok'] = false; }
}

if (!NMMPRO_Hd::automatic_available('DOGE')) {
	hv_ok('fixture: automatic Privacy Mode is available for DOGE', false, (string) NMMPRO_Hd::automatic_unavailable_reason('DOGE'));
	echo "\nHD-VERIFY CHECKS FAILED\n";
	return;
}

function hv_hash($label) { return hash('sha256', 'nmmpro-hd-verify|' . $label); }
function hv_iso($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); }

$GLOBALS['hv_now'] = time();
$GLOBALS['hv_bound'] = $GLOBALS['hv_now'] - 3600;    // every address was bound an hour ago

/**
 * An incoming output to $address. $confirmations 0 = in the mempool. $time is
 * the confirming block's time (defaults to ten minutes after the binding).
 */
function hv_pay($address, $label, $units, $confirmations = 6, $time = null, $out = 0) {
	$GLOBALS['hv_book'][$address][] = array(
		'label' => $label, 'out' => $out, 'value' => $units, 'confs' => $confirmations,
		'time' => $time === null ? $GLOBALS['hv_bound'] + 600 : $time,
	);
}

/** A spend FROM $address (an input reference): it pages and dates like any other. */
function hv_spend($address, $label, $confirmations, $time) {
	$GLOBALS['hv_book'][$address][] = array('label' => $label, 'out' => -1, 'value' => 1, 'confs' => $confirmations, 'time' => $time, 'spend' => true);
}

/** An output (or, with $spend, a spend) at a fixed block height: its confirmations follow the tip. */
function hv_pay_at($address, $label, $units, $height, $time, $spend = false) {
	$GLOBALS['hv_book'][$address][] = array('label' => $label, 'out' => $spend ? -1 : 0, 'value' => $units, 'height' => $height, 'time' => $time, 'spend' => $spend);
}

function hv_ref($o) {
	if (isset($o['height'])) {
		$o['confs'] = $GLOBALS['hv_tip'] - $o['height'] + 1;
	}
	$height = $GLOBALS['hv_tip'] - $o['confs'] + 1;
	if (!empty($o['spend'])) {
		return array('tx_hash' => hv_hash($o['label']), 'block_height' => $height, 'tx_input_n' => 0, 'tx_output_n' => -1, 'value' => $o['value'],
			'spent' => false, 'confirmations' => $o['confs'], 'confirmed' => hv_iso($o['time']), 'double_spend' => false);
	}
	return $o['confs'] > 0
		? array('tx_hash' => hv_hash($o['label']), 'block_height' => $height, 'tx_input_n' => -1, 'tx_output_n' => $o['out'], 'value' => $o['value'],
			'spent' => false, 'confirmations' => $o['confs'], 'confirmed' => hv_iso($o['time']), 'double_spend' => false)
		: array('tx_hash' => hv_hash($o['label']), 'block_height' => -1, 'tx_input_n' => -1, 'tx_output_n' => $o['out'], 'value' => $o['value'],
			'spent' => false, 'confirmations' => 0, 'received' => hv_iso(time()), 'double_spend' => false);
}

$GLOBALS['hv_mock'] = function ($pre, $args, $url) {
	$GLOBALS['hv_requests'][] = $url;
	$parts = wp_parse_url($url);
	if ($parts['host'] !== 'api.blockcypher.com' || !preg_match('#^/v1/doge/main/addrs/([^/]+)$#', $parts['path'], $m)) {
		return new WP_Error('hv_unmocked', 'unmocked ' . $url);
	}
	$address = rawurldecode($m[1]);
	$fail = isset($GLOBALS['hv_fail'][$address]) ? $GLOBALS['hv_fail'][$address] : '';
	if ($fail === 'http') {
		return array('response' => array('code' => 500, 'message' => ''), 'body' => '', 'headers' => array(), 'cookies' => array());
	}
	if ($fail === 'degraded') {
		return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode(array('address' => $address)), 'headers' => array(), 'cookies' => array());
	}
	parse_str(isset($parts['query']) ? $parts['query'] : '', $q);
	$book = isset($GLOBALS['hv_book'][$address]) ? $GLOBALS['hv_book'][$address] : array();
	$refs = array();
	$pending = array();
	foreach ($book as $o) {
		$confs = isset($o['height']) ? $GLOBALS['hv_tip'] - $o['height'] + 1 : $o['confs'];
		if ($confs > 0) { $refs[] = hv_ref($o); } else { $pending[] = hv_ref($o); }
	}
	if ($fail === 'conflict' && isset($refs[0])) {
		$dup = $refs[0];
		$dup['value'] = $dup['value'] + 1;
		$refs[] = $dup;
	}
	usort($refs, function ($a, $b) { return $b['block_height'] - $a['block_height']; });
	// As BlockCypher: counters for the whole address, then one page of
	// references, newest first, below `before`, at most `limit`.
	$body = array('address' => $address,
		'n_tx' => count(array_unique(array_column($refs, 'tx_hash'))),
		'unconfirmed_n_tx' => count(array_unique(array_column($pending, 'tx_hash'))));
	if (isset($q['before'])) {
		$refs = array_values(array_filter($refs, function ($r) use ($q) { return $r['block_height'] < (int) $q['before']; }));
	}
	$limit = isset($q['limit']) ? (int) $q['limit'] : 50;
	$page = array_slice($refs, 0, $limit);
	if ($page !== array()) { $body['txrefs'] = $page; }
	if (!isset($q['before']) && $pending !== array()) { $body['unconfirmed_txrefs'] = $pending; }
	if (count($refs) > $limit) { $body['hasMore'] = true; }
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode($body), 'headers' => array(), 'cookies' => array());
};

/** The chain height when an address was bound at $boundAt (a block a minute). */
function hv_height_at($boundAt) {
	return $GLOBALS['hv_tip'] - intdiv($GLOBALS['hv_now'] - $boundAt, 60);
}

function hv_reset_backoff() {
	global $wpdb;
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
	wp_cache_flush();
}

function hv_order($status = 'on-hold') {
	$o = wc_create_order();
	$o->set_total('100.00');
	$o->save();
	$o->update_status($status);
	$GLOBALS['hv_orders'][] = $o->get_id();
	return $o->get_id();
}

/**
 * A validated assignment of $address to a new order owing $amount DOGE, bound
 * at $boundAt, with the order's own record of its gateway/coin/address. Returns
 * array(order id, row id).
 */
function hv_assign($address, $amount = '456.80863668', $status = 'on-hold', $boundAt = null, $mpk = null) {
	global $wpdb;
	$boundAt = $boundAt === null ? $GLOBALS['hv_bound'] : $boundAt;
	$orderId = hv_order($status);
	$order = wc_get_order($orderId);
	$order->set_payment_method('nmm_gateway');
	$order->update_meta_data('crypto_type_id', 'DOGE');
	$order->update_meta_data('wallet_address', $address);
	$order->save();
	$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address));
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `{$GLOBALS['hv_table']}` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assigned_at`,
		                                       `assignment_version`,`pool_version`,`bound_at`,`validated_at`,`validated_height`)
		 VALUES (%s,'DOGE',%s,%d,'assigned',0,%d,%s,%d,1,1,%d,%d,%d)",
		$address, $mpk === null ? $GLOBALS['hv_mpk'] : $mpk, 100000 + $orderId, $orderId, $amount, $boundAt, $boundAt, $boundAt - 5, hv_height_at($boundAt)));
	return array($orderId, (int) $wpdb->insert_id);
}

function hv_sweep($confs = 2, $percent = '0.99') {
	hv_reset_backoff();
	add_filter('pre_http_request', $GLOBALS['hv_mock'], 10, 3);
	try {
		NMMPRO_Hd::reset_observed_totals();
		NMMPRO_Hd::check_all_pending_addresses_for_payment('DOGE', $GLOBALS['hv_mpk'], $confs, $percent, 0);
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['hv_mock'], 10);
	}
}

function hv_expire($cancelSec = 1800, $reuseSweep = false) {
	if (!$reuseSweep) {
		NMMPRO_Hd::reset_observed_totals();
	}
	hv_reset_backoff();
	add_filter('pre_http_request', $GLOBALS['hv_mock'], 10, 3);
	try {
		NMMPRO_Hd::cancel_expired_addresses('DOGE', $GLOBALS['hv_mpk'], $cancelSec, 0);
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['hv_mock'], 10);
	}
}

function hv_row($address) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$GLOBALS['hv_table']}` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address), ARRAY_A);
}
function hv_status($orderId) {
	$read = NMMPRO_Payment::read_order_authoritatively($orderId);
	return $read['state'] === 'ok' ? $read['order']->get_status() : '(' . $read['state'] . ')';
}
function hv_paid($orderId) { return in_array(hv_status($orderId), array('processing', 'completed'), true); }
function hv_notes($orderId) {
	$text = '';
	foreach (wc_get_order_notes(array('order_id' => $orderId, 'limit' => 100)) as $note) { $text .= "\n" . $note->content; }
	return $text;
}
function hv_owner($address, $label) {
	$owners = NMMPRO_Consumed_Repo::owners('DOGE', $address, array(hv_hash($label)));
	return $owners[hv_hash($label)];
}

$GLOBALS['hv_completions'] = array();
add_action('woocommerce_payment_complete', function ($orderId) { $GLOBALS['hv_completions'][] = (int) $orderId; }, 10, 1);
function hv_completions($orderId) { return count(array_keys($GLOBALS['hv_completions'], (int) $orderId, true)); }

// A previous run's ledger entries and evidence would legitimately block this
// run (a transaction a deleted order owned never pays another): clear them.
$wpdb->query($wpdb->prepare("DELETE e FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` e JOIN `{$GLOBALS['hv_table']}` h ON h.`id` = e.`hd_id` WHERE h.`mpk` = %s", $GLOBALS['hv_mpk']));
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $GLOBALS['hv_mpk']));
$wpdb->query("DELETE FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE `address` LIKE 'hv\\_%'");
$wpdb->suppress_errors(true);

// A second connection for the other worker, and a way to change an order's
// stored status behind WooCommerce's back (as another request's save would).
$wpdb2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb2->suppress_errors(true);
$wpdb2->prefix = $wpdb->prefix;
// Table names come from the main connection: a bare new wpdb() is never
// given the site's table names.
$setStoredStatus = function ($orderId, $status) use ($wpdb, $wpdb2) {
	$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	if (class_exists($util) && $util::custom_orders_table_usage_is_enabled()) {
		$changed = $wpdb2->update($util::get_table_for_orders(), array('status' => $status), array('id' => $orderId));
	}
	else {
		$changed = $wpdb2->update($wpdb->posts, array('post_status' => $status), array('ID' => $orderId));
	}
	if ($changed !== 1) {
		hv_ok('fixture: the other request changed the stored status', false, var_export($changed, true) . ' ' . $wpdb2->last_error);
	}
};

// =====================================================================
echo "--- 1. a real payment ---\n";
list($o, $r) = hv_assign('hv_real');
hv_pay('hv_real', 'real', '45680863668');
hv_sweep();
$row = hv_row('hv_real');
hv_ok('a confirmed post-assignment payment completes the order',           hv_paid($o), 'status=' . hv_status($o));
hv_ok('  exactly once',                                                    hv_completions($o) === 1);
hv_ok('  the row is settled with the attributable amount',                 $row['status'] === 'complete' && $row['credited_units'] === '45680863668', $row['status'] . ' ' . $row['credited_units']);
hv_ok('  the transaction is owned by this order in the shared ledger',     hv_owner('hv_real', 'real') === $o);
hv_ok('  and recorded on the order with its output index',                 wc_get_order($o)->get_meta(NMMPRO_Hd_Verifier::TX_META) === array(hv_hash('real') . ':0'));
hv_ok('  the order\'s transaction id is the real transaction',             wc_get_order($o)->get_transaction_id() === hv_hash('real'));
$note = hv_notes($o);
hv_ok('  the note names the transaction, block time and verification time', strpos($note, hv_hash('real') . ':0') !== false && strpos($note, gmdate('Y-m-d H:i:s', $GLOBALS['hv_bound'] + 600)) !== false && strpos($note, 'Verified at') !== false);
hv_sweep();
hv_ok('a second sweep completes nothing twice',                            hv_completions($o) === 1 && substr_count(hv_notes($o), 'payment of') === 1);

// =====================================================================
echo "--- 2. historical amounts ---\n";
$old = strtotime('2021-05-08 14:22:17 UTC');
foreach (array(
	'exact'  => array(array('45680863668', 6)),
	'over'   => array(array('100000000000', 6)),
	'many'   => array(array('20000000000', 6), array('30000000000', 6)),
) as $case => $parts) {
	$addr = 'hv_hist_' . $case;
	list($oh) = hv_assign($addr);
	foreach ($parts as $i => $p) { hv_pay($addr, 'hist-' . $case . '-' . $i, $p[0], 900000, $old + $i); }
	hv_sweep();
	hv_ok("old $case receipt(s) contribute exactly zero",                  !hv_paid($oh) && hv_row($addr)['credited_units'] === '0' && hv_row($addr)['status'] === 'assigned', hv_status($oh) . ' credited=' . hv_row($addr)['credited_units']);
}
hv_ok('  the merchant is told old funds were ignored',                     strpos(hv_notes($oh), 'before this order was placed was ignored') !== false);
hv_sweep();
hv_ok('  once, not every sweep',                                           substr_count(hv_notes($oh), 'was ignored') === 1);
hv_ok('  the lifetime total is kept only as a diagnostic',                 (float) hv_row('hv_hist_many')['total_received'] === 500.0);

// =====================================================================
echo "--- 3. mixed history and split payments ---\n";
list($om) = hv_assign('hv_mixed');
hv_pay('hv_mixed', 'mixed-old', '380000000000', 900000, $old);
hv_pay('hv_mixed', 'mixed-new', '1000000000');                         // 10 DOGE after assignment
hv_sweep();
$row = hv_row('hv_mixed');
hv_ok('old 3800 + new 10 credits exactly 10',                              $row['credited_units'] === '1000000000' && $row['status'] === 'underpaid', $row['credited_units'] . ' ' . $row['status']);
hv_ok('  the customer is asked for the remainder of the order, not 3800',  strpos(hv_notes($om), 'Remaining payment required: 446.80863668') !== false && !hv_paid($om));
hv_pay('hv_mixed', 'mixed-rest', '44680863668');
hv_sweep();
hv_ok('a later payment of the remainder completes it',                     hv_paid($om) && hv_row('hv_mixed')['credited_units'] === '45680863668');

list($os) = hv_assign('hv_split', '3');
foreach (array('1', '2', '3') as $n) {
	hv_pay('hv_split', 'split-' . $n, '100000000');
	hv_sweep();
	hv_sweep();   // a repeated observation must never add twice
}
hv_ok('three separate parts across sweeps complete it once',               hv_paid($os) && hv_completions($os) === 1 && hv_row('hv_split')['credited_units'] === '300000000');
hv_ok('  and every part is owned by the order',                            hv_owner('hv_split', 'split-1') === $os && hv_owner('hv_split', 'split-3') === $os);

// =====================================================================
echo "--- 4. timing ---\n";
list($ot) = hv_assign('hv_boundary');
hv_pay('hv_boundary', 'boundary', '45680863668', 6, $GLOBALS['hv_bound']);
hv_sweep();
hv_ok('a payment confirmed exactly at the binding counts',                 hv_paid($ot));

list($oa) = hv_assign('hv_ambiguous');
hv_pay('hv_ambiguous', 'ambiguous', '45680863668', 6, $GLOBALS['hv_bound'] - 60);
hv_sweep();
hv_ok('a payment confirmed just before the binding goes to review',       !hv_paid($oa) && hv_row('hv_ambiguous')['status'] === 'review' && hv_row('hv_ambiguous')['review_reason'] === 'timestamp_ambiguous');
hv_ok('  with a note for the merchant',                                    strpos(hv_notes($oa), 'cannot be attributed automatically') !== false);

list($ob) = hv_assign('hv_seen_pending');
hv_pay('hv_seen_pending', 'seen-pending', '45680863668', 0);              // in the mempool: seen after the clean check
hv_sweep();
hv_ok('an unconfirmed payment is not completed',                          !hv_paid($ob) && hv_row('hv_seen_pending')['status'] === 'assigned');
$GLOBALS['hv_book']['hv_seen_pending'][0]['confs'] = 6;
$GLOBALS['hv_book']['hv_seen_pending'][0]['time'] = $GLOBALS['hv_bound'] - 60;   // its block's clock ran slow
hv_sweep();
hv_ok('first seen unconfirmed after the clean check, it counts even so',  hv_paid($ob), hv_status($ob));

list($od) = hv_assign('hv_downtime', '456.80863668', 'on-hold', $GLOBALS['hv_now'] - 5 * DAY_IN_SECONDS);
hv_pay('hv_downtime', 'downtime', '45680863668', 4000, $GLOBALS['hv_now'] - 4 * DAY_IN_SECONDS);
hv_sweep();
hv_ok('a payment found only after days of downtime still completes',       hv_paid($od));

// =====================================================================
echo "--- 5. confirmations ---\n";
list($oc) = hv_assign('hv_confs');
hv_pay('hv_confs', 'confs', '45680863668', 2);
hv_sweep(3);
hv_ok('one confirmation short of the requirement waits',                   !hv_paid($oc) && hv_row('hv_confs')['credited_units'] === '0');
// One more block is mined: the payment stays in its block, the tip moves.
$GLOBALS['hv_book']['hv_confs'][0]['height'] = $GLOBALS['hv_tip'] - 1;
$GLOBALS['hv_tip']++;
hv_sweep(3);
$GLOBALS['hv_tip']--;
unset($GLOBALS['hv_book']['hv_confs']);
hv_ok('the exact requirement completes',                                   hv_paid($oc));

list($oz) = hv_assign('hv_zero_conf');
hv_pay('hv_zero_conf', 'zero-conf', '45680863668', 0);
hv_sweep(0);
hv_ok('a legacy zero-confirmation setting still needs a block',           !hv_paid($oz));

// =====================================================================
echo "--- 6. exact amounts ---\n";
list($e1) = hv_assign('hv_one_short', '1.00000000');
hv_pay('hv_one_short', 'one-short', '99999999');
hv_sweep(2, '1');
hv_ok('one smallest unit below the threshold does not complete',           !hv_paid($e1) && hv_row('hv_one_short')['status'] === 'underpaid');
list($e2) = hv_assign('hv_exact', '1.00000000');
hv_pay('hv_exact', 'exact', '100000000');
hv_sweep(2, '1');
hv_ok('exactly the threshold completes',                                   hv_paid($e2));
list($e3) = hv_assign('hv_zero_amount', '0.00000000');
hv_pay('hv_zero_amount', 'zero-amount', '100000000');
hv_sweep();
hv_ok('a zero expected amount is never satisfied',                         !hv_paid($e3));
list($e4) = hv_assign('hv_huge', '99999999.99999999');
hv_pay('hv_huge', 'huge-a', '9223372036854775807');                        // PHP_INT_MAX units
hv_pay('hv_huge', 'huge-b', '9223372036854775807');
hv_sweep();
hv_ok('amounts beyond a native integer are summed exactly',                hv_row('hv_huge')['credited_units'] === '18446744073709551614' && hv_paid($e4), (string) hv_row('hv_huge')['credited_units']);

// =====================================================================
echo "--- 7. output identity ---\n";
list($oi) = hv_assign('hv_two_outputs');
hv_pay('hv_two_outputs', 'two-out', '30000000000', 6, null, 0);
hv_pay('hv_two_outputs', 'two-out', '15680863668', 6, null, 3);
hv_sweep();
hv_ok('two outputs of one transaction both count',                         hv_paid($oi) && hv_row('hv_two_outputs')['credited_units'] === '45680863668');
hv_ok('  under one ledger identity for the transaction',                   hv_owner('hv_two_outputs', 'two-out') === $oi);

list($ocf) = hv_assign('hv_conflict');
hv_pay('hv_conflict', 'conflict', '45680863668');
$GLOBALS['hv_fail']['hv_conflict'] = 'conflict';
hv_sweep();
unset($GLOBALS['hv_fail']['hv_conflict']);
hv_ok('disagreeing evidence for one output completes nothing',             !hv_paid($ocf));

// =====================================================================
echo "--- 8. replay ---\n";
list($oA) = hv_assign('hv_replay');
hv_pay('hv_replay', 'replayed', '45680863668');
// Another order (an Autopay claim, say) already owns this transaction.
$otherOrder = hv_order('processing');
$wpdb->query($wpdb->prepare("INSERT INTO `" . NMMPRO_Consumed_Repo::table() . "` (identity,transaction_hash,address,coin,order_id,created_at) VALUES (%s,%s,%s,'DOGE',%d,%d)",
	hash('sha256', json_encode(array('DOGE', 'hv_replay', hv_hash('replayed')))), hv_hash('replayed'), 'hv_replay', $otherOrder, time()));
hv_sweep();
hv_ok('a transaction owned by another order cannot pay this one',          !hv_paid($oA) && hv_row('hv_replay')['credited_units'] === '0');
hv_ok('  and its owner is unchanged',                                      hv_owner('hv_replay', 'replayed') === $otherOrder);

list($oL) = hv_assign('hv_legacy_owner');
hv_pay('hv_legacy_owner', 'legacy-owned', '45680863668');
NMMPRO_Consumed_Repo::add('DOGE', 'hv_legacy_owner', hv_hash('legacy-owned'));   // imported history: owner unknown
hv_sweep();
hv_ok('an unknown earlier owner blocks automatic use (review)',            !hv_paid($oL) && hv_row('hv_legacy_owner')['review_reason'] === 'legacy_owner');

// =====================================================================
echo "--- 9. verification races ---\n";
list($oR) = hv_assign('hv_locked');
hv_pay('hv_locked', 'locked', '45680863668');
$GLOBALS['wpdb'] = $wpdb2;
$held = NMMPRO_Util::acquire_address_match_lock('DOGE', 'hv_locked');
$GLOBALS['wpdb'] = $wpdb;
hv_sweep();
hv_ok('while another worker holds the address, nothing is done',          $held === '1' && !hv_paid($oR) && hv_row('hv_locked')['credited_units'] === null);
$GLOBALS['wpdb'] = $wpdb2;
NMMPRO_Util::release_address_match_lock('DOGE', 'hv_locked');
$GLOBALS['wpdb'] = $wpdb;
hv_sweep();
hv_ok('  and once it is released, the payment completes',                  hv_paid($oR) && hv_completions($oR) === 1);

// The order is cancelled by another request after the evidence is gathered
// and the claim committed, before completion.
list($oX) = hv_assign('hv_cancel_race');
hv_pay('hv_cancel_race', 'cancel-race', '45680863668');
$GLOBALS['hv_x_fired'] = false;
$cancelDuring = function ($sql) use ($oX, $setStoredStatus) {
	if (!$GLOBALS['hv_x_fired'] && strpos($sql, "SET `status` = 'completing'") !== false && strpos($sql, '`order_id` = ' . $oX) !== false) {
		$GLOBALS['hv_x_fired'] = true;
		$setStoredStatus($oX, 'wc-cancelled');
	}
	return $sql;
};
add_filter('query', $cancelDuring);
hv_sweep();
remove_filter('query', $cancelDuring);
hv_ok('an order cancelled during verification is not resurrected',         $GLOBALS['hv_x_fired'] && hv_status($oX) === 'cancelled' && hv_completions($oX) === 0, hv_status($oX));
hv_ok('  its funds stay recorded against it, not released',                hv_owner('hv_cancel_race', 'cancel-race') === $oX && hv_row('hv_cancel_race')['status'] === 'underpaid');

// =====================================================================
echo "--- 10. failures ---\n";
list($oF) = hv_assign('hv_claim_fail');
hv_pay('hv_claim_fail', 'claim-fail', '45680863668');
$failClaim = function ($sql) { return strpos($sql, "SET `status` = 'completing'") !== false ? 'SELECT * FROM `hv_injected_failure`' : $sql; };
add_filter('query', $failClaim);
hv_sweep();
remove_filter('query', $failClaim);
hv_ok('a failed claim rolls back entirely',                                !hv_paid($oF) && hv_owner('hv_claim_fail', 'claim-fail') === null && hv_row('hv_claim_fail')['status'] === 'assigned');
hv_ok('  no evidence was left marked credited',                            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` = %d AND `state` = 'credited'", hv_row('hv_claim_fail')['id'])) === 0);
hv_sweep();
hv_ok('  and the next sweep completes it',                                 hv_paid($oF) && hv_completions($oF) === 1);

list($oW) = hv_assign('hv_evidence_fail');
hv_pay('hv_evidence_fail', 'evidence-fail', '45680863668');
$failEvidence = function ($sql) { return strpos($sql, 'INSERT INTO `' . NMMPRO_Hd_Schema::evidence_table()) !== false ? 'SELECT * FROM `hv_injected_failure`' : $sql; };
add_filter('query', $failEvidence);
hv_sweep();
remove_filter('query', $failEvidence);
hv_ok('an evidence write failure completes nothing',                       !hv_paid($oW));

list($oH) = hv_assign('hv_http_fail');
hv_pay('hv_http_fail', 'http-fail', '45680863668');
$GLOBALS['hv_fail']['hv_http_fail'] = 'http';
hv_sweep();
hv_ok('an explorer failure completes nothing',                             !hv_paid($oH) && hv_row('hv_http_fail')['credited_units'] === null);
unset($GLOBALS['hv_fail']['hv_http_fail']);

// =====================================================================
echo "--- 11. recovery ---\n";
// A third-party hook throws inside payment_complete(): the claim and the
// order's ownership of the funds are kept, expiry leaves it alone, and the
// next sweep completes it once.
list($oB) = hv_assign('hv_boom', '456.80863668', 'on-hold', $GLOBALS['hv_now'] - 2 * DAY_IN_SECONDS);
hv_pay('hv_boom', 'boom', '45680863668', 6, $GLOBALS['hv_now'] - 2 * DAY_IN_SECONDS + 600);
$boom = function ($orderId) { throw new \RuntimeException('a third-party hook exploded'); };
add_action('woocommerce_pre_payment_complete', $boom, 10, 1);
hv_sweep();
remove_action('woocommerce_pre_payment_complete', $boom, 10);
hv_ok('a failed completion keeps a recoverable claim',                     !hv_paid($oB) && hv_row('hv_boom')['status'] === 'completing');
hv_ok('  the funds stay owned by this order',                              hv_owner('hv_boom', 'boom') === $oB);
hv_ok('  no success note was written',                                     strpos(hv_notes($oB), 'verified from') === false);
hv_expire(3600, true);
hv_ok('the expiry pass never cancels a completion in flight',              hv_status($oB) === 'on-hold' && hv_row('hv_boom')['status'] === 'completing');
hv_sweep();
hv_ok('the next sweep completes it exactly once',                          hv_paid($oB) && hv_completions($oB) === 1 && hv_row('hv_boom')['status'] === 'complete');

// A claim committed, then the process died before WooCommerce was asked.
list($oK) = hv_assign('hv_crash_after_claim');
hv_pay('hv_crash_after_claim', 'crash', '45680863668');
$rowK = hv_row('hv_crash_after_claim');
NMMPRO_Hd_Evidence_Repo::record((int) $rowK['id'], $oK, 'DOGE', 'hv_crash_after_claim', 'blockcypher',
	array(array('tx_hash' => hv_hash('crash'), 'output_index' => 0, 'amount_units' => '45680863668', 'confirmed' => true, 'confirmations' => 6, 'block_height' => 1, 'block_time' => $GLOBALS['hv_bound'] + 600)), time());
$evId = (int) $wpdb->get_var($wpdb->prepare("SELECT `id` FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` = %d", $rowK['id']));
$claimed = NMMPRO_Consumed_Repo::claim_hd('DOGE', 'hv_crash_after_claim', $oK, (int) $rowK['id'], array(hv_hash('crash')), array($evId), '45680863668');
hv_ok('fixture: a claim committed before the crash',                       $claimed === NMMPRO_Hd_Repo::CLAIM_CLAIMED && hv_row('hv_crash_after_claim')['status'] === 'completing');
hv_sweep();
hv_ok('after a crash between claim and completion, the same order completes', hv_paid($oK) && hv_completions($oK) === 1);

// Completed by WooCommerce, then the process died before the row was settled.
list($oP) = hv_assign('hv_crash_after_complete');
hv_pay('hv_crash_after_complete', 'crash2', '45680863668');
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `status` = 'completing' WHERE `address` = %s", 'hv_crash_after_complete'));
wc_get_order($oP)->payment_complete(hv_hash('crash2'));
$notesBefore = substr_count(hv_notes($oP), 'verified from');
$callsBefore = hv_completions($oP);
hv_sweep();
hv_ok('after a crash past completion, the row settles with nothing redone', hv_row('hv_crash_after_complete')['status'] === 'complete' && hv_completions($oP) === $callsBefore && substr_count(hv_notes($oP), 'verified from') === $notesBefore);

// payment_complete() returns true without transitioning a custom payable status.
$statusFilter = function ($s) { $s['wc-custom-await'] = 'Custom Await'; return $s; };
$payable = function ($s) { $s[] = 'custom-await'; return $s; };
add_filter('wc_order_statuses', $statusFilter);
add_filter('woocommerce_valid_order_statuses_for_payment', $payable);
list($oU) = hv_assign('hv_custom_status');
$co = wc_get_order($oU); $co->set_status('custom-await'); $co->save();
hv_pay('hv_custom_status', 'custom', '45680863668');
hv_sweep();
hv_ok('a completion that did not transition keeps its claim',              hv_status($oU) === 'custom-await' && hv_row('hv_custom_status')['status'] === 'completing' && strpos(hv_notes($oU), 'verified from') === false);
remove_filter('woocommerce_valid_order_statuses_for_payment', $payable);
remove_filter('wc_order_statuses', $statusFilter);

// =====================================================================
echo "--- 12. order states ---\n";
list($oPend) = hv_assign('hv_pending', '456.80863668', 'pending');
hv_pay('hv_pending', 'pending-order', '45680863668');
hv_sweep();
hv_ok('a pending order completes',                                         hv_paid($oPend));
foreach (array('cancelled', 'failed', 'refunded') as $dead) {
	list($oD) = hv_assign('hv_dead_' . $dead, '456.80863668', $dead);
	hv_pay('hv_dead_' . $dead, 'dead-' . $dead, '45680863668');
	hv_sweep();
	hv_ok("a $dead order is not completed by a late payment",              hv_status($oD) === $dead && hv_completions($oD) === 0);
}
hv_ok('  the late payment is noted for the merchant',                      strpos(hv_notes($oD), 'Late payment of 456.80863668') !== false);
hv_sweep();
hv_ok('  once',                                                            substr_count(hv_notes($oD), 'Late payment') === 1);
hv_expire();
hv_ok('  and the reconcile pass retires its address',                      hv_row('hv_dead_refunded')['status'] === 'retired');

list($oG) = hv_assign('hv_ghost');
wc_get_order($oG)->delete(true);
hv_pay('hv_ghost', 'ghost', '45680863668');
list($oBehind) = hv_assign('hv_behind_ghost');
hv_pay('hv_behind_ghost', 'behind', '45680863668');
$threw = false;
try { hv_sweep(); } catch (\Throwable $t) { $threw = true; }
hv_ok('a deleted order does not abort the sweep',                          !$threw && hv_paid($oBehind));
hv_expire();
hv_ok('  and its address is retired',                                      hv_row('hv_ghost')['status'] === 'retired');

foreach (array('wallet_address' => 'hv_other_address', 'crypto_type_id' => 'BTC') as $meta => $value) {
	list($oM) = hv_assign('hv_mismatch_' . $meta);
	$om = wc_get_order($oM); $om->update_meta_data($meta, $value); $om->save();
	hv_pay('hv_mismatch_' . $meta, 'mismatch-' . $meta, '45680863668');
	hv_sweep();
	hv_ok("an order whose $meta changed is never completed (review)",      !hv_paid($oM) && hv_row('hv_mismatch_' . $meta)['review_reason'] === 'binding_mismatch');
}
list($oN) = hv_assign('hv_wrong_gateway');
$on = wc_get_order($oN); $on->set_payment_method('bacs'); $on->save();
hv_pay('hv_wrong_gateway', 'wrong-gateway', '45680863668');
hv_sweep();
hv_ok('an order moved to another gateway is never completed',              !hv_paid($oN));

// =====================================================================
echo "--- 13. reorg ---\n";
list($oZ) = hv_assign('hv_reorg');
hv_pay('hv_reorg', 'reorg', '45680863668');
// The payment is on the first (saved-state) scan, then gone by the fresh
// re-validation scan a moment later.
$GLOBALS['hv_reorg_seen'] = 0;
$reorg = function ($pre, $args, $url) {
	if (strpos($url, '/addrs/hv_reorg') !== false && ++$GLOBALS['hv_reorg_seen'] > 1) {
		$GLOBALS['hv_book']['hv_reorg'] = array();
	}
	return $pre;
};
add_filter('pre_http_request', $reorg, 5, 3);
hv_sweep();
remove_filter('pre_http_request', $reorg, 5);
hv_ok('evidence that disappears before completion completes nothing',      !hv_paid($oZ) && hv_owner('hv_reorg', 'reorg') === null);
hv_ok('  it is marked vanished and no longer credited',                    hv_row('hv_reorg')['credited_units'] === '0'
	&& $wpdb->get_var($wpdb->prepare("SELECT `state` FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` = %d", hv_row('hv_reorg')['id'])) === 'vanished');

// =====================================================================
echo "--- 14. expiry ---\n";
$expiredAt = $GLOBALS['hv_now'] - 2 * DAY_IN_SECONDS;
list($x1) = hv_assign('hv_exp_empty', '456.80863668', 'on-hold', $expiredAt);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `assigned_at` = %d WHERE `address` = %s", $expiredAt, 'hv_exp_empty'));
hv_expire();
hv_ok('an expired order with complete, empty evidence is cancelled',       hv_status($x1) === 'cancelled' && hv_row('hv_exp_empty')['status'] === 'retired' && hv_row('hv_exp_empty')['review_reason'] === 'expired_unpaid');

list($x2) = hv_assign('hv_exp_pending', '456.80863668', 'on-hold', $expiredAt);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `assigned_at` = %d WHERE `address` = %s", $expiredAt, 'hv_exp_pending'));
hv_pay('hv_exp_pending', 'exp-pending', '45680863668', 0);
hv_expire();
hv_ok('a payment still in the mempool prevents cancellation',              hv_status($x2) === 'on-hold');

list($x3) = hv_assign('hv_exp_down', '456.80863668', 'on-hold', $expiredAt);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `assigned_at` = %d WHERE `address` = %s", $expiredAt, 'hv_exp_down'));
$GLOBALS['hv_fail']['hv_exp_down'] = 'http';
hv_expire();
unset($GLOBALS['hv_fail']['hv_exp_down']);
hv_ok('an explorer failure never cancels',                                 hv_status($x3) === 'on-hold');

list($x4) = hv_assign('hv_exp_under', '456.80863668', 'on-hold', $expiredAt);
hv_pay('hv_exp_under', 'exp-under', '1000000000', 6, $expiredAt + 600);
hv_sweep();
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `assigned_at` = %d WHERE `address` = %s", $expiredAt, 'hv_exp_under'));
hv_expire();
hv_ok('an expired, underpaid order is held for review, not cancelled',     hv_status($x4) === 'on-hold' && hv_row('hv_exp_under')['status'] === 'review' && hv_row('hv_exp_under')['review_reason'] === 'expired_underpaid');

list($x5) = hv_assign('hv_exp_old_only', '456.80863668', 'on-hold', $expiredAt);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `assigned_at` = %d WHERE `address` = %s", $expiredAt, 'hv_exp_old_only'));
hv_pay('hv_exp_old_only', 'exp-old', '380000000000', 900000, $old);
hv_sweep();
hv_expire(1800, true);
hv_ok('receipts from before the order do not keep it alive',               hv_status($x5) === 'cancelled');

list($x6) = hv_assign('hv_exp_reuse', '456.80863668', 'on-hold', $expiredAt);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `assigned_at` = %d WHERE `address` = %s", $expiredAt, 'hv_exp_reuse'));
hv_sweep();
$GLOBALS['hv_requests'] = array();
hv_expire(1800, true);
hv_ok('expiry reuses the same cycle\'s complete scan (no second request)', hv_status($x6) === 'cancelled' && count(preg_grep('#/addrs/hv_exp_reuse#', $GLOBALS['hv_requests'])) === 0);

// =====================================================================
echo "--- 15. BTC through Esplora ---\n";
$btcMock = function ($pre, $args, $url) {
	if (strpos($url, 'https://mempool.space/api') !== 0) { return new WP_Error('hv_unmocked', $url); }
	$path = substr($url, strlen('https://mempool.space/api'));
	if ($path === '/blocks/tip/height') { return array('response' => array('code' => 200), 'body' => '900000'); }
	if ($path === '/address/hv_btc_addr') {
		$stats = array('funded_txo_count' => 0, 'funded_txo_sum' => 0, 'spent_txo_count' => 0, 'spent_txo_sum' => 0);
		return array('response' => array('code' => 200), 'body' => wp_json_encode(array('address' => 'hv_btc_addr',
			'chain_stats' => $stats + array('tx_count' => 1), 'mempool_stats' => $stats + array('tx_count' => 0))));
	}
	if (preg_match('#^/address/hv_btc_addr/txs/mempool$#', $path)) { return array('response' => array('code' => 200), 'body' => '[]'); }
	if (preg_match('#^/address/hv_btc_addr/txs/chain$#', $path)) {
		return array('response' => array('code' => 200), 'body' => wp_json_encode(array(array(
			'txid' => hv_hash('btc'), 'vin' => array(),
			'vout' => array(array('scriptpubkey_address' => 'bc1qchange', 'value' => 5), array('scriptpubkey_address' => 'hv_btc_addr', 'value' => 250000)),
			'status' => array('confirmed' => true, 'block_height' => 899990, 'block_hash' => hv_hash('b'), 'block_time' => $GLOBALS['hv_bound'] + 900)))));
	}
	return new WP_Error('hv_unmocked', $url);
};
$orderBtc = hv_order('on-hold');
$ob = wc_get_order($orderBtc); $ob->set_payment_method('nmm_gateway'); $ob->update_meta_data('crypto_type_id', 'BTC'); $ob->update_meta_data('wallet_address', 'hv_btc_addr'); $ob->save();
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `cryptocurrency` = 'BTC' AND `address` = 'hv_btc_addr'"));
$wpdb->query($wpdb->prepare("INSERT INTO `{$GLOBALS['hv_table']}` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assigned_at`,`assignment_version`,`pool_version`,`bound_at`,`validated_at`,`validated_height`)
	VALUES ('hv_btc_addr','BTC',%s,1,'assigned',0,%d,'0.00250000',%d,1,1,%d,%d,899980)", $GLOBALS['hv_mpk'], $orderBtc, $GLOBALS['hv_bound'], $GLOBALS['hv_bound'], $GLOBALS['hv_bound'] - 5));
hv_reset_backoff();
add_filter('pre_http_request', $btcMock, 10, 3);
NMMPRO_Hd::reset_observed_totals();
NMMPRO_Hd::check_all_pending_addresses_for_payment('BTC', $GLOBALS['hv_mpk'], 2, '1', 0);
remove_filter('pre_http_request', $btcMock, 10);
hv_ok('a BTC payment verified through Esplora completes',                  hv_paid($orderBtc) && wc_get_order($orderBtc)->get_meta(NMMPRO_Hd_Verifier::TX_META) === array(hv_hash('btc') . ':1'));

// =====================================================================
echo "--- 16. the eligibility rules, exactly ---\n";
$b = 1000000;
$row = function ($label, $units, $time, $confs, $pendingFirst = false, $state = 'observed') use ($b) {
	return array('id' => crc32($label), 'tx_hash' => hv_hash($label), 'output_index' => 0, 'amount_units' => $units, 'confirmations' => $confs,
		'block_height' => $time === null ? null : 1, 'block_time' => $time, 'first_seen_at' => $b + 10, 'first_seen_pending' => $pendingFirst, 'state' => $state);
};
$ev = NMMPRO_Hd_Verifier::evaluate(array(
	$row('after', '5', $b + 1, 3), $row('at', '7', $b, 3), $row('short', '11', $b + 1, 1), $row('mempool', '13', null, 0, true),
	$row('ambig', '17', $b - 60, 3), $row('old', '19', $b - 90000, 3), $row('gone', '23', $b + 1, 3, false, 'vanished'),
	$row('foreign', '29', $b + 1, 3), $row('legacy', '31', $b + 1, 3), $row('mine', '37', $b + 1, 3), $row('fresh', '41', $b - 60, 3, true),
), $b, $b - 5, 2, array(hv_hash('foreign') => 424242, hv_hash('legacy') => 0, hv_hash('mine') => 7), 7);
$names = function ($list) { return array_map(function ($e) { return $e['amount_units']; }, $list); };
hv_ok('eligible: after or at the binding, confirmed, unowned or ours',     $names($ev['eligible']) === array('5', '7', '37', '41') && $ev['eligible_units'] === '90', implode(',', $names($ev['eligible'])));
hv_ok('unconfirmed: short of confirmations, or in the mempool',            $names($ev['unconfirmed']) === array('11', '13'));
hv_ok('ambiguous: in the block-time slack before the binding',             $names($ev['ambiguous']) === array('17'));
hv_ok('historical: well before the binding, contributes zero',             $names($ev['historical']) === array('19'));
hv_ok('owned elsewhere: another order, or unknown',                        $names($ev['foreign']) === array('29') && $names($ev['legacy']) === array('31'));
hv_ok('vanished outputs count for nothing',                                !in_array('23', $names(array_merge($ev['eligible'], $ev['unconfirmed'])), true) && $ev['observed_units'] === (string) (5 + 7 + 11 + 13 + 17 + 19 + 29 + 31 + 37 + 41));

// =====================================================================
echo "--- 17. independent review, round 1 ---\n";
// (a) An answer naming only the address - no counters, no lists - is not an
// empty history: it never cancels an order that was paid.
$expiredAt2 = $GLOBALS['hv_now'] - 7200;
list($xd) = hv_assign('hv_exp_degraded', '456.80863668', 'on-hold', $expiredAt2);
hv_pay('hv_exp_degraded', 'exp-degraded', '45680863668', 6, $expiredAt2 + 600);
$GLOBALS['hv_fail']['hv_exp_degraded'] = 'degraded';
hv_expire();
hv_ok('a degraded explorer answer never cancels a paid order',              hv_status($xd) === 'on-hold' && hv_row('hv_exp_degraded')['status'] === 'assigned', hv_status($xd));
hv_sweep();
hv_ok('  nor completes it on no evidence',                                  !hv_paid($xd) && hv_owner('hv_exp_degraded', 'exp-degraded') === null);
unset($GLOBALS['hv_fail']['hv_exp_degraded']);
hv_sweep();
hv_ok('  once the explorer answers properly, the payment completes',        hv_paid($xd) && hv_completions($xd) === 1);

// (b) Re-validation has its own page budget: more history than one normal
// scan reads (five pages) still completes...
$tenHours = $GLOBALS['hv_now'] - 10 * HOUR_IN_SECONDS;
list($oDust) = hv_assign('hv_dusty', '456.80863668', 'on-hold', $tenHours);
for ($i = 1; $i <= 300; $i++) { hv_pay('hv_dusty', 'dust-' . $i, '1', 10 + $i, $tenHours + 600); }
hv_pay('hv_dusty', 'dusty-pay', '45680863668', 6, $tenHours + 600);
for ($round = 0; $round < 6 && !hv_paid($oDust); $round++) { hv_sweep(); }
hv_ok('an address with more than five pages of history still completes',   hv_paid($oDust) && hv_completions($oDust) === 1, 'rounds=' . $round . ' status=' . hv_status($oDust) . ' row=' . hv_row('hv_dusty')['status']);
// ...and more history than a re-validation can read is held for review, never
// retried for ever and never completed on a partial read.
// (Bound 30 hours ago, so all 1100 references lie above its scan floor.)
$thirtyHours = $GLOBALS['hv_now'] - 30 * HOUR_IN_SECONDS;
list($oFlood) = hv_assign('hv_flooded', '456.80863668', 'on-hold', $thirtyHours);
for ($i = 1; $i <= 1100; $i++) { hv_pay('hv_flooded', 'flood-' . $i, '1', 10 + $i, $thirtyHours + 600); }
hv_pay('hv_flooded', 'flood-pay', '45680863668', 6, $thirtyHours + 600);
for ($round = 0; $round < 12 && hv_row('hv_flooded')['status'] === 'assigned'; $round++) { hv_sweep(); }
$flooded = hv_row('hv_flooded');
hv_ok('an address flooded beyond one re-validation is held for review',     $flooded['status'] === 'review' && $flooded['review_reason'] === 'history_too_large', $flooded['status'] . '/' . $flooded['review_reason'] . ' after ' . $round . ' sweeps');
hv_ok('  not completed, nothing claimed',                                   !hv_paid($oFlood) && hv_owner('hv_flooded', 'flood-pay') === null);
hv_ok('  and the merchant is told why',                                     strpos(hv_notes($oFlood), 'more transactions than can be re-checked') !== false);

// (c) Block times are not monotonic. A later block stamped long before the
// binding must not end the scan above an older block holding the payment.
// Page 1: fifty spends, one in a block whose miner wrote a time a month
// early; page 2: the payment.
$early = $tenHours - 30 * DAY_IN_SECONDS;
foreach (array('hv_timewarp', 'hv_timewarp_legacy') as $addr) {
	for ($i = 1; $i <= 50; $i++) { hv_spend($addr, $addr . '-spend-' . $i, $i, $i === 25 ? $early : $tenHours + 600); }
	hv_pay($addr, $addr . '-pay', '45680863668', 55, $tenHours + 600);
}
list($oWarp) = hv_assign('hv_timewarp', '456.80863668', 'on-hold', $tenHours);
hv_sweep();
hv_ok('an early-stamped later block does not end the scan early',          hv_paid($oWarp) && hv_owner('hv_timewarp', 'hv_timewarp-pay') === $oWarp, hv_status($oWarp) . ' ' . hv_row('hv_timewarp')['status']);
// Control: the same chain for a record bound without a height (bound by an
// earlier build) keeps the time floor, and misses the payment - the case the
// height floor exists for.
list($oWarpL) = hv_assign('hv_timewarp_legacy', '456.80863668', 'on-hold', $tenHours);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `validated_height` = NULL WHERE `id` = %d", hv_row('hv_timewarp_legacy')['id']));
hv_sweep();
hv_ok('  control: by time alone the same scan stops above the payment',     !hv_paid($oWarpL));

// An output in a block above the binding height whose time says "long
// before": never historical. A human decides; expiry never cancels over it.
list($oStamp) = hv_assign('hv_early_stamp', '456.80863668', 'on-hold', $tenHours);
hv_pay('hv_early_stamp', 'early-stamp', '45680863668', 6, $early);
hv_sweep();
hv_ok('a payment above the binding height, stamped early, goes to review',  !hv_paid($oStamp) && hv_row('hv_early_stamp')['status'] === 'review' && hv_row('hv_early_stamp')['review_reason'] === 'timestamp_ambiguous', hv_row('hv_early_stamp')['status']);
hv_ok('  and is not reported as old funds',                                 strpos(hv_notes($oStamp), 'was ignored') === false);
list($xStamp) = hv_assign('hv_exp_early_stamp', '456.80863668', 'on-hold', $expiredAt2);
hv_pay('hv_exp_early_stamp', 'exp-early-stamp', '45680863668', 6, $early);
hv_expire();
hv_ok('  expiry does not cancel an order over it',                          hv_status($xStamp) === 'on-hold', hv_status($xStamp));
// Old receipts, deep below the binding height, are still historical.
list($xOld) = hv_assign('hv_exp_deep_old', '456.80863668', 'on-hold', $expiredAt2);
hv_pay('hv_exp_deep_old', 'exp-deep-old', '380000000000', 900000, $early);
hv_expire();
hv_ok('  while receipts deep below the binding height still do not count',  hv_status($xOld) === 'cancelled', hv_status($xOld));

// The rule itself.
$vh = 5000;
$hrow = function ($label, $time, $height) use ($b) {
	return array('id' => crc32($label), 'tx_hash' => hv_hash($label), 'output_index' => 0, 'amount_units' => '1', 'confirmations' => 9,
		'block_height' => $height, 'block_time' => $time, 'first_seen_at' => $b + 10, 'first_seen_pending' => false, 'state' => 'observed');
};
hv_ok('timing: early-stamped, above the binding height -> ambiguous',       NMMPRO_Hd_Verifier::timing($hrow('a', $b - 90000, $vh + 1), $b, $b - 5, $vh) === 'ambiguous');
hv_ok('timing: early-stamped, at or below the binding height -> historical', NMMPRO_Hd_Verifier::timing($hrow('b', $b - 90000, $vh), $b, $b - 5, $vh) === 'historical');
hv_ok('timing: after the binding by time -> after, whatever the height',    NMMPRO_Hd_Verifier::timing($hrow('c', $b + 1, $vh - 50), $b, $b - 5, $vh) === 'after');
hv_ok('timing: no recorded height -> the time rules alone',                 NMMPRO_Hd_Verifier::timing($hrow('d', $b - 90000, $vh + 1), $b, $b - 5, null) === 'historical');
$ev = NMMPRO_Hd_Verifier::evaluate(array($hrow('e', $b - 90000, $vh + 1)), $b, $b - 5, 2, array(), 7, $vh);
hv_ok('evaluate() applies it: never credited, never historical',            $ev['ambiguous'] !== array() && $ev['historical'] === array() && $ev['eligible_units'] === '0');

// =====================================================================
echo "--- 18. independent review, round 2 ---\n";
// (1) The checkout binds the address, then records it on the order. A sweep
// in between leaves the row alone; it is not held for review.
list($oGap) = hv_assign('hv_bind_gap', '456.80863668', 'on-hold', time() - 600);
$og = wc_get_order($oGap); $og->delete_meta_data('wallet_address'); $og->save();
hv_pay('hv_bind_gap', 'bind-gap', '45680863668', 3, time() - 300);
hv_sweep();
hv_ok('a sweep before the checkout records the address leaves the row alone', hv_row('hv_bind_gap')['status'] === 'assigned' && !hv_paid($oGap), hv_row('hv_bind_gap')['status'] . '/' . hv_row('hv_bind_gap')['review_reason']);
$og = wc_get_order($oGap); $og->update_meta_data('wallet_address', 'hv_bind_gap'); $og->save();
hv_sweep();
hv_ok('  and once it is recorded, the payment completes',                  hv_paid($oGap) && hv_completions($oGap) === 1);
// A checkout that failed after binding never records the address: after the
// grace period a human decides.
list($oFailed) = hv_assign('hv_bind_failed', '456.80863668', 'on-hold', time() - NMMPRO_Hd_Verifier::BINDING_GRACE_SEC - 60);
$of = wc_get_order($oFailed); $of->delete_meta_data('wallet_address'); $of->save();
hv_pay('hv_bind_failed', 'bind-failed', '45680863668');
hv_sweep();
hv_ok('  an order still without its address after the grace period: review', hv_row('hv_bind_failed')['status'] === 'review' && hv_row('hv_bind_failed')['review_reason'] === 'binding_mismatch' && !hv_paid($oFailed));

// (2) Expiry reads the chain after the order. An order paid while the chain
// was being read is not cancelled from the copy read before.
list($xMid) = hv_assign('hv_exp_paid_mid', '456.80863668', 'on-hold', $expiredAt2);
$GLOBALS['hv_mid_fired'] = false;
$payDuringScan = function ($pre, $args, $url) use ($xMid, $setStoredStatus) {
	if (!$GLOBALS['hv_mid_fired'] && strpos($url, '/addrs/hv_exp_paid_mid') !== false) {
		$GLOBALS['hv_mid_fired'] = true;
		$setStoredStatus($xMid, 'wc-processing');
	}
	return $pre;
};
add_filter('pre_http_request', $payDuringScan, 5, 3);
hv_expire();
remove_filter('pre_http_request', $payDuringScan, 5);
hv_ok('an order paid while expiry reads the chain is not cancelled',        $GLOBALS['hv_mid_fired'] && hv_status($xMid) === 'processing', hv_status($xMid));
hv_ok('  and its address is not retired as expired',                       hv_row('hv_exp_paid_mid')['review_reason'] !== 'expired_unpaid');
// Control: the same expired, empty order is still cancelled when nothing changes.
list($xStill) = hv_assign('hv_exp_still_unpaid', '456.80863668', 'on-hold', $expiredAt2);
hv_expire();
hv_ok('  control: an unchanged expired order is still cancelled',           hv_status($xStill) === 'cancelled' && hv_row('hv_exp_still_unpaid')['review_reason'] === 'expired_unpaid');

// (4) A payment below the newest page is not re-read by a resumed scan; its
// confirmations still follow the chain tip. Sixty spends above it fill the
// newest page; the merchant requires 20 confirmations.
$tip0 = $GLOBALS['hv_tip'];
list($oDeep) = hv_assign('hv_deep_confs', '456.80863668', 'on-hold', $tenHours);
for ($i = 0; $i < 60; $i++) { hv_pay_at('hv_deep_confs', 'deep-spend-' . $i, '1', $tip0 - intdiv($i, 15), $tenHours + 900, true); }
hv_pay_at('hv_deep_confs', 'deep-pay', '45680863668', $tip0 - 4, $tenHours + 600);
hv_sweep(20);
hv_ok('fixture: five confirmations of twenty, not completed',               !hv_paid($oDeep) && (int) $wpdb->get_var($wpdb->prepare("SELECT `confirmations` FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` = %d AND `tx_hash` = %s", hv_row('hv_deep_confs')['id'], hv_hash('deep-pay'))) === 5);
$GLOBALS['hv_tip'] = $tip0 + 30;                                       // thirty blocks later, nothing new at the address
$GLOBALS['hv_requests'] = array();
hv_sweep(20);
hv_ok('  thirty blocks later the payment has 35 confirmations and completes', hv_paid($oDeep) && hv_completions($oDeep) === 1, hv_status($oDeep));
$GLOBALS['hv_tip'] = $tip0;

// =====================================================================
echo "--- 19. irreversible decisions use fresh scans (independent review, round 4) ---\n";
// Saved progress claims the history is covered down past a payment it never
// recorded (as an inflated count would). A scan resuming from it reports
// complete coverage without the payment. Expiry must not cancel on that.
$tip0 = $GLOBALS['hv_tip'];
list($xFalse) = hv_assign('hv_exp_false_cover', '456.80863668', 'on-hold', $expiredAt2);
for ($i = 0; $i < 60; $i++) { hv_pay_at('hv_exp_false_cover', 'fc-spend-' . $i, '1', $tip0 - intdiv($i, 15), $expiredAt2 + 900, true); }
hv_pay_at('hv_exp_false_cover', 'fc-pay', '45680863668', $tip0 - 4, $expiredAt2 + 600);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `scan_state` = %s WHERE `id` = %d",
	wp_json_encode(array('to' => $tip0, 'floor' => true, 'cursor' => null, 'source' => 'blockcypher', 'n' => 61, 'whole' => true)), hv_row('hv_exp_false_cover')['id']));
hv_reset_backoff();
add_filter('pre_http_request', $GLOBALS['hv_mock'], 10, 3);
$resumed = NMMPRO_Hd_Evidence::scan('DOGE', 'hv_exp_false_cover', $expiredAt2, json_decode(hv_row('hv_exp_false_cover')['scan_state'], true));
remove_filter('pre_http_request', $GLOBALS['hv_mock'], 10);
$resumedKeys = array_map(function ($o) { return $o['tx_hash']; }, $resumed['outputs']);
hv_ok('fixture: the resumed scan reports complete coverage without the payment', $resumed['coverage'] === 'complete' && $resumed['resumed'] === true && !in_array(hv_hash('fc-pay'), $resumedKeys, true), $resumed['coverage'] . ' ' . $resumed['reason']);
hv_expire();
hv_ok('expiry does not cancel on resumed coverage: a fresh scan finds the payment', hv_status($xFalse) === 'on-hold', hv_status($xFalse));
hv_ok('  and the payment is recorded',                                      (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` = %d AND `tx_hash` = %s", hv_row('hv_exp_false_cover')['id'], hv_hash('fc-pay'))) === 1);
// An expired order whose address has more history than one fresh scan reads is
// held for review - never cancelled, never left waiting silently.
list($xBig) = hv_assign('hv_exp_flooded', '456.80863668', 'on-hold', $expiredAt2);
for ($i = 0; $i < 1100; $i++) { hv_pay_at('hv_exp_flooded', 'ef-' . $i, '1', $tip0 - intdiv($i, 6), $expiredAt2 + 900, true); }
hv_expire();
hv_ok('an expired order with too much history for one fresh scan: review',  hv_status($xBig) === 'on-hold' && hv_row('hv_exp_flooded')['status'] === 'review' && hv_row('hv_exp_flooded')['review_reason'] === 'history_too_large', hv_status($xBig) . ' ' . hv_row('hv_exp_flooded')['status'] . '/' . hv_row('hv_exp_flooded')['review_reason']);

// In the same cycle as a sweep whose scan resumed saved progress, expiry does
// not reuse that scan: it scans afresh, and a genuinely unpaid order is
// still cancelled (not left waiting for ever).
list($xAfter) = hv_assign('hv_exp_after_resumed', '456.80863668', 'on-hold', $expiredAt2);
for ($i = 0; $i < 60; $i++) { hv_pay_at('hv_exp_after_resumed', 'ar-spend-' . $i, '1', $tip0 - intdiv($i, 15), $expiredAt2 + 900, true); }
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `scan_state` = %s WHERE `id` = %d",
	wp_json_encode(array('to' => $tip0, 'floor' => true, 'cursor' => null, 'source' => 'blockcypher', 'n' => 60, 'whole' => true)), hv_row('hv_exp_after_resumed')['id']));
hv_sweep();
hv_expire(1800, true);
hv_ok('after a resumed sweep, expiry scans afresh and still cancels the unpaid order', hv_status($xAfter) === 'cancelled', hv_status($xAfter));

// =====================================================================
echo "--- 20. never stranded silently (independent review, round 5) ---\n";
// More references in one block than a page holds: the explorer can never
// list them all. A paid order is held for review with a note, not left
// incomplete for ever.
list($oBlock) = hv_assign('hv_one_block', '456.80863668', 'on-hold', $tenHours);
for ($i = 0; $i < 60; $i++) { hv_pay_at('hv_one_block', 'ob-' . $i, '800000000', $tip0 - 6, $tenHours + 600); }
hv_sweep();
hv_ok('an address the explorer cannot list completely is held for review', hv_row('hv_one_block')['status'] === 'review' && hv_row('hv_one_block')['review_reason'] === 'history_unreadable' && !hv_paid($oBlock), hv_row('hv_one_block')['status'] . '/' . hv_row('hv_one_block')['review_reason']);
hv_ok('  with a note saying why',                                          strpos(hv_notes($oBlock), 'cannot list this order') !== false);
list($xBlock) = hv_assign('hv_exp_one_block', '456.80863668', 'on-hold', $expiredAt2);
for ($i = 0; $i < 60; $i++) { hv_pay_at('hv_exp_one_block', 'eob-' . $i, '1', $tip0 - 6, $expiredAt2 + 900, true); }
hv_expire();
hv_ok('  an expired order at such an address: review, not waiting for ever', hv_status($xBlock) === 'on-hold' && hv_row('hv_exp_one_block')['status'] === 'review' && hv_row('hv_exp_one_block')['review_reason'] === 'history_unreadable', hv_row('hv_exp_one_block')['status']);

// An explorer that keeps failing: the order is told after a day, once, and
// retrying continues; when the explorer recovers, the payment completes.
list($oPersist) = hv_assign('hv_persist', '456.80863668', 'on-hold', $GLOBALS['hv_now'] - 2 * HOUR_IN_SECONDS);
hv_pay('hv_persist', 'persist', '45680863668', 6, $GLOBALS['hv_now'] - 2 * HOUR_IN_SECONDS + 600);
$GLOBALS['hv_fail']['hv_persist'] = 'http';
hv_sweep();
$ps = json_decode(hv_row('hv_persist')['scan_state'], true);
hv_ok('a failing explorer: the time it started is recorded, no note yet',   isset($ps['incomplete_since']) && abs($ps['incomplete_since'] - time()) < 60 && strpos(hv_notes($oPersist), 'could not be read completely') === false);
$ps['incomplete_since'] = time() - NMMPRO_Hd_Verifier::INCOMPLETE_NOTICE_SEC - 3600;
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `scan_state` = %s WHERE `id` = %d", wp_json_encode($ps), hv_row('hv_persist')['id']));
hv_sweep();
hv_sweep();
hv_ok('  after a day the order is told, once',                             substr_count(hv_notes($oPersist), 'could not be read completely') === 1, substr_count(hv_notes($oPersist), 'could not be read completely') . ' notes');
hv_ok('  and it is still retried, not held or cancelled',                  hv_row('hv_persist')['status'] === 'assigned' && hv_status($oPersist) === 'on-hold');
unset($GLOBALS['hv_fail']['hv_persist']);
hv_sweep();
$ps = json_decode(hv_row('hv_persist')['scan_state'], true);
hv_ok('  once the explorer recovers, the payment completes',               hv_paid($oPersist) && !isset($ps['incomplete_since']), hv_status($oPersist));

// A cycle's explorer work is bounded; what it does not reach is first next time.
$slow = function ($pre, $args, $url) { if (strpos($url, '/addrs/hv_cap_') !== false) { usleep(600000); } return $pre; };
$budget = function () { return 1.0; };
$capOrders = array();
foreach (array(1, 2, 3) as $n) {
	list($capOrders[$n]) = hv_assign('hv_cap_' . $n, '456.80863668', 'on-hold', $tenHours);
	hv_pay('hv_cap_' . $n, 'cap-' . $n, '45680863668', 6, $tenHours + 600);
}
add_filter('pre_http_request', $slow, 5, 3);
add_filter('nmmpro_hd_pass_budget_sec', $budget);
$paidAfter = array();
for ($cycle = 1; $cycle <= 3; $cycle++) {
	hv_sweep();
	$paidAfter[$cycle] = count(array_filter($capOrders, 'hv_paid'));
}
remove_filter('pre_http_request', $slow, 5);
remove_filter('nmmpro_hd_pass_budget_sec', $budget);
hv_ok('a spent pass budget stops the pass; the rest go first next cycle',  $paidAfter === array(1 => 1, 2 => 2, 3 => 3), wp_json_encode($paidAfter));
// Unpaid addresses stay open, so the order matters: least recently checked
// first means an address one cycle missed is reached by the next.
$rot = array();
foreach (array(1, 2, 3) as $n) { list($rot[$n]) = hv_assign('hv_cap_rot_' . $n, '456.80863668', 'on-hold', $tenHours); }
add_filter('pre_http_request', $slow, 5, 3);
add_filter('nmmpro_hd_pass_budget_sec', $budget);
hv_sweep();
$afterOne = array_map(function ($n) { return (int) hv_row('hv_cap_rot_' . $n)['last_checked'] > 0; }, array(1, 2, 3));
hv_sweep();
$afterTwo = array_map(function ($n) { return (int) hv_row('hv_cap_rot_' . $n)['last_checked'] > 0; }, array(1, 2, 3));
remove_filter('pre_http_request', $slow, 5);
remove_filter('nmmpro_hd_pass_budget_sec', $budget);
hv_ok('  and an address one cycle did not reach is checked by the next',   $afterOne !== array(true, true, true) && $afterTwo === array(true, true, true), wp_json_encode(array($afterOne, $afterTwo)));

// =====================================================================
echo "--- 21. budgets per wallet and pass; escalation in flight (independent review, round 6) ---\n";
// A busy wallet spends its verification budget every cycle. Another wallet's
// verification and expiry still run in the same cycle.
$mpkB = 'hv_mpk_second_wallet';
$wpdb->query($wpdb->prepare("DELETE e FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` e JOIN `{$GLOBALS['hv_table']}` h ON h.`id` = e.`hd_id` WHERE h.`mpk` = %s", $mpkB));
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $mpkB));
foreach (array(1, 2, 3) as $n) { hv_assign('hv_starve_a_' . $n, '456.80863668', 'on-hold', $tenHours); }
list($oB) = hv_assign('hv_starve_b', '456.80863668', 'on-hold', $tenHours, $mpkB);
hv_pay('hv_starve_b', 'starve-b', '45680863668', 6, $tenHours + 600);
list($xB) = hv_assign('hv_starve_exp_b', '456.80863668', 'on-hold', $expiredAt2, $mpkB);
$slowA = function ($pre, $args, $url) { if (strpos($url, '/addrs/hv_starve_a_') !== false) { usleep(600000); } return $pre; };
hv_reset_backoff();
add_filter('pre_http_request', $slowA, 5, 3);
add_filter('pre_http_request', $GLOBALS['hv_mock'], 10, 3);
add_filter('nmmpro_hd_pass_budget_sec', $budget);
NMMPRO_Hd::reset_observed_totals();                                    // one cron cycle
NMMPRO_Hd::check_all_pending_addresses_for_payment('DOGE', $GLOBALS['hv_mpk'], 2, '0.99', 0);
$slowSeen = count(array_filter(array(1, 2, 3), function ($n) { return (int) hv_row('hv_starve_a_' . $n)['last_checked'] > 0; }));
NMMPRO_Hd::check_all_pending_addresses_for_payment('DOGE', $mpkB, 2, '0.99', 0);
NMMPRO_Hd::cancel_expired_addresses('DOGE', $GLOBALS['hv_mpk'], 1800, 0);
NMMPRO_Hd::cancel_expired_addresses('DOGE', $mpkB, 1800, 0);
remove_filter('nmmpro_hd_pass_budget_sec', $budget);
remove_filter('pre_http_request', $GLOBALS['hv_mock'], 10);
remove_filter('pre_http_request', $slowA, 5);
hv_ok('fixture: the busy wallet spent its budget before finishing',        $slowSeen < 3, $slowSeen . ' of 3 reached');
hv_ok('another wallet\'s paid order still completes in the same cycle',   hv_paid($oB), hv_status($oB));
hv_ok('  and its expired order is still cancelled in the same cycle',      hv_status($xB) === 'cancelled', hv_status($xB));

// An attempt that bails out early (the address is busy) still counts as an
// attempt, so the address does not stay first in the queue.
list($oTouch) = hv_assign('hv_touch_locked');
$GLOBALS['wpdb'] = $wpdb2;
$heldTouch = NMMPRO_Util::acquire_address_match_lock('DOGE', 'hv_touch_locked');
$GLOBALS['wpdb'] = $wpdb;
hv_sweep();
$GLOBALS['wpdb'] = $wpdb2;
NMMPRO_Util::release_address_match_lock('DOGE', 'hv_touch_locked');
$GLOBALS['wpdb'] = $wpdb;
hv_ok('an address skipped because it was busy is marked attempted',        $heldTouch === '1' && (int) hv_row('hv_touch_locked')['last_checked'] > 0);

// A completion in flight whose address can no longer be listed completely:
// its claim is kept, and the merchant is told that recovery needs a human.
list($oComp) = hv_assign('hv_comp_block', '456.80863668', 'on-hold', $tenHours);
for ($i = 0; $i < 60; $i++) { hv_pay_at('hv_comp_block', 'cb-' . $i, '800000000', $tip0 - 6, $tenHours + 600); }
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `status` = 'completing', `credited_units` = '48000000000' WHERE `id` = %d", hv_row('hv_comp_block')['id']));
hv_sweep();
hv_sweep();
hv_ok('an unreadable completion in flight keeps its claim',                 hv_row('hv_comp_block')['status'] === 'completing' && !hv_paid($oComp));
hv_ok('  and its order is told, once, that it needs manual completion',    substr_count(hv_notes($oComp), 'complete the order manually') === 1, substr_count(hv_notes($oComp), 'complete the order manually') . ' notes');

// Only a complete scan made from scratch ends an unreadable spell: a resumed
// scan's success in between does not reset the day-long timer.
list($oTimer) = hv_assign('hv_timer', '456.80863668', 'on-hold', $tenHours);
for ($i = 0; $i < 5; $i++) { hv_pay_at('hv_timer', 'tm-' . $i, '1', $tip0 - 10 - $i, $tenHours + 900, true); }
hv_sweep();
$ts = json_decode(hv_row('hv_timer')['scan_state'], true);
$ts['incomplete_since'] = time() - 3600;
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hv_table']}` SET `scan_state` = %s WHERE `id` = %d", wp_json_encode($ts), hv_row('hv_timer')['id']));
hv_sweep();
$ts2 = json_decode(hv_row('hv_timer')['scan_state'], true);
hv_ok('a resumed success does not reset the unreadable timer',              isset($ts2['incomplete_since']) && $ts2['incomplete_since'] === $ts['incomplete_since'], wp_json_encode($ts2));

$wpdb->query($wpdb->prepare("DELETE e FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` e JOIN `{$GLOBALS['hv_table']}` h ON h.`id` = e.`hd_id` WHERE h.`mpk` = %s", $mpkB));
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $mpkB));

// =====================================================================
echo "--- 22. expiry carries on where it stopped ---\n";
// Expired orders that cannot be cancelled (a payment is pending) are
// re-scanned by every expiry pass. With every address stamped in the same
// second (as one verification pass does), they must not keep an expired,
// empty order further down the list from ever being reached.
$rr = array();
foreach (array('a1', 'a2', 'a3') as $n) {
	list($rr[$n]) = hv_assign('hv_rr_' . $n, '456.80863668', 'on-hold', $expiredAt2);
	hv_pay('hv_rr_' . $n, 'rr-' . $n, '45680863668', 0);                // pending: never cancelled over
}
list($rrTail) = hv_assign('hv_rr_z', '456.80863668', 'on-hold', $expiredAt2);
$slowRr = function ($pre, $args, $url) { if (strpos($url, '/addrs/hv_rr_') !== false) { usleep(600000); } return $pre; };
$passes = 0;
add_filter('pre_http_request', $slowRr, 5, 3);
add_filter('nmmpro_hd_pass_budget_sec', $budget);
for ($pass = 1; $pass <= 3 && hv_status($rrTail) !== 'cancelled'; $pass++) {
	$wpdb->query("UPDATE `{$GLOBALS['hv_table']}` SET `last_checked` = 1 WHERE `address` LIKE 'hv\\_rr\\_%'");
	hv_expire();
	$passes = $pass;
}
remove_filter('nmmpro_hd_pass_budget_sec', $budget);
remove_filter('pre_http_request', $slowRr, 5);
hv_ok('an expired order behind ones that cannot be cancelled is reached',   hv_status($rrTail) === 'cancelled' && $passes <= 2, hv_status($rrTail) . ' after ' . $passes . ' passes');
hv_ok('  and the ones with a pending payment are still not cancelled',     count(array_filter($rr, function ($o) { return hv_status($o) === 'on-hold'; })) === 3);

// =====================================================================
echo "--- 23. each pass keeps its own place (independent review, round 7) ---\n";
// The reviewer's loop: A's slow failure spends verification's budget every
// cycle; B is expired and paid, and expiry (which cannot complete it) scans it
// and stamps it. Verification must still reach B.
$mpkT = 'hv_mpk_turns';
$wpdb->query($wpdb->prepare("DELETE e FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` e JOIN `{$GLOBALS['hv_table']}` h ON h.`id` = e.`hd_id` WHERE h.`mpk` = %s", $mpkT));
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $mpkT));
list($oTurnA) = hv_assign('hv_turn_a', '456.80863668', 'on-hold', time() - 600, $mpkT);
list($oTurnB) = hv_assign('hv_turn_b', '456.80863668', 'on-hold', $expiredAt2, $mpkT);
hv_pay('hv_turn_b', 'turn-b', '45680863668', 6, $expiredAt2 + 600);
$GLOBALS['hv_fail']['hv_turn_a'] = 'http';
$slowTurn = function ($pre, $args, $url) { if (strpos($url, '/addrs/hv_turn_a') !== false) { usleep(1200000); } return $pre; };
add_filter('pre_http_request', $slowTurn, 5, 3);
add_filter('pre_http_request', $GLOBALS['hv_mock'], 10, 3);
add_filter('nmmpro_hd_pass_budget_sec', $budget);
$turnCycles = 0;
for ($cycle = 1; $cycle <= 4 && !hv_paid($oTurnB); $cycle++) {
	hv_reset_backoff();
	NMMPRO_Hd::reset_observed_totals();
	NMMPRO_Hd::check_all_pending_addresses_for_payment('DOGE', $mpkT, 2, '0.99', 0);
	NMMPRO_Hd::cancel_expired_addresses('DOGE', $mpkT, 1800, 0);
	$turnCycles = $cycle;
}
remove_filter('nmmpro_hd_pass_budget_sec', $budget);
remove_filter('pre_http_request', $GLOBALS['hv_mock'], 10);
remove_filter('pre_http_request', $slowTurn, 5);
unset($GLOBALS['hv_fail']['hv_turn_a']);
hv_ok('a slow failure first in line does not keep verification from the rest', hv_paid($oTurnB) && $turnCycles <= 2, hv_status($oTurnB) . ' after ' . $turnCycles . ' cycles');
hv_ok('  (the slow address is still open, not cancelled or held)',         hv_row('hv_turn_a')['status'] === 'assigned' && hv_status($oTurnA) === 'on-hold');
$wpdb->query($wpdb->prepare("DELETE e FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` e JOIN `{$GLOBALS['hv_table']}` h ON h.`id` = e.`hd_id` WHERE h.`mpk` = %s", $mpkT));
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $mpkT));
$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s", $wpdb->esc_like(NMMPRO_Hd_Verifier::CURSOR_OPTION_PREFIX) . '%'));

// --- cleanup ---
$wpdb2->close();
$wpdb->suppress_errors(false);
$ids = $wpdb->get_col($wpdb->prepare("SELECT `id` FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $GLOBALS['hv_mpk']));
if ($ids) {
	$wpdb->query("DELETE FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` IN (" . implode(',', array_map('intval', $ids)) . ")");
}
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hv_table']}` WHERE `mpk` = %s", $GLOBALS['hv_mpk']));
$wpdb->query("DELETE FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE `address` LIKE 'hv\\_%'");
foreach ($GLOBALS['hv_orders'] as $oid) {
	$o = wc_get_order($oid);
	if ($o) { $o->delete(true); }
}
hv_reset_backoff();

echo $GLOBALS['hv_ok'] ? "\nHD-VERIFY CHECKS PASSED\n" : "\nHD-VERIFY CHECKS FAILED\n";
