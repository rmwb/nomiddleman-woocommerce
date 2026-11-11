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
 *   3. a node answer certifies for expiry only up to its horizon, and only
 *      when the node shows its log index reaches it
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

// Installed BEFORE wp-stubs.php claims the name, so a section can hook
// nmmpro_evm_rpc_nodes (which also passes the chain id).
$GLOBALS['et_filters'] = array();
if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value, ...$args) {
		if (isset($GLOBALS['et_filters'][$tag])) {
			return call_user_func($GLOBALS['et_filters'][$tag], $value, ...$args);
		}
		return $value;
	}
}

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
		'busy'         => array(),    // blocks holding a transfer of the token to someone else
		'busy_extra'   => array(),    // fields overriding those transfers' logs
		'busy_anywhere'=> false,      // serve those transfers whatever block range was asked
		'log_time'     => null,       // null: no blockTimestamp; 'zero': 0x0; an int: that many seconds before now
		'header_shift' => array(),    // block => seconds added to that block's header time
		'blockscout'   => 500,        // HTTP status, a string body, or an array body for a 200
		'fail_chunk'   => array(),    // url host => index of the address's eth_getLogs call to fail (index-proof queries not counted)
		'fail_proof'   => false,      // the index-proof queries (no recipient filter) fail
		'fail_method'  => array(),    // url host => method that always fails
		'fail_header'  => null,       // a block whose header read fails
		'wrong_number' => null,       // a block whose header names another block number
		'reorg_tip'    => false,      // the tip's hash differs on the second read
		'replaced'     => null,       // a block whose hash differs from its second header read on
		'lag_calls'    => 0,          // the first this-many eth_getLogs calls come from an index LAG blocks behind
		'lag'          => 1000,
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
// Only the queries that filter on the recipient: the address's own range reads.
function et_addr_logs($host = null) {
	$out = array();
	foreach (et_calls('eth_getLogs', $host) as $c) { if (isset($c['params'][0]['topics'][2])) { $out[] = $c; } }
	return $out;
}
// The horizon an answer certifies when its node is current.
function et_horizon() {
	return $GLOBALS['et']['now'] - $GLOBALS['et']['tip_age'] - NMMPRO_Evm_Transfers::MAX_TIP_AHEAD_SEC - NMMPRO_Evm_Transfers::HORIZON_MARGIN_SEC;
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
		if ($s['replaced'] === $block && et_headers_of($block) > 1) { $variant = '-other-branch'; }
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
		if ($s['fail_proof'] && !isset($p[0]['topics'][2])) {
			return $json(array('jsonrpc' => '2.0', 'id' => 1, 'error' => array('code' => -32000, 'message' => 'proof refused')));
		}
		$nth = count(et_addr_logs($host)) - 1;
		if (isset($s['fail_chunk'][$host]) && $s['fail_chunk'][$host] === $nth) {
			return $json(array('jsonrpc' => '2.0', 'id' => 1, 'error' => array('code' => -32000, 'message' => 'range too large')));
		}
		$from = hexdec(substr($p[0]['fromBlock'], 2));
		$to = hexdec(substr($p[0]['toBlock'], 2));
		if (count(et_calls('eth_getLogs')) <= $s['lag_calls']) { $to = min($to, $s['tip'] - $s['lag']); }
		// The address's own transfers are served whatever the filter says (so
		// the parser's checks can be reached); other people's transfers only
		// to a query that does not filter on the recipient.
		$all = $s['logs'];
		if (!isset($p[0]['topics'][2])) {
			foreach ($s['busy'] as $busyBlock) { $all[] = et_log($busyBlock, '01', 900, $s['busy_extra'], ET_OTHER); }
		}
		$out = array();
		foreach ($all as $log) {
			$outOfRangeOk = $s['busy_anywhere'] && $log['to'] === ET_OTHER;
			if (!$outOfRangeOk && ($log['block'] < $from || $log['block'] > $to)) { continue; }
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
// Base ships with one node (mainnet.base.org refuses eth_getLogs from
// November 2026). The chunking and node-to-node failover checks below need a
// small-span node followed by a second one, so they get both through the
// nmmpro_evm_rpc_nodes filter, exactly as a merchant would add a node.
ok('Base ships with base-rpc.publicnode.com as its only node', NMMPRO_Evm_Transfers::nodes(8453) === array(array('https://base-rpc.publicnode.com', 5000)));
$baseA = 'base-a.example';
$baseB = 'base-b.example';
$GLOBALS['et_filters']['nmmpro_evm_rpc_nodes'] = function ($list, $chainId) use ($baseA, $baseB) {
	return $chainId === 8453 ? array(array('https://' . $baseA, 2000), array('https://' . $baseB, 5000)) : $list;
};
ok('  the filter can replace the list for one chain', NMMPRO_Evm_Transfers::nodes(8453)[0][0] === 'https://' . $baseA && NMMPRO_Evm_Transfers::nodes(137) === array(array('https://polygon-bor-rpc.publicnode.com', 10000)));

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
// The newer transfer is also the token's latest near the tip, so it serves as the index proof.
ok('  the page is reported: 2 rows, oldest time, certifiable up to the horizon', $meta === array(2, et_block_time(999000), et_horizon()));

et_reset(array('logs' => array(et_log(999990, '0f4240', 1), et_log(999990, '0f4240', 2))));
$r = et_fetch();
ok('two transfers in one block: both rows, its header read once, and once again at the end', count($r['transactions']) === 2 && et_headers_of(999990) === 2);

et_reset();
$r = et_fetch();
ok('no transfers in range: an empty success, page not certifiable', $r['result'] === 'success' && $r['transactions'] === array() && NMMPRO_Blockchain::take_raw_page_meta() === array(0, null, false));

// --- 3. certifies for expiry only up to the horizon ----------------------------------------
echo "--- 3. certifies only up to the horizon ---\n";
// Only the queries that do NOT filter on the recipient: the index proof.
function et_proofs() {
	$out = array();
	foreach (et_calls('eth_getLogs') as $c) { if (!isset($c['params'][0]['topics'][2])) { $out[] = $c; } }
	return $out;
}
function et_visit($r) {
	return array('transactions' => isset($r['transactions']) ? $r['transactions'] : array(), 'page' => NMMPRO_Blockchain::take_raw_page_meta());
}
$tipTime = function () { return $GLOBALS['et']['now'] - $GLOBALS['et']['tip_age']; };

et_reset(array('busy' => array(999999)));
$visit = et_visit(et_fetch());
$horizon = et_horizon();
ok('a busy token near the tip: the answer certifies', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === false);
ok('  but only up to the tip time minus the margin', NMMPRO_Payment::page_certifies_until($visit) === $horizon, 'until=' . var_export(NMMPRO_Payment::page_certifies_until($visit), true) . ' want=' . $horizon);
ok('  the margin is ten minutes', NMMPRO_Evm_Transfers::HORIZON_MARGIN_SEC >= 600);
ok('  the proof asks for the token\'s Transfers to anyone, ending at the tip', count(et_proofs()) === 1 && et_proofs()[0]['params'][0]['address'] === ET_POL
	&& et_proofs()[0]['params'][0]['topics'] === array(ET_TOPIC) && et_proofs()[0]['params'][0]['toBlock'] === '0x' . dechex(1000000));
ok('  and the block it relies on is checked by header', et_headers_of(999999) >= 1);

et_reset(array('logs' => array(et_log(999990)), 'busy' => array(999999)));
$visit = et_visit(et_fetch());
ok('with a payment in the answer: certifies up to the horizon too', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === false && NMMPRO_Payment::page_certifies_until($visit) === et_horizon());

et_reset();
$visit = et_visit(et_fetch());
ok('no transfer of the token at all near the tip: possibly incomplete', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === true && NMMPRO_Payment::page_certifies_until($visit) === null);
ok('  (a lagging log index looks exactly like this)', count(et_proofs()) === count(NMMPRO_Evm_Transfers::INDEX_PROOF_SEC));

// Polygon is sized at 1.5 s a block: block 999975 is outside the 6 s window (4 blocks), inside the 60 s one (40).
et_reset(array('busy' => array(999975)));
$visit = et_visit(et_fetch());
ok('a quieter token: the longer window is tried and certifies', NMMPRO_Payment::page_certifies_until($visit) === et_horizon() && count(et_proofs()) === 2);
et_reset(array('busy' => array(999999)));
et_fetch(); NMMPRO_Blockchain::take_raw_page_meta();
ok('  but only when the short window is empty', count(et_proofs()) === 1);
et_reset(array('busy' => array(999999 - 45))); // Polygon is sized at 1.5 s a block: 60 s is 40 blocks
$visit = et_visit(et_fetch());
ok('  a transfer older than the longest window proves nothing', NMMPRO_Payment::page_certifies_until($visit) === null);

et_reset(array('busy' => array(999999), 'header_shift' => array(999999 => -NMMPRO_Evm_Transfers::HORIZON_MARGIN_SEC - 600)));
$visit = et_visit(et_fetch());
ok('the proof block\'s header is older than the horizon: no certification', NMMPRO_Payment::page_certifies_until($visit) === null && NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === true);

et_reset(array('busy' => array(999999), 'fail_header' => 999999));
$r = et_fetch();
$visit = et_visit($r);
ok('the proof block\'s header cannot be read: matched, not certified', $r['result'] === 'success' && NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === true);

et_reset(array('logs' => array(et_log(999999, '0f4240', 0, array('blockHash' => et_block_hash(999999, '-orphan'))), et_log(999990))));
$r = et_fetch();
ok('a proof log naming another block hash: the fetch fails (it is also the address\'s own)', $r['result'] === 'error');
NMMPRO_Blockchain::take_raw_page_meta();

// A proof log that is not this token's Transfer is skipped, not trusted.
$GLOBALS['et_proof_extra'] = array(
	'another contract'      => array('address' => ET_OTHER),
	'another event'         => array('topics' => array('0x' . str_repeat('1', 64), et_pad(ET_OTHER), et_pad(ET_OTHER))),
	'reorged out (removed)' => array('removed' => true),
	'another block hash'    => array('blockHash' => et_block_hash(999999, '-orphan')),
);
foreach ($GLOBALS['et_proof_extra'] as $label => $extra) {
	et_reset(array('busy' => array(999999), 'busy_extra' => $extra));
	$visit = et_visit(et_fetch());
	ok('a proof log from ' . $label . ': no certification', NMMPRO_Payment::page_certifies_until($visit) === null);
}

// The proof comes before the address is read: an index that catches up
// mid-fetch must not certify the range it served while it was behind.
et_reset(array('busy' => array(999999), 'logs' => array(et_log(999500))));
et_fetch();
$order = array();
foreach (et_calls('eth_getLogs') as $c) { $order[] = isset($c['params'][0]['topics'][2]) ? 'address' : 'proof'; }
ok('the index proof is asked for before the address\'s own ranges', $order !== array() && $order[0] === 'proof' && in_array('address', $order, true), implode(',', $order));
et_reset(array('busy' => array(999999), 'logs' => array(et_log(999500)), 'lag_calls' => 1));
$r = et_fetch();
$visit = et_visit($r);
ok('an index 1000 blocks behind for the first query only: never a certified answer that misses the payment',
	$r['result'] === 'success' && (count($r['transactions']) === 1 || NMMPRO_Payment::page_certifies_until($visit) === null));
et_reset(array('busy' => array(999999), 'logs' => array(et_log(999500)), 'lag_calls' => 2));
$r = et_fetch();
$visit = et_visit($r);
ok('  behind for both proof windows: not certified', $r['result'] === 'success' && NMMPRO_Payment::page_certifies_until($visit) === null);

// A node that answers the proof with logs outside the window it was asked
// for, or with a block dated after the tip, proves nothing - even when that
// block's time would otherwise sit between the horizon and the tip.
et_reset(array('busy' => array(999800), 'busy_anywhere' => true));
$visit = et_visit(et_fetch());
ok('a proof log below the window asked for: not certified', NMMPRO_Payment::page_certifies_until($visit) === null);
et_reset(array('busy' => array(1000005), 'busy_anywhere' => true, 'header_shift' => array(1000005 => -20)));
$visit = et_visit(et_fetch());
ok('a proof log above the tip: not certified', NMMPRO_Payment::page_certifies_until($visit) === null);
et_reset(array('busy' => array(999999), 'header_shift' => array(999999 => 100)));
$visit = et_visit(et_fetch());
ok('a proof block dated after the tip: not certified', NMMPRO_Payment::page_certifies_until($visit) === null);
et_reset(array('busy' => array(999995), 'busy_anywhere' => true));
$visit = et_visit(et_fetch());
ok('  control: the same node with its proof log inside the window certifies', NMMPRO_Payment::page_certifies_until($visit) === et_horizon());

et_reset(array('busy' => array(999999), 'fail_proof' => true));
$r = et_fetch();
$visit = et_visit($r);
ok('the proof query itself fails: matched, not certified', $r['result'] === 'success' && NMMPRO_Payment::page_certifies_until($visit) === null && NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === true);

et_reset(array('blockscout' => et_tokentx(array(et_row('0x' . str_repeat('c', 64))))));
$visit = et_visit(et_fetch());
ok('control: a Blockscout page certifies with no horizon', NMMPRO_Payment::page_possibly_truncated('USDTPOL', $visit, $LOOKBACK, time()) === false && NMMPRO_Payment::page_certifies_until($visit) === null);

// --- 4. all or nothing -----------------------------------------------------------------
echo "--- 4. all or nothing ---\n";
// 3h + 30 min margin at 2 s/block is ~7,560 blocks: several chunks on Base's 2,000-block node.
et_reset(array('contract' => ET_BASE, 'logs' => array(et_log(999990)), 'fail_chunk' => array($baseA => 1, $baseB => 0)));
$r = et_fetch('USDCBAS');
ok('a chunk fails on every node: the fetch is an error', $r['result'] === 'error' && count(et_addr_logs($baseA)) === 2);
ok('  although the newest chunk held a payment (no partial success)', NMMPRO_Blockchain::take_raw_page_meta() === null);

et_reset(array('contract' => ET_BASE, 'logs' => array(et_log(999990)), 'fail_chunk' => array($baseA => 1)));
$r = et_fetch('USDCBAS');
ok('a chunk fails on the first node: the second node answers in full', $r['result'] === 'success' && $r['source'] === $baseB && count($r['transactions']) === 1);

et_reset(array('logs' => array(et_log(999990)), 'fail_method' => array($polygonNode => 'eth_getBlockByNumber')));
ok('headers unreadable: an error', et_fetch()['result'] === 'error');

et_reset(array('logs' => array(et_log(999990)), 'fail_header' => 999990));
ok('the header of a transfer\'s block unreadable, after usable logs: an error', et_fetch()['result'] === 'error' && count(et_addr_logs()) >= 1);

// --- 5. proven coverage ------------------------------------------------------------------
echo "--- 5. proven coverage ---\n";
$cutoff = function () use ($LOOKBACK) { return $GLOBALS['et']['now'] - $LOOKBACK - NMMPRO_Evm_Transfers::MARGIN_SEC; };
$firstFrom = function () { $c = et_addr_logs(); return hexdec(substr($c[0]['params'][0]['fromBlock'], 2)); };

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
ok('lookback cannot be proven: an error, not a short answer', $r['result'] === 'error' && count(et_addr_logs()) === 0);

// 3.5 h at 0.002 s a block on a 10,000-block node is over 600 chunks.
et_reset(array('tip' => 50000000, 'block_sec' => 0.002, 'logs' => array(et_log(49999990))));
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
et_reset(array('tip_age' => -NMMPRO_Evm_Transfers::MAX_TIP_AHEAD_SEC - 30, 'busy' => array(999999), 'logs' => array(et_log(999990))));
$r = et_fetch();
ok('a tip dated further ahead of this server\'s clock than allowed is refused', $r['result'] === 'error' && count(et_addr_logs()) === 0);
et_reset(array('tip_age' => -NMMPRO_Evm_Transfers::MAX_TIP_AHEAD_SEC + 10, 'busy' => array(999999), 'logs' => array(et_log(999990))));
$r = et_fetch();
$until = NMMPRO_Payment::page_certifies_until(array('transactions' => array(), 'page' => NMMPRO_Blockchain::take_raw_page_meta()));
ok('  control: a tip just inside the allowance is accepted', $r['result'] === 'success');
ok('  and its horizon stays a full margin behind this server\'s clock', is_int($until) && $until <= $GLOBALS['et']['now'] - NMMPRO_Evm_Transfers::HORIZON_MARGIN_SEC, 'until=' . var_export($until, true));
ok('  the allowance is about a minute', NMMPRO_Evm_Transfers::MAX_TIP_AHEAD_SEC > 0 && NMMPRO_Evm_Transfers::MAX_TIP_AHEAD_SEC <= 120);

// --- 7. one chain -------------------------------------------------------------------------
echo "--- 7. one chain ---\n";
et_reset(array('contract' => ET_BASE));
et_fetch('USDCBAS');
$chunks = array();
foreach (et_calls('eth_getLogs', $baseA) as $c) { if (isset($c['params'][0]['topics'][2])) { $chunks[] = $c; } } // not the index proof
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
// Another branch and back: the tip is the same at both ends, but the block a
// transfer came from is no longer the one served at that height.
et_reset(array('logs' => array(et_log(999000)), 'busy' => array(999999), 'replaced' => 999000));
$r = et_fetch();
ok('a transfer\'s block replaced although the tip is unchanged: an error', $r['result'] === 'error' && et_headers_of(999000) === 2 && et_headers_of(1000000) >= 2);
ok('  so nothing from it is reported', NMMPRO_Blockchain::take_raw_page_meta() === null);
et_reset(array('logs' => array(et_log(999000)), 'busy' => array(999999)));
ok('  control: the same block read twice unchanged succeeds', et_fetch()['result'] === 'success' && et_headers_of(999000) === 2);

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

// "$" alone also matches before a trailing newline; a hash carrying one would
// be a second spelling of the same transaction in the ledger.
et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('transactionHash' => et_hash(999990000) . "\n")))));
ok('a transaction hash with a trailing newline: the fetch fails', et_fetch()['result'] === 'error');
// A value carrying one would be read by hex_to_decimal() as one more digit:
// 1,000,000 credited as 16,000,000.
et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('data' => '0x' . str_pad('0f4240', 64, '0', STR_PAD_LEFT) . "\n")))));
ok('a value with a trailing newline: the fetch fails', et_fetch()['result'] === 'error');
// Not a discriminating check: the log's block hash must also equal the
// header's, which a validated header hash never carries a newline to match.
et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('blockHash' => et_block_hash(999990) . "\n")))));
ok('a block hash with a trailing newline: the fetch fails', et_fetch()['result'] === 'error');
// Quantities go through hexdec(), which skips the newline, so only the
// pattern stands between such a block number and a credited transfer.
et_reset(array('logs' => array(et_log(999990, '0f4240', 0, array('blockNumber' => '0x' . dechex(999990) . "\n")))));
ok('a block number with a trailing newline: the fetch fails', et_fetch()['result'] === 'error');
et_reset();
ok('an address with a trailing newline is refused before any request', NMMPRO_Evm_Transfers::fetch(137, ET_POL, ET_ADDR . "\n", $LOOKBACK)['result'] === 'error' && count($GLOBALS['et']['calls']) === 0);

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
ok('150 transfers in three blocks: an error, never the newest 100 alone', $r['result'] === 'error' && $meta === null);
$burst = array();
for ($i = 0; $i < NMMPRO_Evm_Transfers::MAX_ROWS; $i++) { $burst[] = et_log(999990 - intdiv($i, 50), '0f4240', $i); }
et_reset(array('logs' => $burst));
$r = et_fetch();
$meta = NMMPRO_Blockchain::take_raw_page_meta();
ok('  control: exactly MAX_ROWS transfers are all returned', $r['result'] === 'success' && count($r['transactions']) === NMMPRO_Evm_Transfers::MAX_ROWS);
ok('  reported as a full page, which does not certify', is_array($meta) && $meta[0] === NMMPRO_Evm_Transfers::MAX_ROWS && NMMPRO_Blockchain::adapter_page_cap('USDTPOL') === NMMPRO_Evm_Transfers::MAX_ROWS
	&& NMMPRO_Payment::page_possibly_truncated('USDTPOL', array('transactions' => $r['transactions'], 'page' => $meta), $LOOKBACK, time()) === true);

// --- 12. bounded work ------------------------------------------------------------------------------
echo "--- 12. bounded work ---\n";
$polygonCalls = function () use ($polygonNode) { $n = 0; foreach ($GLOBALS['et']['calls'] as $c) { if ($c['host'] === $polygonNode) { $n++; } } return $n; };
// The row cap, not the call budget, limits a busy address: MAX_ROWS
// transfers, each in its own block (two header reads apiece), still fit.
$spread = array();
for ($i = 0; $i < NMMPRO_Evm_Transfers::MAX_ROWS; $i++) { $spread[] = et_log(999990 - $i * 3); }
et_reset(array('logs' => $spread, 'busy' => array(999999)));
$r = et_fetch();
ok('MAX_ROWS transfers in as many blocks: all returned within the budget', $r['result'] === 'success' && count($r['transactions']) === NMMPRO_Evm_Transfers::MAX_ROWS, 'calls=' . $polygonCalls());
// A lookback of days on a small-span node fits too: five days on Base.
et_reset(array('contract' => ET_BASE, 'logs' => array(et_log(999990)), 'busy' => array(999999)));
$GLOBALS['et_filters']['nmmpro_evm_rpc_nodes_saved'] = $GLOBALS['et_filters']['nmmpro_evm_rpc_nodes'];
unset($GLOBALS['et_filters']['nmmpro_evm_rpc_nodes']);
$r = et_fetch('USDCBAS', 5 * 86400);
$GLOBALS['et_filters']['nmmpro_evm_rpc_nodes'] = $GLOBALS['et_filters']['nmmpro_evm_rpc_nodes_saved'];
ok('a five-day lookback on Base\'s own node fits within the budget', $r['result'] === 'success' && count($r['transactions']) === 1);
// Exhausting the budget is an error, and stops at the budget.
et_reset(array('tip' => 50000000, 'block_sec' => 0.002, 'logs' => array(et_log(49999990))));
$r = et_fetch();
ok('a fetch that runs out of calls is an error', $r['result'] === 'error');
ok('  and the node was asked no more than the budget', $polygonCalls() <= NMMPRO_Evm_Transfers::MAX_REQUESTS, 'calls=' . $polygonCalls());
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
