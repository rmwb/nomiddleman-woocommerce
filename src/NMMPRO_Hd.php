<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMMPRO_Hd {

	// A fresh cron cycle starts with no remembered scans (NMMPRO_Cron calls
	// this at the top of every acquired cycle; tests call it too).
	public static function reset_observed_totals() {
		NMMPRO_Hd_Verifier::reset_cycle();
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
	public static function order_awaits_payment($order) {
		if ($order->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
			return false;
		}

		return $order->has_status(array('on-hold', 'pending')) || $order->needs_payment();
	}

	/**
	 * Whether Privacy Mode may run automatically for this coin on this site:
	 * the 1.5 evidence schema is in place AND the coin has a reviewed evidence
	 * adapter. Checkout, the background job and the verifier all consult this;
	 * when it is false no HD address is issued, derived, verified or expired.
	 * Existing rows are left to the legacy policy and manual review.
	 */
	public static function automatic_available($cryptoId) {
		return self::automatic_unavailable_reason($cryptoId) === null;
	}

	/**
	 * null when automatic HD is available, otherwise 'schema' (this site's
	 * 1.5 upgrade has not completed), 'unsupported' (no reviewed evidence
	 * adapter for the coin) or 'allocation_limit' (too many consecutive
	 * addresses already had chain history; cleared when the merchant re-saves
	 * the settings).
	 */
	public static function automatic_unavailable_reason($cryptoId) {
		if (!NMMPRO_Hd_Schema::ready()) {
			return 'schema';
		}
		if (!NMMPRO_Cryptocurrencies::hd_verifiable($cryptoId)) {
			return 'unsupported';
		}
		if (self::allocation_limited($cryptoId)) {
			return 'allocation_limit';
		}
		return null;
	}

	/**
	 * Follow the merchant's decisions on rows held for manual review. A held
	 * row is never completed or cancelled automatically; once the merchant has
	 * settled its ORDER by hand, the row follows: 'complete' when the order is
	 * paid, 'retired' when it is gone or finished unpaid (cancelled, refunded,
	 * failed, trashed). A row whose order still awaits payment stays held.
	 * Never touches the order. Bounded per tick, with a cursor so a large
	 * backlog of still-open orders cannot starve the rows behind it.
	 */
	public static function settle_review_rows($batch = 50) {
		if (!NMMPRO_Hd_Schema::ready()) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$cursor = (int) NMMPRO_Compat::get_option('nmmpro_hd_review_cursor', 0);

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT `id`, `order_id` FROM `$table` WHERE `status` = 'review' AND `id` > %d ORDER BY `id` LIMIT %d",
			$cursor, $batch
		), ARRAY_A);

		if (!is_array($rows)) {
			return;
		}

		foreach ($rows as $row) {
			// Read past WooCommerce's caches; only an order confirmed not to
			// exist retires its row, and one that cannot be read stays held.
			$read = NMMPRO_Payment::read_order_authoritatively((int) $row['order_id']);
			$next = null;

			if ($read['state'] === 'absent') {
				$next = 'retired';
			}
			elseif ($read['state'] === 'ok' && $read['order']->is_paid()) {
				$next = 'complete';
			}
			elseif ($read['state'] === 'ok' && !self::order_awaits_payment($read['order'])) {
				$next = 'retired';
			}

			if ($next !== null) {
				$wpdb->query($wpdb->prepare(
					"UPDATE `$table` SET `status` = %s WHERE `id` = %d AND `status` = 'review' AND `order_id` <=> %d",
					$next, $row['id'], $row['order_id']
				));
			}
		}

		$last = $rows === array() ? 0 : (int) $rows[count($rows) - 1]['id'];
		NMMPRO_Compat::update_option('nmmpro_hd_review_cursor', count($rows) < $batch ? 0 : $last, false);
	}

	/**
	 * What a stored review/retirement reason means, for the merchant. Unknown
	 * codes (a future release, a hand edit) fall back to the code itself.
	 */
	public static function reason_label($code) {
		$labels = array(
			'legacy_pool'         => __('Retired on upgrade: an older release may already have issued this pool address.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'legacy_quarantine'   => __('Retired on upgrade: quarantined addresses are no longer recycled.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'legacy_unvalidated'  => __('Held for review: assigned by an older release that judged payments by lifetime totals.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'chain_history'       => __('Retired unissued: the address already had transactions on chain.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'derivation_mismatch' => __('Retired unissued: the stored address does not match the configured key.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'superseded'          => __('Retired: the order was checked out again in another currency before this address was shown.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'binding_mismatch'    => __('Held for review: the order\'s payment method, currency or address changed after this address was issued.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'evidence_conflict'   => __('Held for review: explorers reported conflicting details for a payment.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'timestamp_ambiguous' => __('Held for review: a payment was confirmed at about the time the address was issued.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'legacy_owner'        => __('Held for review: a transaction was already recorded as used by an earlier payment.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'history_unreadable'  => __('Held for review: the explorer cannot list the address completely.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'history_too_large'   => __('Held for review: the address received more transactions than can be re-checked automatically.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'expired_underpaid'   => __('Held for review: the payment window passed with only part of the payment received.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'expired_unpaid'      => __('Retired: the order expired unpaid and was cancelled.', 'nomiddleman-crypto-payments-for-woocommerce'),
			'order_deleted'       => __('Retired: the order was deleted.', 'nomiddleman-crypto-payments-for-woocommerce'),
		);
		if (isset($labels[$code])) {
			return $labels[$code];
		}
		if (is_string($code) && strpos($code, 'order_') === 0) {
			/* translators: %s: order status */
			return sprintf(__('Retired: the order became %s.', 'nomiddleman-crypto-payments-for-woocommerce'), substr($code, 6));
		}
		return (string) $code;
	}

	public static function buffer_ready_addresses($cryptoId, $mpk, $amount, $hdMode) {
		if (!self::automatic_available($cryptoId)) {
			return;
		}

		$hdRepo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		$readyCount = $hdRepo->count_ready();

		$neededAddresses = $amount - $readyCount;

		for ($i = 0; $i < $neededAddresses; $i++) {

			try {
				self::force_new_address($cryptoId, $mpk, $hdMode);
			}
			catch ( \Exception $e ) {
				// An explorer outage, a database failure or the used-address cap:
				// stop this cycle rather than repeat the same failure N times.
				NMMPRO_Util::log(__FILE__, __LINE__, $e->getMessage());
				break;
			}
		}
	}

	/**
	 * Verify every open Privacy Mode assignment of this wallet from
	 * attributable transaction evidence (NMMPRO_Hd_Verifier). The lifetime
	 * total an address has received is never used: it cannot tell a new
	 * order's payment from funds that arrived before the order existed.
	 */
	public static function check_all_pending_addresses_for_payment($cryptoId, $mpk, $requiredConfirmations, $percentToVerify, $hdMode) {
		NMMPRO_Hd_Verifier::verify_wallet($cryptoId, $mpk, $requiredConfirmations, $percentToVerify, $hdMode);
	}

	/**
	 * Reconcile open assignments with their orders and cancel expired, unpaid
	 * ones - only on complete evidence that nothing arrived
	 * (NMMPRO_Hd_Verifier::expire_wallet).
	 */
	public static function cancel_expired_addresses($cryptoId, $mpk, $orderCancellationTimeSec, $hdMode) {
		NMMPRO_Hd_Verifier::expire_wallet($cryptoId, $mpk, $orderCancellationTimeSec, $hdMode);
	}

	/**
	 * Derive the next address into the ready pool.
	 *
	 * With $precheck (the background buffer), each derived address is checked
	 * on chain first: one with history is recorded as retired and skipped, and
	 * an inconclusive check stops this cycle rather than guessing. Without it
	 * (a checkout whose pool ran dry) the address goes straight into the pool;
	 * NMMPRO_Hd_Allocator checks every candidate again before issuing it, so a
	 * pool row is never trusted either way.
	 *
	 * Consecutive used addresses are capped. Hitting the cap means something
	 * other than this store is using the wallet, or the explorer is wrong: the
	 * coin's Privacy Mode is suspended (automatic_unavailable_reason()
	 * 'allocation_limit') with an admin message, and nothing is reused to get
	 * around it.
	 *
	 * @throws \Exception When no address could be added.
	 */
	public static function force_new_address($cryptoId, $mpk, $hdMode, $precheck = true) {
		$hdRepo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		$index = $hdRepo->get_next_index();
		$maxUsedSkips = (int) NMMPRO_Compat::filter('nmmpro_hd_max_used_skips', 20, $cryptoId);

		for ($skips = 0; ; $skips++) {
			$address = self::create_hd_address($cryptoId, $mpk, $index, $hdMode);
			if (!is_string($address) || $address === '') {
				throw new \Exception('Could not derive ' . esc_html($cryptoId) . ' HD address ' . (int) $index . '.');
			}

			$activity = $precheck ? NMMPRO_Hd_Evidence::address_activity($cryptoId, $address) : null;

			if ($activity === null || $activity['state'] === NMMPRO_Hd_Evidence::CLEAN) {
				if (!$hdRepo->insert_pool($address, $index)) {
					throw new \Exception(esc_html('Database insert failed adding ' . $cryptoId . ' HD address ' . $address . ' to the pool (see log for the database error).'));
				}
				self::clear_allocation_limit($cryptoId);
				return;
			}

			if ($activity['state'] === NMMPRO_Hd_Evidence::UNKNOWN) {
				throw new \Exception(esc_html('Could not check ' . $cryptoId . ' HD address ' . $address . ' on chain (' . $activity['reason'] . '); not adding it to the pool this cycle.'));
			}

			// Used before we ever issued it. Record it retired so derivation moves
			// past it for good.
			if (!$hdRepo->insert($address, $index, 'retired')) {
				throw new \Exception(esc_html('Database insert failed recording used ' . $cryptoId . ' HD address ' . $address . ' (see log for the database error).'));
			}
			NMMPRO_Util::log(__FILE__, __LINE__, 'HD address ' . $address . ' (index ' . $index . ') already has chain history (' . $activity['reason'] . '); retired without issuing it.', 'warning');

			if ($skips + 1 >= $maxUsedSkips) {
				self::record_allocation_limit($cryptoId, $maxUsedSkips);
				throw new \Exception(esc_html('Found ' . $maxUsedSkips . ' consecutive ' . $cryptoId . ' HD addresses with chain history; Privacy Mode for ' . $cryptoId . ' is suspended until this is investigated.'));
			}
			$index++;
		}
	}

	const ALLOCATION_LIMIT_OPTION = 'nmmpro_hd_allocation_limit';

	private static function record_allocation_limit($cryptoId, $limit) {
		$limits = NMMPRO_Compat::get_option(self::ALLOCATION_LIMIT_OPTION, array());
		$limits = is_array($limits) ? $limits : array();
		$limits[$cryptoId] = array('at' => time(), 'limit' => (int) $limit);
		NMMPRO_Compat::update_option(self::ALLOCATION_LIMIT_OPTION, $limits, false);
	}

	private static function clear_allocation_limit($cryptoId) {
		$limits = NMMPRO_Compat::get_option(self::ALLOCATION_LIMIT_OPTION, array());
		if (is_array($limits) && isset($limits[$cryptoId])) {
			unset($limits[$cryptoId]);
			NMMPRO_Compat::update_option(self::ALLOCATION_LIMIT_OPTION, $limits, false);
		}
	}

	/**
	 * The merchant re-saving the settings is the acknowledgement that lifts a
	 * used-address suspension (they changed the key, or confirmed the wallet
	 * is this store's alone). Hooked on the settings option's update.
	 */
	public static function clear_allocation_limits() {
		NMMPRO_Compat::delete_option(self::ALLOCATION_LIMIT_OPTION);
	}

	/** Whether the consecutive-used cap suspended this coin's Privacy Mode. */
	public static function allocation_limited($cryptoId) {
		$limits = NMMPRO_Compat::get_option(self::ALLOCATION_LIMIT_OPTION, array());
		return is_array($limits) && isset($limits[$cryptoId]);
	}

	public static function create_hd_address($cryptoId, $mpk, $index, $hdMode) {

		try {
			if (!NMMPRO_Util::p_enabled()) {
				if (self::is_valid_xpub($mpk)) {
					return HdHelper::mpk_to_bc_address($cryptoId, $mpk, $index, 2, false);
				}
			}
			else {
				if (self::is_valid_mpk($cryptoId, $mpk)) {
					return NMMPRO_Compat::filter('nmmpro_get_hd_address', $cryptoId, $mpk, $index, $hdMode);
				}
			}
		}
		catch (\Throwable $e) {
			// \Throwable: the math backends reject a malformed key with a
			// ValueError (an \Error), which would otherwise escape every caller
			// that handles \Exception - the background buffer among them, where
			// it aborted the whole cycle for every coin. The message only: a
			// stack trace carries the call arguments, and those include the
			// master public key, which must never reach a log.
			throw new \Exception('Invalid MPK for ' . esc_html($cryptoId) . ': ' . esc_html($e->getMessage()));
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
