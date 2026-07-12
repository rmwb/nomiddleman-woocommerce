<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Util {

	/**
	 * Operational log. Routes to WooCommerce's logger (WooCommerce > Status >
	 * Logs, source "nomiddleman") when available, otherwise error_log().
	 *
	 * Levels follow WC_Log_Levels. Warnings and above are always emitted so an
	 * operator sees events that may need intervention (enqueue/migration
	 * failures, cron-lock degradation, address-claim exhaustion, payment
	 * collisions, ...). Verbose debug/info tracing is emitted only when debug
	 * logging is enabled (WP_DEBUG, the NMM_DEBUG_LOG constant, or the
	 * nmm_debug_logging filter), so production is not flooded. Identical
	 * messages are de-duplicated for a short window so a per-tick failure can
	 * be visible without spamming, and messages are length-capped so a full
	 * third-party response is never dumped wholesale.
	 */
	public static function log($fileName, $lineNumber, $message, $level = 'debug') {
		$important = in_array($level, array('warning', 'error', 'critical', 'alert', 'emergency'), true);

		if (!$important && !self::debug_logging_enabled()) {
			return;
		}

		$message = (string) $message;
		if (strlen($message) > 2000) {
			$message = substr($message, 0, 2000) . ' ...[truncated]';
		}
		$entry = basename((string) $fileName) . ':' . $lineNumber . '  ' . $message;

		// De-duplicate: skip an identical entry seen within the throttle window.
		if (function_exists('get_transient') && function_exists('set_transient')) {
			$throttleKey = 'nmm_log_' . md5($level . '|' . $entry);
			if (get_transient($throttleKey) !== false) {
				return;
			}
			$window = in_array($level, array('error', 'critical', 'alert', 'emergency'), true) ? 60 : 300;
			set_transient($throttleKey, 1, $window);
		}

		if (function_exists('wc_get_logger')) {
			wc_get_logger()->log($level, $entry, array('source' => 'nomiddleman'));
		}
		else {
			error_log('[nomiddleman][' . $level . '] ' . $entry);
		}
	}

	private static function debug_logging_enabled() {
		if (defined('NMM_DEBUG_LOG')) {
			return (bool) NMM_DEBUG_LOG;
		}
		$default = defined('WP_DEBUG') && WP_DEBUG;
		return function_exists('apply_filters') ? (bool) apply_filters('nmm_debug_logging', $default) : $default;
	}

	public static function p_enabled() {
		return function_exists('NMMP_init');
	}

	/**
	 * Privacy Mode derives HD (BIP32) addresses using elliptic-curve math that
	 * PHP cannot do natively; it needs either the gmp or the bcmath extension
	 * (see src/vendor/HdHelper.php, which prefers gmp). Without one, address
	 * derivation silently returns nothing and the settings page reports a
	 * misleading "check your MPK" error.
	 */
	public static function hd_math_available() {
		return extension_loaded('gmp') || extension_loaded('bcmath');
	}

}

?>