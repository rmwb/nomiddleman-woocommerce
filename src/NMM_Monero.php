<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monero Autopay via the merchant's own monero-wallet-rpc (a view-only
 * wallet is sufficient). Each order gets a fresh subaddress, and incoming
 * transfers are read over JSON-RPC - fully non-custodial, and the view key
 * never leaves the merchant's infrastructure.
 *
 * Every request goes through validate_rpc_url() + plan_request(): when cURL is
 * available the connection is pinned to the validated IP (CURLOPT_RESOLVE) so a
 * hostname cannot be rebound to a private target between validation and connect.
 * monero-wallet-rpc uses HTTP digest auth when --rpc-login is set, which
 * WordPress's HTTP API does not speak, so digest is layered onto that cURL path.
 */
class NMM_Monero {

	private static function settings() {
		return new NMM_Settings(get_option(NMM_REDUX_ID));
	}

	public static function rpc($method, $params = array()) {
		$settings = self::settings();
		$url = $settings->get_xmr_rpc_url();

		if ($url === '') {
			return new WP_Error('nmm_xmr', 'Monero wallet RPC is not configured.');
		}

		$target = self::validate_rpc_url($url);
		if (is_wp_error($target)) {
			return $target;
		}

		$payload = json_encode(array(
			'jsonrpc' => '2.0',
			'id' => '0',
			'method' => $method,
			'params' => empty($params) ? new stdClass() : $params,
		));

		$user = $settings->get_xmr_rpc_user();
		$pass = $settings->get_xmr_rpc_password();

		// Pinning needs both cURL and the CURLOPT_RESOLVE option; a build missing
		// the latter has cURL but cannot pin a hostname, so treat it as unpinnable.
		$hasCurl = function_exists('curl_init');
		$canPin = $hasCurl && defined('CURLOPT_RESOLVE');
		$plan = self::plan_request($target, $hasCurl, $canPin, $user !== '');

		if ($plan['transport'] === 'reject') {
			return new WP_Error('nmm_xmr', 'Monero wallet RPC host cannot be reached safely: without the cURL extension the connection cannot be pinned to the validated address, so a hostname could be rebound to a private target. Use an IP-literal RPC URL, or enable the cURL PHP extension.');
		}

		if ($plan['transport'] === 'curl') {
			$ch = curl_init($url);
			$curlOpts = array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 20,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $payload,
				CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
				// Restrict to HTTP(S) and never follow a redirect (which could be
				// steered to a file:// or internal target).
				CURLOPT_FOLLOWLOCATION => false,
			);
			// Digest auth only when the merchant configured credentials; the RPC
			// is otherwise open and WordPress's HTTP API cannot speak digest.
			if ($plan['digest']) {
				$curlOpts[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
				$curlOpts[CURLOPT_USERPWD] = $user . ':' . $pass;
			}
			if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
				$curlOpts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
				$curlOpts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
			}
			// Pin the connection to the exact IP we validated, so a hostname that
			// re-resolves to a private address between validation and connect
			// (DNS rebinding) cannot redirect us there.
			if (defined('CURLOPT_RESOLVE') && $target['ip'] !== '') {
				$curlOpts[CURLOPT_RESOLVE] = array($target['host'] . ':' . $target['port'] . ':' . $target['ip']);
			}
			curl_setopt_array($ch, $curlOpts);
			$body = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($body === false || $code !== 200) {
				return new WP_Error('nmm_xmr', 'Monero wallet RPC unreachable (http ' . $code . ').');
			}
		}
		else {
			// No cURL: plan_request only reaches here for an IP-literal target,
			// where there is no DNS to rebind and the request goes to exactly the
			// address we validated. (Hostnames are rejected above.)
			$response = wp_remote_post($url, array(
				'headers' => array('Content-Type' => 'application/json'),
				'body' => $payload,
				'timeout' => 20,
				'redirection' => 0, // never follow a redirect to another target
			));

			if (is_wp_error($response) || $response['response']['code'] !== 200) {
				return new WP_Error('nmm_xmr', 'Monero wallet RPC unreachable.');
			}

			$body = $response['body'];
		}

		$decoded = json_decode($body);

		if (!is_object($decoded)) {
			return new WP_Error('nmm_xmr', 'Monero wallet RPC returned an unreadable response.');
		}

		if (isset($decoded->error)) {
			$message = isset($decoded->error->message) && $decoded->error->message !== ''
				? $decoded->error->message
				: 'code ' . (isset($decoded->error->code) ? $decoded->error->code : '?') . ' (is the wallet RPC connected to a daemon?)';

			return new WP_Error('nmm_xmr', 'Monero wallet RPC error: ' . $message);
		}

		if (!isset($decoded->result)) {
			return new WP_Error('nmm_xmr', 'Monero wallet RPC returned no result.');
		}

		return $decoded->result;
	}

	/**
	 * Vet the merchant-configured Monero RPC URL before we request it, to stop a
	 * settings value (a manage_options user, or a site admin on multisite who is
	 * NOT a host admin) from steering the server at internal services, cloud
	 * metadata, loopback, or non-HTTP schemes (SSRF). Returns an array
	 * { host, port, ip } on success or a WP_Error to abort.
	 *
	 * Monero wallet RPC legitimately runs on localhost or a private LAN, so
	 * private targets are permitted for single-site installs (the merchant owns
	 * the server). On multisite they require an explicit opt-in - the
	 * NMM_XMR_ALLOW_PRIVATE_RPC constant or the nmm_xmr_allow_private_rpc filter.
	 */
	public static function validate_rpc_url($url) {
		$parts = wp_parse_url(trim((string) $url));
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return new WP_Error('nmm_xmr', 'Monero wallet RPC URL is malformed.');
		}
		if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
			return new WP_Error('nmm_xmr', 'Monero wallet RPC URL must use http or https.');
		}

		$host = $parts['host'];
		$port = isset($parts['port']) ? (int) $parts['port'] : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
		$isLiteral = (bool) filter_var($host, FILTER_VALIDATE_IP);
		$ip = $isLiteral ? $host : gethostbyname($host);

		$isPrivate = (strtolower($host) === 'localhost') || self::is_private_or_reserved_ip($ip);
		if ($isPrivate) {
			$allow = defined('NMM_XMR_ALLOW_PRIVATE_RPC') ? (bool) NMM_XMR_ALLOW_PRIVATE_RPC : !is_multisite();
			$allow = (bool) apply_filters('nmm_xmr_allow_private_rpc', $allow, $url, $host, $ip);
			if (!$allow) {
				return new WP_Error('nmm_xmr', 'Monero wallet RPC points at a private or loopback address, which is not permitted here. Define NMM_XMR_ALLOW_PRIVATE_RPC or use the nmm_xmr_allow_private_rpc filter to allow it.');
			}
		}

		return array(
			'host' => $host,
			'port' => $port,
			'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
			// is_literal: the URL host is already an IP, so there is no DNS to
			// rebind. is_private: resolves into private/loopback/reserved space.
			'is_literal' => $isLiteral,
			'is_private' => $isPrivate,
		);
	}

	/**
	 * Decide how to send an RPC request to an already-validated target so the
	 * connection cannot be steered to a different address than the one we vetted
	 * (DNS rebinding). Pure and side-effect free so it can be unit-tested.
	 *
	 * $hasCurl is whether curl_init() exists; $canPin is whether the cURL build
	 * can actually pin a hostname to a vetted IP (curl_init AND CURLOPT_RESOLVE).
	 * The two are distinct: an ancient libcurl without CURLOPT_RESOLVE has cURL
	 * but cannot pin, and must not be allowed to send an unpinned hostname
	 * request. IP literals never need pinning (there is no DNS to rebind).
	 *
	 * Returns array( 'transport' => 'curl'|'wp_remote'|'reject',
	 *                'digest' => bool, 'reason' => string ).
	 *
	 * - curl:      the host is an IP literal (safe on any cURL build), or it is a
	 *              hostname and the build can pin it to the validated IP. The only
	 *              rebinding-immune transport; digest auth is added only with creds.
	 * - wp_remote: no cURL, but the host is an IP literal - there is no DNS to
	 *              rebind, so a plain request reaches exactly the vetted address.
	 * - reject:    a hostname (public OR private) we cannot pin - no cURL, or a
	 *              cURL build without CURLOPT_RESOLVE. Even a public host is unsafe
	 *              because it re-resolves at connect time and could land in private
	 *              space (rebinding), so we refuse rather than reopen the SSRF path.
	 */
	public static function plan_request($target, $hasCurl, $canPin, $hasCreds) {
		$isLiteral = !empty($target['is_literal']);

		if ($hasCurl && ($isLiteral || ($canPin && $target['ip'] !== ''))) {
			return array('transport' => 'curl', 'digest' => (bool) $hasCreds, 'reason' => $isLiteral ? 'ip-literal-curl' : 'pinned');
		}

		if ($isLiteral) {
			return array('transport' => 'wp_remote', 'digest' => false, 'reason' => 'ip-literal');
		}

		return array('transport' => 'reject', 'digest' => false, 'reason' => 'unpinnable-hostname');
	}

	// True for loopback / private / link-local (incl. 169.254.169.254 cloud
	// metadata) / reserved addresses, and for anything that will not resolve.
	private static function is_private_or_reserved_ip($ip) {
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return true;
		}
		return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}

	// Fresh subaddress for an order; throws so the existing checkout
	// error handling cancels the order rather than showing no address.
	public static function create_subaddress($orderId) {
		$result = self::rpc('create_address', array(
			'account_index' => 0,
			'label' => 'order-' . $orderId,
		));

		if (is_wp_error($result) || !isset($result->address)) {
			throw new \Exception(esc_html__('Could not create a Monero payment address. Please try again or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
		}

		return $result->address;
	}

	/**
	 * Incoming transfers for a specific subaddress, in the shape the
	 * payment matcher expects. Amounts are atomic units (1e-12 XMR),
	 * matching XMR's round precision of 12.
	 */
	public static function get_address_transactions($address) {
		$params = array(
			'in' => true,
			'pool' => true,
			'account_index' => 0,
		);

		// let the wallet filter to this order's subaddress instead of
		// returning every transfer in the account; fall back to the full
		// list (client-side filtered below) if the lookup fails
		$indexResult = self::rpc('get_address_index', array('address' => $address));

		if (!is_wp_error($indexResult) && isset($indexResult->index->minor)) {
			$params['subaddr_indices'] = array((int) $indexResult->index->minor);
		}

		$result = self::rpc('get_transfers', $params);

		if (is_wp_error($result)) {
			NMM_Util::log(__FILE__, __LINE__, 'XMR RPC failed: ' . $result->get_error_message());

			return array(
				'result' => 'error',
				'total_received' => '',
			);
		}

		$transactions = array();

		foreach (array('in', 'pool') as $bucket) {
			if (!isset($result->{$bucket}) || !is_array($result->{$bucket})) {
				continue;
			}

			foreach ($result->{$bucket} as $transfer) {
				if (!isset($transfer->address, $transfer->amount, $transfer->txid) || $transfer->address !== $address) {
					continue;
				}

				$transactions[] = new NMM_Transaction(
					$transfer->amount,
					isset($transfer->confirmations) ? (int) $transfer->confirmations : 0,
					isset($transfer->timestamp) ? (int) $transfer->timestamp : time(),
					$transfer->txid
				);
			}
		}

		return array(
			'result' => 'success',
			'transactions' => $transactions,
		);
	}
}
