<?php
/**
 * Live-DB test: Privacy Mode (HD) schema 1.5 and the migration gate.
 *
 * - The 1.4 -> 1.5 upgrade adds every evidence column, converts a legacy
 *   MyISAM HD table, creates the InnoDB evidence table, and applies the
 *   conservative legacy policy (pool and quarantine rows retire; unvalidated
 *   in-flight assignments go to review) - verifying all of it before it
 *   reports success, so the version only moves on a complete upgrade.
 * - A failed or partial upgrade leaves the version where it was, keeps
 *   automatic HD off, and succeeds on retry.
 * - Nothing is back-filled: no legacy row gains a validated assignment or an
 *   assignment boundary.
 * - Until the schema is ready and a coin has a reviewed evidence adapter, no
 *   Privacy Mode address is issued and no order is verified or expired.
 *
 * The upgrade runs on scratch tables holding a frozen copy of the 1.4 schema,
 * so the site's real table is only used for the gate and review checks.
 * Requires WordPress + WooCommerce + a database; destructive fixtures, so use
 * an isolated installation. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-hd-migration.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !class_exists('NMMPRO_Hd_Schema') || !function_exists('wc_create_order') || !class_exists('NMMPRO_Gateway')) {
	echo "test-hd-migration: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$GLOBALS['hm_ok'] = true;

function hm_ok($label, $cond, $extra = '') {
	printf("%-70s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['hm_ok'] = false; }
}

$hmHd  = $wpdb->prefix . 'nmmhdmig_hd';
$hmEv  = $wpdb->prefix . 'nmmhdmig_ev';
$hmHd2 = $wpdb->prefix . 'nmmhdmig2_hd';   // a second "site"

// The HD table exactly as schema 1.4 left it (base table + hd_mode + the 1.2-1.4
// indexes). Frozen here on purpose: a migration test must start from the old
// shape, not from whatever the current code would create.
function hm_create_14($table, $engine = 'InnoDB') {
	global $wpdb;
	$wpdb->query("DROP TABLE IF EXISTS `$table`");
	$wpdb->query("CREATE TABLE `$table` (
		`id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
		`mpk` char(150) NOT NULL,
		`mpk_index` bigint(20) NOT NULL DEFAULT '0',
		`address` char(199) NOT NULL,
		`cryptocurrency` char(7) NOT NULL,
		`status` char(24) NOT NULL DEFAULT 'error',
		`total_received` decimal(16,8) NOT NULL DEFAULT '0.00000000',
		`last_checked` bigint(20) NOT NULL DEFAULT '0',
		`assigned_at` bigint(20) NOT NULL DEFAULT '0',
		`order_id` bigint(10) NULL,
		`order_amount` decimal(16,8) NOT NULL DEFAULT '0.00000000',
		`all_order_ids` text NULL,
		`hd_mode` bigint(10) NOT NULL DEFAULT '0',
		PRIMARY KEY (`id`),
		UNIQUE KEY `hd_address` (`cryptocurrency`, `address`),
		KEY `status` (`status`),
		KEY `status_checked` (`status`, `last_checked`),
		KEY `mpk_index` (`mpk_index`),
		KEY `mpk` (`mpk`),
		KEY `order_lookup` (`order_id`, `id`),
		KEY `hd_pool` (`cryptocurrency`, `hd_mode`, `status`, `mpk_index`),
		KEY `hd_wallet_status` (`cryptocurrency`, `hd_mode`, `status`, `mpk`)
	) ENGINE=$engine " . $wpdb->get_charset_collate());
}

function hm_seed_legacy($table) {
	global $wpdb;
	$rows = array(
		// address, status, index, order, amount, total, assigned_at
		array('leg_ready',       'ready',               10, null, '0',          '0',           0),
		array('leg_quarantine',  'quarantine',          11, 501,  '1.00000000', '0',           1700000000),
		array('leg_qverified',   'quarantine_verified', 12, 502,  '1.00000000', '0',           1700000100),
		array('leg_assigned',    'assigned',            13, 503,  '456.80863668', '3800.00000000', 1790000000),
		array('leg_underpaid',   'underpaid',           14, 504,  '2.00000000', '0.50000000',  1790000100),
		array('leg_completing',  'completing',          15, 505,  '1.00000000', '1.00000000',  1790000200),
		array('leg_complete',    'complete',            16, 506,  '1.00000000', '1.00000000',  1780000000),
		array('leg_dirty',       'dirty',               17, null, '0',          '5.00000000',  0),
	);
	foreach ($rows as $r) {
		$wpdb->query($wpdb->prepare(
			"INSERT INTO `$table` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`total_received`,`assigned_at`)
			 VALUES (%s,'DOGE','hm_mpk',%d,%s,0," . ($r[3] === null ? 'NULL' : (int) $r[3]) . ",%s,%s,%d)",
			$r[0], $r[2], $r[1], $r[4], $r[5], $r[6]
		));
	}
}

function hm_rows($table) {
	global $wpdb;
	$out = array();
	foreach ((array) $wpdb->get_results("SELECT * FROM `$table` ORDER BY `id`", ARRAY_A) as $row) {
		$out[$row['address']] = $row;
	}
	return $out;
}

// Make any statement matching $pattern fail, as a refused ALTER or a lost
// connection would.
function hm_fail_queries($pattern) {
	$f = function ($sql) use ($pattern) {
		return preg_match($pattern, $sql) ? 'SELECT * FROM `nmmhdmig_injected_failure_no_such_table`' : $sql;
	};
	add_filter('query', $f);
	return $f;
}

$reportBefore = NMMPRO_Compat::get_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION, null);
NMMPRO_Compat::delete_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION);
$wpdb->suppress_errors(true);

// =====================================================================
echo "--- 1. fresh install ---\n";
hm_create_14($hmHd);
$wpdb->query("DROP TABLE IF EXISTS `$hmEv`");
hm_ok('an empty 1.4 table upgrades',                              NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === true);
hm_ok('  every 1.5 column is present',                           NMMPRO_Hd_Schema::missing_columns($hmHd) === array());
hm_ok('  the evidence table is InnoDB',                          NMMPRO_Hd_Schema::engine($hmEv) === 'innodb');
hm_ok('  with its output-identity key',                          !empty($wpdb->get_results("SHOW INDEX FROM `$hmEv` WHERE Key_name = 'output_identity'")));
hm_ok('  no legacy report is recorded when nothing moved',       NMMPRO_Compat::get_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION, null) === null);

// The evidence identity is enforced by the database, not by a read-then-write.
$evIns = function () use ($wpdb, $hmEv) {
	return $wpdb->query($wpdb->prepare(
		"INSERT INTO `$hmEv` (`hd_id`,`order_id`,`coin`,`address`,`tx_hash`,`output_index`,`amount_units`,`source`,`first_seen_at`,`observed_at`,`state`)
		 VALUES (1, 7, 'DOGE', 'a', %s, 0, '100', 'test', 1, 1, 'observed')", str_repeat('ab', 32)));
};
hm_ok('  a first output observation is stored',                  $evIns() === 1);
hm_ok('  the same output cannot be stored twice',                $evIns() === false);

// =====================================================================
echo "--- 2. old schema with legacy rows ---\n";
hm_create_14($hmHd);
hm_seed_legacy($hmHd);
hm_create_14($hmHd2);
hm_seed_legacy($hmHd2);
$before = hm_rows($hmHd);
$before2 = hm_rows($hmHd2);
$maxIndexBefore = (int) $wpdb->get_var("SELECT MAX(`mpk_index`) FROM `$hmHd`");

hm_ok('a 1.4 table with legacy rows upgrades',                   NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === true);
$after = hm_rows($hmHd);

$expect = array(
	'leg_ready'      => array('retired',  NMMPRO_Hd_Schema::REASON_LEGACY_POOL),
	'leg_quarantine' => array('retired',  NMMPRO_Hd_Schema::REASON_LEGACY_QUARANTINE),
	'leg_qverified'  => array('retired',  NMMPRO_Hd_Schema::REASON_LEGACY_QUARANTINE),
	'leg_assigned'   => array('review',   NMMPRO_Hd_Schema::REASON_LEGACY_UNVALIDATED),
	'leg_underpaid'  => array('review',   NMMPRO_Hd_Schema::REASON_LEGACY_UNVALIDATED),
	'leg_completing' => array('review',   NMMPRO_Hd_Schema::REASON_LEGACY_UNVALIDATED),
	'leg_complete'   => array('complete', null),
	'leg_dirty'      => array('dirty',    null),
);
foreach ($expect as $address => $want) {
	$row = isset($after[$address]) ? $after[$address] : null;
	hm_ok(sprintf('  %-15s -> %s', $address, $want[0] . ($want[1] ? " ($want[1])" : '')),
		$row && $row['status'] === $want[0] && $row['review_reason'] === $want[1],
		$row ? 'got=' . $row['status'] . '/' . var_export($row['review_reason'], true) : '(missing)');
}

$preserved = true;
$notBackfilled = true;
foreach ($before as $address => $old) {
	$new = $after[$address];
	foreach (array('id', 'mpk', 'mpk_index', 'order_id', 'order_amount', 'total_received', 'assigned_at', 'hd_mode') as $col) {
		if ((string) $old[$col] !== (string) $new[$col]) { $preserved = false; }
	}
	foreach (array('assignment_version', 'pool_version', 'bound_at', 'validated_at', 'validated_height', 'reservation_token', 'credited_units') as $col) {
		if ($new[$col] !== null) { $notBackfilled = false; }
	}
}
hm_ok('  every row is kept, with its order, amount, index and times', count($after) === count($before) && $preserved);
hm_ok('  no row is back-filled with a validated assignment',     $notBackfilled);
hm_ok('  derivation still starts past the highest index',        (int) $wpdb->get_var("SELECT MAX(`mpk_index`) FROM `$hmHd`") === $maxIndexBefore);
$report = NMMPRO_Compat::get_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION, null);
hm_ok('  the moved counts are recorded for the admin report',    is_array($report) && $report['retired_pool'] === 1 && $report['retired_quarantine'] === 2 && $report['review_unvalidated'] === 3, wp_json_encode($report));
hm_ok('another site\'s table is untouched',                      hm_rows($hmHd2) === $before2);

echo "--- 3. repeated upgrade ---\n";
$snapshot = hm_rows($hmHd);
hm_ok('upgrading again succeeds',                                NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === true);
hm_ok('  and changes nothing',                                   hm_rows($hmHd) === $snapshot);

// =====================================================================
echo "--- 4. partial failure and retry ---\n";
hm_create_14($hmHd);
hm_seed_legacy($hmHd);
$f = hm_fail_queries('/^ALTER TABLE `' . preg_quote($hmHd, '/') . '` ADD /');
hm_ok('a refused column ALTER fails the upgrade',                NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === false);
remove_filter('query', $f);
hm_ok('  leaving the columns absent',                            count((array) NMMPRO_Hd_Schema::missing_columns($hmHd)) === count(NMMPRO_Hd_Schema::hd_columns()));
hm_ok('  and every legacy row as it was',                        hm_rows($hmHd)['leg_assigned']['status'] === 'assigned');

// Only one column missing: nothing downstream reads it, so the column check
// itself must refuse the upgrade.
$wpdb->query("ALTER TABLE `$hmHd` " . implode(', ', array_map(function ($c) { return 'ADD `' . $c . '` ' . NMMPRO_Hd_Schema::hd_columns()[$c]; }, array_diff(array_keys(NMMPRO_Hd_Schema::hd_columns()), array('scan_state')))));
$f = hm_fail_queries('/^ALTER TABLE `' . preg_quote($hmHd, '/') . '` ADD /');
hm_ok('one refused column still fails the upgrade',              NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === false);
remove_filter('query', $f);
hm_ok('  (scan_state is the column missing)',                    NMMPRO_Hd_Schema::missing_columns($hmHd) === array('scan_state'));

$f = hm_fail_queries("/SET `status` = 'review'/");
hm_ok('a failed legacy-policy write fails the upgrade',          NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === false);
remove_filter('query', $f);
hm_ok('  and no unvalidated assignment reaches the new path',   hm_rows($hmHd)['leg_assigned']['assignment_version'] === null);
hm_ok('the retry completes the upgrade',                         NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === true);
hm_ok('  and applies the policy',                                hm_rows($hmHd)['leg_assigned']['status'] === 'review');

// Through the real version bookkeeping, on the site's own table: the version
// only moves when the upgrade verifiably completed, and the gate follows it.
$realVersion = NMMPRO_Compat::get_option('nmmpro_hd_table_version', '1.0');
hm_ok('fixture: the site itself is at 1.5',                      $realVersion === NMMPRO_Hd_Schema::VERSION, 'version=' . $realVersion);
NMMPRO_Compat::update_option('nmmpro_hd_table_version', '1.4');
$f = hm_fail_queries("/SET `status` = 'retired'/");
NMMPRO_update_hd_table();
remove_filter('query', $f);
hm_ok('a failed 1.5 step leaves the site at 1.4',                NMMPRO_Compat::get_option('nmmpro_hd_table_version', '1.0') === '1.4');
hm_ok('  and automatic HD is off for the schema',                NMMPRO_Hd::automatic_unavailable_reason('DOGE') === 'schema');
NMMPRO_update_hd_table();
hm_ok('the next load completes it',                              NMMPRO_Compat::get_option('nmmpro_hd_table_version', '1.0') === NMMPRO_Hd_Schema::VERSION);

// =====================================================================
echo "--- 5. non-transactional engine ---\n";
hm_create_14($hmHd, 'MyISAM');
hm_seed_legacy($hmHd);
hm_ok('fixture: the legacy table is MyISAM',                     NMMPRO_Hd_Schema::engine($hmHd) === 'myisam');
$f = hm_fail_queries('/ENGINE=InnoDB$/');
hm_ok('a refused engine conversion fails the upgrade',           NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === false);
remove_filter('query', $f);
hm_ok('  (the table is still MyISAM)',                           NMMPRO_Hd_Schema::engine($hmHd) === 'myisam');
hm_ok('an allowed conversion completes it',                      NMMPRO_Hd_Schema::upgrade($hmHd, $hmEv) === true);
hm_ok('  the HD table is now InnoDB',                            NMMPRO_Hd_Schema::engine($hmHd) === 'innodb');
hm_ok('  with every row kept',                                   count(hm_rows($hmHd)) === 8);

$wpdb->query("DROP TABLE IF EXISTS `$hmHd`");
$wpdb->query("DROP TABLE IF EXISTS `$hmHd2`");
$wpdb->query("DROP TABLE IF EXISTS `$hmEv`");
$wpdb->suppress_errors(false);

// =====================================================================
// 6. The gate, on the site's real table.
// =====================================================================
echo "--- 6. the gate ---\n";
$realHd = $wpdb->prefix . NMMPRO_HD_TABLE;
$gateMpk = 'hm_gate_mpk';
$wpdb->query($wpdb->prepare("DELETE FROM `$realHd` WHERE `mpk` = %s", $gateMpk));

hm_ok('XMY has no evidence adapter',                             NMMPRO_Hd::automatic_unavailable_reason('XMY') === 'unsupported');

hm_ok('QTUM, BTX and XMY have no evidence adapter',              NMMPRO_Hd::automatic_unavailable_reason('QTUM') === 'unsupported' && NMMPRO_Hd::automatic_unavailable_reason('BTX') === 'unsupported');

$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
wp_cache_flush();

// Answers like BlockCypher would for the incident: lifetime receipts of 3800
// DOGE on the funded address and none on the expired one. If any HD pass ran
// on an unvalidated row, the old code would pay the first and cancel the
// second - so these are the requests the gate must never let happen.
$requests = array();
$recorder = function ($pre, $args, $url) use (&$requests) {
	$requests[] = $url;
	$total = strpos($url, 'hm_gate_legacy') !== false ? 380000000000 : 0;
	return array('response' => array('code' => 200, 'message' => 'OK'), 'headers' => array(), 'cookies' => array(),
		'body' => wp_json_encode(array('address' => 'x', 'total_received' => $total, 'balance' => $total, 'final_balance' => $total, 'n_tx' => $total ? 1 : 0)));
};

// Funded and expired legacy rows an older release left 'assigned' (say,
// after a downgrade).
$legacyOrder = wc_create_order();
$legacyOrder->set_total('100.00');
$legacyOrder->save();
$legacyOrder->update_status('on-hold');
$expiredOrder = wc_create_order();
$expiredOrder->set_total('100.00');
$expiredOrder->save();
$expiredOrder->update_status('on-hold');
foreach (array(array('hm_gate_legacy', $legacyOrder, 2), array('hm_gate_expired', $expiredOrder, 3)) as $g) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$realHd` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`total_received`,`assigned_at`)
		 VALUES (%s,'DOGE',%s,%d,'assigned',0,%d,'456.80863668','0',%d)",
		$g[0], $gateMpk, $g[2], $g[1]->get_id(), time() - 7 * DAY_IN_SECONDS));
}

add_filter('pre_http_request', $recorder, 10, 3);
NMMPRO_Hd::reset_observed_totals();
NMMPRO_Hd::check_all_pending_addresses_for_payment('DOGE', $gateMpk, 2, '0.99', 0);
NMMPRO_Hd::cancel_expired_addresses('DOGE', $gateMpk, 3600, 0);
remove_filter('pre_http_request', $recorder, 10);
hm_ok('the verifier does not pay an unvalidated funded row',     wc_get_order($legacyOrder->get_id())->get_status() === 'on-hold', 'status=' . wc_get_order($legacyOrder->get_id())->get_status());
hm_ok('expiry does not cancel an unvalidated expired row',       wc_get_order($expiredOrder->get_id())->get_status() === 'on-hold', 'status=' . wc_get_order($expiredOrder->get_id())->get_status());
hm_ok('  and no explorer request is made',                       $requests === array(), implode(' ', $requests));

NMMPRO_Hd_Schema::apply_legacy_policy($realHd);
hm_ok('the per-tick legacy policy moves it to review',           $wpdb->get_var("SELECT `status` FROM `$realHd` WHERE `address` = 'hm_gate_legacy'") === 'review');

// Checkout: Privacy Mode enabled, but the schema not (yet) ready.
$settingsBefore = get_option(NMMPRO_REDUX_ID, null);
$settings = is_array($settingsBefore) ? $settingsBefore : array();
$settings['crypto_select'] = array('DOGE', 'XMY');
$settings['DOGE_mode'] = '2';
$settings['XMY_mode'] = '2';
$settings['DOGE_hd_mpk'] = 'xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ';
$settings['XMY_hd_mpk'] = $settings['DOGE_hd_mpk'];
$settings['selected_price_apis'] = array('0');
update_option(NMMPRO_REDUX_ID, $settings);
foreach (array('DOGE', 'XMY') as $cid) {
	set_transient('nmmpro_rate_coingecko_' . $cid, 0.25, HOUR_IN_SECONDS);
	set_transient('nmmpro_rate_good_' . $cid, array('price' => 0.25, 'time' => time()), HOUR_IN_SECONDS);
}
$stg = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

$gateCheckout = function ($cryptoId) use ($wpdb, $realHd, $recorder, &$requests) {
	$requests = array();
	$o = wc_create_order();
	$o->set_total('100.00');
	$o->save();
	add_filter('pre_http_request', $recorder, 10, 3);
	$result = (new NMMPRO_Gateway())->initialize_order_payment($o->get_id(), $cryptoId);
	remove_filter('pre_http_request', $recorder, 10);
	$after = wc_get_order($o->get_id());
	$after->read_meta_data(true);
	$bound = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$realHd` WHERE `order_id` = %d", $o->get_id()));
	$state = array(
		'outcome' => isset($result['outcome']) ? $result['outcome'] : '?',
		'address' => (string) $after->get_meta('wallet_address'),
		'bound'   => $bound,
		'status'  => $after->get_status(),
		'requests' => count($requests),
	);
	$after->delete(true);
	return $state;
};

NMMPRO_Compat::update_option('nmmpro_hd_table_version', '1.4');
$pendingCheckout = $gateCheckout('DOGE');
hm_ok('schema pending: Privacy Mode DOGE is not offered',        !$stg->crypto_selected_and_valid('DOGE'));
hm_ok('  a DOGE order is failed without an address',             $pendingCheckout['outcome'] === 'failed' && $pendingCheckout['address'] === '' && $pendingCheckout['bound'] === 0, wp_json_encode($pendingCheckout));
hm_ok('  before contacting any explorer',                        $pendingCheckout['requests'] === 0);
NMMPRO_Compat::update_option('nmmpro_hd_table_version', NMMPRO_Hd_Schema::VERSION);

$unsupportedCheckout = $gateCheckout('XMY');
hm_ok('unsupported coin: Privacy Mode XMY is not offered',       !$stg->crypto_selected_and_valid('XMY'));
hm_ok('  an XMY order is failed without an address',             $unsupportedCheckout['outcome'] === 'failed' && $unsupportedCheckout['address'] === '' && $unsupportedCheckout['bound'] === 0, wp_json_encode($unsupportedCheckout));
hm_ok('  before contacting any explorer',                        $unsupportedCheckout['requests'] === 0);

if ($settingsBefore === null) { delete_option(NMMPRO_REDUX_ID); } else { update_option(NMMPRO_REDUX_ID, $settingsBefore); }
foreach (array('DOGE', 'XMY') as $cid) {
	delete_transient('nmmpro_rate_coingecko_' . $cid);
	delete_transient('nmmpro_rate_good_' . $cid);
	delete_transient('nmmpro_rate_reference_' . $cid);
}

// =====================================================================
echo "--- 7. held rows follow the merchant's decision ---\n";
$mk = function ($status) {
	$o = wc_create_order();
	$o->set_total('10.00');
	$o->save();
	$o->update_status($status);
	return $o->get_id();
};
$heldOrders = array(
	'hm_held_open'      => $mk('on-hold'),
	'hm_held_paid'      => $mk('processing'),
	'hm_held_cancelled' => $mk('cancelled'),
	'hm_held_deleted'   => $mk('on-hold'),
);
wc_get_order($heldOrders['hm_held_deleted'])->delete(true);
$index = 20;
foreach ($heldOrders as $address => $orderId) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$realHd` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assigned_at`,`review_reason`)
		 VALUES (%s,'DOGE',%s,%d,'review',0,%d,'1.00000000',%d,%s)",
		$address, $gateMpk, $index++, $orderId, time(), NMMPRO_Hd_Schema::REASON_LEGACY_UNVALIDATED));
}
NMMPRO_Compat::update_option('nmmpro_hd_review_cursor', 0, false);
NMMPRO_Hd::settle_review_rows(1000);
$held = function ($address) use ($wpdb, $realHd) {
	return $wpdb->get_var($wpdb->prepare("SELECT `status` FROM `$realHd` WHERE `address` = %s", $address));
};
hm_ok('an order still awaiting payment stays held',              $held('hm_held_open') === 'review');
hm_ok('  and is not changed',                                    wc_get_order($heldOrders['hm_held_open'])->get_status() === 'on-hold');
hm_ok('an order the merchant marked paid settles the row',       $held('hm_held_paid') === 'complete');
hm_ok('a cancelled order retires the row',                       $held('hm_held_cancelled') === 'retired');
hm_ok('a deleted order retires the row',                         $held('hm_held_deleted') === 'retired');

// A read that fails is not a deleted order.
$f = hm_fail_queries('/SELECT COUNT\(\*\) FROM `' . preg_quote($wpdb->posts, '/') . '` WHERE `ID`|wc_orders` WHERE `id`/');
$ghost = $mk('on-hold');
wc_get_order($ghost)->delete(true);
$wpdb->query($wpdb->prepare(
	"INSERT INTO `$realHd` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assigned_at`)
	 VALUES ('hm_held_unreadable','DOGE',%s,%d,'review',0,%d,'1.00000000',%d)", $gateMpk, $index++, $ghost, time()));
NMMPRO_Compat::update_option('nmmpro_hd_review_cursor', 0, false);
$wpdb->suppress_errors(true);
NMMPRO_Hd::settle_review_rows(1000);
$wpdb->suppress_errors(false);
remove_filter('query', $f);
hm_ok('an order that cannot be read keeps its row held',         $held('hm_held_unreadable') === 'review');

// =====================================================================
echo "--- 8. setters are scoped to their wallet ---\n";
$wpdb->query($wpdb->prepare(
	"INSERT INTO `$realHd` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`) VALUES ('hm_wallet_row','DOGE',%s,40,'dirty',0)", $gateMpk));
$otherWallet = new NMMPRO_Hd_Repo('DOGE', 'hm_some_other_wallet', 0);
$walletRowId = (int) $wpdb->get_var("SELECT `id` FROM `$realHd` WHERE `address` = 'hm_wallet_row'");
$wpdb->query("UPDATE `$realHd` SET `status` = 'assigned', `order_id` = 999 WHERE `address` = 'hm_wallet_row'");
$movedByOther = $otherWallet->transition($walletRowId, 999, array('assigned'), 'retired', 'order_cancelled');
$retiredByOther = $otherWallet->retire_address('hm_wallet_row', 'order_cancelled');
$wr = $wpdb->get_row("SELECT `status`, `review_reason` FROM `$realHd` WHERE `address` = 'hm_wallet_row'", ARRAY_A);
hm_ok('another wallet\'s repository cannot change the row',     !$movedByOther && !$retiredByOther && $wr['status'] === 'assigned' && $wr['review_reason'] === null, wp_json_encode($wr));
$ownWallet = new NMMPRO_Hd_Repo('DOGE', $gateMpk, 0);
hm_ok('  while its own wallet can',                              $ownWallet->transition($walletRowId, 999, array('assigned'), 'retired', 'order_cancelled'));

// --- cleanup ---
foreach (array_merge(array($legacyOrder->get_id(), $expiredOrder->get_id()), array_values($heldOrders)) as $oid) {
	$o = wc_get_order($oid);
	if ($o) { $o->delete(true); }
}
$wpdb->query($wpdb->prepare("DELETE FROM `$realHd` WHERE `mpk` = %s", $gateMpk));
NMMPRO_Compat::delete_option('nmmpro_hd_review_cursor');
if ($reportBefore === null) { NMMPRO_Compat::delete_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION); }
else { NMMPRO_Compat::update_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION, $reportBefore, false); }

echo $GLOBALS['hm_ok'] ? "\nHD-MIGRATION CHECKS PASSED\n" : "\nHD-MIGRATION CHECKS FAILED\n";
