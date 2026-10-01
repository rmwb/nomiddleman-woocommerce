<?php
/**
 * Offline test for the ERC-20 transfer sources and their fallback order
 * (issue #17): Blockscout, then public JSON-RPC nodes read log by log
 * (NMMPRO_Evm_Transfers).
 *
 * A scripted explorer and a scripted chain answer every request; nothing
 * reaches the network. Each section states a rule the JSON-RPC source must
 * keep so that its answer is never weaker than an explorer's:
 *
 *   1. source order, and that a later source is used only on failure
 *   2. what a JSON-RPC answer contains (value, confirmations, time, hash)
 *   3. all or nothing: an unreadable chunk fails the fetch
 *   4. proven coverage: the starting block's own time reaches the lookback
 *   5. a live tip: a lagging node is refused
 *   6. one snapshot: explicit, contiguous block ranges within the span limit
 *   7. strict parsing of logs
 *   8. the page report for the matcher's truncation check
 *   9. bounded work
 *  10. the hexadecimal-to-decimal conversion
 *
 *   Run:  php tests/test-evm-transfers.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/wp-stubs.php';

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data) { return json_encode($data); }
}

nmmpro_test_require_plugin(array(
	'src/NMMPRO_Compat.php',
	'src/NMMPRO_Util.php',
	'src/NMMPRO_Transaction.php',
	'src/NMMPRO_Cryptocurrency.php',
	'src/NMMPRO_Cryptocurrencies.php',
	'src/NMMPRO_Blockchain.php',
	'src/NMMPRO_Evm_Transfers.php',
));

$GLOBALS['et_failed'] = false;
$GLOBALS['et_count'] = 0;
function ok($label, $pass, $extra = '') {
	$GLOBALS['et_count']++;
	printf("%-66s %s%s\n", $label, $pass ? 'ok' : 'FAIL', $extra !== '' ? '  ' . $extra : '');
	if (!$pass) { $GLOBALS['et_failed'] = true; }
}

const ET_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const ET_ADDR  = '0x1111111111111111111111111111111111111111';
const ET_OTHER = '0x2222222222222222222222222222222222222222';
const ET_POL   = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f'; // USDTPOL
const ET_ETH   = '0xdac17f958d2ee523a2206206994597c13d831ec7'; // USDT

function et_pad($address) { return '0x' . str_repeat('0', 24) . substr(strtolower($address), 2); }
function et_hash($n) { return '0x' . str_pad(dechex($n), 64, 'a', STR_PAD_LEFT); }

/**
 * A scripted chain behind one or more JSON-RPC URLs, plus the explorers.
 * $GLOBALS['et'] holds the scenario; the handler records every request.
 */
function et_reset($overrides = array()) {
	$GLOBALS['nmmpro_test_transients'] = array();   // no host backoff carried between scenarios
	NMMPRO_Blockchain::take_raw_page_meta();
	$GLOBALS['et'] = array_merge(array(
		'now'          => time(),
		'tip'          => 1000000,
		'block_sec'    => 2,          // real seconds per block on the fake chain
		'tip_age'      => 3,          // seconds since the tip block
		'contract'     => ET_POL,
		'logs'         => array(),    // array(block, logIndex, to, valueHex, extra fields)
		'log_time'     => false,      // whether logs carry blockTimestamp; 'zero' sends 0x0 (as Arbitrum's node does)
		'header_shift' => array(),    // block => seconds added to that block's header time
		'blockscout'   => 500,        // HTTP status, or an array body for a 200
		'fail_chunk'   => array(),    // url host => index of the eth_getLogs call to fail
		'fail_method'  => array(),    // url host => method that always fails
		'wrong_header' => false,      // header answers carry a different block number
		'calls'        => array(),    // every request, in order
	), $overrides);
}
function et_block_time($block) {
	$s = $GLOBALS['et'];
	return (int) ($s['now'] - $s['tip_age'] - ($s['tip'] - $block) * $s['block_sec']);
}
function et_calls($method, $host = null) {
	$out = array();
	foreach ($GLOBALS['et']['calls'] as $c) {
		if ($c['method'] === $method && ($host === null || $c['host'] === $host)) { $out[] = $c; }
	}
	return $out;
}

$GLOBALS['nmmpro_http_handler'] = function ($url, $method, $postBody, $headers) {
	$s = &$GLOBALS['et'];
	$host = (string) parse_url($url, PHP_URL_HOST);
	$json = function ($body, $code = 200) { return array('body' => json_encode($body), 'response' => array('code' => $code)); };

	if ($method === 'GET') {
		$s['calls'][] = array('method' => 'blockscout', 'host' => $host, 'url' => $url);
		$answer = $s['blockscout'];
		if (is_int($answer)) { return array('body' => 'down', 'response' => array('code' => $answer)); }
		return is_string($answer) ? array('body' => $answer, 'response' => array('code' => 200)) : $json($answer);
	}

	$req = json_decode($postBody, true);
	$m = $req['method'];
	$p = $req['params'];
	$call = array('method' => $m, 'host' => $host, 'params' => $p);
	$s['calls'][] = $call;

	if (isset($s['fail_method'][$host]) && $s['fail_method'][$host] === $m) {
		return array('body' => 'down', 'response' => array('code' => 503));
	}

	if ($m === 'eth_blockNumber') {
		return $json(array('jsonrpc' => '2.0', 'id' => 1, 'result' => '0x' . dechex($s['tip'])));
	}
	if ($m === 'eth_getBlockByNumber') {
		$block = hexdec(substr($p[0], 2));
		$number = $s['wrong_header'] ? $block + 1 : $block;
		$shift = isset($s['header_shift'][$block]) ? $s['header_shift'][$block] : 0;
		return $json(array('jsonrpc' => '2.0', 'id' => 1, 'result' => array('number' => '0x' . dechex($number), 'timestamp' => '0x' . dechex(et_block_time($block) + $shift))));
	}
	if ($m === 'eth_getLogs') {
		$nth = count(et_calls('eth_getLogs', $host)) - 1;
		if (isset($s['fail_chunk'][$host]) && $s['fail_chunk'][$host] === $nth) {
			return $json(array('jsonrpc' => '2.0', 'id' => 1, 'error' => array('code' => -32000, 'message' => 'range too large')));
		}
		$from = hexdec(substr($p[0]['fromBlock'], 2));
		$to = hexdec(substr($p[0]['toBlock'], 2));
		$out = array();
		foreach ($s['logs'] as $log) {
			if ($log['block'] < $from || $log['block'] > $to) { continue; }
			$row = array_merge(array(
				'address'         => $s['contract'],
				'topics'          => array(ET_TOPIC, et_pad(ET_OTHER), et_pad($log['to'])),
				'data'            => '0x' . str_pad($log['value'], 64, '0', STR_PAD_LEFT),
				'blockNumber'     => '0x' . dechex($log['block']),
				'transactionHash' => et_hash($log['block'] * 1000 + $log['index']),
				'logIndex'        => '0x' . dechex($log['index']),
				'removed'         => false,
			), isset($log['extra']) ? $log['extra'] : array());
			if ($s['log_time'] === 'zero') { $row['blockTimestamp'] = '0x0'; }
			elseif ($s['log_time']) { $row['blockTimestamp'] = '0x' . dechex(et_block_time($log['block'])); }
			$out[] = $row;
		}
		return $json(array('jsonrpc' => '2.0', 'id' => 1, 'result' => $out));
	}
	return $json(array('jsonrpc' => '2.0', 'id' => 1, 'error' => array('code' => -32601, 'message' => 'unknown')));
};

function et_log($block, $value = '0f4240', $index = 0, $extra = array(), $to = ET_ADDR) {
	return array('block' => $block, 'index' => $index, 'to' => $to, 'value' => $value, 'extra' => $extra);
}
function et_tokentx($rows) {
	return array('status' => '1', 'message' => 'OK', 'result' => $rows);
}
function et_row($hash, $to = ET_ADDR, $contract = ET_POL, $value = '1000000') {
	return array('hash' => $hash, 'to' => $to, 'from' => ET_OTHER, 'contractAddress' => $contract, 'value' => $value, 'confirmations' => '12', 'timeStamp' => (string) (time() - 60));
}

$LOOKBACK = 3 * 3600;
$polygonNode = 'polygon-bor-rpc.publicnode.com';

// --- 1. source order -----------------------------------------------------------
echo "--- 1. source order ---\n";
et_reset(array('blockscout' => et_tokentx(array(et_row('0xAB' . str_repeat('c', 62)), et_row('0x' . str_repeat('d', 64), ET_OTHER)))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
$meta = NMMPRO_Blockchain::take_raw_page_meta();
ok('Blockscout answers: its incoming rows are returned', $r['result'] === 'success' && count($r['transactions']) === 1);
ok('  no other source is asked', count(et_calls('eth_blockNumber')) === 0);
ok('  the hash is lower-cased (one identity across sources)', $r['transactions'][0]->get_hash() === '0xab' . str_repeat('c', 62));
ok('  the raw page (both directions) is reported', $meta === array(2, (int) $meta[1]) && $meta[0] === 2);

et_reset(array('logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('Polygon token, Blockscout down: the JSON-RPC node answers', $r['result'] === 'success' && count($r['transactions']) === 1 && $r['source'] === $polygonNode);
ok('  Blockscout was asked once, first', count(et_calls('blockscout')) === 1 && $GLOBALS['et']['calls'][0]['method'] === 'blockscout');

et_reset(array('contract' => ET_ETH, 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDT', ET_ADDR, $LOOKBACK);
ok('Ethereum token, Blockscout down: the JSON-RPC node answers', $r['result'] === 'success' && count($r['transactions']) === 1 && $r['source'] === 'ethereum-rpc.publicnode.com');
ok('  no third party other than Blockscout and the node is asked', count(array_filter($GLOBALS['et']['calls'], function ($c) { return !in_array($c['host'], array('eth.blockscout.com', 'ethereum-rpc.publicnode.com'), true); })) === 0);

et_reset(array('blockscout' => array('status' => '0', 'message' => 'NOTOK', 'result' => 'Max rate limit reached'), 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('Blockscout 200 with an error body: the next source is used', $r['result'] === 'success' && isset($r['source']));

et_reset(array('blockscout' => et_tokentx(array(array('hash' => '0x' . str_repeat('f', 64)))), 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('Blockscout row missing its fields: the next source is used', $r['result'] === 'success' && isset($r['source']));

et_reset(array('blockscout' => 'not json at all', 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('Blockscout 200 with a non-JSON body: the next source is used', $r['result'] === 'success' && isset($r['source']));

et_reset(array('blockscout' => et_tokentx(array())));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('Blockscout answers "no transfers": an empty success, no fallback', $r['result'] === 'success' && count($r['transactions']) === 0 && count(et_calls('eth_blockNumber')) === 0);

et_reset(array('fail_method' => array($polygonNode => 'eth_blockNumber')));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('every source down: an error, never an empty success', $r['result'] === 'error');
ok('  and no page is reported for the truncation check', NMMPRO_Blockchain::take_raw_page_meta() === null);

// --- 2. what a JSON-RPC answer contains ---------------------------------------------
echo "--- 2. the JSON-RPC answer ---\n";
$big = 'ffffffffffffffffffff';     // 2^80 - 1, beyond PHP's integers
et_reset(array('logs' => array(et_log(999000, '0f4240', 3), et_log(999990, $big, 7, array('transactionHash' => '0x' . strtoupper(str_repeat('ab', 32)))))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
$meta = NMMPRO_Blockchain::take_raw_page_meta();
$t = $r['transactions'];
ok('two transfers are returned, newest first', $r['result'] === 'success' && count($t) === 2 && $t[0]->get_time_stamp() > $t[1]->get_time_stamp());
ok('  value is the exact decimal amount', $t[1]->get_amount() === '1000000' && $t[0]->get_amount() === '1208925819614629174706175', $t[0]->get_amount());
ok('  confirmations are counted from the tip', $t[0]->get_confirmations() === 11 && $t[1]->get_confirmations() === 1001);
ok('  time is the block\'s own timestamp', $t[0]->get_time_stamp() === et_block_time(999990) && $t[1]->get_time_stamp() === et_block_time(999000));
ok('  the hash is lower-cased', $t[0]->get_hash() === '0x' . str_repeat('ab', 32));
ok('  the page is reported: 2 rows, oldest time', $meta === array(2, et_block_time(999000)));

et_reset(array('logs' => array(et_log(999990, '0f4240', 1), et_log(999990, '0f4240', 2))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('two transfers in one block: both rows, one header read', count($r['transactions']) === 2 && count(et_calls('eth_getBlockByNumber')) === 3, 'headers=' . count(et_calls('eth_getBlockByNumber')));

et_reset(array('logs' => array(et_log(999990)), 'log_time' => true));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('a node that stamps its logs needs no header per row', $r['result'] === 'success' && count(et_calls('eth_getBlockByNumber')) === 2 && $r['transactions'][0]->get_time_stamp() === et_block_time(999990));

// Arbitrum's public node stamps every log with blockTimestamp 0x0.
et_reset(array('logs' => array(et_log(999990)), 'log_time' => 'zero'));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
$meta = NMMPRO_Blockchain::take_raw_page_meta();
ok('a log stamped 0x0: the block header\'s time is used instead', $r['result'] === 'success' && $r['transactions'][0]->get_time_stamp() === et_block_time(999990) && count(et_calls('eth_getBlockByNumber')) === 3);
ok('  and the page report carries the real time, not zero', $meta === array(1, et_block_time(999990)));

et_reset(array('logs' => array(et_log(999990)), 'header_shift' => array(999990 => 86400)));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('a header time after the tip\'s: the fetch fails', $r['result'] === 'error');
et_reset(array('logs' => array(et_log(999990)), 'header_shift' => array(999990 => -30 * 86400)));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('a header time before the starting block\'s: the fetch fails', $r['result'] === 'error');

et_reset();
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('no transfers in range: an empty success with an empty page', $r['result'] === 'success' && $r['transactions'] === array() && NMMPRO_Blockchain::take_raw_page_meta() === array(0, null));

// --- 3. all or nothing ---------------------------------------------------------------
echo "--- 3. all or nothing ---\n";
// 3h + 30min margin at 2s/block is ~7,560 blocks: on Base's 2,000-block node that is several chunks.
$baseA = 'mainnet.base.org'; $baseB = 'base-rpc.publicnode.com';
et_reset(array('contract' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'logs' => array(et_log(999990)), 'fail_chunk' => array($baseA => 1, $baseB => 0)));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDCBAS', ET_ADDR, $LOOKBACK);
ok('a chunk fails on every node: the fetch is an error', $r['result'] === 'error' && count(et_calls('eth_getLogs', $baseA)) === 2);
ok('  although the newest chunk held a payment (no partial success)', NMMPRO_Blockchain::take_raw_page_meta() === null);

et_reset(array('contract' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'logs' => array(et_log(999990)), 'fail_chunk' => array($baseA => 1)));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDCBAS', ET_ADDR, $LOOKBACK);
ok('a chunk fails on the first node: the second node answers in full', $r['result'] === 'success' && $r['source'] === $baseB && count($r['transactions']) === 1);

et_reset(array('logs' => array(et_log(999990)), 'fail_method' => array($polygonNode => 'eth_getBlockByNumber')));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('headers unreadable: an error', $r['result'] === 'error');

et_reset(array('logs' => array(et_log(999990)), 'wrong_header' => true));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('a header for a different block than asked: an error', $r['result'] === 'error');

// --- 4. proven coverage --------------------------------------------------------------
echo "--- 4. proven coverage ---\n";
$cutoff = function () use ($LOOKBACK) { return $GLOBALS['et']['now'] - $LOOKBACK - NMMPRO_Evm_Transfers::MARGIN_SEC; };
$firstFrom = function () { $c = et_calls('eth_getLogs'); return hexdec(substr($c[0]['params'][0]['fromBlock'], 2)); };

et_reset();
NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('normal chain: the range starts at or before the lookback', et_block_time($firstFrom()) <= $cutoff(), 'start is ' . ($GLOBALS['et']['now'] - et_block_time($firstFrom())) . 's back');

// The chain is three times faster than assumed (0.5s against 1.5s): the first guess is too recent.
et_reset(array('block_sec' => 0.5, 'logs' => array(et_log(1000000 - 25000))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('faster chain: the start is moved back until its time reaches the lookback', $r['result'] === 'success' && et_block_time($firstFrom()) <= $cutoff(), 'start is ' . ($GLOBALS['et']['now'] - et_block_time($firstFrom())) . 's back');
ok('  so a payment near the old end of the window is found', count($r['transactions']) === 1);

ok('  and the range overshoots the lookback only slightly', $GLOBALS['et']['now'] - et_block_time($firstFrom()) < ($LOOKBACK + NMMPRO_Evm_Transfers::MARGIN_SEC) * 1.25);

// A node whose headers all carry the same recent time: no start block can prove the lookback.
et_reset(array('block_sec' => 0, 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('lookback cannot be proven: an error, not a short answer', $r['result'] === 'error' && count(et_calls('eth_getLogs')) === 0);

// A chain so fast that covering the lookback exceeds the request budget.
et_reset(array('block_sec' => 0.01, 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('lookback needs more requests than the budget: an error', $r['result'] === 'error');

et_reset();
NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, 0);
ok('no lookback given: the default (longer) lookback is covered', et_block_time($firstFrom()) <= $GLOBALS['et']['now'] - NMMPRO_Blockchain::ERC20_DEFAULT_LOOKBACK_SEC);

// --- 5. a live tip --------------------------------------------------------------------
echo "--- 5. a live tip ---\n";
et_reset(array('tip_age' => NMMPRO_Evm_Transfers::MAX_TIP_AGE_SEC + 60, 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('a node whose tip is old is refused', $r['result'] === 'error' && count(et_calls('eth_getLogs')) === 0);
et_reset(array('tip_age' => NMMPRO_Evm_Transfers::MAX_TIP_AGE_SEC - 60, 'logs' => array(et_log(999990))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('  control: a tip just inside the limit is accepted', $r['result'] === 'success');

// --- 6. one snapshot ------------------------------------------------------------------
echo "--- 6. one snapshot ---\n";
et_reset(array('contract' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913'));
NMMPRO_Blockchain::get_erc20_address_transactions('USDCBAS', ET_ADDR, $LOOKBACK);
$chunks = et_calls('eth_getLogs', $baseA);
$contiguous = true; $withinSpan = true; $explicit = true; $prevEnd = null;
foreach ($chunks as $c) {
	$f = $c['params'][0]['fromBlock']; $t2 = $c['params'][0]['toBlock'];
	if (!preg_match('/^0x[0-9a-f]+$/', $f) || !preg_match('/^0x[0-9a-f]+$/', $t2)) { $explicit = false; continue; }
	$f = hexdec(substr($f, 2)); $t2 = hexdec(substr($t2, 2));
	if ($prevEnd !== null && $f !== $prevEnd + 1) { $contiguous = false; }
	if ($t2 - $f + 1 > 2000) { $withinSpan = false; }
	$prevEnd = $t2;
}
ok('several chunks were needed', count($chunks) >= 4, 'chunks=' . count($chunks));
ok('  every range is explicit block numbers, never "latest"', $explicit);
ok('  the chunks are contiguous and end at the tip', $contiguous && $prevEnd === 1000000);
ok('  none exceeds the node\'s span limit', $withinSpan);
ok('  each asks for this contract, Transfer, to this address', $chunks[0]['params'][0]['address'] === '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913' && $chunks[0]['params'][0]['topics'] === array(ET_TOPIC, null, et_pad(ET_ADDR)));

// --- 7. strict parsing ----------------------------------------------------------------
echo "--- 7. strict parsing ---\n";
$bad = array(
	'a log from another contract'       => array('address' => '0x' . str_repeat('9', 40)),
	'a log with four topics'            => array('topics' => array(ET_TOPIC, et_pad(ET_OTHER), et_pad(ET_ADDR), et_pad(ET_OTHER))),
	'a log to another address'          => array('topics' => array(ET_TOPIC, et_pad(ET_OTHER), et_pad(ET_OTHER))),
	'a log of another event'            => array('topics' => array('0x' . str_repeat('1', 64), et_pad(ET_OTHER), et_pad(ET_ADDR))),
	'a value that is not 32 bytes'      => array('data' => '0x0f4240'),
	'a malformed transaction hash'      => array('transactionHash' => '0x1234'),
	'a block outside the range asked'   => array('blockNumber' => '0x1'),
	'a malformed block number'          => array('blockNumber' => 'latest'),
	'a malformed log timestamp'         => array('blockTimestamp' => 'soon'),
);
foreach ($bad as $label => $extra) {
	et_reset(array('logs' => array(et_log(999990, '0f4240', 0, $extra))));
	$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
	ok($label . ': the fetch fails', $r['result'] === 'error');
}
// A log that belongs to an EARLIER chunk of the same overall range, served in the
// last chunk: its time fits, so only the per-chunk range check can refuse it.
et_reset(array('contract' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'logs' => array(et_log(999990, '0f4240', 0, array('blockNumber' => '0x' . dechex(1000000 - 3000))))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDCBAS', ET_ADDR, $LOOKBACK);
ok('a log from another chunk of the range: the fetch fails', $r['result'] === 'error' && count(et_calls('eth_getLogs', 'mainnet.base.org')) >= 4);
et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('removed' => true)), et_log(999980))));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
ok('a log flagged removed (reorged out) is ignored, the rest kept', $r['result'] === 'success' && count($r['transactions']) === 1 && $r['transactions'][0]->get_time_stamp() === et_block_time(999980));
$r = NMMPRO_Evm_Transfers::fetch(137, ET_POL, 'not-an-address', $LOOKBACK);
ok('a malformed address is refused before any request', $r['result'] === 'error');

// --- 8. the page report ---------------------------------------------------------------
echo "--- 8. the page report ---\n";
$many = array();
for ($i = 0; $i < 150; $i++) { $many[] = et_log(999990 - $i, '0f4240', 0); }
et_reset(array('logs' => $many, 'log_time' => true));
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
$meta = NMMPRO_Blockchain::take_raw_page_meta();
ok('150 transfers in range: the newest 100 are returned', $r['result'] === 'success' && count($r['transactions']) === 100 && $r['transactions'][0]->get_time_stamp() === et_block_time(999990));
ok('  reported as a full page whose oldest row is inside the window', $meta === array(NMMPRO_Evm_Transfers::MAX_ROWS, et_block_time(999990 - 99)) && NMMPRO_Blockchain::adapter_page_cap('USDTPOL') === NMMPRO_Evm_Transfers::MAX_ROWS);

// --- 9. bounded work ------------------------------------------------------------------
echo "--- 9. bounded work ---\n";
$spread = array();
for ($i = 0; $i < 60; $i++) { $spread[] = et_log(999990 - $i * 3); }
et_reset(array('logs' => $spread));           // no log timestamps: one header per block
$r = NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, $LOOKBACK);
$rpcCalls = 0; foreach ($GLOBALS['et']['calls'] as $c) { if ($c['host'] === $polygonNode) { $rpcCalls++; } }
ok('more headers needed than the budget allows: an error', $r['result'] === 'error');
ok('  and the node was asked no more than the budget', $rpcCalls <= NMMPRO_Evm_Transfers::MAX_REQUESTS, 'calls=' . $rpcCalls);

// --- 10. hexadecimal to decimal ---------------------------------------------------------
echo "--- 10. hexadecimal to decimal ---\n";
$cases = array(
	'0' => '0', '00' => '0', '1' => '1', 'ff' => '255', '0f4240' => '1000000',
	'10000000000000000' => '18446744073709551616',
	str_repeat('f', 64) => '115792089237316195423570985008687907853269984665640564039457584007913129639935',
);
$allOk = true;
foreach ($cases as $hex => $dec) { if (NMMPRO_Evm_Transfers::hex_to_decimal($hex) !== $dec) { $allOk = false; echo "  $hex => " . NMMPRO_Evm_Transfers::hex_to_decimal($hex) . "\n"; } }
ok('hex values convert exactly, up to the largest uint256', $allOk);

echo $GLOBALS['et_failed']
	? "\nEVM-TRANSFERS CHECKS FAILED\n"
	: "\nEVM-TRANSFERS CHECKS PASSED (" . $GLOBALS['et_count'] . ")\n";
exit($GLOBALS['et_failed'] ? 1 : 0);
