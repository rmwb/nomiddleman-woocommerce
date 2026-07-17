<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function NMM_do_cron_job() {
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
	$lockName = NMM_Util::cron_lock_name();
	$lockAcquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName));

	if ($lockAcquired === '0') {
		// Definitively held by another live connection; skip this tick.
		NMM_Util::log(__FILE__, __LINE__, 'Previous cron cycle still running; skipping this tick.');
		return;
	}
	// $lockAcquired === '1' -> we own it. Any other value (null) means GET_LOCK
	// is unavailable on this host; degrade to running unlocked rather than never
	// running, matching the pre-lock behaviour.
	if ($lockAcquired !== '1') {
		NMM_Util::log(__FILE__, __LINE__, 'Advisory lock unavailable on this host; running cron without overlap protection.', 'warning');
	}

	try {
		$nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));
		// Number of clean addresses in the database at all times for faster thank you page load times
		$hdBufferAddressCount = 4;

		// Only look at transactions in the past two hours
		$autoPaymentTransactionLifetimeSec = 3 * 60 * 60;

		$startTime = time();
		NMM_Util::log(__FILE__, __LINE__, 'Starting Cron Job...');

		NMM_warm_price_caches($nmmSettings);

		NMM_Carousel_Repo::init();
		foreach (NMM_Cryptocurrencies::get() as $crypto) {
			$cryptoId = $crypto->get_id();

			if ($nmmSettings->hd_enabled($cryptoId)) {
				NMM_Util::log(__FILE__, __LINE__, 'Starting Hd stuff for: ' . $cryptoId);
				$mpk = $nmmSettings->get_mpk($cryptoId);
				$hdMode = $nmmSettings->get_hd_mode($cryptoId);
				$hdPercentToVerify = $nmmSettings->get_hd_processing_percent($cryptoId);
				$hdRequiredConfirmations = $nmmSettings->get_hd_required_confirmations($cryptoId);
				$hdOrderCancellationTimeHr = $nmmSettings->get_hd_cancellation_time($cryptoId);
				$hdOrderCancellationTimeSec = round($hdOrderCancellationTimeHr * 60 * 60, 0);

				NMM_Hd::check_all_pending_addresses_for_payment($cryptoId, $mpk, $hdRequiredConfirmations, $hdPercentToVerify, $hdMode);

				NMM_Hd::buffer_ready_addresses($cryptoId, $mpk, $hdBufferAddressCount, $hdMode);
				NMM_Hd::cancel_expired_addresses($cryptoId, $mpk, $hdOrderCancellationTimeSec, $hdMode);

				// Re-verify quarantined (abandoned, unpaid) addresses with fresh
				// explorer checks spaced at least this far apart, and past the
				// payment expiry, before any are recycled. Filterable so a
				// merchant can lengthen the wait.
				$hdQuarantinePeriodSec = apply_filters('nmm_hd_quarantine_seconds', max($hdOrderCancellationTimeSec, 6 * HOUR_IN_SECONDS), $cryptoId);
				$hdQuarantineBatch = (int) apply_filters('nmm_hd_quarantine_batch', 25, $cryptoId);
				NMM_Hd::process_quarantined_addresses($cryptoId, $mpk, $hdRequiredConfirmations, $hdMode, $hdQuarantinePeriodSec, $hdQuarantineBatch);
			}
		}

		NMM_Payment::check_all_addresses_for_matching_payment($autoPaymentTransactionLifetimeSec);
		NMM_Payment::cancel_expired_payments();

		// Reclaim durable Solana retry rows for addresses no longer scanned at all
		// (SOL disabled, or a carousel address removed/replaced) once they are far
		// past any matching window. Per-address expiry never revisits those, so
		// this bounded global pass prevents unbounded growth across config changes.
		// A seven-day retention needs no minute-by-minute checking, so gate it to
		// run at most hourly; run_global_cleanup() clamps the retention to a safe
		// minimum and drains in bounded batches when there is work.
		if (get_transient('nmm_sol_global_cleanup_ran') === false) {
			$solGlobalRetention = (int) apply_filters('nmm_sol_retry_global_retention_seconds', 7 * DAY_IN_SECONDS);
			NMM_Sol_Retry_Repo::run_global_cleanup($solGlobalRetention, $autoPaymentTransactionLifetimeSec + 30 * MINUTE_IN_SECONDS);
			set_transient('nmm_sol_global_cleanup_ran', 1, HOUR_IN_SECONDS);
		}

		NMM_Util::log(__FILE__, __LINE__, 'total time for cron job: ' . NMM_get_time_passed($startTime));
	}
	finally {
		// Release only the lock we actually acquired. RELEASE_LOCK is a no-op
		// for any connection that does not own it, but we guard anyway so a
		// degraded (unlocked) run never touches another connection's lock.
		if ($lockAcquired === '1') {
			$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
		}
	}
}

function NMM_get_time_passed($startTime) {
	return time() - $startTime;
}

/**
 * Refresh expired exchange-rate transients from the background job so the
 * thank-you page is a cache hit for (almost) every customer. Every fetcher
 * short-circuits on a warm transient, so this costs nothing when rates are
 * fresh; the lock keeps a 60-second scheduler from re-checking too often.
 */
function NMM_warm_price_caches($nmmSettings) {
	if (get_transient('nmm_rates_warm_lock') !== false) {
		return;
	}
	set_transient('nmm_rates_warm_lock', 1, 240);

	try {
		NMM_Exchange::get_order_total_in_usd(1.0, get_woocommerce_currency());
	}
	catch (\Exception $e) {
		NMM_Util::log(__FILE__, __LINE__, 'Fiat rate warm-up failed: ' . $e->getMessage());
	}

	$selectedApis = $nmmSettings->get_selected_price_apis();

	if (count($selectedApis) === 0) {
		return;
	}

	foreach (NMM_Cryptocurrencies::get() as $crypto) {
		$cryptoId = $crypto->get_id();

		if (!$nmmSettings->crypto_selected_and_valid($cryptoId)) {
			continue;
		}

		try {
			NMM_Exchange::get_average_usd_price($cryptoId, $crypto->get_update_interval(), $selectedApis);
		}
		catch (\Exception $e) {
			NMM_Util::log(__FILE__, __LINE__, 'Rate warm-up failed for ' . $cryptoId . ': ' . $e->getMessage());
		}
	}
}

?>