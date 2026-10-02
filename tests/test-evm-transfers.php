<?php
/**
 * Offline test for the ERC-20 transfer sources and their fallback order
 * (issue #17): Blockscout, then public JSON-RPC nodes read log by log
 * (NMMPRO_Evm_Transfers).
 *
 * A scripted explorer and a scripted chain answer every request; nothing
 * reaches the network. Each section states a rule:
 *
 *   1. source order: the node is used only when Blockscout fails
 *   2. what a node answer contains (value, confirmations, time, hash)
 *   3. a node answer never certifies an address for expiry
 *   4. all or nothing: anything unreadable fails the fetch
 *   5. proven coverage: the starting block's own time reaches the lookback
 *   6. a live tip: a lagging node is refused
 *   7. one chain: explicit contiguous ranges, block hashes, an unchanged tip
 *   8. strict parsing of logs
 *   9. times come from verified headers, never from the log
 *  10. replies of the wrong JSON type are unreadable, not empty
 *  11. the page report and the row cap
 *  12. bounded work
 *  13. the Blockscout parser: required fields, hashes passed through as served
 *  14. the hexadecimal-to-decimal conversion
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
	'src/NMMPRO_Payment.php',
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
const ET_BASE  = '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913'; // USDCBAS

function et_pad($address) { return '0x' . str_repeat('0', 24) . substr(strtolower($address), 2); }
function et_hash($n) { return '0x' . str_pad(dechex($n), 64, 'a', STR_PAD_LEFT); }

/**
 * A scripted chain behind one or more JSON-RPC URLs, plus Blockscout.
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
		'logs'         => array(),    // see et_log()
		'log_time'     => null,       // null: no blockTimestamp; 'zero': 0x0; an int: that many seconds before now
		'header_shift' => array(),    // block => seconds added to that block's header time
		'blockscout'   => 500,        // HTTP status, a string body, or an array body for a 200
		'fail_chunk'   => array(),    // url host => index of the eth_getLogs call to fail
		'fail_method'  => array(),    // url host => method that always fails
		'fail_header'  => null,       // a block whose header read fails
		'wrong_number' => null,       // a block whose header names another block number
		'reorg_tip'    => false,      // the tip's hash differs on the second read
		'logs_kind'    => 'list',     // 'object': eth_getLogs answers {} instead of a list
		'header_kind'  => 'object',   // 'list': headers answered as a JSON list
		'calls'        => array(),    // every request, in order
	), $overrides);
}
function et_block_time($block) {
	$s = $GLOBALS['et'];
	return (int) ($s['now'] - $s['tip_age'] - ($s['tip'] - $block) * $s['block_sec']);
}
function et_block_hash($block, $variant = '') {
	return '0x' . hash('sha256', 'block-' . $block . $variant);
}
function et_calls($method, $host = null) {
	$out = array();
	foreach ($GLOBALS['et']['calls'] as $c) {
		if ($c['method'] === $method && ($host === null || $c['host'] === $host)) { $out[] = $c; }
	}
	return $out;
}
function et_headers_of($block) {
	$n = 0;
	foreach (et_calls('eth_getBlockByNumber') as $c) { if (hexdec(substr($c['params'][0], 2)) === $block) { $n++; } }
	return $n;
}

$GLOBALS['nmmpro_http_handler'] = function ($url, $method, $postBody, $headers) {
	$s = &$GLOBALS['et'];
	$host = (string) parse_url($url, PHP_URL_HOST);
	$json = function ($body, $code = 200) { return array('body' => json_encode($body), 'response' => array('code' => $code)); };
	$rpc = function ($result) use ($json) { return $json(array('jsonrpc' => '2.0', 'id' => 1, 'result' => $result)); };

	if ($method === 'GET') {
		$s['calls'][] = array('method' => 'blockscout', 'host' => $host, 'url' => $url);
		$answer = $s['blockscout'];
		if (is_int($answer)) { return array('body' => 'down', 'response' => array('code' => $answer)); }
		return is_string($answer) ? array('body' => $answer, 'response' => array('code' => 200)) : $json($answer);
	}

	$req = json_decode($postBody, true);
	$m = $req['method'];
	$p = $req['params'];
	$s['calls'][] = array('method' => $m, 'host' => $host, 'params' => $p);

	if (isset($s['fail_method'][$host]) && $s['fail_method'][$host] === $m) {
		return array('body' => 'down', 'response' => array('code' => 503));
	}

	if ($m === 'eth_blockNumber') {
		return $rpc('0x' . dechex($s['tip']));
	}
	if ($m === 'eth_getBlockByNumber') {
		$block = hexdec(substr($p[0], 2));
		if ($s['fail_header'] === $block) {
			return $json(array('jsonrpc' => '2.0', 'id' => 1, 'error' => array('code' => -32000, 'message' => 'header unavailable')));
		}
		$variant = ($block === $s['tip'] && $s['reorg_tip'] && et_headers_of($block) > 1) ? '-reorged' : '';
		$header = array(
			'number'    => '0x' . dechex($s['wrong_number'] === $block ? $block + 1 : $block),
			'hash'      => et_block_hash($block, $variant),
			'timestamp' => '0x' . dechex(et_block_time($block) + (isset($s['header_shift'][$block]) ? $s['header_shift'][$block] : 0)),
		);
		return $rpc($s['header_kind'] === 'list' ? array_values($header) : $header);
	}
	if ($m === 'eth_getLogs') {
		if ($s['logs_kind'] === 'object') {
			return array('body' => '{"jsonrpc":"2.0","id":1,"result":{}}', 'response' => array('code' => 200));
		}
		$nth = count(et_calls('eth_getLogs', $host)) - 1;
		if (isset($s['fail_chunk'][$host]) && $s['fail_chunk'][$host] === $nth) {
			return $json(array('jsonrpc' => '2.0', 'id' => 1, 'error' => array('code' => -32000, 'message' => 'range too large')));
		}
		$from = hexdec(substr($p[0]['fromBlock'], 2));
		$to = hexdec(substr($p[0]['toBlock'], 2));
		$out = array();
		foreach ($s['logs'] as $log) {
			if ($log['block'] < $from || $log['block'] > $to) { continue; }
			$row = array(
				'address'         => $s['contract'],
				'topics'          => array(ET_TOPIC, et_pad(ET_OTHER), et_pad($log['to'])),
				'data'            => '0x' . str_pad($log['value'], 64, '0', STR_PAD_LEFT),
				'blockNumber'     => '0x' . dechex($log['block']),
				'blockHash'       => et_block_hash($log['block']),
				'transactionHash' => et_hash($log['block'] * 1000 + $log['index']),
				'logIndex'        => '0x' . dechex($log['index']),
				'removed'         => false,
			);
			if ($s['log_time'] === 'zero') { $row['blockTimestamp'] = '0x0'; }
			elseif (is_int($s['log_time'])) { $row['blockTimestamp'] = '0x' . dechex($s['now'] - $s['log_time']); }
			$out[] = array_merge($row, $log['extra']);
		}
		return $rpc($out);
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
// The $i-th transaction of a result, or an empty one: a mutant that turns a
// success into an error must fail a check, not crash the suite.
function et_t($r, $i) {
	return (is_array($r) && isset($r['transactions'][$i])) ? $r['transactions'][$i] : new NMMPRO_Transaction(null, null, null, null);
}
function et_fetch($cryptoId = 'USDTPOL', $lookback = null) {
	return NMMPRO_Blockchain::get_erc20_address_transactions($cryptoId, ET_ADDR, $lookback === null ? $GLOBALS['LOOKBACK'] : $lookback);
}

$GLOBALS['LOOKBACK'] = $LOOKBACK = 3 * 3600;
$polygonNode = 'polygon-bor-rpc.publicnode.com';
$baseA = 'mainnet.base.org';
$baseB = 'base-rpc.publicnode.com';

// --- 1. source order -----------------------------------------------------------
echo "--- 1. source order ---\n";
et_reset(array('blockscout' => et_tokentx(array(et_row('0x' . str_repeat('c', 64)), et_row('0x' . str_repeat('d', 64), ET_OTHER)))));
$r = et_fetch();
$meta = NMMPRO_Blockchain::take_raw_page_meta();
ok('Blockscout answers: its incoming rows are returned', $r['result'] === 'success' && count($r['transactions']) === 1);
ok('  no node is asked', count(et_calls('eth_blockNumber')) === 0);
ok('  the raw page (both directions) is reported, certifiable', is_array($meta) && count($meta) === 2 && $meta[0] === 2);

et_reset(array('logs' => array(et_log(999990))));
$r = et_fetch();
ok('Polygon token, Blockscout down: the JSON-RPC node answers', $r['result'] === 'success' && count($r['transactions']) === 1 && $r['source'] === $polygonNode);
ok('  Blockscout was asked once, first', count(et_calls('blockscout')) === 1 && $GLOBALS['et']['calls'][0]['method'] === 'blockscout');

et_reset(array('contract' => ET_ETH, 'logs' => array(et_log(999990))));
$r = et_fetch('USDT');
ok('Ethereum token, Blockscout down: the JSON-RPC node answers', $r['result'] === 'success' && count($r['transactions']) === 1 && $r['source'] === 'ethereum-rpc.publicnode.com');
ok('  no third party other than Blockscout and the node is asked', count(array_filter($GLOBALS['et']['calls'], function ($c) { return !in_array($c['host'], array('eth.blockscout.com', 'ethereum-rpc.publicnode.com'), true); })) === 0);

foreach (array(
	'Blockscout 200 with an error body'   => array('status' => '0', 'message' => 'NOTOK', 'result' => 'Max rate limit reached'),
	'Blockscout 200 with a non-JSON body' => 'not json at all',
) as $label => $body) {
	et_reset(array('blockscout' => $body, 'logs' => array(et_log(999990))));
	$r = et_fetch();
	ok($label . ': the node is used', $r['result'] === 'success' && isset($r['source']));
}

et_reset(array('blockscout' => et_tokentx(array())));
$r = et_fetch();
ok('Blockscout answers "no transfers": an empty success, no fallback', $r['result'] === 'success' && count($r['transactions']) === 0 && count(et_calls('eth_blockNumber')) === 0);

et_reset(array('fail_method' => array($polygonNode => 'eth_blockNumber')));
$r = et_fetch();
ok('every source down: an error, never an empty success', $r['result'] === 'error');
ok('  and no page is reported', NMMPRO_Blockchain::take_raw_page_meta() === null);

// --- 2. the node answer ----------------------------------------------------------
echo "--- 2. the node answer ---\n";
$big = 'ffffffffffffffffffff';     // 2^80 - 1, beyond PHP's integers
et_reset(array('logs' => array(et_log(999000, '0f4240', 3), et_log(999990, $big, 7))));
$r = et_fetch();
$meta = NMMPRO_Blockchain::take_raw_page_meta();
$t = array(et_t($r, 0), et_t($r, 1));
ok('two transfers are returned, newest first', $r['result'] === 'success' && count($t) === 2 && $t[0]->get_time_stamp() > $t[1]->get_time_stamp());
ok('  value is the exact decimal amount', $t[1]->get_amount() === '1000000' && $t[0]->get_amount() === '1208925819614629174706175', $t[0]->get_amount());
ok('  confirmations are counted as Blockscout counts them (tip - block)', $t[0]->get_confirmations() === 10 && $t[1]->get_confirmations() === 1000);
ok('  time is the verified block header\'s', $t[0]->get_time_stamp() === et_block_time(999990) && $t[1]->get_time_stamp() === et_block_time(999000));
ok('  the hash is exactly as the node sent it', $t[0]->get_hash() === et_hash(999990 * 1000 + 7));
ok('  the page is reported: 2 rows, oldest time, not certifiable', $meta === array(2, et_block_time(999000), false));

et_reset(array('logs' => array(et_log(999990, '0f4240', 1), et_log(999990, '0f4240', 2))));
$r = et_fetch();
ok('two transfers in one block: both rows, one header read for it', count($r['transactions']) === 2 && et_headers_of(999990) === 1);

et_reset();
$r = et_fetch();
ok('no transfers in range: an empty success, page not certifiable', $r['result'] === 'success' && $r['transactions'] === array() && NMMPRO_Blockchain::take_raw_page_meta() === array(0, null, false));

// --- 3. never certifies an address for expiry -----------------------------------------
echo "--- 3. never certifies for expiry ---\n";
et_reset(array('logs' => array(et_log(999990))));
$r = et_fetch();
$nodeVisit = array('transactions' => $r['transactions'], 'page' => NMMPRO_Blockchain::take_raw_page_meta());
ok('a node answer with one transfer is treated as possibly incomplete', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $nodeVisit, $LOOKBACK, time()) === true);
et_reset();
$r = et_fetch();
$emptyVisit = array('transactions' => $r['transactions'], 'page' => NMMPRO_Blockchain::take_raw_page_meta());
ok('  as is an empty node answer (a lagging node looks exactly like this)', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $emptyVisit, $LOOKBACK, time()) === true);
et_reset(array('blockscout' => et_tokentx(array(et_row('0x' . str_repeat('c', 64))))));
$r = et_fetch();
$bsVisit = array('transactions' => $r['transactions'], 'page' => NMMPRO_Blockchain::take_raw_page_meta());
ok('  control: the same short page from Blockscout certifies', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $bsVisit, $LOOKBACK, time()) === false);

// --- 4. all or nothing -----------------------------------------------------------------
echo "--- 4. all or nothing ---\n";
// 3h + 30 min margin at 2 s/block is ~7,560 blocks: several chunks on Base's 2,000-block node.
et_reset(array('contract' => ET_BASE, 'logs' => array(et_log(999990)), 'fail_chunk' => array($baseA => 1, $baseB => 0)));
$r = et_fetch('USDCBAS');
ok('a chunk fails on every node: the fetch is an error', $r['result'] === 'error' && count(et_calls('eth_getLogs', $baseA)) === 2);
ok('  although the newest chunk held a payment (no partial success)', NMMPRO_Blockchain::take_raw_page_meta() === null);

et_reset(array('contract' => ET_BASE, 'logs' => array(et_log(999990)), 'fail_chunk' => array($baseA => 1)));
$r = et_fetch('USDCBAS');
ok('a chunk fails on the first node: the second node answers in full', $r['result'] === 'success' && $r['source'] === $baseB && count($r['transactions']) === 1);

et_reset(array('logs' => array(et_log(999990)), 'fail_method' => array($polygonNode => 'eth_getBlockByNumber')));
ok('headers unreadable: an error', et_fetch()['result'] === 'error');

et_reset(array('logs' => array(et_log(999990)), 'fail_header' => 999990));
ok('the header of a transfer\'s block unreadable, after usable logs: an error', et_fetch()['result'] === 'error' && count(et_calls('eth_getLogs')) >= 1);

// --- 5. proven coverage ------------------------------------------------------------------
echo "--- 5. proven coverage ---\n";
$cutoff = function () use ($LOOKBACK) { return $GLOBALS['et']['now'] - $LOOKBACK - NMMPRO_Evm_Transfers::MARGIN_SEC; };
$firstFrom = function () { $c = et_calls('eth_getLogs'); return hexdec(substr($c[0]['params'][0]['fromBlock'], 2)); };

et_reset();
et_fetch();
ok('normal chain: the range starts at or before the lookback', et_block_time($firstFrom()) <= $cutoff(), 'start is ' . ($GLOBALS['et']['now'] - et_block_time($firstFrom())) . 's back');

// The chain is three times faster than assumed (0.5 s against 1.5 s): the first guess is too recent.
et_reset(array('block_sec' => 0.5, 'logs' => array(et_log(1000000 - 25000))));
$r = et_fetch();
ok('faster chain: the start is moved back until its time reaches the lookback', $r['result'] === 'success' && et_block_time($firstFrom()) <= $cutoff(), 'start is ' . ($GLOBALS['et']['now'] - et_block_time($firstFrom())) . 's back');
ok('  so a payment near the old end of the window is found', count($r['transactions']) === 1);
ok('  and the range overshoots the lookback only slightly', $GLOBALS['et']['now'] - et_block_time($firstFrom()) < ($LOOKBACK + NMMPRO_Evm_Transfers::MARGIN_SEC) * 1.25);

et_reset(array('block_sec' => 0, 'logs' => array(et_log(999990))));
$r = et_fetch();
ok('lookback cannot be proven: an error, not a short answer', $r['result'] === 'error' && count(et_calls('eth_getLogs')) === 0);

et_reset(array('block_sec' => 0.01, 'logs' => array(et_log(999990))));
ok('lookback needs more requests than the budget: an error', et_fetch()['result'] === 'error');

et_reset(array('tip' => 3000, 'logs' => array(et_log(10))));
$r = et_fetch();
ok('a chain younger than the lookback: read from block 0, and found', $r['result'] === 'success' && $firstFrom() === 0 && count($r['transactions']) === 1);

et_reset();
NMMPRO_Blockchain::get_erc20_address_transactions('USDTPOL', ET_ADDR, 0);
ok('no lookback given: the default lookback is covered', et_block_time($firstFrom()) <= $GLOBALS['et']['now'] - NMMPRO_Blockchain::ERC20_DEFAULT_LOOKBACK_SEC);

// --- 6. a live tip -------------------------------------------------------------------------
echo "--- 6. a live tip ---\n";
et_reset(array('tip_age' => NMMPRO_Evm_Transfers::MAX_TIP_AGE_SEC + 30, 'logs' => array(et_log(999990))));
$r = et_fetch();
ok('a node whose tip is older than the limit is refused', $r['result'] === 'error' && count(et_calls('eth_getLogs')) === 0);
et_reset(array('tip_age' => NMMPRO_Evm_Transfers::MAX_TIP_AGE_SEC - 30, 'logs' => array(et_log(999990))));
ok('  control: a tip just inside the limit is accepted', et_fetch()['result'] === 'success');
ok('  and the limit is a few minutes, not a quarter of an hour', NMMPRO_Evm_Transfers::MAX_TIP_AGE_SEC <= 300);

// --- 7. one chain -------------------------------------------------------------------------
echo "--- 7. one chain ---\n";
et_reset(array('contract' => ET_BASE));
et_fetch('USDCBAS');
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
ok('  each asks for this contract, Transfer, to this address', $chunks[0]['params'][0]['address'] === ET_BASE && $chunks[0]['params'][0]['topics'] === array(ET_TOPIC, null, et_pad(ET_ADDR)));
ok('  the tip header is read again at the end', et_headers_of(1000000) >= 2);

et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('blockHash' => et_block_hash(999990, '-orphan'))))));
ok('a transfer naming a block hash its header does not have: an error', et_fetch()['result'] === 'error');

et_reset(array('logs' => array(et_log(999990)), 'reorg_tip' => true));
ok('the tip replaced while the range was read: an error', et_fetch()['result'] === 'error');
et_reset(array('logs' => array(et_log(999990))));
ok('  control: an unchanged tip and matching hashes succeed', et_fetch()['result'] === 'success');

// --- 8. strict parsing --------------------------------------------------------------------
echo "--- 8. strict parsing ---\n";
$bad = array(
	'a log from another contract'            => array('address' => '0x' . str_repeat('9', 40)),
	'a log with four topics'                 => array('topics' => array(ET_TOPIC, et_pad(ET_OTHER), et_pad(ET_ADDR), et_pad(ET_OTHER))),
	'a log to another address'               => array('topics' => array(ET_TOPIC, et_pad(ET_OTHER), et_pad(ET_OTHER))),
	'a log of another event'                 => array('topics' => array('0x' . str_repeat('1', 64), et_pad(ET_OTHER), et_pad(ET_ADDR))),
	'a value that is not 32 bytes'           => array('data' => '0x0f4240'),
	'a malformed transaction hash'           => array('transactionHash' => '0x1234'),
	'an upper-case transaction hash'         => array('transactionHash' => '0x' . str_repeat('AB', 32)),
	'a missing block hash'                   => array('blockHash' => null),
	'a block outside the range asked'        => array('blockNumber' => '0x1'),
	'a malformed block number'               => array('blockNumber' => 'latest'),
	'a malformed log index'                  => array('logIndex' => 'first'),
);
foreach ($bad as $label => $extra) {
	et_reset(array('logs' => array(et_log(999990, '0f4240', 0, $extra))));
	ok($label . ': the fetch fails', et_fetch()['result'] === 'error');
}
// A log from an EARLIER chunk of the same range, served in the last chunk.
et_reset(array('contract' => ET_BASE, 'logs' => array(et_log(999990, '0f4240', 0, array('blockNumber' => '0x' . dechex(1000000 - 3000), 'blockHash' => et_block_hash(1000000 - 3000))))));
ok('a log from another chunk of the range: the fetch fails', et_fetch('USDCBAS')['result'] === 'error' && count(et_calls('eth_getLogs', $baseA)) >= 4);
et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('removed' => true)), et_log(999980))));
$r = et_fetch();
ok('a log flagged removed (reorged out) is ignored, the rest kept', $r['result'] === 'success' && count($r['transactions']) === 1 && et_t($r, 0)->get_time_stamp() === et_block_time(999980));
ok('a malformed address is refused before any request', NMMPRO_Evm_Transfers::fetch(137, ET_POL, 'not-an-address', $LOOKBACK)['result'] === 'error');

// --- 9. times from verified headers -----------------------------------------------------------
echo "--- 9. times from headers ---\n";
et_reset(array('logs' => array(et_log(999990)), 'log_time' => 'zero'));
$r = et_fetch();
ok('a log stamped 0x0 (as Arbitrum\'s node sends): the header\'s time is used', $r['result'] === 'success' && et_t($r, 0)->get_time_stamp() === et_block_time(999990));
// A plausible wrong stamp: inside [start, tip], but 3 h old for a transfer seconds old.
et_reset(array('logs' => array(et_log(999990)), 'log_time' => $LOOKBACK + 1));
$r = et_fetch();
ok('a plausible but wrong log stamp is ignored: the header\'s time is used', $r['result'] === 'success' && et_t($r, 0)->get_time_stamp() === et_block_time(999990), 'age=' . (time() - et_t($r, 0)->get_time_stamp()) . 's');
et_reset(array('logs' => array(et_log(999990)), 'header_shift' => array(999990 => 86400)));
ok('a header time after the tip\'s: the fetch fails', et_fetch()['result'] === 'error');
et_reset(array('logs' => array(et_log(999990)), 'header_shift' => array(999990 => -30 * 86400)));
ok('a header time before the starting block\'s: the fetch fails', et_fetch()['result'] === 'error');
et_reset(array('logs' => array(et_log(999990)), 'wrong_number' => 999990));
ok('a transfer\'s header naming another block number: the fetch fails', et_fetch()['result'] === 'error');
et_reset(array('logs' => array(et_log(999990)), 'wrong_number' => 1000000));
ok('the tip header naming another block number: the fetch fails', et_fetch()['result'] === 'error');

// --- 10. replies of the wrong JSON type ---------------------------------------------------------
echo "--- 10. wrong JSON types ---\n";
et_reset(array('logs' => array(et_log(999990)), 'logs_kind' => 'object'));
$r = et_fetch();
ok('logs answered as {} instead of a list: an error, not "no transfers"', $r['result'] === 'error' && NMMPRO_Blockchain::take_raw_page_meta() === null);
et_reset(array('logs' => array(et_log(999990)), 'header_kind' => 'list'));
ok('a header answered as a list: an error', et_fetch()['result'] === 'error');

// --- 11. the page report and the row cap -----------------------------------------------------------
echo "--- 11. the page report ---\n";
$many = array();
for ($i = 0; $i < 150; $i++) { $many[] = et_log(999990 - $i, '0f4240', 0); }
et_reset(array('logs' => $many));
$r = et_fetch();
$meta = NMMPRO_Blockchain::take_raw_page_meta();
// 150 rows need 100 headers: more than the budget, so this fails rather than truncating silently.
ok('150 transfers needing 100 headers: an error, not a partial page', $r['result'] === 'error' && $meta === null);
$burst = array();
for ($i = 0; $i < 150; $i++) { $burst[] = et_log(999990 - intdiv($i, 50), '0f4240', $i); }
et_reset(array('logs' => $burst));
$r = et_fetch();
$meta = NMMPRO_Blockchain::take_raw_page_meta();
ok('150 transfers in three blocks: the newest 100 are returned', $r['result'] === 'success' && count($r['transactions']) === 100);
ok('  reported as a full page, not certifiable', is_array($meta) && $meta[0] === NMMPRO_Evm_Transfers::MAX_ROWS && $meta[2] === false && NMMPRO_Blockchain::adapter_page_cap('USDTPOL') === NMMPRO_Evm_Transfers::MAX_ROWS);

// --- 12. bounded work ------------------------------------------------------------------------------
echo "--- 12. bounded work ---\n";
$spread = array();
for ($i = 0; $i < 60; $i++) { $spread[] = et_log(999990 - $i * 3); }
et_reset(array('logs' => $spread));
$r = et_fetch();
$rpcCalls = 0; foreach ($GLOBALS['et']['calls'] as $c) { if ($c['host'] === $polygonNode) { $rpcCalls++; } }
ok('more headers needed than the budget allows: an error', $r['result'] === 'error');
ok('  and the node was asked no more than the budget', $rpcCalls <= NMMPRO_Evm_Transfers::MAX_REQUESTS, 'calls=' . $rpcCalls);
ok('a fetch from one node has a wall-clock limit of a minute or less', NMMPRO_Evm_Transfers::MAX_FETCH_SEC > 0 && NMMPRO_Evm_Transfers::MAX_FETCH_SEC <= 60);

// --- 13. the Blockscout parser ------------------------------------------------------------------------
echo "--- 13. the Blockscout parser ---\n";
foreach (array('to', 'value', 'confirmations', 'timeStamp', 'hash') as $field) {
	$row = et_row('0x' . str_repeat('e', 64));
	unset($row[$field]);
	et_reset(array('blockscout' => et_tokentx(array($row)), 'logs' => array(et_log(999990))));
	$r = et_fetch();
	ok('Blockscout row without "' . $field . '": the node is used', $r['result'] === 'success' && isset($r['source']));
}
et_reset(array('blockscout' => et_tokentx(array(et_row('0xAB' . str_repeat('c', 62))))));
$r = et_fetch();
ok('a Blockscout hash is passed through exactly as served (ledger identity)', et_t($r, 0)->get_hash() === '0xAB' . str_repeat('c', 62));
// The same transfer from both sources must carry one identity in the consumed ledger.
$hash = et_hash(999990 * 1000);
et_reset(array('blockscout' => et_tokentx(array(et_row($hash)))));
$fromExplorer = et_t(et_fetch(), 0)->get_hash();
et_reset(array('logs' => array(et_log(999990))));
$fromNode = et_t(et_fetch(), 0)->get_hash();
ok('the same transfer from Blockscout and from a node: one identical hash', $fromExplorer === $fromNode && $fromNode === $hash);

// --- 14. hexadecimal to decimal ---------------------------------------------------------------------------
echo "--- 14. hexadecimal to decimal ---\n";
$cases = array(
	array('0', '0'), array('00', '0'), array('1', '1'), array('ff', '255'), array('0f4240', '1000000'),
	array('10000000000000000', '18446744073709551616'),
	array(str_repeat('f', 64), '115792089237316195423570985008687907853269984665640564039457584007913129639935'),
);
$allOk = true;
foreach ($cases as $case) { if (NMMPRO_Evm_Transfers::hex_to_decimal($case[0]) !== $case[1]) { $allOk = false; echo '  ' . $case[0] . ' => ' . NMMPRO_Evm_Transfers::hex_to_decimal($case[0]) . "\n"; } }
ok('hex values convert exactly, up to the largest uint256', $allOk);

echo $GLOBALS['et_failed']
	? "\nEVM-TRANSFERS CHECKS FAILED\n"
	: "\nEVM-TRANSFERS CHECKS PASSED (" . $GLOBALS['et_count'] . ")\n";
exit($GLOBALS['et_failed'] ? 1 : 0);
