<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Hd {

	// On-chain totals the verifier observed during THIS cron run. The expiry
	// pass consults this instead of making its own explorer calls: the verifier
	// runs immediately before it in the same process and has already fetched
	// every reconcilable address's balance, so re-fetching would (a) double
	// every explorer call under a large backlog and (b) livelock on hosts with
	// a per-host cooldown (chainz for BTX: the verifier's own successful call
	// starts the cooldown, so an immediate second call is refused every cycle
	// and the expired order is never cancelled).
	//
	// Entries are array('total' => float, 'at' => unix ts) and are trusted only
	// while younger than OBSERVATION_MAX_AGE_SEC: under a pathologically large
	// backlog the verifier's observation can be minutes old by the time the
	// expiry pass runs, and a payment landing in that window must not be missed
	// - so a stale entry falls back to a fresh fetch (safe: by then any short
	// per-host cooldown has lapsed too). NMM_Cron resets the cache at the start
	// of every acquired cycle, so a long-lived process (CLI cron runner,
	// multisite loop) can never act on a previous cycle's observations.
	const OBSERVATION_MAX_AGE_SEC = 120;

	private static $observedTotals = array();

	// A fresh cron run starts with no observations. Called by NMM_Cron at the
	// top of every acquired cycle (and by tests).
	public static function reset_observed_totals() {
		self::$observedTotals = array();
	}

	// Scoped by site (table prefix), coin, wallet (mpk + hd_mode) and address,
	// so a multisite loop or a runner serving several wallets in one process
	// can never consume another site's or wallet's observation.
	private static function observation_key($cryptoId, $mpk, $hdMode, $address) {
		global $wpdb;
		return $wpdb->prefix . '|' . $cryptoId . '|' . (int) $hdMode . '|' . md5((string) $mpk) . '|' . $address;
	}

	/**
	 * Is this order still legitimately awaiting its crypto payment?
	 *
	 * The gateway's own awaiting states are on-hold and pending, but a site may
	 * declare additional payable statuses through WooCommerce's
	 * woocommerce_valid_order_statuses_for_payment filter (a custom payment
	 * workflow, say); needs_payment() honours that filter. Such an order must
	 * be neither refused completion by the verifier nor retired by the
	 * reconcile pass.
	 *
	 * Terminal states are checked FIRST and always lose: WooCommerce's default
	 * for that filter includes 'failed', and a failed/cancelled/refunded order
	 * receiving a late payment must never be resurrected by it.
	 */
	private static function order_awaits_payment($order) {
		if ($order->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
			return false;
		}

		return $order->has_status(array('on-hold', 'pending')) || $order->needs_payment();
	}

	public static function buffer_ready_addresses($cryptoId, $mpk, $amount, $hdMode) {
		$hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);
		$readyCount = $hdRepo->count_ready();		
		
		$neededAddresses = $amount - $readyCount;
		
		for ($i = 0; $i < $neededAddresses; $i++) {
			
			try {
				self::force_new_address($cryptoId, $mpk, $hdMode);
			}
			catch ( \Exception $e ) {
				NMM_Util::log(__FILE__, __LINE__, $e->getMessage());
			}
		}
	}

	public static function check_all_pending_addresses_for_payment($cryptoId, $mpk, $requiredConfirmations, $percentToVerify, $hdMode) {
		global $woocommerce;
		$hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);

		$pendingRecords = $hdRepo->get_pending();

		foreach ($pendingRecords as $record) {

			try {
				$blockchainTotalReceived = self::get_total_received_for_address($cryptoId, $record['address'], $requiredConfirmations);
			}
			catch ( \Exception $e ) {
				// just go to next record if the endpoint is not responding
				continue;
			}

			// Record every successful observation (zero included) for the expiry
			// pass, which runs right after this in the same cron process.
			self::$observedTotals[self::observation_key($cryptoId, $mpk, $hdMode, $record['address'])] = array(
				'total' => $blockchainTotalReceived,
				'at'    => time(),
			);

			$address = $record['address'];
			$orderId = $record['order_id'];
			$orderAmount = $record['order_amount'];

			$recordTotalReceived = (float) $record['total_received'];
			$newPaymentAmount = $blockchainTotalReceived - $recordTotalReceived;

			// TODO: This should be 1 / 10*max digits
			$hasNewPayment = $newPaymentAmount > 0.0000001;

			// A row left mid-completion by an earlier sweep that was interrupted
			// (the process died, or payment_complete() failed). get_pending()
			// returns it precisely so this run can finish the job; we already own
			// it, so we resume without re-claiming.
			$isResuming = $record['status'] === 'completing';

			$amountToVerify = ((float) $orderAmount) * $percentToVerify;
			$paymentAmountVerified = $blockchainTotalReceived >= $amountToVerify;

			// Nothing to act on: no new funds, no fully-funded order still awaiting
			// completion, and no interrupted completion to resume.
			if (!$hasNewPayment && !$paymentAmountVerified && !$isResuming) {
				continue;
			}

			if ($hasNewPayment) {
				NMM_Util::log(__FILE__, __LINE__, 'Address ' . $address . ' received a new payment of ' . NMM_Cryptocurrencies::get_price_string($cryptoId, $newPaymentAmount) . ' ' . $cryptoId);
			}

			// The order's LIVE status governs everything below. The row was read at
			// the top of this sweep and says nothing about the order now: it may
			// since have been cancelled, failed, or paid out of band. wc_get_order()
			// (not new WC_Order(), which throws for a deleted order outside the try
			// above and would abort the whole sweep) returns false when it is gone.
			$order = $orderId ? wc_get_order($orderId) : false;

			// enough to process the order
			if ($paymentAmountVerified) {

				// Cache the observed total up front, before the claim. It is the
				// record that these funds have been seen, and every path below is
				// safe with it cached: the reconcile pass (which runs later this
				// same cron cycle) only cancels ZERO-balance expired rows, so a
				// non-zero total protects a verified order from being cancelled;
				// and retry no longer depends on a stale total (it is driven by the
				// order still being payable). Caching here rather than after the
				// claim also closes the crash window in which a row could reach
				// 'completing' with a zero total and then be wrongly cancelled.
				//
				// If this write cannot be confirmed, do NOT go on to claim or
				// complete: the invariant that a verified row carries its funds is
				// the whole basis of the expiry pass's safety. Leave the row as-is
				// for the next sweep to retry. (Belt-and-braces: the expiry pass
				// also re-checks the chain before cancelling, so even here a funded
				// order is not cancelled.)
				if (!$hdRepo->set_total_received($address, $blockchainTotalReceived)) {
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: could not record the received total for ' . $cryptoId . ' address ' . $address . '; deferring completion to the next sweep.', 'error');
					continue;
				}

				if (!$order) {
					// Order deleted. The cached non-zero total makes the reconcile
					// pass RETIRE the address (dirty) rather than recycle it toward a
					// different order. A resuming row is left 'completing', which
					// get_reconcilable() also covers.
					if ($hasNewPayment) {
						NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: address ' . $address . ' received ' . NMM_Cryptocurrencies::get_price_string($cryptoId, $blockchainTotalReceived) . ' ' . $cryptoId . ' but order ' . $orderId . ' no longer exists. Please reconcile manually.', 'warning');
					}
					continue;
				}

				if ($order->is_paid()) {
					// Paid through some other path (or a previous sweep completed
					// the order but was interrupted before it could mark the row).
					// Settle the row to terminal 'complete' so it stops being swept.
					$hdRepo->set_status($address, 'complete');
					continue;
				}

				if (!self::order_awaits_payment($order)) {
					// Cancelled, failed, refunded - not awaiting payment. Leave the
					// row payable; the reconcile pass retires it (non-zero total ->
					// dirty). Do NOT complete the order: that is the
					// late-payment-resurrects-a-dead-order bug. The note is gated on
					// a genuinely new payment so a fully-funded row that keeps
					// re-verifying every sweep does not re-note each time.
					// A row we were completing when the order died goes back to
					// 'assigned' (the reconcile pass covers 'completing' too, but
					// 'assigned' is the address's natural resting state).
					if ($isResuming) {
						$hdRepo->release_claim($address);
					}
					if ($hasNewPayment) {
						NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: verified ' . $cryptoId . ' payment for order ' . $orderId . ' but the order is ' . $order->get_status() . ' - not completing it. Please reconcile manually.', 'warning');
						$order->add_order_note(sprintf(
							/* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address, 4: order status */
							__('Late payment of %1$s %2$s received at Privacy Mode address %3$s after this order became %4$s. The order has NOT been completed automatically - please reconcile this payment manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
							NMM_Cryptocurrencies::get_price_string($cryptoId, $blockchainTotalReceived),
							$cryptoId,
							$address,
							$order->get_status()));
					}
					continue;
				}

				// The order is live and fully funded. Claim it for completion. The
				// claim is a single atomic CAS that both takes a payable
				// ('assigned'/'underpaid') row AND resumes a 'completing' row whose
				// lease has expired (a claim abandoned by a crashed run) - so a
				// row we are resuming goes through exactly the same gate, and a
				// 'completing' row a concurrent run still holds is refused.
				// 'completing' is non-terminal and still swept, so a crash between
				// the claim and the completion is picked up by a later sweep.
				$claim = $hdRepo->claim_for_complete($address);

				if ($claim === NMM_Hd_Repo::CLAIM_DB_ERROR) {
					// Row state unknown; the funds are cached, and the next sweep
					// re-evaluates (still verified, still payable) and retries.
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: database error claiming ' . $cryptoId . ' address ' . $address . ' for order ' . $orderId . '; leaving it for retry.', 'error');
					continue;
				}

				if ($claim === NMM_Hd_Repo::CLAIM_ALREADY) {
					// Either a concurrent run holds a live claim (only possible
					// without the cron advisory lock), or the row was moved out of a
					// claimable state between get_pending() and here. If a holder
					// fails to complete, it releases the row for a later retry.
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: ' . $cryptoId . ' address ' . $address . ' for order ' . $orderId . ' is already being completed by another run; not completing it here.', 'warning');
					continue;
				}

				// We hold the claim (row is 'completing'). Complete the order, then
				// mark the row terminal - in that order, so no failure point strands
				// the payment:
				//   * die before payment_complete -> row stays 'completing', resumed.
				//   * die after payment_complete, before the mark -> next sweep sees
				//     is_paid() and settles the row.

				// payment_complete() REPORTS failure rather than raising it:
				// WooCommerce wraps the event in its own try/catch and returns false
				// if a hook threw (a third-party integration is the usual culprit).
				// The \Throwable catch is for what that misses - an \Error is not an
				// Exception. payment_complete() is idempotent (it no-ops once the
				// order is already paid), so a resumed retry cannot pay twice.
				try {
					$completed = $order->payment_complete();
				}
				catch ( \Throwable $t ) {
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: completing order ' . $orderId . ' for ' . $cryptoId . ' address ' . $address . ' raised: ' . $t->getMessage(), 'error');
					$completed = false;
				}

				// payment_complete()'s return value alone is not proof: for a
				// status outside ITS OWN allowlist (a different filter -
				// woocommerce_valid_order_statuses_for_payment_complete - from the
				// one needs_payment() consults) it performs no transition yet still
				// returns true. Marking the row 'complete' on that lie would strand
				// a paid-but-never-transitioned order forever, so require the order
				// to actually BE paid before settling.
				if ($completed && !$order->is_paid()) {
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: payment_complete() did not transition order ' . $orderId . ' (status ' . $order->get_status() . ' is payable but not completable); releasing the claim. Please reconcile manually.', 'error');
					$completed = false;
				}

				if (!$completed) {
					// Hand the row back to 'assigned' - a payable, swept state - so a
					// later sweep retries. A single write: the total is already
					// cached (full), and is deliberately NOT rolled back, so the
					// reconcile pass still sees funds and never cancels this order.
					// If even this write fails, the row stays 'completing', which is
					// also still swept - so the payment is never stranded either way.
					$hdRepo->release_claim($address);
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: could not complete order ' . $orderId . ' for ' . $cryptoId . ' address ' . $address . '; released the claim for retry.', 'error');
					continue;
				}

				$hdRepo->set_status($address, 'complete');
				$order->add_order_note(sprintf(
					/* translators: 1: amount, 2: cryptocurrency ticker, 3: date/time */
					__('Order payment of %1$s %2$s verified at %3$s.', 'nomiddleman-crypto-payments-for-woocommerce'),
					NMM_Cryptocurrencies::get_price_string($cryptoId, $blockchainTotalReceived),
					$cryptoId,
					date('Y-m-d H:i:s', time())));
			}
			// we received payment but it was not enough to meet store admin's processing requirement
			else {

				// Only a genuinely new partial payment is worth noting; a resume
				// with no new funds (e.g. a reorg dropped the balance below the
				// threshold) just gets released back to a normal payable state.
				if (!$hasNewPayment) {
					if ($isResuming) {
						$hdRepo->release_claim($address);
					}
					continue;
				}

				// The underpayment is real and observed, so cache it before noting:
				// re-noting the same partial payment on every sweep would spam the
				// customer, and nothing here holds a claim to lose.
				$hdRepo->set_total_received($address, $blockchainTotalReceived);

				if (!$order) {
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: address ' . $address . ' received an underpayment but order ' . $orderId . ' no longer exists. Please reconcile manually.', 'warning');
					continue;
				}

				// handle multiple underpayments, just add a new note
				if ($record['status'] === 'underpaid') {
						$orderNote = sprintf(
							/* translators: 1: amount received, 2: cryptocurrency ticker, 3: remaining amount, 4: wallet address */
							__('New payment was received but is still under order total. Received payment of %1$s %2$s.<br>Remaining payment required: %3$s<br>Wallet Address: %4$s', 'nomiddleman-crypto-payments-for-woocommerce'),
							NMM_Cryptocurrencies::get_price_string($cryptoId, $newPaymentAmount),
							$cryptoId,
							NMM_Cryptocurrencies::get_price_string($cryptoId, ((float) $orderAmount) - $blockchainTotalReceived),
							$address);

						add_filter('woocommerce_email_subject_customer_note', 'NMM_change_partial_email_note_subject_line', 1, 2);
	    				add_filter('woocommerce_email_heading_customer_note', 'NMM_change_partial_email_heading', 1, 2);

						$order->add_order_note($orderNote, true);
					}
					// handle first underpayment, update status to pending payment (since we use on-hold for orders with no payment yet)
					else {						
						$orderNote = sprintf(
							/* translators: 1: amount received, 2: cryptocurrency ticker, 3: date/time, 4: remaining amount, 5: wallet address */
							__('Payment of %1$s %2$s received at %3$s. This is under the amount required to process this order.<br>Remaining payment required: %4$s<br>Wallet Address: %5$s', 'nomiddleman-crypto-payments-for-woocommerce'),
							NMM_Cryptocurrencies::get_price_string($cryptoId, $blockchainTotalReceived),
							$cryptoId,
							date('m/d/Y g:i a', time() + (60 * 60 * get_option('gmt_offset'))),
							NMM_Cryptocurrencies::get_price_string($cryptoId, $amountToVerify - $blockchainTotalReceived),
							$address);
						
						add_filter('woocommerce_email_subject_customer_note', 'NMM_change_partial_email_note_subject_line', 1, 2);
	    				add_filter('woocommerce_email_heading_customer_note', 'NMM_change_partial_email_heading', 1, 2);
						
						$order->add_order_note($orderNote, true);
						$hdRepo->set_status($address, 'underpaid');
					}
				}
			}
		}

	private static function get_total_received_for_address($cryptoId, $address, $requiredConfirmations) {
		if ($cryptoId === 'BTC') {
			return self::get_total_received_for_bitcoin_address($address, $requiredConfirmations);
		}
		if ($cryptoId === 'LTC') {
			return self::get_total_received_for_litecoin_address($address, $requiredConfirmations);
		}
		if ($cryptoId === 'QTUM') {
			return self::get_total_received_for_qtum_address($address);
		}
		if ($cryptoId === 'DASH') {
			return self::get_total_received_for_dash_address($address);
		}
		if ($cryptoId === 'DOGE') {
			return self::get_total_received_for_doge_address($address);
		}
		if ($cryptoId === 'XMY') {
			return self::get_total_received_for_xmy_address($address);
		}
		if ($cryptoId === 'BTX') {
			return self::get_total_received_for_bitcore_address($address, $requiredConfirmations);
		}
	}

	private static function get_total_received_for_bitcoin_address($address, $requiredConfirmations) {
		
		$primaryResult = NMM_Blockchain::get_blockchaininfo_total_received_for_btc_address($address, $requiredConfirmations);
		
		if ($primaryResult['result'] === 'success') {
			return $primaryResult['total_received'];
		}

		// The mempool.space / blockstream address summaries report confirmed
		// (>=1 conf) totals only - they cannot express "N confirmations". Use
		// them only when the requirement is EXACTLY one confirmation. A zero-
		// confirmation merchant must not fall through to them either: they
		// cannot see an unconfirmed payment, so they would report zero for an
		// order the merchant considers paid - and a zero observation is what
		// lets the expiry pass cancel. Wait for the primary source instead.
		if ((int) $requiredConfirmations === 1) {
			$secondaryResult = NMM_Blockchain::get_mempoolspace_total_received_for_btc_address($address);

			if ($secondaryResult['result'] === 'success') {
				return $secondaryResult['total_received'];
			}

			$fallbackResult = NMM_Blockchain::get_blockstream_total_received_for_btc_address($address);

			if ($fallbackResult['result'] === 'success') {
				return $fallbackResult['total_received'];
			}
		}

		throw new \Exception("Unable to get BTC HD address information from external sources.");
	}

	private static function get_total_received_for_litecoin_address($address, $requiredConfirmations) {
		$primaryResult = NMM_Blockchain::get_blockcypher_total_received_for_ltc_address($address, $requiredConfirmations);

		if ($primaryResult['result'] === 'success') {
			return $primaryResult['total_received'];
		}

		// litecoinspace's address summary reports confirmed (>=1 conf) totals
		// only and cannot express "N confirmations"; only use it when the
		// requirement is EXACTLY one confirmation (see the BTC note above - a
		// zero-confirmation requirement must not fall through to a source that
		// cannot see unconfirmed payments).
		if ((int) $requiredConfirmations === 1) {
			$secondaryResult = NMM_Blockchain::get_litecoinspace_total_received_for_ltc_address($address);

			if ($secondaryResult['result'] === 'success') {
				return $secondaryResult['total_received'];
			}
		}

		throw new \Exception("Unable to get LTC HD address information from external sources.");
	}

	private static function get_total_received_for_qtum_address($address) {
		$result = NMM_Blockchain::get_qtuminfo_total_received_for_qtum_address($address);

		if ($result['result'] === 'success') {
			return $result['total_received'];
		}		

		throw new \Exception("Unable to get QTUM HD address information from external sources.");
	}

	private static function get_total_received_for_dash_address($address) {
		$result = NMM_Blockchain::get_dashblockexplorer_total_received_for_dash_address($address);

		if ($result['result'] === 'success') {
			return $result['total_received'];
		}		

		throw new \Exception("Unable to get DASH HD address information from external sources.");
	}

	private static function get_total_received_for_doge_address($address) {
		$result = NMM_Blockchain::get_blockcypher_total_received_for_doge_address($address);

		if ($result['result'] === 'success') {
			return $result['total_received'];
		}		

		throw new \Exception("Unable to get DOGE HD address information from external sources.");
	}

	private static function get_total_received_for_xmy_address($address) {
		$result = NMM_Blockchain::get_blockbook_total_received_for_xmy_address($address);

		if ($result['result'] === 'success') {
			return $result['total_received'];
		}		

		throw new \Exception("Unable to get XMY HD address information from external sources.");
	}
	
	private static function get_total_received_for_bitcore_address($address) {
		$result = NMM_Blockchain::get_chainz_total_received_for_btx_address($address);

		if ($result['result'] === 'success') {
			return $result['total_received'];
		}		

		throw new \Exception("Unable to get XMY HD address information from external sources.");
	}

	public static function cancel_expired_addresses($cryptoId, $mpk, $orderCancellationTimeSec, $hdMode, $requiredConfirmations = 1) {
		global $woocommerce;
		$hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);

		$assignedRecords = $hdRepo->get_reconcilable();

		foreach ($assignedRecords as $record) {
			
			$assignedAt = $record['assigned_at'];
			$totalReceived = $record['total_received'];
			$address = $record['address'];
			$orderId = $record['order_id'];

			// Reconcile the address against the live order instead of leaving it
			// stuck in 'assigned'. A HD address is only ever handed to one order,
			// so once that order is dead the address must leave the assigned
			// state. But we must not simply retire every one of them: on
			// Electrum-backed HD wallets, a long run of retired-but-never-paid
			// addresses (abandoned checkouts) pushes a later paid address beyond
			// the wallet's gap limit, where it is not automatically discovered
			// from the seed. So a dead order whose cached total_received is zero
			// goes to QUARANTINE - verified by fresh explorer checks over time
			// before it may be recycled - and only an address that actually
			// received funds is retired ('dirty') outright. See
			// process_quarantined_addresses().
			$order = $orderId ? wc_get_order($orderId) : false;

			if (!$order) {
				// Order deleted - quarantine (or retire if it already saw funds).
				self::quarantine_or_retire_hd_address($hdRepo, $address, $totalReceived, 'order deleted');
				continue;
			}

			if ($order->is_paid()) {
				// Paid, possibly out-of-band (e.g. the merchant verified during
				// an explorer outage so the verifier never marked it complete).
				$hdRepo->set_status($address, 'complete');
				continue;
			}

			if (!self::order_awaits_payment($order)) {
				// Any state that is neither paid nor awaiting payment - cancelled,
				// failed, refunded, or a custom terminal status - means this address
				// is done. Retire it (dirty if it saw funds, else quarantine for
				// fresh-check verification). Leaving 'refunded' and the like here was
				// a leak: the verifier's payable gate rejects them too, so the row
				// would otherwise be swept forever and never retired. A custom
				// payable status declared via WooCommerce's filter counts as
				// awaiting (see order_awaits_payment) and is protected.
				self::quarantine_or_retire_hd_address($hdRepo, $address, $totalReceived, 'order ' . $order->get_status());
				continue;
			}

			$assignedFor = time() - $assignedAt;
			NMM_Util::log(__FILE__, __LINE__, 'address ' . $address . ' has been assigned for ' . $assignedFor . '... cancel time: ' . $orderCancellationTimeSec);
			if ($assignedFor > $orderCancellationTimeSec && $totalReceived == 0) {
				// The row's cached balance is zero and its window has passed. Before
				// cancelling, confirm a zero balance against a FRESH observation: a
				// payment may have landed since the last verifier check, or the
				// verifier may have failed to record one. Cancelling a funded order
				// is the one thing this pass must never do.
				//
				// The verifier ran moments ago in this same process and already
				// fetched this address's balance - reuse that observation while it
				// is still fresh (OBSERVATION_MAX_AGE_SEC). It costs no extra
				// explorer call (a large abandonment backlog would double every
				// call otherwise) and sidesteps per-host cooldowns (chainz: the
				// verifier's own call starts the cooldown, so a second call here
				// would be refused every cycle and the order never cancelled). An
				// observation older than the age bound - a huge backlog between
				// the verifier touching this address and this pass reaching it -
				// is NOT trusted for a cancellation: a payment could have landed
				// in that window, so we fetch fresh instead (safe from the
				// cooldown too: any short per-host cooldown lapsed long ago).
				$observedKey = self::observation_key($cryptoId, $mpk, $hdMode, $address);
				$observation = isset(self::$observedTotals[$observedKey]) ? self::$observedTotals[$observedKey] : null;

				if ($observation !== null && (time() - $observation['at']) <= self::OBSERVATION_MAX_AGE_SEC) {
					$freshTotal = $observation['total'];
				}
				else {
					try {
						$freshTotal = self::get_total_received_for_address($cryptoId, $address, $requiredConfirmations);
					}
					catch ( \Exception $e ) {
						// Could not confirm a zero balance (explorer down). Do NOT
						// cancel on an unverified assumption; try again next cycle.
						NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: could not re-check ' . $cryptoId . ' address ' . $address . ' before expiry; leaving the order for the next cycle.', 'warning');
						continue;
					}
				}

				if ($freshTotal > 0) {
					// Funds are present after all. Record them and let the verifier
					// handle the order; never cancel it.
					$hdRepo->set_total_received($address, $freshTotal);
					NMM_Util::log(__FILE__, __LINE__, 'Privacy Mode: ' . $cryptoId . ' address ' . $address . ' has an on-chain balance at expiry time; not cancelling order ' . $orderId . '.', 'warning');
					continue;
				}

				// Cancel the unpaid, expired order and quarantine its address for
				// fresh-check verification before any reuse.
				self::quarantine_or_retire_hd_address($hdRepo, $address, $totalReceived, 'expired unpaid');

				$orderNote = sprintf(
					/* translators: 1: cryptocurrency ticker, 2: number of hours */
					__('Your %1$s order was <strong>cancelled</strong> because you were unable to pay for %2$s hour(s). Please do not send any funds to the payment address.', 'nomiddleman-crypto-payments-for-woocommerce'),
					$cryptoId,
					round($orderCancellationTimeSec/3600, 1));

				add_filter('woocommerce_email_subject_customer_note', 'NMM_change_cancelled_email_note_subject_line', 1, 2);
	    		add_filter('woocommerce_email_heading_customer_note', 'NMM_change_cancelled_email_heading', 1, 2);
				
				$order->update_status('wc-cancelled');				
				$order->add_order_note($orderNote, true);
				
				NMM_Util::log(__FILE__, __LINE__, 'Cancelled order: ' . $orderId . ' which was using address: ' . $address . 'due to non-payment.');
			}
		}
	}

	/**
	 * Handle a HD address whose order is dead. If the cached total_received is
	 * already non-zero the address definitely saw funds, so retire it ('dirty')
	 * - never reuse an address money touched. Otherwise the cached zero cannot
	 * be trusted (the explorer may have failed/lagged), so quarantine it: the
	 * cron re-verifies it with fresh explorer checks before it may return to the
	 * pool, which keeps pristine unused addresses reusable (so a run of
	 * abandoned checkouts does not blow the wallet's gap limit) without ever
	 * recycling one that received a late payment.
	 */
	private static function quarantine_or_retire_hd_address($hdRepo, $address, $totalReceived, $reason) {
		if ((float) $totalReceived > 0) {
			$hdRepo->set_status($address, 'dirty');
			NMM_Util::log(__FILE__, __LINE__, 'Retiring HD address ' . $address . ' (dirty): ' . $reason . ', received ' . $totalReceived . '.');
		}
		else {
			$hdRepo->set_quarantine($address, 'quarantine', time());
			NMM_Util::log(__FILE__, __LINE__, 'Quarantining HD address ' . $address . ': ' . $reason . ' (fresh explorer checks will decide reuse).');
		}
	}

	/**
	 * Verify quarantined addresses with fresh explorer checks, spaced in time,
	 * before deciding their fate. Requires two successful clean checks
	 * ('quarantine' -> 'quarantine_verified' -> 'ready') so a pristine unused
	 * address is returned to the pool (preserving the wallet gap limit), while
	 * any address that turns out to have received funds is retired and any that
	 * cannot be verified stays quarantined.
	 */
	public static function process_quarantined_addresses($cryptoId, $mpk, $requiredConfirmations, $hdMode, $quarantinePeriodSec, $batchLimit = 25) {
		$hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);

		foreach ($hdRepo->get_quarantined($batchLimit) as $record) {
			$address = $record['address'];
			$status = $record['status'];
			$lastChecked = (int) $record['last_checked'];

			// Space fresh checks apart in time (and past the payment expiry).
			if (time() - $lastChecked < $quarantinePeriodSec) {
				continue;
			}

			try {
				$freshReceived = self::get_total_received_for_address($cryptoId, $address, $requiredConfirmations);
			}
			catch (\Exception $e) {
				$freshReceived = null; // explorer failure
			}

			if ($freshReceived === null) {
				// Could not verify (explorer down, or coin has no checker) - keep
				// it quarantined and try again after another interval.
				$hdRepo->set_quarantine($address, $status, time());
				continue;
			}

			if ($freshReceived > 0) {
				// It received funds after all - retire permanently, never reuse.
				$hdRepo->set_status($address, 'dirty');
				NMM_Util::log(__FILE__, __LINE__, 'Quarantine: retiring ' . $address . ' (fresh check found ' . $freshReceived . ' received).');
				continue;
			}

			// A clean fresh check. Require a second, later one before reuse.
			if ($status === 'quarantine') {
				$hdRepo->set_quarantine($address, 'quarantine_verified', time());
			}
			else {
				// One atomic, status-guarded UPDATE so a checkout that claims the
				// address the instant it becomes ready cannot have its new order
				// amount clobbered by a second write.
				$hdRepo->recycle_quarantined($address);
				NMM_Util::log(__FILE__, __LINE__, 'Quarantine: recycling pristine unused address ' . $address . ' after two clean fresh checks.');
			}
		}
	}

	private static function is_dirty_address($cryptoId, $address) {
		if ($cryptoId === 'BTC') {
			return self::is_dirty_btc_address($address);
		}
		if ($cryptoId === 'LTC') {
			return self::is_dirty_ltc_address($address);
		}
		if ($cryptoId === 'QTUM') {
			return self::is_dirty_qtum_address($address);	
		}
		if ($cryptoId === 'DASH') {
			return self::is_dirty_dash_address($address);	
		}
		if ($cryptoId === 'DOGE') {
			return self::is_dirty_doge_address($address);	
		}
		if ($cryptoId === 'XMY') {
			return self::is_dirty_xmy_address($address);
		}
		if ($cryptoId === 'BTX') {
			return self::is_dirty_btx_address($address);
		}
	}

	private static function is_dirty_btc_address($address) {
		$primaryResult = NMM_Blockchain::get_blockchaininfo_total_received_for_btc_address($address, 0);

		if ($primaryResult['result'] === 'success') {
			// if we get a non zero balance from first source then address is dirty
			if ($primaryResult['total_received'] >= 0.00000001) {				
				return true;
			}
			else {
				$secondaryResult = NMM_Blockchain::get_mempoolspace_total_received_for_btc_address($address);

				// we have a primary resource saying address is clean and backup source failed, so return clean
				if ($secondaryResult['result'] === 'error') {					
					return false;
				}
				// backup source gave us data
				else {
					// primary source is clean but if we see a balance we return dirty
					if ($secondaryResult['total_received'] >= 0.00000001) {
						return true;
					}
					// both sources return clean
					else {
						return false;
					}
				}
			}
		}
		else {
			$secondaryResult = NMM_Blockchain::get_mempoolspace_total_received_for_btc_address($address);

			if ($secondaryResult['result'] === 'success') {
				return $secondaryResult['total_received'] >= 0.00000001;
			}
		}

		$fallbackResult = NMM_Blockchain::get_blockstream_total_received_for_btc_address($address);
		if ($fallbackResult['result'] === 'success') {
				return $fallbackResult['total_received'] >= 0.00000001;
			}
		throw new \Exception("Unable to get BTC address total amount received to verify is address is unused.");
	}

	private static function is_dirty_ltc_address($address) {
		$primaryResult = NMM_Blockchain::get_litecoinspace_total_received_for_ltc_address($address);		

		if ($primaryResult['result'] === 'success') {
			// if we get a non zero balance from first source then address is dirty
			if ($primaryResult['total_received'] >= 0.00000001) {				
				return true;
			}
			else {
				$secondaryResult = NMM_Blockchain::get_blockcypher_total_received_for_ltc_address($address, 0);

				// we have a primary resource saying address is clean and backup source failed, so return clean
				if ($secondaryResult['result'] === 'error') {					
					return false;
				}
				// backup source gave us data
				else {
					// primary source is clean but if we see a balance we return dirty
					if ($secondaryResult['total_received'] >= 0.00000001) {
						return true;
					}
					// both sources return clean
					else {
						return false;
					}
				}
			}
		}
		else {
			$secondaryResult = NMM_Blockchain::get_blockcypher_total_received_for_ltc_address($address, 0);

			if ($secondaryResult['result'] === 'success') {
				return $secondaryResult['total_received'] >= 0.00000001;
			}
		}
		
		throw new \Exception("Unable to get LTC address total amount received to verify is address is unused.");
	}

	private static function is_dirty_qtum_address($address) {
		return self::get_total_received_for_qtum_address($address) >= 0.00000001;
	}
	
	private static function is_dirty_dash_address($address) {
		return self::get_total_received_for_dash_address($address) >= 0.00000001;
	}

	private static function is_dirty_doge_address($address) {
		return self::get_total_received_for_doge_address($address) >= 0.00000001;
	}

	private static function is_dirty_xmy_address($address) {
		return self::get_total_received_for_xmy_address($address) >= 0.00000001;
	}
	
	private static function is_dirty_btx_address($address) {
		return self::get_total_received_for_bitcore_address($address) >= 0.00000001;
	}
	
	public static function force_new_address($cryptoId, $mpk, $hdMode) {
		
		$hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);

		$startIndex = $hdRepo->get_next_index();	

		$address = self::create_hd_address($cryptoId, $mpk, $startIndex, $hdMode);

		// Skip past already-used ("dirty") addresses, but never loop unbounded:
		// each check makes a block-explorer request, so an explorer that is
		// rate-limiting us or wrongly reports every address as used would spin
		// forever - previously set_time_limit() was reset on every iteration, so
		// PHP could never kill it, which pinned the CPU. Cap at a BIP44-style
		// gap limit and abort this buffer-fill cycle if it is exceeded.
		$maxDirtySkips = 20;

		try {
			$skips = 0;
			while (self::is_dirty_address($cryptoId, $address)) {

				if ($skips >= $maxDirtySkips) {
					throw new \Exception('Exceeded the dirty-address gap limit for ' . $cryptoId . ' (explorer may be unavailable); aborting this buffer-fill cycle.');
				}

				$hdRepo->insert($address, $startIndex, 'dirty');
				$startIndex = $startIndex + 1;
				$address = self::create_hd_address($cryptoId, $mpk, $startIndex, $hdMode);
				$skips++;
			}
		}
		catch ( \Exception $e ) {
			NMM_Util::log(__FILE__, __LINE__, 'Could not create new addresses: ' . $e->getMessage());
			throw new \Exception(esc_html($e->getMessage()));
		}

		$hdRepo->insert($address, $startIndex, 'ready');
	}

	public static function create_hd_address($cryptoId, $mpk, $index, $hdMode) {
		
		try {
			if (!NMM_Util::p_enabled()) {
				if (self::is_valid_xpub($mpk)) {
					return HdHelper::mpk_to_bc_address($cryptoId, $mpk, $index, 2, false);
				}
			}
			else {
				if (self::is_valid_mpk($cryptoId, $mpk)) {
					return apply_filters('nmm_get_hd_address', $cryptoId, $mpk, $index, $hdMode);
				}
			}
		}
		catch (\Exception $e) {
			throw new \Exception('Invalid MPK for ' . esc_html($cryptoId) . '. ' . esc_html($e->getTraceAsString()));
		}
	}

	public static function is_valid_xpub($mpk) {
		$mpkStart = substr($mpk, 0, 5);
		$validMpk = strlen($mpk) == 111 && $mpkStart === 'xpub6';
		return $validMpk;
	}

	public static function is_valid_ypub($mpk) {
		$mpkStart = substr($mpk, 0, 5);
		$validMpk = strlen($mpk) == 111 && $mpkStart === 'ypub6';
		return $validMpk;
	}

	public static function is_valid_zpub($mpk) {
		$mpkStart = substr($mpk, 0, 5);
		$validMpk = strlen($mpk) == 111 && $mpkStart === 'zpub6';
		return $validMpk;
	}

	public static function is_valid_mpk($cryptoId, $mpk) {
		if ($cryptoId == 'BTC') {
			return self::is_valid_xpub($mpk) || self::is_valid_ypub($mpk) || self::is_valid_zpub($mpk);
		}
		if ($cryptoId === 'LTC') {
			return self::is_valid_xpub($mpk) || self::is_valid_ypub($mpk) || self::is_valid_zpub($mpk);
		}
		if ($cryptoId === 'QTUM') {
			return self::is_valid_xpub($mpk) || self::is_valid_ypub($mpk);
		}
		if ($cryptoId === 'DASH') {
			return self::is_valid_xpub($mpk);
		}
		if ($cryptoId === 'DOGE') {
			return self::is_valid_xpub($mpk);
		}
		if ($cryptoId === 'XMY') {
			return self::is_valid_xpub($mpk);	
		}	
		if ($cryptoId === 'BTX') {
			return self::is_valid_xpub($mpk);	
		}
	}
}

?>