<?php
/**
 * Live smoke test: probes the configured explorer and exchange-rate fixtures
 * using public addresses. This is not exhaustive coverage of every provider. Fails (exit 1) if any coin that should
 * have working verification returns an error or an empty transaction list.
 *
 * Hits live third-party APIs - run on a schedule / manually, not per-push.
 *
 * Usage: php tests/smoke-explorers.php [CoinId ...]   (default: all)
 */

require __DIR__ . '/wp-stubs.php';

nmmpro_test_require_plugin(array(
	'src/vendor/bcmath_Utils.php',
	'src/vendor/CashAddress.php',
	'src/NMMPRO_Util.php',
	'src/NMMPRO_Settings.php',
	'src/NMMPRO_Transaction.php',
	'src/NMMPRO_Cryptocurrency.php',
	'src/NMMPRO_Cryptocurrencies.php',
	'src/NMMPRO_Sol_Retry_Repo.php',
	'src/NMMPRO_Blockchain.php',
	'src/NMMPRO_Exchange.php',
	'src/NMMPRO_Hd_Evidence.php',
	'src/NMMPRO_Compat.php',
	'src/NMMPRO_Evm_Transfers.php',
));

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data) { return json_encode($data); }
}

// SOL verification touches the durable retry store; offline it no-ops (no $wpdb).
if (!defined('NMMPRO_SOL_RETRY_TABLE')) { define('NMMPRO_SOL_RETRY_TABLE', 'nmmpro_sol_retry'); }

$failures = array();

function check($label, $result, $expectTxs = true) {
	global $failures;

	$ok = ($result['result'] === 'success');
	$detail = '';

	if (isset($result['transactions'])) {
		$n = count($result['transactions']);
		$ok = $ok && (!$expectTxs || $n > 0);
		$first = $n ? $result['transactions'][0] : null;
		$detail = "txs=$n" . ($first ? ' first=' . $first->get_amount() . '/' . substr($first->get_hash(), 0, 12) : '');
	} elseif (array_key_exists('total_received', $result)) {
		$ok = $ok && is_numeric($result['total_received']);
		$detail = 'received=' . var_export($result['total_received'], true);
	}

	printf("%-22s %-5s %s\n", $label, $ok ? 'ok' : 'FAIL', $detail);
	if (!$ok) {
		$failures[] = $label;
	}
}

function check_price($label, $fn) {
	global $failures;
	$price = $fn();
	// price APIs (Binance especially) intermittently reject bursts; retry once
	if (!(is_numeric($price) && $price > 0)) {
		sleep(3);
		$price = $fn();
	}
	$ok = is_numeric($price) && $price > 0;
	printf("%-22s %-5s %s\n", $label, $ok ? 'ok' : 'FAIL', $price);
	if (!$ok) {
		$failures[] = $label;
	}
}

// harvest a currently-active ADA address (fixed ones go quiet over time)
function harvest_ada_address() {
	for ($try = 0; $try < 3; $try++) {
		$blocks = json_decode(wp_remote_get('https://api.koios.rest/api/v1/blocks?limit=1')['body']);
		$txs = isset($blocks[0]->hash) ? json_decode(wp_remote_post('https://api.koios.rest/api/v1/block_txs', array(
			'headers' => array('Content-Type' => 'application/json'),
			'body' => json_encode(array('_block_hashes' => array($blocks[0]->hash))),
		))['body']) : null;
		$utxos = isset($txs[0]->tx_hash) ? json_decode(wp_remote_post('https://api.koios.rest/api/v1/tx_utxos', array(
			'headers' => array('Content-Type' => 'application/json'),
			'body' => json_encode(array('_tx_hashes' => array($txs[0]->tx_hash))),
		))['body']) : null;

		if (isset($utxos[0]->outputs[0]->payment_addr->bech32)) {
			return $utxos[0]->outputs[0]->payment_addr->bech32;
		}

		sleep(20); // koios public tier throttles per-minute
	}

	return '';
}

function harvest_zec_address() {
	$out = json_decode(wp_remote_get('https://api.blockchair.com/zcash/outputs?limit=1')['body']);
	return $out->data[0]->recipient;
}

$only = array_slice($argv, 1);
$unmatched = array_fill_keys($only, true);
$run = function($coin) use ($only, &$unmatched) { unset($unmatched[$coin]); return count($only) === 0 || in_array($coin, $only, true); };

// --- payment verification (autopay tx listings) ---
if ($run('BTC')) check('BTC mempool.space', NMMPRO_Blockchain::get_btc_address_transactions('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'));
if ($run('ETH')) check('ETH blockscout', NMMPRO_Blockchain::get_eth_address_transactions('0xde0b295669a9fd93d5f28d9ec85e40f4cb697bae'));
if ($run('DOGE')) check('DOGE blockcypher', NMMPRO_Blockchain::get_doge_address_transactions('DH5yaieqoZN36fDVciNyRueRGvGLR3mr7L'));
// High-traffic exchange wallet: keeps incoming XRP Payments on page 1 (the
// endpoint returns only the newest 25 txns), so the probe stays meaningful.
if ($run('XRP')) check('XRP xrpscan', NMMPRO_Blockchain::get_xrp_address_transactions('rEb8TK3gBgk5auZkwc6sHnwrGVJH8DuaLh'));
if ($run('BCH')) check('BCH haskoin', NMMPRO_Blockchain::get_bch_address_transactions('qp3wjpa3tjlj042z2wv7hahsldgwhwy0rq9sywjpyy'));
if ($run('DASH')) check('DASH insight', NMMPRO_Blockchain::get_dash_address_transactions('XdAUmwtig27HBG6WfYyHAzP8n6XC9jESEw'));
if ($run('EOS')) check('EOS hyperion', NMMPRO_Blockchain::get_eos_address_transactions('binancecleos'));
if ($run('ADA')) { $adaAddr = harvest_ada_address(); if ($adaAddr === '') { printf("%-22s %-5s %s\n", 'ADA koios', 'FAIL', 'no address harvested'); $failures[] = 'ADA'; } else { check('ADA koios', NMMPRO_Blockchain::get_ada_address_transactions($adaAddr)); } }
if ($run('BSV')) { sleep(2); check('BSV whatsonchain', NMMPRO_Blockchain::get_bsv_address_transactions('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa')); }
if ($run('DGB')) check('DGB digiexplorer', NMMPRO_Blockchain::get_dgb_address_transactions('DQ6pae47DenMJqoPxgNSRJaefRxQU4ZJUb'));
if ($run('XTZ')) check('XTZ tzkt', NMMPRO_Blockchain::get_xtz_address_transactions('tz3RDC3Jdn4j15J7bBHZd29EUee9gVB1CxD9'));
if ($run('ZEC')) { sleep(2); check('ZEC blockchair', NMMPRO_Blockchain::get_zec_address_transactions(harvest_zec_address())); }
if ($run('BLK')) check('BLK iquidus', NMMPRO_Blockchain::get_blk_address_transactions('tblk1pxfzy6gvcajtuqrn4ax9mjpv9kywalwe40nd84xyy7tc2sugjx8ms85w7t8'));
if ($run('USDT')) check('USDT erc20 blockscout', NMMPRO_Blockchain::get_erc20_address_transactions('USDT', '0x28C6c06298d514Db089934071355E5743bf21d60'));
if ($run('TRX')) check('TRX tronscan', NMMPRO_Blockchain::get_trx_address_transactions('TV6MuMXfmLbBqPZvBHdwFsDnQeVfnmiuSi'));
if ($run('USDTTRX')) check('USDTTRX tronscan', NMMPRO_Blockchain::get_trc20_usdt_address_transactions('TV6MuMXfmLbBqPZvBHdwFsDnQeVfnmiuSi'));
// find an address that RECEIVED lamports in a busy wallet's recent activity
// (a hot wallet itself mostly sends, so its own deltas are usually negative)
function harvest_sol_recipient() {
	$rpc = function($method, $params) {
		$r = wp_remote_post('https://api.mainnet-beta.solana.com', array(
			'headers' => array('Content-Type' => 'application/json'),
			'body' => json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params)),
		));
		return (!is_wp_error($r) && $r['response']['code'] === 200) ? json_decode($r['body']) : null;
	};

	$sigs = $rpc('getSignaturesForAddress', array('9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM', array('limit' => 8, 'commitment' => 'finalized')));

	foreach (($sigs->result ?? array()) as $entry) {
		if ($entry->err !== null) continue;
		sleep(2);
		$tx = $rpc('getTransaction', array($entry->signature, array('encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0)));
		if (!isset($tx->result->meta->postBalances)) continue;
		foreach ($tx->result->transaction->message->accountKeys as $i => $key) {
			$delta = $tx->result->meta->postBalances[$i] - $tx->result->meta->preBalances[$i];
			if ($delta > 1000000) { // received > 0.001 SOL
				return $key->pubkey;
			}
		}
	}

	return '';
}

if ($run('SOL')) {
	sleep(5);
	$solAddr = harvest_sol_recipient();
	if ($solAddr === '') { printf("%-22s %-5s %s\n", 'SOL mainnet rpc', 'FAIL', 'no recipient harvested'); $failures[] = 'SOL'; }
	else { sleep(5); check('SOL mainnet rpc', NMMPRO_Blockchain::get_sol_address_transactions($solAddr)); }
}

// multi-network tokens: harvest a recent recipient from each chain's blockscout
function harvest_erc20_recipient($host, $contract) {
	for ($try = 0; $try < 3; $try++) {
		$r = wp_remote_get("https://$host/api?module=account&action=tokentx&contractaddress=$contract&page=1&offset=5&sort=desc");
		if (!is_wp_error($r) && $r['response']['code'] === 200) {
			$d = json_decode($r['body']);
			if (isset($d->result) && is_array($d->result)) {
				foreach ($d->result as $tx) {
					if (!empty($tx->to)) return $tx->to;
				}
			}
		}
		sleep(5);
	}
	return '';
}
$multinet = array(
	'DAI'     => array('eth.blockscout.com', '0x6B175474E89094C44Da98b954EedeAC495271d0F'),
	'PYUSD'   => array('eth.blockscout.com', '0x6c3ea9036406852006290770BEdFcAbA0e23A0e8'),
	'USDTPOL' => array('polygon.blockscout.com', '0xc2132D05D31c914a87C6611C10748AEb04B58e8F'),
	'USDCBAS' => array('base.blockscout.com', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'),
	'USDTARB' => array('arbitrum.blockscout.com', '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9'),
);
foreach ($multinet as $mnId => $mnInfo) {
	if (!$run($mnId)) continue;
	sleep(5); // polygon especially is burst-sensitive
	$mnAddr = harvest_erc20_recipient($mnInfo[0], $mnInfo[1]);
	if ($mnAddr === '') { printf("%-22s %-5s %s\n", $mnId, 'FAIL', 'no recipient harvested'); $failures[] = $mnId; continue; }
	sleep(5);
	check($mnId . ' ' . $mnInfo[0], NMMPRO_Blockchain::get_erc20_address_transactions($mnId, $mnAddr));
}

// --- ERC-20 fallback sources (issue #17) ---
// Used only when Blockscout cannot answer, so they are probed directly here:
// every JSON-RPC node of every chain must list a recent recipient's
// transfers over the matcher's lookback. The recipient is harvested from a node, not from
// Blockscout, so this section does not depend on the primary being up.
if ($run('FALLBACK')) {
	$fallbackTokens = array(
		1     => array('USDT',    '0xdAC17F958D2ee523a2206206994597C13D831ec7', 5),
		137   => array('USDTPOL', '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 20),
		42161 => array('USDTARB', '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 40),
		8453  => array('USDCBAS', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 5),
	);
	$fetchFrom = new ReflectionMethod('NMMPRO_Evm_Transfers', 'fetch_from');
	$fetchFrom->setAccessible(true);

	foreach ($fallbackTokens as $chainId => $token) {
		$nodes = NMMPRO_Evm_Transfers::nodes($chainId);

		// Up to three distinct recent recipients, newest first. Public nodes
		// rate-limit bursts, so two rounds with a pause between them.
		$recipients = array();
		for ($round = 0; $round < 2 && !$recipients; $round++) {
			if ($round > 0) { sleep(20); $GLOBALS['nmmpro_test_transients'] = array(); }
			foreach ($nodes as $node) {
				$tip = NMMPRO_Blockchain::evm_rpc($node[0], 'eth_blockNumber', array());
				if ($tip['result'] !== 'success') { continue; }
				$tipNumber = hexdec(substr($tip['value'], 2));
				$recent = NMMPRO_Blockchain::evm_rpc($node[0], 'eth_getLogs', array(array(
					'address' => $token[1], 'fromBlock' => '0x' . dechex($tipNumber - $token[2]), 'toBlock' => '0x' . dechex($tipNumber),
					'topics' => array(NMMPRO_Evm_Transfers::TRANSFER_TOPIC),
				)));
				if ($recent['result'] !== 'success' || !is_array($recent['value'])) { continue; }
				foreach (array_reverse($recent['value']) as $log) {
					$candidate = '0x' . substr($log['topics'][2], 26);
					if (!in_array($candidate, $recipients, true)) { $recipients[] = $candidate; }
					if (count($recipients) === 3) { break; }
				}
				if ($recipients) { break; }
			}
		}
		if (!$recipients) { printf("%-22s %-5s %s\n", $token[0] . ' json-rpc', 'FAIL', 'no recipient harvested'); $failures[] = $token[0] . ' json-rpc'; continue; }

		// A node passes when it lists one of these recipients completely. A
		// very busy address can legitimately exceed the request budget (on
		// Arbitrum every transfer needs a header), so a failure counts only
		// when all three fail, each retried once after a pause.
		foreach ($nodes as $node) {
			$host = (string) parse_url($node[0], PHP_URL_HOST);
			$result = array('result' => 'error', 'message' => 'not tried');
			$reasons = array();
			foreach ($recipients as $recipient) {
				for ($attempt = 0; $attempt < 2; $attempt++) {
					sleep($attempt > 0 ? 20 : 1);
					if ($attempt > 0) { $GLOBALS['nmmpro_test_transients'] = array(); }
					try {
						$result = $fetchFrom->invoke(null, $node[0], $node[1], $chainId, strtolower($token[1]), strtolower($recipient), 3 * 3600 + NMMPRO_Evm_Transfers::MARGIN_SEC);
					} catch (\Throwable $e) {
						$result = array('result' => 'error', 'message' => $e->getMessage());
					}
					NMMPRO_Blockchain::take_raw_page_meta();
					if ($result['result'] === 'success') { break 2; }
				}
				$reasons[] = substr($recipient, 0, 10) . ': ' . (isset($result['message']) ? $result['message'] : 'failed');
			}
			if ($result['result'] !== 'success') { echo '   ' . $host . ' - ' . implode('; ', $reasons) . "\n"; }
			check($token[0] . ' ' . $host, $result);
		}
	}
}

// --- HD (privacy mode) evidence adapters ---
// Each reviewed adapter must still answer the clean-address check (these
// public addresses are well used, so 'used') and return well-formed incoming
// outputs from a bounded scan. One page is enough to prove the format; an
// address this busy legitimately reports 'page limit reached'.
function check_hd_evidence($coin, $address) {
	global $failures;
	$activity = NMMPRO_Hd_Evidence::address_activity($coin, $address);
	$tip = NMMPRO_Hd_Evidence::chain_tip($coin);
	$scan = NMMPRO_Hd_Evidence::scan($coin, $address, time() - 365 * 86400, null, 1);
	$scanOk = ($scan['coverage'] === 'complete' || $scan['reason'] === 'page limit reached') && count($scan['outputs']) > 0;
	$ok = $activity['state'] === 'used' && $scanOk && $tip['height'] !== null;
	$first = $scan['outputs'] ? $scan['outputs'][0] : null;
	printf("%-22s %-5s %s\n", $coin . ' hd evidence', $ok ? 'ok' : 'FAIL',
		'activity=' . $activity['state'] . ' tip=' . var_export($tip['height'], true) . ' coverage=' . $scan['coverage'] . ($scan['reason'] !== '' ? ' (' . $scan['reason'] . ')' : '')
		. ' outputs=' . count($scan['outputs']) . ($first ? ' first=' . $first['amount_units'] . '/' . substr($first['tx_hash'], 0, 12) . ':' . $first['output_index'] . ' confs=' . $first['confirmations'] : ''));
	if (!$ok) {
		$failures[] = $coin . ' hd evidence';
	}
}
if ($run('BTC')) check_hd_evidence('BTC', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa');
if ($run('LTC')) check_hd_evidence('LTC', 'LVg2kJoFNg45Nbpy53h7Fe1wKyeXVRhMH9');
if ($run('DOGE')) check_hd_evidence('DOGE', 'DH5yaieqoZN36fDVciNyRueRGvGLR3mr7L');
if ($run('DASH')) check_hd_evidence('DASH', 'XdAUmwtig27HBG6WfYyHAzP8n6XC9jESEw');

// --- exchange rates ---
if ($run('RATES')) {
	check_price('CoinGecko BTC/USD', function() { return NMMPRO_Exchange::get_coingecko_price('BTC', 60); });
	// Binance geo-blocks US IPs (HTTP 451) - GitHub-hosted runners live there.
	// Skip when blocked; still fail on real outages.
	$binanceProbe = wp_remote_get('https://api.binance.com/api/v3/ping');
	if (!is_wp_error($binanceProbe) && (int) $binanceProbe['response']['code'] === 451) {
		printf("%-22s %-5s %s\n", 'Binance BTC/USDT', 'skip', 'geo-blocked from this runner (451)');
	} else {
		check_price('Binance BTC/USDT', function() { return NMMPRO_Exchange::get_binance_price('BTC', 60); });
	}
	check_price('EUR->USD frankfurter', function() { return NMMPRO_Exchange::get_order_total_in_usd(100, 'EUR'); });
	check_price('AUD->USD frankfurter', function() { return NMMPRO_Exchange::get_order_total_in_usd(100, 'AUD'); });
}

foreach (array_keys($unmatched) as $unknown) { $failures[] = 'No smoke fixture for requested selector: ' . $unknown; }

echo "\n";
if (count($failures) > 0) {
	echo 'FAILED: ' . implode(', ', $failures) . "\n";
	exit(1);
}
echo "ALL PASS\n";
exit(0);
