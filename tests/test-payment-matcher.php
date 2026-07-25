<?php
/**
 * Live-DB test: NMM_Payment::process_address_transactions() driven DIRECTLY
 * with injected NMM_Transaction objects (no network) - the seam the method was
 * split out for. Covers the single-tx matching rules (threshold, tolerance,
 * order-relative window, consumed-tx bookkeeping, collisions) and the
 * split-payment aggregation pass (several transactions summing to the order
 * total). Requires WordPress + WooCommerce + a database. Skips cleanly
 * standalone.
 *
 *   Run:  wp eval-file tests/test-payment-matcher.php
 */

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !function_exists('wc_create_order')) {
	echo "test-payment-matcher: skipped (needs WordPress + WooCommerce + DB)\n";
	return;
}

$wpdb = $GLOBALS['wpdb'];
$pt = $wpdb->prefix . NMM_PAYMENT_TABLE;
$wpdb->query("DELETE FROM `$pt`");

// wp eval-file runs this file in function scope: track pass/fail through
// $GLOBALS so the final banner is accurate (see test-autopay-cancel.php).
$GLOBALS['pm_ok'] = true;
function pmok($label, $cond, $extra = '') { printf("%-56s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : ''); if (!$cond) { $GLOBALS['pm_ok'] = false; } }

function pm_mkorder() { $o = wc_create_order(); $o->set_payment_method('nmmpro_gateway'); $o->set_status('pending'); $o->save(); return $o->get_id(); }
function pm_rec($wpdb, $pt, $orderId) { return $wpdb->get_var($wpdb->prepare("SELECT status FROM `$pt` WHERE order_id=%d", $orderId)); }
function pm_hash($wpdb, $pt, $orderId) { return (string) $wpdb->get_var($wpdb->prepare("SELECT tx_hash FROM `$pt` WHERE order_id=%d", $orderId)); }
function pm_paidlike($orderId) { $o = wc_get_order($orderId); return $o && $o->has_status(array('processing', 'completed')); }

$cryptos = NMM_Cryptocurrencies::get();
$btc = $cryptos['BTC'];
$rp = new NMM_Payment_Repo();
$stg = new NMM_Settings(get_option(NMM_REDUX_ID));
$amt = '0.00100000';
$units = 0.001 * (10 ** $btc->get_round_precision()); // 100000 smallest units
$life = 3600;

$ins = function ($orderId, $address, $orderedAt = null, $orderAmount = null) use ($wpdb, $pt, $amt) {
	$wpdb->query($wpdb->prepare(
		"INSERT INTO `$pt` (address,cryptocurrency,status,ordered_at,order_id,order_amount,hd_address) VALUES (%s,'BTC','unpaid',%d,%d,%s,0)",
		$address, $orderedAt === null ? time() : $orderedAt, $orderId, $orderAmount === null ? $amt : $orderAmount));
};

// Consumed-tx and ambiguous-pool state lives in per-address options that
// OUTLIVE the table wipe (the harness DB persists between runs); every address
// this suite touches is cleared up front and again at the end so reruns stay
// deterministic.
$pmAddrs = array('pm_exact', 'pm_over', 'pm_tolin', 'pm_tolout', 'pm_preord', 'pm_consumed',
	'pm_multi', 'pm_split', 'pm_splitpre', 'pm_conf', 'pm_dberr', 'pm_ambig', 'pm_ambsub', 'pm_mo', 'pm_mosub');
foreach ($pmAddrs as $a) {
	delete_option('nmmpro_BTC_transactions_consumed_for_' . $a);
	delete_option('nmmpro_BTC_split_ambiguous_for_' . $a);
}

// The matching tolerance is a store setting; pin it to the shipped default
// (0.1% shortfall) through the same filter the matcher applies, so a harness
// with customized settings cannot skew the threshold sections.
add_filter('nmm_autopay_percent', function () { return '0.999'; });

// Required confirmations has no filter - pin it via the settings option (the
// matcher re-reads NMM_REDUX_ID on every call) and restore the exact original
// afterwards, since the harness DB persists.
$pmReduxBak = get_option(NMM_REDUX_ID);
$pmRedux = is_array($pmReduxBak) ? $pmReduxBak : array();
$pmRedux['BTC_autopayment_required_confirmations'] = 2;
update_option(NMM_REDUX_ID, $pmRedux, false);

// --- single-tx pass: threshold and tolerance --------------------------------
$oExact = pm_mkorder(); $ins($oExact, 'pm_exact');
NMM_Payment::process_address_transactions($btc, 'pm_exact', array(new NMM_Transaction($units, 999, time(), 'PMTX_EXACT')), $life);
pmok('exact single match: record paid',            pm_rec($wpdb, $pt, $oExact) === 'paid');
pmok('  order completed',                          pm_paidlike($oExact));
pmok('  hash consumed',                            $stg->tx_already_consumed('BTC', 'pm_exact', 'PMTX_EXACT') === true);
pmok('  hash stored on the row',                   pm_hash($wpdb, $pt, $oExact) === 'PMTX_EXACT');

$oOver = pm_mkorder(); $ins($oOver, 'pm_over');
NMM_Payment::process_address_transactions($btc, 'pm_over', array(new NMM_Transaction($units * 1.5, 999, time(), 'PMTX_OVER')), $life);
pmok('overpayment matches',                        pm_rec($wpdb, $pt, $oOver) === 'paid');

// 0.05% short: inside the 0.1% tolerance.
$oTolIn = pm_mkorder(); $ins($oTolIn, 'pm_tolin');
NMM_Payment::process_address_transactions($btc, 'pm_tolin', array(new NMM_Transaction($units * 0.9995, 999, time(), 'PMTX_TOLIN')), $life);
pmok('shortfall inside tolerance matches',         pm_rec($wpdb, $pt, $oTolIn) === 'paid');

// 0.2% short: outside the tolerance - and the near-miss tx must stay
// unconsumed so a later top-up can aggregate with it.
$oTolOut = pm_mkorder(); $ins($oTolOut, 'pm_tolout');
NMM_Payment::process_address_transactions($btc, 'pm_tolout', array(new NMM_Transaction($units * 0.998, 999, time(), 'PMTX_TOLOUT')), $life);
pmok('shortfall outside tolerance does NOT match', pm_rec($wpdb, $pt, $oTolOut) === 'unpaid');
pmok('  near-miss tx left unconsumed',             $stg->tx_already_consumed('BTC', 'pm_tolout', 'PMTX_TOLOUT') === false);

// --- order-relative window and consumed bookkeeping -------------------------
// A tx dated 2h before the order (grace is 1h) must not pay it, even with a
// matching window wide enough by age alone.
$oPre = pm_mkorder(); $preOrderedAt = time(); $ins($oPre, 'pm_preord', $preOrderedAt);
NMM_Payment::process_address_transactions($btc, 'pm_preord', array(new NMM_Transaction($units, 999, $preOrderedAt - 2 * 3600, 'PMTX_PREORD')), 6 * 3600);
pmok('pre-order tx rejected',                      pm_rec($wpdb, $pt, $oPre) === 'unpaid');

$oCons = pm_mkorder(); $ins($oCons, 'pm_consumed');
$stg->add_consumed_tx('BTC', 'pm_consumed', 'PMTX_CONSUMED');
NMM_Payment::process_address_transactions($btc, 'pm_consumed', array(new NMM_Transaction($units, 999, time(), 'PMTX_CONSUMED')), $life);
pmok('already-consumed tx skipped',                pm_rec($wpdb, $pt, $oCons) === 'unpaid');

// --- split payment: two txs summing to the total ----------------------------
$oSplit = pm_mkorder(); $ins($oSplit, 'pm_split');
$splitTxs = array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_SPLIT_A'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_SPLIT_B'),
);
NMM_Payment::process_address_transactions($btc, 'pm_split', $splitTxs, $life);
pmok('split payment: record paid',                 pm_rec($wpdb, $pt, $oSplit) === 'paid');
pmok('  order completed',                          pm_paidlike($oSplit));
pmok('  BOTH hashes consumed',                     $stg->tx_already_consumed('BTC', 'pm_split', 'PMTX_SPLIT_A') && $stg->tx_already_consumed('BTC', 'pm_split', 'PMTX_SPLIT_B'));
pmok('  both hashes stored on the row',            pm_hash($wpdb, $pt, $oSplit) === 'PMTX_SPLIT_A,PMTX_SPLIT_B');

// The aggregate applies the order-relative lower bound per contributor: a
// pre-order stray must not top up a fresh partial payment.
$oSplitPre = pm_mkorder(); $spOrderedAt = time(); $ins($oSplitPre, 'pm_splitpre', $spOrderedAt);
NMM_Payment::process_address_transactions($btc, 'pm_splitpre', array(
	new NMM_Transaction($units * 0.6, 999, $spOrderedAt - 2 * 3600, 'PMTX_SP_OLD'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_SP_NEW'),
), 6 * 3600);
pmok('split with pre-order tx does NOT complete',  pm_rec($wpdb, $pt, $oSplitPre) === 'unpaid');
pmok('  nothing consumed',                         !$stg->tx_already_consumed('BTC', 'pm_splitpre', 'PMTX_SP_OLD') && !$stg->tx_already_consumed('BTC', 'pm_splitpre', 'PMTX_SP_NEW'));

// --- split payment: confirmation gating -------------------------------------
// One contributor under the required confirmations (pinned to 2): the sum must
// NOT complete this tick and the aggregate must consume nothing, so the same
// transactions complete the order once confirmations arrive.
$oConf = pm_mkorder(); $ins($oConf, 'pm_conf');
NMM_Payment::process_address_transactions($btc, 'pm_conf', array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_CONF_A'),
	new NMM_Transaction($units * 0.4, 1, time(), 'PMTX_CONF_B'), // 1 conf < required 2
), $life);
pmok('under-confirmed split: NOT completed',       pm_rec($wpdb, $pt, $oConf) === 'unpaid');
pmok('  nothing consumed by the aggregate',        !$stg->tx_already_consumed('BTC', 'pm_conf', 'PMTX_CONF_A') && !$stg->tx_already_consumed('BTC', 'pm_conf', 'PMTX_CONF_B'));
NMM_Payment::process_address_transactions($btc, 'pm_conf', array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_CONF_A'),
	new NMM_Transaction($units * 0.4, 2, time(), 'PMTX_CONF_B'), // confirmations arrived
), $life);
pmok('completes once confirmations arrive',        pm_rec($wpdb, $pt, $oConf) === 'paid');
pmok('  both hashes then consumed',                $stg->tx_already_consumed('BTC', 'pm_conf', 'PMTX_CONF_A') && $stg->tx_already_consumed('BTC', 'pm_conf', 'PMTX_CONF_B'));

// --- multi-order collision: no aggregation, warning --------------------------
// Two unpaid orders share the address (static/carousel reuse) and the combined
// txs would cover either total. Attribution is ambiguous, so the aggregate must
// stand down with a warning and leave the txs unconsumed for a later clean tick.
$oMultiA = pm_mkorder(); $ins($oMultiA, 'pm_multi');
$oMultiB = pm_mkorder(); $ins($oMultiB, 'pm_multi');
$GLOBALS['pm_warned'] = false;
$pmLogSpy = function ($message, $level, $context, $handler) {
	if ($level === 'warning' && strpos($message, 'split-payment collision') !== false) { $GLOBALS['pm_warned'] = true; }
	return $message;
};
add_filter('woocommerce_logger_log_message', $pmLogSpy, 10, 4);
NMM_Payment::process_address_transactions($btc, 'pm_multi', array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_MULTI_A'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_MULTI_B'),
), $life);
remove_filter('woocommerce_logger_log_message', $pmLogSpy, 10);
pmok('multi-order collision: order A not paid',    pm_rec($wpdb, $pt, $oMultiA) === 'unpaid');
pmok('multi-order collision: order B not paid',    pm_rec($wpdb, $pt, $oMultiB) === 'unpaid');
pmok('  collision logged at warning',              $GLOBALS['pm_warned'] === true);
pmok('  txs left unconsumed',                      !$stg->tx_already_consumed('BTC', 'pm_multi', 'PMTX_MULTI_A') && !$stg->tx_already_consumed('BTC', 'pm_multi', 'PMTX_MULTI_B'));

// --- ambiguity survives an expiry: cancellation is not disambiguation --------
// Two orders share the address and the pooled txs would cover either total:
// the pool is flagged. When one order then expires and is cancelled, the
// survivor is the ONLY unpaid row - but the flagged pool must NOT aggregate
// into it, because those funds may have been the cancelled order's payment.
$oAmbA = pm_mkorder(); $ins($oAmbA, 'pm_ambig');
$oAmbB = pm_mkorder(); $ins($oAmbB, 'pm_ambig');
$ambTxs = array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_AMB_A'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_AMB_B'),
);
$GLOBALS['pm_warned'] = false;
add_filter('woocommerce_logger_log_message', $pmLogSpy, 10, 4);
NMM_Payment::process_address_transactions($btc, 'pm_ambig', $ambTxs, $life);
remove_filter('woocommerce_logger_log_message', $pmLogSpy, 10);
$ambPool = get_option('nmmpro_BTC_split_ambiguous_for_pm_ambig', array());
pmok('ambiguous pool: collision warning fired',    $GLOBALS['pm_warned'] === true);
pmok('  implicated hashes flagged (persisted)',    is_array($ambPool) && isset($ambPool['PMTX_AMB_A']) && isset($ambPool['PMTX_AMB_B']));

// Expire order A exactly as the expiry cron would (same conditional claim).
$rp->claim_for_cancellation($oAmbA, $amt);
NMM_Payment::process_address_transactions($btc, 'pm_ambig', $ambTxs, $life);
pmok('after expiry: survivor NOT auto-completed',  pm_rec($wpdb, $pt, $oAmbB) === 'unpaid');
pmok('  flagged txs left unconsumed',              !$stg->tx_already_consumed('BTC', 'pm_ambig', 'PMTX_AMB_A') && !$stg->tx_already_consumed('BTC', 'pm_ambig', 'PMTX_AMB_B'));

// A FRESH split pair (new hashes) on the same address must still aggregate
// into the survivor - only the flagged pool is withheld. The injected list
// carries old and new txs together, as a real chain fetch would. A stale flag
// planted past the window must be pruned by the same pass (self-cleaning).
$ambPool = get_option('nmmpro_BTC_split_ambiguous_for_pm_ambig', array());
$ambPool['PMTX_AMB_STALE'] = time() - 2 * $life;
update_option('nmmpro_BTC_split_ambiguous_for_pm_ambig', $ambPool, false);
NMM_Payment::process_address_transactions($btc, 'pm_ambig', array_merge($ambTxs, array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_AMB_C'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_AMB_D'),
)), $life);
pmok('fresh split pair completes the survivor',    pm_rec($wpdb, $pt, $oAmbB) === 'paid');
pmok('  fresh hashes consumed',                    $stg->tx_already_consumed('BTC', 'pm_ambig', 'PMTX_AMB_C') && $stg->tx_already_consumed('BTC', 'pm_ambig', 'PMTX_AMB_D'));
pmok('  flagged hashes still unconsumed',          !$stg->tx_already_consumed('BTC', 'pm_ambig', 'PMTX_AMB_A') && !$stg->tx_already_consumed('BTC', 'pm_ambig', 'PMTX_AMB_B'));
$ambPool = get_option('nmmpro_BTC_split_ambiguous_for_pm_ambig', array());
pmok('  stale flag entry pruned',                  is_array($ambPool) && !isset($ambPool['PMTX_AMB_STALE']));
pmok('  live flag entries retained',               is_array($ambPool) && isset($ambPool['PMTX_AMB_A']) && isset($ambPool['PMTX_AMB_B']));

// --- sub-threshold partials on a shared address are flagged too --------------
// Partials that cover NEITHER order are just as unattributable as a covering
// pool: they must be flagged the tick they are seen (no warning - nothing is
// actionable yet), so that after one order expires a later top-up cannot pool
// with them into the survivor. Only txs arriving while exactly one order is
// unpaid may ever auto-aggregate.
$oSubA = pm_mkorder(); $ins($oSubA, 'pm_ambsub');
$oSubB = pm_mkorder(); $ins($oSubB, 'pm_ambsub');
$subTxs = array(
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_SUB_A'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_SUB_B'),
);
$GLOBALS['pm_warned'] = false;
add_filter('woocommerce_logger_log_message', $pmLogSpy, 10, 4);
NMM_Payment::process_address_transactions($btc, 'pm_ambsub', $subTxs, $life);
remove_filter('woocommerce_logger_log_message', $pmLogSpy, 10);
$subPool = get_option('nmmpro_BTC_split_ambiguous_for_pm_ambsub', array());
pmok('sub-threshold pool: hashes flagged',         is_array($subPool) && isset($subPool['PMTX_SUB_A']) && isset($subPool['PMTX_SUB_B']));
pmok('  no collision warning (nothing covered)',   $GLOBALS['pm_warned'] === false);

// Expire order A, then a fresh 0.2 top-up arrives: 0.4+0.4+0.2 would sum to
// the survivor's total, but the flagged 0.8 may have been the cancelled
// order's payment - NO aggregation, nothing consumed.
$rp->claim_for_cancellation($oSubA, $amt);
NMM_Payment::process_address_transactions($btc, 'pm_ambsub', array_merge($subTxs, array(
	new NMM_Transaction($units * 0.2, 999, time(), 'PMTX_SUB_TOP'),
)), $life);
pmok('flagged partials + top-up: NOT aggregated',  pm_rec($wpdb, $pt, $oSubB) === 'unpaid');
pmok('  nothing consumed',                         !$stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_A') && !$stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_B') && !$stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_TOP'));

// Two fresh in-window txs (new hashes) summing over the survivor's total still
// aggregate normally - only the flagged pool is withheld. The top-up arrived
// while exactly one order was unpaid, so it may legitimately contribute too.
NMM_Payment::process_address_transactions($btc, 'pm_ambsub', array_merge($subTxs, array(
	new NMM_Transaction($units * 0.2, 999, time(), 'PMTX_SUB_TOP'),
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_SUB_C'),
	new NMM_Transaction($units * 0.5, 999, time(), 'PMTX_SUB_D'),
)), $life);
pmok('fresh txs still complete the survivor',      pm_rec($wpdb, $pt, $oSubB) === 'paid');
pmok('  fresh hashes consumed',                    $stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_C') && $stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_D'));
pmok('  flagged hashes still unconsumed',          !$stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_A') && !$stg->tx_already_consumed('BTC', 'pm_ambsub', 'PMTX_SUB_B'));

// --- multi-output transaction: outputs sum per hash --------------------------
// UTXO adapters emit one NMM_Transaction per matching OUTPUT: one on-chain tx
// paying 0.6 + 0.4 across two outputs shares a single hash. The outputs must
// sum (the single-tx loop compares each output alone and can never match it),
// the >=2 gate counts ENTRIES so this one-hash split completes, and the hash
// is consumed exactly once.
$oMo = pm_mkorder(); $ins($oMo, 'pm_mo');
NMM_Payment::process_address_transactions($btc, 'pm_mo', array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_MO'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_MO'),
), $life);
pmok('multi-output tx: order completed',           pm_rec($wpdb, $pt, $oMo) === 'paid');
$moConsumed = get_option('nmmpro_BTC_transactions_consumed_for_pm_mo', array());
pmok('  hash consumed exactly once',               is_array($moConsumed) && count(array_keys($moConsumed, 'PMTX_MO', true)) === 1);
pmok('  single hash stored on the row',            pm_hash($wpdb, $pt, $oMo) === 'PMTX_MO');

// Two outputs of one tx summing BELOW the threshold: nothing completes and
// nothing is consumed - the partial multi-output tx stays eligible for a
// later aggregate once a top-up arrives.
$oMoSub = pm_mkorder(); $ins($oMoSub, 'pm_mosub');
NMM_Payment::process_address_transactions($btc, 'pm_mosub', array(
	new NMM_Transaction($units * 0.3, 999, time(), 'PMTX_MOSUB'),
	new NMM_Transaction($units * 0.3, 999, time(), 'PMTX_MOSUB'),
), $life);
pmok('under-total multi-output tx: NOT completed', pm_rec($wpdb, $pt, $oMoSub) === 'unpaid');
pmok('  its hash left unconsumed',                 $stg->tx_already_consumed('BTC', 'pm_mosub', 'PMTX_MOSUB') === false);

// --- split payment: DB error on the claim ------------------------------------
// The hook renames the table away right before the claim (the established
// CLAIM_DB_ERROR technique from test-autopay-cancel.php). The row state is then
// unknown: the aggregate must consume NOTHING and touch nothing, so the whole
// split payment is retried on a later tick.
$oDbErr = pm_mkorder(); $ins($oDbErr, 'pm_dberr');
$pmErrHook = function ($orderId, $cryptoId, $address, $hash) use ($oDbErr, $pt, $wpdb) {
	if ($orderId == $oDbErr) { $wpdb->suppress_errors(true); $wpdb->query("RENAME TABLE `$pt` TO `{$pt}_bak`"); }
};
add_action('nmm_before_autopay_complete', $pmErrHook, 10, 4);
NMM_Payment::process_address_transactions($btc, 'pm_dberr', array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_ERR_A'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_ERR_B'),
), $life);
remove_action('nmm_before_autopay_complete', $pmErrHook, 10);
$wpdb->query("RENAME TABLE `{$pt}_bak` TO `$pt`"); // restore; row is still unpaid
$wpdb->suppress_errors(false);
pmok('split DB error: record still unpaid',        pm_rec($wpdb, $pt, $oDbErr) === 'unpaid');
pmok('  order untouched',                          !pm_paidlike($oDbErr));
pmok('  txs left UNconsumed (retryable)',          !$stg->tx_already_consumed('BTC', 'pm_dberr', 'PMTX_ERR_A') && !$stg->tx_already_consumed('BTC', 'pm_dberr', 'PMTX_ERR_B'));
// And the retry actually lands once the DB is healthy again.
NMM_Payment::process_address_transactions($btc, 'pm_dberr', array(
	new NMM_Transaction($units * 0.6, 999, time(), 'PMTX_ERR_A'),
	new NMM_Transaction($units * 0.4, 999, time(), 'PMTX_ERR_B'),
), $life);
pmok('  next tick completes the split payment',    pm_rec($wpdb, $pt, $oDbErr) === 'paid');

// --- cleanup (the harness DB persists between runs) --------------------------
remove_all_filters('nmm_autopay_percent');
if ($pmReduxBak === false) { delete_option(NMM_REDUX_ID); } else { update_option(NMM_REDUX_ID, $pmReduxBak, false); }
foreach ($pmAddrs as $a) {
	delete_option('nmmpro_BTC_transactions_consumed_for_' . $a);
	delete_option('nmmpro_BTC_split_ambiguous_for_' . $a);
}
$wpdb->query("DELETE FROM `$pt`");

echo $GLOBALS['pm_ok'] ? "\nPAYMENT-MATCHER CHECKS PASSED\n" : "\nPAYMENT-MATCHER CHECKS FAILED\n";
