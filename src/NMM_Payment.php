<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Payment {

	public static function check_all_addresses_for_matching_payment($transactionLifetime) {		
		$paymentRepo = new NMM_Payment_Repo();

		// get a unique list of unpaid "payments" to crypto addresses
		$addressesToCheck = $paymentRepo->get_distinct_unpaid_addresses();

		$cryptos = NMM_Cryptocurrencies::get();

		foreach ($addressesToCheck as $record) {
			$address = $record['address'];

			$cryptoId = $record['cryptocurrency'];
			$crypto = $cryptos[$cryptoId];

			self::check_address_transactions_for_matching_payments($crypto, $address, $transactionLifetime);
		}
	}

	private static function check_address_transactions_for_matching_payments($crypto, $address, $transactionLifetime) {
		$cryptoId = $crypto->get_id();

		NMM_Util::log(__FILE__, __LINE__, '===========================================================================');
		NMM_Util::log(__FILE__, __LINE__, 'Starting payment verification for: ' . $cryptoId . ' - ' . $address);

		try {
			$transactions = self::get_address_transactions($cryptoId, $address, $transactionLifetime);
		}
		catch (\Exception $e) {
			NMM_Util::log(__FILE__, __LINE__, 'Unable to get transactions for ' . $cryptoId);
			return;
		}

		NMM_Util::log(__FILE__, __LINE__, 'Transcations found for ' . $cryptoId . ' - ' . $address . ': ' . print_r($transactions, true));

		self::process_address_transactions($crypto, $address, $transactions, $transactionLifetime);
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
				// go through the same conditional update, exactly one wins. If we
				// lose, the row was already cancelled/paid out from under us - do
				// NOT complete the order (that would split-brain the order against
				// its payment record).
				if (!$paymentRepo->claim_for_payment($orderId, $orderAmount)) {
					// Crucially, still consume the tx. This address (a static
					// address or a carousel seat) will be reused, and an unconsumed
					// tx that is still inside the matching window could otherwise be
					// matched against a *new* order of the same amount and
					// misattribute the payment. Record the hash on the cancelled row
					// too, for manual reconciliation.
					$nmmSettings->add_consumed_tx($cryptoId, $address, $txHash);
					$paymentRepo->set_hash_on_cancelled($orderId, $orderAmount, $txHash);
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: verified ' . $cryptoId . ' payment for order ' . $orderId . ' but its record was already transitioned (likely expired and cancelled) - not completing the order; recorded the transaction as consumed to prevent reuse on a recycled address. Transaction Hash: ' . $txHash . '. Please reconcile manually.', 'warning');
					continue;
				}

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

		// Single clock for the whole pass, shared by the coarse SQL cutoff below
		// and the exact per-record check, so the two can never disagree by a
		// second at a boundary.
		$now = time();

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
		foreach ($unpaidCryptos as $unpaidCryptoId) {
			$windowSec = (float) $nmmSettings->get_autopay_cancellation_time($unpaidCryptoId) * 60 * 60;
			if ($shortestWindowSec === null || $windowSec < $shortestWindowSec) {
				$shortestWindowSec = $windowSec;
			}
		}

		$coarseCutoff = $now - $shortestWindowSec;
		$unpaidPayments = $paymentRepo->get_unpaid($coarseCutoff);

		foreach ($unpaidPayments as $paymentRecord) {
			$orderTime = $paymentRecord['ordered_at'];
			$cryptoId = $paymentRecord['cryptocurrency'];

			$paymentCancellationTimeHr = $nmmSettings->get_autopay_cancellation_time($cryptoId);
			$paymentCancellationTimeSec = $paymentCancellationTimeHr * 60 * 60;
			$timeSinceOrder = $now - $orderTime;
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
				// only while it is still 'unpaid'. If the claim affects zero rows the
				// verifier already took the row and we must not cancel the order.
				if (!$paymentRepo->claim_for_cancellation($orderId, $orderAmount)) {
					NMM_Util::log(__FILE__, __LINE__, 'Autopay: cancellation claim lost for order ' . $orderId . '; another worker transitioned it first. Not cancelling.');
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