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

	/**
	 * Replaces the values of credential-bearing query parameters with REDACTED
	 * so a URL can be logged safely (WC logs end up in forums and support
	 * bundles). Redaction happens only at the point of logging - the real
	 * request always carries the real values.
	 */
	public static function redact_url($url) {
		return preg_replace('/([?&](?:token|apikey|api_key|key|auth|password)=)[^&#]*/i', '$1REDACTED', (string) $url);
	}

	/**
	 * Compact, log-safe summary of a wp_remote_* result: the HTTP status (or
	 * WP_Error message) plus a truncated body prefix, instead of a print_r of
	 * the whole response array (which is huge and echoes request headers -
	 * including credentials - back into the log).
	 */
	public static function summarize_response($response) {
		if (function_exists('is_wp_error') && is_wp_error($response)) {
			return 'WP_Error: ' . $response->get_error_message();
		}

		$code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
		$body = isset($response['body']) ? (string) $response['body'] : '';
		if (strlen($body) > 500) {
			$body = substr($body, 0, 500) . ' ...[truncated]';
		}

		return 'http ' . $code . ': ' . $body;
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

	// Site-scoped cron lock name. DB_NAME separates installs that share a MySQL
	// server; $wpdb->prefix separates subsites on multisite (which share
	// DB_NAME), so one subsite's slow cycle cannot make every other subsite
	// skip its tick - each subsite has its own tables and backlog, so
	// cross-site blocking buys no correctness, only starvation. Both are
	// hashed to a fixed length, comfortably under MySQL's 64-char lock-name
	// limit, so a long DB_NAME can never truncate into a collision.
	public static function cron_lock_name() {
		global $wpdb;

		return 'nmm_cron_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 12);
	}

	// Per-order advisory lock name. Scoped to this site AND this order so distinct
	// orders never share a lock, and neither do same-numbered orders on different
	// sites. The table prefix ($wpdb->prefix) is blog-specific on multisite, where
	// sites share DB_NAME and WooCommerce order ids can overlap; DB_NAME still
	// separates sites that merely share a MySQL server. Both are hashed to a fixed
	// length so they can never push the (variable-length) order id past MySQL's
	// 64-char lock-name limit - which would truncate and collide DIFFERENT orders.
	private static function order_init_lock_name($orderId) {
		global $wpdb;

		return 'nmm_oinit_' . (int) $orderId . '_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 12);
	}

	/**
	 * Acquire the per-order initialization lock so two concurrent first loads of
	 * the thank-you page cannot both allocate a payment address for one order.
	 * GET_LOCK is atomic across connections, owned by the acquiring connection,
	 * and released automatically if that PHP process dies, so a crash can never
	 * wedge it. Returns the raw GET_LOCK result: '1' acquired, '0' timed out,
	 * null if advisory locks are unavailable on this host. The caller must call
	 * release_order_init_lock() only when this returned '1'.
	 */
	public static function acquire_order_init_lock($orderId, $timeoutSeconds = 15) {
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::order_init_lock_name($orderId), $timeoutSeconds));
	}

	public static function release_order_init_lock($orderId) {
		global $wpdb;

		$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::order_init_lock_name($orderId)));
	}

	/**
	 * The pinned request currently in flight: the token that identifies it, the
	 * URL it was issued for, the cURL options to install, and whether the cURL
	 * transport actually installed them. Static because http_api_curl is a
	 * global action - the callback has no other route back to this request.
	 */
	private static $pinnedRequest = null;

	/**
	 * POST through WordPress's HTTP API with extra cURL options installed from
	 * the http_api_curl action, instead of driving cURL ourselves.
	 *
	 * WordPress fires http_api_curl only when it selects its cURL transport,
	 * and its streams transport resolves the hostname AGAIN when it connects -
	 * the DNS-rebinding window an IP pin exists to close. So the streams
	 * transport is filtered off for the duration of this call: when cURL is
	 * unavailable WordPress returns its own "no HTTP transports available"
	 * error rather than quietly connecting unpinned. Whether the callback ran
	 * is then checked as a second gate, so a transport that ignores the action
	 * can never silently drop the pin (fail closed, never fail open).
	 *
	 * Returns a wp_remote-shaped response array, or WP_Error.
	 */
	public static function post_with_curl_options($url, $args, $curlOptions) {
		$token = 'nmm_' . md5(uniqid('', true));

		self::$pinnedRequest = array(
			'token'   => $token,
			'url'     => $url,
			'options' => $curlOptions,
			'applied' => false,
		);

		$args['nmm_pinned_token'] = $token;
		$args['redirection'] = 0; // never follow a redirect to another target

		add_action('http_api_curl', array(__CLASS__, 'apply_pinned_curl_options'), 10, 3);
		add_filter('use_streams_transport', '__return_false', 99);

		$response = wp_remote_post($url, $args);

		remove_filter('use_streams_transport', '__return_false', 99);
		remove_action('http_api_curl', array(__CLASS__, 'apply_pinned_curl_options'), 10);

		$applied = !empty(self::$pinnedRequest['applied']);
		self::$pinnedRequest = null;

		if (!$applied) {
			return new WP_Error(
				'nmm_pin_unavailable',
				'The request was not sent over a connection that could be pinned to the validated address.'
			);
		}

		return $response;
	}

	/**
	 * http_api_curl callback. Public because WordPress invokes it; a no-op
	 * unless a pinned request is in flight. The token and URL are both matched
	 * before anything is installed, so options meant for our request - which
	 * may carry RPC credentials - can never be applied to another plugin's
	 * request that happens to be dispatched inside the same window.
	 */
	public static function apply_pinned_curl_options($handle, $parsedArgs = array(), $requestUrl = '') {
		if (self::$pinnedRequest === null) {
			return;
		}

		$token = isset($parsedArgs['nmm_pinned_token']) ? $parsedArgs['nmm_pinned_token'] : '';

		if ($token !== self::$pinnedRequest['token'] || $requestUrl !== self::$pinnedRequest['url']) {
			return;
		}

		if (!empty(self::$pinnedRequest['options'])) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- this configures WordPress's OWN cURL handle from the http_api_curl action, which is the documented way to set options the HTTP API does not expose (CURLOPT_RESOLVE pinning and digest auth). No request is issued here.
			curl_setopt_array($handle, self::$pinnedRequest['options']);
		}

		self::$pinnedRequest['applied'] = true;
	}

}

?>
