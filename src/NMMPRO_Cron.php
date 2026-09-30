<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function NMMPRO_do_cron_job() {
	global $wpdb;

	// Never run two cycles at once. A slow cycle - e.g. explorers rate-limiting
	// the HD address checks - must not stack a second PHP process on top; a few
	// stacked cycles exhaust memory, trigger swap/page-faults, and pin the CPU.
	//
	// A get-then-set transient is not atomic: two ticks firing together can both
	// see "free" and both proceed. Use a MySQL advisory lock instead - GET_LOCK
	// is atomic across connections, owned by the acquiring connection, and is
	// released automatically if that PHP process dies, so a crashed run can
	// never wedge the cron. The lock name is scoped to this site (database +
	// table prefix) so neither sites sharing a MySQL server nor subsites on
	// one multisite network block one another.
	// Paused by the operator (for example before a downgrade - see
	// docs/DOWNGRADE.md): do nothing at all, not even take the lock. Nothing
	// else runs matching, completion recovery or expiry, so a paused store has
	// no Autopay worker once any pass already in flight has finished.
	if (NMMPRO_Compat::get_option('nmmpro_background_paused', false)) {
		NMMPRO_Util::log(__FILE__, __LINE__, 'Background job paused (nmmpro_background_paused); skipping this tick.');
		return;
	}

	$lockName = NMMPRO_Util::cron_lock_name();
	$lockAcquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName));

	if ($lockAcquired === '0') {
		// Definitively held by another live connection; skip this tick.
		NMMPRO_Util::log(__FILE__, __LINE__, 'Previous cron cycle still running; skipping this tick.');
		return;
	}
	// $lockAcquired === '1' -> we own it. Any other value (null) means GET_LOCK
	// is unavailable on this host; degrade to running unlocked rather than never
	// running, matching the pre-lock behaviour.
	if ($lockAcquired !== '1') {
		NMMPRO_Util::log(__FILE__, __LINE__, 'Advisory lock unavailable on this host; running cron without overlap protection.', 'warning');
	}

	try {
		// Re-check the pause now that the lock is held, straight from the table
		// (not the object cache): a pass that read "not paused" and then waited
		// for the lock must not start work after the operator paused and saw
		// the lock free. Together with the check above, a paused store starts no
		// new work once the lock has been seen free. A read that FAILS is not
		// "not paused": get_var() answers null for a missing option and for a
		// failed query alike, so the error is checked and the pass exits. Both
		// exits are inside the try, so the lock is released in finally.
		$pausedNow = $wpdb->get_var($wpdb->prepare("SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", 'nmmpro_background_paused'));
		if ($wpdb->last_error !== '') {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Could not read the background pause state (' . $wpdb->last_error . '); exiting this tick without doing anything.', 'warning');
			return;
		}
		if ($pausedNow !== null && $pausedNow !== '' && $pausedNow !== '0') {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Background job paused while waiting for the lock; exiting.');
			return;
		}

		// Automatic expiry acts on sweep coverage, and coverage is only
		// trustworthy if this pass is really the only one writing it. Without a
		// held, still-owned cron lock this pass matches (which fails closed on
		// its own per-address lock) but certifies and cancels nothing; the
		// status screen reports why.
		$fence = NMMPRO_Util::begin_cron_fence($lockAcquired);
		if ($fence !== 'held') {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Cron pass is not exclusive (' . $fence . '); Autopay will match payments but not expire orders this tick.', 'warning');
		}

		$nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));
		// Number of clean addresses in the database at all times for faster thank you page load times
		$hdBufferAddressCount = 4;

		// Only look at transactions in the past two hours
		$autoPaymentTransactionLifetimeSec = 3 * 60 * 60;

		$startTime = time();
		NMMPRO_Util::log(__FILE__, __LINE__, 'Starting Cron Job...');

		// Each cycle starts with a clean observation cache. A long-lived process
		// that runs many cycles (a CLI cron runner, a multisite loop) must never
		// let the expiry pass act on a PREVIOUS cycle's balance observations.
		NMMPRO_Hd::reset_observed_totals();

		NMMPRO_warm_price_caches($nmmSettings);

		NMMPRO_Carousel_Repo::init();

		// Rows an older release wrote (for example during a downgrade) are put
		// under the legacy policy every tick, before any HD pass can see them:
		// unvalidated pool rows retire and unvalidated assignments go to manual
		// review. Idempotent, conditional UPDATEs; a failure is logged and the
		// passes below still only ever act on validated rows.
		if (NMMPRO_Hd_Schema::ready()) {
			NMMPRO_Hd_Schema::apply_legacy_policy($GLOBALS['wpdb']->prefix . NMMPRO_HD_TABLE);
			NMMPRO_Hd::settle_review_rows();
		}

		foreach (NMMPRO_Cryptocurrencies::get() as $crypto) {
			$cryptoId = $crypto->get_id();

			if ($nmmSettings->hd_enabled($cryptoId) && !NMMPRO_Hd::automatic_available($cryptoId)) {
				// Once an hour per coin, not every tick.
				if (get_transient('nmmpro_hd_unavailable_logged_' . $cryptoId) === false) {
					NMMPRO_Util::log(__FILE__, __LINE__, 'Privacy Mode is enabled for ' . $cryptoId . ' but automatic verification is unavailable (' . NMMPRO_Hd::automatic_unavailable_reason($cryptoId) . '); skipping its HD passes.', 'warning');
					set_transient('nmmpro_hd_unavailable_logged_' . $cryptoId, 1, HOUR_IN_SECONDS);
				}
			}
			elseif ($nmmSettings->hd_enabled($cryptoId)) {
				NMMPRO_Util::log(__FILE__, __LINE__, 'Starting Hd stuff for: ' . $cryptoId);
				$mpk = $nmmSettings->get_mpk($cryptoId);
				$hdMode = $nmmSettings->get_hd_mode($cryptoId);
				$hdPercentToVerify = $nmmSettings->get_hd_processing_percent($cryptoId);
				$hdRequiredConfirmations = $nmmSettings->get_hd_required_confirmations($cryptoId);
				$hdOrderCancellationTimeHr = $nmmSettings->get_hd_cancellation_time($cryptoId);
				$hdOrderCancellationTimeSec = round($hdOrderCancellationTimeHr * 60 * 60, 0);

				NMMPRO_Hd::check_all_pending_addresses_for_payment($cryptoId, $mpk, $hdRequiredConfirmations, $hdPercentToVerify, $hdMode);

				NMMPRO_Hd::buffer_ready_addresses($cryptoId, $mpk, $hdBufferAddressCount, $hdMode);
				NMMPRO_Hd::cancel_expired_addresses($cryptoId, $mpk, $hdOrderCancellationTimeSec, $hdMode);

				// Retired and held addresses can still be paid: keep collecting
				// their evidence and tell the merchant (read-only, bounded).
				NMMPRO_Hd_Verifier::monitor_wallet($cryptoId, $mpk, $hdMode);

				// No quarantine pass: an issued address is never recycled; an
				// ended order's address is retired (NMMPRO_Hd_Verifier).
			}
		}

		NMMPRO_Payment::resume_verified_orders();
		NMMPRO_Payment::recover_interrupted_cancellations();
		NMMPRO_Payment::check_all_addresses_for_matching_payment($autoPaymentTransactionLifetimeSec);
		NMMPRO_Payment::cancel_expired_payments();
		NMMPRO_Payment::purge_lapsed_deferrals();

		// Reclaim durable Solana retry rows for addresses no longer scanned at all
		// (SOL disabled, or a carousel address removed/replaced) once they are far
		// past any matching window. Per-address expiry never revisits those, so
		// this bounded global pass prevents unbounded growth across config changes.
		// A seven-day retention needs no minute-by-minute checking, so gate it to
		// run at most hourly; run_global_cleanup() clamps the retention to a safe
		// minimum and drains in bounded batches when there is work.
		if (get_transient('nmmpro_sol_global_cleanup_ran') === false) {
			$solGlobalRetention = (int) NMMPRO_Compat::filter('nmmpro_sol_retry_global_retention_seconds', 7 * DAY_IN_SECONDS);
			NMMPRO_Sol_Retry_Repo::run_global_cleanup($solGlobalRetention, $autoPaymentTransactionLifetimeSec + 30 * MINUTE_IN_SECONDS);
			set_transient('nmmpro_sol_global_cleanup_ran', 1, HOUR_IN_SECONDS);
		}

		NMMPRO_Util::log(__FILE__, __LINE__, 'total time for cron job: ' . NMMPRO_get_time_passed($startTime));
	}
	finally {
		// Record how the pass ENDED for the Status screen. Judging at the start
		// would hide a pass that began exclusive and lost its lock part-way,
		// which certifies and cancels nothing just the same.
		// Re-ask the server rather than trusting the last answer: a connection
		// dropped after the last certified write still means this pass was not
		// exclusive to the end.
		if (NMMPRO_Util::cron_fence_state() === 'held') {
			NMMPRO_Util::cron_fence_held();
		}
		$fenceAtEnd = NMMPRO_Util::cron_fence_state();
		if ($fenceAtEnd === 'held') {
			if (NMMPRO_Compat::get_option('nmmpro_autopay_unfenced', false) !== false) {
				NMMPRO_Compat::delete_option('nmmpro_autopay_unfenced');
			}
		}
		elseif ($fenceAtEnd !== null) {
			NMMPRO_Compat::update_option('nmmpro_autopay_unfenced', array('at' => time(), 'reason' => $fenceAtEnd), false);
		}
		NMMPRO_Util::end_cron_fence();
		// Release only the lock we actually acquired. RELEASE_LOCK is a no-op
		// for any connection that does not own it, but we guard anyway so a
		// degraded (unlocked) run never touches another connection's lock.
		if ($lockAcquired === '1') {
			$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
		}
	}
}

function NMMPRO_get_time_passed($startTime) {
	return time() - $startTime;
}

/**
 * Refresh expired exchange-rate transients from the background job so the
 * thank-you page is a cache hit for (almost) every customer. Every fetcher
 * short-circuits on a warm transient, so this costs nothing when rates are
 * fresh; the lock keeps a 60-second scheduler from re-checking too often.
 */
function NMMPRO_warm_price_caches($nmmSettings) {
	if (get_transient('nmmpro_rates_warm_lock') !== false) {
		return;
	}
	set_transient('nmmpro_rates_warm_lock', 1, 240);

	try {
		NMMPRO_Exchange::get_order_total_in_usd(1.0, get_woocommerce_currency());
	}
	catch (\Exception $e) {
		NMMPRO_Util::log(__FILE__, __LINE__, 'Fiat rate warm-up failed: ' . $e->getMessage());
	}

	$selectedApis = $nmmSettings->get_selected_price_apis();

	if (count($selectedApis) === 0) {
		return;
	}

	foreach (NMMPRO_Cryptocurrencies::get() as $crypto) {
		$cryptoId = $crypto->get_id();

		if (!$nmmSettings->crypto_selected_and_valid($cryptoId)) {
			continue;
		}

		try {
			NMMPRO_Exchange::get_average_usd_price($cryptoId, $crypto->get_update_interval(), $selectedApis);
		}
		catch (\Exception $e) {
			NMMPRO_Util::log(__FILE__, __LINE__, 'Rate warm-up failed for ' . $cryptoId . ': ' . $e->getMessage());
		}
	}
}

?>
