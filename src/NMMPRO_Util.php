<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMMPRO_Util {

	/**
	 * Operational log. Routes to WooCommerce's logger (WooCommerce > Status >
	 * Logs, source "nomiddleman") when available, otherwise error_log().
	 *
	 * Levels follow WC_Log_Levels. Warnings and above are always emitted so an
	 * operator sees events that may need intervention (enqueue/migration
	 * failures, cron-lock degradation, address-claim exhaustion, payment
	 * collisions, ...). Verbose debug/info tracing is emitted only when debug
	 * logging is enabled (WP_DEBUG, the NMMPRO_DEBUG_LOG constant, or the
	 * nmmpro_debug_logging filter), so production is not flooded. Identical
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
			$throttleKey = 'nmmpro_log_' . md5($level . '|' . $entry);
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
		if ((NMMPRO_Compat::config('NMMPRO_DEBUG_LOG') !== null)) {
			return (bool) NMMPRO_Compat::config('NMMPRO_DEBUG_LOG');
		}
		$default = defined('WP_DEBUG') && WP_DEBUG;
		return function_exists('apply_filters') ? (bool) NMMPRO_Compat::filter('nmmpro_debug_logging', $default) : $default;
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

	/**
	 * The cron pass's exclusivity, for the writes that authorise cancelling an
	 * order: coverage stamps, exclusion sets and the expiry pass itself. Null
	 * outside a cron pass. 'held' latches to false the first time it is found
	 * lost - a lock that came back would not undo what a concurrent pass may
	 * have written meanwhile.
	 *
	 * @var array{held: bool, reason: string}|null
	 */
	private static $cronFence = null;

	/**
	 * Start a cron pass's fence from the raw GET_LOCK result for the cron lock.
	 *
	 * Holding the lock is not enough on its own. Before MySQL 5.7.5 (and
	 * MariaDB 10.0.2) a connection could hold only ONE named lock: taking a
	 * second released the first. The pass takes a per-address lock for every
	 * address it matches, so on such a server the cron lock is gone from the
	 * first address onward and two passes can both believe they are exclusive.
	 * Probe the behaviour instead of trusting a version string (forks report
	 * those differently): take one more lock, then ask whether this connection
	 * still owns the cron lock. Only the cron-lock holder ever probes, so the
	 * probe lock is uncontended.
	 *
	 * Returns 'held', or why the pass is unfenced: 'unavailable' (no advisory
	 * locks on this host) or 'single-lock' (the server drops the first lock).
	 */
	public static function begin_cron_fence($lockAcquired) {
		global $wpdb;

		if ($lockAcquired !== '1') {
			self::$cronFence = array('held' => false, 'reason' => 'unavailable');
			return 'unavailable';
		}

		// Derived from the cron lock's own (already site-scoped) name.
		$probe = 'nmm_fprobe_' . substr(md5(self::cron_lock_name()), 0, 12);
		$probed = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $probe));
		$stillHeld = self::cron_lock_owned();
		if ($probed === '1') {
			$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $probe));
		}

		self::$cronFence = $stillHeld
			? array('held' => true, 'reason' => 'held')
			: array('held' => false, 'reason' => 'single-lock');
		return self::$cronFence['reason'];
	}

	/**
	 * May this pass still write state that authorises cancellation? True
	 * outside a cron pass (direct calls behave as they always have). Inside
	 * one, re-asks the server every time: WordPress reconnects silently after
	 * a dropped connection, and every advisory lock dies with the old one.
	 *
	 * @phpstan-impure Asks the database each call; the answer can change.
	 */
	public static function cron_fence_held() {
		if (self::$cronFence === null) {
			return true;
		}
		if (!self::$cronFence['held']) {
			return false;
		}
		if (!self::cron_lock_owned()) {
			self::$cronFence = array('held' => false, 'reason' => 'lost');
			self::log(__FILE__, __LINE__, 'Cron lock lost mid-pass; this pass certifies and cancels nothing further.', 'warning');
			return false;
		}
		return true;
	}

	public static function end_cron_fence() {
		self::$cronFence = null;
	}

	/**
	 * The current pass's fence as 'held', 'unavailable', 'single-lock' or
	 * 'lost'; null outside a cron pass. Does not re-query the server.
	 */
	public static function cron_fence_state() {
		return self::$cronFence === null ? null : self::$cronFence['reason'];
	}

	/**
	 * Write an option that authorises expiry - coverage, exclusions, the sweep
	 * cursor and start - ONLY if this connection still owns the cron lock at
	 * the moment of the write. The ownership test is part of the statement
	 * itself (INSERT ... SELECT ... WHERE IS_USED_LOCK() = CONNECTION_ID()), so
	 * no check-then-write gap exists: a pass that lost its lock a microsecond
	 * earlier writes nothing. A refused or failed write latches the fence lost,
	 * so every later certification write in the pass is skipped as well.
	 *
	 * Outside a cron pass this is a plain option update, as before.
	 *
	 * Returns true when the value is stored (or was already stored) under an
	 * owned lock, false otherwise.
	 */
	public static function fenced_update_option($name, $value) {
		global $wpdb;

		if (self::$cronFence === null) {
			NMMPRO_Compat::update_option($name, $value, false);
			// update_option() returns false for "unchanged" as well as "failed",
			// and get_option() can answer from a stale cache, so confirm against
			// the stored bytes themselves. WordPress stores a scalar as its
			// string form and anything else serialized.
			$stored = $wpdb->get_var($wpdb->prepare("SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name));
			return $stored !== null && $stored === (string) maybe_serialize($value);
		}
		if (!self::$cronFence['held']) {
			return false;
		}

		// 'off' is the non-autoload value from WordPress 6.6; 'no' before it.
		$autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
		$serialized = maybe_serialize($value);
		$affected = $wpdb->query($wpdb->prepare(
			"INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`)
			 SELECT %s, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
			 ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`)",
			$name, $serialized, $autoload, self::cron_lock_name()
		));
		// The write bypassed the options API, so drop every cached copy.
		wp_cache_delete($name, 'options');
		$notoptions = wp_cache_get('notoptions', 'options');
		if (is_array($notoptions) && isset($notoptions[$name])) {
			unset($notoptions[$name]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}
		wp_cache_delete('alloptions', 'options');

		if ($affected === false) {
			self::$cronFence = array('held' => false, 'reason' => 'lost');
			self::log(__FILE__, __LINE__, 'Could not write ' . $name . ' (' . $wpdb->last_error . '); this pass certifies nothing further.', 'error');
			return false;
		}
		if ($affected > 0) {
			return true;
		}
		// 0 rows: either the stored value was already identical, or the lock
		// was not ours and nothing was written. Only ownership tells them apart.
		if (!self::cron_lock_owned()) {
			self::$cronFence = array('held' => false, 'reason' => 'lost');
			self::log(__FILE__, __LINE__, 'Cron lock lost before writing ' . $name . '; this pass certifies nothing further.', 'warning');
			return false;
		}
		return true;
	}

	/**
	 * Is a cron pass running right now on this site? Only meaningful where
	 * advisory locks work; used by the downgrade procedure to confirm the
	 * background job has drained after it was paused.
	 *
	 * @phpstan-impure
	 */
	public static function cron_pass_running() {
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', self::cron_lock_name())) === '0';
	}

	/**
	 * Does THIS connection still own the per-address match lock? A worker that
	 * took the lock and then paused can have lost it to a silent reconnect,
	 * after which another worker may own the address. Checked immediately
	 * before an irreversible WooCommerce side effect.
	 *
	 * @phpstan-impure Asks the database each call.
	 */
	public static function address_match_lock_owned($cryptoId, $address) {
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::address_match_lock_name($cryptoId, $address))) === '1';
	}

	// Ownership, not mere use: IS_USED_LOCK returns the OWNER's connection id,
	// so "somebody holds it" is not proof that we do. One query, so both values
	// come from the same moment.
	private static function cron_lock_owned() {
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::cron_lock_name())) === '1';
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

	// Per-(currency, address) advisory lock name. The address is hashed rather
	// than embedded: addresses vary in length and can be long (Monero
	// subaddresses are 95-106 chars), which would blow past MySQL's 64-char
	// lock-name limit and truncate DIFFERENT addresses into one lock. Site
	// scoping matches the other lock names.
	private static function address_match_lock_name($cryptoId, $address) {
		global $wpdb;

		return 'nmm_amatch_' . substr(md5(DB_NAME . '|' . $wpdb->prefix . '|' . $cryptoId . '|' . $address), 0, 24);
	}

	/**
	 * Serialize payment matching for one address. Two verifiers working the
	 * same address concurrently can otherwise credit one transaction to two
	 * orders: the winner's claim removes its order from the unpaid set before
	 * the transaction is durably recorded as consumed, so the other worker
	 * still sees that transaction as available for a sibling order.
	 *
	 * Default timeout 0: if another worker holds this address there is nothing
	 * to wait for - it is already doing this exact work, and the sweep revisits
	 * the address on the next tick.
	 *
	 * Returns the raw GET_LOCK result: '1' acquired, '0' held by another
	 * connection, null if advisory locks are unavailable on this host. Release
	 * only when this returned '1'.
	 */
	public static function acquire_address_match_lock($cryptoId, $address, $timeoutSeconds = 0) {
		global $wpdb;

		$name = self::address_match_lock_name($cryptoId, $address);
		// Named locks are recursive per connection (MySQL 5.7.5+, MariaDB
		// 10.0.2+): a second GET_LOCK from this same connection "succeeds".
		// Code reached from inside an address's work - a WooCommerce hook
		// fired by a cancellation or a completion - must see it as busy, or a
		// recovery pass could settle the very lease its caller still holds.
		if (isset(self::$heldAddressLocks[$name])) {
			return '0';
		}
		$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeoutSeconds));
		if ($acquired === '1') {
			self::$heldAddressLocks[$name] = true;
		}
		return $acquired;
	}

	public static function release_address_match_lock($cryptoId, $address) {
		global $wpdb;

		$name = self::address_match_lock_name($cryptoId, $address);
		if (!isset(self::$heldAddressLocks[$name])) {
			return; // never ours (busy or unavailable): nothing to release
		}
		unset(self::$heldAddressLocks[$name]);
		$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
	}

	/** @var array<string, true> address locks this process currently holds */
	private static $heldAddressLocks = array();

	/**
	 * The pinned request currently in flight: the token that identifies it, the
	 * URL it was issued for, the cURL options to install, and whether the cURL
	 * transport actually installed them. Static because http_api_curl is a
	 * global action - the callback has no other route back to this request.
	 *
	 * @var array{token: string, url: string, options: array<int, mixed>, applied: bool}|null
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
		$token = 'nmmpro_' . md5(uniqid('', true));

		self::$pinnedRequest = array(
			'token'   => $token,
			'url'     => $url,
			'options' => $curlOptions,
			'applied' => false,
		);

		$args['nmmpro_pinned_token'] = $token;
		$args['redirection'] = 0; // never follow a redirect to another target

		add_action('http_api_curl', array(__CLASS__, 'apply_pinned_curl_options'), 10, 3);
		add_filter('use_streams_transport', '__return_false', 99);

		$response = wp_remote_post($url, $args);

		remove_filter('use_streams_transport', '__return_false', 99);
		remove_action('http_api_curl', array(__CLASS__, 'apply_pinned_curl_options'), 10);

		$applied = self::pinned_request_applied();
		self::$pinnedRequest = null;

		if (!$applied) {
			return new WP_Error(
				'nmmpro_pin_unavailable',
				'The request was not sent over a connection that could be pinned to the validated address.'
			);
		}

		return $response;
	}

	/**
	 * Did the http_api_curl callback install this request's options?
	 *
	 * Read through a method rather than inline: the callback flips the flag
	 * while wp_remote_post() is running, which static analysis cannot see from
	 * the assignment in post_with_curl_options(), and an inline check there is
	 * reported as always-false.
	 */
	private static function pinned_request_applied() {
		return self::$pinnedRequest !== null && !empty(self::$pinnedRequest['applied']);
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

		$token = isset($parsedArgs['nmmpro_pinned_token']) ? $parsedArgs['nmmpro_pinned_token'] : '';

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
