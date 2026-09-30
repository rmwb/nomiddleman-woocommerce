<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy Mode (HD) chain evidence, normalised per coin.
 *
 * Two questions, each answered explicitly:
 *
 *  - address_activity(): has this address ever been used? CLEAN, USED or
 *    UNKNOWN. Used before an address is issued (NMMPRO_Hd_Allocator).
 *  - scan(): which incoming outputs has this address received since the
 *    order's assignment boundary, and is that list COMPLETE? Used by the
 *    verifier and the expiry pass.
 *
 * Anything that is not a complete, well-formed, successful answer - a
 * timeout, an HTTP or rate-limit error, malformed JSON, a missing or
 * impossible field, an answer about a different address, a page limit - is
 * UNKNOWN or incomplete, never zero and never proof of payment.
 *
 * Output identity is (transaction hash, output index). Evidence here is a
 * separate normalised type: the Autopay NMMPRO_Transaction objects and their
 * adapters are unchanged.
 */
class NMMPRO_Hd_Evidence {

	const CLEAN = 'clean';
	const USED = 'used';
	const UNKNOWN = 'unknown';

	const COMPLETE = 'complete';
	const INCOMPLETE = 'incomplete';

	// A block's timestamp is set by its miner and may trail real time (it only
	// has to exceed the median of the previous eleven blocks). A scan therefore
	// continues this far below the boundary before calling itself complete.
	const TIME_SLACK_SEC = 10800;

	// Block times are not monotonic, so an address bound at a known chain
	// height is scanned by height instead: down to this many blocks below the
	// height recorded when it was proven clean (covering a reorganisation).
	const HEIGHT_MARGIN = 100;

	// Earliest plausible block time (the Bitcoin genesis block), and how far
	// in the future a block time may be before it is treated as impossible.
	const EARLIEST_BLOCK_TIME = 1231006505;
	const FUTURE_TOLERANCE_SEC = 7200;

	const DEFAULT_MAX_PAGES = 5;
	const BLOCKCYPHER_PAGE = 50;
	const ESPLORA_PAGE = 25;          // documented /txs/chain page size
	const ESPLORA_MEMPOOL_MAX = 50;   // documented /txs/mempool cap (no paging)

	/**
	 * The capability matrix for every coin the registry marks HD-capable.
	 * Each supported coin lists its reviewed sources in order: a later one is
	 * used only when an earlier one cannot be reached, and one scan never
	 * mixes sources. A coin with no reviewed source of attributable per-output
	 * evidence cannot run Privacy Mode automatically.
	 * See docs/HD-PAYMENT-SAFETY-REPORT.md (Step D) for the review behind it.
	 */
	private static $adapters = array(
		'BTC'  => array(
			array('type' => 'esplora', 'base' => 'https://mempool.space/api', 'source' => 'mempool.space'),
			array('type' => 'esplora', 'base' => 'https://blockstream.info/api', 'source' => 'blockstream.info'),
		),
		'LTC'  => array(
			array('type' => 'esplora', 'base' => 'https://litecoinspace.org/api', 'source' => 'litecoinspace.org'),
			array('type' => 'blockcypher', 'chain' => 'ltc', 'source' => 'blockcypher'),
		),
		'DOGE' => array(
			array('type' => 'blockcypher', 'chain' => 'doge', 'source' => 'blockcypher'),
		),
		'DASH' => array(
			array('type' => 'blockcypher', 'chain' => 'dash', 'source' => 'blockcypher'),
		),
	);

	private static $unsupportedReasons = array(
		'QTUM' => 'The only source in use (qtum.info) was used for lifetime totals; no reviewed per-output, paginated evidence adapter exists.',
		'BTX'  => 'The only source (chainz getreceivedbyaddress) reports a lifetime total and cannot list transactions or confirmations.',
		'XMY'  => 'No working public explorer API remains for Myriad.',
	);

	/**
	 * One row per HD-capable coin in the registry.
	 *
	 * @return array<string, array{automatic: bool, source: string, reason: string}>
	 */
	public static function capability_matrix() {
		$matrix = array();
		foreach (NMMPRO_Cryptocurrencies::get() as $crypto) {
			if (!$crypto->has_hd()) {
				continue;
			}
			$id = $crypto->get_id();
			if (isset(self::$adapters[$id])) {
				$matrix[$id] = array('automatic' => true, 'source' => implode(', then ', array_column(self::$adapters[$id], 'source')), 'reason' => '');
			}
			else {
				$matrix[$id] = array(
					'automatic' => false,
					'source' => '',
					'reason' => isset(self::$unsupportedReasons[$id]) ? self::$unsupportedReasons[$id] : 'No reviewed evidence adapter.',
				);
			}
		}
		return $matrix;
	}

	/** Whether this coin has a reviewed evidence adapter. */
	public static function has_adapter($cryptoId) {
		return isset(self::$adapters[$cryptoId]);
	}

	/** Kept for callers of the Step C name. */
	public static function has_activity_check($cryptoId) {
		return self::has_adapter($cryptoId);
	}

	// =====================================================================
	// Clean-address check.
	// =====================================================================

	/**
	 * Has $address ever been used on chain? CLEAN only with validated evidence
	 * of no receipts, no transactions at all (a spent address with a zero
	 * balance is used), and nothing pending.
	 *
	 * @return array{state: string, reason: string, source: string}
	 */
	public static function address_activity($cryptoId, $address) {
		if (!self::has_adapter($cryptoId)) {
			return self::activity(self::UNKNOWN, 'no evidence adapter for ' . $cryptoId, '');
		}

		// The first definite answer wins. An unusable answer from one source
		// (unreachable, malformed, about another address) moves on to the
		// next; if none gives a definite answer, the result is UNKNOWN.
		$reasons = array();
		foreach (self::$adapters[$cryptoId] as $adapter) {
			$answer = self::activity_from($adapter, $address);
			if ($answer['state'] !== self::UNKNOWN) {
				return $answer;
			}
			$reasons[] = $adapter['source'] . ': ' . $answer['reason'];
		}
		return self::activity(self::UNKNOWN, implode('; ', $reasons), '');
	}

	private static function activity_from($adapter, $address) {
		if ($adapter['type'] === 'blockcypher') {
			$summary = NMMPRO_Blockchain::get_blockcypher_address_summary($adapter['chain'], $address);
			if ($summary['result'] !== 'success') {
				return self::activity(self::UNKNOWN, 'explorer request failed', $adapter['source']);
			}
			$answer = self::classify_blockcypher_summary($summary['body'], $address);
			$answer['source'] = $adapter['source'];
			return $answer;
		}

		$stats = NMMPRO_Blockchain::get_esplora($adapter['base'], '/address/' . rawurlencode($address));
		if ($stats['result'] !== 'success') {
			return self::activity(self::UNKNOWN, 'explorer request failed', $adapter['source']);
		}
		return self::classify_esplora_stats($stats['body'], $address, $adapter['source']);
	}

	/**
	 * Classify a decoded BlockCypher address summary. Per BlockCypher's
	 * documentation total_received and n_tx count CONFIRMED activity only;
	 * unconfirmed_n_tx, unconfirmed_balance and final_n_tx cover the mempool.
	 */
	public static function classify_blockcypher_summary($body, $address) {
		if (!is_array($body)) {
			return self::activity(self::UNKNOWN, 'malformed answer', 'blockcypher');
		}
		if (!isset($body['address']) || !is_string($body['address']) || $body['address'] !== $address) {
			return self::activity(self::UNKNOWN, 'answer is not about the requested address', 'blockcypher');
		}

		$fields = array('total_received', 'n_tx', 'unconfirmed_n_tx', 'final_n_tx', 'unconfirmed_balance');
		$values = array();
		foreach ($fields as $field) {
			$value = self::counter(isset($body[$field]) ? $body[$field] : null, $field === 'unconfirmed_balance');
			if ($value === null) {
				return self::activity(self::UNKNOWN, 'missing or malformed ' . $field, 'blockcypher');
			}
			$values[$field] = $value;
		}

		foreach ($values as $field => $value) {
			if ($value !== '0') {
				return self::activity(self::USED, $field . ' is ' . $value, 'blockcypher');
			}
		}

		return self::activity(self::CLEAN, 'no receipts, no transactions, nothing pending', 'blockcypher');
	}

	/**
	 * Classify a decoded Esplora /address answer: chain_stats (confirmed) and
	 * mempool_stats (unconfirmed), each with tx_count, funded_txo_count,
	 * funded_txo_sum, spent_txo_count and spent_txo_sum.
	 */
	public static function classify_esplora_stats($body, $address, $source = 'esplora') {
		if (!is_array($body)) {
			return self::activity(self::UNKNOWN, 'malformed answer', $source);
		}
		if (!isset($body['address']) || !is_string($body['address']) || $body['address'] !== $address) {
			return self::activity(self::UNKNOWN, 'answer is not about the requested address', $source);
		}

		foreach (array('chain_stats', 'mempool_stats') as $group) {
			if (!isset($body[$group]) || !is_array($body[$group])) {
				return self::activity(self::UNKNOWN, 'missing ' . $group, $source);
			}
			foreach (array('tx_count', 'funded_txo_count', 'funded_txo_sum', 'spent_txo_count', 'spent_txo_sum') as $field) {
				$value = self::counter(isset($body[$group][$field]) ? $body[$group][$field] : null, false);
				if ($value === null) {
					return self::activity(self::UNKNOWN, 'missing or malformed ' . $group . '.' . $field, $source);
				}
				if ($value !== '0') {
					return self::activity(self::USED, $group . '.' . $field . ' is ' . $value, $source);
				}
			}
		}

		return self::activity(self::CLEAN, 'no receipts, no transactions, nothing pending', $source);
	}

	/**
	 * The chain's current height, from the coin's reviewed sources in order.
	 * Recorded when an address is bound: every block mined after that has a
	 * greater height, whatever time its miner wrote into it.
	 *
	 * @return array{height: int|null, source: string, reason: string}
	 */
	public static function chain_tip($cryptoId) {
		if (!self::has_adapter($cryptoId)) {
			return array('height' => null, 'source' => '', 'reason' => 'no evidence adapter for ' . $cryptoId);
		}
		$reasons = array();
		foreach (self::$adapters[$cryptoId] as $adapter) {
			if ($adapter['type'] === 'blockcypher') {
				$raw = NMMPRO_Blockchain::get_blockcypher_chain($adapter['chain']);
				$height = ($raw['result'] === 'success' && isset($raw['body']['height'])) ? self::small_int($raw['body']['height']) : null;
			}
			else {
				$raw = NMMPRO_Blockchain::get_esplora($adapter['base'], '/blocks/tip/height', false);
				$height = ($raw['result'] === 'success' && preg_match('/^\d{1,12}$/', $raw['body'])) ? (int) $raw['body'] : null;
			}
			if ($height !== null && $height > 0) {
				return array('height' => $height, 'source' => $adapter['source'], 'reason' => '');
			}
			$reasons[] = $adapter['source'] . ': ' . ($raw['result'] === 'success' ? 'malformed chain height' : 'explorer request failed');
		}
		return array('height' => null, 'source' => '', 'reason' => implode('; ', $reasons));
	}

	// =====================================================================
	// Incoming-output scan.
	// =====================================================================

	/**
	 * Every incoming output to $address from the top of the chain (and the
	 * mempool) down to the floor, with coverage stated. The floor is
	 * $floorHeight when the caller knows one (a block below it was mined
	 * before the address was bound); otherwise $sinceTime minus
	 * TIME_SLACK_SEC by block time, for records bound without a height.
	 *
	 * A listing is also checked against the explorer's own transaction
	 * counters: an answer that lists fewer transactions than the address
	 * reports (a truncated or degraded response) is incomplete, never an
	 * empty history.
	 *
	 * Paging is bounded by $maxPages. $state (from the previous call, null the
	 * first time) makes progress durable: the height range already covered
	 * and, if the last descent stopped early, where to resume it. A call that
	 * runs out of pages returns INCOMPLETE with the outputs it did see and a
	 * state that continues from there, so a busy address converges instead of
	 * starting over every time. A call that re-joins a previously covered
	 * range does not re-read it.
	 *
	 * Returns:
	 *   coverage   COMPLETE | INCOMPLETE
	 *   reason     why coverage is incomplete ('' when complete)
	 *   outputs    list of normalised outputs (see normalise_output())
	 *   pending    true if any incoming output is unconfirmed
	 *   tip_height chain tip at scan time, or null if the source gave none
	 *   state      the state to pass to the next call
	 *   source     provider name
	 *   exhausted  true when the only reason for incompleteness is the page
	 *              budget (more history than $maxPages pages)
	 *   unreadable true when the answer can never be read completely, however
	 *              often it is asked (e.g. more references in one block than a
	 *              page holds): a human must look, retrying will not help
	 *   resumed    true when the coverage relies on progress saved by earlier
	 *              calls. Such coverage is ADVISORY: the counts behind it are
	 *              kept as numbers, not transaction ids, so a reorganisation
	 *              or an inconsistent explorer can mislead them. Claiming a
	 *              payment and cancelling an order are only ever decided on a
	 *              scan made from scratch in one call ($state null), whose
	 *              transactions are deduplicated by id.
	 */
	public static function scan($cryptoId, $address, $sinceTime, $state = null, $maxPages = self::DEFAULT_MAX_PAGES, $floorHeight = null) {
		if (!self::has_adapter($cryptoId)) {
			return array(
				'coverage' => self::INCOMPLETE, 'reason' => 'no evidence adapter for ' . $cryptoId, 'outputs' => array(),
				'pending' => false, 'tip_height' => null, 'state' => null, 'source' => '', 'transport' => false, 'exhausted' => false,
				'resumed' => false, 'unreadable' => false,
			);
		}

		// Saved progress belongs to the source that made it (cursors differ
		// between providers). A source that cannot be reached hands over to the
		// next, which starts its own scan; any other incomplete answer - a
		// conflict, malformed data, a page limit - is final for this call.
		$result = null;
		foreach (self::$adapters[$cryptoId] as $adapter) {
			$own = (is_array($state) && isset($state['source']) && $state['source'] === $adapter['source']) ? $state : null;
			$result = self::scan_with($adapter, $address, $sinceTime, $own, $maxPages, $floorHeight);
			if (!$result['transport']) {
				return $result;
			}
		}
		// No source could be reached: nothing was learned, so the caller keeps
		// the progress it already had.
		$result['state'] = is_array($state) ? $state : null;
		$result['outputs'] = array();
		$result['pending'] = false;
		return $result;
	}

	private static function scan_with($adapter, $address, $sinceTime, $state, $maxPages, $floorHeight) {
		$result = array(
			'coverage' => self::INCOMPLETE, 'reason' => '', 'outputs' => array(), 'pending' => false,
			'tip_height' => null, 'state' => self::normalise_state($state), 'source' => $adapter['source'], 'transport' => false,
			'exhausted' => false, 'resumed' => false, 'unreadable' => false,
		);
		$result['state']['source'] = $adapter['source'];
		$old = $result['state'];
		$floorTime = (int) $sinceTime - self::TIME_SLACK_SEC;
		$floorHeight = $floorHeight === null ? null : (int) $floorHeight;
		// Whether a page reaches below the floor: everything at or above the
		// floor has then been read (heights descend; a page cut part-way
		// through a height is cut below the floor).
		$belowFloor = function ($page) use ($floorHeight, $floorTime) {
			if ($floorHeight !== null) {
				return $page['oldest_height'] !== null && $page['oldest_height'] < $floorHeight;
			}
			return $page['oldest_time'] !== null && $page['oldest_time'] < $floorTime;
		};
		$collected = array();
		// What the explorer says the address has, to catch a listing that is
		// short.
		$counters = null;

		$context = array('address' => $address, 'adapter' => $adapter, 'tip' => null);
		if ($adapter['type'] === 'esplora') {
			$stats = NMMPRO_Blockchain::get_esplora($adapter['base'], '/address/' . rawurlencode($address));
			if ($stats['result'] !== 'success') {
				$result['reason'] = 'could not read the address counters';
				$result['transport'] = true;
				return $result;
			}
			$counters = self::esplora_counters($stats['body'], $address);
			if ($counters === null) {
				$result['reason'] = 'malformed address counters';
				return $result;
			}

			$tip = NMMPRO_Blockchain::get_esplora($adapter['base'], '/blocks/tip/height', false);
			if ($tip['result'] !== 'success' || !preg_match('/^\d{1,12}$/', $tip['body'])) {
				$result['reason'] = 'could not read the chain tip';
				$result['transport'] = $tip['result'] !== 'success';
				return $result;
			}
			$context['tip'] = (int) $tip['body'];
			$result['tip_height'] = $context['tip'];

			// The mempool list is capped and not pageable: a full list may hide
			// more, so it cannot establish complete coverage.
			$mempool = NMMPRO_Blockchain::get_esplora($adapter['base'], '/address/' . rawurlencode($address) . '/txs/mempool');
			if ($mempool['result'] !== 'success' || !self::is_list($mempool['body'])) {
				$result['reason'] = 'could not read the mempool';
				$result['transport'] = $mempool['result'] !== 'success';
				return $result;
			}
			if (count($mempool['body']) >= self::ESPLORA_MEMPOOL_MAX) {
				$result['reason'] = 'the mempool list is full; more may be hidden';
				return $result;
			}
			$page = self::parse_esplora_txs($mempool['body'], $context, true);
			if (isset($page['error'])) {
				$result['reason'] = $page['error'];
				return $result;
			}
			if (count($page['tx_ids']) < $counters['unconfirmed']) {
				$result['reason'] = 'the mempool list is shorter than the address reports';
				return $result;
			}
			if (!self::merge($collected, $page['outputs'], $conflict)) {
				$result['reason'] = $conflict;
				return $result;
			}
		}

		// Descend from the top. Confirmed transactions read are kept as
		// txid => height, to count the history against the explorer's own
		// counter (a short listing is a degraded answer, not a short history).
		$pages = 0;
		$topHeight = null;
		$cursor = null;
		$stop = null;
		$lowest = null;
		$topIds = array();
		while ($pages < $maxPages) {
			$page = self::fetch_page($context, $cursor);
			$pages++;
			if (isset($page['error'])) {
				$result['reason'] = $page['error'];
				$result['transport'] = !empty($page['transport']);
				$result['unreadable'] = !empty($page['permanent']);
				$result['outputs'] = array_values($collected);
				return $result;
			}
			if (!self::merge($collected, $page['outputs'], $conflict)) {
				$result['reason'] = $conflict;
				return $result;
			}
			if ($pages === 1) {
				if (isset($page['counters'])) {
					$counters = $page['counters'];
				}
				if ($result['tip_height'] === null && isset($page['tip'])) {
					$result['tip_height'] = $page['tip'];
				}
				// The newest page lists nothing confirmed, yet the address has
				// confirmed transactions: a degraded answer, not an empty one.
				if ($counters !== null && $page['newest_height'] === null && $counters['confirmed'] > 0) {
					$result['reason'] = 'the explorer lists no confirmed transactions but reports ' . $counters['confirmed'];
					return $result;
				}
				// A floor above the chain itself would end the scan at once: the
				// recorded height cannot be trusted.
				if ($floorHeight !== null && $result['tip_height'] !== null && $floorHeight > $result['tip_height']) {
					$result['reason'] = 'the recorded binding height is above the chain tip';
					return $result;
				}
			}
			$topIds += $page['confirmed_ids'];
			if ($topHeight === null) {
				$topHeight = $page['newest_height'];
			}
			if ($page['oldest_height'] !== null) {
				$lowest = $page['oldest_height'];
			}
			if ($old['to'] !== null && $page['oldest_height'] !== null && $page['oldest_height'] <= $old['to']) {
				$stop = 'overlap';
				break;
			}
			if ($belowFloor($page)) {
				$stop = 'floor';
				break;
			}
			if ($page['next'] === null) {
				$stop = 'end';
				break;
			}
			$cursor = $page['next'];
		}

		$newTop = $topHeight !== null ? $topHeight : $old['to'];
		$esplora = $adapter['type'] === 'esplora';

		// Not joined to the saved range: this call's own descent is all there is.
		if ($stop !== 'overlap') {
			if ($stop === 'floor' || $stop === 'end') {
				return self::settle($result, $collected, $counters, $newTop, count($topIds), $stop === 'end');
			}
			// Out of pages: the range from the top down to here is covered, and
			// $cursor is the next (older) page to read.
			return self::unfinished($result, $collected, $newTop, $cursor, $topIds, 0, $lowest, 0, null);
		}

		// Joined the saved range. The explorer's newest transaction cannot be
		// older than the newest it showed before: if it is, it contradicts
		// itself (or the chain reorganised). Start afresh rather than count a
		// transaction twice when it reappears.
		if ($topHeight === null || $topHeight < $old['to']) {
			$result['reason'] = 'the explorer\'s newest transaction is older than one it reported before';
			$result['state'] = array('source' => $result['source']);
			$result['outputs'] = array_values($collected);
			return $result;
		}
		$result['resumed'] = true;

		// What is new lies above it.
		$new = 0;
		foreach ($topIds as $height) {
			if ($height > $old['to']) {
				$new++;
			}
		}
		if ($old['floor']) {
			return self::settle($result, $collected, $counters, $newTop, $old['n'] + $new, $old['whole']);
		}

		// The saved descent had not finished: continue it from where it
		// stopped. BlockCypher's continuation re-reads the edge height in full;
		// Esplora's continues after the last transaction read, so what was
		// already counted at the edge stays counted.
		$base = $old['n'] + $new + ($esplora ? $old['n_edge'] : 0);
		$contIds = array();
		$contLowest = null;
		$cursor = $old['cursor'];
		while ($cursor !== null && $pages < $maxPages) {
			$page = self::fetch_page($context, $cursor);
			$pages++;
			if (isset($page['error'])) {
				$result['reason'] = $page['error'];
				$result['transport'] = !empty($page['transport']);
				$result['unreadable'] = !empty($page['permanent']);
				$result['outputs'] = array_values($collected);
				return $result;
			}
			if (!self::merge($collected, $page['outputs'], $conflict)) {
				$result['reason'] = $conflict;
				return $result;
			}
			$contIds += $page['confirmed_ids'];
			if ($page['oldest_height'] !== null) {
				$contLowest = $page['oldest_height'];
			}
			if ($belowFloor($page) || $page['next'] === null) {
				return self::settle($result, $collected, $counters, $newTop, $base + count($contIds), $page['next'] === null);
			}
			$cursor = $page['next'];
		}
		if ($contLowest === null) {
			// The budget ran out before the continuation read anything: the
			// saved edge and its count stand.
			return self::unfinished($result, $collected, $newTop, $old['cursor'], array(), $old['n'] + $new, $old['edge'], 0, $old['n_edge']);
		}
		// Esplora pages can stay within the edge height; what was counted
		// there before is carried.
		$carriedEdge = ($esplora && $contLowest === $old['edge']) ? $old['n_edge'] : 0;
		return self::unfinished($result, $collected, $newTop, $cursor, $contIds, $base - $carriedEdge, $contLowest, $carriedEdge, null);
	}

	/**
	 * A finished descent: complete, unless it covered the whole history and
	 * counted fewer confirmed transactions than the address reports. Then the
	 * listing was short (a degraded or truncated answer): incomplete, and the
	 * saved progress is dropped so the next scan starts afresh rather than
	 * repeat the shortfall.
	 */
	private static function settle($result, $collected, $counters, $top, $count, $whole) {
		if ($whole && $counters !== null && $count < $counters['confirmed']) {
			$result['reason'] = 'the listed history (' . $count . ' confirmed transactions) is shorter than the address reports (' . $counters['confirmed'] . ')';
			$result['state'] = array('source' => $result['source']);
			$result['outputs'] = array_values($collected);
			return $result;
		}
		return self::finish($result, $collected, array('to' => $top, 'floor' => true, 'cursor' => null, 'n' => $count, 'whole' => (bool) $whole));
	}

	/**
	 * A descent out of pages: covered from $top down to $cursor. $ids were
	 * read in this call; $counted were counted before it (excluding the edge).
	 * The lowest height read (the edge) may have been cut part-way, so it is
	 * counted separately.
	 */
	private static function unfinished($result, $collected, $top, $cursor, $ids, $counted, $edge, $carriedEdge, $keepEdgeCount) {
		$atEdge = 0;
		foreach ($ids as $height) {
			if ($height === $edge) {
				$atEdge++;
			}
		}
		$nEdge = $keepEdgeCount !== null ? $keepEdgeCount : $atEdge + $carriedEdge;
		$result['state'] = array(
			'to' => $top, 'floor' => false, 'cursor' => $cursor, 'source' => $result['source'],
			'n' => $counted + count($ids) - $atEdge, 'edge' => $edge, 'n_edge' => $nEdge,
		);
		$result['reason'] = 'page limit reached';
		$result['exhausted'] = true;
		$result['outputs'] = array_values($collected);
		$result['pending'] = self::any_pending($collected);
		return $result;
	}

	/**
	 * Esplora /address counters: confirmed and mempool transaction counts,
	 * or null when the answer is not a well-formed one about $address.
	 *
	 * @return array{confirmed: int, unconfirmed: int}|null
	 */
	private static function esplora_counters($body, $address) {
		if (!is_array($body) || !isset($body['address']) || $body['address'] !== $address) {
			return null;
		}
		$out = array();
		foreach (array('chain_stats' => 'confirmed', 'mempool_stats' => 'unconfirmed') as $group => $key) {
			$n = (isset($body[$group]) && is_array($body[$group]) && isset($body[$group]['tx_count'])) ? self::small_int($body[$group]['tx_count']) : null;
			if ($n === null || $n < 0) {
				return null;
			}
			$out[$key] = $n;
		}
		return $out;
	}

	private static function finish($result, $collected, $state) {
		$state['source'] = $result['source'];
		$result['coverage'] = self::COMPLETE;
		$result['reason'] = '';
		$result['state'] = $state;
		$result['outputs'] = array_values($collected);
		$result['pending'] = self::any_pending($collected);
		return $result;
	}

	private static function normalise_state($state) {
		$fresh = array('to' => null, 'floor' => false, 'cursor' => null, 'n' => 0, 'whole' => false, 'edge' => null, 'n_edge' => 0);
		if (!is_array($state)) {
			return $fresh;
		}
		$out = $fresh;
		if (isset($state['to']) && is_int($state['to'])) { $out['to'] = $state['to']; }
		if (isset($state['floor'])) { $out['floor'] = (bool) $state['floor']; }
		if (isset($state['cursor']) && (is_int($state['cursor']) || is_string($state['cursor']))) { $out['cursor'] = $state['cursor']; }
		$int = function ($key) use ($state) { return (isset($state[$key]) && is_int($state[$key]) && $state[$key] >= 0) ? $state[$key] : null; };
		$out['n'] = $int('n');
		$out['whole'] = !empty($state['whole']);
		$out['edge'] = $int('edge');
		$out['n_edge'] = $int('n_edge');
		// A covered range is either finished (floor, with its count) or has a
		// resume point, an edge and counts. Anything else - including progress
		// saved before transactions were counted - is not trusted: start a
		// full descent instead.
		$finished = $out['to'] !== null && $out['floor'] && $out['n'] !== null;
		$resumable = $out['to'] !== null && !$out['floor'] && $out['cursor'] !== null && $out['n'] !== null && $out['edge'] !== null && $out['n_edge'] !== null;
		if (!$finished && !$resumable) {
			return $fresh;
		}
		if ($finished) {
			$out['edge'] = null;
			$out['n_edge'] = 0;
		}
		return $out;
	}

	/**
	 * Add $outputs to $collected, keyed by output identity. An identical
	 * repeat (an overlapping page) is absorbed; a repeat that DISAGREES is a
	 * conflict, and the whole scan stops rather than pick a version.
	 */
	private static function merge(&$collected, $outputs, &$conflict) {
		foreach ($outputs as $output) {
			$key = $output['tx_hash'] . ':' . $output['output_index'];
			if (!isset($collected[$key])) {
				$collected[$key] = $output;
				continue;
			}
			$seen = $collected[$key];
			if ($seen['amount_units'] !== $output['amount_units']) {
				$conflict = 'conflicting evidence for output ' . $key;
				return false;
			}
			// The same output seen unconfirmed and then confirmed (it was mined
			// between two requests of one scan): keep the confirmed view.
			if ($seen['confirmed'] && $output['confirmed']
				&& ($seen['block_height'] !== $output['block_height'] || $seen['block_time'] !== $output['block_time'])) {
				$conflict = 'conflicting block for output ' . $key;
				return false;
			}
			if (!$seen['confirmed'] && $output['confirmed']) {
				$collected[$key] = $output;
			}
		}
		return true;
	}

	private static function any_pending($collected) {
		foreach ($collected as $output) {
			if (!$output['confirmed']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One page, normalised: outputs, newest/oldest confirmed height, oldest
	 * block time, and the cursor for the next (older) page or null at the end.
	 * ['error' => reason] on anything unusable.
	 */
	private static function fetch_page($context, $cursor) {
		$adapter = $context['adapter'];

		if ($adapter['type'] === 'blockcypher') {
			$raw = NMMPRO_Blockchain::get_blockcypher_address_page($adapter['chain'], $context['address'], $cursor, self::BLOCKCYPHER_PAGE);
			if ($raw['result'] !== 'success') {
				return array('error' => 'explorer request failed', 'transport' => true);
			}
			return self::parse_blockcypher_page($raw['body'], $context['address'], $cursor);
		}

		$path = '/address/' . rawurlencode($context['address']) . '/txs/chain' . ($cursor !== null ? '/' . rawurlencode((string) $cursor) : '');
		$raw = NMMPRO_Blockchain::get_esplora($adapter['base'], $path);
		if ($raw['result'] !== 'success' || !self::is_list($raw['body'])) {
			return array('error' => 'explorer request failed', 'transport' => $raw['result'] !== 'success');
		}
		$page = self::parse_esplora_txs($raw['body'], $context, false);
		if (isset($page['error'])) {
			return $page;
		}
		// A short page is the last one; otherwise continue after its last txid.
		$page['next'] = count($raw['body']) < self::ESPLORA_PAGE ? null : $page['last_txid'];
		if ($page['next'] !== null && $page['next'] === $cursor) {
			return array('error' => 'the explorer repeated a page cursor');
		}
		return $page;
	}

	/**
	 * A decoded BlockCypher address page. Public for offline tests.
	 *
	 * Paging is by block height and a page can end part-way through a height,
	 * so the next page re-includes the lowest height seen (before = lowest +
	 * 1) and the overlap is absorbed by output identity. A full page that is
	 * all one height cannot be paged past: that is an error (incomplete), not
	 * a skipped block.
	 *
	 * Every page must carry the address's transaction counters (n_tx confirmed,
	 * unconfirmed_n_tx pending). The first page must list exactly the pending
	 * transactions it reports, and - when it is also the last - exactly the
	 * confirmed ones. A later page always repeats the height it continues
	 * from, so an empty one is truncated. BlockCypher omits empty lists, so
	 * without these checks a degraded answer would read as an unused address.
	 */
	public static function parse_blockcypher_page($body, $address, $cursor = null) {
		if (!is_array($body) || !isset($body['address']) || $body['address'] !== $address) {
			return array('error' => 'answer is not about the requested address');
		}
		$nTx = self::small_int(isset($body['n_tx']) ? $body['n_tx'] : null);
		$nPending = self::small_int(isset($body['unconfirmed_n_tx']) ? $body['unconfirmed_n_tx'] : null);
		if ($nTx === null || $nTx < 0 || $nPending === null || $nPending < 0) {
			return array('error' => 'missing or malformed transaction counters');
		}

		$outputs = array();
		$newest = null;
		$oldest = null;
		$oldestTime = null;
		$tip = null;
		$refs = array();

		foreach (array('txrefs' => true, 'unconfirmed_txrefs' => false) as $key => $confirmedList) {
			if (!array_key_exists($key, $body)) {
				continue;  // BlockCypher omits an empty list
			}
			if (!self::is_list($body[$key])) {
				return array('error' => 'malformed ' . $key);
			}
			foreach ($body[$key] as $ref) {
				$refs[] = array($ref, $confirmedList);
			}
		}
		// Unconfirmed references only belong to the first page.
		if ($cursor !== null) {
			$refs = array_values(array_filter($refs, function ($r) { return $r[1]; }));
		}

		foreach ($refs as $pair) {
			list($ref, $confirmedList) = $pair;
			if (!is_array($ref) || !isset($ref['tx_hash']) || !is_string($ref['tx_hash']) || !preg_match('/^[0-9a-f]{64}$/', $ref['tx_hash'])) {
				return array('error' => 'malformed transaction reference');
			}
			$inputN = self::small_int(isset($ref['tx_input_n']) ? $ref['tx_input_n'] : null);
			$outputN = self::small_int(isset($ref['tx_output_n']) ? $ref['tx_output_n'] : null);
			$height = self::small_int(isset($ref['block_height']) ? $ref['block_height'] : null);
			$confs = self::small_int(isset($ref['confirmations']) ? $ref['confirmations'] : null);
			if ($inputN === null || $outputN === null || $height === null || $confs === null) {
				return array('error' => 'malformed transaction reference fields');
			}

			if ($confirmedList) {
				$time = isset($ref['confirmed']) ? self::iso_time($ref['confirmed']) : null;
				if ($height < 0 || $confs < 1 || $time === null) {
					return array('error' => 'a confirmed reference lacks a valid height, confirmation count or time');
				}
				$newest = $newest === null ? $height : max($newest, $height);
				$oldest = $oldest === null ? $height : min($oldest, $height);
				$oldestTime = $oldestTime === null ? $time : min($oldestTime, $time);
				$tip = $tip === null ? $height + $confs - 1 : max($tip, $height + $confs - 1);
			}
			else {
				if ($height !== -1 || $confs !== 0) {
					return array('error' => 'an unconfirmed reference claims a block');
				}
				$time = null;
			}

			// Inputs (spends from this address) have a negative output index.
			if ($inputN !== -1 || $outputN < 0) {
				continue;
			}
			if (!empty($ref['double_spend'])) {
				return array('error' => 'a double-spent output was reported');
			}

			$output = self::normalise_output($ref['tx_hash'], $outputN, isset($ref['value']) ? $ref['value'] : null,
				$confirmedList, $confirmedList ? $confs : 0, $confirmedList ? $height : null, $time);
			if ($output === null) {
				return array('error' => 'malformed output value');
			}
			$outputs[] = $output;
		}

		$hasMore = !empty($body['hasMore']);
		$next = null;
		if ($hasMore) {
			if ($oldest === null) {
				return array('error' => 'more history is reported but no confirmed height to page from');
			}
			$next = $oldest + 1;
			if ($cursor !== null && $next >= (int) $cursor) {
				return array('error' => 'a full page at one height cannot be paged past', 'permanent' => true);
			}
		}

		$confirmedIds = array();
		$pendingIds = array();
		foreach ($refs as $pair) {
			if ($pair[1]) {
				$confirmedIds[$pair[0]['tx_hash']] = (int) $pair[0]['block_height'];
			}
			else {
				$pendingIds[$pair[0]['tx_hash']] = true;
			}
		}
		if ($cursor === null) {
			if (count($pendingIds) !== $nPending) {
				return array('error' => 'the page lists ' . count($pendingIds) . ' pending transactions but the address reports ' . $nPending);
			}
			if (!$hasMore && count($confirmedIds) !== $nTx) {
				return array('error' => 'the page lists ' . count($confirmedIds) . ' confirmed transactions but the address reports ' . $nTx);
			}
		}
		elseif ($confirmedIds === array()) {
			return array('error' => 'a continuation page lists nothing');
		}

		return array(
			'outputs' => $outputs, 'newest_height' => $newest, 'oldest_height' => $oldest, 'oldest_time' => $oldestTime, 'next' => $next,
			'tx_ids' => array_keys($confirmedIds + $pendingIds),
			'confirmed_ids' => $confirmedIds,
			'counters' => array('confirmed' => $nTx, 'unconfirmed' => $nPending),
			'tip' => $tip,
		);
	}

	/**
	 * A decoded Esplora transaction list. Public for offline tests. Outputs
	 * are the vout entries paying $address; the output index is the entry's
	 * position. Confirmations come from the tip read at the start of the scan.
	 */
	public static function parse_esplora_txs($txs, $context, $mempool) {
		$outputs = array();
		$newest = null;
		$oldest = null;
		$oldestTime = null;
		$lastTxid = null;
		$ids = array();
		$confirmedIds = array();

		foreach ($txs as $tx) {
			if (!is_array($tx) || !isset($tx['txid']) || !is_string($tx['txid']) || !preg_match('/^[0-9a-f]{64}$/', $tx['txid'])
				|| !isset($tx['vout']) || !self::is_list($tx['vout']) || !isset($tx['status']) || !is_array($tx['status'])
				|| !isset($tx['status']['confirmed']) || !is_bool($tx['status']['confirmed'])) {
				return array('error' => 'malformed transaction');
			}
			$lastTxid = $tx['txid'];
			$ids[$tx['txid']] = true;
			$confirmed = $tx['status']['confirmed'];
			if ($mempool === $confirmed) {
				return array('error' => $mempool ? 'a confirmed transaction in the mempool list' : 'an unconfirmed transaction in the chain list');
			}

			$height = null;
			$time = null;
			$confs = 0;
			if ($confirmed) {
				$height = self::small_int(isset($tx['status']['block_height']) ? $tx['status']['block_height'] : null);
				$time = self::small_int(isset($tx['status']['block_time']) ? $tx['status']['block_time'] : null);
				if ($height === null || $height < 0 || $time === null || !self::plausible_time($time) || $context['tip'] === null || $height > $context['tip']) {
					return array('error' => 'a confirmed transaction lacks a valid height or block time');
				}
				$confs = $context['tip'] - $height + 1;
				$confirmedIds[$tx['txid']] = $height;
				$newest = $newest === null ? $height : max($newest, $height);
				$oldest = $oldest === null ? $height : min($oldest, $height);
				$oldestTime = $oldestTime === null ? $time : min($oldestTime, $time);
			}

			foreach ($tx['vout'] as $index => $vout) {
				if (!is_array($vout)) {
					return array('error' => 'malformed output');
				}
				if (!isset($vout['scriptpubkey_address']) || $vout['scriptpubkey_address'] !== $context['address']) {
					continue;
				}
				$output = self::normalise_output($tx['txid'], $index, isset($vout['value']) ? $vout['value'] : null, $confirmed, $confs, $height, $time);
				if ($output === null) {
					return array('error' => 'malformed output value');
				}
				$outputs[] = $output;
			}
		}

		return array('outputs' => $outputs, 'newest_height' => $newest, 'oldest_height' => $oldest, 'oldest_time' => $oldestTime, 'last_txid' => $lastTxid, 'tx_ids' => array_keys($ids), 'confirmed_ids' => $confirmedIds);
	}

	/**
	 * The normalised output every adapter produces:
	 *   tx_hash, output_index, amount_units (canonical decimal string of the
	 *   coin's smallest unit), confirmed, confirmations, block_height,
	 *   block_time (UTC unix seconds of the confirming block; null while
	 *   unconfirmed - never the fetch time).
	 * null when the value is not an exact non-negative integer.
	 */
	private static function normalise_output($txHash, $index, $value, $confirmed, $confirmations, $height, $time) {
		$units = self::counter($value, false);
		if ($units === null) {
			return null;
		}
		return array(
			'tx_hash'       => $txHash,
			'output_index'  => (int) $index,
			'amount_units'  => $units,
			'confirmed'     => (bool) $confirmed,
			'confirmations' => (int) $confirmations,
			'block_height'  => $height === null ? null : (int) $height,
			'block_time'    => $time === null ? null : (int) $time,
		);
	}

	/**
	 * A counter as a canonical decimal string, or null when absent or not a
	 * whole number. Negative values are only meaningful for a balance.
	 */
	private static function counter($value, $signed) {
		if (is_int($value)) {
			$value = (string) $value;
		}
		if (!is_string($value) || !preg_match($signed ? '/^-?\d{1,40}$/' : '/^\d{1,40}$/', $value)) {
			return null;
		}
		$negative = $value[0] === '-';
		$digits = ltrim($negative ? substr($value, 1) : $value, '0');
		if ($digits === '') {
			return '0';
		}
		return ($negative ? '-' : '') . $digits;
	}

	// A whole number that fits a native int (heights, indexes, counts, times).
	private static function small_int($value) {
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^-?\d{1,15}$/', $value)) {
			return (int) $value;
		}
		return null;
	}

	// BlockCypher's `confirmed`: an ISO 8601 UTC time. Nothing else is accepted
	// (no guessing, no substituting the current time).
	private static function iso_time($value) {
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value)) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', substr($value, 0, 19), new DateTimeZone('UTC'));
		if ($dt === false || $dt->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
			return null;
		}
		$ts = $dt->getTimestamp();
		return self::plausible_time($ts) ? $ts : null;
	}

	private static function plausible_time($ts) {
		return $ts >= self::EARLIEST_BLOCK_TIME && $ts <= time() + self::FUTURE_TOLERANCE_SEC;
	}

	private static function is_list($value) {
		return is_array($value) && ($value === array() || array_keys($value) === range(0, count($value) - 1));
	}

	private static function activity($state, $reason, $source) {
		return array('state' => $state, 'reason' => $reason, 'source' => $source);
	}
}
