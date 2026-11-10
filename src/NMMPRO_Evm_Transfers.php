<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incoming ERC-20 transfers read straight from public JSON-RPC nodes, as the
 * last fallback when the explorer APIs cannot answer (see
 * NMMPRO_Blockchain::get_erc20_address_transactions()).
 *
 * An explorer hands back "the newest transfers of this address". A node can
 * only be asked "the Transfer logs of this contract to this address between
 * block A and block B", so this class has to establish what an explorer's
 * answer takes for granted:
 *
 *  - All or nothing. Any chunk, header or field that cannot be read fails the
 *    whole fetch, and a reply of the wrong JSON type (an object where a list
 *    of logs belongs) is unreadable, not empty.
 *  - Proven coverage. The starting block's own timestamp must be at or before
 *    the start of the lookback; assumed block times only size the first guess.
 *  - A live tip. A node whose newest block is more than a few minutes old is
 *    lagging, and one whose newest block is dated more than a minute ahead
 *    of this server's clock is refused too.
 *  - One chain. Blocks are requested by number, so every block a transfer is
 *    taken from is checked by hash: its header must carry the block hash the
 *    log names, the tip read at the start must still be the block at that
 *    height at the end, and each of those blocks is read again after that
 *    and must not have changed. An unchanged tip alone rules out an ordinary
 *    reorganisation; the second read also rules out a node that switched to
 *    another branch and back while it was read.
 *  - Complete or nothing. More transfers than MAX_ROWS in the lookback fail
 *    the fetch; the oldest are never silently dropped.
 *  - Strict parsing. Only the token contract's own logs, the standard
 *    three-topic Transfer layout with a 32-byte value, addressed to the
 *    queried address, inside the block range asked. A log flagged 'removed'
 *    (reorged out) is ignored. Transaction hashes must be lower-case hex, the
 *    form Blockscout serves; the consumed-transaction ledger also treats
 *    0x-prefixed 32-byte hashes case-insensitively (NMMPRO_Consumed_Repo), so
 *    a hash recorded in another case is still recognised.
 *  - Times from headers. A transfer's time is its verified block header's,
 *    never the log's own blockTimestamp (Arbitrum's public node sends 0x0
 *    there, and any node could send a plausible wrong value), and it must lie
 *    between the starting block's and the tip's.
 *
 * An answer from here always lets payments be matched. It certifies the
 * address as covered for expiry only up to a HORIZON, never up to this
 * server's clock: the tip's own time minus MAX_TIP_AHEAD_SEC and
 * HORIZON_MARGIN_SEC, and only when the same node has shown, BEFORE the
 * address is read, that its logs are indexed that far (see
 * indexed_horizon()). The horizon travels with the page report
 * (NMMPRO_Blockchain::note_raw_page()), and the sweep never stamps the coin
 * as covered later than the earliest horizon it relied on, so a node that
 * lags only delays expiry. Without that proof the page is reported as not
 * certifiable, and NMMPRO_Payment::page_possibly_truncated() keeps the
 * address out of the coverage stamp until a later visit can certify it.
 *
 * What this cannot rule out: a node operator answering every request
 * coherently but falsely, or a load balancer whose backends disagree so
 * that the address's log ranges come from a backend whose log index is more
 * than HORIZON_MARGIN_SEC behind while the headers and the index check come
 * from current ones. Blockscout carries the same kind of trust in its own
 * indexer, without a horizon.
 */
class NMMPRO_Evm_Transfers {

	// keccak256("Transfer(address,address,uint256)")
	const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

	// Rows one answer may hold - the same page size the explorer adapters use
	// (NMMPRO_Blockchain::adapter_page_cap()). An answer is complete or it
	// is an error: more incoming transfers than this inside the lookback
	// fail the fetch rather than drop the oldest of them.
	const MAX_ROWS = 100;

	// JSON-RPC calls one fetch may make against one node: the tip, its
	// header (read again at the end), up to two index-proof queries and a
	// header, up to COVERAGE_TRIES start headers, the log chunks, and two
	// reads of the header of each block that holds a transfer.
	const MAX_REQUESTS = 50;

	// Read this much further back than asked, so clock differences between
	// this server and the chain cannot leave a gap at the old end.
	const MARGIN_SEC = 1800;

	// A tip older than this means the node is not following the chain.
	const MAX_TIP_AGE_SEC = 300;

	// How far a tip's own time may run ahead of this server's clock. A tip
	// further ahead is refused; up to this much is subtracted from the
	// horizon, so a chain clock that runs ahead cannot stretch it.
	const MAX_TIP_AHEAD_SEC = 60;

	// Wall-clock time one fetch from one node may take, on top of the call
	// budget: a slow node must not hold a cron pass for minutes.
	const MAX_FETCH_SEC = 60;

	// How often the starting block may be moved further back to reach the
	// lookback before the fetch gives up.
	const COVERAGE_TRIES = 4;

	// How far behind the tip's own time an answer certifies the address for
	// expiry. A node whose log index trails its headers by less than this
	// cannot make expiry miss a transfer; trailing by more is what
	// indexed_horizon() looks for.
	const HORIZON_MARGIN_SEC = 600;

	// The windows, ending at the tip, in which the token's own Transfer logs
	// (to anyone) must show up to prove the node's log index is current. The
	// short one holds a few dozen logs for a busy stablecoin; the long one is
	// tried only when it is empty, for quieter tokens.
	const INDEX_PROOF_SEC = array(6, 60);

	// Seconds per block, used ONLY to size the first guess at the starting
	// block. The guess is then checked against that block's real timestamp.
	private static $blockSeconds = array(
		1     => 12,
		137   => 1.5,
		42161 => 0.25,
		8453  => 2,
	);

	/**
	 * Public nodes per chain id, tried in order: array(url, max blocks one
	 * eth_getLogs call may span). The spans are ones each operator accepted
	 * when the list was reviewed (30 September 2026); a node that tightens
	 * its limit simply fails, and the next is tried. Several public nodes
	 * also refuse ranges that reach far into the past ("archive" requests),
	 * which is why the starting block is chosen to overshoot the lookback as
	 * little as possible.
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	public static function nodes($chainId) {
		$nodes = array(
			1 => array(
				array('https://ethereum-rpc.publicnode.com', 5000),
			),
			137 => array(
				array('https://polygon-bor-rpc.publicnode.com', 10000),
			),
			42161 => array(
				array('https://arb1.arbitrum.io/rpc', 50000),
			),
			// mainnet.base.org was dropped in November 2026: it answers
			// eth_getLogs only with "request limit reached" (429), even for
			// a single spaced request.
			8453 => array(
				array('https://base-rpc.publicnode.com', 5000),
			),
		);

		$list = isset($nodes[(int) $chainId]) ? $nodes[(int) $chainId] : array();

		/**
		 * The JSON-RPC nodes used as the last fallback for ERC-20 transfers.
		 *
		 * @param array $list    array(url, max block span per eth_getLogs call) entries, in order.
		 * @param int   $chainId EVM chain id (1, 137, 42161, 8453).
		 */
		$list = NMMPRO_Compat::filter('nmmpro_evm_rpc_nodes', $list, (int) $chainId);

		$clean = array();
		foreach ((array) $list as $node) {
			if (is_array($node) && isset($node[0], $node[1]) && is_string($node[0]) && (int) $node[1] > 0) {
				$clean[] = array($node[0], (int) $node[1]);
			}
		}

		return $clean;
	}

	/**
	 * Incoming transfers of token $contract to $address over the last
	 * $lookbackSec seconds, from the first node that can answer completely.
	 *
	 * @return array{result: string, transactions?: array<int, NMMPRO_Transaction>, source?: string, message?: string}
	 */
	public static function fetch($chainId, $contract, $address, $lookbackSec) {
		$contract = strtolower((string) $contract);
		$address = strtolower((string) $address);

		if (!self::is_address($contract) || !self::is_address($address)) {
			return array('result' => 'error', 'message' => 'Not an EVM address');
		}

		$lookbackSec = max(0, (int) $lookbackSec) + self::MARGIN_SEC;
		$message = 'No JSON-RPC node is configured for chain ' . (int) $chainId;

		foreach (self::nodes($chainId) as $node) {
			try {
				$result = self::fetch_from($node[0], $node[1], (int) $chainId, $contract, $address, $lookbackSec);
			}
			catch (\Throwable $e) {
				// A malformed answer must move on to the next node, not
				// abort the fetch with the others untried.
				$result = array('result' => 'error', 'message' => $e->getMessage());
			}

			if ($result['result'] === 'success') {
				return $result;
			}

			$message = (string) wp_parse_url($node[0], PHP_URL_HOST) . ': ' . (isset($result['message']) ? $result['message'] : 'failed');
			NMMPRO_Util::log(__FILE__, __LINE__, 'JSON-RPC transfer fetch failed (' . $message . ')', 'warning');
		}

		return array('result' => 'error', 'message' => $message);
	}

	private static function fetch_from($url, $maxSpan, $chainId, $contract, $address, $lookbackSec) {
		$now = time();
		$budget = array('calls' => 0, 'until' => $now + self::MAX_FETCH_SEC);

		// ---- the tip, and proof that the node is following the chain ----
		$tipHex = self::call($url, 'eth_blockNumber', array(), $budget, 'scalar');
		if (!self::is_quantity($tipHex)) {
			return self::error('no tip');
		}
		$tip = self::to_int($tipHex);

		$tipHeader = self::block_header($url, $tip, $budget);
		if ($tipHeader === null) {
			return self::error('no tip header');
		}
		$tipTime = $tipHeader['time'];
		if ($tipTime < $now - self::MAX_TIP_AGE_SEC) {
			return self::error('node is behind the chain (tip is ' . ($now - $tipTime) . 's old)');
		}
		if ($tipTime > $now + self::MAX_TIP_AHEAD_SEC) {
			return self::error('the tip is ' . ($tipTime - $now) . 's ahead of this server\'s clock');
		}

		// ---- the node's log index reaches the horizon ----
		// Proven BEFORE the address is read: an index that catches up while
		// the address's ranges are read must not certify what it served
		// while it was behind.
		$headers = array();
		$horizon = self::indexed_horizon($url, $chainId, $contract, $tip, $tipTime, $headers, $budget);

		// ---- a starting block whose own time reaches the lookback ----
		// The first guess comes from the chain's usual block time. If that
		// block turns out to be too recent, the next guess uses the rate
		// actually measured between it and the tip, so the range overshoots
		// the lookback only slightly.
		$cutoff = $now - $lookbackSec;
		$perBlock = isset(self::$blockSeconds[$chainId]) ? self::$blockSeconds[$chainId] : 12;
		$span = (int) ceil(($tipTime - $cutoff) / $perBlock * 1.05) + 1;
		$from = null;
		$fromTime = 0;

		for ($try = 0; $try < self::COVERAGE_TRIES; $try++) {
			$candidate = max(0, $tip - $span);
			$header = self::block_header($url, $candidate, $budget);
			if ($header === null) {
				return self::error('no header for the starting block');
			}
			$time = $header['time'];
			if ($time <= $cutoff || $candidate === 0) {
				$from = $candidate;
				$fromTime = $time;
				break;
			}

			$measured = ($tipTime - $time) / max(1, $tip - $candidate);
			$span = $measured > 0
				? max($span + 1, (int) ceil(($tipTime - $cutoff) / $measured * 1.1) + 1)
				: $span * 2;
		}
		if ($from === null) {
			return self::error('could not reach back ' . $lookbackSec . 's');
		}

		// ---- every Transfer to the address in [from, tip], in chunks ----
		$logs = array();
		$topics = array(self::TRANSFER_TOPIC, null, '0x' . str_repeat('0', 24) . substr($address, 2));

		for ($start = $from; $start <= $tip; $start += $maxSpan) {
			$end = min($tip, $start + $maxSpan - 1);

			$chunk = self::call($url, 'eth_getLogs', array(array(
				'address'   => $contract,
				'fromBlock' => self::to_hex($start),
				'toBlock'   => self::to_hex($end),
				'topics'    => $topics,
			)), $budget, 'list');

			if (!is_array($chunk)) {
				return self::error('logs ' . $start . '-' . $end . ' unreadable');
			}

			foreach ($chunk as $log) {
				$row = self::parse_log($log, $contract, $topics[2], $start, $end);
				if ($row === false) {
					return self::error('malformed log in ' . $start . '-' . $end);
				}
				if ($row !== null) {
					$logs[] = $row;
				}
			}
		}

		// Newest first, like the explorer pages.
		usort($logs, function ($a, $b) {
			if ($a['block'] !== $b['block']) {
				return $a['block'] < $b['block'] ? 1 : -1;
			}
			return $a['index'] === $b['index'] ? 0 : ($a['index'] < $b['index'] ? 1 : -1);
		});
		if (count($logs) > self::MAX_ROWS) {
			return self::error(count($logs) . ' transfers in the lookback, more than one answer may hold');
		}

		// ---- each transfer's block, verified by hash ----
		$transactions = array();
		$oldest = null;

		foreach ($logs as $row) {
			$block = $row['block'];
			if (!isset($headers[$block])) {
				$header = self::block_header($url, $block, $budget);
				if ($header === null) {
					return self::error('no header for block ' . $block);
				}
				$headers[$block] = $header;
			}
			// A block inside [from, tip] cannot be older than the start block
			// or newer than the tip; one that is means the node's answers do
			// not describe one chain. Checked for every row, including a
			// header the index proof already read.
			if ($headers[$block]['time'] < $fromTime || $headers[$block]['time'] > $tipTime) {
				return self::error('block ' . $block . ' has a time outside the range read');
			}

			if ($row['block_hash'] !== $headers[$block]['hash']) {
				return self::error('a transfer in block ' . $block . ' names a different block hash than its header');
			}

			$time = $headers[$block]['time'];
			if ($oldest === null || $time < $oldest) {
				$oldest = $time;
			}

			// Counted as Blockscout counts them (tip minus block), so the
			// required-confirmations setting means the same for both sources.
			$transactions[] = new NMMPRO_Transaction($row['value'], $tip - $block, $time, $row['hash']);
		}

		// ---- the tip is still the tip: nothing in the range was replaced ----
		$tipAgain = self::block_header($url, $tip, $budget);
		if ($tipAgain === null) {
			return self::error('no tip header at the end');
		}
		if ($tipAgain['hash'] !== $tipHeader['hash']) {
			return self::error('the chain changed while it was read');
		}

		// ---- every credited block is still on that chain ----
		// The same tip at both ends does not rule out a node that switched
		// to another branch and back in between; a transfer read from that
		// branch names a block the node no longer serves at that height.
		$rechecked = array();
		foreach ($logs as $row) {
			$block = $row['block'];
			if (isset($rechecked[$block])) {
				continue;
			}
			$again = self::block_header($url, $block, $budget);
			if ($again === null) {
				return self::error('no header for block ' . $block . ' at the end');
			}
			if ($again['hash'] !== $headers[$block]['hash']) {
				return self::error('block ' . $block . ' was replaced while it was read');
			}
			$rechecked[$block] = true;
		}

		// Expiry may rely on this answer only up to the horizon, and only
		// when the node proved its log index reaches it (false otherwise; see
		// the class comment).
		NMMPRO_Blockchain::note_raw_page(count($logs), $oldest, $horizon === null ? false : $horizon);

		return array(
			'result'       => 'success',
			'transactions' => $transactions,
			'source'       => (string) wp_parse_url($url, PHP_URL_HOST),
		);
	}

	/**
	 * One log as array(block, index, hash, block_hash, value); null for a
	 * log to skip (reorged out); false for anything that is not exactly a
	 * standard Transfer of this contract to this address inside the range.
	 *
	 * @return array{block: int, index: int, hash: string, block_hash: string, value: string}|null|false
	 */
	private static function parse_log($log, $contract, $paddedTo, $start, $end) {
		if (!is_array($log)) {
			return false;
		}
		if (!empty($log['removed'])) {
			return null;
		}

		$topics = isset($log['topics']) ? $log['topics'] : null;

		if (!isset($log['address'], $log['data'], $log['blockNumber'], $log['blockHash'], $log['transactionHash'], $log['logIndex'])
			|| !is_string($log['address']) || strtolower($log['address']) !== $contract
			|| !is_array($topics) || count($topics) !== 3
			|| !is_string($topics[0]) || strtolower($topics[0]) !== self::TRANSFER_TOPIC
			|| !is_string($topics[2]) || strtolower($topics[2]) !== $paddedTo
			|| !is_string($log['data']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['data'])
			|| !self::is_hash($log['transactionHash'])
			|| !is_string($log['blockHash']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['blockHash'])
			|| !self::is_quantity($log['blockNumber']) || !self::is_quantity($log['logIndex'])) {
			return false;
		}

		$block = self::to_int($log['blockNumber']);
		if ($block < $start || $block > $end) {
			return false; // outside what was asked for: the node is not answering the question
		}

		return array(
			'block'      => $block,
			'index'      => self::to_int($log['logIndex']),
			'hash'       => $log['transactionHash'],
			'block_hash' => strtolower($log['blockHash']),
			'value'      => self::hex_to_decimal(substr($log['data'], 2)),
		);
	}

	/**
	 * A block header as array(time, hash), or null when it cannot be read or
	 * is not the header of block $block.
	 *
	 * @return array{time: int, hash: string}|null
	 */
	private static function block_header($url, $block, &$budget) {
		$header = self::call($url, 'eth_getBlockByNumber', array(self::to_hex($block), false), $budget, 'object');

		if (!is_array($header) || !isset($header['timestamp'], $header['number'], $header['hash'])
			|| !self::is_quantity($header['timestamp']) || !self::is_quantity($header['number'])
			|| !is_string($header['hash']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $header['hash'])
			|| self::to_int($header['number']) !== (int) $block) {
			return null;
		}

		$time = self::to_int($header['timestamp']);

		return $time > 0 ? array('time' => $time, 'hash' => strtolower($header['hash'])) : null;
	}

	/**
	 * One JSON-RPC call whose result must be of JSON type $kind ('list',
	 * 'object' or 'scalar'); null on any failure, a result of another type,
	 * or an exhausted budget (calls or time).
	 */
	private static function call($url, $method, $params, &$budget, $kind) {
		if ($budget['calls'] >= self::MAX_REQUESTS || time() > $budget['until']) {
			return null;
		}
		$budget['calls']++;

		$answer = NMMPRO_Blockchain::evm_rpc($url, $method, $params);

		if ($answer['result'] !== 'success' || $answer['kind'] !== $kind) {
			return null;
		}

		return $answer['value'];
	}

	/**
	 * The time up to which this fetch may certify the address for expiry,
	 * or null when it may not.
	 *
	 * The address's own log query cannot show that the node's log index is
	 * current: "no transfers" looks the same as "not indexed yet". The token's
	 * Transfer logs to ANYONE can. A busy token has some in the last few
	 * blocks, so the newest of them, checked by hash against its header,
	 * shows the index reaches that block - near the tip, well past the
	 * horizon. No such log (a quiet token, a lagging index, an exhausted
	 * budget) withholds certification only; the transfers already read are
	 * still returned for matching.
	 */
	private static function indexed_horizon($url, $chainId, $contract, $tip, $tipTime, &$headers, &$budget) {
		$perBlock = isset(self::$blockSeconds[$chainId]) ? self::$blockSeconds[$chainId] : 12;
		// The tip's time may run up to MAX_TIP_AHEAD_SEC ahead of this
		// server's clock, which is the clock expiry uses.
		$horizon = $tipTime - self::MAX_TIP_AHEAD_SEC - self::HORIZON_MARGIN_SEC;

		foreach (self::INDEX_PROOF_SEC as $seconds) {
			$from = max(0, $tip - (int) ceil($seconds / $perBlock));

			$logs = self::call($url, 'eth_getLogs', array(array(
				'address'   => $contract,
				'fromBlock' => self::to_hex($from),
				'toBlock'   => self::to_hex($tip),
				'topics'    => array(self::TRANSFER_TOPIC),
			)), $budget, 'list');
			if (!is_array($logs)) {
				return null;
			}

			// The newest well-formed Transfer of this contract in the window.
			// Anything else is skipped: only a log that passes the header
			// check below proves anything.
			$newest = null;
			$newestHash = '';
			foreach ($logs as $log) {
				if (!is_array($log) || !empty($log['removed'])
					|| !isset($log['address'], $log['topics'], $log['blockNumber'], $log['blockHash'])
					|| !is_string($log['address']) || strtolower($log['address']) !== $contract
					|| !is_array($log['topics']) || !isset($log['topics'][0]) || !is_string($log['topics'][0])
					|| strtolower($log['topics'][0]) !== self::TRANSFER_TOPIC
					|| !self::is_quantity($log['blockNumber'])
					|| !is_string($log['blockHash']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['blockHash'])) {
					continue;
				}
				$block = self::to_int($log['blockNumber']);
				if ($block >= $from && $block <= $tip && ($newest === null || $block > $newest)) {
					$newest = $block;
					$newestHash = strtolower($log['blockHash']);
				}
			}
			if ($newest === null) {
				continue;
			}

			if (!isset($headers[$newest])) {
				$header = self::block_header($url, $newest, $budget);
				if ($header === null) {
					return null;
				}
				$headers[$newest] = $header;
			}

			// The log must belong to the block the header describes, and that
			// block must lie between the horizon and the tip.
			if ($headers[$newest]['hash'] !== $newestHash
				|| $headers[$newest]['time'] < $horizon || $headers[$newest]['time'] > $tipTime) {
				return null;
			}

			return $horizon;
		}

		return null;
	}

	private static function error($message) {
		return array('result' => 'error', 'message' => $message);
	}

	// A transaction hash in the form Blockscout serves: 0x and 64 lower-case
	// hex digits. The consumed-transaction ledger keys on the exact string.
	private static function is_hash($value) {
		return is_string($value) && preg_match('/^0x[0-9a-f]{64}$/', $value) === 1;
	}

	private static function is_address($value) {
		return is_string($value) && preg_match('/^0x[0-9a-f]{40}$/', $value) === 1;
	}

	// A JSON-RPC quantity small enough to be a PHP integer (block numbers,
	// timestamps, log indexes): 0x followed by 1-15 hex digits.
	private static function is_quantity($value) {
		return is_string($value) && preg_match('/^0x[0-9a-fA-F]{1,15}$/', $value) === 1;
	}

	private static function to_int($quantity) {
		return (int) hexdec(substr($quantity, 2));
	}

	private static function to_hex($int) {
		return '0x' . dechex((int) $int);
	}

	/**
	 * A hexadecimal uint256 (no 0x) as a decimal string, without needing an
	 * arbitrary-precision extension: token values exceed PHP's integers.
	 */
	public static function hex_to_decimal($hex) {
		$hex = (string) $hex;
		$digits = array(0);

		$length = strlen($hex);
		for ($i = 0; $i < $length; $i++) {
			$carry = (int) hexdec($hex[$i]);
			for ($j = 0, $n = count($digits); $j < $n; $j++) {
				$value = $digits[$j] * 16 + $carry;
				$digits[$j] = $value % 10;
				$carry = intdiv($value, 10);
			}
			while ($carry > 0) {
				$digits[] = $carry % 10;
				$carry = intdiv($carry, 10);
			}
		}

		return implode('', array_reverse($digits));
	}
}
