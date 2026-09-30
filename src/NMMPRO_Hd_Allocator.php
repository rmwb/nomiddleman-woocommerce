<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy Mode (HD) checkout allocation: reserve, freshly validate,
 * permanently bind, and only then let the caller show the address.
 *
 * The pool is never trusted. An address derived earlier may since have
 * received funds (a stranger's payment, a wallet shared with another store),
 * and the incident this class exists for was exactly that: a pooled address
 * with years-old receipts handed to a new order. So every candidate is
 * checked on chain at the moment of issue, outside any database transaction,
 * and bound with the database's own clock as the order's assignment boundary
 * and the chain's height at that moment as its block boundary.
 *
 * The caller (NMMPRO_Gateway::initialize_order_payment) holds the per-order
 * initialization lock, so one order never allocates twice concurrently; the
 * reservation token keeps two orders from ever binding the same address, and
 * a stale worker whose reservation expired from ever finalizing it.
 */
class NMMPRO_Hd_Allocator {

	// How long a reservation holds a candidate while it is being checked. Far
	// longer than one explorer request (8s timeout), so a live checkout is
	// never overtaken; a crashed one frees the candidate soon after.
	const RESERVATION_TTL_SEC = 120;

	// An inconclusive check sets the candidate aside this long before anyone
	// checks it again.
	const DEFER_SEC = 300;

	// Per-checkout budget: candidates tried, inconclusive answers tolerated,
	// and wall-clock time. An explorer outage must fail the checkout quickly,
	// not walk the whole wallet.
	const MAX_CANDIDATES = 5;
	const MAX_UNKNOWN = 2;
	const TIME_BUDGET_SEC = 25;

	/**
	 * The address to show for $orderId: its existing validated assignment on a
	 * retried checkout, otherwise a freshly validated, newly bound one.
	 *
	 * @throws \Exception With a customer-safe message when no address can be
	 *                    issued; nothing has been shown in that case.
	 */
	public static function allocate($cryptoId, $mpk, $hdMode, $orderId, $orderAmount) {
		$resumed = self::resume($cryptoId, $mpk, $hdMode, $orderId, $orderAmount);
		if ($resumed !== null) {
			return $resumed;
		}

		$repo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		$token = bin2hex(random_bytes(16));
		$started = microtime(true);
		$unknown = 0;
		$tipHeight = null;
		$tip = array('height' => null, 'reason' => 'not read');

		for ($attempt = 0; $attempt < self::MAX_CANDIDATES; $attempt++) {
			if (microtime(true) - $started > self::TIME_BUDGET_SEC) {
				break;
			}

			$candidate = $repo->reserve_candidate($token, self::RESERVATION_TTL_SEC);
			if ($candidate === null) {
				// The pool is dry: derive one. It is checked below like any other.
				try {
					NMMPRO_Hd::force_new_address($cryptoId, $mpk, $hdMode, false);
				}
				catch (\Exception $e) {
					// Most often a concurrent derivation (the background buffer)
					// inserted the same next index first; its row is a candidate
					// now. Anything else simply uses up this attempt.
					NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode allocation for order ' . $orderId . ': ' . $e->getMessage(), 'warning');
				}
				$candidate = $repo->reserve_candidate($token, self::RESERVATION_TTL_SEC);
			}
			if ($candidate === false) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode allocation for order ' . $orderId . ': database error reserving a ' . $cryptoId . ' address.', 'error');
				break;
			}
			if ($candidate === null) {
				continue; // another checkout took the one just derived
			}

			$id = (int) $candidate['id'];
			$address = (string) $candidate['address'];

			// The row must be what this wallet derives at that index. A row that
			// is not (a changed key, a corrupted table) must never be issued.
			try {
				$derived = NMMPRO_Hd::create_hd_address($cryptoId, $mpk, (int) $candidate['mpk_index'], $hdMode);
			}
			catch (\Exception $e) {
				$derived = null;
			}
			if ($derived !== $address) {
				$repo->retire_reserved($id, $token, 'derivation_mismatch');
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: pool row ' . $id . ' (' . $cryptoId . ' index ' . (int) $candidate['mpk_index'] . ') does not match the configured key; retired without issuing it.', 'error');
				continue;
			}

			$activity = NMMPRO_Hd_Evidence::address_activity($cryptoId, $address);

			if ($activity['state'] === NMMPRO_Hd_Evidence::USED) {
				$repo->retire_reserved($id, $token, 'chain_history');
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: ' . $cryptoId . ' address ' . $address . ' already has chain history (' . $activity['reason'] . '); retired without issuing it.', 'warning');
				continue;
			}

			if ($activity['state'] !== NMMPRO_Hd_Evidence::CLEAN) {
				$repo->defer_reserved($id, $token, self::DEFER_SEC);
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: could not confirm ' . $cryptoId . ' address ' . $address . ' is unused (' . $activity['reason'] . '); set aside.', 'warning');
				if (++$unknown >= self::MAX_UNKNOWN) {
					break;
				}
				continue;
			}

			// The chain height now: every block mined after the binding is
			// above it, whatever time its miner wrote into it. Read once per
			// checkout; an earlier reading is only lower, which is safe.
			if ($tipHeight === null) {
				$tip = NMMPRO_Hd_Evidence::chain_tip($cryptoId);
				$tipHeight = $tip['height'];
			}
			if ($tipHeight === null) {
				$repo->defer_reserved($id, $token, self::DEFER_SEC);
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: could not read the ' . $cryptoId . ' chain height (' . $tip['reason'] . '); address ' . $address . ' set aside.', 'warning');
				if (++$unknown >= self::MAX_UNKNOWN) {
					break;
				}
				continue;
			}

			$bind = $repo->bind_reserved($id, $token, $orderId, $orderAmount, time(), $tipHeight);
			if ($bind === NMMPRO_Hd_Repo::BIND_BOUND) {
				return $address;
			}
			if ($bind === NMMPRO_Hd_Repo::BIND_DB_ERROR) {
				// The row's state is unknown - it may even be bound. Fail without
				// showing anything; a retried checkout resumes a binding that did
				// land, and one that did not frees the candidate on expiry.
				break;
			}
			// BIND_CONFLICT: our reservation expired and another checkout took the
			// candidate. It is theirs now; try the next one.
		}

		throw new \Exception(esc_html__('We could not verify a fresh payment address for this order right now, so none has been issued and you have not been charged. Please try again in a few minutes.', 'nomiddleman-crypto-payments-for-woocommerce'));
	}

	/**
	 * A retried checkout keeps the order's existing validated assignment. The
	 * order carries no wallet_address (the caller only allocates for such an
	 * order), so an assignment it holds in another coin or wallet was never
	 * shown: it is retired, never recycled. One that has received funds is
	 * never discarded - the checkout is refused instead.
	 *
	 * @return string|null The resumed address, or null to allocate afresh.
	 * @throws \Exception
	 */
	private static function resume($cryptoId, $mpk, $hdMode, $orderId, $orderAmount) {
		$existing = NMMPRO_Hd_Repo::order_assignments($orderId);
		if ($existing === false) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode allocation for order ' . $orderId . ': could not read its existing assignments.', 'error');
			throw new \Exception(esc_html__('We could not set up a payment address for this order right now. Please try again in a few minutes.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}

		$same = null;
		foreach ($existing as $row) {
			$sameWallet = $row['cryptocurrency'] === $cryptoId && $row['mpk'] === $mpk && (string) $row['hd_mode'] === (string) $hdMode;
			if ($sameWallet && $same === null) {
				$same = $row;
				continue;
			}
			if ($row['status'] !== 'assigned' || $row['credited_units'] !== null) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: order ' . $orderId . ' already has a ' . $row['cryptocurrency'] . ' assignment in state ' . $row['status'] . '; not issuing another address.', 'error');
				throw new \Exception(esc_html__('A payment is already being processed for this order, so no new payment address has been issued. Please contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
			}
			NMMPRO_Hd_Repo::retire_assignment((int) $row['id'], $orderId, 'superseded');
		}

		if ($same === null) {
			return null;
		}

		// Funds already credited to it (or a completion in flight) mean the
		// order is being paid at that address: never re-price or re-show it
		// through a fresh checkout.
		if ($same['status'] !== 'assigned' || $same['credited_units'] !== null) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode: order ' . $orderId . ' already has a ' . $cryptoId . ' assignment in state ' . $same['status'] . '; not re-issuing it.', 'error');
			throw new \Exception(esc_html__('A payment is already being processed for this order, so no new payment address has been issued. Please contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}

		$repo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);
		if (!$repo->reprice_assignment((int) $same['id'], $orderId, $orderAmount)) {
			throw new \Exception(esc_html__('We could not set up a payment address for this order right now. Please try again in a few minutes.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}

		return (string) $same['address'];
	}
}
