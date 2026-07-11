<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function NMM_do_cron_job() {
	global $wpdb;	
	
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
		}		
	}

	NMM_Payment::check_all_addresses_for_matching_payment($autoPaymentTransactionLifetimeSec);	
	NMM_Payment::cancel_expired_payments();

	NMM_Util::log(__FILE__, __LINE__, 'total time for cron job: ' . NMM_get_time_passed($startTime));
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