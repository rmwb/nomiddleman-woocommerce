<?php
/**
 * Live-DB test: NMMPRO_Payment::process_address_transactions() driven DIRECTLY
 * with injected NMMPRO_Transaction objects (no network) - the seam the method was
 * split out for. Covers the single-tx matching rules (threshold, tolerance,
 * order-relative window, consumed-tx bookkeeping, collisions) and the
 * split-payment aggregation pass (several transactions summing to the order
 * total).
 *
 * Aggregation is confined to addresses minted for exactly ONE order
 * (NMMPRO_Payment::address_is_per_order() - Monero subaddresses in this table),
 * so every aggregate-dependent section below runs on XMR; the single-tx
 * sections stay on BTC, where they are coin-agnostic. One section asserts the
 * confinement itself, in both directions.
 *
 * Requires WordPress + WooCommerce + a database. Skips cleanly standalone.
 *
 *   Run:  wp eval-file tests/test-payment-matcher.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !function_exists('wc_create_order')) {
	echo "test-payment-matcher: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$pt = $wpdb->prefix . NMMPRO_PAYMENT_TABLE;
NMMPRO_Consumed_Repo::drop();
$wpdb->query("DELETE FROM `$pt`");

// wp eval-file runs this file in function scope: track pass/fail through
// $GLOBALS so the final banner is accurate (see test-autopay-cancel.php).
$GLOBALS['pm_ok'] = true;
function pmok($label, $cond, $extra = '') { printf("%-56s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['pm_ok'] = false; } }

function pm_mkorder() { $o = wc_create_order(); $o->set_payment_method('nmm_gateway'); $o->set_status('pending'); $o->save(); return $o->get_id(); }
function pm_rec($wpdb, $pt, $orderId) { return $wpdb->get_var($wpdb->prepare("SELECT status FROM `$pt` WHERE order_id=%d", $orderId)); }
function pm_hash($wpdb, $pt, $orderId) { return (string) $wpdb->get_var($wpdb->prepare("SELECT tx_hash FROM `$pt` WHERE order_id=%d", $orderId)); }
function pm_paidlike($orderId) { $o = wc_get_order($orderId); return $o && $o->has_status(array('processing', 'completed')); }

$cryptos = NMMPRO_Cryptocurrencies::get();
$btc = $cryptos['BTC'];
$xmr = $cryptos['XMR'];
$stg = new NMMPRO_Settings(get_option(NMMPRO_REDUX_ID));
$amt = '0.00100000';
$units = 0.001 * (10 ** $btc->get_round_precision()); // 100000 smallest units
// Monero carries 12 decimals, not 8 - read the precision rather than assume it.
$xunits = 0.001 * (10 ** $xmr->get_round_precision()); // 1000000000 atomic units
$life = 3600;

$ins = function ($orderId, $address, $orderedAt = null, $orderAmount = null, $coin = 'BTC') use ($wpdb, $pt, $amt) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,%s,'unpaid',%d,%d,%s,0)",
		$address, $coin, $orderedAt === null ? time() : $orderedAt, $orderId, $orderAmount === null ? $amt : $orderAmount));
};

// Consumed-tx state lives in per-address options that OUTLIVE the table wipe
// (the harness DB persists between runs); every address this suite touches is
// cleared up front and again at the end so reruns stay deterministic.
$pmAddrs = array('pm_exact', 'pm_over', 'pm_tolin', 'pm_tolout', 'pm_preord', 'pm_consumed',
	'pm_lock', 'pm_noagg');
$xmrAddrs = array('xmr_split', 'xmr_splitpre', 'xmr_conf', 'xmr_multi', 'xmr_race',
	'xmr_mo', 'xmr_mosub', 'xmr_dberr', 'xmr_agg');
foreach ($pmAddrs as $a) {
	delete_option('nmmpro_BTC_transactions_consumed_for_' . $a);
}
foreach ($xmrAddrs as $a) {
	delete_option('nmmpro_XMR_transactions_consumed_for_' . $a);
}

// NMMPRO_Util::log() de-duplicates an identical entry for 300s through a
// transient. The multi-order collision warning asserted below is a constant
// string (no order ids in it), so a rerun inside that window would observe no
// warning at all. Clear the log throttles up front - and again at the end -
// so consecutive runs behave identically.
$pmThrottleWipe = "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\_transient\_nmm\_log\_%' OR option_name LIKE '\_transient\_timeout\_nmm\_log\_%'";
$wpdb->query($pmThrottleWipe);

// The matching tolerance is a store setting; pin it to the shipped default
// (0.1% shortfall) through the same filter the matcher applies, so a harness
// with customized settings cannot skew the threshold sections. The filter is
// coin-agnostic, so it covers the XMR sections too.
add_filter('nmmpro_autopay_percent', function () { return '0.999'; });

// Required confirmations has no filter - pin it via the settings option (the
// matcher re-reads NMMPRO_REDUX_ID on every call) for BOTH coins, and restore the
// exact original afterwards, since the harness DB persists.
$pmReduxBak = get_option(NMMPRO_REDUX_ID);
$pmRedux = is_array($pmReduxBak) ? $pmReduxBak : array();
$pmRedux['BTC_autopayment_required_confirmations'] = 2;
$pmRedux['XMR_autopayment_required_confirmations'] = 2;
update_option(NMMPRO_REDUX_ID, $pmRedux, false);

// --- single-tx pass: threshold and tolerance --------------------------------
$oExact = pm_mkorder(); $ins($oExact, 'pm_exact');
NMMPRO_Payment::process_address_transactions($btc, 'pm_exact', array(new NMMPRO_Transaction($units, 999, time(), 'PMTX_EXACT')), $life);
pmok('exact single match: record paid',            pm_rec($wpdb, $pt, $oExact) === 'paid');
pmok('  order completed',                          pm_paidlike($oExact));
pmok('  hash consumed',                            $stg->tx_already_consumed('BTC', 'pm_exact', 'PMTX_EXACT') === true);
pmok('  hash stored on the row',                   pm_hash($wpdb, $pt, $oExact) === 'PMTX_EXACT');

$oOver = pm_mkorder(); $ins($oOver, 'pm_over');
NMMPRO_Payment::process_address_transactions($btc, 'pm_over', array(new NMMPRO_Transaction($units * 1.5, 999, time(), 'PMTX_OVER')), $life);
pmok('overpayment matches',                        pm_rec($wpdb, $pt, $oOver) === 'paid');

// 0.05% short: inside the 0.1% tolerance.
$oTolIn = pm_mkorder(); $ins($oTolIn, 'pm_tolin');
NMMPRO_Payment::process_address_transactions($btc, 'pm_tolin', array(new NMMPRO_Transaction($units * 0.9995, 999, time(), 'PMTX_TOLIN')), $life);
pmok('shortfall inside tolerance matches',         pm_rec($wpdb, $pt, $oTolIn) === 'paid');

// 0.2% short: outside the tolerance - and the near-miss tx must stay
// unconsumed so a later top-up can aggregate with it.
$oTolOut = pm_mkorder(); $ins($oTolOut, 'pm_tolout');
NMMPRO_Payment::process_address_transactions($btc, 'pm_tolout', array(new NMMPRO_Transaction($units * 0.998, 999, time(), 'PMTX_TOLOUT')), $life);
pmok('shortfall outside tolerance does NOT match', pm_rec($wpdb, $pt, $oTolOut) === 'unpaid');
pmok('  near-miss tx left unconsumed',             $stg->tx_already_consumed('BTC', 'pm_tolout', 'PMTX_TOLOUT') === false);

// --- order-relative window and consumed bookkeeping -------------------------
// A tx dated 2h before the order (grace is 1h) must not pay it, even with a
// matching window wide enough by age alone.
$oPre = pm_mkorder(); $preOrderedAt = time(); $ins($oPre, 'pm_preord', $preOrderedAt);
NMMPRO_Payment::process_address_transactions($btc, 'pm_preord', array(new NMMPRO_Transaction($units, 999, $preOrderedAt - 2 * 3600, 'PMTX_PREORD')), 6 * 3600);
pmok('pre-order tx rejected',                      pm_rec($wpdb, $pt, $oPre) === 'unpaid');

$oCons = pm_mkorder(); $ins($oCons, 'pm_consumed');
$stg->add_consumed_tx('BTC', 'pm_consumed', 'PMTX_CONSUMED');
NMMPRO_Payment::process_address_transactions($btc, 'pm_consumed', array(new NMMPRO_Transaction($units, 999, time(), 'PMTX_CONSUMED')), $life);
pmok('already-consumed tx skipped',                pm_rec($wpdb, $pt, $oCons) === 'unpaid');

// --- aggregation is confined to per-order addresses --------------------------
// The rule the aggregate pass now enforces, asserted in BOTH directions.
// On a REUSED-address coin (a BTC static address) two transactions that
// together cover the total must NOT complete the order: attribution on a
// reused address is a manual reconciliation, so the funds stay put and both
// hashes stay unconsumed, ready for a human (or a later exact payment).
$oNoAgg = pm_mkorder(); $ins($oNoAgg, 'pm_noagg');
NMMPRO_Payment::process_address_transactions($btc, 'pm_noagg', array(
	new NMMPRO_Transaction($units * 0.6, 999, time(), 'PMTX_NOAGG_A'),
	new NMMPRO_Transaction($units * 0.4, 999, time(), 'PMTX_NOAGG_B'),
), $life);
pmok('reused address: split does NOT complete',    pm_rec($wpdb, $pt, $oNoAgg) === 'unpaid');
pmok('  order not completed',                      !pm_paidlike($oNoAgg));
pmok('  both hashes left unconsumed',              !$stg->tx_already_consumed('BTC', 'pm_noagg', 'PMTX_NOAGG_A') && !$stg->tx_already_consumed('BTC', 'pm_noagg', 'PMTX_NOAGG_B'));

// The identical shape on a per-order address (XMR subaddress) DOES aggregate.
$oAgg = pm_mkorder(); $ins($oAgg, 'xmr_agg', null, null, 'XMR');
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_agg', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_AGG_A'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_AGG_B'),
), $life);
pmok('per-order address: same split completes',    pm_rec($wpdb, $pt, $oAgg) === 'paid');
pmok('  order completed',                          pm_paidlike($oAgg));
pmok('  both hashes consumed',                     $stg->tx_already_consumed('XMR', 'xmr_agg', 'XMRTX_AGG_A') && $stg->tx_already_consumed('XMR', 'xmr_agg', 'XMRTX_AGG_B'));

// --- split payment: two txs summing to the total ----------------------------
$oSplit = pm_mkorder(); $ins($oSplit, 'xmr_split', null, null, 'XMR');
$splitTxs = array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_SPLIT_A'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_SPLIT_B'),
);
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_split', $splitTxs, $life);
pmok('split payment: record paid',                 pm_rec($wpdb, $pt, $oSplit) === 'paid');
pmok('  order completed',                          pm_paidlike($oSplit));
pmok('  BOTH hashes consumed',                     $stg->tx_already_consumed('XMR', 'xmr_split', 'XMRTX_SPLIT_A') && $stg->tx_already_consumed('XMR', 'xmr_split', 'XMRTX_SPLIT_B'));
pmok('  both hashes stored on the row',            pm_hash($wpdb, $pt, $oSplit) === 'XMRTX_SPLIT_A,XMRTX_SPLIT_B');

// The aggregate applies the order-relative lower bound per contributor: a
// pre-order stray must not top up a fresh partial payment.
$oSplitPre = pm_mkorder(); $spOrderedAt = time(); $ins($oSplitPre, 'xmr_splitpre', $spOrderedAt, null, 'XMR');
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_splitpre', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, $spOrderedAt - 2 * 3600, 'XMRTX_SP_OLD'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_SP_NEW'),
), 6 * 3600);
pmok('split with pre-order tx does NOT complete',  pm_rec($wpdb, $pt, $oSplitPre) === 'unpaid');
pmok('  nothing consumed',                         !$stg->tx_already_consumed('XMR', 'xmr_splitpre', 'XMRTX_SP_OLD') && !$stg->tx_already_consumed('XMR', 'xmr_splitpre', 'XMRTX_SP_NEW'));

// --- split payment: confirmation gating -------------------------------------
// One contributor under the required confirmations (pinned to 2): the sum must
// NOT complete this tick and the aggregate must consume nothing, so the same
// transactions complete the order once confirmations arrive.
$oConf = pm_mkorder(); $ins($oConf, 'xmr_conf', null, null, 'XMR');
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_conf', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_CONF_A'),
	new NMMPRO_Transaction($xunits * 0.4, 1, time(), 'XMRTX_CONF_B'), // 1 conf < required 2
), $life);
pmok('under-confirmed split: NOT completed',       pm_rec($wpdb, $pt, $oConf) === 'unpaid');
pmok('  nothing consumed by the aggregate',        !$stg->tx_already_consumed('XMR', 'xmr_conf', 'XMRTX_CONF_A') && !$stg->tx_already_consumed('XMR', 'xmr_conf', 'XMRTX_CONF_B'));
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_conf', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_CONF_A'),
	new NMMPRO_Transaction($xunits * 0.4, 2, time(), 'XMRTX_CONF_B'), // confirmations arrived
), $life);
pmok('completes once confirmations arrive',        pm_rec($wpdb, $pt, $oConf) === 'paid');
pmok('  both hashes then consumed',                $stg->tx_already_consumed('XMR', 'xmr_conf', 'XMRTX_CONF_A') && $stg->tx_already_consumed('XMR', 'xmr_conf', 'XMRTX_CONF_B'));

// --- an address serving two orders never aggregates, whatever the coin -------
// Two rows on one address means the address is reused, so pooling transactions
// towards either total is unattributable. Two guards catch this and either is
// a correct outcome: the row-count check (an address that has EVER carried
// more than one order is reused by definition) fires first, and the defensive
// multi-unpaid-order branch backs it up. Assert the behaviour that matters -
// neither order is paid, nothing is consumed, and the operator is warned -
// rather than which guard got there first.
$oMultiA = pm_mkorder(); $ins($oMultiA, 'xmr_multi', null, null, 'XMR');
$oMultiB = pm_mkorder(); $ins($oMultiB, 'xmr_multi', null, null, 'XMR');
$GLOBALS['pm_warned'] = false;
$pmLogSpy = function ($message, $level, $context, $handler) {
	if ($level === 'warning' && strpos($message, 'split-payment') !== false
		&& (strpos($message, 'unexpectedly has') !== false || strpos($message, 'more than one order') !== false)) { $GLOBALS['pm_warned'] = true; }
	return $message;
};
add_filter('woocommerce_logger_log_message', $pmLogSpy, 10, 4);
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_multi', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_MULTI_A'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_MULTI_B'),
), $life);
remove_filter('woocommerce_logger_log_message', $pmLogSpy, 10);
pmok('multi-order collision: order A not paid',    pm_rec($wpdb, $pt, $oMultiA) === 'unpaid');
pmok('multi-order collision: order B not paid',    pm_rec($wpdb, $pt, $oMultiB) === 'unpaid');
pmok('  collision logged at warning',              $GLOBALS['pm_warned'] === true);
pmok('  txs left unconsumed',                      !$stg->tx_already_consumed('XMR', 'xmr_multi', 'XMRTX_MULTI_A') && !$stg->tx_already_consumed('XMR', 'xmr_multi', 'XMRTX_MULTI_B'));

// --- per-address serialization across connections ----------------------------
// Claiming an order and durably consuming its transactions are two writes, so
// two verifiers on one address can credit a transaction twice. Matching is
// therefore serialized per (currency, address) with a MySQL advisory lock.
// GET_LOCK is owned per CONNECTION, so a second wpdb connection stands in for a
// concurrent worker exactly as it does in test-order-init-lock.
$pmMain = $GLOBALS['wpdb'];
$pmDb2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$pmDb2->suppress_errors(true);
$pmDb2->prefix = $pmMain->prefix; // lock names are site-scoped by prefix

$oLock = pm_mkorder(); $ins($oLock, 'pm_lock');
$lockTx = array(new NMMPRO_Transaction($units, 999, time(), 'PMTX_LOCK'));

$GLOBALS['wpdb'] = $pmDb2;
$pmHeld = NMMPRO_Util::acquire_address_match_lock('BTC', 'pm_lock');
$GLOBALS['wpdb'] = $pmMain;
pmok('address lock: held by the stand-in worker',  $pmHeld === '1', 'got=' . var_export($pmHeld, true));

NMMPRO_Payment::process_address_transactions($btc, 'pm_lock', $lockTx, $life);
pmok('  contended address is skipped this tick',   pm_rec($wpdb, $pt, $oLock) === 'unpaid');
pmok('  and nothing is consumed',                  !$stg->tx_already_consumed('BTC', 'pm_lock', 'PMTX_LOCK'));

$GLOBALS['wpdb'] = $pmDb2;
if ($pmHeld === '1') { NMMPRO_Util::release_address_match_lock('BTC', 'pm_lock'); }
$GLOBALS['wpdb'] = $pmMain;

NMMPRO_Payment::process_address_transactions($btc, 'pm_lock', $lockTx, $life);
pmok('  completes once the address is free',       pm_rec($wpdb, $pt, $oLock) === 'paid');
pmok('  and the hash is consumed',                 $stg->tx_already_consumed('BTC', 'pm_lock', 'PMTX_LOCK'));
$pmDb2->close();

// --- concurrent verifier must never credit one hash to two orders ------------
// A 1-unit and a 2-unit order share an address; transactions T=1 and U=1 exist.
// T matches the 1-unit order exactly, so the single-tx pass claims it. If the
// hash were only consumed AFTER payment_complete(), a verifier running in that
// window (possible when GET_LOCK is unavailable and the cron degrades to
// running unlocked) would see only the 2-unit order as unpaid, pool the still
// unconsumed T with U, and complete it too - 2 units on chain settling 3 units
// of orders. Re-entering the matcher from inside payment_complete() reproduces
// exactly that interleaving.
$oRaceX = pm_mkorder(); $ins($oRaceX, 'xmr_race', null, null, 'XMR');                    // 1 unit
$oRaceY = pm_mkorder(); $ins($oRaceY, 'xmr_race', null, '0.00200000', 'XMR');            // 2 units
$raceTxs = array(
	new NMMPRO_Transaction($xunits, 999, time(), 'XMRTX_RACE_T'),
	new NMMPRO_Transaction($xunits, 999, time(), 'XMRTX_RACE_U'),
);
$GLOBALS['pm_race_reentered'] = false;
$GLOBALS['pm_race_consumed_midflight'] = null;
$raceHook = function ($orderId) use ($xmr, $raceTxs, $life, $oRaceX, $stg) {
	// Only re-enter once, and only for the order the single-tx pass just won.
	if ($orderId != $oRaceX || $GLOBALS['pm_race_reentered']) {
		return;
	}
	$GLOBALS['pm_race_reentered'] = true;
	// Is T reserved at the moment payment_complete() is running?
	$GLOBALS['pm_race_consumed_midflight'] = $stg->tx_already_consumed('XMR', 'xmr_race', 'XMRTX_RACE_T');
	// The concurrent verifier, mid-window.
	NMMPRO_Payment::process_address_transactions($xmr, 'xmr_race', $raceTxs, $life);
};
add_action('woocommerce_payment_complete', $raceHook, 10, 1);
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_race', $raceTxs, $life);
remove_action('woocommerce_payment_complete', $raceHook, 10);

pmok('race: the concurrent verifier actually ran', $GLOBALS['pm_race_reentered'] === true);
pmok('  hash consumed BEFORE payment_complete',    $GLOBALS['pm_race_consumed_midflight'] === true);
pmok('  1-unit order paid',                        pm_rec($wpdb, $pt, $oRaceX) === 'paid');
pmok('  2-unit sibling NOT credited',              pm_rec($wpdb, $pt, $oRaceY) === 'unpaid');
pmok('  sibling order not completed',              !pm_paidlike($oRaceY));
pmok('  U left unconsumed for a legitimate later match',
	!$stg->tx_already_consumed('XMR', 'xmr_race', 'XMRTX_RACE_U'));

// --- multi-output transaction: outputs sum per hash --------------------------
// UTXO adapters emit one NMMPRO_Transaction per matching OUTPUT: one on-chain tx
// paying 0.6 + 0.4 across two outputs shares a single hash. The outputs must
// sum (the single-tx loop compares each output alone and can never match it),
// the >=2 gate counts ENTRIES so this one-hash split completes, and the hash
// is consumed exactly once.
$oMo = pm_mkorder(); $ins($oMo, 'xmr_mo', null, null, 'XMR');
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_mo', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_MO'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_MO'),
), $life);
pmok('multi-output tx: order completed',           pm_rec($wpdb, $pt, $oMo) === 'paid');
$moConsumed = $wpdb->get_var("SELECT COUNT(*) FROM `" . NMMPRO_Consumed_Repo::table() . "` WHERE transaction_hash='XMRTX_MO' AND address='xmr_mo' AND coin='XMR'");
pmok('  hash consumed exactly once',               (int) $moConsumed === 1);
pmok('  single hash stored on the row',            pm_hash($wpdb, $pt, $oMo) === 'XMRTX_MO');

// Two outputs of one tx summing BELOW the threshold: nothing completes and
// nothing is consumed - the partial multi-output tx stays eligible for a
// later aggregate once a top-up arrives.
$oMoSub = pm_mkorder(); $ins($oMoSub, 'xmr_mosub', null, null, 'XMR');
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_mosub', array(
	new NMMPRO_Transaction($xunits * 0.3, 999, time(), 'XMRTX_MOSUB'),
	new NMMPRO_Transaction($xunits * 0.3, 999, time(), 'XMRTX_MOSUB'),
), $life);
pmok('under-total multi-output tx: NOT completed', pm_rec($wpdb, $pt, $oMoSub) === 'unpaid');
pmok('  its hash left unconsumed',                 $stg->tx_already_consumed('XMR', 'xmr_mosub', 'XMRTX_MOSUB') === false);

// --- split payment: DB error on the claim ------------------------------------
// The hook renames the table away right before the claim (the established
// CLAIM_DB_ERROR technique from test-autopay-cancel.php). The row state is then
// unknown: the aggregate must consume NOTHING and touch nothing, so the whole
// split payment is retried on a later tick.
$oDbErr = pm_mkorder(); $ins($oDbErr, 'xmr_dberr', null, null, 'XMR');
$pmErrHook = function ($orderId, $cryptoId, $address, $hash) use ($oDbErr, $pt, $wpdb) {
	if ($orderId == $oDbErr) { $wpdb->suppress_errors(true); $wpdb->query("RENAME TABLE `$pt` TO `{$pt}_bak`"); }
};
add_action('nmmpro_before_autopay_complete', $pmErrHook, 10, 4);
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_dberr', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_ERR_A'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_ERR_B'),
), $life);
remove_action('nmmpro_before_autopay_complete', $pmErrHook, 10);
$wpdb->query("RENAME TABLE `{$pt}_bak` TO `$pt`"); // restore; row is still unpaid
$wpdb->suppress_errors(false);
pmok('split DB error: record still unpaid',        pm_rec($wpdb, $pt, $oDbErr) === 'unpaid');
pmok('  order untouched',                          !pm_paidlike($oDbErr));
pmok('  txs left UNconsumed (retryable)',          !$stg->tx_already_consumed('XMR', 'xmr_dberr', 'XMRTX_ERR_A') && !$stg->tx_already_consumed('XMR', 'xmr_dberr', 'XMRTX_ERR_B'));
// And the retry actually lands once the DB is healthy again.
NMMPRO_Payment::process_address_transactions($xmr, 'xmr_dberr', array(
	new NMMPRO_Transaction($xunits * 0.6, 999, time(), 'XMRTX_ERR_A'),
	new NMMPRO_Transaction($xunits * 0.4, 999, time(), 'XMRTX_ERR_B'),
), $life);
pmok('  next tick completes the split payment',    pm_rec($wpdb, $pt, $oDbErr) === 'paid');

// --- cleanup (the harness DB persists between runs) --------------------------
remove_all_filters('nmmpro_autopay_percent');
if ($pmReduxBak === false) { delete_option(NMMPRO_REDUX_ID); } else { update_option(NMMPRO_REDUX_ID, $pmReduxBak, false); }
foreach ($pmAddrs as $a) {
	delete_option('nmmpro_BTC_transactions_consumed_for_' . $a);
}
foreach ($xmrAddrs as $a) {
	delete_option('nmmpro_XMR_transactions_consumed_for_' . $a);
}
$wpdb->query($pmThrottleWipe);
$wpdb->query("DELETE FROM `$pt`");

echo $GLOBALS['pm_ok'] ? "\nPAYMENT-MATCHER CHECKS PASSED\n" : "\nPAYMENT-MATCHER CHECKS FAILED\n";
