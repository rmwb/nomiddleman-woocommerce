<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Payment {

	// How far before an order's creation time a matching transaction may be dated
	// and still be accepted, absorbing block-timestamp clock skew while rejecting
	// genuinely pre-order transactions on reused addresses.
	const TX_ORDER_SKEW_GRACE_SEC = 3600;

	public static function check_all_addresses_for_matching_payment($transactionLifetime) {
		$paymentRepo = new NMM_Payment_Repo();

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
		$lastRun = (int) get_option('nmm_autopay_scan_last_run', 0);
		update_option('nmm_autopay_scan_last_run', $now, false);
		$cronIntervalSec = ($lastRun > 0 && $now > $lastRun) ? ($now - $lastRun) : 60;

		// Count only (a single scalar) so a large backlog is never loaded into PHP
		// just to size the budget.
		$total = $paymentRepo->count_distinct_unpaid_addresses();
		if ($total < 1) {
			// Nothing unpaid: drop any stale retry keys so they cannot linger,
			// and keep the sweep-start fresh - an empty backlog is a trivially
			// complete sweep, so the first sweep over newly arriving rows must
			// not inherit a start time from before the idle stretch.
			if (get_option('nmm_autopay_scan_retry', array())) {
				update_option('nmm_autopay_scan_retry', array(), false);
			}
			update_option('nmm_autopay_scan_sweep_start', $now, false);
			return;
		}

		$cryptos = NMM_Cryptocurrencies::get();
		$nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

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
		$baseBudget = (int) apply_filters('nmm_autopay_scan_budget', 50);

		// When the current multi-tick sweep began (the tick that fetched the
		// head of the address list). Initialized here on the very first run;
		// thereafter reset by the wrap handling at the bottom. The coverage
		// stamp uses this START time, never the wrap time - see below.
		$sweepStart = (int) get_option('nmm_autopay_scan_sweep_start', 0);
		if ($sweepStart < 1) {
			$sweepStart = $now;
			update_option('nmm_autopay_scan_sweep_start', $sweepStart, false);
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
		$cursor = get_option('nmm_autopay_scan_cursor', '');
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
		$retrySet = get_option('nmm_autopay_scan_retry', array());
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
		$priorityWindow = (int) apply_filters('nmm_autopay_priority_window', 30 * MINUTE_IN_SECONDS);
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
		$partialCryptos = array();

		foreach ($toProcess as $record) {
			$cryptoId = $record['cryptocurrency'];
			$address = $record['address'];

			if (!isset($cryptos[$cryptoId])) {
				// A ticker no longer in the registry (coin support removed)
				// cannot be verified at all - it must not be certified covered,
				// or expiry would cancel its orders without any payment check.
				// Dirty (not failed): there is no point re-fetching it every
				// tick, and the next sweep re-marks it for as long as its
				// unpaid rows exist, so they are never auto-cancelled.
				$partialCryptos[$cryptoId] = true;
				continue;
			}
			$crypto = $cryptos[$cryptoId];

			// This coin's window, widened past its own oldest unpaid order.
			$cryptoLifetime = isset($lifetimeByCrypto[$cryptoId]) ? $lifetimeByCrypto[$cryptoId] : $effectiveLifetime;

			do_action('nmm_autopay_address_checked', $cryptoId, $address);

			if ($cryptoId === 'XMR') {
				if (!$xmrFetched) {
					$xmrBatch = NMM_Monero::get_account_transactions($cryptoLifetime);
					$xmrOk = (isset($xmrBatch['result']) && $xmrBatch['result'] === 'success');
					$xmrByAddress = ($xmrOk && isset($xmrBatch['by_address'])) ? $xmrBatch['by_address'] : array();
					$xmrFetched = true;
				}
				if (!$xmrOk) {
					$newFailed[] = self::scan_key($record); // could not fetch; retry next tick
					continue;
				}
				$xmrTxs = isset($xmrByAddress[$address]) ? $xmrByAddress[$address] : array();
				self::process_address_transactions($crypto, $address, $xmrTxs, $cryptoLifetime);
			}
			else {
				if (!self::check_address_transactions_for_matching_payments($crypto, $address, $cryptoLifetime)) {
					$newFailed[] = self::scan_key($record);
				}
				elseif ($cryptoId === 'SOL' && !NMM_Blockchain::sol_address_fully_swept($address)) {
					// The bounded Solana sweep made durable progress but has
					// not yet inspected this address's whole matching window
					// (a busy or dusted address spans several ticks). Not a
					// failure - collected payments were returned and progress
					// is durable - but not verification either: the coin must
					// not be certified covered while signatures below the
					// internal cursor, or in its retry queue, are uninspected.
					$partialCryptos[$cryptoId] = true;
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
		$dirtyAdd = $partialCryptos;
		$retryCap = max(1, (int) apply_filters('nmm_autopay_scan_retry_cap', 200));
		$newFailed = array_values(array_unique($newFailed));
		if (count($newFailed) > $retryCap) {
			foreach (array_slice($newFailed, $retryCap) as $droppedKey) {
				$droppedParts = explode('|', $droppedKey, 2);
				$dirtyAdd[$droppedParts[0]] = true;
			}
			$newFailed = array_slice($newFailed, 0, $retryCap);
		}
		if (!empty($dirtyAdd)) {
			$dirty = get_option('nmm_autopay_scan_dirty', array());
			if (!is_array($dirty)) {
				$dirty = array();
			}
			foreach (array_keys($dirtyAdd) as $dirtyAddCryptoId) {
				$dirty[$dirtyAddCryptoId] = true;
			}
			update_option('nmm_autopay_scan_dirty', $dirty, false);
		}
		update_option('nmm_autopay_scan_retry', $newFailed, false);
		update_option('nmm_autopay_scan_cursor', $lastKey, false);

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
		// Coverage is stamped PER CURRENCY, and a currency with an unresolved
		// fetch failure ($newFailed carries this tick's failures AND re-failed
		// retries from earlier ticks) keeps its previous stamp: a failed
		// address was not verified, and stamping it would let cancellation
		// treat it as checked. Scoping this per currency matters - one
		// permanently failing endpoint (say, an unconfigured Monero wallet
		// RPC) must only hold back ITS coin's expirations, not freeze
		// cancellation for every currency on the store. The retry path
		// re-checks the failed address next tick, and that coin's stamp waits
		// for the next wrap with all of its fetches clean.
		if ($wrapped || $take >= $total) {
			$failedCryptos = array();
			foreach ($newFailed as $failedKey) {
				$failedParts = explode('|', $failedKey, 2);
				$failedCryptos[$failedParts[0]] = true;
			}

			// Currencies marked dirty during this sweep - failed keys dropped
			// from the retry set, or a Solana address whose bounded internal
			// history sweep is still mid-window - have addresses that were not
			// fully verified: exclude them from this stamp, then clear the
			// marker. The sweep now starting revisits every address, and a
			// still-incomplete address simply re-marks its coin dirty.
			$dirty = get_option('nmm_autopay_scan_dirty', array());
			if (is_array($dirty) && !empty($dirty)) {
				foreach (array_keys($dirty) as $dirtyCryptoId) {
					$failedCryptos[$dirtyCryptoId] = true;
				}
				update_option('nmm_autopay_scan_dirty', array(), false);
			}

			$stampAt = ($take >= $total) ? $now : $sweepStart;
			$coveredMap = get_option('nmm_autopay_scan_covered_at', array());
			if (!is_array($coveredMap)) {
				$coveredMap = array();
			}
			foreach ($paymentRepo->get_distinct_unpaid_cryptos() as $sweptCryptoId) {
				if (!isset($failedCryptos[$sweptCryptoId])) {
					$coveredMap[$sweptCryptoId] = $stampAt;
				}
			}
			update_option('nmm_autopay_scan_covered_at', $coveredMap, false);

			// The next sweep begins with the head rows this tick just fetched.
			update_option('nmm_autopay_scan_sweep_start', $now, false);
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

	// Returns true if the address's transactions were fetched (and matched), or
	// false if the fetch failed - so the sweep can retry a transient failure on
	// the next tick instead of leaving it until the whole backlog is swept again.
	private static function check_address_transactions_for_matching_payments($crypto, $address, $transactionLifetime) {
		$cryptoId = $crypto->get_id();

		NMM_Util::log(__FILE__, __LINE__, '===========================================================================');
		NMM_Util::log(__FILE__, __LINE__, 'Starting payment verification for: ' . $cryptoId . ' - ' . $address);

		try {
			$transactions = self::get_address_transactions($cryptoId, $address, $transactionLifetime);
		}
		catch (\Exception $e) {
			NMM_Util::log(__FILE__, __LINE__, 'Unable to get transactions for ' . $cryptoId);
			return false;
		}

		NMM_Util::log(__FILE__, __LINE__, 'Transcations found for ' . $cryptoId . ' - ' . $address . ': ' . print_r($transactions, true));

		self::process_address_transactions($crypto, $address, $transactions, $transactionLifetime);

		return true;
	}

	/**
	 * Match already-fetched transactions for one address against its unpaid
	 * orders, then claim/complete or reconcile. Split out from the network fetch
	 * so the matching, race-claim and consumed-tx logic can be exercised directly
	 * in tests with injected NMM_Transaction objects (no external calls).
	 *
	 * @param NMM_Cryptocurrency $crypto
	 * @param string             $address
	 * @param NMM_Transaction[]  $transactions
	 * @param int                $transactionLifetime
	 */
	public static function process_address_transactions($crypto, $address, $transactions, $transactionLifetime) {
		$paymentRepo = new NMM_Payment_Repo();
		$nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

		$cryptoId = $crypto->get_id();

		foreach ($transactions as $transaction) {
			$txHash = $transaction->get_hash();
			$transactionAmount = $transaction->get_amount();

			$requiredConfirmations = $nmmSettings->get_autopay_required_confirmations($cryptoId);
			$txConfirmations = $transaction->get_confirmations();

			NMM_Util::log(__FILE__, __LINE__, '---confirmations: ' . $txConfirmations . ' Required: ' . $requiredConfirmations);
			if ($txConfirmations < $requiredConfirmations) {
				continue;
			}

			$txTimeStamp = $transaction->get_time_stamp();
			$timeSinceTx = time() - $txTimeStamp;

			NMM_Util::log(__FILE__, __LINE__, '---time since transaction: ' . $timeSinceTx . ' TX Lifetime: ' . $transactionLifetime);
			if ($timeSinceTx > $transactionLifetime) {
				continue;
			}

			if ($nmmSettings->tx_already_consumed($cryptoId, $address, $txHash)) {
				// Ordinary: we have already processed this tx. Expected, not a warning.
				NMM_Util::log(__FILE__, __LINE__, 'Already-consumed transaction skipped: ' . $txHash);
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
				$paymentAmountSmallestUnit = $paymentAmount * (10**$crypto->get_round_precision());

				$autoPaymentPercent = apply_filters('nmm_autopay_percent', $nmmSettings->get_autopay_processing_percent($cryptoId), $paymentAmount, $cryptoId, $address);

				// Guard against a zero (or unparseable) expected amount so we
				// never divide by zero, and treat any overpayment as a match:
				// the shortfall tolerance only applies to UNDER-payment.
				if ($paymentAmountSmallestUnit <= 0) {
					continue;
				}

				if ($transactionAmount >= $paymentAmountSmallestUnit) {
					$matchingPaymentRecords[] = $record;
				}
				else {
					$percentShortfall = ($paymentAmountSmallestUnit - $transactionAmount) / $paymentAmountSmallestUnit;

					if ($percentShortfall <= (1 - $autoPaymentPercent)) {
						$matchingPaymentRecords[] = $record;
					}
				}

				NMM_Util::log(__FILE__, __LINE__, '---CryptoId, paymentAmount, paymentAmountSmallestUnit, transactionAmount:' . $cryptoId . ',' . $paymentAmount .',' . $paymentAmountSmallestUnit . ',' .  $transactionAmount);
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
				NMM_Util::log(__FILE__, __LINE__, 'Autopay collision: ' . $cryptoId . ' transaction ' . $txHash . ' matches multiple unpaid orders (' . implode(', ', $collidingOrderIds) . '); left for manual reconciliation.', 'warning');

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
				do_action('nmm_before_autopay_complete', $orderId, $cryptoId, $address, $txHash);

				// Atomically claim the row for payment. The expiry cron races us
				// with the opposite claim (unpaid -> cancelled); because both sides
				// go through the same conditional update, exactly one wins. The
				// claim is tri-state so we never confuse a genuine race loss with a
				// transient DB error.
				$claim = $paymentRepo->claim_for_payment($orderId, $orderAmount);

				if ($claim === NMM_Payment_Repo::CLAIM_DB_ERROR) {
					// The UPDATE failed, so the row state is unknown - it may well
					// still be unpaid. Do NOT consume the tx (that would permanently
					// ignore a valid payment) and do NOT complete the order; leave
					// everything untouched so a later tick retries this transaction.
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: database error claiming ' . $cryptoId . ' order ' . $orderId . ' for payment; leaving the transaction unconsumed for retry. Transaction Hash: ' . $txHash, 'error');
					continue;
				}

				if ($claim === NMM_Payment_Repo::CLAIM_ALREADY) {
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
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: verified ' . $cryptoId . ' payment for order ' . $orderId . ' but its record was already transitioned (likely expired and cancelled) - not completing the order; recorded the transaction as consumed to prevent reuse on a recycled address. Transaction Hash: ' . $txHash . '. Please reconcile manually.', 'warning');
					continue;
				}

				// CLAIM_CLAIMED: we won the row - complete the order.

				$paymentRepo->set_hash($orderId, $orderAmount, $txHash);

				$order = wc_get_order($orderId);
				if (!$order) {
					// Row is claimed 'paid' (so it stops matching), but the order is
					// gone - nothing to complete. Record the tx as consumed and move on.
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: verified ' . $cryptoId . ' payment but order ' . $orderId . ' no longer exists. Transaction Hash: ' . $txHash, 'warning');
					$nmmSettings->add_consumed_tx($cryptoId, $address, $txHash);
					continue;
				}
				$orderNote = sprintf(
						/* translators: 1: amount, 2: cryptocurrency ticker, 3: date/time, 4: transaction hash */
						__('Order payment of %1$s %2$s verified at %3$s. Transaction Hash: %4$s', 'nomiddleman-crypto-payments-for-woocommerce'),
						NMM_Cryptocurrencies::get_price_string($crypto->get_id(), $transactionAmount / (10**$crypto->get_round_precision())),
						$cryptoId,
						date('Y-m-d H:i:s', time()),
						apply_filters('nmm_order_txhash', $txHash, $cryptoId));

				$order->update_meta_data('transaction_hash', $txHash);
				$order->payment_complete();
				$order->add_order_note($orderNote);

				$nmmSettings->add_consumed_tx($cryptoId, $address, $txHash);
			}		
		}		
	}

	private static function get_address_transactions($cryptoId, $address, $transactionLifetime = null) {
		if ($cryptoId === 'ETH') {
			$result = NMM_Blockchain::get_eth_address_transactions($address);
		}
		if ($cryptoId === 'BCH') {
			$result = NMM_Blockchain::get_bch_address_transactions($address);
		}
		if ($cryptoId === 'DOGE') {
			$result = NMM_Blockchain::get_doge_address_transactions($address);
		}
		if ($cryptoId === 'ZEC') {
			$result = NMM_Blockchain::get_zec_address_transactions($address);
		}
		if ($cryptoId === 'DASH') {
			$result = NMM_Blockchain::get_dash_address_transactions($address);
		}
		if ($cryptoId === 'XRP') {
			$result = NMM_Blockchain::get_xrp_address_transactions($address);
		}
		if ($cryptoId === 'ETC') {
			$result = NMM_Blockchain::get_etc_address_transactions($address);
		}
		if ($cryptoId === 'XLM') {
			$result = NMM_Blockchain::get_xlm_address_transactions($address);
		}
		if ($cryptoId === 'BSV') {
			$result = NMM_Blockchain::get_bsv_address_transactions($address);
		}
		if ($cryptoId === 'EOS') {
			$result = NMM_Blockchain::get_eos_address_transactions($address);
		}
		if ($cryptoId === 'TRX') {
			$result = NMM_Blockchain::get_trx_address_transactions($address);
		}
		if ($cryptoId === 'ONION') {
			$result = NMM_Blockchain::get_onion_address_transactions($address);
		}
		if ($cryptoId === 'BLK') {
			$result = NMM_Blockchain::get_blk_address_transactions($address);
		}
		if ($cryptoId === 'ADA') {
			$result = NMM_Blockchain::get_ada_address_transactions($address);	
		}
		if ($cryptoId === 'XTZ') {
			$result = NMM_Blockchain::get_xtz_address_transactions($address);	
		}
		if ($cryptoId === 'REP') {
			$result = NMM_Blockchain::get_erc20_address_transactions('REP', $address);	
		}
		if ($cryptoId === 'MLN') {
			$result = NMM_Blockchain::get_erc20_address_transactions('MLN', $address);	
		}
		if ($cryptoId === 'GNO') {
			$result = NMM_Blockchain::get_erc20_address_transactions('GNO', $address);	
		}
		if ($cryptoId === 'LTC') {
			$result = NMM_Blockchain::get_ltc_address_transactions($address);
		}
		if ($cryptoId === 'BTC') {
			$result = NMM_Blockchain::get_btc_address_transactions($address);	
		}
		if ($cryptoId === 'BAT') {
			$result = NMM_Blockchain::get_erc20_address_transactions('BAT', $address);	
		}
		if ($cryptoId === 'BNB') {
			$result = NMM_Blockchain::get_erc20_address_transactions('BNB', $address);	
		}
		if ($cryptoId === 'HOT') {
			$result = NMM_Blockchain::get_erc20_address_transactions('HOT', $address);	
		}
		if ($cryptoId === 'LINK') {
			$result = NMM_Blockchain::get_erc20_address_transactions('LINK', $address);	
		}
		if ($cryptoId === 'OMG') {
			$result = NMM_Blockchain::get_erc20_address_transactions('OMG', $address);	
		}
		if ($cryptoId === 'ZRX') {
			$result = NMM_Blockchain::get_erc20_address_transactions('ZRX', $address);	
		}
		if ($cryptoId === 'GUSD') {
			$result = NMM_Blockchain::get_erc20_address_transactions('GUSD', $address);	
		}
		if ($cryptoId === 'WAVES') {
			$result = NMM_Blockchain::get_waves_address_transactions($address);	
		}
		if ($cryptoId === 'DCR') {
			$result = NMM_Blockchain::get_dcr_address_transactions($address);	
		}
		if ($cryptoId === 'LSK') {
			$result = NMM_Blockchain::get_lsk_address_transactions($address);	
		}
		if ($cryptoId === 'XEM') {
			$result = NMM_Blockchain::get_xem_address_transactions($address);	
		}
		if ($cryptoId === 'XMY') {
			$result = NMM_Blockchain::get_xmy_address_transactions($address);	
		}
		if ($cryptoId === 'BTX') {
			$result = NMM_Blockchain::get_btx_address_transactions($address);	
		}
		if ($cryptoId === 'GRS') {
			$result = NMM_Blockchain::get_grs_address_transactions($address);	
		}
        if ($cryptoId === 'DGB') {
            $result = NMM_Blockchain::get_dgb_address_transactions($address);
        }
        if ($cryptoId === 'USDC') {
			$result = NMM_Blockchain::get_erc20_address_transactions('USDC', $address);
		}
		if ($cryptoId === 'USDT') {
			$result = NMM_Blockchain::get_erc20_address_transactions('USDT', $address);
		}
		if ($cryptoId === 'USDTTRX') {
			$result = NMM_Blockchain::get_trc20_usdt_address_transactions($address);
		}
		if ($cryptoId === 'SOL') {
			$result = NMM_Blockchain::get_sol_address_transactions($address, $transactionLifetime);
		}
		
		if ($cryptoId === 'XMR') {
			$result = NMM_Monero::get_address_transactions($address);
		}
		// any registered ERC-20 token without an explicit branch above
		if (!isset($result)) {
			$cryptos = NMM_Cryptocurrencies::get();
			if (isset($cryptos[$cryptoId]) && $cryptos[$cryptoId]->is_erc20_token()) {
				$result = NMM_Blockchain::get_erc20_address_transactions($cryptoId, $address);
			}
			else {
				$result = array('result' => 'error', 'message' => 'No verification available');
			}
		}

		if ($result['result'] === 'error') {			
			NMM_Util::log(__FILE__, __LINE__, 'BAD API CALL');
			throw new \Exception(esc_html__('Could not reach external service to do auto payment processing.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}		

		return $result['transactions'];
	}

	public static function cancel_expired_payments() {
		global $woocommerce;
		$nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

		$paymentRepo = new NMM_Payment_Repo();

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
		$coveredMap = get_option('nmm_autopay_scan_covered_at', array());
		if (!is_array($coveredMap)) {
			$coveredMap = array(); // unknown format: treat as no coverage (defer, never cancel unverified)
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
			NMM_Util::log(__FILE__, __LINE__, 'cryptoID: ' . $cryptoId . ' payment cancellation time sec: ' . $paymentCancellationTimeSec . ' time since order: ' . $timeSinceOrder);

			if ($timeSinceOrder > $paymentCancellationTimeSec) {
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
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' is gone; retiring its payment record.');
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

				// Atomically claim this row for cancellation. The verifier flips a
				// matched row 'unpaid' -> 'paid'; this flips 'unpaid' -> 'cancelled'
				// only while it is still 'unpaid'. Only CLAIM_CLAIMED means we won
				// and may cancel: on CLAIM_ALREADY the verifier already took the row,
				// and on CLAIM_DB_ERROR the outcome is unknown - in both cases leave
				// the order alone (a real error is retried next tick).
				if ($paymentRepo->claim_for_cancellation($orderId, $orderAmount) !== NMM_Payment_Repo::CLAIM_CLAIMED) {
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: did not claim order ' . $orderId . ' for cancellation (already transitioned or DB error); not cancelling this tick.');
					continue;
				}

				// Hook point immediately before the final transition. Integrations
				// (and the concurrency test) can observe - or, in a genuine race,
				// complete - the order here; the re-fetch below then reconciles.
				do_action('nmm_before_autopay_cancel', $orderId, $cryptoId, $address);

				// Final re-fetch after the claim to close the order-side window as
				// far as WooCommerce allows. If the order was paid or advanced
				// out-of-band after we claimed the row, reconcile the record and
				// leave the order alone rather than cancelling a paid order.
				$order = wc_get_order($orderId);

				if (!$order) {
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' vanished after cancellation claim; record already retired.');
					continue;
				}

				if ($order->is_paid()) {
					$paymentRepo->set_status($orderId, $orderAmount, 'paid');
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' was paid after cancellation claim; reconciled record to paid, not cancelling.');
					continue;
				}

				if (!$order->has_status(array('pending', 'on-hold'))) {
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: order ' . $orderId . ' advanced to ' . $order->get_status() . ' after cancellation claim; leaving order, record cancelled.');
					continue;
				}

				$orderNote = sprintf(
					/* translators: 1: cryptocurrency ticker, 2: number of hours */
					__('Your %1$s order was <strong>cancelled</strong> because you were unable to pay for %2$s hour(s). Please do not send any funds to the payment address.', 'nomiddleman-crypto-payments-for-woocommerce'),
					$cryptoId,
					round($paymentCancellationTimeSec/3600, 1));

				add_filter('woocommerce_email_subject_customer_note', 'NMM_change_cancelled_email_note_subject_line', 1, 2);
	    		add_filter('woocommerce_email_heading_customer_note', 'NMM_change_cancelled_email_heading', 1, 2);

				$order->update_status('wc-cancelled');
				$order->add_order_note($orderNote, true);

				NMM_Util::log(__FILE__, __LINE__, 'Cancelled ' . $cryptoId . ' payment: ' . $orderId . ' which was using address: ' . $address . 'due to non-payment.');
			}
		}
	}
}

?>