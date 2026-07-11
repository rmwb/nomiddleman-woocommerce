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
 * monero-wallet-rpc uses HTTP digest auth when --rpc-login is set, which
 * WordPress's HTTP API does not speak; in that case we fall back to curl.
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

		$payload = json_encode(array(
			'jsonrpc' => '2.0',
			'id' => '0',
			'method' => $method,
			'params' => empty($params) ? new stdClass() : $params,
		));

		$user = $settings->get_xmr_rpc_user();
		$pass = $settings->get_xmr_rpc_password();

		if ($user !== '' && function_exists('curl_init')) {
			// digest auth path
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 20,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $payload,
				CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
				CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
				CURLOPT_USERPWD => $user . ':' . $pass,
			));
			$body = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($body === false || $code !== 200) {
				return new WP_Error('nmm_xmr', 'Monero wallet RPC unreachable (http ' . $code . ').');
			}
		}
		else {
			$response = wp_remote_post($url, array(
				'headers' => array('Content-Type' => 'application/json'),
				'body' => $payload,
				'timeout' => 20,
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
