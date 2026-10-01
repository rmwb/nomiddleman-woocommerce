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
 * block A and block B", so this class has to prove what an explorer's answer
 * takes for granted: that the range it read really covers the period the
 * matcher and the expiry pass are about to reason over. Every rule below
 * exists so that an answer from here is never weaker than the explorer's:
 *
 *  - All or nothing. Any chunk, header or field that cannot be read fails the
 *    whole fetch. A partial view must never let expiry conclude that nothing
 *    arrived.
 *  - Proven coverage. The starting block's own timestamp must be at or before
 *    the start of the lookback; assumed block times only size the first guess.
 *  - A live tip. A node whose newest block is old is lagging, and would hide a
 *    recent payment; it is refused.
 *  - One snapshot. The range ends at the tip's block NUMBER, never 'latest',
 *    so every chunk describes the same chain state, and confirmations are
 *    counted from that tip.
 *  - Strict parsing. Only the token contract's own logs, the standard
 *    three-topic Transfer layout with a 32-byte value, addressed to the
 *    queried address. A log flagged 'removed' (reorged out) is ignored.
 *  - Times that fit. Every transfer's time must lie between the starting
 *    block's and the tip's. A log's own blockTimestamp is used only if it
 *    does (Arbitrum's public node sends 0x0); otherwise the header is read.
 *
 * Nothing here decides a payment: it returns the same NMMPRO_Transaction list
 * the explorer adapters do, and reports its page through
 * NMMPRO_Blockchain::note_raw_page() so the matcher's truncation check applies
 * unchanged.
 */
class NMMPRO_Evm_Transfers {

	// keccak256("Transfer(address,address,uint256)")
	const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

	// Rows returned at most - the same page size the explorer adapters use
	// (NMMPRO_Blockchain::adapter_page_cap()). More incoming transfers than
	// this inside the lookback are reported as a full page, which the
	// matcher's truncation check treats as "possibly more below".
	const MAX_ROWS = 100;

	// JSON-RPC calls one fetch may make against one node: tip, tip header,
	// up to COVERAGE_TRIES start headers, the log chunks, and one header per
	// block that holds a kept transfer when the node omits blockTimestamp.
	const MAX_REQUESTS = 40;

	// Read this much further back than asked, so clock differences between
	// this server and the chain cannot leave a gap at the old end.
	const MARGIN_SEC = 1800;

	// A tip older than this means the node is not following the chain.
	const MAX_TIP_AGE_SEC = 900;

	// How often the starting block may be moved further back to reach the
	// lookback before the fetch gives up.
	const COVERAGE_TRIES = 4;

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
			8453 => array(
				array('https://mainnet.base.org', 2000),
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
		$calls = 0;
		$now = time();

		// ---- the tip, and proof that the node is following the chain ----
		$tipHex = self::call($url, 'eth_blockNumber', array(), $calls);
		if (!self::is_quantity($tipHex)) {
			return self::error('no tip');
		}
		$tip = self::to_int($tipHex);

		$tipTime = self::block_time($url, $tip, $calls);
		if ($tipTime === null) {
			return self::error('no tip header');
		}
		if ($tipTime < $now - self::MAX_TIP_AGE_SEC) {
			return self::error('node is behind the chain (tip is ' . ($now - $tipTime) . 's old)');
		}

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
			$time = self::block_time($url, $candidate, $calls);
			if ($time === null) {
				return self::error('no header for the starting block');
			}
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
			)), $calls);

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
		$logs = array_slice($logs, 0, self::MAX_ROWS);

		// ---- block times for the kept rows ----
		$times = array();
		$transactions = array();
		$oldest = null;

		foreach ($logs as $row) {
			$block = $row['block'];
			if (!isset($times[$block])) {
				// A block inside [from, tip] cannot be older than the start
				// block or newer than the tip. A log's own blockTimestamp is
				// used only when it fits - some nodes send 0x0 there - and
				// otherwise the header is read; a header that does not fit
				// either means the node's answers do not describe one chain.
				$time = $row['time'];
				if ($time === null || $time < $fromTime || $time > $tipTime) {
					$time = self::block_time($url, $block, $calls);
					if ($time === null) {
						return self::error('no header for block ' . $block);
					}
					if ($time < $fromTime || $time > $tipTime) {
						return self::error('block ' . $block . ' has a time outside the range read');
					}
				}
				$times[$block] = $time;
			}

			$time = $times[$block];
			if ($oldest === null || $time < $oldest) {
				$oldest = $time;
			}

			$transactions[] = new NMMPRO_Transaction($row['value'], $tip - $block + 1, $time, $row['hash']);
		}

		NMMPRO_Blockchain::note_raw_page(count($logs), $oldest);

		return array(
			'result'       => 'success',
			'transactions' => $transactions,
			'source'       => (string) wp_parse_url($url, PHP_URL_HOST),
		);
	}

	/**
	 * One log as array(block, index, hash, value, time|null); null for a log
	 * to skip (reorged out); false for anything that is not exactly a
	 * standard Transfer of this contract to this address inside the range.
	 *
	 * @return array{block: int, index: int, hash: string, value: string, time: int|null}|null|false
	 */
	private static function parse_log($log, $contract, $paddedTo, $start, $end) {
		if (!is_array($log)) {
			return false;
		}
		if (!empty($log['removed'])) {
			return null;
		}

		$topics = isset($log['topics']) ? $log['topics'] : null;

		if (!isset($log['address'], $log['data'], $log['blockNumber'], $log['transactionHash'], $log['logIndex'])
			|| !is_string($log['address']) || strtolower($log['address']) !== $contract
			|| !is_array($topics) || count($topics) !== 3
			|| !is_string($topics[0]) || strtolower($topics[0]) !== self::TRANSFER_TOPIC
			|| !is_string($topics[2]) || strtolower($topics[2]) !== $paddedTo
			|| !is_string($log['data']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['data'])
			|| !is_string($log['transactionHash']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['transactionHash'])
			|| !self::is_quantity($log['blockNumber']) || !self::is_quantity($log['logIndex'])) {
			return false;
		}

		$block = self::to_int($log['blockNumber']);
		if ($block < $start || $block > $end) {
			return false; // outside what was asked for: the node is not answering the question
		}

		$time = null;
		if (isset($log['blockTimestamp'])) {
			if (!self::is_quantity($log['blockTimestamp'])) {
				return false;
			}
			$time = self::to_int($log['blockTimestamp']);
		}

		return array(
			'block' => $block,
			'index' => self::to_int($log['logIndex']),
			'hash'  => strtolower($log['transactionHash']),
			'value' => self::hex_to_decimal(substr($log['data'], 2)),
			'time'  => $time,
		);
	}

	/**
	 * A block's timestamp, or null when the header cannot be read.
	 */
	private static function block_time($url, $block, &$calls) {
		$header = self::call($url, 'eth_getBlockByNumber', array(self::to_hex($block), false), $calls);

		if (!is_array($header) || !isset($header['timestamp'], $header['number'])
			|| !self::is_quantity($header['timestamp']) || !self::is_quantity($header['number'])
			|| self::to_int($header['number']) !== (int) $block) {
			return null;
		}

		$time = self::to_int($header['timestamp']);

		return $time > 0 ? $time : null;
	}

	/**
	 * One JSON-RPC call; null on any failure, including the request budget.
	 */
	private static function call($url, $method, $params, &$calls) {
		if ($calls >= self::MAX_REQUESTS) {
			return null;
		}
		$calls++;

		$answer = NMMPRO_Blockchain::evm_rpc($url, $method, $params);

		return $answer['result'] === 'success' ? $answer['value'] : null;
	}

	private static function error($message) {
		return array('result' => 'error', 'message' => $message);
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
