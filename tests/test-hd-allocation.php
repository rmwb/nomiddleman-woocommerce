<?php
/**
 * Live-DB test: Privacy Mode (HD) checkout allocation and permanent non-reuse.
 *
 * NMMPRO_Hd_Allocator reserves a candidate, checks it on chain at the moment
 * of issue, and binds it to the order with the database clock before the
 * checkout may show it. The suite covers:
 *
 *   - clean-address evidence (offline): a spent address with a zero balance
 *     is used, a pending transfer is used, and a malformed, partial or
 *     wrong-address answer is unknown, never clean;
 *   - allocation: stale pool rows, prior receipts, pending transfers,
 *     explorer failures, reservation failures and rows that do not match the
 *     key are never issued; unknown evidence is deferred, not guessed;
 *   - non-reuse: an issued address never returns to the pool; a retried
 *     checkout keeps its own address and boundary;
 *   - reservation races, with a second database connection as the other
 *     worker: one candidate is never bound twice, a stale worker cannot
 *     finalize, and a concurrent pool fill is absorbed;
 *   - wallet recovery: derivation never rewinds, the diagnostic reports the
 *     scan range, and repeated chain history suspends rather than reuses.
 *
 * DOGE via offline BlockCypher answers; the allocator is exercised directly
 * (the automatic-HD gate that fronts it at checkout is covered by
 * test-hd-migration.php). Requires WordPress + WooCommerce + a database;
 * destructive fixtures, so use an isolated installation.
 *
 *   Run:  wp eval-file tests/test-hd-allocation.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !class_exists('NMMPRO_Hd_Allocator') || !function_exists('wc_create_order')) {
	echo "test-hd-allocation: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$GLOBALS['ha_ok'] = true;

function ha_ok($label, $cond, $extra = '') {
	printf("%-72s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['ha_ok'] = false; }
}

const HA_XPUB = 'xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ';
const HA_OTHER_KEY = 'ha_other_wallet_key';
$GLOBALS['ha_table'] = $wpdb->prefix . NMMPRO_HD_TABLE;

// =====================================================================
echo "--- 1. clean-address evidence (offline) ---\n";
$c = function ($body) { return NMMPRO_Hd_Evidence::classify_blockcypher_summary($body, 'DAddr')['state']; };
$zero = array('address' => 'DAddr', 'total_received' => 0, 'total_sent' => 0, 'balance' => 0, 'unconfirmed_balance' => 0, 'final_balance' => 0, 'n_tx' => 0, 'unconfirmed_n_tx' => 0, 'final_n_tx' => 0);
ha_ok('an address with no activity is clean',                          $c($zero) === 'clean');
ha_ok('confirmed receipts: used',                                      $c(array_merge($zero, array('total_received' => 1, 'n_tx' => 1, 'final_n_tx' => 1))) === 'used');
ha_ok('received and fully spent (balance 0): used',                    $c(array_merge($zero, array('total_received' => 500000000, 'total_sent' => 500000000, 'n_tx' => 2, 'final_n_tx' => 2))) === 'used');
ha_ok('a pending incoming transfer: used',                             $c(array_merge($zero, array('unconfirmed_n_tx' => 1, 'final_n_tx' => 1, 'unconfirmed_balance' => 100))) === 'used');
ha_ok('a pending transfer counted only in final_n_tx: used',           $c(array_merge($zero, array('final_n_tx' => 1))) === 'used');
ha_ok('a big-integer total (string) is read exactly',                  $c(array_merge($zero, array('total_received' => '98765432109876543210'))) === 'used');
ha_ok('an empty answer is unknown, not clean',                         $c(array()) === 'unknown');
ha_ok('a missing counter is unknown',                                  $c(array_diff_key($zero, array('unconfirmed_n_tx' => 1))) === 'unknown');
ha_ok('a non-numeric counter is unknown',                              $c(array_merge($zero, array('n_tx' => 'n/a'))) === 'unknown');
ha_ok('a fractional counter is unknown',                               $c(array_merge($zero, array('total_received' => 0.5))) === 'unknown');
ha_ok('a negative receipt total is unknown',                           $c(array_merge($zero, array('total_received' => -1))) === 'unknown');
ha_ok('an answer about another address is unknown',                    $c(array_merge($zero, array('address' => 'DOther'))) === 'unknown');
ha_ok('a non-object answer is unknown',                                $c('0') === 'unknown');
ha_ok('a coin without a check is unknown',                             NMMPRO_Hd_Evidence::address_activity('BTX', 'x')['state'] === 'unknown');

// =====================================================================
// Offline BlockCypher DOGE. Addresses default to a valid empty history;
// $GLOBALS['ha_chain'][$address] overrides with 'used' | 'spent' | 'pending'
// | 'fail' | 'malformed' | 'other'.
// =====================================================================
$GLOBALS['ha_chain'] = array();
$GLOBALS['ha_asked'] = array();
const HA_TIP = 5400000;
$GLOBALS['ha_tip_fail'] = false;
$GLOBALS['ha_mock'] = function ($pre, $args, $url) {
	if ($url === 'https://api.blockcypher.com/v1/doge/main') {
		$GLOBALS['ha_asked'][] = 'TIP';
		return $GLOBALS['ha_tip_fail']
			? array('response' => array('code' => 500, 'message' => 'Error'), 'body' => '', 'headers' => array(), 'cookies' => array())
			: array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode(array('name' => 'DOGE.main', 'height' => HA_TIP)), 'headers' => array(), 'cookies' => array());
	}
	if (!preg_match('#^https://api\.blockcypher\.com/v1/doge/main/addrs/([^/?]+)/balance#', $url, $m)) {
		$GLOBALS['ha_asked'][] = 'UNMOCKED ' . $url;
		return new WP_Error('ha_unmocked', 'unmocked ' . $url);
	}
	$address = rawurldecode($m[1]);
	$GLOBALS['ha_asked'][] = $address;
	$kind = isset($GLOBALS['ha_chain'][$address]) ? $GLOBALS['ha_chain'][$address] : 'clean';
	if ($kind === 'fail') {
		return array('response' => array('code' => 500, 'message' => 'Error'), 'body' => '', 'headers' => array(), 'cookies' => array());
	}
	$body = array('address' => $address, 'total_received' => 0, 'total_sent' => 0, 'balance' => 0, 'unconfirmed_balance' => 0, 'final_balance' => 0, 'n_tx' => 0, 'unconfirmed_n_tx' => 0, 'final_n_tx' => 0);
	if ($kind === 'used')      { $body = array_merge($body, array('total_received' => 5000000000, 'balance' => 5000000000, 'final_balance' => 5000000000, 'n_tx' => 1, 'final_n_tx' => 1)); }
	if ($kind === 'spent')     { $body = array_merge($body, array('total_received' => 5000000000, 'total_sent' => 5000000000, 'n_tx' => 2, 'final_n_tx' => 2)); }
	if ($kind === 'pending')   { $body = array_merge($body, array('unconfirmed_balance' => 100000000, 'final_balance' => 100000000, 'unconfirmed_n_tx' => 1, 'final_n_tx' => 1)); }
	if ($kind === 'malformed') { $body = array('address' => $address); }
	if ($kind === 'other')     { $body['address'] = 'DSomeOtherAddress'; }
	return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => wp_json_encode($body), 'headers' => array(), 'cookies' => array());
};

function ha_addr($index) {
	return NMMPRO_Hd::create_hd_address('DOGE', HA_XPUB, $index, '0');
}

function ha_reset_wallet() {
	global $wpdb;
	$wpdb->query($wpdb->prepare("DELETE FROM `{$GLOBALS['ha_table']}` WHERE `cryptocurrency` = 'DOGE' AND `mpk` IN (%s, %s)", HA_XPUB, HA_OTHER_KEY));
	$GLOBALS['ha_chain'] = array();
	$GLOBALS['ha_asked'] = array();
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
	wp_cache_flush();
}

function ha_pool($index) {
	$repo = new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0');
	return $repo->insert_pool(ha_addr($index), $index);
}

function ha_row($address) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$GLOBALS['ha_table']}` WHERE `cryptocurrency` = 'DOGE' AND `address` = %s", $address), ARRAY_A);
}

function ha_order() {
	$o = wc_create_order();
	$o->set_total('10.00');
	$o->save();
	$GLOBALS['ha_orders'][] = $o->get_id();
	return $o->get_id();
}

// Allocate with the mock in place; returns array(address|null, message).
function ha_allocate($orderId, $amount = '40.00000000', $mpk = HA_XPUB) {
	add_filter('pre_http_request', $GLOBALS['ha_mock'], 10, 3);
	try {
		return array(NMMPRO_Hd_Allocator::allocate('DOGE', $mpk, '0', $orderId, $amount), '');
	}
	catch (\Exception $e) {
		return array(null, $e->getMessage());
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['ha_mock'], 10);
	}
}

function ha_asked_before($address) {
	return in_array($address, $GLOBALS['ha_asked'], true);
}

$GLOBALS['ha_orders'] = array();
$wpdb->suppress_errors(true);

// =====================================================================
echo "--- 2. allocation ---\n";
ha_reset_wallet();
ha_pool(2); ha_pool(3); ha_pool(4);
$GLOBALS['ha_chain'][ha_addr(2)] = 'used';     // stale pool row: received funds since it was derived
$GLOBALS['ha_chain'][ha_addr(3)] = 'spent';    // prior receipts, now a zero balance
$o1 = ha_order();
$dbNow = (int) $wpdb->get_var('SELECT UNIX_TIMESTAMP()');
list($a1, $m1) = ha_allocate($o1);
ha_ok('a stale pool row with receipts is never issued',                $a1 !== ha_addr(2), 'issued=' . var_export($a1, true));
ha_ok('  it is retired for good (chain_history)',                      ha_row(ha_addr(2))['status'] === 'retired' && ha_row(ha_addr(2))['review_reason'] === 'chain_history');
ha_ok('a spent address with a zero balance is never issued',           $a1 !== ha_addr(3) && ha_row(ha_addr(3))['status'] === 'retired');
ha_ok('the next clean candidate is issued',                            $a1 === ha_addr(4), 'issued=' . var_export($a1, true) . ' ' . $m1);
ha_ok('  after checking it on chain',                                  ha_asked_before(ha_addr(4)));
$r1 = ha_row(ha_addr(4));
ha_ok('  bound to the order, validated',                               $r1['status'] === 'assigned' && (int) $r1['order_id'] === $o1 && (int) $r1['assignment_version'] === 1, wp_json_encode(array_intersect_key($r1, array_flip(array('status', 'order_id', 'assignment_version')))));
ha_ok('  and the chain height read just before binding, as its block boundary', (int) $r1['validated_height'] === HA_TIP && end($GLOBALS['ha_asked']) === 'TIP', 'validated_height=' . var_export($r1['validated_height'], true));
ha_ok('  with a database-clock boundary set once',                     $r1['bound_at'] !== null && abs((int) $r1['bound_at'] - $dbNow) <= 5 && $r1['bound_at'] === $r1['assigned_at'], 'bound_at=' . $r1['bound_at'] . ' now=' . $dbNow);
ha_ok('  and the order amount',                                        $r1['order_amount'] === '40.00000000');
ha_ok('  its reservation is cleared',                                  $r1['reservation_token'] === null && $r1['reservation_expires'] === null);

// No chain height, no binding: the clean candidate is set aside, never bound
// without its block boundary.
ha_reset_wallet();
ha_pool(2); ha_pool(3); ha_pool(4);
$GLOBALS['ha_tip_fail'] = true;
list($aTip, $mTip) = ha_allocate(ha_order());
$GLOBALS['ha_tip_fail'] = false;
ha_ok('an unreadable chain height issues no address',                     $aTip === null && $mTip !== '', 'issued=' . var_export($aTip, true));
ha_ok('  the clean candidates are set aside, not bound',                  ha_row(ha_addr(2))['status'] === 'reserved' && ha_row(ha_addr(2))['order_id'] === null && ha_row(ha_addr(2))['validated_height'] === null);
ha_ok('  within the unknown-answer budget',                               (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$GLOBALS['ha_table']}` WHERE `mpk` = %s AND `status` = 'reserved'", HA_XPUB)) === NMMPRO_Hd_Allocator::MAX_UNKNOWN);

// A pending incoming transfer blocks allocation too.
ha_reset_wallet();
ha_pool(2); ha_pool(3);
$GLOBALS['ha_chain'][ha_addr(2)] = 'pending';
list($a2) = ha_allocate(ha_order());
ha_ok('an address with a pending transfer is never issued',            $a2 === ha_addr(3) && ha_row(ha_addr(2))['status'] === 'retired', 'issued=' . var_export($a2, true));

// Unknown evidence: deferred, never recorded clean, and the outage fails the
// checkout within budget instead of walking the wallet.
foreach (array('fail' => 'an explorer failure (HTTP 500)', 'malformed' => 'a malformed answer', 'other' => 'an answer about another address') as $kind => $label) {
	ha_reset_wallet();
	ha_pool(2); ha_pool(3); ha_pool(4); ha_pool(5);
	foreach (array(2, 3, 4, 5) as $i) { $GLOBALS['ha_chain'][ha_addr($i)] = $kind; }
	list($a, $msg) = ha_allocate(ha_order());
	$deferred = ha_row(ha_addr(2));
	ha_ok($label . ': checkout fails without an address',              $a === null && $msg !== '', 'issued=' . var_export($a, true));
	ha_ok('  the candidate is set aside, not clean and not bound',     $deferred['status'] === 'reserved' && $deferred['reservation_token'] === null && $deferred['order_id'] === null && $deferred['validated_at'] === null);
	// Candidates touched, not HTTP requests: after a failure the host's
	// backoff answers further checks without a request, still as unknown.
	$setAside = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$GLOBALS['ha_table']}` WHERE `mpk` = %s AND `status` = 'reserved'", HA_XPUB));
	$untouched = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$GLOBALS['ha_table']}` WHERE `mpk` = %s AND `status` = 'ready'", HA_XPUB));
	ha_ok('  and the outage is not walked through the wallet',         $setAside === NMMPRO_Hd_Allocator::MAX_UNKNOWN && $untouched === 4 - NMMPRO_Hd_Allocator::MAX_UNKNOWN, "set aside=$setAside untouched=$untouched");
}
// A deferred candidate is checked again once its deferral lapses.
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['ha_table']}` SET `reservation_expires` = UNIX_TIMESTAMP() - 1 WHERE `address` = %s", ha_addr(2)));
$GLOBALS['ha_chain'][ha_addr(2)] = 'clean';
list($again) = ha_allocate(ha_order());
ha_ok('a lapsed deferral is re-checked and can then be issued',        $again === ha_addr(2));

// A database failure while reserving: nothing is issued or bound.
ha_reset_wallet();
ha_pool(2);
$failReserve = function ($sql) { return strpos($sql, "SET `status` = 'reserved'") !== false ? 'SELECT * FROM `ha_injected_failure`' : $sql; };
add_filter('query', $failReserve);
$o5 = ha_order();
list($a5) = ha_allocate($o5);
remove_filter('query', $failReserve);
ha_ok('a reservation database failure issues nothing',                 $a5 === null && ha_row(ha_addr(2))['status'] === 'ready' && ha_row(ha_addr(2))['order_id'] === null);

// A binding database failure: the checkout fails, nothing is shown.
ha_reset_wallet();
ha_pool(2);
$failBind = function ($sql) { return strpos($sql, "SET `status` = 'assigned', `assignment_version`") !== false ? 'SELECT * FROM `ha_injected_failure`' : $sql; };
add_filter('query', $failBind);
list($a6) = ha_allocate(ha_order());
remove_filter('query', $failBind);
ha_ok('a binding database failure issues nothing',                     $a6 === null && ha_row(ha_addr(2))['order_id'] === null);

// A pool row that is not what this key derives at that index.
ha_reset_wallet();
$repo = new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0');
$repo->insert_pool(ha_addr(9), 2);   // index 2 claims to hold index 9's address
ha_pool(3);
list($a7) = ha_allocate(ha_order());
ha_ok('a row that does not match the key is never issued',             $a7 === ha_addr(3) && ha_row(ha_addr(9))['status'] === 'retired' && ha_row(ha_addr(9))['review_reason'] === 'derivation_mismatch', 'issued=' . var_export($a7, true));
ha_ok('  and was never even checked on chain',                         !ha_asked_before(ha_addr(9)));

// An empty pool: the checkout derives, checks and binds.
ha_reset_wallet();
list($a8) = ha_allocate(ha_order());
ha_ok('an empty pool derives the next index and issues it',            $a8 === ha_addr(2) && ha_asked_before(ha_addr(2)));

// A legacy pool row (no pool_version) is never a candidate.
ha_reset_wallet();
$repo->insert(ha_addr(2), 2, 'ready');
list($a9) = ha_allocate(ha_order());
ha_ok('a legacy ready row is never issued',                            $a9 === ha_addr(3), 'issued=' . var_export($a9, true));

// =====================================================================
echo "--- 3. non-reuse and retries ---\n";
ha_reset_wallet();
ha_pool(2);
$oA = ha_order();
list($aA) = ha_allocate($oA, '40.00000000');
$bound = ha_row($aA);
sleep(1);
$askedBefore = count($GLOBALS['ha_asked']);
list($aA2) = ha_allocate($oA, '41.50000000');
$rebound = ha_row($aA);
ha_ok('a retried checkout keeps the order\'s own address',             $aA2 === $aA);
ha_ok('  without another explorer request or a new boundary',          count($GLOBALS['ha_asked']) === $askedBefore && $rebound['bound_at'] === $bound['bound_at'] && $rebound['assigned_at'] === $bound['assigned_at']);
ha_ok('  re-priced to the retried amount',                             $rebound['order_amount'] === '41.50000000');
ha_ok('  and still one assignment for the order',                      count(NMMPRO_Hd_Repo::order_assignments($oA)) === 1);

// The order ends; its address is retired and never offered again, even though
// the chain says it is clean.
(new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->retire_address($aA, 'order cancelled');
list($aB) = ha_allocate(ha_order());
ha_ok('an ended order\'s address is never issued again',               $aB !== $aA && $aB !== null && ha_row($aA)['status'] === 'retired', 'issued=' . var_export($aB, true));

// A retried checkout that switches wallet retires the unshown old binding...
ha_reset_wallet();
$repoOther = new NMMPRO_Hd_Repo('DOGE', HA_OTHER_KEY, '0');
$wpdb->query($wpdb->prepare("INSERT INTO `{$GLOBALS['ha_table']}` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assignment_version`,`bound_at`,`assigned_at`)
	VALUES ('ha_other_wallet_addr','DOGE',%s,2,'assigned',0,%d,'1.00000000',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())", HA_OTHER_KEY, $oS = ha_order()));
list($aS) = ha_allocate($oS);
ha_ok('a switched checkout retires the unshown old binding',           $aS === ha_addr(2) && ha_row('ha_other_wallet_addr')['status'] === 'retired' && ha_row('ha_other_wallet_addr')['review_reason'] === 'superseded');
// ...but never one that has been credited.
$wpdb->query($wpdb->prepare("INSERT INTO `{$GLOBALS['ha_table']}` (`address`,`cryptocurrency`,`mpk`,`mpk_index`,`status`,`hd_mode`,`order_id`,`order_amount`,`assignment_version`,`bound_at`,`assigned_at`,`credited_units`)
	VALUES ('ha_other_wallet_paid','DOGE',%s,3,'underpaid',0,%d,'1.00000000',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'5000')", HA_OTHER_KEY, $oP = ha_order()));
list($aP, $mP) = ha_allocate($oP);
ha_ok('an order with a credited assignment gets no new address',       $aP === null && ha_row('ha_other_wallet_paid')['status'] === 'underpaid', $mP);

// A reservation is not a payable assignment.
ha_reset_wallet();
ha_pool(2);
$repo->reserve_candidate('ha_token_visible_x_0000000000000', 60);
$openAddrs = array_column((array) $repo->open_assignments(), 'address');
ha_ok('the verifier and expiry never see a reservation',               ha_row(ha_addr(2))['status'] === 'reserved' && !in_array(ha_addr(2), $openAddrs, true));

// =====================================================================
echo "--- 4. reservation races (second connection = the other checkout) ---\n";
$wpdb2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb2->suppress_errors(true);
$wpdb2->prefix = $wpdb->prefix;
$main = $wpdb;
$asOther = function (callable $fn) use ($wpdb2, $main) {
	$GLOBALS['wpdb'] = $wpdb2;
	try { return $fn(); } finally { $GLOBALS['wpdb'] = $main; }
};

// Two checkouts, one candidate: the other checkout is mid-check on it.
ha_reset_wallet();
ha_pool(2);
$held = $asOther(function () { return (new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->reserve_candidate('ha_token_other_worker_0000000000', 120); });
list($aR) = ha_allocate(ha_order());
ha_ok('fixture: the other checkout holds the only candidate',          is_array($held) && $held['address'] === ha_addr(2));
ha_ok('a concurrent checkout never takes a held candidate',            $aR === ha_addr(3), 'issued=' . var_export($aR, true));
$otherBind = $asOther(function () use ($held) { return (new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->bind_reserved((int) $held['id'], 'ha_token_other_worker_0000000000', 777001, '1.00000000', time(), 1000); });
ha_ok('  and the holder still binds its own',                          $otherBind === NMMPRO_Hd_Repo::BIND_BOUND && (int) ha_row(ha_addr(2))['order_id'] === 777001);

// A stale worker: its reservation expired (it crashed or stalled mid-check)
// and another checkout took the candidate over with a fresh check.
ha_reset_wallet();
ha_pool(2);
$stale = $asOther(function () { return (new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->reserve_candidate('ha_token_stale_worker_0000000000', 120); });
$wpdb->query($wpdb->prepare("UPDATE `{$GLOBALS['ha_table']}` SET `reservation_expires` = UNIX_TIMESTAMP() - 1 WHERE `address` = %s", ha_addr(2)));
$oT = ha_order();
list($aT) = ha_allocate($oT);
ha_ok('an expired reservation is taken over after a fresh check',      $aT === ha_addr(2) && ha_asked_before(ha_addr(2)) && (int) ha_row(ha_addr(2))['order_id'] === $oT);
$staleBind = $asOther(function () use ($stale) { return (new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->bind_reserved((int) $stale['id'], 'ha_token_stale_worker_0000000000', 777002, '1.00000000', time(), 1000); });
$staleRetire = $asOther(function () use ($stale) { return (new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->retire_reserved((int) $stale['id'], 'ha_token_stale_worker_0000000000', 'chain_history'); });
ha_ok('  the stale worker cannot bind it',                             $staleBind === NMMPRO_Hd_Repo::BIND_CONFLICT);
ha_ok('  or retire it',                                                $staleRetire === false && (int) ha_row(ha_addr(2))['order_id'] === $oT && ha_row(ha_addr(2))['status'] === 'assigned');

// A crash after the durable binding, before the order was given its address:
// the retried checkout resumes the same binding.
ha_reset_wallet();
ha_pool(2);
$oC = ha_order();
list($aC1) = ha_allocate($oC);
list($aC2) = ha_allocate($oC);
ha_ok('a checkout that died after binding resumes the same address',   $aC1 === ha_addr(2) && $aC2 === $aC1);

// A concurrent pool fill: the background buffer inserts the next index
// between this checkout's read of the highest index and its own insert.
ha_reset_wallet();
$raced = false;
$race = function ($sql) use (&$raced, $asOther) {
	if (!$raced && strpos($sql, 'SELECT MAX(`mpk_index`)') !== false) {
		$raced = true;
		$asOther(function () { (new NMMPRO_Hd_Repo('DOGE', HA_XPUB, '0'))->insert_pool(ha_addr(2), 2); });
		return 'SELECT NULL';   // this checkout still believes index 2 is free
	}
	return $sql;
};
add_filter('query', $race);
list($aF, $mF) = ha_allocate(ha_order());
remove_filter('query', $race);
ha_ok('a concurrent pool fill is absorbed, not a failed checkout',     $raced && $aF === ha_addr(2), 'issued=' . var_export($aF, true) . ' ' . $mF);
ha_ok('  with the address recorded exactly once',                      (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$GLOBALS['ha_table']}` WHERE `address` = %s", ha_addr(2))) === 1);
$wpdb2->close();

// =====================================================================
echo "--- 5. wallet recovery ---\n";
ha_reset_wallet();
ha_pool(2); ha_pool(3); ha_pool(4);
$GLOBALS['ha_chain'][ha_addr(3)] = 'used';
list($w1) = ha_allocate($ow1 = ha_order());   // index 2
list($w2) = ha_allocate(ha_order());           // 3 is used -> 4
$repo->retire_address($w1, 'expired unpaid');
$nextBefore = $repo->get_next_index();
$summary = $repo->index_summary();
ha_ok('derivation never rewinds past retired addresses',               $nextBefore === 5, 'next=' . $nextBefore);
ha_ok('the diagnostic reports the highest issued and derived index',   $summary['issued'] === 4 && $summary['derived'] === 4, wp_json_encode($summary));
ha_ok('  and the issued addresses retired without payment',            $summary['unused_issued'] === 1, wp_json_encode($summary));

// Repeated chain history on fresh derivations suspends Privacy Mode instead
// of reusing anything.
ha_reset_wallet();
NMMPRO_Hd::clear_allocation_limits();
foreach (range(2, 8) as $i) { $GLOBALS['ha_chain'][ha_addr($i)] = 'used'; }
$cap = function () { return 3; };
add_filter('nmmpro_hd_max_used_skips', $cap);
add_filter('pre_http_request', $GLOBALS['ha_mock'], 10, 3);
$threw = false;
try { NMMPRO_Hd::force_new_address('DOGE', HA_XPUB, '0', true); } catch (\Exception $e) { $threw = true; }
remove_filter('pre_http_request', $GLOBALS['ha_mock'], 10);
remove_filter('nmmpro_hd_max_used_skips', $cap);
$readyAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$GLOBALS['ha_table']}` WHERE `mpk` = %s AND `status` = 'ready'", HA_XPUB));
ha_ok('the used-address cap stops derivation',                         $threw && $readyAfter === 0);
ha_ok('  records each used address as retired',                        (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$GLOBALS['ha_table']}` WHERE `mpk` = %s AND `status` = 'retired'", HA_XPUB)) === 3);
ha_ok('  and suspends the coin until the merchant acknowledges it',    NMMPRO_Hd::allocation_limited('DOGE'));
NMMPRO_Hd::clear_allocation_limits();
ha_ok('re-saving the settings lifts the suspension',                   !NMMPRO_Hd::allocation_limited('DOGE'));

ha_ok('the mock refused no request',                                   count(preg_grep('/^UNMOCKED/', $GLOBALS['ha_asked'])) === 0);

// --- cleanup ---
$wpdb->suppress_errors(false);
ha_reset_wallet();
foreach ($GLOBALS['ha_orders'] as $oid) {
	$o = wc_get_order($oid);
	if ($o) { $o->delete(true); }
}
$wpdb->query("DELETE FROM `{$GLOBALS['ha_table']}` WHERE `order_id` IN (777001, 777002)");

echo $GLOBALS['ha_ok'] ? "\nHD-ALLOCATION CHECKS PASSED\n" : "\nHD-ALLOCATION CHECKS FAILED\n";
