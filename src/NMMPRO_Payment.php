<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMMPRO_Payment {

	// How far before an order's creation time a matching transaction may be dated
	// and still be accepted, absorbing block-timestamp clock skew while rejecting
	// genuinely pre-order transactions on reused addresses.
	const TX_ORDER_SKEW_GRACE_SEC = 3600;

    public static function resume_verified_orders() {
        global $wpdb;
        $table = $wpdb->prefix . NMMPRO_PAYMENT_TABLE;
        $cursor = (int) NMMPRO_Compat::get_option('nmmpro_completion_cursor', 0);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE status='completing' AND id>%d ORDER BY id LIMIT 25", $cursor), ARRAY_A);
        // Advance even when a hook repeatedly fails, so later orders are not starved.
        NMMPRO_Compat::update_option('nmmpro_completion_cursor', count((array) $rows) === 25 ? (int) end($rows)['id'] : 0, false);
        foreach ((array) $rows as $row) {
            $coin = $row['cryptocurrency']; $address = $row['address'];
            if (NMMPRO_Util::acquire_address_match_lock($coin, $address) !== '1') { continue; }
            try {
                $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM `$table` WHERE id=%d", $row['id']));
                if ($status !== 'completing') { continue; }
                $order = wc_get_order($row['order_id']);
                if (!$order || $order->has_status(array('cancelled','failed','refunded','trash'))) {
                    if ($order) { $order->add_order_note(__('A verified cryptocurrency payment requires manual reconciliation.', 'nomiddleman-crypto-payments-for-woocommerce')); }
                    (new NMMPRO_Payment_Repo())->set_status($row['order_id'], $row['order_amount'], 'review');
                    continue;
                }
                if (!$order->is_paid()) {
                    $order->update_meta_data('transaction_hash', $row['tx_hash']);
                    $order->payment_complete();
                }
                if ($order->is_paid()) { (new NMMPRO_Payment_Repo())->set_status($row['order_id'], $row['order_amount'], 'paid'); }
            } catch (\Throwable $e) { NMMPRO_Util::log(__FILE__, __LINE__, 'Verified payment completion will retry: ' . $e->getMessage(), 'error'); }
            finally { NMMPRO_Util::release_address_match_lock($coin, $address); }
        }
    }

	public static function check_all_addresses_for_matching_payment($transactionLifetime) {
		$paymentRepo = new NMMPRO_Payment_Repo();

		// Observe the cron cadence FIRST, on every tick INCLUDING empty ones.
		// The gap since the previous run feeds scan_plan below: budget math uses
		// it clamped (bounding the explorer burst per tick) while the matching
		// window is widened by the REAL cadence, so even an hourly cron cannot
		// let a payment age out between two visits. Recording it only on
		// non-empty ticks would make the first order after an idle stretch read
		// the whole idle period as one "interval" and request an enormous
		// Monero/Solana history window. Written before the scan work so a
		// mid-tick crash degrades to the nominal 60s assumption on the next run
		// instead of compounding its budget.
		$now = time();
		$lastRun = (int) NMMPRO_Compat::get_option('nmmpro_autopay_scan_last_run', 0);
		NMMPRO_Compat::update_option('nmmpro_autopay_scan_last_run', $now, false);
		$cronIntervalSec = ($lastRun > 0 && $now > $lastRun) ? ($now - $lastRun) : 60;

		// Count only (a single scalar) so a large backlog is never loaded into PHP
		// just to size the budget.
		$total = $paymentRepo->count_distinct_unpaid_addresses();
		if ($total < 1) {
			// Nothing unpaid: drop any stale retry keys so they cannot linger,
			// and keep the sweep-start fresh - an empty backlog is a trivially
			// complete sweep, so the first sweep over newly arriving rows must
			// not inherit a start time from before the idle stretch.
			if (NMMPRO_Compat::get_option('nmmpro_autopay_scan_retry', array())) {
				NMMPRO_Compat::update_option('nmmpro_autopay_scan_retry', array(), false);
			}
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_sweep_start', $now, false);
			return;
		}

		$cryptos = NMMPRO_Cryptocurrencies::get();
		$nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

		// The sweep must also finish before an order can be cancelled, or an order
		// inserted just behind the cursor could reach cancel_expired_payments()
		// (which runs the same tick) before its address is ever checked. Find the
		// shortest cancellation window among the coins that actually have unpaid
		// orders and let scan_plan keep the sweep inside it.
		$shortestCancelSec = 0;
		foreach ($paymentRepo->get_distinct_unpaid_cryptos() as $unpaidCryptoId) {
			$winSec = (int) ((float) $nmmSettings->get_autopay_cancellation_time($unpaidCryptoId) * 3600);
			if ($winSec > 0 && ($shortestCancelSec === 0 || $winSec < $shortestCancelSec)) {
				$shortestCancelSec = $winSec;
			}
		}

		// Size the per-tick budget and the effective matching window together (see
		// scan_plan). The baseline budget keeps normal stores gentle on explorers;
		// a large backlog raises it so the sweep stays fast; and the matching window
		// is widened by the sweep period so a payment seen still-unconfirmed on one
		// visit is not rejected as too old before its address next comes round.
		$baseBudget = (int) NMMPRO_Compat::filter('nmmpro_autopay_scan_budget', 50);

		// When the current multi-tick sweep began (the tick that fetched the
		// head of the address list). Initialized here on the very first run;
		// thereafter reset by the wrap handling at the bottom. The coverage
		// stamp uses this START time, never the wrap time - see below.
		$sweepStart = (int) NMMPRO_Compat::get_option('nmmpro_autopay_scan_sweep_start', 0);
		if ($sweepStart < 1) {
			$sweepStart = $now;
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_sweep_start', $sweepStart, false);
		}

		$plan = self::scan_plan($total, $baseBudget, $transactionLifetime, $shortestCancelSec, $cronIntervalSec);
		$take = $plan['take'];
		$effectiveLifetime = $plan['effective_lifetime'];

		// Each coin's matching window must also reach back past ITS oldest
		// unpaid order (plus the pre-order skew grace), or the coverage stamp
		// would be hollow for aged rows: a days-old order met after an upgrade
		// or cron outage may have been paid when it was fresh, and a sweep
		// that only fetched the last few hours would "verify" its address
		// without ever being able to see that payment - expiry would then
		// cancel a paid order. Widening the window also lets such a payment
		// actually MATCH (the per-order TX_ORDER_SKEW_GRACE_SEC lower bound
		// still blocks pre-order transactions on reused addresses, and
		// consumed-tx tracking still blocks replays). The widening is scoped
		// PER CURRENCY: one coin's stale row (say, months-old BTC behind a
		// dead explorer) must not inflate every other coin's history requests
		// - an oversized Monero get_transfers or Solana signature sweep every
		// tick would defeat the bounded-scan goal. Steady-state cost is small
		// (a coin's oldest unpaid row is at most about its cancellation window
		// old); after an outage that coin's window stays wide exactly until
		// its aged backlog has been verified once and settled.
		$lifetimeByCrypto = array();
		foreach ($paymentRepo->oldest_unpaid_ordered_at_by_crypto() as $agedCryptoId => $agedOrderedAt) {
			if ($agedOrderedAt > 0) {
				$atRiskLifetime = ($now - $agedOrderedAt) + self::TX_ORDER_SKEW_GRACE_SEC;
				if ($atRiskLifetime > $effectiveLifetime) {
					$lifetimeByCrypto[$agedCryptoId] = $atRiskLifetime;
				}
			}
		}

		// Keyset pagination around the persisted cursor: fetch only the budgeted
		// slice ordered strictly AFTER the previous tick's stopping point, then
		// wrap to the head if that page ran off the end. Because we ask for rows
		// "greater than" the cursor (not an exact key), a cursor row paid/removed
		// between ticks simply advances to the next one - no restart-at-top that
		// could starve later addresses. With $take <= $total the after/head pages
		// never overlap, so no address is processed twice in a tick.
		$cursor = NMMPRO_Compat::get_option('nmmpro_autopay_scan_cursor', '');
		$cursorParts = ($cursor !== '') ? explode('|', $cursor, 2) : array('', '');
		$cursorCrypto = $cursorParts[0];
		$cursorAddress = isset($cursorParts[1]) ? $cursorParts[1] : '';

		$batch = $paymentRepo->get_unpaid_addresses_after($cursorCrypto, $cursorAddress, $take);
		$wrapped = false;
		if (count($batch) < $take) {
			$head = $paymentRepo->get_unpaid_addresses_from_start($take - count($batch));
			$batch = array_merge($batch, $head);
			$wrapped = true;
		}

		// Re-check addresses whose fetch FAILED last tick BEFORE the fair sweep, so
		// a transient explorer/RPC error is retried on the very next tick rather
		// than waiting a whole sweep (by which time a payment could age out). The
		// retry set is bounded, and only the fair-sweep batch advances the cursor.
		$retrySet = NMMPRO_Compat::get_option('nmmpro_autopay_scan_retry', array());
		if (!is_array($retrySet)) {
			$retrySet = array();
		}

		// Parse retry keys, then keep only those whose payment is STILL unpaid: a
		// row paid/cancelled/deleted while its explorer was down must not be
		// re-queried forever (which would peg the failing endpoint and, at up to
		// the 200-key cap, occupy the cron lock indefinitely).
		$retryPairs = array();
		foreach ($retrySet as $key) {
			$parts = explode('|', $key, 2);
			if (count($parts) === 2) {
				$retryPairs[] = array('cryptocurrency' => $parts[0], 'address' => $parts[1]);
			}
		}
		$liveRetry = $paymentRepo->filter_unpaid_pairs($retryPairs);

		$toProcess = array();
		$seen = array();
		foreach ($retryPairs as $pair) {
			$key = $pair['cryptocurrency'] . '|' . $pair['address'];
			if (isset($liveRetry[$key]) && !isset($seen[$key])) {
				$toProcess[] = $pair;
				$seen[$key] = true;
			}
		}

		// Priority lane: a fresh customer is watching the thank-you page's
		// 15-second poller, so their first check must not wait for the fair
		// sweep to come around (up to the full sweep period under a backlog).
		// Scan recently created payment records every tick, ADDITIVE to the
		// sweep budget - carving the lane out of the sweep budget would slow
		// the sweep and re-open the sweep-within-lifetime invariant - and
		// bounded by the baseline so the worst-case extra explorer load per
		// tick is one baseline's worth. The lane never advances the cursor: it
		// is not part of the fair sweep, and an address in both the lane and
		// the sweep page is scanned only once ($seen).
		$priorityWindow = (int) NMMPRO_Compat::filter('nmmpro_autopay_priority_window', 30 * MINUTE_IN_SECONDS);
		if ($priorityWindow > 0) {
			foreach ($paymentRepo->get_recent_unpaid_addresses($priorityWindow, $baseBudget) as $record) {
				$key = self::scan_key($record);
				if (!isset($seen[$key])) {
					$toProcess[] = $record;
					$seen[$key] = true;
				}
			}
		}

		$lastKey = $cursor;
		foreach ($batch as $record) {
			$key = self::scan_key($record);
			$lastKey = $key; // the cursor tracks the fair sweep only, never retries
			if (!isset($seen[$key])) {
				$toProcess[] = $record;
				$seen[$key] = true;
			}
		}

		// For Monero, fetch the account's incoming transfers ONCE per tick and
		// group them by subaddress locally, instead of two wallet-RPC calls
		// (get_address_index + get_transfers) for every address.
		$xmrFetched = false;
		$xmrOk = false;
		$xmrByAddress = array();

		$newFailed = array();
		$coinDirty = array();       // whole-coin exclusions (unverifiable ticker)
		$incompleteKeys = array();  // per-ADDRESS exclusions ("crypto|address")

		foreach ($toProcess as $record) {
			$cryptoId = $record['cryptocurrency'];
			$address = $record['address'];

			if (!isset($cryptos[$cryptoId])) {
				// A ticker no longer in the registry (coin support removed)
				// cannot be verified at all - it must not be certified covered,
				// or expiry would cancel its orders without any payment check.
				// Coin-level dirty (not failed): there is no point re-fetching
				// it every tick, and the next sweep re-marks it for as long as
				// its unpaid rows exist, so they are never auto-cancelled.
				$coinDirty[$cryptoId] = true;
				continue;
			}
			$crypto = $cryptos[$cryptoId];

			// This coin's window, widened past its own oldest unpaid order.
			$cryptoLifetime = isset($lifetimeByCrypto[$cryptoId]) ? $lifetimeByCrypto[$cryptoId] : $effectiveLifetime;

			NMMPRO_Compat::action('nmmpro_autopay_address_checked', $cryptoId, $address);

			if ($cryptoId === 'XMR') {
				if (!$xmrFetched) {
					$xmrBatch = NMMPRO_Monero::get_account_transactions($cryptoLifetime);
					$xmrOk = (isset($xmrBatch['result']) && $xmrBatch['result'] === 'success');
					$xmrByAddress = ($xmrOk && isset($xmrBatch['by_address'])) ? $xmrBatch['by_address'] : array();
					$xmrFetched = true;
				}
				if (!$xmrOk) {
					$newFailed[] = self::scan_key($record); // could not fetch; retry next tick
					continue;
				}
				$xmrTxs = isset($xmrByAddress[$address]) ? $xmrByAddress[$address] : array();
				if (self::process_address_transactions($crypto, $address, $xmrTxs, $cryptoLifetime) === false) {
					// Another verifier holds this subaddress; we did not examine
					// it, so it must not be certified as covered.
					$newFailed[] = self::scan_key($record);
					continue;
				}
			}
			else {
				$fetched = self::check_address_transactions_for_matching_payments($crypto, $address, $cryptoLifetime);
				if ($fetched === false) {
					$newFailed[] = self::scan_key($record);
				}
				elseif ($cryptoId === 'SOL' && !NMMPRO_Blockchain::sol_address_fully_swept($address)) {
					// The bounded Solana sweep made durable progress but has
					// not yet inspected this address's whole matching window
					// (a busy or dusted address spans several ticks). Not a
					// failure - collected payments were returned and progress
					// is durable - but not verification either: THIS ADDRESS
					// must not be certified while signatures below its
					// internal cursor, or in its retry queue, are uninspected.
					// Address-level, not coin-level: one busy address must not
					// freeze expiry for the whole currency.
					$incompleteKeys[self::scan_key($record)] = true;
				}
				elseif ($cryptoId !== 'SOL' && self::page_possibly_truncated($cryptoId, $fetched, $cryptoLifetime, $now)) {
					// A full newest-page (raw, pre-filtering) whose oldest
					// entry is still inside the matching window: in-window
					// history may be hidden below the fixed-depth page (see
					// page_possibly_truncated) - a valid check, but not
					// certifiable coverage for THIS ADDRESS. Address-level,
					// not coin-level: a permanently busy (or deliberately
					// dusted) address would otherwise mark the coin dirty on
					// every sweep and no order of that currency would ever
					// auto-cancel.
					$incompleteKeys[self::scan_key($record)] = true;
				}
			}
		}

		// Persist the bounded retry set and the fair-sweep cursor. If the cap
		// forces failed keys to be DROPPED (a wide outage: accumulated retries
		// plus a full sweep page can exceed it), their addresses were passed by
		// the cursor without ever being verified and will not be retried - so
		// mark their currencies dirty. A dirty currency is excluded from the
		// coverage stamp at the next wrap (then cleared: the following sweep
		// revisits every address, so a clean wrap after that is trustworthy
		// again). Without this, an endpoint recovering after drops would let a
		// later clean wrap certify coverage for addresses that were never
		// successfully checked, and an aged paid order could be cancelled.
		$retryCap = max(1, (int) NMMPRO_Compat::filter('nmmpro_autopay_scan_retry_cap', 200));
		$newFailed = array_values(array_unique($newFailed));
		if (count($newFailed) > $retryCap) {
			// Dropped keys will not be retried, so their addresses stay
			// unverified until the next sweep visits them - exclude exactly
			// those addresses (not their whole coins) from certification.
			foreach (array_slice($newFailed, $retryCap) as $droppedKey) {
				$incompleteKeys[$droppedKey] = true;
			}
			$newFailed = array_slice($newFailed, 0, $retryCap);
		}

		// Accumulate this tick's per-address incompleteness (truncated pages,
		// mid-window Solana sweeps, dropped retries) into the BUILDER set for
		// the sweep in progress; the wrap below promotes it to the active
		// exclusion set that cancel_expired_payments() consults. Bounded: past
		// the cap we can no longer track addresses individually, so the
		// overflow's coins fall back to the coarse coin-level dirty marker.
		if (!empty($incompleteKeys)) {
			$builder = NMMPRO_Compat::get_option('nmmpro_autopay_scan_incomplete_next', array());
			if (!is_array($builder)) {
				$builder = array();
			}
			foreach (array_keys($incompleteKeys) as $incompleteKey) {
				if (count($builder) >= 500 && !isset($builder[$incompleteKey])) {
					$incompleteParts = explode('|', $incompleteKey, 2);
					$coinDirty[$incompleteParts[0]] = true;
					continue;
				}
				$builder[$incompleteKey] = true;
			}
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_incomplete_next', $builder, false);
		}

		if (!empty($coinDirty)) {
			$dirty = NMMPRO_Compat::get_option('nmmpro_autopay_scan_dirty', array());
			if (!is_array($dirty)) {
				$dirty = array();
			}
			foreach (array_keys($coinDirty) as $dirtyCryptoId) {
				$dirty[$dirtyCryptoId] = true;
			}
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_dirty', $dirty, false);
		}
		NMMPRO_Compat::update_option('nmmpro_autopay_scan_retry', $newFailed, false);
		NMMPRO_Compat::update_option('nmmpro_autopay_scan_cursor', $lastKey, false);

		// A full sweep has just completed: either this tick's page wrapped past
		// the end of the address list, or the budget covered the whole backlog
		// in one page. Stamp the coverage time - cancel_expired_payments() only
		// treats a row as expired once its whole window lies behind this stamp,
		// so a row that pre-dates the cursor (plugin upgrade with an aged
		// backlog) or that aged out during a long cron outage is always checked
		// at least once after expiring before it can be cancelled.
		//
		// The stamp is the completed sweep's START time, never the wrap time:
		// the keyset sweep proves only that every row present throughout
		// [start, wrap] was visited at some point in that interval, so the
		// earliest such visit is all "checked after expiry" may assume. Using
		// the wrap time would let a row checked early in the sweep - whose
		// window closed before the wrap - be cancelled although a payment
		// arriving after its check was never seen. This also covers the
		// stale-cursor wrap (backlog churn leaving the cursor beyond every
		// row): the head page alone completes a "sweep" whose start is old, so
		// rows in the unscanned tail that expired after that start remain
		// protected until a genuinely complete sweep finishes. A single page
		// covering the whole backlog stamps this tick's own start.
		//
		// Coverage is stamped PER CURRENCY, with incompleteness tracked PER
		// ADDRESS. The stamp's claim is "every address of this coin was
		// checked, with nothing relevant possibly hidden, after the row's
		// window closed - EXCEPT the addresses in the active exclusion set,
		// whose rows defer". The exclusion set (promoted from the builder at
		// each wrap, plus the keys still failing in the retry set) carries the
		// addresses the sweep could not conclusively verify: fetch failures,
		// Solana mid-window sweeps, possibly-truncated fixed-depth pages, and
		// dropped retries. Address-level granularity matters in both
		// directions: one permanently failing endpoint or one busy/dusted
		// address must only hold back ITS OWN rows' expirations, never freeze
		// cancellation for a whole currency - and conversely an excluded
		// address's rows never expire until a sweep verifies it cleanly (the
		// next wrap then simply drops it from the set). Only a ticker missing
		// from the registry (nothing of that coin can ever be verified) still
		// blocks its coin's stamp via the coin-level dirty marker.
		if ($wrapped || $take >= $total) {
			// Coin-level exclusions: unverifiable tickers and address-tracking
			// overflow. Cleared after use - the sweep now starting re-marks
			// them for as long as their rows exist.
			$excludedCryptos = array();
			$dirty = NMMPRO_Compat::get_option('nmmpro_autopay_scan_dirty', array());
			if (is_array($dirty) && !empty($dirty)) {
				foreach (array_keys($dirty) as $dirtyCryptoId) {
					$excludedCryptos[$dirtyCryptoId] = true;
				}
				NMMPRO_Compat::update_option('nmmpro_autopay_scan_dirty', array(), false);
			}

			$stampAt = ($take >= $total) ? $now : $sweepStart;
			$coveredMap = NMMPRO_Compat::get_option('nmmpro_autopay_scan_covered_at', array());
			if (!is_array($coveredMap)) {
				$coveredMap = array();
			}
			foreach ($paymentRepo->get_distinct_unpaid_cryptos() as $sweptCryptoId) {
				if (!isset($excludedCryptos[$sweptCryptoId])) {
					$coveredMap[$sweptCryptoId] = $stampAt;
				}
			}
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_covered_at', $coveredMap, false);

			// Promote the completed sweep's incomplete addresses to the ACTIVE
			// exclusion set cancel_expired_payments() consults, adding the
			// keys still failing in the retry set (they were visited but never
			// verified). The builder resets: the sweep now starting revisits
			// every address, and a still-incomplete one re-enters the builder.
			$promoted = NMMPRO_Compat::get_option('nmmpro_autopay_scan_incomplete_next', array());
			if (!is_array($promoted)) {
				$promoted = array();
			}
			foreach ($newFailed as $failedKey) {
				$promoted[$failedKey] = true;
			}
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_incomplete', $promoted, false);
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_incomplete_next', array(), false);

			// The next sweep begins with the head rows this tick just fetched.
			NMMPRO_Compat::update_option('nmmpro_autopay_scan_sweep_start', $now, false);
		}
	}

	/**
	 * Pure planner for one sweep tick. Given the distinct-unpaid backlog size, the
	 * merchant baseline budget and the base matching lifetime, returns how many
	 * addresses to check this tick ('take'/'budget') and the effective matching
	 * lifetime to check them against ('effective_lifetime').
	 *
	 * A larger backlog raises the budget so a full sweep still completes within
	 * about half the base lifetime (2x margin for late cron runs)
	 * AND within half the shortest cancellation window, so an order can never be
	 * cancelled before its address is checked at least once. Spreading the sweep
	 * across ticks means an address is only revisited every sweep period, so a
	 * payment still below its confirmation count on one visit could age past the
	 * base lifetime before the next visit. Widening the matching window by the
	 * actual sweep period closes that gap: any transaction that confirms within
	 * the base lifetime is still matched on the next visit, regardless of the
	 * (coin/config-dependent) confirmation delay. For a normal store the sweep is
	 * ~1 tick, so the window is only nudged by a minute.
	 *
	 * $shortestCancelSec is the shortest order-cancellation window among coins with
	 * unpaid orders; pass 0 when unknown to fall back to the lifetime bound alone.
	 * $cronIntervalSec is the OBSERVED gap between cron runs, unclamped. The
	 * budget math clamps it to [60s, 600s] - the floor keeps back-to-back manual
	 * runs from shrinking the budget, the ceiling caps the explorer burst any one
	 * tick can be asked to absorb - but the wall-clock sweep period (and so the
	 * widened matching window) uses the REAL cadence: with an hourly cron the
	 * burst cap means the sweep genuinely takes longer, and the matching window
	 * must reflect the real revisit gap or a payment seen still-unconfirmed on
	 * one visit would age out before its address next comes round.
	 */
	public static function scan_plan($total, $baseBudget, $transactionLifetime, $shortestCancelSec = 0, $cronIntervalSec = 60) {
		$total = max(0, (int) $total);
		$baseBudget = max(1, (int) $baseBudget);
		$transactionLifetime = max(0, (int) $transactionLifetime);
		$shortestCancelSec = max(0, (int) $shortestCancelSec);
		$cronIntervalSec = max(1, (int) $cronIntervalSec);
		$plannedIntervalSec = min(600, max(60, $cronIntervalSec));

		// Target a full sweep within half the base lifetime, tightened to half the
		// shortest cancellation window when that is smaller.
		$targetSweepSec = max($plannedIntervalSec, (int) ($transactionLifetime / 2));
		if ($shortestCancelSec > 0) {
			$targetSweepSec = min($targetSweepSec, max($plannedIntervalSec, (int) ($shortestCancelSec / 2)));
		}

		$sweepTicks = max(1, (int) floor($targetSweepSec / $plannedIntervalSec));
		$budget = max($baseBudget, (int) ceil($total / $sweepTicks));
		$take = min($budget, $total);

		// Actual ticks to sweep the whole backlog at this budget - 1 tick for a
		// small store, more for a large one - converted to wall-clock seconds at
		// the REAL observed cadence, not the clamped planning interval.
		$sweepPeriodSec = ($budget > 0 ? (int) ceil($total / $budget) : 1) * $cronIntervalSec;

		return array(
			'budget' => $budget,
			'take' => $take,
			'effective_lifetime' => $transactionLifetime + $sweepPeriodSec,
		);
	}

	// Stable identity of a distinct-unpaid-address row, for the sweep cursor.
	private static function scan_key($record) {
		return $record['cryptocurrency'] . '|' . $record['address'];
	}

	// Fetches and matches the address's transactions. Returns
	// array('transactions' => NMMPRO_Transaction[], 'page' => rawPageMeta|null)
	// on success - so the sweep can inspect the served page for possible
	// truncation before certifying coverage - or false if the fetch failed,
	// so the sweep can retry a transient failure on the next tick instead of
	// leaving it until the whole backlog is swept again.
	private static function check_address_transactions_for_matching_payments($crypto, $address, $transactionLifetime) {
		$cryptoId = $crypto->get_id();

		NMMPRO_Util::log(__FILE__, __LINE__, '===========================================================================');
		NMMPRO_Util::log(__FILE__, __LINE__, 'Starting payment verification for: ' . $cryptoId . ' - ' . $address);

		// Clear any stale raw-page note so the meta read after this fetch can
		// only belong to this fetch.
		NMMPRO_Blockchain::take_raw_page_meta();

		try {
			$transactions = self::get_address_transactions($cryptoId, $address, $transactionLifetime);
		}
		catch (\Throwable $e) {
			// \Throwable, not \Exception: adapter parsing of a malformed
			// HTTP-200 body can raise TypeError/Error on PHP 8, and an
			// uncaught one would abort the whole sweep BEFORE the cursor and
			// retry state persist - the same bad address would then terminate
			// every cron run. A throw here is just a failed fetch: retry it.
			NMMPRO_Util::log(__FILE__, __LINE__, 'Unable to get transactions for ' . $cryptoId . ': ' . $e->getMessage(), 'warning');
			return false;
		}

		$pageMeta = NMMPRO_Blockchain::take_raw_page_meta();

		NMMPRO_Util::log(__FILE__, __LINE__, 'Transactions found for ' . $cryptoId . ': ' . count((array) $transactions));

		if (self::process_address_transactions($crypto, $address, $transactions, $transactionLifetime) === false) {
			// Another verifier holds this address; we did not examine it. Treat
			// it exactly like a failed fetch so it is retried and never
			// certified as covered.
			return false;
		}

		return array(
			'transactions' => is_array($transactions) ? $transactions : array(),
			'page' => $pageMeta,
		);
	}

	/**
	 * Whether one address visit "may be truncated within the matching window":
	 * the fixed-depth explorer adapters return only the newest page of an
	 * address's history, so when a page comes back FULL and even its oldest
	 * entry is still inside the matching window, in-window transactions may
	 * exist below the page, unseen. Such a visit is a valid payment check (it
	 * sees exactly what the matcher can ever see) but it must NOT certify
	 * coverage for cancellation: under the bounded sweep an address is
	 * revisited less often than every tick, so a payment that was visible when
	 * it arrived can be buried under a burst of later activity (e.g. dusting a
	 * reused carousel address) before the cursor comes back - master's
	 * every-tick scan would have matched it first. A page whose oldest entry
	 * is OLDER than the window proves the whole window is visible; a short
	 * page proves there is nothing below.
	 *
	 * Fullness is judged on the RAW page the explorer served (reported by the
	 * adapter via NMMPRO_Blockchain::note_raw_page BEFORE its incoming-only
	 * filtering), never on the filtered result: a 25-entry page of 24
	 * outgoing transfers and one payment filters down to a single entry, but
	 * an older in-window payment may still be hidden below the full raw page.
	 * When an adapter reports no raw metadata (not yet instrumented), the
	 * filtered result is used as a floor - it can only under-detect, so
	 * instrumenting an adapter strictly tightens the check.
	 */
	public static function page_possibly_truncated($cryptoId, $fetchResult, $transactionLifetime, $now) {
		$cap = NMMPRO_Blockchain::adapter_page_cap($cryptoId);
		if ($cap < 1) {
			return false;
		}

		$pageMeta = isset($fetchResult['page']) ? $fetchResult['page'] : null;
		$transactions = isset($fetchResult['transactions']) ? $fetchResult['transactions'] : array();

		if (is_array($pageMeta)) {
			$count = (int) $pageMeta[0];
			$oldest = $pageMeta[1];
		}
		else {
			$count = is_array($transactions) ? count($transactions) : 0;
			$oldest = null;
			foreach ($transactions as $transaction) {
				$ts = (int) $transaction->get_time_stamp();
				if ($oldest === null || $ts < $oldest) {
					$oldest = $ts;
				}
			}
		}

		if ($count < $cap) {
			return false;
		}

		// A full page whose oldest entry cannot be shown to pre-date the
		// matching window may hide in-window history below it. An unknown
		// oldest timestamp on a full page counts as truncated - reach past
		// the window cannot be proven. The comparison is INCLUSIVE at the
		// cutoff: the matcher accepts a transaction dated exactly at the
		// boundary (only strictly older ones are rejected), and several
		// transactions can share one block timestamp - an oldest entry
		// sitting exactly on the cutoff proves nothing about eligible
		// same-second history below the page.
		return ($oldest === null) || ((int) $oldest >= ($now - (int) $transactionLifetime));
	}

	/**
	 * Match already-fetched transactions for one address against its unpaid
	 * orders, then claim/complete or reconcile. Split out from the network fetch
	 * so the matching, race-claim and consumed-tx logic can be exercised directly
	 * in tests with injected NMMPRO_Transaction objects (no external calls).
	 *
	 * @param NMMPRO_Cryptocurrency $crypto
	 * @param string             $address
	 * @param NMMPRO_Transaction[]  $transactions
	 * @param int                $transactionLifetime
	 */
	public static function process_address_transactions($crypto, $address, $transactions, $transactionLifetime) {
		$paymentRepo = new NMMPRO_Payment_Repo();
		$nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

		$cryptoId = $crypto->get_id();

		// Serialize matching per address. Claiming an order and durably
		// recording the transactions that paid it are two separate writes, so
		// between them the order has left the unpaid set while its
		// transactions still look available. A second verifier working the
		// same address in that window can credit one transaction to a sibling
		// order - two units on chain settling three units of orders. The cron
		// normally guarantees one verifier per site, but it degrades to
		// running unlocked when GET_LOCK is unavailable (see NMMPRO_Cron), which
		// is exactly when this matters. Serializing per address also removes
		// the lost-update risk in the read-modify-write consumed-tx option,
		// since every writer for an address is now single-file.
		$matchLock = NMMPRO_Util::acquire_address_match_lock($cryptoId, $address);

		if ($matchLock !== '1') {
			// Another worker is mid-flight on this exact address. Nothing to
			// wait for; the sweep revisits it next tick. Returning false marks
			// the visit INCOMPLETE: certifying an address we never examined
			// would let the coverage stamp advance past it, and expiry could
			// then cancel an order whose payment we simply never looked at.
			NMMPRO_Util::log(__FILE__, __LINE__, 'Address match lock busy for ' . $cryptoId . ' ' . $address . '; another verifier is processing it. Skipping this tick.');
			return false;
		}

		try {

		foreach ($transactions as $transaction) {
			$txHash = $transaction->get_hash();
			$transactionAmount = NMMPRO_Amount::to_units($transaction->get_amount(), 0);

			$requiredConfirmations = $nmmSettings->get_autopay_required_confirmations($cryptoId);
			$txConfirmations = $transaction->get_confirmations();

			NMMPRO_Util::log(__FILE__, __LINE__, '---confirmations: ' . $txConfirmations . ' Required: ' . $requiredConfirmations);
			if ($txConfirmations < $requiredConfirmations) {
				continue;
			}

			$txTimeStamp = $transaction->get_time_stamp();
			$timeSinceTx = time() - $txTimeStamp;

			NMMPRO_Util::log(__FILE__, __LINE__, '---time since transaction: ' . $timeSinceTx . ' TX Lifetime: ' . $transactionLifetime);
			if ($timeSinceTx > $transactionLifetime) {
				continue;
			}

			if ($nmmSettings->tx_already_consumed($cryptoId, $address, $txHash)) {
				// Ordinary: we have already processed this tx. Expected, not a warning.
				NMMPRO_Util::log(__FILE__, __LINE__, 'Already-consumed transaction skipped: ' . $txHash);
				continue;
			}

			$paymentRecords = $paymentRepo->get_unpaid_for_address($cryptoId, $address);

			$matchingPaymentRecords = [];

			foreach ($paymentRecords as $record) {
				// A transaction cannot pay an order that did not exist when it was
				// made. On a reused static/carousel address an old, unconsumed
				// transaction could otherwise complete a newly created order of the
				// same amount - a risk the widened sweep window (which accepts ages
				// beyond the base lifetime) would enlarge. Require the tx to be no
				// older than the order, less a grace for block-timestamp clock skew.
				$orderedAt = isset($record['ordered_at']) ? (int) $record['ordered_at'] : 0;
				if ($orderedAt > 0 && $txTimeStamp < $orderedAt - self::TX_ORDER_SKEW_GRACE_SEC) {
					continue;
				}

				$paymentAmount = $record['order_amount'];
				$paymentAmountSmallestUnit = NMMPRO_Amount::to_units($paymentAmount, $crypto->get_round_precision());

				$autoPaymentPercent = NMMPRO_Compat::filter('nmmpro_autopay_percent', $nmmSettings->get_autopay_processing_percent($cryptoId), $paymentAmount, $cryptoId, $address);

				// Guard against a zero (or unparseable) expected amount so we
				// never divide by zero, and treat any overpayment as a match:
				// the shortfall tolerance only applies to UNDER-payment.
				if ($paymentAmountSmallestUnit <= 0) {
					continue;
				}

				if (NMMPRO_Amount::clears($transactionAmount, $paymentAmountSmallestUnit, $autoPaymentPercent)) {
					$matchingPaymentRecords[] = $record;
				}

				NMMPRO_Util::log(__FILE__, __LINE__, '---CryptoId, paymentAmount, paymentAmountSmallestUnit, transactionAmount:' . $cryptoId . ',' . $paymentAmount .',' . $paymentAmountSmallestUnit . ',' .  $transactionAmount);
			}

			// Transaction does not match any order payment
			if (count($matchingPaymentRecords) == 0) {
				// Do nothing
			}
			if (count($matchingPaymentRecords) > 1) {
				// We have a collision, send admin note to each order
				$collidingOrderIds = array();
				foreach ($matchingPaymentRecords as $matchingRecord) {
					$orderId = $matchingRecord['order_id'];
					$collidingOrderIds[] = $orderId;
					$order = wc_get_order($orderId);
					if (!$order) {
						continue;
					}
					/* translators: 1: cryptocurrency ticker, 2: transaction hash */
					$order->add_order_note(sprintf(__('This order has a matching %1$s transaction but we cannot verify it due to other orders with similar payment totals. Please reconcile manually. Transaction Hash: %2$s', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoId, $txHash));
				}

				// A genuine payment collision needs a human: surface it as a
				// warning naming the affected orders and the tx that could not
				// be auto-assigned. (Ordinary already-consumed skips stay debug.)
				NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay collision: ' . $cryptoId . ' transaction ' . $txHash . ' matches multiple unpaid orders (' . implode(', ', $collidingOrderIds) . '); left for manual reconciliation.', 'warning');

				$nmmSettings->add_consumed_tx($cryptoId, $address, $txHash);
			}
			if (count($matchingPaymentRecords) == 1) {
				// We have validated a transaction: update database to paid, update order to processing, add transaction to consumed transactions
				$orderId = $matchingPaymentRecords[0]['order_id'];
				$orderAmount = $matchingPaymentRecords[0]['order_amount'];

				// Hook fired immediately before the claim, in the exact window the
				// conditional claim below is designed to close. Integrations - and
				// the concurrency test - can observe or, in a race, complete/cancel
				// the order here.
				NMMPRO_Compat::action('nmmpro_before_autopay_complete', $orderId, $cryptoId, $address, $txHash);

				// Atomically claim the row for payment. The expiry cron races us
				// with the opposite claim (unpaid -> cancelled); because both sides
				// go through the same conditional update, exactly one wins. The
				// claim is tri-state so we never confuse a genuine race loss with a
				// transient DB error.
				$claim = NMMPRO_Consumed_Repo::claim($paymentRepo, $cryptoId, $address, $orderId, $orderAmount, array($txHash));

				if ($claim === NMMPRO_Payment_Repo::CLAIM_DB_ERROR) {
					// The UPDATE failed, so the row state is unknown - it may well
					// still be unpaid. Do NOT consume the tx (that would permanently
					// ignore a valid payment) and do NOT complete the order; leave
					// everything untouched so a later tick retries this transaction.
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: database error claiming ' . $cryptoId . ' order ' . $orderId . ' for payment; leaving the transaction unconsumed for retry. Transaction Hash: ' . $txHash, 'error');
					continue;
				}

				if ($claim === NMMPRO_Payment_Repo::CLAIM_ALREADY) {
					// The row was conclusively transitioned out of 'unpaid' by
					// another worker (expiry cron cancelled it, or another verifier
					// paid it). Do NOT complete the order. But DO consume the tx:
					// this address (a static address or a carousel seat) will be
					// reused, and an unconsumed in-window tx could otherwise be
					// matched against a *new* order of the same amount and
					// misattribute the payment. Persist the hash on the cancelled
					// row too, for manual reconciliation.
					$nmmSettings->add_consumed_tx($cryptoId, $address, $txHash);
					$paymentRepo->set_hash_on_cancelled($orderId, $orderAmount, $txHash);
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: verified ' . $cryptoId . ' payment for order ' . $orderId . ' but its record was already transitioned (likely expired and cancelled) - not completing the order; recorded the transaction as consumed to prevent reuse on a recycled address. Transaction Hash: ' . $txHash . '. Please reconcile manually.', 'warning');
					continue;
				}

				// CLAIM_CLAIMED: we won the row - complete the order.

				// Consume the hash NOW, before payment_complete(). The claim has
				// already taken this order out of the unpaid set, so a verifier
				// running concurrently (possible when GET_LOCK is unavailable and
				// the cron degrades to running unlocked, see NMMPRO_Cron) would see
				// only the remaining sibling on this shared address. If the hash
				// were still unconsumed it could be pooled into that sibling's
				// aggregate and credited twice - one 1 BTC transaction settling
				// both a 1 BTC and a 2 BTC order. payment_complete() fires order
				// hooks, emails and third-party integrations, so leaving the
				// window open across it is a real exposure, not a theoretical one.
				$nmmSettings->add_consumed_tx($cryptoId, $address, $txHash);

				$paymentRepo->set_hash($orderId, $orderAmount, $txHash);

				$order = wc_get_order($orderId);
				if (!$order) {
					// Row is claimed 'paid' (so it stops matching), but the order is
					// gone - nothing to complete. The tx is already consumed above.
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: verified ' . $cryptoId . ' payment but order ' . $orderId . ' no longer exists. Transaction Hash: ' . $txHash, 'warning');
					continue;
				}
				$orderNote = sprintf(
						/* translators: 1: amount, 2: cryptocurrency ticker, 3: date/time, 4: transaction hash */
						__('Order payment of %1$s %2$s verified at %3$s. Transaction Hash: %4$s', 'nomiddleman-crypto-payments-for-woocommerce'),
						NMMPRO_Cryptocurrencies::get_price_string($crypto->get_id(), NMMPRO_Amount::from_units($transactionAmount, $crypto->get_round_precision())),
						$cryptoId,
						wp_date('Y-m-d H:i:s'),
						NMMPRO_Compat::filter('nmmpro_order_txhash', $txHash, $cryptoId));

                if ($order->has_status(array('cancelled', 'failed', 'refunded', 'trash'))) {
                    $paymentRepo->set_status($orderId, $orderAmount, 'review');
                    $order->add_order_note(__('A verified cryptocurrency payment requires manual reconciliation.', 'nomiddleman-crypto-payments-for-woocommerce'));
                    continue;
                }
				$order->update_meta_data('transaction_hash', $txHash);
				$order->payment_complete();
				$order->add_order_note($orderNote);
                if ($order->is_paid()) { $paymentRepo->set_status($orderId, $orderAmount, 'paid'); }
			}
		}

		// Second pass: a customer who pays in SEVERAL transactions (exchange
		// withdrawal limits, wallet UTXO splitting, topping up after a fee
		// miscalculation) sends no single tx that clears the order amount, so
		// the per-transaction loop above matches nothing - the funds land
		// on-chain but the order would sit unpaid until expiry cancelled it.
		// Privacy Mode already credits the cumulative total_received; this pass
		// gives Autopay the same semantics. It runs AFTER the single-tx loop on
		// purpose: an order completed above is no longer unpaid, and a hash
		// consumed above no longer contributes to any sum.
		self::aggregate_split_payment($crypto, $address, $transactions, $transactionLifetime, $paymentRepo, $nmmSettings);

		return true;

		}
		finally {
			// Release only a lock we actually acquired ('1'); on '0' we returned
			// above without holding it, and on null we never had one. The lock is
			// also released automatically if this process dies, so a crash mid-
			// match cannot wedge the address.
			NMMPRO_Util::release_address_match_lock($cryptoId, $address);
		}
	}

	/**
	 * The transactions among $transactions that may contribute to paying
	 * $record: sufficiently confirmed, inside the matching window, not already
	 * consumed, not flagged as part of an ambiguous multi-order pool,
	 * positive-amount, and - critically - no older than the order itself (the
	 * same TX_ORDER_SKEW_GRACE_SEC lower bound the single-tx pass applies, so
	 * on a reused static/carousel address an old stray transaction can never
	 * help pay a NEWER order). Consumed state is re-read here because the
	 * single-tx pass may have consumed hashes earlier in this same tick.
	 *
	 * Several UTXO adapters emit one NMMPRO_Transaction per matching OUTPUT, so a
	 * single on-chain transaction paying the address across two outputs shows
	 * up as two entries sharing one hash. Their amounts are SUMMED - all
	 * outputs pay the order - while the hash appears once in 'hashes' so it is
	 * consumed exactly once. Eligibility is judged per entry; outputs of one
	 * transaction share its confirmations and timestamp, so they always agree.
	 * 'entries' counts eligible NMMPRO_Transaction OBJECTS (not distinct hashes)
	 * for the caller's split gate.
	 *
	 * @return array ['sum' => float (smallest units), 'hashes' => string[],
	 *                'entries' => int]
	 */
	private static function split_payment_contributions($record, $transactions, $transactionLifetime, $cryptoId, $address, $nmmSettings, $requiredConfirmations, $now) {
		$sum = '0';
		$entries = 0;
		$hashTs = array();
		$orderedAt = isset($record['ordered_at']) ? (int) $record['ordered_at'] : 0;


		foreach ($transactions as $transaction) {
			$txHash = $transaction->get_hash();
			$txTimeStamp = $transaction->get_time_stamp();
			if (($now - $txTimeStamp) > $transactionLifetime) {
				continue;
			}
			if ($orderedAt > 0 && $txTimeStamp < $orderedAt - self::TX_ORDER_SKEW_GRACE_SEC) {
				continue;
			}
			$transactionAmount = NMMPRO_Amount::to_units($transaction->get_amount(), 0);
			if ($transactionAmount <= 0) {
				// Adds nothing to the sum; consuming its hash would only lose
				// information. The single-tx pass never matches it either.
				continue;
			}
			if ($nmmSettings->tx_already_consumed($cryptoId, $address, $txHash)) {
				continue;
			}

			if ($transaction->get_confirmations() < $requiredConfirmations) {
				// Not spendable-certain yet: it may contribute on a later tick,
				// but it must never help clear an order now.
				continue;
			}

			$sum = NMMPRO_Amount::add($sum, $transactionAmount);
			$entries++;
			if (!isset($hashTs[$txHash])) {
				$hashTs[$txHash] = $txTimeStamp;
			}
		}

		return array('sum' => $sum, 'hashes' => array_keys($hashTs), 'entries' => $entries);
	}

	/**
	 * Whether this address belongs to exactly ONE order, which is what makes
	 * split-payment aggregation safe.
	 *
	 * Aggregation pools several transactions towards one order total. On an
	 * address that serves several orders - a static address, or a carousel
	 * seat handed out again - that pooling cannot be attributed safely:
	 * whether two orders overlap in time or merely follow one another on the
	 * same address, there is no way to tell whose partial payment is whose.
	 * Timestamps cannot decide it either, because a block header time comes
	 * from the miner (constrained only against the previous blocks median),
	 * not from the store clock. So aggregation is confined to addresses that
	 * are minted per order and never re-issued.
	 *
	 * In the Autopay payments table that means Monero: NMMPRO_Gateway mints a
	 * fresh subaddress per order for XMR, while static and carousel addresses
	 * are reused by design. Privacy Mode (HD) never reaches this code - it
	 * verifies through NMMPRO_Hd against a cumulative balance, which already
	 * credits split payments correctly, and retires any address that receives
	 * funds instead of recycling it.
	 *
	 * Merchants on a reused address are not worse off than before this
	 * release: their split payments simply remain a manual reconciliation, as
	 * they have always been. Crediting them automatically needs a durable
	 * transaction-to-order binding (the 2.11.0 consumed-tx table), not a
	 * timestamp heuristic.
	 */
	/**
	 * Can Autopay actually verify a payment to this address?
	 *
	 * Used as a brake on auto-cancellation, not on allocation. Returns true
	 * when we cannot tell (unknown coin, validator unavailable): this gates an
	 * irreversible action, so an indeterminate answer must not silently stop
	 * ordinary expiry from working.
	 */
	private static function address_verifiable_for_expiry($cryptoId, $address) {
		if (!class_exists('NMMPRO_Address')) {
			return true;
		}

		// Only a WELL-FORMED address that the explorer still cannot report on
		// earns the reprieve - the Zcash shielded/Unified case, where the
		// customer can pay successfully and the merchant really does receive
		// the funds, so cancelling would kill a paid order.
		//
		// A MALFORMED address does not qualify and expires as it always has.
		// It is unverifiable for a different reason: no wallet will send to a
		// failed checksum, so there is no payment to protect - and treating
		// those as unverifiable too would quietly stop expiry for every order
		// a store issued under the older, looser address rules, leaving unpaid
		// orders to accumulate forever.
		if (!NMMPRO_Cryptocurrencies::is_valid_wallet_address($cryptoId, $address)) {
			return true;
		}

		return NMMPRO_Address::is_autopay_verifiable_form($cryptoId, $address);
	}

	private static function address_is_per_order($cryptoId) {
		return $cryptoId === "XMR";
	}

	/**
	 * Whether $sumSmallestUnit clears $record's total under the SAME tolerance
	 * the single-tx pass applies: any overpayment matches; the configured
	 * shortfall tolerance (default 0.1%) applies to under-payment only; and a
	 * zero/unparseable expected amount never matches (and never divides).
	 */
	private static function split_payment_sum_clears($record, $sumSmallestUnit, $crypto, $cryptoId, $address, $nmmSettings) {
		$paymentAmount = $record['order_amount'];
		$paymentAmountSmallestUnit = NMMPRO_Amount::to_units($paymentAmount, $crypto->get_round_precision());

		if ($paymentAmountSmallestUnit <= 0) {
			return false;
		}

		$autoPaymentPercent = NMMPRO_Compat::filter('nmmpro_autopay_percent', $nmmSettings->get_autopay_processing_percent($cryptoId), $paymentAmount, $cryptoId, $address);
		return NMMPRO_Amount::clears($sumSmallestUnit, $paymentAmountSmallestUnit, $autoPaymentPercent);
	}

	/**
	 * Aggregate (split-payment) matching for one address, run after the
	 * single-tx pass of process_address_transactions. When the sum of the
	 * eligible unconsumed transactions clears the order total, the order is
	 * completed through the same claim/complete sequence the single-tx path
	 * uses and ALL contributing hashes are consumed together.
	 */
	private static function aggregate_split_payment($crypto, $address, $transactions, $transactionLifetime, $paymentRepo, $nmmSettings) {
		$cryptoId = $crypto->get_id();

		// Only ever aggregate on an address minted for a single order. See
		// address_is_per_order(): pooling transactions towards one total is
		// unattributable the moment an address can serve more than one order,
		// and no timestamp comparison can rescue it.
		if (!self::address_is_per_order($cryptoId)) {
			return;
		}

		// Belt and braces for the rule above. address_is_per_order() infers the
		// property from the coin, but the fact that makes it true lives in
		// NMMPRO_Gateway, in another class: if anyone ever adds a fallback there
		// ("wallet RPC down, use the static address"), aggregation would
		// silently become unsafe with no test failing. This asks the data
		// instead - an address that has EVER carried more than one order,
		// whatever their statuses, is by definition reused.
		$rowsForAddress = $paymentRepo->count_rows_for_address($cryptoId, $address);
		if ($rowsForAddress === null || $rowsForAddress > 1) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay split-payment: ' . $cryptoId . ' address ' . $address . ($rowsForAddress === null ? ' could not be confirmed as per-order (count query failed)' : ' has served more than one order, so it is not per-order after all') . '; not aggregating.', 'warning');
			return;
		}

		// Re-read the unpaid rows AFTER the single-tx pass ran: an order it
		// completed (or a collision it consumed) must not be double-processed.
		$paymentRecords = $paymentRepo->get_unpaid_for_address($cryptoId, $address);
		if (count($paymentRecords) == 0) {
			return;
		}

		$requiredConfirmations = $nmmSettings->get_autopay_required_confirmations($cryptoId);
		$now = time();

		// Defensive: a per-order address should never carry two unpaid orders
		// (a Monero subaddress is minted for one order and never re-issued).
		// If one somehow does, attribution is ambiguous - surface it for a
		// human and leave every transaction unconsumed for a later clean tick.
		if (count($paymentRecords) > 1) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay split-payment: ' . $cryptoId . ' address ' . $address . ' unexpectedly has ' . count($paymentRecords) . ' unpaid orders; not aggregating - please reconcile manually.', 'warning');
			return;
		}

		$record = $paymentRecords[0];
		$contrib = self::split_payment_contributions($record, $transactions, $transactionLifetime, $cryptoId, $address, $nmmSettings, $requiredConfirmations, $now);
		$contributingHashes = $contrib['hashes'];

		// The split gate counts transaction ENTRIES, not distinct hashes:
		// several UTXO adapters emit one NMMPRO_Transaction per matching output,
		// and a single transaction paying the order across two outputs is
		// exactly the split-funds case this pass exists for - the single-tx
		// loop compares each output individually and can never match it. A
		// lone single-output entry stays gated out: it is the single-tx
		// pass's case (it either matched above, or fails the identical
		// threshold here), and gating it keeps a claim that pass left for
		// retry (CLAIM_DB_ERROR) from being re-attempted within the same tick.
		if ($contrib['entries'] < 2) {
			return;
		}

		if (!self::split_payment_sum_clears($record, $contrib['sum'], $crypto, $cryptoId, $address, $nmmSettings)) {
			NMMPRO_Util::log(__FILE__, __LINE__, '---split-payment sum below threshold: ' . $cryptoId . ',' . $address . ',' . $contrib['sum']);
			return;
		}

		$orderId = $record['order_id'];
		$orderAmount = $record['order_amount'];

		// One combined string where a single hash would go. The tx_hash column
		// is char(255), so a long list (3+ Solana signatures) is truncated for
		// STORAGE only - the order note below always carries the full list, so
		// nothing a human needs for reconciliation is lost.
		$hashList = implode(',', $contributingHashes);
		$storedHashList = (strlen($hashList) > 255) ? substr($hashList, 0, 255) : $hashList;

		// Same pre-claim hook, same claim, same tri-state handling as the
		// single-tx path - see the comments there. Integrations receive the
		// combined comma-separated hash list where a single hash would go.
		NMMPRO_Compat::action('nmmpro_before_autopay_complete', $orderId, $cryptoId, $address, $hashList);

		$claim = NMMPRO_Consumed_Repo::claim($paymentRepo, $cryptoId, $address, $orderId, $orderAmount, $contributingHashes);

		if ($claim === NMMPRO_Payment_Repo::CLAIM_DB_ERROR) {
			// Row state unknown - consume NOTHING and touch nothing, so every
			// contributing transaction is still eligible when a later tick
			// retries the whole aggregate.
			NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay split-payment: database error claiming ' . $cryptoId . ' order ' . $orderId . ' for payment; leaving all transactions unconsumed for retry. Transaction Hashes: ' . $hashList, 'error');
			return;
		}

		if ($claim === NMMPRO_Payment_Repo::CLAIM_ALREADY) {
			// Conclusively transitioned elsewhere (expired and cancelled, or
			// paid by another worker). Do NOT complete the order - but DO
			// consume EVERY contributing tx and persist the hashes on the
			// cancelled row: the address will be reused, and any unconsumed
			// in-window transaction could otherwise be matched (singly or in a
			// new aggregate) against a NEW order of the same amount and
			// misattribute the payment.
			foreach ($contributingHashes as $consumedHash) {
				$nmmSettings->add_consumed_tx($cryptoId, $address, $consumedHash);
			}
			$paymentRepo->set_hash_on_cancelled($orderId, $orderAmount, $storedHashList);
			NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay split-payment: verified combined ' . $cryptoId . ' payment for order ' . $orderId . ' but its record was already transitioned (likely expired and cancelled) - not completing the order; recorded all transactions as consumed to prevent reuse on a recycled address. Transaction Hashes: ' . $hashList . '. Please reconcile manually.', 'warning');
			return;
		}

		// CLAIM_CLAIMED: we won the row - complete the order exactly as the
		// single-tx path does.

		// Consume every contributor NOW, before payment_complete(), for the same
		// reason the single-tx path does: the claim has already removed this
		// order from the unpaid set, so any hash still unconsumed could be
		// pooled into a sibling order by a verifier running concurrently.
		foreach ($contributingHashes as $consumedHash) {
			$nmmSettings->add_consumed_tx($cryptoId, $address, $consumedHash);
		}

		$paymentRepo->set_hash($orderId, $orderAmount, $storedHashList);

		$order = wc_get_order($orderId);
		if (!$order) {
			// Row is claimed 'paid' (so it stops matching), but the order is
			// gone - nothing to complete. The txs are already consumed above.
			NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay split-payment: verified combined ' . $cryptoId . ' payment but order ' . $orderId . ' no longer exists. Transaction Hashes: ' . $hashList, 'warning');
			return;
		}

		$displayHashes = array();
		foreach ($contributingHashes as $noteHash) {
			$displayHashes[] = NMMPRO_Compat::filter('nmmpro_order_txhash', $noteHash, $cryptoId);
		}
		$orderNote = sprintf(
				/* translators: 1: amount, 2: cryptocurrency ticker, 3: number of transactions, 4: date/time, 5: transaction hashes */
				__('Order payment of %1$s %2$s verified across %3$d transactions at %4$s. Transaction Hashes: %5$s', 'nomiddleman-crypto-payments-for-woocommerce'),
				NMMPRO_Cryptocurrencies::get_price_string($crypto->get_id(), NMMPRO_Amount::from_units($contrib['sum'], $crypto->get_round_precision())),
				$cryptoId,
				count($contributingHashes),
				wp_date('Y-m-d H:i:s'),
				implode(', ', $displayHashes));

        if ($order->has_status(array('cancelled', 'failed', 'refunded', 'trash'))) {
            $paymentRepo->set_status($orderId, $orderAmount, 'review');
            $order->add_order_note(__('A verified cryptocurrency payment requires manual reconciliation.', 'nomiddleman-crypto-payments-for-woocommerce'));
            return;
        }
		$order->update_meta_data('transaction_hash', $storedHashList);
		$order->payment_complete();
		$order->add_order_note($orderNote);
                if ($order->is_paid()) { $paymentRepo->set_status($orderId, $orderAmount, 'paid'); }
	}

	private static function get_address_transactions($cryptoId, $address, $transactionLifetime = null) {
		if ($cryptoId === 'ETH') {
			$result = NMMPRO_Blockchain::get_eth_address_transactions($address);
		}
		if ($cryptoId === 'BCH') {
			$result = NMMPRO_Blockchain::get_bch_address_transactions($address);
		}
		if ($cryptoId === 'DOGE') {
			$result = NMMPRO_Blockchain::get_doge_address_transactions($address);
		}
		if ($cryptoId === 'ZEC') {
			$result = NMMPRO_Blockchain::get_zec_address_transactions($address);
		}
		if ($cryptoId === 'DASH') {
			$result = NMMPRO_Blockchain::get_dash_address_transactions($address);
		}
		if ($cryptoId === 'XRP') {
			$result = NMMPRO_Blockchain::get_xrp_address_transactions($address);
		}
		if ($cryptoId === 'ETC') {
			$result = NMMPRO_Blockchain::get_etc_address_transactions($address);
		}
		if ($cryptoId === 'XLM') {
			$result = NMMPRO_Blockchain::get_xlm_address_transactions($address);
		}
		if ($cryptoId === 'BSV') {
			$result = NMMPRO_Blockchain::get_bsv_address_transactions($address);
		}
		if ($cryptoId === 'EOS') {
			$result = NMMPRO_Blockchain::get_eos_address_transactions($address);
		}
		if ($cryptoId === 'TRX') {
			$result = NMMPRO_Blockchain::get_trx_address_transactions($address);
		}
		if ($cryptoId === 'BLK') {
			$result = NMMPRO_Blockchain::get_blk_address_transactions($address);
		}
		if ($cryptoId === 'ADA') {
			$result = NMMPRO_Blockchain::get_ada_address_transactions($address);
		}
		if ($cryptoId === 'XTZ') {
			$result = NMMPRO_Blockchain::get_xtz_address_transactions($address);
		}
		if ($cryptoId === 'REP') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('REP', $address);
		}
		if ($cryptoId === 'MLN') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('MLN', $address);
		}
		if ($cryptoId === 'GNO') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('GNO', $address);
		}
		if ($cryptoId === 'LTC') {
			$result = NMMPRO_Blockchain::get_ltc_address_transactions($address);
		}
		if ($cryptoId === 'BTC') {
			$result = NMMPRO_Blockchain::get_btc_address_transactions($address);
		}
		if ($cryptoId === 'BAT') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('BAT', $address);
		}
		if ($cryptoId === 'BNB') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('BNB', $address);
		}
		if ($cryptoId === 'HOT') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('HOT', $address);
		}
		if ($cryptoId === 'LINK') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('LINK', $address);
		}
		if ($cryptoId === 'OMG') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('OMG', $address);
		}
		if ($cryptoId === 'ZRX') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('ZRX', $address);
		}
		if ($cryptoId === 'GUSD') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('GUSD', $address);
		}
		if ($cryptoId === 'WAVES') {
			$result = NMMPRO_Blockchain::get_waves_address_transactions($address);
		}
		if ($cryptoId === 'DCR') {
			$result = NMMPRO_Blockchain::get_dcr_address_transactions($address);
		}
		if ($cryptoId === 'GRS') {
			$result = NMMPRO_Blockchain::get_grs_address_transactions($address);
		}
        if ($cryptoId === 'DGB') {
            $result = NMMPRO_Blockchain::get_dgb_address_transactions($address);
        }
        if ($cryptoId === 'USDC') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('USDC', $address);
		}
		if ($cryptoId === 'USDT') {
			$result = NMMPRO_Blockchain::get_erc20_address_transactions('USDT', $address);
		}
		if ($cryptoId === 'USDTTRX') {
			$result = NMMPRO_Blockchain::get_trc20_usdt_address_transactions($address);
		}
		if ($cryptoId === 'SOL') {
			$result = NMMPRO_Blockchain::get_sol_address_transactions($address, $transactionLifetime);
		}

		if ($cryptoId === 'XMR') {
			$result = NMMPRO_Monero::get_address_transactions($address);
		}
		// any registered ERC-20 token without an explicit branch above
		if (!isset($result)) {
			$cryptos = NMMPRO_Cryptocurrencies::get();
			if (isset($cryptos[$cryptoId]) && $cryptos[$cryptoId]->is_erc20_token()) {
				$result = NMMPRO_Blockchain::get_erc20_address_transactions($cryptoId, $address);
			}
			else {
				$result = array('result' => 'error', 'message' => 'No verification available');
			}
		}

		if ($result['result'] === 'error') {
			NMMPRO_Util::log(__FILE__, __LINE__, 'BAD API CALL');
			throw new \Exception(esc_html__('Could not reach external service to do auto payment processing.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}

		return $result['transactions'];
	}

	public static function cancel_expired_payments() {
		global $woocommerce;
		$nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

		$paymentRepo = new NMMPRO_Payment_Repo();

		// Single real-time clock for the whole pass. Each row's effective
		// expiry clock is additionally capped at ITS currency's last
		// full-sweep coverage stamp: a row only counts as expired once the
		// bounded sweep has checked its address at least once AFTER its window
		// closed. Otherwise rows that pre-date the scan cursor (plugin upgrade
		// with an aged unpaid backlog) or that aged out during a long cron
		// outage would be cancelled without ever being verified, and an
		// on-chain-paid order could be cancelled. The stamp is per currency so
		// one dead endpoint only pauses its own coin's expirations.
		// Cancellation can therefore lag by up to one sweep period (<= half
		// the shortest window) - late, never wrong. min() with real time keeps
		// a fresh stamp from ever loosening the real-time expiry check.
		$nowReal = time();
		$coveredMap = NMMPRO_Compat::get_option('nmmpro_autopay_scan_covered_at', array());
		if (!is_array($coveredMap)) {
			$coveredMap = array(); // unknown format: treat as no coverage (defer, never cancel unverified)
		}

		// Addresses the last completed sweep could not conclusively verify
		// (failing endpoint, possibly-truncated page, mid-window Solana sweep,
		// dropped retry). Their rows defer - only theirs; the coin's other
		// orders expire normally against the coin's stamp.
		$incompleteAddrs = NMMPRO_Compat::get_option('nmmpro_autopay_scan_incomplete', array());
		if (!is_array($incompleteAddrs)) {
			$incompleteAddrs = array();
		}

		// Only rows old enough to be expirable for SOME configured coin can
		// possibly be cancelled. The shortest cancellation window among the coins
		// that actually have unpaid rows is a safe coarse cutoff: anything newer
		// than it is within every window and cannot be expired. Filtering on it in
		// SQL lets the unpaid_expiry(status, ordered_at) index do a range scan
		// instead of returning every unpaid checkout for PHP to iterate.
		$unpaidCryptos = $paymentRepo->get_distinct_unpaid_cryptos();
		if (empty($unpaidCryptos)) {
			return;
		}

		$shortestWindowSec = null;
		$latestCoveredAt = 0;
		foreach ($unpaidCryptos as $unpaidCryptoId) {
			$windowSec = (float) $nmmSettings->get_autopay_cancellation_time($unpaidCryptoId) * 60 * 60;
			if ($shortestWindowSec === null || $windowSec < $shortestWindowSec) {
				$shortestWindowSec = $windowSec;
			}
			if (isset($coveredMap[$unpaidCryptoId]) && (int) $coveredMap[$unpaidCryptoId] > $latestCoveredAt) {
				$latestCoveredAt = (int) $coveredMap[$unpaidCryptoId];
			}
		}

		// The coarse cutoff must not exclude any row the per-record check below
		// could cancel, so it pairs the shortest window with the LATEST per-coin
		// coverage stamp; per-record filtering then applies each row's own
		// window and its own coin's stamp. With no coverage at all the cutoff
		// goes negative and nothing is fetched.
		$coarseCutoff = min($nowReal, $latestCoveredAt) - $shortestWindowSec;
		$unpaidPayments = $paymentRepo->get_unpaid($coarseCutoff);

		foreach ($unpaidPayments as $paymentRecord) {
			$orderTime = $paymentRecord['ordered_at'];
			$cryptoId = $paymentRecord['cryptocurrency'];

			$cryptoCoveredAt = isset($coveredMap[$cryptoId]) ? (int) $coveredMap[$cryptoId] : 0;
			$cancelClock = min($nowReal, $cryptoCoveredAt);

			$paymentCancellationTimeHr = $nmmSettings->get_autopay_cancellation_time($cryptoId);
			$paymentCancellationTimeSec = $paymentCancellationTimeHr * 60 * 60;
			$timeSinceOrder = $cancelClock - $orderTime;
			NMMPRO_Util::log(__FILE__, __LINE__, 'cryptoID: ' . $cryptoId . ' payment cancellation time sec: ' . $paymentCancellationTimeSec . ' time since order: ' . $timeSinceOrder);

			if ($timeSinceOrder > $paymentCancellationTimeSec) {
				if (isset($incompleteAddrs[$cryptoId . '|' . $paymentRecord['address']])) {
					// This address was not conclusively verified by the last
					// completed sweep - a payment may be hidden from view, so
					// its rows must not expire yet. The exclusion refreshes at
					// every wrap: once a sweep verifies the address cleanly,
					// expiry resumes on the next cancellation pass.
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: deferring expiry for ' . $cryptoId . ' address ' . $paymentRecord['address'] . ' (not conclusively verified by the last sweep).');
					continue;
				}

				$orderId = $paymentRecord['order_id'];
				$orderAmount = $paymentRecord['order_amount'];
				$address = $paymentRecord['address'];

				// The unpaid rows were snapshotted earlier; re-check the live order
				// right before touching state, because a merchant, webhook or the
				// verifier can complete the order in between. Never cancel one that
				// has already been paid or is no longer awaiting payment.
				$order = $orderId ? wc_get_order($orderId) : false;

				if (!$order) {
					// Order deleted - retire the orphaned payment record. Claim it
					// conditionally so a concurrent verifier still wins the row.
					$paymentRepo->claim_for_cancellation($orderId, $orderAmount);
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' is gone; retiring its payment record.');
					continue;
				}

				if ($order->is_paid()) {
					// Paid out-of-band since the snapshot - reconcile the record to
					// paid so the cron stops matching it, and do not cancel.
					$paymentRepo->set_status($orderId, $orderAmount, 'paid');
					continue;
				}

				if (!$order->has_status(array('pending', 'on-hold'))) {
					// Terminal non-paid or otherwise not awaiting payment - reconcile
					// the record but leave the order alone.
					$paymentRepo->claim_for_cancellation($orderId, $orderAmount);
					continue;
				}

				// NEVER auto-cancel an order whose address Autopay cannot look up.
				// A store upgrading from a release with looser address rules can
				// still hold unpaid orders that were issued an address the
				// explorer cannot report on - a Zcash shielded or Unified address
				// is the known case. Those orders escaped the carousel long before
				// this code runs, so refusing to hand such an address out at
				// allocation time does not help them: the address is already on
				// the order and the customer may already have paid it. Cancelling
				// is the one irreversible thing we could do, and it would cancel a
				// GENUINELY PAID order. Leave it for the merchant, who can see the
				// payment in their own wallet.
				if (!self::address_verifiable_for_expiry($cryptoId, $address)) {
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: not cancelling ' . $cryptoId . ' order ' . $orderId . ' - its payment address (' . $address . ') cannot be checked on a public explorer, so an actual payment would be invisible to us. Please confirm this order in your own wallet and complete or cancel it by hand.', 'warning');
					continue;
				}

				// Atomically claim this row for cancellation. The verifier flips a
				// matched row 'unpaid' -> 'paid'; this flips 'unpaid' -> 'cancelled'
				// only while it is still 'unpaid'. Only CLAIM_CLAIMED means we won
				// and may cancel: on CLAIM_ALREADY the verifier already took the row,
				// and on CLAIM_DB_ERROR the outcome is unknown - in both cases leave
				// the order alone (a real error is retried next tick).
				if ($paymentRepo->claim_for_cancellation($orderId, $orderAmount) !== NMMPRO_Payment_Repo::CLAIM_CLAIMED) {
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: did not claim order ' . $orderId . ' for cancellation (already transitioned or DB error); not cancelling this tick.');
					continue;
				}

				// Hook point immediately before the final transition. Integrations
				// (and the concurrency test) can observe - or, in a genuine race,
				// complete - the order here; the re-fetch below then reconciles.
				NMMPRO_Compat::action('nmmpro_before_autopay_cancel', $orderId, $cryptoId, $address);

				// Final re-fetch after the claim to close the order-side window as
				// far as WooCommerce allows. If the order was paid or advanced
				// out-of-band after we claimed the row, reconcile the record and
				// leave the order alone rather than cancelling a paid order.
				$order = WC_Order_Factory::get_order($orderId);

				if (!$order) {
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' vanished after cancellation claim; record already retired.');
					continue;
				}

				if ($order->is_paid()) {
					$paymentRepo->set_status($orderId, $orderAmount, 'paid');
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' was paid after cancellation claim; reconciled record to paid, not cancelling.');
					continue;
				}

				if (!$order->has_status(array('pending', 'on-hold'))) {
					NMMPRO_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' advanced to ' . $order->get_status() . ' after cancellation claim; leaving order, record cancelled.');
					continue;
				}

				$orderNote = sprintf(
					/* translators: 1: cryptocurrency ticker, 2: number of hours */
					__('Your %1$s order was <strong>cancelled</strong> because you were unable to pay for %2$s hour(s). Please do not send any funds to the payment address.', 'nomiddleman-crypto-payments-for-woocommerce'),
					$cryptoId,
					round($paymentCancellationTimeSec/3600, 1));

				add_filter('woocommerce_email_subject_customer_note', 'NMMPRO_change_cancelled_email_note_subject_line', 1, 2);
			add_filter('woocommerce_email_heading_customer_note', 'NMMPRO_change_cancelled_email_heading', 1, 2);

				$order->update_status('wc-cancelled');
				$order->add_order_note($orderNote, true);

				NMMPRO_Util::log(__FILE__, __LINE__, 'Cancelled ' . $cryptoId . ' payment: ' . $orderId . ' which was using address: ' . $address . 'due to non-payment.');
			}
		}
	}
}

?>
