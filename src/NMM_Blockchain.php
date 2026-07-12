<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Class that communicates with various blockchains via HTTP
class NMM_Blockchain {

	// Hosts that ask for a minimum spacing between requests (seconds)
	private static $hostCooldowns = array(
		'chainz.cryptoid.info' => 10,
	);

	// Blockscout instance per token; anything unlisted lives on Ethereum mainnet
	private static $blockscoutHosts = array(
		'USDTPOL' => 'polygon.blockscout.com',
		'USDCPOL' => 'polygon.blockscout.com',
		'USDTARB' => 'arbitrum.blockscout.com',
		'USDCARB' => 'arbitrum.blockscout.com',
		'USDCBAS' => 'base.blockscout.com',
	);

	// Rate-limit-aware wrapper around wp_remote_get: skips hosts that are in
	// backoff (from earlier 429/5xx responses) and records failures so a
	// misbehaving or throttling API is left alone with exponential backoff.
	private static function api_get($request, $args = array()) {
		// lets merchants point a coin at their own node or explorer instance
		$request = apply_filters('nmm_api_url', $request);
		$host = (string) parse_url($request, PHP_URL_HOST);

		if (self::host_unavailable($host)) {
			return array('body' => 'nmm-rate-limit-backoff', 'response' => array('code' => 429));
		}

		$response = wp_remote_get($request, $args);

		self::record_api_result($host, $response);

		return $response;
	}

	private static function api_post($request, $args = array()) {
		$request = apply_filters('nmm_api_url', $request);
		$host = (string) parse_url($request, PHP_URL_HOST);

		if (self::host_unavailable($host)) {
			return array('body' => 'nmm-rate-limit-backoff', 'response' => array('code' => 429));
		}

		$response = wp_remote_post($request, $args);

		self::record_api_result($host, $response);

		return $response;
	}

	private static function host_unavailable($host) {
		if ($host === '') {
			return false;
		}

		if (get_transient('nmm_backoff_' . md5($host)) !== false) {
			return true;
		}

		if (isset(self::$hostCooldowns[$host]) && get_transient('nmm_cooldown_' . md5($host)) !== false) {
			return true;
		}

		return false;
	}

	private static function record_api_result($host, $response) {
		if ($host === '') {
			return;
		}

		if (isset(self::$hostCooldowns[$host])) {
			set_transient('nmm_cooldown_' . md5($host), 1, self::$hostCooldowns[$host]);
		}

		$code = (!is_wp_error($response) && isset($response['response']['code'])) ? (int) $response['response']['code'] : 0;

		$isFailure = is_wp_error($response) || $code === 429 || $code === 402 || $code >= 500;

		if ($isFailure) {
			$failures = (int) get_transient('nmm_apifail_' . md5($host)) + 1;
			set_transient('nmm_apifail_' . md5($host), $failures, HOUR_IN_SECONDS);

			// 60s, 120s, 240s ... capped at 30 minutes
			$backoff = min(60 * pow(2, $failures - 1), 30 * MINUTE_IN_SECONDS);
			set_transient('nmm_backoff_' . md5($host), 1, $backoff);
			NMM_Util::log(__FILE__, __LINE__, 'API host ' . $host . ' failing (http ' . $code . '), backing off ' . $backoff . 's');
		}
		elseif ($code === 200) {
			delete_transient('nmm_apifail_' . md5($host));
		}
	}

	// Optional BlockCypher token raises their keyless rate limits substantially
	private static function blockcypher_token_query($urlHasQuery) {
		$nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));
		$token = $nmmSettings->get_blockcypher_token();

		if ($token === '') {
			return '';
		}

		return ($urlHasQuery ? '&' : '?') . 'token=' . rawurlencode($token);
	}


	public static function get_blockchaininfo_total_received_for_btc_address($address, $requiredConfirmations) {
		$userAgentString = self::get_user_agent_string();
		$request = 'https://blockchain.info/q/getreceivedbyaddress/' . $address . '?confirmations=' . $requiredConfirmations;

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceivedSatoshi = (float) json_decode($response['body']);
		$result = array (
			'result' => 'success',
			'total_received' => $totalReceivedSatoshi / 100000000,
		);

		return $result;
	}

	public static function get_mempoolspace_total_received_for_btc_address($address) {
		$userAgentString = self::get_user_agent_string();

		$request = 'https://mempool.space/api/address/' . $address;

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (!isset($body->chain_stats->funded_txo_sum)) {
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceivedSatoshi = (float) $body->chain_stats->funded_txo_sum;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceivedSatoshi / 100000000,
		);

		return $result;
	}

	public static function get_blockstream_total_received_for_btc_address($address) {
		$userAgentString = self::get_user_agent_string();

		$request = 'https://blockstream.info/api/address/' . $address;

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (!isset($body->chain_stats->funded_txo_sum)) {
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceivedSatoshi = (float) $body->chain_stats->funded_txo_sum;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceivedSatoshi / 100000000,
		);

		return $result;
	}

	public static function get_blockcypher_total_received_for_ltc_address($address, $requiredConfirmations) {
		$userAgentString = self::get_user_agent_string();
		
		$request = 'https://api.blockcypher.com/v1/ltc/main/addrs/' . $address . '?confirmations=' . $requiredConfirmations . self::blockcypher_token_query(true);

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceivedMmltc = json_decode($response['body'])->total_received;
		$totalReceived = $totalReceivedMmltc / 100000000;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceived,
		);

		return $result;
	}

	public static function get_litecoinspace_total_received_for_ltc_address($address) {
		$userAgentString = self::get_user_agent_string();

		$request = 'https://litecoinspace.org/api/address/' . $address;

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (!isset($body->chain_stats->funded_txo_sum)) {
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceived = (float) $body->chain_stats->funded_txo_sum / 100000000;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceived,
		);

		return $result;
	}

	public static function get_qtuminfo_total_received_for_qtum_address($address) {
		$userAgentString = self::get_user_agent_string();
		
		$request = 'https://qtum.info/api/address/' . $address;

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}
		
		$totalReceived = (float) json_decode($response['body'])->totalReceived / 100000000;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceived,
		);

		return $result;
	}

	public static function get_dashblockexplorer_total_received_for_dash_address($address) {
		$userAgentString = self::get_user_agent_string();
		
		$request = 'https://insight.dash.org/insight-api/addr/' . $address . '/totalReceived';

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}
		
		$totalReceived = (float) json_decode($response['body']) / 100000000;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceived,
		);

		return $result;
	}

	public static function get_blockcypher_total_received_for_doge_address($address) {
		$userAgentString = self::get_user_agent_string();

		$request = 'https://api.blockcypher.com/v1/doge/main/addrs/' . $address . '/balance' . self::blockcypher_token_query(false);

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (!isset($body->total_received)) {
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$result = array (
			'result' => 'success',
			'total_received' => (float) $body->total_received / 100000000,
		);

		return $result;
	}

	public static function get_blockbook_total_received_for_xmy_address($address) {		
		$userAgentString = self::get_user_agent_string();
		
		$request = 'https://blockbook.myralicious.com/api/address/' . $address;

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceived = (float) json_decode($response['body'])->balance;

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceived,
		);

		return $result;
	}

	public static function get_chainz_total_received_for_btx_address($address) {
		$userAgentString = self::get_user_agent_string();

		// chainz answers this query keyless; returns a plain decimal BTX amount.
		// Their API etiquette asks for at most one request every 10 seconds.
		$request = 'https://chainz.cryptoid.info/btx/api.dws?q=getreceivedbyaddress&a=' . rawurlencode($address);

		$args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);
		if (is_wp_error($response) || $response['response']['code'] !== 200 || !is_numeric(trim($response['body']))) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
			$result = array (
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$totalReceived = (float) trim($response['body']);

		$result = array (
			'result' => 'success',
			'total_received' => $totalReceived,
		);

		return $result;
	}


	public static function get_ada_address_transactions($address) {
		// Koios public tier: list txs for the address, then fetch each tx's outputs
		$request = 'https://api.koios.rest/api/v1/address_txs';

		$response = self::api_post($request, array(
			'headers' => array('Content-Type' => 'application/json'),
			'body' => json_encode(array('_addresses' => array($address))),
		));

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$rawTxList = json_decode($response['body']);

		if (!is_array($rawTxList)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}

		// most recent first, and cap the outputs lookup to a sane batch
		usort($rawTxList, function($a, $b) {
			return $b->block_height <=> $a->block_height;
		});
		$rawTxList = array_slice($rawTxList, 0, 25);

		$txHashes = array();
		$txTimes = array();
		foreach ($rawTxList as $row) {
			$txHashes[] = $row->tx_hash;
			$txTimes[$row->tx_hash] = $row->block_time;
		}

		$transactions = array();

		if (count($txHashes) > 0) {
			$response2 = self::api_post('https://api.koios.rest/api/v1/tx_utxos', array(
				'headers' => array('Content-Type' => 'application/json'),
				'body' => json_encode(array('_tx_hashes' => $txHashes)),
			));

			if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
				NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( koios tx_utxos ): ' . print_r($response2, true));

				return array(
					'result' => 'error',
					'total_received' => '',
				);
			}

			$utxoRows = json_decode($response2['body']);

			foreach ((array) $utxoRows as $utxoRow) {
				if (!isset($utxoRow->outputs) || !is_array($utxoRow->outputs)) {
					continue;
				}

				// amounts are in lovelace (1e-6 ADA), matching ADA's round precision
				$received = 0;
				foreach ($utxoRow->outputs as $output) {
					if (isset($output->payment_addr->bech32) && $output->payment_addr->bech32 === $address) {
						$received += (float) $output->value;
					}
				}

				if ($received > 0) {
					$transactions[] = new NMM_Transaction($received,
														  10000,
														  isset($txTimes[$utxoRow->tx_hash]) ? $txTimes[$utxoRow->tx_hash] : time(),
														  $utxoRow->tx_hash);
				}
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_bch_address_transactions($address) {
		// Normalize to a prefixed cashaddr, which is what the API returns
		if (strpos($address, 'bitcoincash:') === 0) {
			$addressToMatch = $address;
		}
		elseif ($address[0] === 'p' || $address[0] === 'q') {
			$addressToMatch = 'bitcoincash:' . $address;
		}
		else {
			$addressToMatch = \CashAddress\CashAddress::old2new($address);
			if (strpos($addressToMatch, 'bitcoincash:') !== 0) {
				$addressToMatch = 'bitcoincash:' . $addressToMatch;
			}
		}

		$request = 'https://api.blockchain.info/haskoin-store/bch/address/' . rawurlencode($addressToMatch) . '/transactions/full?limit=50';
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$rawTransactions = json_decode($response['body']);

		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}

		// confirmations are not included, so fetch the current best block height
		$tipHeight = 0;
		$tipResponse = self::api_get('https://api.blockchain.info/haskoin-store/bch/block/best?notx=true');
		if (!is_wp_error($tipResponse) && $tipResponse['response']['code'] === 200) {
			$tipBody = json_decode($tipResponse['body']);
			if (isset($tipBody->height)) {
				$tipHeight = (int) $tipBody->height;
			}
		}

		$transactions = array();

		foreach ($rawTransactions as $rawTransaction) {
			$confirmations = 0;

			if (isset($rawTransaction->block->height) && $tipHeight > 0) {
				$confirmations = $tipHeight - (int) $rawTransaction->block->height + 1;
			}

			foreach ($rawTransaction->outputs as $output) {
				if (!isset($output->address)) {
					continue;
				}

				if ($output->address === $addressToMatch) {
					$transactions[] = new NMM_Transaction(
						$output->value,
						$confirmations,
						$rawTransaction->time,
						$rawTransaction->txid);
				}
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_blk_address_transactions($address) {
		
		$request = 'https://explorer.blackcoin.nl/ext/getaddress/' . $address;

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (property_exists($body, 'error')) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . $body->error);
			$result = array(
				'result' => 'error',
				'total_received' => '',
			);
			return $result;
		}

		$rawTransactionIds = $body->last_txs;
		if (is_array($rawTransactionIds)) {
			// each entry costs one HTTP request; only inspect the most recent ones
			$rawTransactionIds = array_slice($rawTransactionIds, -25);
		}
		if (!is_array($rawTransactionIds)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		
		foreach ($rawTransactionIds as $rawTransactionId) {			
			if ($rawTransactionId->type === 'vout' || $rawTransactionId->type === 'vin') {

				$txId = $rawTransactionId->addresses;

				$request2 = 'https://explorer.blackcoin.nl/api/getrawtransaction?txid=' . $txId . '&decrypt=1';
				
				$response2 = self::api_get($request2);

				if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
					continue;
				}

				$rawTransaction = json_decode($response2['body']);

				$vouts = $rawTransaction->vout;

				foreach ($vouts as $vout) {
					// newer nodes emit scriptPubKey.address (singular); older ones an addresses array
					$voutAddresses = array();
					if (isset($vout->scriptPubKey->addresses) && is_array($vout->scriptPubKey->addresses)) {
						$voutAddresses = $vout->scriptPubKey->addresses;
					}
					elseif (isset($vout->scriptPubKey->address)) {
						$voutAddresses = array($vout->scriptPubKey->address);
					}

					if (in_array($address, $voutAddresses, true)) {
						$transactions[] = new NMM_Transaction($vout->value * 100000000,
															  isset($rawTransaction->confirmations) ? $rawTransaction->confirmations : 0,
															  isset($rawTransaction->time) ? $rawTransaction->time : time(),
															  $rawTransaction->txid);
					}
				}


				
			}			
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_bsv_address_transactions($address) {

		// WhatsOnChain: tx-hash history first, then a bulk lookup for amounts
		$request = 'https://api.whatsonchain.com/v1/bsv/main/address/' . rawurlencode($address) . '/history';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$history = json_decode($response['body']);

		if (!is_array($history)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}

		// newest entries are last in the history; keep the 20 most recent
		$history = array_slice($history, -20);

		$tipHeight = 0;
		$tipResponse = self::api_get('https://api.whatsonchain.com/v1/bsv/main/chain/info');
		if (!is_wp_error($tipResponse) && $tipResponse['response']['code'] === 200) {
			$tipBody = json_decode($tipResponse['body']);
			if (isset($tipBody->blocks)) {
				$tipHeight = (int) $tipBody->blocks;
			}
		}

		$txHashes = array();
		$txHeights = array();
		foreach ($history as $entry) {
			if (isset($entry->tx_hash)) {
				$txHashes[] = $entry->tx_hash;
				$txHeights[$entry->tx_hash] = isset($entry->height) ? (int) $entry->height : 0;
			}
		}

		$transactions = array();

		if (count($txHashes) > 0) {
			$response2 = self::api_post('https://api.whatsonchain.com/v1/bsv/main/txs', array(
				'headers' => array('Content-Type' => 'application/json'),
				'body' => json_encode(array('txids' => $txHashes)),
			));

			if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
				NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( whatsonchain bulk txs ): ' . print_r($response2, true));

				return array(
					'result' => 'error',
					'total_received' => '',
				);
			}

			foreach ((array) json_decode($response2['body']) as $rawTransaction) {
				if (!isset($rawTransaction->vout) || !is_array($rawTransaction->vout)) {
					continue;
				}

				$height = isset($txHeights[$rawTransaction->txid]) ? $txHeights[$rawTransaction->txid] : 0;
				$confirmations = ($height > 0 && $tipHeight > 0) ? $tipHeight - $height + 1 : 0;

				foreach ($rawTransaction->vout as $vout) {
					if (isset($vout->scriptPubKey->addresses) && in_array($address, $vout->scriptPubKey->addresses, true)) {
						$transactions[] = new NMM_Transaction($vout->value * 100000000,
															  $confirmations,
															  isset($rawTransaction->time) ? $rawTransaction->time : time(),
															  $rawTransaction->txid);
					}
				}
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_btc_address_transactions($address) {
		$userAgentString = self::get_user_agent_string();

        $request = 'https://mempool.space/api/address/' . $address . '/txs';

        $args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));
            $request2 = 'https://api.blockcypher.com/v1/btc/main/addrs/' . $address . self::blockcypher_token_query(false);
            $response2 = self::api_get($request2, $args);
            if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
                $result = array(
                    'result' => 'error',
                    'total_received' => '',
                );

                return $result;
            }

            $body = json_decode($response2['body']);

            $rawTransactions = $body->txrefs;
            if (!is_array($rawTransactions)) {
                $result = array(
                    'result' => 'error',
                    'message' => 'No transactions found',
                );

                return $result;
            }
            $transactions = array();
            foreach ($rawTransactions as $rawTransaction) {
                if ($rawTransaction->tx_input_n == -1) {
                    $transactions[] = new NMM_Transaction(
                        $rawTransaction->value,
                        $rawTransaction->confirmations,
                        $rawTransaction->confirmed,
                        $rawTransaction->tx_hash);
                }
            }
            $result = array (
                'result' => 'success',
                'transactions' => $transactions,
            );

            return $result;
		}

		$rawTransactions = json_decode($response['body']);

		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}

		// mempool.space does not include confirmation counts, so fetch the tip height
		$tipHeight = 0;
		$tipResponse = self::api_get('https://mempool.space/api/blocks/tip/height', $args);
		if (!is_wp_error($tipResponse) && $tipResponse['response']['code'] === 200) {
			$tipHeight = (int) $tipResponse['body'];
		}

		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			$confirmations = 0;
			$time = time();

			if (!empty($rawTransaction->status->confirmed) && $tipHeight > 0) {
				$confirmations = $tipHeight - (int) $rawTransaction->status->block_height + 1;
				$time = $rawTransaction->status->block_time;
			}

			foreach ($rawTransaction->vout as $vout) {
				if (isset($vout->scriptpubkey_address) && $vout->scriptpubkey_address === $address) {
					$transactions[] = new NMM_Transaction($vout->value,
														  $confirmations,
														  $time,
														  $rawTransaction->txid);
				}
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_btx_address_transactions($address) {
		
		$request = 'https://insight.bitcore.cc/api/addr/' . $address;
		
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$transactionIds = $body->transactions;
		if (!is_array($transactionIds)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();

		foreach ($transactionIds as $transactionId) {

				$request2 = 'https://insight.bitcore.cc/api/tx/' . $transactionId;
				
				$response2 = self::api_get($request2);

				if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
					continue;
				}

				$rawTransaction = json_decode($response2['body']);

				$vouts = $rawTransaction->vout;

			foreach ($rawTransaction->vout as $vout) {
				if ($vout->scriptPubKey->addresses[0] === $address) {
					$transactions[] = new NMM_Transaction($vout->value * 100000000, 
														  $rawTransaction->confirmations, 
														  $rawTransaction->time,
														  $rawTransaction->txid);		
				}
			}		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}
	
	public static function get_dash_address_transactions($address) {		
		
		$request = 'https://insight.dash.org/insight-api/txs/?address=' . $address;
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->txs;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			foreach ($rawTransaction->vout as $vout) {
				if ($vout->scriptPubKey->addresses[0] === $address) {
					$transactions[] = new NMM_Transaction($vout->value * 100000000, 
														  $rawTransaction->confirmations, 
														  $rawTransaction->time,
														  $rawTransaction->txid);		
				}
			}
			
		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_dcr_address_transactions($address) {
		
		$request = 'https://explorer.dcrdata.org/insight/api/txs/?address=' . $address;
		
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->txs;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			foreach ($rawTransaction->vout as $vout) {
				if ($vout->scriptPubKey->addresses[0] === $address) {
					$transactions[] = new NMM_Transaction($vout->value * 100000000, 
														  $rawTransaction->confirmations, 
														  $rawTransaction->time,
														  $rawTransaction->txid);		
				}
			}
			
		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_doge_address_transactions($address) {

		$request = 'https://api.blockcypher.com/v1/doge/main/addrs/' . $address . self::blockcypher_token_query(false);

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = isset($body->txrefs) ? $body->txrefs : null;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			if ($rawTransaction->tx_input_n == -1) {
				$transactions[] = new NMM_Transaction($rawTransaction->value,
													  $rawTransaction->confirmations,
													  $rawTransaction->confirmed,
													  $rawTransaction->tx_hash);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

    public static function get_dgb_address_transactions($address) {
        // digiexplorer.info runs an Esplora API (same interface as mempool.space)
        $request = 'https://digiexplorer.info/api/address/' . rawurlencode($address) . '/txs';

        $response = self::api_get($request);

        if (is_wp_error($response) || $response['response']['code'] !== 200) {
            NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

            $result = array(
                'result' => 'error',
                'total_received' => '',
            );

            return $result;
        }

        $rawTransactions = json_decode($response['body']);

        if (!is_array($rawTransactions)) {
            $result = array(
                'result' => 'error',
                'message' => 'No transactions found',
            );

            return $result;
        }

        // Esplora omits confirmation counts, so fetch the tip height
        $tipHeight = 0;
        $tipResponse = self::api_get('https://digiexplorer.info/api/blocks/tip/height');
        if (!is_wp_error($tipResponse) && $tipResponse['response']['code'] === 200) {
            $tipHeight = (int) $tipResponse['body'];
        }

        $transactions = array();
        foreach ($rawTransactions as $rawTransaction) {
            $confirmations = 0;
            $time = time();

            if (!empty($rawTransaction->status->confirmed) && $tipHeight > 0) {
                $confirmations = $tipHeight - (int) $rawTransaction->status->block_height + 1;
                $time = $rawTransaction->status->block_time;
            }

            foreach ($rawTransaction->vout as $vout) {
                if (isset($vout->scriptpubkey_address) && $vout->scriptpubkey_address === $address) {
                    $transactions[] = new NMM_Transaction($vout->value,
                        $confirmations,
                        $time,
                        $rawTransaction->txid);
                }
            }
        }

        $result = array (
            'result' => 'success',
            'transactions' => $transactions,
        );

        return $result;
    }

	public static function get_eos_address_transactions($address) {

		// Primary: public Hyperion history API (keyless)
		$request = 'https://eos.hyperion.eosrio.io/v2/history/get_actions?account=' . rawurlencode($address) . '&filter=eosio.token%3Atransfer&limit=50&sort=desc';

		$response = self::api_get($request);

		if (!is_wp_error($response) && $response['response']['code'] === 200) {
			$body = json_decode($response['body']);

			if (isset($body->actions) && is_array($body->actions)) {
				$transactions = array();

				foreach ($body->actions as $action) {
					if (!isset($action->act->data->to, $action->act->data->quantity)) {
						continue;
					}

					$data = $action->act->data;

					if ($data->to !== $address) {
						continue;
					}

					if (isset($data->symbol) && $data->symbol !== 'EOS') {
						continue;
					}

					// quantity is "1.2345 EOS"; EOS has 4 decimal places
					$transactions[] = new NMM_Transaction((float) $data->quantity * 10000,
														  10000,
														  strtotime($action->timestamp),
														  $action->trx_id);
				}

				return array(
					'result' => 'success',
					'transactions' => $transactions,
				);
			}
		}

		NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

		// Fallback: Greymass v1 history (deprecated but maintained)
		$request2 = 'https://eos.greymass.com/v1/history/get_actions';

		$response2 = self::api_post($request2, array(
			'headers' => array('Content-Type' => 'application/json'),
			'body' => json_encode(array(
				'account_name' => $address,
				'pos' => -1,
				'offset' => -100,
			)),
		));

		if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request2 . ' ): ' . print_r($response2, true));

			return array(
				'result' => 'error',
				'total_received' => '',
			);
		}

		$body2 = json_decode($response2['body']);

		if (!isset($body2->actions) || !is_array($body2->actions)) {
			return array(
				'result' => 'error',
				'message' => 'No transactions found',
			);
		}

		$transactions = array();
		$seenTrxIds = array();

		foreach ($body2->actions as $action) {
			if (!isset($action->action_trace->act)) {
				continue;
			}

			$act = $action->action_trace->act;

			if ($act->account !== 'eosio.token' || $act->name !== 'transfer') {
				continue;
			}

			if (!isset($act->data->to, $act->data->quantity) || $act->data->to !== $address) {
				continue;
			}

			if (strpos($act->data->quantity, ' EOS') === false) {
				continue;
			}

			// the same transfer can appear once per notified account; dedupe by trx id
			$trxId = $action->action_trace->trx_id;
			if (isset($seenTrxIds[$trxId])) {
				continue;
			}
			$seenTrxIds[$trxId] = true;

			$transactions[] = new NMM_Transaction((float) $act->data->quantity * 10000,
												  10000,
												  strtotime($action->block_time),
												  $trxId);
		}

		return array(
			'result' => 'success',
			'transactions' => $transactions,
		);
	}

	public static function get_etc_address_transactions($address) {
		
		$request = 'https://blockscout.com/etc/mainnet/api?module=account&action=txlist&address=' . $address;

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->result;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();


		foreach ($rawTransactions as $rawTransaction) {
			
			if (strtolower($rawTransaction->to) === strtolower($address)) {
				
				$transactions[] = new NMM_Transaction($rawTransaction->value, 
													  $rawTransaction->confirmations, 
													  $rawTransaction->timeStamp,
													  $rawTransaction->hash);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_eth_address_transactions($address) {
		
		$request = 'https://eth.blockscout.com/api?module=account&action=txlist&address=' . $address . '&startblock=0&endblock=99999999&sort=desc';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->result;

		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}

		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			
			if (strtolower($rawTransaction->to) === strtolower($address)) {
				
				$transactions[] = new NMM_Transaction($rawTransaction->value, 
													  $rawTransaction->confirmations, 
													  $rawTransaction->timeStamp,
													  $rawTransaction->hash);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_grs_address_transactions($address) {
		
		$request = 'https://groestlsight.groestlcoin.org/api/txs?address=' . $address;
		
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->txs;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			foreach ($rawTransaction->vout as $vout) {
				if ($vout->scriptPubKey->addresses[0] === $address) {
					$transactions[] = new NMM_Transaction($vout->value * 100000000, 
														  $rawTransaction->confirmations, 
														  $rawTransaction->time,
														  $rawTransaction->txid);		
				}
			}
			
		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_lsk_address_transactions($address) {
		
		$request = 'https://node08.lisk.io/api/transactions?recipientId=' . $address . '&limit=10&offset=0&sort=amount%3Aasc';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->data;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {				
			$transactions[] = new NMM_Transaction($rawTransaction->amount, 
												  $rawTransaction->confirmations, 
												  time(),
												  $rawTransaction->id);
		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_ltc_address_transactions($address) {
		$userAgentString = self::get_user_agent_string();

        $request = 'https://api.blockcypher.com/v1/ltc/main/addrs/' . $address . self::blockcypher_token_query(false);

        $args = array(
			'user-agent' => $userAgentString
		);

		$response = self::api_get($request, $args);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
            $result = array(
                'result' => 'error',
                'total_received' => '',
            );

            return $result;
        }

		$body = json_decode($response['body']);

        $rawTransactions = $body->txrefs;
        if (!is_array($rawTransactions)) {
            $result = array(
                'result' => 'error',
                'message' => 'No transactions found',
            );

            return $result;
        }
        $transactions = array();
        foreach ($rawTransactions as $rawTransaction) {
            if ($rawTransaction->tx_input_n == -1) {
                $transactions[] = new NMM_Transaction(
                    $rawTransaction->value,
                    $rawTransaction->confirmations,
                    $rawTransaction->confirmed,
                    $rawTransaction->tx_hash);
            }
        }
        $result = array (
            'result' => 'success',
            'transactions' => $transactions,
        );

        return $result;
	}

	public static function get_onion_address_transactions($address) {
		
		//$request = 'https://explorer.deeponion.org/ext/getaddress/' . $address;
		$request = 'http://onionexplorer.youngwebsolutions.com:3001/ext/getaddress/' . $address;
		
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (property_exists($body, 'error')) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . $body->error);
			$result = array(
				'result' => 'error',
				'total_received' => '',
			);
			return $result;
		}

		$rawTransactionIds = $body->last_txs;
		if (is_array($rawTransactionIds)) {
			// each entry costs one HTTP request; only inspect the most recent ones
			$rawTransactionIds = array_slice($rawTransactionIds, -25);
		}
		if (!is_array($rawTransactionIds)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		
		foreach ($rawTransactionIds as $rawTransactionId) {			
			if ($rawTransactionId->type === 'vout' || $rawTransactionId->type === 'vin') {

				$txId = $rawTransactionId->addresses;

				$request2 = 'https://explorer.deeponion.org/api/getrawtransaction?txid=' . $txId . '&decrypt=1';
				
				$response2 = self::api_get($request2);

				if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
					continue;
				}

				$rawTransaction = json_decode($response2['body']);

				$vouts = $rawTransaction->vout;

				foreach ($vouts as $vout) {
					// newer nodes emit scriptPubKey.address (singular); older ones an addresses array
					$voutAddresses = array();
					if (isset($vout->scriptPubKey->addresses) && is_array($vout->scriptPubKey->addresses)) {
						$voutAddresses = $vout->scriptPubKey->addresses;
					}
					elseif (isset($vout->scriptPubKey->address)) {
						$voutAddresses = array($vout->scriptPubKey->address);
					}

					if (in_array($address, $voutAddresses, true)) {
						$transactions[] = new NMM_Transaction($vout->value * 100000000,
															  isset($rawTransaction->confirmations) ? $rawTransaction->confirmations : 0,
															  isset($rawTransaction->time) ? $rawTransaction->time : time(),
															  $rawTransaction->txid);
					}
				}


				
			}			
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}	

	public static function get_trx_address_transactions($address) {
		
		$request = 'https://apilist.tronscan.org/api/transaction?address=' . $address;

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->data;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		
		foreach ($rawTransactions as $rawTransaction) {
			
			if ($rawTransaction->toAddress === $address && $rawTransaction->confirmed) {
				$transactions[] = new NMM_Transaction($rawTransaction->contractData->amount,
													  10000,
													  $rawTransaction->timestamp/1000,
													  $rawTransaction->hash);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_waves_address_transactions($address) {
		
		$request = 'https://nodes.wavesnodes.com/transactions/address/' . $address . '/limit/100';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body[0];
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			if ($rawTransaction->type == '4') {
				$transactions[] = new NMM_Transaction($rawTransaction->amount, 
													  10000, 
													  $rawTransaction->timestamp,
													  $rawTransaction->id);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_xem_address_transactions($address) {
		
		$request = 'http://108.61.168.86:7890/account/transfers/incoming?address=' . $address;

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->data;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {				
			$transactions[] = new NMM_Transaction($rawTransaction->transaction->amount, 
												  10000, 
												  time(),
												  $rawTransaction->meta->hash->data);
		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_xlm_address_transactions($address) {
		$request = 'https://horizon.stellar.org/accounts/' . $address . '/payments?order=desc';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->_embedded->records;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		
		foreach ($rawTransactions as $rawTransaction) {
			
			if ($rawTransaction->type === 'create_account') {
				if ($rawTransaction->account === $address) {
					$transactions[] = new NMM_Transaction($rawTransaction->starting_balance * 10000000,
												  10000,
												  strtotime($rawTransaction->created_at),
												  $rawTransaction->transaction_hash);
				}
			}
			if ($rawTransaction->type === 'payment') {
				if ($rawTransaction->to === $address) {
					$transactions[] = new NMM_Transaction($rawTransaction->amount * 10000000,
												  10000, 
												  strtotime($rawTransaction->created_at),
												  $rawTransaction->transaction_hash);
				}
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_xmy_address_transactions($address) {
		
		$request = 'https://blockbook.myralicious.com/api/address/' . $address;
		
		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$transactionIds = $body->transactions;
		if (!is_array($transactionIds)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();

		foreach ($transactionIds as $transactionId) {

				$request2 = 'https://blockbook.myralicious.com/api/tx/' . $transactionId;
				
				$response2 = self::api_get($request2);

				if (is_wp_error($response2) || $response2['response']['code'] !== 200) {
					continue;
				}

				$rawTransaction = json_decode($response2['body']);

				$vouts = $rawTransaction->vout;

			foreach ($rawTransaction->vout as $vout) {
				if ($vout->scriptPubKey->addresses[0] === $address) {
					$transactions[] = new NMM_Transaction($vout->value * 100000000, 
														  $rawTransaction->confirmations, 
														  $rawTransaction->time,
														  $rawTransaction->txid);		
				}
			}		
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_xrp_address_transactions($address) {
		
		$request = 'https://api.xrpscan.com/api/v1/account/' . $address . '/transactions';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = isset($body->transactions) ? $body->transactions : null;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();
		foreach ($rawTransactions as $rawTransaction) {
			if (!isset($rawTransaction->TransactionType) || $rawTransaction->TransactionType !== 'Payment') {
				continue;
			}

			// Amount is an object for XRP payments; value is in drops
			if (!isset($rawTransaction->Destination, $rawTransaction->Amount->currency, $rawTransaction->Amount->value)
				|| $rawTransaction->Amount->currency !== 'XRP') {
				continue;
			}

			if ($rawTransaction->Destination === $address) {

				$transactions[] = new NMM_Transaction($rawTransaction->Amount->value,
												  10000,
												  strtotime($rawTransaction->date),
												  $rawTransaction->hash);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_xtz_address_transactions($address) {

		// TzKT: applied incoming transactions; amount is in mutez (1e-6 XTZ)
		$request = 'https://api.tzkt.io/v1/operations/transactions?target=' . rawurlencode($address) . '&status=applied&limit=50&sort.desc=id';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$rawTransactions = json_decode($response['body']);

		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();

		foreach ($rawTransactions as $rawTransaction) {
			if (!isset($rawTransaction->amount, $rawTransaction->hash) || $rawTransaction->amount <= 0) {
				continue;
			}

			$transactions[] = new NMM_Transaction($rawTransaction->amount,
											  10000,
											  strtotime($rawTransaction->timestamp),
											  $rawTransaction->hash);
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_zec_address_transactions($address) {

		// Blockchair outputs filtered by recipient; values are in zatoshi (1e-8 ZEC)
		$request = 'https://api.blockchair.com/zcash/outputs?q=recipient(' . rawurlencode($address) . ')&limit=50&s=block_id(desc)';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		if (!isset($body->data) || !is_array($body->data)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}

		// ZEC requires real confirmation counts; blockchair context carries the tip
		$tipHeight = isset($body->context->state) ? (int) $body->context->state : 0;

		$transactions = array();
		foreach ($body->data as $output) {
			$confirmations = 0;
			if ($tipHeight > 0 && isset($output->block_id) && $output->block_id > 0) {
				$confirmations = $tipHeight - (int) $output->block_id + 1;
			}

			$transactions[] = new NMM_Transaction($output->value,
												  $confirmations,
												  strtotime($output->time),
												  $output->transaction_hash);
		}

		return array(
			'result' => 'success',
			'transactions' => $transactions,
		);
	}

	public static function get_erc20_address_transactions($cryptoId, $address) {
		$cryptos = NMM_Cryptocurrencies::get();
		$contract = isset($cryptos[$cryptoId]) ? (string) $cryptos[$cryptoId]->get_erc20_contract() : '';

		if ($contract === '') {
			return array(
				'result' => 'error',
				'message' => 'Unknown token',
			);
		}

		$host = isset(self::$blockscoutHosts[$cryptoId]) ? self::$blockscoutHosts[$cryptoId] : 'eth.blockscout.com';

		$request = 'https://' . $host . '/api?module=account&action=tokentx&address=' . $address . '&contractaddress=' . $contract . '&page=1&offset=100&sort=desc';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			$result = array(
				'result' => 'error',
				'total_received' => '',
			);

			return $result;
		}

		$body = json_decode($response['body']);

		$rawTransactions = $body->result;
		if (!is_array($rawTransactions)) {
			$result = array(
				'result' => 'error',
				'message' => 'No transactions found',
			);

			return $result;
		}
		$transactions = array();

		foreach($rawTransactions as $rawTransaction) {
			
			
			if (strtolower($rawTransaction->to) === strtolower($address)
				&& isset($rawTransaction->contractAddress)
				&& strtolower($rawTransaction->contractAddress) === strtolower($contract)) {

				$transactions[] = new NMM_Transaction($rawTransaction->value,
												  $rawTransaction->confirmations,
												  $rawTransaction->timeStamp,
												  $rawTransaction->hash);
			}
		}

		$result = array (
			'result' => 'success',
			'transactions' => $transactions,
		);

		return $result;
	}

	public static function get_trc20_usdt_address_transactions($address) {

		// official USDT contract on Tron; tronscan is keyless (already used for TRX)
		$contract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

		$request = 'https://apilist.tronscan.org/api/token_trc20/transfers?relatedAddress=' . rawurlencode($address) . '&contract_address=' . $contract . '&limit=50&start=0';

		$response = self::api_get($request);

		if (is_wp_error($response) || $response['response']['code'] !== 200) {
			NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( ' . $request . ' ): ' . print_r($response, true));

			return array(
				'result' => 'error',
				'total_received' => '',
			);
		}

		$body = json_decode($response['body']);

		if (!isset($body->token_transfers) || !is_array($body->token_transfers)) {
			return array(
				'result' => 'error',
				'message' => 'No transactions found',
			);
		}

		$transactions = array();

		foreach ($body->token_transfers as $transfer) {
			// relatedAddress returns both directions; keep confirmed incoming only
			if (!isset($transfer->to_address, $transfer->quant) || $transfer->to_address !== $address) {
				continue;
			}

			if (empty($transfer->confirmed) || (isset($transfer->finalResult) && $transfer->finalResult !== 'SUCCESS')) {
				continue;
			}

			// Ignore zero/dust transfers. A 0-value TRC-20 transfer is valid
			// on-chain and anyone can push one; it must never become a
			// zero-amount transaction the matcher then has to reason about.
			if (!is_numeric($transfer->quant) || (float) $transfer->quant <= 0) {
				continue;
			}

			// quant is already in 1e-6 USDT units; Tron finality is fast, so
			// confirmed transfers get the no-confirmation-tracking sentinel
			$transactions[] = new NMM_Transaction($transfer->quant,
												  10000,
												  (int) ($transfer->block_ts / 1000),
												  $transfer->transaction_id);
		}

		return array(
			'result' => 'success',
			'transactions' => $transactions,
		);
	}

	public static function get_sol_address_transactions($address, $transactionLifetime = null) {

		// public mainnet RPC, keyless; only finalized transactions are listed
		$rpc = 'https://api.mainnet-beta.solana.com';

		// getSignaturesForAddress returns newest-first. Carousel SOL addresses
		// are reused, so a valid payment can be buried under later activity - or
		// a cheap dusting burst - which is also an attack surface: fetching a
		// getTransaction for every signature would let an attacker force ~1000
		// sequential RPC calls per tick, hit rate limits, and hold the cron lock.
		//
		// We sweep the in-window history newest-to-oldest across ticks. Each tick
		// resumes from a persisted cursor and inspects a bounded batch, advancing
		// the cursor monotonically; when the sweep reaches the window cutoff (or
		// the end of history) the cursor is cleared so the next sweep restarts
		// from the newest signature. The cursor is a single value persisted
		// independently of any bounded cache, so - unlike a fixed-size
		// dedup cache, which oscillates once more signatures than it holds must
		// be skipped - every in-window signature is guaranteed to be reached
		// within one sweep, however deeply a payment is buried, while per-tick
		// work stays hard bounded.
		$cutoffTime = ($transactionLifetime !== null) ? time() - (int) $transactionLifetime : null;
		$pageSize = 100;        // signatures per getSignaturesForAddress call
		$maxPages = 5;          // safety bound on getSignatures pages per tick
		$inspectBudget = 25;    // <=25 getTransaction calls per address per tick
		$retryCap = 50;                        // bounded queue of failed-detail signatures
		$retryBudget = intdiv($inspectBudget, 2); // at most half the budget on retries, so the sweep always advances
		// Keep retrying a failed signature for the whole time it could still be a
		// live payment (the matching window) plus a margin, rather than dropping
		// it after a fixed number of attempts - an ordinary RPC outage or host
		// backoff can burn many consecutive ticks. Poll with exponential backoff
		// so a persistent failure is checked sparsely, not every tick.
		$retryRetentionSec = ($transactionLifetime !== null ? (int) $transactionLifetime : 3 * 60 * 60) + 1800;
		$retryBaseSec = 60;       // first backoff step (~one cron tick)
		$retryMaxSec = 30 * 60;   // cap on the interval between retries

		$cursorKey = 'nmm_sol_cursor_' . md5($address);
		$retryKey  = 'nmm_sol_retry_' . md5($address);

		// Resume the sweep from the persisted cursor; false => start a fresh
		// sweep from the newest signature.
		$before = get_transient($cursorKey);
		if ($before === false) {
			$before = null;
		}

		// Retry queue is a map: signature => { first, attempts, next }. Preserved
		// insertion order gives us fair rotation (see below).
		$retryMap = get_transient($retryKey);
		if (!is_array($retryMap)) {
			$retryMap = array();
		}

		$transactions = array();
		$hardError = false;
		$nowTs = time();

		// Phase 0: retry signatures whose detail lookup failed earlier, but spend
		// at most $retryBudget of the tick here so a batch of persistently-failing
		// signatures can never starve the sweep (which would freeze the cursor and
		// stop new payments from ever being inspected). Expire entries only once
		// they are past the matching window (prominent log), and among the rest
		// attempt those whose backoff has elapsed, oldest-first, re-queuing failures
		// at the back with a longer backoff so every entry is rotated fairly.
		$due = array();
		foreach ($retryMap as $sig => $meta) {
			$meta = self::sol_normalize_retry($meta, $nowTs);
			$retryMap[$sig] = $meta;

			if ($nowTs - $meta['first'] > $retryRetentionSec) {
				NMM_Util::log(__FILE__, __LINE__, 'Giving up on Solana signature ' . $sig . ' after ' . $meta['attempts'] . ' failed detail lookups over ' . ($nowTs - $meta['first']) . 's (now past the payment matching window).');
				unset($retryMap[$sig]);
				continue;
			}
			if ($meta['next'] <= $nowTs) {
				$due[] = $sig;
			}
		}

		$retriesUsed = 0;
		foreach (array_slice($due, 0, $retryBudget) as $sig) {
			$retriesUsed++;
			$meta = $retryMap[$sig];
			unset($retryMap[$sig]); // re-add at the back only if still failing

			list($ok, $tx) = self::sol_inspect_signature($rpc, $sig, $address);
			if ($ok) {
				if ($tx !== null) {
					$transactions[] = $tx;
				}
			}
			else {
				$meta = self::sol_bump_retry($meta, $nowTs, $retryBaseSec, $retryMaxSec);
				$retryMap[$sig] = $meta;
				if ($meta['attempts'] % 10 === 0) {
					NMM_Util::log(__FILE__, __LINE__, 'Solana signature ' . $sig . ' still failing detail lookup after ' . $meta['attempts'] . ' attempts (' . ($nowTs - $meta['first']) . 's).');
				}
			}
		}

		// Phase 1: from the cursor, page older and collect the remaining budget of
		// signatures to inspect this tick.
		$sweepBudget = max(0, $inspectBudget - $retriesUsed);
		$candidates = array();
		$sweepComplete = false;
		$hitBudget = false;

		for ($page = 0; $page < $maxPages && count($candidates) < $sweepBudget; $page++) {
			$sigParams = array('limit' => $pageSize, 'commitment' => 'finalized');
			if ($before !== null) {
				$sigParams['before'] = $before;
			}

			$response = self::api_post($rpc, array(
				'headers' => array('Content-Type' => 'application/json'),
				'body' => json_encode(array(
					'jsonrpc' => '2.0',
					'id' => 1,
					'method' => 'getSignaturesForAddress',
					'params' => array($address, $sigParams),
				)),
			));

			if (is_wp_error($response) || $response['response']['code'] !== 200) {
				NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( solana getSignaturesForAddress ): ' . print_r($response, true));

				// Signal a hard error only if we end up with nothing at all; a
				// later-page failure still lets us inspect what we collected, and
				// Phase 0 may already have recovered a payment.
				if ($page === 0) {
					$hardError = true;
				}
				break;
			}

			$body = json_decode($response['body']);

			if (!isset($body->result) || !is_array($body->result)) {
				if ($page === 0) {
					$hardError = true;
				}
				break;
			}

			if (count($body->result) === 0) {
				$sweepComplete = true; // no more history at this cursor
				break;
			}

			foreach ($body->result as $entry) {
				if (!isset($entry->signature)) {
					continue;
				}

				// Advance the cursor past every signature we see, failed or not,
				// so the next page continues strictly older than this one.
				$before = $entry->signature;

				// Once signatures are older than the payment window, nothing
				// further back can be a live payment - the sweep is complete.
				if ($cutoffTime !== null && isset($entry->blockTime) && $entry->blockTime !== null && $entry->blockTime < $cutoffTime) {
					$sweepComplete = true;
					break;
				}

				if ($entry->err !== null) {
					continue;
				}

				$candidates[] = $entry;
				if (count($candidates) >= $sweepBudget) {
					$hitBudget = true;
					break;
				}
			}

			if ($sweepComplete) {
				break;
			}
			if ($hitBudget) {
				// Stopped on the per-tick budget, not on end-of-history. The
				// current page may still hold unprocessed signatures, so resume
				// from the advanced cursor next tick - do NOT treat this as the
				// end of the sweep.
				break;
			}
			if (count($body->result) < $pageSize) {
				$sweepComplete = true; // consumed a short page: end of history
				break;
			}
		}

		// Phase 2: inspect this tick's sweep candidates. A candidate whose detail
		// lookup fails is added to the retry map (Phase 0 next tick), so the
		// already-advanced cursor never drops it.
		foreach ($candidates as $entry) {
			list($ok, $tx) = self::sol_inspect_signature($rpc, $entry->signature, $address);
			if (!$ok) {
				$sig = $entry->signature;
				$meta = isset($retryMap[$sig]) ? self::sol_normalize_retry($retryMap[$sig], $nowTs) : array('first' => $nowTs, 'attempts' => 0, 'next' => 0);
				$retryMap[$sig] = self::sol_bump_retry($meta, $nowTs, $retryBaseSec, $retryMaxSec);
			}
			elseif ($tx !== null) {
				$transactions[] = $tx;
			}
		}

		// Persist the sweep cursor: clear it when the sweep finished so the next
		// tick restarts from the newest signature (catching new arrivals);
		// otherwise remember where to resume so progress stays monotonic.
		if (!$sweepComplete && $before !== null) {
			set_transient($cursorKey, $before, 6 * HOUR_IN_SECONDS);
		}
		else {
			delete_transient($cursorKey);
		}

		// Persist the bounded retry map. On overflow keep the NEWEST failures
		// (largest first_failed_at) - they are the most likely to still be inside
		// the matching window; the oldest are closest to expiry anyway.
		if (count($retryMap) > $retryCap) {
			uasort($retryMap, function ($a, $b) { return $b['first'] - $a['first']; });
			$retryMap = array_slice($retryMap, 0, $retryCap, true);
		}
		if (!empty($retryMap)) {
			set_transient($retryKey, $retryMap, 6 * HOUR_IN_SECONDS);
		}
		else {
			delete_transient($retryKey);
		}

		// Only report a hard error when we truly have nothing; otherwise return
		// what we found (a retry recovery or a partial sweep) as success so the
		// caller does not throw the recovered transactions away.
		if ($hardError && empty($transactions)) {
			return array(
				'result' => 'error',
				'total_received' => '',
			);
		}

		return array(
			'result' => 'success',
			'transactions' => $transactions,
		);
	}

	// Fetch and interpret a single Solana transaction. Returns
	// array($inspected, $transactionOrNull): $inspected is false when the detail
	// lookup failed or came back unusable in a way that could be transient (the
	// caller should retry it), and true when we got a usable finalized result;
	// the second element is an NMM_Transaction when the tx credited $address,
	// otherwise null.
	// Coerce a retry-map entry into { first, attempts, next }, tolerating the
	// legacy plain-integer (attempt count) format from an in-flight upgrade.
	private static function sol_normalize_retry($meta, $now) {
		if (is_array($meta)) {
			return array(
				'first' => isset($meta['first']) ? (int) $meta['first'] : $now,
				'attempts' => isset($meta['attempts']) ? (int) $meta['attempts'] : 1,
				'next' => isset($meta['next']) ? (int) $meta['next'] : 0,
			);
		}
		return array('first' => $now, 'attempts' => (int) $meta, 'next' => 0);
	}

	// Record another failed attempt and schedule the next retry: the first
	// failure is retried on the next tick, later failures back off exponentially
	// up to $maxSec so a persistently-failing signature is polled sparsely.
	private static function sol_bump_retry($meta, $now, $baseSec, $maxSec) {
		$meta['attempts']++;
		if ($meta['attempts'] <= 1) {
			$meta['next'] = $now;
		}
		else {
			$step = $baseSec * (1 << min($meta['attempts'] - 2, 20));
			$meta['next'] = $now + min($step, $maxSec);
		}
		return $meta;
	}

	private static function sol_inspect_signature($rpc, $signature, $address) {
		$txResponse = self::api_post($rpc, array(
			'headers' => array('Content-Type' => 'application/json'),
			'body' => json_encode(array(
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'getTransaction',
				'params' => array($signature, array(
					'encoding' => 'jsonParsed',
					'maxSupportedTransactionVersion' => 0,
					'commitment' => 'finalized',
				)),
			)),
		));

		if (is_wp_error($txResponse) || $txResponse['response']['code'] !== 200) {
			return array(false, null); // HTTP failure (incl. rate limit); retry
		}

		$tx = json_decode($txResponse['body']);

		if (!is_object($tx) || isset($tx->error)) {
			return array(false, null); // truncated body or RPC error object; retry
		}

		if (!isset($tx->result) || $tx->result === null) {
			return array(false, null); // not yet available at this node; retry
		}

		if (!isset($tx->result->transaction->message->accountKeys) || !isset($tx->result->meta->preBalances)) {
			// Finalized but we cannot read balances (e.g. an unsupported tx
			// version). This will not improve on retry, so treat it as inspected
			// with no credit rather than looping on it forever.
			return array(true, null);
		}

		// lamports received = balance delta of our address in this transaction
		foreach ($tx->result->transaction->message->accountKeys as $index => $accountKey) {
			if (isset($accountKey->pubkey) && $accountKey->pubkey === $address) {
				$delta = $tx->result->meta->postBalances[$index] - $tx->result->meta->preBalances[$index];

				if ($delta > 0) {
					return array(true, new NMM_Transaction($delta,
														  10000,
														  isset($tx->result->blockTime) ? $tx->result->blockTime : time(),
														  $signature));
				}

				return array(true, null); // inspected, no credit to us
			}
		}

		return array(true, null); // inspected, our address is not a party
	}

	private static function get_user_agent_string() {
		return 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.1 Safari/534.12';
	}
}

?>