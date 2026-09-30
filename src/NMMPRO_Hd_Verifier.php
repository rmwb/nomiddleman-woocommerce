<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy Mode (HD) payment verification and expiry, from attributable
 * transaction evidence.
 *
 * An order is paid only by incoming outputs to its own address that
 *   - arrived after the address was bound to it (block time at or after the
 *     binding, or first seen unconfirmed by this plugin after the address was
 *     proven clean),
 *   - have at least the merchant's confirmations (and never fewer than one),
 *   - are not owned by another order in the ledger shared with Autopay,
 * summed exactly, and only when the scan behind them covered everything back
 * to the binding. Lifetime receipts and balances never decide anything: an
 * old 3800 DOGE receipt plus a new 10 DOGE payment credits 10.
 *
 * Scans resume from saved progress to stay cheap, but coverage resumed that
 * way is advisory: the two irreversible decisions - claiming a payment and
 * cancelling an order - are taken only on a fresh scan made from scratch in
 * one call.
 *
 * Completion is claimed first - transaction ownership, credited evidence and
 * the 'completing' state in one InnoDB transaction - after re-validating the
 * evidence with a fresh full scan, and WooCommerce is asked to complete the
 * order only after that commit. A failure anywhere after the claim leaves a
 * recoverable 'completing' row that this same pass resumes for the same
 * order; the funds are never released for another order.
 *
 * Every per-address step runs under the per-address match lock shared with
 * Autopay; when it cannot be taken the address waits for the next tick.
 */
class NMMPRO_Hd_Verifier {

	// A scan result the verifier obtained this cron cycle may be reused by the
	// expiry pass for this long (one explorer call per address per cycle, not
	// two). Only complete results are reused.
	const SCAN_CACHE_SEC = 120;

	// The fresh re-validation before a claim reads the whole history since the
	// binding in one go, with this page budget. An address with more history
	// than that (a flood of dust sent to it) is held for review, not left to
	// retry forever.
	const REVALIDATE_MAX_PAGES = 20;

	// The checkout binds the address, then records it on the order. Until the
	// order carries its address the row is left alone, for up to this long
	// after the binding; an order that still has none by then is a checkout
	// that failed after binding, and a human decides.
	const BINDING_GRACE_SEC = 900;

	// An address that cannot be read completely for this long, for any reason,
	// is reported on its order (once) and in the log. Retrying continues: an
	// explorer outage is late, never wrong, but never silent.
	const INCOMPLETE_NOTICE_SEC = 86400;

	// Explorer work per pass (verification, expiry, monitoring) per wallet in
	// a cron cycle (filter nmmpro_hd_pass_budget_sec). Each pass of each
	// wallet has its own budget, so a busy wallet never starves another or a
	// later pass. The budget is checked between addresses: one address's
	// work (at most one fresh scan of REVALIDATE_MAX_PAGES pages) is never
	// cut short, so a pass can run over it by that much.
	const PASS_BUDGET_SEC = 30;

	// Each pass of each wallet carries on where it stopped last cycle,
	// round-robin by row id (option CURSOR_OPTION_PREFIX . pass . '_' . wallet
	// hash; one option each, so passes and wallets never overwrite each
	// other's progress). Every attempt moves the position on - a busy
	// address, a failure, an exception - so no row, and no other pass's
	// timestamps, can keep the rest of the list from being reached.
	const CURSOR_OPTION_PREFIX = 'nmmpro_hd_cursor_';

	// Order meta: every contributing output, and dedupe keys for notes.
	const TX_META = '_nmmpro_hd_transactions';
	const NOTED_META = '_nmmpro_hd_noted';

	private static $scanCache = array();

	/** A new cron cycle: forget the previous cycle's scans. */
	public static function reset_cycle() {
		self::$scanCache = array();
	}

	private static function cursor_option($pass, $cryptoId, $mpk, $hdMode) {
		return self::CURSOR_OPTION_PREFIX . $pass . '_' . md5($cryptoId . '|' . $mpk . '|' . $hdMode);
	}

	/** $rows by id, those after the saved position first, then from the start. */
	private static function in_turn($rows, $option) {
		$after = (int) get_option($option, 0);
		usort($rows, function ($a, $b) { return (int) $a['id'] <=> (int) $b['id']; });
		$first = array();
		$then = array();
		foreach ($rows as $row) {
			if ((int) $row['id'] > $after) { $first[] = $row; } else { $then[] = $row; }
		}
		return array_merge($first, $then);
	}

	private static function save_turn($option, $lastId) {
		if ($lastId !== null) {
			update_option($option, (int) $lastId, false);
		}
	}

	/**
	 * Whether this pass's explorer budget (from $started) is spent. The
	 * remaining addresses are first in the same pass next cycle.
	 */
	private static function over_budget($started, $pass, $cryptoId) {
		$budget = (float) NMMPRO_Compat::filter('nmmpro_hd_pass_budget_sec', self::PASS_BUDGET_SEC);
		if (microtime(true) - $started <= $budget) {
			return false;
		}
		NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: the ' . $cryptoId . ' ' . $pass . ' pass used its explorer budget (' . $budget . 's); it continues next cycle with the addresses attempted least recently.', 'warning');
		return true;
	}

	// =====================================================================
	// Verification.
	// =====================================================================

	public static function verify_wallet($cryptoId, $mpk, $requiredConfirmations, $percentToVerify, $hdMode) {
		if (!NMMPRO_Hd::automatic_available($cryptoId)) {
			return;
		}
		$repo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		$rows = $repo->open_assignments();
		if ($rows === false) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: could not read ' . $cryptoId . ' assignments; skipping verification this cycle.', 'error');
			return;
		}

		$turn = self::cursor_option('verify', $cryptoId, $mpk, $hdMode);
		$rows = self::in_turn($rows, $turn);
		$started = microtime(true);
		$last = null;
		foreach ($rows as $row) {
			if (self::over_budget($started, 'verification', $cryptoId)) {
				break;
			}
			$repo->touch_checked((int) $row['id'], (int) $row['order_id']);
			try {
				self::verify_row($repo, $cryptoId, $row, max(1, (int) $requiredConfirmations), (string) $percentToVerify);
			}
			catch (\Throwable $t) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: verifying ' . $cryptoId . ' address ' . $row['address'] . ' raised: ' . $t->getMessage(), 'error');
			}
			$last = (int) $row['id'];
		}
		self::save_turn($turn, $last);
	}

	private static function verify_row($repo, $cryptoId, $row, $requiredConfirmations, $percentToVerify) {
		$id = (int) $row['id'];
		$orderId = (int) $row['order_id'];
		$address = (string) $row['address'];

		$read = NMMPRO_Payment::read_order_authoritatively($orderId);
		if ($read['state'] === 'error') {
			return; // unknown is not deleted: try again next cycle
		}

		if (NMMPRO_Util::acquire_address_match_lock($cryptoId, $address) !== '1') {
			return; // another worker has the address, or locking is unavailable: defer
		}

		try {
			$row = $repo->row($id);
			if (!is_array($row) || !in_array($row['status'], array('assigned', 'underpaid', 'completing'), true) || (int) $row['order_id'] !== $orderId) {
				return;
			}

			$order = $read['order'];
			if ($order !== null && !self::binding_matches($order, $cryptoId, $address)) {
				if ((string) $order->get_meta('wallet_address') === '' && time() - (int) $row['bound_at'] < self::BINDING_GRACE_SEC) {
					return; // the checkout has not recorded the address yet
				}
				$repo->transition($id, $orderId, array('assigned', 'underpaid', 'completing'), 'review', 'binding_mismatch');
				self::note_once($order, 'binding_mismatch', sprintf(
					/* translators: 1: wallet address */
					__('Privacy Mode: this order no longer matches its payment address %1$s (payment method, currency or address changed). Automatic verification has stopped; please reconcile this order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
					$address));
				return;
			}

			$scan = self::scan($cryptoId, $address, $row, false);
			if ($scan === null) {
				return;
			}
			$precision = NMMPRO_Cryptocurrencies::get()[$cryptoId]->get_round_precision();
			$expectedUnits = NMMPRO_Amount::to_units($row['order_amount'], $precision);

			$assessment = self::assess($repo, $cryptoId, $address, $row, $orderId, $requiredConfirmations, $precision);
			if ($assessment === null) {
				return;
			}

			if ($order === null) {
				return; // deleted: the expiry pass retires the address; evidence is kept
			}

			if ($assessment['historical'] !== array()) {
				self::note_once($order, 'historical', sprintf(
					/* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address */
					__('Privacy Mode: %1$s %2$s received at %3$s before this order was placed was ignored. It cannot pay this order.', 'nomiddleman-crypto-payments-for-woocommerce'),
					NMMPRO_Amount::from_units(self::sum($assessment['historical']), $precision), $cryptoId, $address), false);
			}

			if ($order->is_paid()) {
				// Paid through another path, or a completion interrupted after
				// WooCommerce saved it: settle the row, attribute nothing new.
				$repo->transition($id, $orderId, array('assigned', 'underpaid', 'completing'), 'complete');
				return;
			}

			$eligibleUnits = $assessment['eligible_units'];

			if (!NMMPRO_Hd::order_awaits_payment($order)) {
				// Cancelled, failed, refunded: never resurrected. A completion in
				// flight for it goes back to an open state for the expiry pass
				// to retire; the funds stay recorded against this order.
				$repo->transition($id, $orderId, array('completing'), $eligibleUnits === '0' ? 'assigned' : 'underpaid');
				if ($eligibleUnits !== '0') {
					self::note_once($order, 'late:' . $eligibleUnits, sprintf(
						/* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address, 4: order status */
						__('Late payment of %1$s %2$s received at Privacy Mode address %3$s after this order became %4$s. The order has NOT been completed automatically - please reconcile this payment manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
						NMMPRO_Amount::from_units($eligibleUnits, $precision), $cryptoId, $address, $order->get_status()), false);
				}
				return;
			}

			if (!NMMPRO_Amount::clears($eligibleUnits, $expectedUnits, $percentToVerify)) {
				self::handle_underpayment($repo, $order, $row, $assessment, $expectedUnits, $precision, $cryptoId, $address);
				return;
			}

			// Enough, on the saved scan. Re-validate with a fresh, full scan
			// before anything is claimed: every contributing output must still
			// be on chain, confirmed, and sufficient.
			$fresh = self::scan($cryptoId, $address, $row, true);
			if ($fresh === null) {
				return;
			}
			$assessment = self::assess($repo, $cryptoId, $address, $row, $orderId, $requiredConfirmations, $precision);
			if ($assessment === null) {
				return;
			}
			$eligibleUnits = $assessment['eligible_units'];
			if (!NMMPRO_Amount::clears($eligibleUnits, $expectedUnits, $percentToVerify)) {
				self::handle_underpayment($repo, $order, $row, $assessment, $expectedUnits, $precision, $cryptoId, $address);
				return;
			}

			$claim = NMMPRO_Consumed_Repo::claim_hd($cryptoId, $address, $orderId, $id, $assessment['hashes'],
				array_map(function ($e) { return (int) $e['id']; }, $assessment['eligible']), $eligibleUnits);
			if ($claim !== NMMPRO_Hd_Repo::CLAIM_CLAIMED) {
				return; // rolled back, or no longer open: the next cycle decides again
			}

			self::complete($repo, $row, $orderId, $assessment, $precision, $cryptoId, $address);
		}
		finally {
			NMMPRO_Util::release_address_match_lock($cryptoId, $address);
		}
	}

	/**
	 * The order is still the one this address was issued to: this gateway,
	 * this coin, this address. The binding is written to the row before the
	 * order is given its address, so until the order carries the address it
	 * is not verified (never completed in that interval), and within
	 * BINDING_GRACE_SEC not held for review either.
	 */
	private static function binding_matches($order, $cryptoId, $address) {
		return $order->get_payment_method() === 'nmm_gateway'
			&& (string) $order->get_meta('crypto_type_id') === $cryptoId
			&& (string) $order->get_meta('wallet_address') === $address;
	}

	/**
	 * Scan the address and record what was seen. $fresh ignores saved
	 * progress (a full re-read, with its own larger page budget) and, on
	 * complete coverage, marks outputs no longer reported as vanished. Returns
	 * the scan when coverage is complete; null to defer (incomplete, a
	 * conflict, or a database failure). A fresh re-read that runs out of pages
	 * holds the row for review.
	 */
	private static function scan($cryptoId, $address, $row, $fresh) {
		$id = (int) $row['id'];
		$orderId = (int) $row['order_id'];
		$state = $fresh ? null : json_decode((string) $row['scan_state'], true);
		$result = NMMPRO_Hd_Evidence::scan($cryptoId, $address, (int) $row['bound_at'], is_array($state) ? $state : null,
			$fresh ? self::REVALIDATE_MAX_PAGES : NMMPRO_Hd_Evidence::DEFAULT_MAX_PAGES, self::floor_height($row));

		$recorded = NMMPRO_Hd_Evidence_Repo::record($id, $orderId, $cryptoId, $address, $result['source'], $result['outputs'], time());
		if ($recorded === NMMPRO_Hd_Evidence_Repo::RECORD_CONFLICT) {
			self::hold_for_review($cryptoId, $row, 'evidence_conflict');
			return null;
		}
		if ($recorded !== NMMPRO_Hd_Evidence_Repo::RECORD_OK) {
			return null;
		}
		if ($result['tip_height'] !== null && !NMMPRO_Hd_Evidence_Repo::refresh_confirmations($id, (int) $result['tip_height'])) {
			return null;
		}

		// Save the progress (or keep the old progress when the scan brought
		// none), with how long the address has been unreadable.
		$previous = json_decode((string) $row['scan_state'], true);
		$previous = is_array($previous) ? $previous : array();
		// (A complete scan always brings a state, so the marker goes with it.)
		$saved = is_array($result['state']) ? $result['state'] : $previous;
		$complete = $result['coverage'] === NMMPRO_Hd_Evidence::COMPLETE;
		$hadSince = isset($previous['incomplete_since']) && is_int($previous['incomplete_since']);
		$since = $hadSince ? $previous['incomplete_since'] : time();
		// Only a complete scan made from scratch ends an unreadable spell: an
		// advisory resumed success in between does not postpone the notice.
		if (!$complete || ($hadSince && !empty($result['resumed']))) {
			$saved['incomplete_since'] = $since;
		}
		$wallet = self::repo_for($cryptoId, $row);
		if ($wallet !== null) {
			$wallet->save_scan_state($id, $orderId, $saved);
		}

		if (!$complete) {
			if (!empty($result['unreadable'])) {
				// Asking again will not help: a human looks.
				self::hold_or_escalate($cryptoId, $row, 'history_unreadable', 'cannot be read completely (' . $result['reason'] . ')');
			}
			elseif ($fresh && !empty($result['exhausted'])) {
				self::hold_or_escalate($cryptoId, $row, 'history_too_large', 'has more history since its binding than one re-validation can read');
			}
			elseif (time() - $since >= self::INCOMPLETE_NOTICE_SEC) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: ' . $cryptoId . ' address ' . $address . ' (order ' . $orderId . ') has not been readable completely since ' . gmdate('Y-m-d H:i', $since) . ' UTC (' . $result['reason'] . '); still retrying.', 'warning');
				$read = NMMPRO_Payment::read_order_authoritatively($orderId);
				if ($read['state'] === 'ok') {
					self::note_once($read['order'], 'incomplete:' . $since, sprintf(
						/* translators: 1: wallet address, 2: date and time (UTC), 3: reason */
						__('Privacy Mode: the payments to %1$s could not be read completely from the blockchain explorers since %2$s UTC (%3$s). The order is neither completed nor cancelled until they can; it is still being retried. Please check the address on a block explorer if this persists.', 'nomiddleman-crypto-payments-for-woocommerce'),
						$address, gmdate('Y-m-d H:i', $since), $result['reason']));
				}
			}
			return null;
		}

		if ($fresh) {
			$seen = array();
			foreach ($result['outputs'] as $o) {
				$seen[] = $o['tx_hash'] . ':' . $o['output_index'];
			}
			if (!NMMPRO_Hd_Evidence_Repo::mark_vanished($id, $seen)) {
				return null;
			}
		}

		self::$scanCache[self::cache_key($cryptoId, $address)] = array('at' => time(), 'result' => $result);
		return $result;
	}

	/**
	 * Classify this assignment's recorded evidence (see evaluate()) with the
	 * current ownership of each transaction, record the attributable amount,
	 * and hold the row for review when the evidence cannot be attributed
	 * automatically. null means "stop here" (held, or a read failed).
	 */
	private static function assess($repo, $cryptoId, $address, $row, $orderId, $requiredConfirmations, $precision) {
		$rows = NMMPRO_Hd_Evidence_Repo::rows((int) $row['id']);
		if ($rows === false) {
			return null;
		}

		$hashes = array();
		foreach ($rows as $e) {
			$hashes[$e['tx_hash']] = true;
		}
		try {
			$owners = NMMPRO_Consumed_Repo::owners($cryptoId, $address, array_keys($hashes));
		}
		catch (\Throwable $t) {
			return null;
		}

		$assessment = self::evaluate($rows, (int) $row['bound_at'], (int) $row['validated_at'], $requiredConfirmations, $owners, $orderId, self::validated_height($row));

		$repo->save_amounts((int) $row['id'], $orderId, $assessment['eligible_units'], NMMPRO_Amount::from_units($assessment['observed_units'], $precision));

		foreach (array('conflict' => 'evidence_conflict', 'ambiguous' => 'timestamp_ambiguous', 'legacy' => 'legacy_owner') as $key => $reason) {
			if ($assessment[$key] !== array()) {
				self::hold_for_review($cryptoId, $row, $reason);
				return null;
			}
		}

		return $assessment;
	}

	/**
	 * Pure eligibility decision over recorded evidence rows. Public so the
	 * rules can be tested exactly.
	 *
	 *   eligible     confirmed enough, after the binding, unowned or owned by
	 *                this order - these pay the order
	 *   unconfirmed  after the binding but not yet confirmed enough (pending)
	 *   historical   confirmed well before the binding: contribute exactly zero
	 *   ambiguous    confirmed within the block-time slack before the binding
	 *                and not seen unconfirmed after the address was proven
	 *                clean, or in a block above the binding height whose time
	 *                says otherwise: a human decides
	 *   foreign      owned by another order (or Autopay): excluded
	 *   legacy       owned by an unknown earlier owner: blocks automatic use
	 *   conflict     disagreeing evidence
	 * Vanished outputs (left the chain) count for nothing.
	 *
	 * @param array<string, int|null> $owners tx hash => owning order id (0 = unknown), null = unclaimed
	 * @param int|null $validatedHeight chain height recorded at the binding (null for records bound without one)
	 */
	public static function evaluate($rows, $boundAt, $validatedAt, $requiredConfirmations, $owners, $orderId, $validatedHeight = null) {
		$out = array(
			'eligible' => array(), 'unconfirmed' => array(), 'historical' => array(), 'ambiguous' => array(),
			'foreign' => array(), 'legacy' => array(), 'conflict' => array(),
			'eligible_units' => '0', 'observed_units' => '0', 'hashes' => array(),
		);
		$required = max(1, (int) $requiredConfirmations);
		$hashes = array();

		foreach ($rows as $e) {
			if ($e['state'] === NMMPRO_Hd_Evidence_Repo::VANISHED) {
				continue;
			}
			$out['observed_units'] = NMMPRO_Amount::add($out['observed_units'], $e['amount_units']);
			if ($e['state'] === NMMPRO_Hd_Evidence_Repo::CONFLICT) {
				$out['conflict'][] = $e;
				continue;
			}

			$timing = self::timing($e, $boundAt, $validatedAt, $validatedHeight);
			if ($timing !== 'after') {
				$out[$timing][] = $e;
				continue;
			}

			$owner = array_key_exists($e['tx_hash'], $owners) ? $owners[$e['tx_hash']] : null;
			if ($owner !== null && $owner !== (int) $orderId) {
				$out[$owner === 0 ? 'legacy' : 'foreign'][] = $e;
				continue;
			}

			if ($e['block_time'] === null || $e['confirmations'] < $required) {
				$out['unconfirmed'][] = $e;
				continue;
			}

			$out['eligible'][] = $e;
			$out['eligible_units'] = NMMPRO_Amount::add($out['eligible_units'], $e['amount_units']);
			$hashes[$e['tx_hash']] = true;
		}

		$out['hashes'] = array_keys($hashes);
		return $out;
	}

	/**
	 * When an output arrived relative to the binding: 'after', 'ambiguous' or
	 * 'historical'. Pending outputs, and outputs confirmed at or after the
	 * binding or first seen pending after the clean check, are after. Block
	 * times are set by miners and are not monotonic, so an output in a block
	 * above the height recorded at the binding is never historical, whatever
	 * its time says: if its time says "before", a human decides.
	 */
	public static function timing($e, $boundAt, $validatedAt, $validatedHeight) {
		if ($e['block_time'] === null || $e['block_time'] >= $boundAt
			|| ($e['first_seen_pending'] && $e['first_seen_at'] >= $validatedAt)) {
			return 'after';
		}
		if ($validatedHeight !== null && $e['block_height'] !== null && $e['block_height'] > $validatedHeight) {
			return 'ambiguous';
		}
		if ($e['block_time'] >= $boundAt - NMMPRO_Hd_Evidence::TIME_SLACK_SEC) {
			return 'ambiguous';
		}
		return 'historical';
	}

	/** The chain height recorded when the row was bound, or null. */
	private static function validated_height($row) {
		return isset($row['validated_height']) ? (int) $row['validated_height'] : null;
	}

	/** The height a scan must reach for this row, or null to scan by time. */
	private static function floor_height($row) {
		$height = self::validated_height($row);
		return $height === null ? null : max(0, $height - NMMPRO_Hd_Evidence::HEIGHT_MARGIN);
	}

	private static function handle_underpayment($repo, $order, $row, $assessment, $expectedUnits, $precision, $cryptoId, $address) {
		$id = (int) $row['id'];
		$orderId = (int) $row['order_id'];
		$credited = $assessment['eligible_units'];
		$previous = $row['credited_units'] === null ? '0' : (string) $row['credited_units'];

		// A completion that no longer clears (a reorg took funds away) goes
		// back to an open state; nothing is released or refunded.
		if ($row['status'] === 'completing') {
			$repo->transition($id, $orderId, array('completing'), $credited === '0' ? 'assigned' : 'underpaid');
			return;
		}
		if ($credited === '0' || NMMPRO_Amount::compare($credited, $previous) <= 0) {
			return; // nothing new to tell the customer
		}

		$newUnits = NMMPRO_Amount::shortfall($credited, $previous);
		$remaining = NMMPRO_Amount::from_units(NMMPRO_Amount::shortfall($expectedUnits, $credited), $precision);

		add_filter('woocommerce_email_subject_customer_note', 'NMMPRO_change_partial_email_note_subject_line', 1, 2);
		add_filter('woocommerce_email_heading_customer_note', 'NMMPRO_change_partial_email_heading', 1, 2);

		if ($row['status'] === 'underpaid') {
			$order->add_order_note(sprintf(
				/* translators: 1: amount received, 2: cryptocurrency ticker, 3: remaining amount, 4: wallet address */
				__('New payment was received but is still under order total. Received payment of %1$s %2$s.<br>Remaining payment required: %3$s<br>Wallet Address: %4$s', 'nomiddleman-crypto-payments-for-woocommerce'),
				NMMPRO_Amount::from_units($newUnits, $precision), $cryptoId, $remaining, $address), 1);
		}
		else {
			$order->add_order_note(sprintf(
				/* translators: 1: amount received, 2: cryptocurrency ticker, 3: date/time, 4: remaining amount, 5: wallet address */
				__('Payment of %1$s %2$s received at %3$s. This is under the amount required to process this order.<br>Remaining payment required: %4$s<br>Wallet Address: %5$s', 'nomiddleman-crypto-payments-for-woocommerce'),
				NMMPRO_Amount::from_units($credited, $precision), $cryptoId, wp_date('m/d/Y g:i a'), $remaining, $address), 1);
			$repo->transition($id, $orderId, array('assigned'), 'underpaid');
		}
	}

	/**
	 * After the claim committed: complete the order, then settle the row. A
	 * failure leaves the claimed 'completing' row (and this order's ownership
	 * of the funds) for the next cycle to resume.
	 */
	private static function complete($repo, $row, $orderId, $assessment, $precision, $cryptoId, $address) {
		$id = (int) $row['id'];

		// Re-check the live order right before completing: it may have been
		// cancelled or paid while the evidence was being gathered.
		$read = NMMPRO_Payment::read_order_authoritatively($orderId);
		if ($read['state'] !== 'ok') {
			return; // unreadable or gone: the claim stays for the next cycle
		}
		$order = $read['order'];
		if ($order->is_paid()) {
			$repo->transition($id, $orderId, array('completing'), 'complete');
			return;
		}
		if (!NMMPRO_Hd::order_awaits_payment($order)) {
			$repo->transition($id, $orderId, array('completing'), 'underpaid');
			return;
		}

		$outputs = $assessment['eligible'];
		usort($outputs, function ($a, $b) { return array($a['block_time'], $a['tx_hash'], $a['output_index']) <=> array($b['block_time'], $b['tx_hash'], $b['output_index']); });
		$primaryTx = $outputs[0]['tx_hash'];

		try {
			$completed = $order->payment_complete($primaryTx);
		}
		catch (\Throwable $t) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: completing order ' . $orderId . ' raised: ' . $t->getMessage(), 'error');
			$completed = false;
		}

		// payment_complete()'s return value is not proof: for a status outside
		// its own allowlist it returns true without transitioning.
		$after = NMMPRO_Payment::read_order_authoritatively($orderId);
		if (!$completed || $after['state'] !== 'ok' || !$after['order']->is_paid()) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: order ' . $orderId . ' was not completed; its claim is kept for a retry. Please check the order if this repeats.', 'error');
			return;
		}
		$order = $after['order'];

		$identities = array();
		$latestTime = 0;
		$fewestConfirmations = null;
		foreach ($outputs as $o) {
			$identities[] = $o['tx_hash'] . ':' . $o['output_index'];
			$latestTime = max($latestTime, (int) $o['block_time']);
			$fewestConfirmations = $fewestConfirmations === null ? $o['confirmations'] : min($fewestConfirmations, $o['confirmations']);
		}
		$order->update_meta_data(self::TX_META, $identities);
		$order->save();
		$repo->transition($id, $orderId, array('completing'), 'complete');

		$order->add_order_note(sprintf(
			/* translators: 1: amount, 2: cryptocurrency ticker, 3: transaction outputs, 4: confirmations, 5: block time (UTC), 6: verification time */
			__('Privacy Mode payment of %1$s %2$s verified from %3$s, with at least %4$d confirmation(s); last confirmed in a block at %5$s UTC. Verified at %6$s.', 'nomiddleman-crypto-payments-for-woocommerce'),
			NMMPRO_Amount::from_units($assessment['eligible_units'], $precision), $cryptoId, implode(', ', $identities),
			(int) $fewestConfirmations, gmdate('Y-m-d H:i:s', $latestTime), wp_date('Y-m-d H:i:s')));
	}

	// =====================================================================
	// Expiry.
	// =====================================================================

	/**
	 * Reconcile open assignments with their orders, and cancel an expired
	 * order only on a COMPLETE scan made from scratch showing nothing at all
	 * has arrived since the binding - confirmed or pending, any amount,
	 * attributable or not. An incomplete or resumed scan, a failed request or
	 * a busy address never cancels.
	 * An expired order that received part of its payment is held for review,
	 * not cancelled; its address is never reused either way.
	 */
	public static function expire_wallet($cryptoId, $mpk, $orderCancellationTimeSec, $hdMode) {
		if (!NMMPRO_Hd::automatic_available($cryptoId)) {
			return;
		}
		$repo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		$rows = $repo->open_assignments();
		if ($rows === false) {
			return;
		}

		// Round-robin by row id, starting after where the last pass stopped:
		// expired orders it cannot cancel (something arrived) are re-scanned
		// every pass and must not keep the rest from being reached.
		$turn = self::cursor_option('expiry', $cryptoId, $mpk, $hdMode);
		$rows = self::in_turn($rows, $turn);

		$started = microtime(true);
		$last = null;
		foreach ($rows as $row) {
			if (self::over_budget($started, 'expiry', $cryptoId)) {
				break;
			}
			try {
				self::expire_row($repo, $cryptoId, $row, (int) $orderCancellationTimeSec);
			}
			catch (\Throwable $t) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: reconciling ' . $cryptoId . ' address ' . $row['address'] . ' raised: ' . $t->getMessage(), 'error');
			}
			$last = (int) $row['id'];
		}
		self::save_turn($turn, $last);
	}

	private static function expire_row($repo, $cryptoId, $row, $cancelSec) {
		$id = (int) $row['id'];
		$orderId = (int) $row['order_id'];
		$address = (string) $row['address'];
		$open = array('assigned', 'underpaid', 'completing');

		if ($row['status'] === 'completing') {
			return; // a completion is in flight or recovering: never touched here
		}

		$read = NMMPRO_Payment::read_order_authoritatively($orderId);
		if ($read['state'] === 'error') {
			return;
		}
		if ($read['state'] === 'absent') {
			$repo->transition($id, $orderId, $open, 'retired', 'order_deleted');
			return;
		}
		$order = $read['order'];
		if ($order->is_paid()) {
			$repo->transition($id, $orderId, $open, 'complete');
			return;
		}
		if (!NMMPRO_Hd::order_awaits_payment($order)) {
			$repo->transition($id, $orderId, $open, 'retired', 'order_' . $order->get_status());
			return;
		}

		if (time() - (int) $row['assigned_at'] <= $cancelSec) {
			return;
		}

		if ($row['status'] === 'underpaid' || ($row['credited_units'] !== null && $row['credited_units'] !== '0')) {
			// Part of the payment arrived. Keep the order for the merchant.
			if ($repo->transition($id, $orderId, array('underpaid', 'assigned'), 'review', 'expired_underpaid')) {
				self::note_once($order, 'expired_underpaid', __('Privacy Mode: this order\'s payment window has passed with only part of the payment received. It has not been cancelled; please reconcile it manually.', 'nomiddleman-crypto-payments-for-woocommerce'), false);
			}
			return;
		}

		if (NMMPRO_Util::acquire_address_match_lock($cryptoId, $address) !== '1') {
			return;
		}
		try {
			$row = $repo->row($id);
			if (!is_array($row) || $row['status'] !== 'assigned') {
				return;
			}

			// Cancelling is irreversible, so it is decided only on coverage made
			// from scratch in one call (this cycle's, or a fresh scan now), never
			// on progress resumed from earlier scans. A history too large for
			// one fresh scan is held for review instead.
			$key = self::cache_key($cryptoId, $address);
			$cached = (isset(self::$scanCache[$key]) && time() - self::$scanCache[$key]['at'] <= self::SCAN_CACHE_SEC
				&& empty(self::$scanCache[$key]['result']['resumed'])) ? self::$scanCache[$key]['result'] : null;
			$scan = $cached !== null ? $cached : self::scan($cryptoId, $address, $row, true);
			if ($scan === null || $scan['coverage'] !== NMMPRO_Hd_Evidence::COMPLETE || !empty($scan['resumed'])) {
				return; // no complete picture: never cancel on an assumption
			}

			$evidence = NMMPRO_Hd_Evidence_Repo::rows($id);
			if ($evidence === false) {
				return;
			}
			$anything = $scan['pending'];
			foreach ($evidence as $e) {
				if ($e['state'] !== NMMPRO_Hd_Evidence_Repo::VANISHED
					&& self::timing($e, (int) $row['bound_at'], (int) $row['validated_at'], self::validated_height($row)) !== 'historical') {
					$anything = true;
				}
			}
			if ($anything) {
				return; // something arrived: the verifier decides, expiry never cancels over it
			}

			// The chain was read after the order was: read the order again, past
			// every cache, and cancel only what is still unpaid and awaiting
			// payment - never the copy from before the scan.
			$read = NMMPRO_Payment::read_order_authoritatively($orderId);
			if ($read['state'] !== 'ok') {
				return;
			}
			$order = $read['order'];
			if ($order->is_paid() || !NMMPRO_Hd::order_awaits_payment($order)) {
				return; // changed meanwhile: the next pass reconciles the row with it
			}

			if (!$repo->transition($id, $orderId, array('assigned'), 'retired', 'expired_unpaid')) {
				return;
			}

			add_filter('woocommerce_email_subject_customer_note', 'NMMPRO_change_cancelled_email_note_subject_line', 1, 2);
			add_filter('woocommerce_email_heading_customer_note', 'NMMPRO_change_cancelled_email_heading', 1, 2);
			$order->update_status('wc-cancelled');
			$order->add_order_note(sprintf(
				/* translators: 1: cryptocurrency ticker, 2: number of hours */
				__('Your %1$s order was <strong>cancelled</strong> because you were unable to pay for %2$s hour(s). Please do not send any funds to the payment address.', 'nomiddleman-crypto-payments-for-woocommerce'),
				$cryptoId, round($cancelSec / 3600, 1)), 1);
		}
		finally {
			NMMPRO_Util::release_address_match_lock($cryptoId, $address);
		}
	}

	// =====================================================================
	// Late-payment monitoring (read-only).
	// =====================================================================

	// Watch a retired or held address for this long after it was bound, at
	// most once per interval, a bounded batch per cycle.
	const MONITOR_DAYS = 90;
	const MONITOR_INTERVAL_SEC = 21600;
	const MONITOR_BATCH = 25;

	/**
	 * A retired address is never issued again, and a held order is never
	 * completed automatically - but a customer can still pay either. This pass
	 * keeps collecting their evidence and tells the merchant, once per
	 * transaction, when a payment arrives after the binding. It never
	 * completes, cancels or claims anything: the funds are for a human to
	 * reconcile, and they can never pay a different order.
	 */
	public static function monitor_wallet($cryptoId, $mpk, $hdMode, $batch = self::MONITOR_BATCH) {
		if (!NMMPRO_Hd::automatic_available($cryptoId)) {
			return;
		}
		$repo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		$turn = self::cursor_option('monitor', $cryptoId, $mpk, $hdMode);
		$rows = $repo->monitored_rows(self::MONITOR_DAYS, self::MONITOR_INTERVAL_SEC, $batch, (int) get_option($turn, 0));
		if ($rows === false) {
			return;
		}

		$started = microtime(true);
		$last = null;
		foreach ($rows as $row) {
			if (self::over_budget($started, 'monitoring', $cryptoId)) {
				break;
			}
			try {
				self::monitor_row($repo, $cryptoId, $row);
			}
			catch (\Throwable $t) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: monitoring ' . $cryptoId . ' address ' . $row['address'] . ' raised: ' . $t->getMessage(), 'error');
			}
			$last = (int) $row['id'];
		}
		self::save_turn($turn, $last);
	}

	private static function monitor_row($repo, $cryptoId, $row) {
		$id = (int) $row['id'];
		$orderId = (int) $row['order_id'];
		$address = (string) $row['address'];

		if (NMMPRO_Util::acquire_address_match_lock($cryptoId, $address) !== '1') {
			return; // busy: its check time is not advanced, so it is retried first
		}
		try {
			$state = json_decode((string) $row['scan_state'], true);
			$state = is_array($state) ? $state : array();
			// Only what arrives after monitoring began is reported: evidence
			// seen while the order was open was already handled (credited,
			// underpaid, or the reason it was held).
			$since = isset($state['monitor_since']) ? (int) $state['monitor_since'] : time();
			$scan = NMMPRO_Hd_Evidence::scan($cryptoId, $address, (int) $row['bound_at'], $state, NMMPRO_Hd_Evidence::DEFAULT_MAX_PAGES, self::floor_height($row));
			$recorded = NMMPRO_Hd_Evidence_Repo::record($id, $orderId, $cryptoId, $address, $scan['source'], $scan['outputs'], time());
			// Advance the check time whatever the outcome, so a failing
			// explorer costs one request per interval, not one per cycle.
			$saved = is_array($scan['state']) ? $scan['state'] : $state;
			unset($saved['incomplete_since']);
			$saved['monitor_since'] = $since;
			$complete = $recorded === NMMPRO_Hd_Evidence_Repo::RECORD_OK && $scan['coverage'] === NMMPRO_Hd_Evidence::COMPLETE;
			$unreadableSince = (isset($state['incomplete_since']) && is_int($state['incomplete_since'])) ? $state['incomplete_since'] : time();
			if (!$complete) {
				$saved['incomplete_since'] = $unreadableSince;
			}
			$repo->save_monitor_state($id, $orderId, $saved);
			if (!$complete) {
				// Late payments cannot be seen while the address cannot be
				// read: after a day, say so on the order (once per spell).
				if (time() - $unreadableSince >= self::INCOMPLETE_NOTICE_SEC) {
					$read = NMMPRO_Payment::read_order_authoritatively($orderId);
					if ($read['state'] === 'ok') {
						self::note_once($read['order'], 'monitor-incomplete:' . $unreadableSince, sprintf(
							/* translators: 1: wallet address, 2: date and time (UTC), 3: reason */
							__('Privacy Mode: late payments to %1$s could not be checked since %2$s UTC (%3$s). Checking continues; please look at the address on a block explorer if this persists.', 'nomiddleman-crypto-payments-for-woocommerce'),
							$address, gmdate('Y-m-d H:i', $unreadableSince), $scan['reason'] !== '' ? $scan['reason'] : 'evidence could not be recorded'));
					}
				}
				return;
			}

			$evidence = NMMPRO_Hd_Evidence_Repo::rows($id);
			if ($evidence === false) {
				return;
			}
			$late = array();
			foreach ($evidence as $e) {
				$timing = self::timing($e, (int) $row['bound_at'], (int) $row['validated_at'], self::validated_height($row));
				$arrivedAfter = $timing === 'after'
					|| ($timing === 'ambiguous' && self::validated_height($row) !== null && $e['block_height'] !== null && $e['block_height'] > self::validated_height($row));
				if ($e['state'] === NMMPRO_Hd_Evidence_Repo::OBSERVED && $arrivedAfter && $e['first_seen_at'] >= $since) {
					$late[] = $e;
				}
			}
			if ($late === array()) {
				return;
			}

			$read = NMMPRO_Payment::read_order_authoritatively($orderId);
			if ($read['state'] !== 'ok') {
				return; // the evidence is recorded; the audit reports it
			}
			$precision = NMMPRO_Cryptocurrencies::get()[$cryptoId]->get_round_precision();
			foreach ($late as $e) {
				// Confirmed and pending sightings are separate notes: a pending
				// payment may never confirm, and a confirmed one must be told.
				$key = 'late:' . $e['tx_hash'] . ':' . $e['output_index'] . ($e['block_time'] === null ? ':pending' : '');
				if ($row['status'] === 'review') {
					/* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address, 4: transaction output, 5: why the order is held */
					$message = __('Privacy Mode: %1$s %2$s arrived at %3$s in transaction %4$s while this order is held for manual review (%5$s). It has NOT been applied automatically - please reconcile it.', 'nomiddleman-crypto-payments-for-woocommerce');
				}
				else {
					/* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address, 4: transaction output, 5: why the address was retired */
					$message = __('Privacy Mode: %1$s %2$s arrived at %3$s in transaction %4$s after this order\'s payment address was retired (%5$s). It has NOT been applied to this or any other order - please reconcile it manually.', 'nomiddleman-crypto-payments-for-woocommerce');
				}
				self::note_once($read['order'], $key, sprintf(
					$message,
					NMMPRO_Amount::from_units($e['amount_units'], $precision),
					$cryptoId,
					$address,
					$e['tx_hash'] . ':' . $e['output_index'] . ($e['block_time'] === null ? ' (' . __('unconfirmed', 'nomiddleman-crypto-payments-for-woocommerce') . ')' : ''),
					NMMPRO_Hd::reason_label((string) $row['review_reason'])));
			}
		}
		finally {
			NMMPRO_Util::release_address_match_lock($cryptoId, $address);
		}
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Hold an open row for review. A row whose completion is in flight
	 * ('completing') keeps its claim - the funds stay recorded against its
	 * order - so it is not moved; its order is told instead, once, that
	 * automatic recovery is blocked and a human must finish it.
	 */
	private static function hold_or_escalate($cryptoId, $row, $reason, $why) {
		$address = (string) $row['address'];
		if (self::hold_for_review($cryptoId, $row, $reason)) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: ' . $cryptoId . ' address ' . $address . ' ' . $why . '; held for review.', 'warning');
			return;
		}
		$current = self::repo_for($cryptoId, $row);
		$current = $current !== null ? $current->row((int) $row['id']) : null;
		if (!is_array($current) || $current['status'] !== 'completing') {
			return;
		}
		NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: ' . $cryptoId . ' address ' . $address . ' ' . $why . ' while order ' . (int) $row['order_id'] . '\'s completion is in flight; its claim is kept and it needs manual completion.', 'error');
		$read = NMMPRO_Payment::read_order_authoritatively((int) $row['order_id']);
		if ($read['state'] === 'ok') {
			self::note_once($read['order'], 'blocked:' . $reason, sprintf(
				/* translators: 1: wallet address */
				__('Privacy Mode: this order\'s payment was claimed, but its completion was interrupted and the payments to %1$s can no longer be re-checked automatically (the explorer cannot list them completely). The funds stay recorded against this order. Please check the address on a block explorer and complete the order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
				$address));
		}
	}

	private static function hold_for_review($cryptoId, $row, $reason) {
		$wallet = self::repo_for($cryptoId, $row);
		if ($wallet === null) {
			return false;
		}
		// A row already completing keeps its claim: a human resolves it, and
		// its funds stay recorded against this order.
		if ($wallet->transition((int) $row['id'], (int) $row['order_id'], array('assigned', 'underpaid'), 'review', $reason)) {
			$read = NMMPRO_Payment::read_order_authoritatively((int) $row['order_id']);
			if ($read['state'] === 'ok') {
				$messages = array(
					'evidence_conflict'   => __('Privacy Mode: explorers reported conflicting details for a payment to this order\'s address. Automatic verification has stopped; please check the address on a block explorer and reconcile this order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
					'timestamp_ambiguous' => __('Privacy Mode: a payment to this order\'s address was confirmed at about the time the address was issued, so it cannot be attributed automatically. Please check it on a block explorer and reconcile this order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
					'legacy_owner'        => __('Privacy Mode: a transaction to this order\'s address was already recorded as used by an earlier payment. Automatic verification has stopped; please reconcile this order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
					'history_unreadable'  => __('Privacy Mode: the blockchain explorer cannot list this order\'s address completely (for example, too many transactions in one block), so it cannot be verified automatically. Please check the address on a block explorer and reconcile this order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
					'history_too_large'   => __('Privacy Mode: this order\'s address has received more transactions than can be re-checked automatically. Automatic verification has stopped; please check the address on a block explorer and reconcile this order manually.', 'nomiddleman-crypto-payments-for-woocommerce'),
				);
				if (isset($messages[$reason])) {
					self::note_once($read['order'], $reason, $messages[$reason], false);
				}
			}
			return true;
		}
		return false;
	}

	private static function repo_for($cryptoId, $row) {
		global $wpdb;
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$wallet = $wpdb->get_row($wpdb->prepare("SELECT `mpk`, `hd_mode` FROM `$table` WHERE `id` = %d", (int) $row['id']), ARRAY_A);
		return is_array($wallet) ? new NMMPRO_Hd_Repo($cryptoId, $wallet['mpk'], $wallet['hd_mode']) : null;
	}

	/** Add an order note once per $key (merchant-only unless $customer). */
	private static function note_once($order, $key, $message, $customer = false) {
		$noted = $order->get_meta(self::NOTED_META);
		$noted = is_array($noted) ? $noted : array();
		if (in_array($key, $noted, true)) {
			return;
		}
		$noted[] = $key;
		$order->update_meta_data(self::NOTED_META, $noted);
		$order->save();
		$order->add_order_note($message, $customer ? 1 : 0);
	}

	private static function sum($rows) {
		$total = '0';
		foreach ($rows as $e) {
			$total = NMMPRO_Amount::add($total, $e['amount_units']);
		}
		return $total;
	}

	private static function cache_key($cryptoId, $address) {
		global $wpdb;
		return $wpdb->prefix . '|' . $cryptoId . '|' . $address;
	}
}
