<?php
/**
 * Live-DB test: Privacy Mode (HD) late-payment monitoring, the order-screen
 * panel, and log hygiene (HD safety Step G).
 *
 *   - A retired or held address can still be paid. The monitor keeps
 *     collecting its evidence and tells the merchant once per transaction
 *     (pending and confirmed sightings separately) - and never completes,
 *     cancels or claims anything, so a late payment can never pay any order.
 *   - What was already known while the order was open (the part payment that
 *     got it held, say) is not reported again.
 *   - Monitoring is bounded: a batch per cycle, one check per interval per
 *     address, and only for addresses bound in the last 90 days. A busy
 *     address is skipped without losing its turn; an explorer failure still
 *     spaces the next attempt.
 *   - The order screen shows the order's Privacy Mode state, why it is held
 *     or retired, the amount due and the amount attributable to it, escaped.
 *   - An invalid key's error never carries the key.
 *
 * DOGE via an offline BlockCypher fake. A second database connection holds
 * the address lock. Requires WordPress + WooCommerce + a database;
 * destructive fixtures, so use an isolated installation.
 *
 *   Run:  wp eval-file tests/test-hd-monitor.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !class_exists('NMMPRO_Hd_Verifier') || !function_exists('wc_create_order')) {
	echo "test-hd-monitor: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$GLOBALS['hmn_ok'] = true;
$GLOBALS['hmn_table'] = $wpdb->prefix . NMMPRO_HD_TABLE;
$GLOBALS['hmn_mpk'] = 'test_mpk_hd_monitor';
$GLOBALS['hmn_orders'] = array();
$GLOBALS['hmn_book'] = array();
$GLOBALS['hmn_fail'] = array();
$GLOBALS['hmn_requests'] = array();
$GLOBALS['hmn_now'] = time();
$GLOBALS['hmn_bound'] = $GLOBALS['hmn_now'] - 2 * DAY_IN_SECONDS;
const HMN_TIP = 5000000;

function hmn_ok($label, $cond, $extra = '') {
	printf("%-74s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['hmn_ok'] = false; }
}
function hmn_hash($label) { return hash('sha256', 'nmmpro-hd-monitor|' . $label); }

function hmn_pay($address, $label, $units, $confs = 6, $time = null) {
	$GLOBALS['hmn_book'][$address][] = array('label' => $label, 'value' => $units, 'confs' => $confs, 'time' => $time === null ? $GLOBALS['hmn_bound'] + 600 : $time);
}

$GLOBALS['hmn_mock'] = function ($pre, $args, $url) {
	$GLOBALS['hmn_requests'][] = $url;
	$parts = wp_parse_url($url);
	if ($parts['host'] !== 'api.blockcypher.com' || !preg_match('#^/v1/doge/main/addrs/([^/]+)$#', $parts['path'], $m)) {
		return new WP_Error('hmn_unmocked', 'unmocked ' . $url);
	}
	$address = rawurldecode($m[1]);
	if (!empty($GLOBALS['hmn_fail'][$address])) {
		return array('response' => array('code' => 500, 'message' => ''), 'body' => '', 'headers' => array(), 'cookies' => array());
	}
	$body = array('address' => $address);
	foreach (isset($GLOBALS['hmn_book'][$address]) ? $GLOBALS['hmn_book'][$address] : array() as $o) {
		$ref = array('tx_hash' => hmn_hash($o['label']), 'tx_input_n' => -1, 'tx_output_n' => 0, 'value' => $o['value'], 'double_spend' => false, 'spent' => false);
		if ($o['confs'] > 0) {
			$body['txrefs'][] = $ref + array('block_height' => HMN_TIP - $o['confs'] + 1, 'confirmations' => $o['confs'], 'confirmed' => gmdate('Y-m-d\TH:i:s\Z', $o['time']));
		}
		else {
			$body['unconfirmed_txrefs'][] = $ref + array('block_height' => -1, 'confirmations' => 0, 'received' => gmdate('Y-m-d\TH:i:s\Z'));
		}
	}
	// As BlockCypher: the address's transaction counters.
	$body['n_tx'] = isset($body['txrefs']) ? count(array_unique(array_column($body['txrefs'], 'tx_hash'))) : 0;
	$body['unconfirmed_n_tx'] = isset($body['unconfirmed_txrefs']) ? count(array_unique(array_column($body['unconfirmed_txrefs'], 'tx_hash'))) : 0;
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode($body), 'headers' => array(), 'cookies' => array());
};

function hmn_reset_backoff() {
	global $wpdb;
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
	wp_cache_flush();
}

/** A validated row for a new order, in $status with $reason. Returns array(order id, row id). */
function hmn_row_for($address, $status, $reason, $orderStatus = 'cancelled', $boundAt = null) {
	global $wpdb;
	$boundAt = $boundAt === null ? $GLOBALS['hmn_bound'] : $boundAt;
	$o = wc_create_order();
	$o->set_total('100.00');
	$o->set_payment_method('nmm_gateway');
	$o->update_meta_data('crypto_type_id', 'DOGE');
	$o->update_meta_data('wallet_address', $address);
	$o->save();
	$o->update_status($orderStatus);
	$GLOBALS['hmn_orders'][] = $o->get_id();
	$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hmn_table']}` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address));
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `{$GLOBALS['hmn_table']}` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assigned_at`,
		                                        `assignment_version`,`pool_version`,`bound_at`,`validated_at`,`review_reason`)
		 VALUES (%s,'DOGE',%s,%d,%s,0,%d,'456.80863668',%d,1,1,%d,%d,%s)",
		$address, $GLOBALS['hmn_mpk'], 200000 + $o->get_id(), $status, $o->get_id(), $boundAt, $boundAt, $boundAt - 5, $reason));
	return array($o->get_id(), (int) $wpdb->insert_id);
}

function hmn_monitor($batch = NMMPRO_Hd_Verifier::MONITOR_BATCH) {
	hmn_reset_backoff();
	$GLOBALS['hmn_requests'] = array();
	add_filter('pre_http_request', $GLOBALS['hmn_mock'], 10, 3);
	try {
		NMMPRO_Hd_Verifier::monitor_wallet('DOGE', $GLOBALS['hmn_mpk'], 0, $batch);
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['hmn_mock'], 10);
	}
}

function hmn_due($address) {
	global $wpdb;
	$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hmn_table']}` SET `last_checked` = 0 WHERE `address` = %s", $address));
}
function hmn_notes($orderId) {
	$t = '';
	foreach (wc_get_order_notes(array('order_id' => $orderId, 'limit' => 100)) as $n) { $t .= "\n" . $n->content; }
	return $t;
}
function hmn_row($address) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$GLOBALS['hmn_table']}` WHERE `address` = %s", $address), ARRAY_A);
}
function hmn_asked($address) {
	return count(preg_grep('#/addrs/' . preg_quote($address, '#') . '#', $GLOBALS['hmn_requests']));
}

$GLOBALS['hmn_completions'] = 0;
add_action('woocommerce_payment_complete', function () { $GLOBALS['hmn_completions']++; });

$ids = $wpdb->get_col($wpdb->prepare("SELECT `id` FROM `{$GLOBALS['hmn_table']}` WHERE `mpk` = %s", $GLOBALS['hmn_mpk']));
if ($ids) { $wpdb->query("DELETE FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` IN (" . implode(',', array_map('intval', $ids)) . ")"); }
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hmn_table']}` WHERE `mpk` = %s", $GLOBALS['hmn_mpk']));
$wpdb->query("DELETE FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE `address` LIKE 'hmn\\_%'");
$wpdb->suppress_errors(true);

// =====================================================================
echo "--- 1. a late payment to a retired address ---\n";
list($oR) = hmn_row_for('hmn_retired', 'retired', 'expired_unpaid');
hmn_monitor();
hmn_ok('fixture: an empty retired address is scanned and noted nothing',   hmn_asked('hmn_retired') === 1 && strpos(hmn_notes($oR), 'arrived at') === false);
hmn_pay('hmn_retired', 'late-confirmed', '45680863668');
hmn_due('hmn_retired');
hmn_monitor();
$notes = hmn_notes($oR);
hmn_ok('a late payment is reported to the merchant',                       strpos($notes, '456.80863668 DOGE arrived at hmn_retired') !== false && strpos($notes, hmn_hash('late-confirmed') . ':0') !== false, substr(trim($notes), 0, 120));
hmn_ok('  with why the address was retired',                               strpos($notes, NMMPRO_Hd::reason_label('expired_unpaid')) !== false);
hmn_ok('  it pays nothing: no completion, the order unchanged',            $GLOBALS['hmn_completions'] === 0 && wc_get_order($oR)->get_status() === 'cancelled');
hmn_ok('  no transaction is claimed in the shared ledger',                 NMMPRO_Consumed_Repo::owners('DOGE', 'hmn_retired', array(hmn_hash('late-confirmed')))[hmn_hash('late-confirmed')] === null);
hmn_ok('  the address stays retired, with its evidence recorded',          hmn_row('hmn_retired')['status'] === 'retired'
	&& (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` = %d", hmn_row('hmn_retired')['id'])) === 1);
hmn_due('hmn_retired');
hmn_monitor();
hmn_ok('  reported once, not every check',                                 substr_count(hmn_notes($oR), 'arrived at hmn_retired') === 1);

// A receipt from before the order was bound is not a late payment for it.
list($oOld) = hmn_row_for('hmn_prehistory', 'retired', 'expired_unpaid');
hmn_monitor();
hmn_pay('hmn_prehistory', 'pre-binding', '380000000000', 900000, strtotime('2021-05-08 14:22:17 UTC'));
hmn_due('hmn_prehistory');
hmn_monitor();
hmn_ok('a receipt from before the binding is not reported as late',       hmn_asked('hmn_prehistory') === 1 && strpos(hmn_notes($oOld), 'arrived at') === false);

// A pending sighting and its confirmation are separate reports.
list($oP) = hmn_row_for('hmn_pending', 'retired', 'order_cancelled');
hmn_monitor();
hmn_pay('hmn_pending', 'late-pending', '100000000', 0);
hmn_due('hmn_pending');
hmn_monitor();
hmn_ok('a late pending payment is reported as unconfirmed',                strpos(hmn_notes($oP), '(unconfirmed)') !== false);
$GLOBALS['hmn_book']['hmn_pending'][0]['confs'] = 3;
$GLOBALS['hmn_book']['hmn_pending'][0]['time'] = time() - 60;
hmn_due('hmn_pending');
hmn_monitor();
hmn_ok('  and reported again once it confirms',                            substr_count(hmn_notes($oP), 'arrived at hmn_pending') === 2);

// =====================================================================
echo "--- 2. a held order ---\n";
list($oH) = hmn_row_for('hmn_held', 'underpaid', null, 'on-hold');
hmn_pay('hmn_held', 'held-part', '1000000000');                    // the part payment, seen while open
NMMPRO_Hd_Evidence_Repo::record(hmn_row('hmn_held')['id'], $oH, 'DOGE', 'hmn_held', 'blockcypher',
	array(array('tx_hash' => hmn_hash('held-part'), 'output_index' => 0, 'amount_units' => '1000000000', 'confirmed' => true, 'confirmations' => 6, 'block_height' => 1, 'block_time' => $GLOBALS['hmn_bound'] + 600)), time() - 3600);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hmn_table']}` SET `status` = 'review', `review_reason` = 'expired_underpaid' WHERE `address` = %s", 'hmn_held'));
hmn_monitor();
hmn_ok('the part payment known before the hold is not reported again',     strpos(hmn_notes($oH), 'arrived at hmn_held') === false);
hmn_pay('hmn_held', 'held-more', '500000000', 3);                  // in a newer block, as on a real chain
hmn_due('hmn_held');
hmn_monitor();
$notes = hmn_notes($oH);
hmn_ok('a new payment to a held order is reported as held for review',     strpos($notes, '5 DOGE arrived at hmn_held') !== false && strpos($notes, 'held for manual review') !== false, substr(trim($notes), 0, 120));
hmn_ok('  and the held order is not completed',                            wc_get_order($oH)->get_status() === 'on-hold' && hmn_row('hmn_held')['status'] === 'review' && $GLOBALS['hmn_completions'] === 0);

// =====================================================================
echo "--- 3. bounded ---\n";
hmn_monitor();
hmn_ok('an address checked within the interval is not scanned again',      count($GLOBALS['hmn_requests']) === 0, count($GLOBALS['hmn_requests']) . ' requests');
hmn_row_for('hmn_old', 'retired', 'expired_unpaid', 'cancelled', $GLOBALS['hmn_now'] - (NMMPRO_Hd_Verifier::MONITOR_DAYS + 1) * DAY_IN_SECONDS);
hmn_monitor();
hmn_ok('an address bound more than 90 days ago is no longer watched',      hmn_asked('hmn_old') === 0);
foreach (array('hmn_retired', 'hmn_pending', 'hmn_held') as $a) { hmn_due($a); }
hmn_monitor(2);
hmn_ok('one cycle checks at most a batch of addresses',                    count($GLOBALS['hmn_requests']) === 2, count($GLOBALS['hmn_requests']) . ' requests');

list($oF) = hmn_row_for('hmn_failing', 'retired', 'expired_unpaid');
$GLOBALS['hmn_fail']['hmn_failing'] = true;
hmn_monitor();
hmn_ok('an explorer failure notes nothing',                                strpos(hmn_notes($oF), 'arrived at') === false);
hmn_ok('  but still spaces the next attempt',                              (int) hmn_row('hmn_failing')['last_checked'] > 0);
$fs = json_decode(hmn_row('hmn_failing')['scan_state'], true);
hmn_ok('  and records since when the address cannot be read',              isset($fs['incomplete_since']) && abs($fs['incomplete_since'] - time()) < 60);
// A day later, still failing: the order is told once that late payments
// cannot be checked; monitoring continues.
$fs['incomplete_since'] = time() - NMMPRO_Hd_Verifier::INCOMPLETE_NOTICE_SEC - 60;
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hmn_table']}` SET `scan_state` = %s WHERE `address` = %s", wp_json_encode($fs), 'hmn_failing'));
hmn_due('hmn_failing');
hmn_monitor();
hmn_due('hmn_failing');
hmn_monitor();
hmn_ok('  after a day of failures the order is told, once',                substr_count(hmn_notes($oF), 'could not be checked since') === 1, substr_count(hmn_notes($oF), 'could not be checked since') . ' notes');
unset($GLOBALS['hmn_fail']['hmn_failing']);
hmn_due('hmn_failing');
hmn_monitor();
$fs = json_decode(hmn_row('hmn_failing')['scan_state'], true);
hmn_ok('  and a complete check ends the spell',                            !isset($fs['incomplete_since']) && isset($fs['monitor_since']));

list($oB) = hmn_row_for('hmn_busy', 'retired', 'expired_unpaid');
$wpdb2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb2->suppress_errors(true);
$wpdb2->prefix = $wpdb->prefix;
$GLOBALS['wpdb'] = $wpdb2;
$held = NMMPRO_Util::acquire_address_match_lock('DOGE', 'hmn_busy');
$GLOBALS['wpdb'] = $wpdb;
hmn_monitor();
hmn_ok('a busy address is skipped without losing its turn',                $held === '1' && hmn_asked('hmn_busy') === 0 && (int) hmn_row('hmn_busy')['last_checked'] === 0);
$GLOBALS['wpdb'] = $wpdb2;
NMMPRO_Util::release_address_match_lock('DOGE', 'hmn_busy');
$GLOBALS['wpdb'] = $wpdb;
$wpdb2->close();

// Legacy held rows have no trustworthy boundary to attribute against.
$wpdb->query($wpdb->prepare(
	"INSERT INTO `{$GLOBALS['hmn_table']}` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assigned_at`,`review_reason`)
	 VALUES ('hmn_legacy','DOGE',%s,1,'review',0,%d,'1',%d,'legacy_unvalidated')", $GLOBALS['hmn_mpk'], $oB, time()));
hmn_monitor();
hmn_ok('a legacy held row is not monitored automatically',                 hmn_asked('hmn_legacy') === 0);

// Busy or failing addresses keep being due, but they no longer hold their
// place: a later address with a late payment is still reached.
$wpdb->query("UPDATE `{$GLOBALS['hmn_table']}` SET `last_checked` = UNIX_TIMESTAMP() WHERE `address` NOT LIKE 'hmn\\_rot\\_%'");
$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s", $wpdb->esc_like(NMMPRO_Hd_Verifier::CURSOR_OPTION_PREFIX) . '%'));
foreach (array('b1', 'b2', 'b3', 'x1', 'x2') as $n) { hmn_row_for('hmn_rot_' . $n, 'retired', 'expired_unpaid'); }
list($oRotT) = hmn_row_for('hmn_rot_target', 'retired', 'expired_unpaid');
hmn_pay('hmn_rot_target', 'rot-late', '700000000');
$wpdbRot = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdbRot->suppress_errors(true);
$wpdbRot->prefix = $wpdb->prefix;
$GLOBALS['wpdb'] = $wpdbRot;
$heldRot = array_map(function ($n) { return NMMPRO_Util::acquire_address_match_lock('DOGE', 'hmn_rot_' . $n); }, array('b1', 'b2', 'b3'));
$GLOBALS['wpdb'] = $wpdb;
$throwRot = function ($pre, $args, $url) { if (strpos($url, '/addrs/hmn_rot_x') !== false) { throw new RuntimeException('injected failure'); } return $pre; };
add_filter('pre_http_request', $throwRot, 5, 3);
$rotCycles = 0;
for ($cycle = 1; $cycle <= 4 && strpos(hmn_notes($oRotT), 'arrived at hmn_rot_target') === false; $cycle++) {
	hmn_monitor(2);
	$rotCycles = $cycle;
}
remove_filter('pre_http_request', $throwRot, 5);
$GLOBALS['wpdb'] = $wpdbRot;
foreach (array('b1', 'b2', 'b3') as $n) { NMMPRO_Util::release_address_match_lock('DOGE', 'hmn_rot_' . $n); }
$GLOBALS['wpdb'] = $wpdb;
$wpdbRot->close();
hmn_ok('busy and failing addresses do not keep a later late payment from being reported', $heldRot === array('1', '1', '1') && strpos(hmn_notes($oRotT), 'arrived at hmn_rot_target') !== false && $rotCycles <= 3, 'cycles=' . $rotCycles);
hmn_ok('  and the busy ones are still due, not marked checked',            (int) hmn_row('hmn_rot_b1')['last_checked'] === 0);

// =====================================================================
echo "--- 4. the order screen ---\n";
if (!function_exists('add_meta_box')) { require_once ABSPATH . 'wp-admin/includes/template.php'; }
$render = function ($orderId) {
	ob_start();
	NMMPRO_Hd_Order_Panel::render(wc_get_order($orderId));
	return ob_get_clean();
};
list($oPanel, $rowPanel) = hmn_row_for('hmn_panel', 'review', 'timestamp_ambiguous', 'on-hold');
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hmn_table']}` SET `credited_units` = '1000000000' WHERE `id` = %d", $rowPanel));
$op = wc_get_order($oPanel); $op->update_meta_data(NMMPRO_Hd_Verifier::TX_META, array(hmn_hash('panel') . ':1')); $op->save();
$html = $render($oPanel);
hmn_ok('the panel shows the state and why it is held',                    strpos($html, 'Held for manual review') !== false && strpos($html, esc_html(NMMPRO_Hd::reason_label('timestamp_ambiguous'))) !== false);
hmn_ok('  the amount due and the amount attributable to the order',       strpos($html, '456.80863668') !== false && strpos($html, '<td>10</td>') !== false);
hmn_ok('  the transactions that paid it',                                  strpos($html, hmn_hash('panel') . ':1') !== false);
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['hmn_table']}` SET `address` = %s WHERE `id` = %d", '<script>alert(1)</script>', $rowPanel));
$html = $render($oPanel);
hmn_ok('  and escapes what it prints',                                     strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false);
$plain = wc_create_order(); $plain->save(); $GLOBALS['hmn_orders'][] = $plain->get_id();
hmn_ok('an order without a Privacy Mode address gets no panel',            $render($plain->get_id()) === '');

global $wp_meta_boxes;
$wp_meta_boxes = array();
$hposScreen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
NMMPRO_Hd_Order_Panel::register('shop_order', get_post($oPanel) ?: null);
NMMPRO_Hd_Order_Panel::register($hposScreen, wc_get_order($oPanel));
NMMPRO_Hd_Order_Panel::register('shop_order', wc_get_order($plain->get_id()));
hmn_ok('the panel registers on the HPOS order screen',                     isset($wp_meta_boxes[$hposScreen]['side']['default']['nmmpro-hd-payment']));
hmn_ok('  and on the legacy screen when the order is a post',              get_post($oPanel) === null || isset($wp_meta_boxes['shop_order']['side']['default']['nmmpro-hd-payment']));

// =====================================================================
echo "--- 5. reasons and log hygiene ---\n";
$codes = array('legacy_pool', 'legacy_quarantine', 'legacy_unvalidated', 'chain_history', 'derivation_mismatch', 'superseded', 'binding_mismatch',
	'evidence_conflict', 'timestamp_ambiguous', 'legacy_owner', 'expired_underpaid', 'expired_unpaid', 'order_deleted');
$explained = true;
foreach ($codes as $code) { if (NMMPRO_Hd::reason_label($code) === $code) { $explained = false; } }
hmn_ok('every reason the plugin stores has an explanation',                $explained);
hmn_ok('an order status reason names the status',                          strpos(NMMPRO_Hd::reason_label('order_refunded'), 'refunded') !== false);
hmn_ok('an unknown reason falls back to its code',                         NMMPRO_Hd::reason_label('something_new') === 'something_new');

// A key that passes the shape check but is not a valid key.
$badKey = 'xpub6' . str_repeat('0', 106);
$message = '';
try { NMMPRO_Hd::create_hd_address('DOGE', $badKey, 2, '0'); } catch (\Throwable $t) { $message = $t->getMessage(); }
hmn_ok('an invalid key\'s error does not carry the key or a stack trace',  $message !== '' && strpos($message, 'xpub6') === false && strpos($message, '#0 ') === false, substr($message, 0, 90));
// The math backends reject such a key with a ValueError (an \Error). It used
// to escape the background buffer, which catches Exceptions, and abort the
// whole cron cycle for every coin.
$escaped = null;
try { NMMPRO_Hd::buffer_ready_addresses('DOGE', $badKey, 1, '0'); } catch (\Throwable $t) { $escaped = get_class($t); }
hmn_ok('a malformed key cannot abort the background cycle',               $escaped === null, (string) $escaped);
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hmn_table']}` WHERE `mpk` = %s", $badKey));

// --- cleanup ---
$wpdb->suppress_errors(false);
$ids = $wpdb->get_col($wpdb->prepare("SELECT `id` FROM `{$GLOBALS['hmn_table']}` WHERE `mpk` = %s", $GLOBALS['hmn_mpk']));
if ($ids) { $wpdb->query("DELETE FROM `" . NMMPRO_Hd_Schema::evidence_table() . "` WHERE `hd_id` IN (" . implode(',', array_map('intval', $ids)) . ")"); }
$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['hmn_table']}` WHERE `mpk` = %s", $GLOBALS['hmn_mpk']));
$wpdb->query("DELETE FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE `address` LIKE 'hmn\\_%'");
foreach ($GLOBALS['hmn_orders'] as $oid) { $o = wc_get_order($oid); if ($o) { $o->delete(true); } }
hmn_reset_backoff();

echo $GLOBALS['hmn_ok'] ? "\nHD-MONITOR CHECKS PASSED\n" : "\nHD-MONITOR CHECKS FAILED\n";
