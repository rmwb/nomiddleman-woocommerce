<?php
/**
 * WordPress test: the Privacy Mode (HD) evidence contract and coin
 * capabilities (NMMPRO_Hd_Evidence).
 *
 * Two fake explorers stand in for the real ones, paging exactly as their
 * documentation describes: BlockCypher (DOGE, DASH) pages by block height
 * with `before`/`limit`/`hasMore` and lists unconfirmed references
 * separately; Esplora (BTC via mempool.space, LTC via litecoinspace) pages
 * confirmed transactions 25 at a time after the last txid seen and caps its
 * unpaged mempool list at 50. The suite checks:
 *
 *   - capability: every HD coin in the registry has an explicit verdict;
 *   - output identity: two real outputs in one transaction count as two,
 *     outputs to other addresses and spends are ignored, overlapping pages
 *     count once, and a disagreeing duplicate stops the scan;
 *   - pagination and coverage: a valid empty history is complete; a
 *     same-height page boundary loses nothing; a full page at one height, a
 *     repeated cursor, a full mempool list or an exhausted page budget is
 *     incomplete, and resumed progress converges;
 *   - confirmations and time: counts come from the explorer or the tip;
 *     unconfirmed outputs carry no time; malformed, non-UTC, future and
 *     pre-genesis times are rejected, never replaced with the current time;
 *   - failures: HTTP errors, malformed JSON, wrong-address answers, negative
 *     or fractional values and double spends are never read as zero;
 *   - counters: a listing shorter than the explorer's own transaction counts
 *     (an answer naming only the address, an empty or cut-short page) is
 *     incomplete, never an empty history;
 *   - the height floor: an address bound at a known height is scanned down
 *     to that height, so an early-stamped block cannot end the scan early,
 *     and a floor above the chain tip is not trusted.
 *
 * Needs WordPress (the explorer transport runs through pre_http_request);
 * no WooCommerce orders are created.
 *
 *   Run:  wp eval-file tests/test-hd-evidence.php
 */

if (!function_exists('add_filter') || !class_exists('NMMPRO_Hd_Evidence')) {
	echo "test-hd-evidence: skipped (needs WordPress)\n";
	return;
}

$GLOBALS['he_ok'] = true;
function he_ok($label, $cond, $extra = '') {
	printf("%-74s %s%s\n", $label, $cond ? 'ok' : 'FAIL', $extra !== '' ? "  $extra" : '');
	if (!$cond) { $GLOBALS['he_ok'] = false; }
}

function he_hash($label) { return hash('sha256', 'nmmpro-hd-evidence|' . $label); }
function he_iso($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); }

const HE_TIP = 900000;
$GLOBALS['he_now'] = time();
function he_time_at($height) { return $GLOBALS['he_now'] - (HE_TIP - $height) * 600; }  // a block every ten minutes
const HE_SLACK_BLOCKS = 18;   // NMMPRO_Hd_Evidence::TIME_SLACK_SEC at ten minutes a block

// ---------------------------------------------------------------------
// Fake BlockCypher. $GLOBALS['he_bc'][$address] = array(
//   'refs' => list of txrefs (confirmed), 'unconfirmed' => list, 'raw' => optional override body,
//   'truncate_after' => optional: continuation pages list only this many references and no hasMore,
//   'counters' => optional override of n_tx / unconfirmed_n_tx)
// ---------------------------------------------------------------------
$GLOBALS['he_bc'] = array();
$GLOBALS['he_esplora'] = array();
$GLOBALS['he_requests'] = array();
$GLOBALS['he_fail'] = array();          // url substring => HTTP code or 'malformed'

function he_bc_ref($label, $out, $value, $height, $extra = array()) {
	return array_merge(array(
		'tx_hash' => he_hash($label), 'block_height' => $height, 'tx_input_n' => -1, 'tx_output_n' => $out,
		'value' => $value, 'spent' => false, 'confirmations' => HE_TIP - $height + 1,
		'confirmed' => he_iso(he_time_at($height)), 'double_spend' => false,
	), $extra);
}
function he_bc_pending($label, $out, $value) {
	return array('tx_hash' => he_hash($label), 'block_height' => -1, 'tx_input_n' => -1, 'tx_output_n' => $out,
		'value' => $value, 'spent' => false, 'confirmations' => 0, 'received' => he_iso($GLOBALS['he_now']), 'double_spend' => false);
}

function he_http($code, $body) {
	return array('response' => array('code' => $code, 'message' => ''), 'body' => $body, 'headers' => array(), 'cookies' => array());
}

$GLOBALS['he_mock'] = function ($pre, $args, $url) {
	$GLOBALS['he_requests'][] = $url;
	foreach ($GLOBALS['he_fail'] as $needle => $what) {
		if (strpos($url, $needle) !== false) {
			return $what === 'malformed' ? he_http(200, '{"not json') : he_http((int) $what, '');
		}
	}
	$parts = wp_parse_url($url);
	parse_str(isset($parts['query']) ? $parts['query'] : '', $q);
	$path = $parts['path'];

	if ($parts['host'] === 'api.blockcypher.com' && preg_match('#^/v1/(doge|dash|ltc)/main$#', $path)) {
		return he_http(200, wp_json_encode(array('name' => 'X.main', 'height' => isset($GLOBALS['he_tip_override']) ? $GLOBALS['he_tip_override'] : HE_TIP)));
	}
	if ($parts['host'] === 'api.blockcypher.com' && preg_match('#^/v1/(doge|dash|ltc)/main/addrs/([^/]+)$#', $path, $m)) {
		$address = rawurldecode($m[2]);
		$book = isset($GLOBALS['he_bc'][$address]) ? $GLOBALS['he_bc'][$address] : array('refs' => array(), 'unconfirmed' => array());
		if (isset($book['raw'])) {
			return he_http(200, wp_json_encode($book['raw']));
		}
		$refs = $book['refs'];
		usort($refs, function ($a, $b) { return $b['block_height'] - $a['block_height']; });
		if (isset($q['before'])) {
			$refs = array_values(array_filter($refs, function ($r) use ($q) { return $r['block_height'] < (int) $q['before']; }));
		}
		// Degraded answers that still carry the true counters: 'drop' omits
		// one transaction everywhere; 'hide_newest' omits the newest N.
		if (isset($book['drop'])) {
			$refs = array_values(array_filter($refs, function ($r) use ($book) { return $r['tx_hash'] !== he_hash($book['drop']); }));
		}
		if (isset($book['hide_newest'])) {
			$refs = array_slice($refs, $book['hide_newest']);
		}
		$limit = isset($q['limit']) ? (int) $q['limit'] : 20;
		// Counters count transactions (distinct hashes), not references.
		$body = array('address' => $address,
			'n_tx' => count(array_unique(array_column($book['refs'], 'tx_hash'))),
			'unconfirmed_n_tx' => count(array_unique(array_column($book['unconfirmed'], 'tx_hash'))));
		if (isset($book['counters'])) { $body = array_merge($body, $book['counters']); }
		$page = array_slice($refs, 0, $limit);
		$more = count($refs) > $limit;
		if (isset($q['before']) && isset($book['truncate_after'])) {
			$page = array_slice($page, 0, $book['truncate_after']);
			$more = false;
		}
		if ($page !== array()) { $body['txrefs'] = $page; }
		if (!isset($q['before']) && $book['unconfirmed'] !== array()) { $body['unconfirmed_txrefs'] = $book['unconfirmed']; }
		if ($more) { $body['hasMore'] = true; }
		return he_http(200, wp_json_encode($body));
	}

	foreach (array('mempool.space' => '/api', 'blockstream.info' => '/api', 'litecoinspace.org' => '/api') as $host => $prefix) {
		if ($parts['host'] !== $host || strpos($path, $prefix) !== 0) {
			continue;
		}
		$rest = substr($path, strlen($prefix));
		if ($rest === '/blocks/tip/height') {
			return he_http(200, (string) (isset($GLOBALS['he_tip_override']) ? $GLOBALS['he_tip_override'] : HE_TIP));
		}
		if (preg_match('#^/address/([^/]+)(/txs/(chain|mempool)(?:/([0-9a-f]{64}))?)?$#', $rest, $m)) {
			$address = rawurldecode($m[1]);
			$book = isset($GLOBALS['he_esplora'][$address]) ? $GLOBALS['he_esplora'][$address] : array('chain' => array(), 'mempool' => array());
			if (empty($m[2])) {
				$counts = isset($book['counts']) ? $book['counts'] : array(count($book['chain']), count($book['mempool']));
				return he_http(200, wp_json_encode(array('address' => $address,
					'chain_stats' => array('funded_txo_count' => 0, 'funded_txo_sum' => 0, 'spent_txo_count' => 0, 'spent_txo_sum' => 0, 'tx_count' => $counts[0]),
					'mempool_stats' => array('funded_txo_count' => 0, 'funded_txo_sum' => 0, 'spent_txo_count' => 0, 'spent_txo_sum' => 0, 'tx_count' => $counts[1]))));
			}
			if ($m[3] === 'mempool') {
				return he_http(200, wp_json_encode(array_slice(!empty($book['hide_mempool']) ? array() : $book['mempool'], 0, 50)));
			}
			$chain = $book['chain'];
			usort($chain, function ($a, $b) { return $b['status']['block_height'] - $a['status']['block_height']; });
			$start = 0;
			if (!empty($m[4])) {
				if (!empty($book['repeat_cursor'])) { $start = 0; }
				else {
					foreach ($chain as $i => $tx) { if ($tx['txid'] === $m[4]) { $start = $i + 1; break; } }
				}
				if (isset($book['truncate_after'])) {
					return he_http(200, wp_json_encode(array_slice($chain, $start, $book['truncate_after'])));
				}
			}
			if (!empty($book['empty_chain'])) {
				return he_http(200, '[]');
			}
			return he_http(200, wp_json_encode(array_slice($chain, $start, 25)));
		}
	}

	return new WP_Error('he_unmocked', 'unmocked ' . $url);
};

function he_esplora_tx($label, $height, $vouts, $confirmed = true) {
	$status = $confirmed
		? array('confirmed' => true, 'block_height' => $height, 'block_hash' => he_hash('block' . $height), 'block_time' => he_time_at($height))
		: array('confirmed' => false);
	$vout = array();
	foreach ($vouts as $v) {
		$vout[] = array('scriptpubkey_address' => $v[0], 'value' => $v[1]);
	}
	return array('txid' => he_hash($label), 'vin' => array(), 'vout' => $vout, 'status' => $status);
}

function he_scan($coin, $address, $since, $state = null, $pages = NMMPRO_Hd_Evidence::DEFAULT_MAX_PAGES, $floorHeight = null) {
	global $wpdb;
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%' OR `option_name` LIKE '%nmmpro_cooldown%'");
	wp_cache_flush();
	$GLOBALS['he_requests'] = array();
	add_filter('pre_http_request', $GLOBALS['he_mock'], 10, 3);
	try {
		return NMMPRO_Hd_Evidence::scan($coin, $address, $since, $state, $pages, $floorHeight);
	}
	finally {
		remove_filter('pre_http_request', $GLOBALS['he_mock'], 10);
	}
}

function he_keys($scan) {
	$keys = array();
	foreach ($scan['outputs'] as $o) { $keys[] = $o['tx_hash'] . ':' . $o['output_index']; }
	sort($keys);
	return $keys;
}

$since = he_time_at(HE_TIP - 100);   // assignment boundary: 100 blocks ago

// =====================================================================
echo "--- 1. capability matrix ---\n";
$matrix = NMMPRO_Hd_Evidence::capability_matrix();
$hdCoins = array();
foreach (NMMPRO_Cryptocurrencies::get() as $crypto) { if ($crypto->has_hd()) { $hdCoins[] = $crypto->get_id(); } }
he_ok('every HD coin in the registry has a verdict',                        array_keys($matrix) == $hdCoins, implode(',', array_keys($matrix)));
foreach (array('BTC', 'LTC', 'DOGE', 'DASH') as $id) {
	he_ok("  $id: automatic via " . (isset($matrix[$id]) ? $matrix[$id]['source'] : '?'), isset($matrix[$id]) && $matrix[$id]['automatic'] === true);
}
foreach (array('QTUM', 'BTX', 'XMY') as $id) {
	he_ok("  $id: unavailable, with a stated reason",                        isset($matrix[$id]) && $matrix[$id]['automatic'] === false && $matrix[$id]['reason'] !== '');
}

// =====================================================================
echo "--- 2. BlockCypher: identity, confirmations, time ---\n";
$addr = 'DHeEvidenceAddrOne';
$GLOBALS['he_bc'][$addr] = array(
	'refs' => array(
		he_bc_ref('two-outputs', 0, 100000000, HE_TIP - 10),
		he_bc_ref('two-outputs', 2, 250000000, HE_TIP - 10),
		he_bc_ref('spend', -1, 50000000, HE_TIP - 5, array('tx_input_n' => 0, 'tx_output_n' => -1)),
	),
	'unconfirmed' => array(he_bc_pending('in-mempool', 1, 700000000)),
);
$s = he_scan('DOGE', $addr, $since);
$byKey = array();
foreach ($s['outputs'] as $o) { $byKey[$o['tx_hash'] . ':' . $o['output_index']] = $o; }
he_ok('a short history is complete',                                        $s['coverage'] === 'complete', $s['reason']);
he_ok('two real outputs of one transaction count as two',                   isset($byKey[he_hash('two-outputs') . ':0'], $byKey[he_hash('two-outputs') . ':2']));
he_ok('  with exact smallest-unit amounts',                                 $byKey[he_hash('two-outputs') . ':2']['amount_units'] === '250000000');
he_ok('  confirmations from the explorer',                                  $byKey[he_hash('two-outputs') . ':0']['confirmations'] === 11);
he_ok('  and the confirming block\'s UTC time',                             $byKey[he_hash('two-outputs') . ':0']['block_time'] === he_time_at(HE_TIP - 10));
he_ok('a spend from the address is not an incoming output',                 !isset($byKey[he_hash('spend') . ':-1']) && count($s['outputs']) === 3);
he_ok('an unconfirmed output is reported as pending',                       $s['pending'] === true && $byKey[he_hash('in-mempool') . ':1']['confirmed'] === false);
he_ok('  with no time and no confirmations - never the fetch time',         $byKey[he_hash('in-mempool') . ':1']['block_time'] === null && $byKey[he_hash('in-mempool') . ':1']['confirmations'] === 0);

$s = he_scan('DOGE', 'DHeEvidenceEmpty', $since);
he_ok('a valid empty history is complete, not an error',                    $s['coverage'] === 'complete' && $s['outputs'] === array() && $s['pending'] === false, $s['reason']);

// =====================================================================
echo "--- 3. BlockCypher: pagination and coverage ---\n";
$addr = 'DHeEvidencePaged';
$refs = array();
$heights = array();
for ($i = 0; $i < 300; $i++) {
	$h = HE_TIP - 1 - intdiv($i, 3);                     // three outputs per block
	$refs[] = he_bc_ref('paged-' . $i, 0, 1000 + $i, $h);
	$heights[$h] = true;
}
$GLOBALS['he_bc'][$addr] = array('refs' => $refs, 'unconfirmed' => array());
$s = he_scan('DOGE', $addr, he_time_at(HE_TIP - 10), null, 10);
$want = array();
foreach ($refs as $r) { if ($r['block_height'] >= HE_TIP - 10 - HE_SLACK_BLOCKS) { $want[] = $r['tx_hash'] . ':0'; } }
he_ok('pages cut mid-block lose nothing and count nothing twice',           $s['coverage'] === 'complete' && count(he_keys($s)) === count(array_unique(he_keys($s))) && count(array_diff($want, he_keys($s))) === 0, count($s['outputs']) . ' outputs, ' . $s['reason']);
he_ok('  and the scan stops at the boundary, not the end of history',       count($GLOBALS['he_requests']) <= 3, count($GLOBALS['he_requests']) . ' requests for ' . count($refs) . ' references');

$full = array();
for ($i = 0; $i < 60; $i++) { $full[] = he_bc_ref('one-height-' . $i, 0, 5000, HE_TIP - 3); }
$GLOBALS['he_bc']['DHeEvidenceOneHeight'] = array('refs' => $full, 'unconfirmed' => array());
$s = he_scan('DOGE', 'DHeEvidenceOneHeight', $since);
he_ok('a full page at one height cannot be paged past: incomplete',         $s['coverage'] === 'incomplete' && strpos($s['reason'], 'one height') !== false, $s['reason']);
he_ok('  detected at once, not by spending the page budget',               count($GLOBALS['he_requests']) === 2, count($GLOBALS['he_requests']) . ' requests');
he_ok('  and flagged unreadable: asking again will not help',               $s['unreadable'] === true && $s['exhausted'] === false);

// A busy address: the page budget runs out; resumed progress converges.
$busy = array();
for ($i = 0; $i < 400; $i++) { $busy[] = he_bc_ref('busy-' . $i, 0, 1, HE_TIP - 1 - $i); }
$GLOBALS['he_bc']['DHeEvidenceBusy'] = array('refs' => $busy, 'unconfirmed' => array());
$s1 = he_scan('DOGE', 'DHeEvidenceBusy', he_time_at(HE_TIP - 380), null, 3);
he_ok('an exhausted page budget is incomplete',                             $s1['coverage'] === 'incomplete' && $s1['reason'] === 'page limit reached', $s1['reason']);
he_ok('  and records where to resume',                                      $s1['state']['cursor'] !== null && $s1['state']['to'] === HE_TIP - 1);
$seen = he_keys($s1);
$s2 = he_scan('DOGE', 'DHeEvidenceBusy', he_time_at(HE_TIP - 380), $s1['state'], 3);
$seen = array_unique(array_merge($seen, he_keys($s2)));
$state = $s2['state'];
for ($round = 0; $round < 10 && $s2['coverage'] !== 'complete'; $round++) {
	$s2 = he_scan('DOGE', 'DHeEvidenceBusy', he_time_at(HE_TIP - 380), $state, 3);
	$seen = array_unique(array_merge($seen, he_keys($s2)));
	$state = $s2['state'];
}
$wantBusy = array();
foreach ($busy as $r) { if ($r['block_height'] >= HE_TIP - 380 - HE_SLACK_BLOCKS) { $wantBusy[] = $r['tx_hash'] . ':0'; } }
he_ok('resumed scans converge to complete coverage',                        $s2['coverage'] === 'complete', 'rounds=' . ($round + 2));
he_ok('  having seen every output above the boundary',                      count(array_diff($wantBusy, $seen)) === 0, (count($wantBusy) - count(array_intersect($wantBusy, $seen))) . ' missing');

// After a complete scan, a new arrival costs one page, not a re-read.
$GLOBALS['he_bc']['DHeEvidenceBusy']['refs'][] = he_bc_ref('busy-new', 0, 7, HE_TIP);
$s3 = he_scan('DOGE', 'DHeEvidenceBusy', he_time_at(HE_TIP - 380), $state, 3);
he_ok('a later scan re-joins the covered range from the top page',          $s3['coverage'] === 'complete' && in_array(he_hash('busy-new') . ':0', he_keys($s3), true) && count($GLOBALS['he_requests']) === 1, count($GLOBALS['he_requests']) . ' requests');

// "Covered to the tip, unfinished, nowhere to resume" cannot be true; trusting
// it would stall the scan as incomplete for ever.
he_ok('an inconsistent saved state is not trusted',                         he_scan('DOGE', 'DHeEvidenceAddrOne', $since, array('to' => HE_TIP, 'floor' => false, 'cursor' => null, 'source' => 'blockcypher'))['coverage'] === 'complete');

// =====================================================================
echo "--- 4. duplicates and conflicts ---\n";
$GLOBALS['he_bc']['DHeEvidenceConflict'] = array('refs' => array(he_bc_ref('dup', 0, 100, HE_TIP - 2), he_bc_ref('dup', 0, 999, HE_TIP - 2)), 'unconfirmed' => array());
$s = he_scan('DOGE', 'DHeEvidenceConflict', $since);
he_ok('a disagreeing duplicate stops the scan (never the larger amount)',   $s['coverage'] === 'incomplete' && strpos($s['reason'], 'conflicting') !== false, $s['reason']);
$GLOBALS['he_bc']['DHeEvidenceSame'] = array('refs' => array(he_bc_ref('same', 0, 100, HE_TIP - 2), he_bc_ref('same', 0, 100, HE_TIP - 2)), 'unconfirmed' => array());
$s = he_scan('DOGE', 'DHeEvidenceSame', $since);
he_ok('an identical duplicate counts once',                                 $s['coverage'] === 'complete' && count($s['outputs']) === 1);
$GLOBALS['he_bc']['DHeEvidenceDouble'] = array('refs' => array(he_bc_ref('ds', 0, 100, HE_TIP - 2, array('double_spend' => true))), 'unconfirmed' => array());
he_ok('a reported double spend is incomplete, not a payment',               he_scan('DOGE', 'DHeEvidenceDouble', $since)['coverage'] === 'incomplete');

// =====================================================================
echo "--- 5. malformed answers and failures (BlockCypher) ---\n";
$bad = array(
	'a negative value'                   => array('value' => -5),
	'a fractional value'                 => array('value' => 1.5),
	'a missing value'                    => array('value' => null),
	'a confirmed reference with no time' => array('confirmed' => null),
	'a non-UTC time'                     => array('confirmed' => '2026-09-22T07:36:12+01:00'),
	'an impossible date'                 => array('confirmed' => '2026-02-30T07:36:12Z'),
	'a time in the future'               => array('confirmed' => he_iso(time() + 86400)),
	'a time before the genesis block'    => array('confirmed' => '2001-01-01T00:00:00Z'),
	'a confirmed reference at height -1' => array('block_height' => -1),
	'zero confirmations in a block'      => array('confirmations' => 0),
	'a malformed hash'                   => array('tx_hash' => 'not-a-hash'),
);
foreach ($bad as $label => $override) {
	$ref = he_bc_ref('bad', 0, 100, HE_TIP - 2);
	foreach ($override as $k => $v) { if ($v === null) { unset($ref[$k]); } else { $ref[$k] = $v; } }
	$GLOBALS['he_bc']['DHeEvidenceBad'] = array('refs' => array($ref), 'unconfirmed' => array());
	$s = he_scan('DOGE', 'DHeEvidenceBad', $since);
	he_ok("$label is incomplete, never zero",                               $s['coverage'] === 'incomplete' && $s['outputs'] === array(), $s['reason']);
}
$GLOBALS['he_bc']['DHeEvidenceBadPending'] = array('refs' => array(), 'unconfirmed' => array(array_merge(he_bc_pending('p', 0, 1), array('block_height' => 5))));
he_ok('an unconfirmed reference claiming a block is incomplete',            he_scan('DOGE', 'DHeEvidenceBadPending', $since)['coverage'] === 'incomplete');
$GLOBALS['he_bc']['DHeEvidenceWrong'] = array('raw' => array('address' => 'DSomethingElse', 'txrefs' => array()), 'refs' => array(), 'unconfirmed' => array());
he_ok('an answer about another address is incomplete',                      he_scan('DOGE', 'DHeEvidenceWrong', $since)['coverage'] === 'incomplete');
$GLOBALS['he_bc']['DHeEvidenceNoRefs'] = array('raw' => array('address' => 'DHeEvidenceNoRefs', 'hasMore' => true), 'refs' => array(), 'unconfirmed' => array());
he_ok('more history with nothing to page from is incomplete',               he_scan('DOGE', 'DHeEvidenceNoRefs', $since)['coverage'] === 'incomplete');
foreach (array('429' => 'a rate limit (429)', '500' => 'a server error (500)', 'malformed' => 'malformed JSON') as $what => $label) {
	$GLOBALS['he_fail'] = array('DHeEvidenceAddrOne' => $what);
	$s = he_scan('DOGE', 'DHeEvidenceAddrOne', $since);
	he_ok("$label is incomplete, never zero",                               $s['coverage'] === 'incomplete' && $s['outputs'] === array(), $s['reason']);
}
$GLOBALS['he_fail'] = array('DHeEvidenceAddrOne' => '500');
he_ok('an explorer failure is not unreadable (it is retried)',             he_scan('DOGE', 'DHeEvidenceAddrOne', $since)['unreadable'] === false);
$GLOBALS['he_fail'] = array();

// =====================================================================
echo "--- 6. Esplora (BTC, LTC) ---\n";
$btc = 'bc1qevidenceaddressone';
$GLOBALS['he_esplora'][$btc] = array(
	'chain' => array(
		he_esplora_tx('btc-a', HE_TIP - 4, array(array($btc, 120000), array('bc1qsomeoneelse', 999), array($btc, 30000))),
	),
	'mempool' => array(he_esplora_tx('btc-mem', 0, array(array($btc, 5000)), false)),
);
$s = he_scan('BTC', $btc, $since);
$byKey = array();
foreach ($s['outputs'] as $o) { $byKey[$o['tx_hash'] . ':' . $o['output_index']] = $o; }
he_ok('BTC: complete, from mempool.space',                                  $s['coverage'] === 'complete' && $s['source'] === 'mempool.space', $s['reason']);
he_ok('  both outputs to the address count, by their vout position',       isset($byKey[he_hash('btc-a') . ':0'], $byKey[he_hash('btc-a') . ':2']) && !isset($byKey[he_hash('btc-a') . ':1']));
he_ok('  confirmations are tip - height + 1',                               $byKey[he_hash('btc-a') . ':0']['confirmations'] === 5 && $s['tip_height'] === HE_TIP);
he_ok('  the mempool output is pending',                                    $s['pending'] && $byKey[he_hash('btc-mem') . ':0']['confirmed'] === false);

$ltc = 'LTCevidencePaged';
$chain = array();
for ($i = 0; $i < 60; $i++) { $chain[] = he_esplora_tx('ltc-' . $i, HE_TIP - 1 - $i, array(array($ltc, 100 + $i))); }
$GLOBALS['he_esplora'][$ltc] = array('chain' => $chain, 'mempool' => array());
$s = he_scan('LTC', $ltc, he_time_at(HE_TIP - 50), null, 10);
he_ok('LTC: 25-per-page chain paging is complete and exact',                $s['coverage'] === 'complete' && count($s['outputs']) === 60 && $s['source'] === 'litecoinspace.org', count($s['outputs']) . ' ' . $s['reason']);

$GLOBALS['he_esplora']['LTCrepeat'] = array('chain' => $chain, 'mempool' => array(), 'repeat_cursor' => true);
$s = he_scan('LTC', 'LTCrepeat', he_time_at(HE_TIP - 50), null, 10);
he_ok('a repeated page cursor is incomplete, and detected as such',         $s['coverage'] === 'incomplete' && strpos($s['reason'], 'repeated') !== false, $s['reason']);

$mem = array();
for ($i = 0; $i < 50; $i++) { $mem[] = he_esplora_tx('mem-' . $i, 0, array(array('bc1qfullmempool', 1)), false); }
$GLOBALS['he_esplora']['bc1qfullmempool'] = array('chain' => array(), 'mempool' => $mem);
he_ok('a full, unpageable mempool list is incomplete',                      he_scan('BTC', 'bc1qfullmempool', $since)['coverage'] === 'incomplete');

$GLOBALS['he_fail'] = array('/blocks/tip/height' => '500');
$s = he_scan('BTC', $btc, $since);
he_ok('no chain tip means no confirmation counts: incomplete',              $s['coverage'] === 'incomplete' && $s['reason'] === 'could not read the chain tip', $s['reason']);
$GLOBALS['he_fail'] = array();

echo "--- 6b. source fallback ---\n";
$GLOBALS['he_fail'] = array('mempool.space' => '500');
$s = he_scan('BTC', $btc, $since);
he_ok('an unreachable primary hands over to the next reviewed source',      $s['coverage'] === 'complete' && $s['source'] === 'blockstream.info' && $s['state']['source'] === 'blockstream.info', $s['source'] . ' ' . $s['reason']);
$GLOBALS['he_fail'] = array('litecoinspace.org' => '500');
$GLOBALS['he_bc']['LTCviaBlockCypher'] = array('refs' => array(he_bc_ref('ltc-bc', 1, 4200, HE_TIP - 3)), 'unconfirmed' => array());
$s = he_scan('LTC', 'LTCviaBlockCypher', $since);
he_ok('LTC falls back from litecoinspace to BlockCypher',                   $s['coverage'] === 'complete' && $s['source'] === 'blockcypher' && count($s['outputs']) === 1, $s['source'] . ' ' . $s['reason']);
$GLOBALS['he_fail'] = array('mempool.space' => '500', 'blockstream.info' => '500');
$saved = array('to' => HE_TIP - 50, 'floor' => false, 'cursor' => he_hash('x'), 'source' => 'mempool.space');
$s = he_scan('BTC', $btc, $since, $saved);
he_ok('with every source unreachable, saved progress is kept unchanged',    $s['coverage'] === 'incomplete' && $s['state'] === $saved && $s['outputs'] === array());
$GLOBALS['he_fail'] = array();
// Progress made by one source is never replayed against another.
$s = he_scan('BTC', $btc, $since, array('to' => HE_TIP, 'floor' => true, 'cursor' => null, 'source' => 'blockstream.info'));
he_ok('another source\'s saved cursor is not reused',                      $s['source'] === 'mempool.space' && $s['coverage'] === 'complete' && count($s['outputs']) === 3, count($s['outputs']) . ' outputs');
// A BlockCypher cursor is a block height; litecoinspace's are txids. LTC's
// primary must start its own scan rather than resume from the other's cursor.
$s = he_scan('LTC', $ltc, he_time_at(HE_TIP - 50), array('to' => HE_TIP - 1, 'floor' => false, 'cursor' => 3184000, 'source' => 'blockcypher'), 10);
he_ok('  (an LTC scan saved by BlockCypher restarts at litecoinspace)',    $s['source'] === 'litecoinspace.org' && $s['coverage'] === 'complete' && count($s['outputs']) === 60, $s['source'] . ' ' . count($s['outputs']) . ' ' . $s['reason']);
// A definite problem at the primary is final: no shopping for a nicer answer.
$GLOBALS['he_esplora']['bc1qconflictprimary'] = array('chain' => array(he_esplora_tx('cp', HE_TIP - 1, array(array('bc1qconflictprimary', 1)))), 'mempool' => array(he_esplora_tx('cp', HE_TIP - 1, array(array('bc1qconflictprimary', 1)), true)));
$s = he_scan('BTC', 'bc1qconflictprimary', $since);
he_ok('malformed data at the primary does not fall back',                   $s['coverage'] === 'incomplete' && $s['source'] === 'mempool.space', $s['source'] . ' ' . $s['reason']);

$GLOBALS['he_esplora']['bc1qconfinmem'] = array('chain' => array(), 'mempool' => array(he_esplora_tx('cm', HE_TIP - 1, array(array('bc1qconfinmem', 1)), true)));
he_ok('a confirmed transaction in the mempool list is incomplete',          he_scan('BTC', 'bc1qconfinmem', $since)['coverage'] === 'incomplete');
$GLOBALS['he_esplora']['bc1qabovetip'] = array('chain' => array(he_esplora_tx('at', HE_TIP + 5, array(array('bc1qabovetip', 1)))), 'mempool' => array());
he_ok('a block above the tip is incomplete',                                he_scan('BTC', 'bc1qabovetip', $since)['coverage'] === 'incomplete');
he_ok('an empty Esplora history is complete',                               he_scan('BTC', 'bc1qnothinghere', $since)['coverage'] === 'complete');

// =====================================================================
echo "--- 7. clean-address check (Esplora, offline) ---\n";
$zero = array('funded_txo_count' => 0, 'funded_txo_sum' => 0, 'spent_txo_count' => 0, 'spent_txo_sum' => 0, 'tx_count' => 0);
$e = function ($chain, $mempool, $addr = 'bc1qx') { return NMMPRO_Hd_Evidence::classify_esplora_stats(array('address' => 'bc1qx', 'chain_stats' => $chain, 'mempool_stats' => $mempool), $addr)['state']; };
he_ok('no activity anywhere is clean',                                      $e($zero, $zero) === 'clean');
he_ok('received and spent (balance zero) is used',                          $e(array_merge($zero, array('funded_txo_count' => 1, 'funded_txo_sum' => 5, 'spent_txo_count' => 1, 'spent_txo_sum' => 5, 'tx_count' => 2)), $zero) === 'used');
he_ok('a pending receipt is used',                                          $e($zero, array_merge($zero, array('funded_txo_count' => 1, 'tx_count' => 1))) === 'used');
he_ok('missing mempool stats are unknown',                                  NMMPRO_Hd_Evidence::classify_esplora_stats(array('address' => 'bc1qx', 'chain_stats' => $zero), 'bc1qx')['state'] === 'unknown');
he_ok('an answer about another address is unknown',                         $e($zero, $zero, 'bc1qy') === 'unknown');

// =====================================================================
echo "--- 8. listings checked against the explorer's counters ---\n";
// BlockCypher omits empty lists, so an answer naming only the address used to
// read as an unused address.
$GLOBALS['he_bc']['DHeEvidenceBare'] = array('raw' => array('address' => 'DHeEvidenceBare'), 'refs' => array(), 'unconfirmed' => array());
$s = he_scan('DOGE', 'DHeEvidenceBare', $since);
he_ok('BlockCypher: an answer naming only the address is incomplete',      $s['coverage'] === 'incomplete' && strpos($s['reason'], 'counters') !== false, $s['reason']);
$GLOBALS['he_bc']['DHeEvidenceNoList'] = array('raw' => array('address' => 'DHeEvidenceNoList', 'n_tx' => 2, 'unconfirmed_n_tx' => 0), 'refs' => array(), 'unconfirmed' => array());
$s = he_scan('DOGE', 'DHeEvidenceNoList', $since);
he_ok('  counters reporting transactions with no list: incomplete',        $s['coverage'] === 'incomplete' && $s['outputs'] === array(), $s['reason']);
$GLOBALS['he_bc']['DHeEvidenceNoPending'] = array('refs' => array(he_bc_ref('np', 0, 5, HE_TIP - 2)), 'unconfirmed' => array(), 'counters' => array('unconfirmed_n_tx' => 1));
$s = he_scan('DOGE', 'DHeEvidenceNoPending', $since);
he_ok('  a pending transaction counted but not listed: incomplete',        $s['coverage'] === 'incomplete' && strpos($s['reason'], 'pending') !== false, $s['reason']);
$GLOBALS['he_bc']['DHeEvidenceShort'] = array('refs' => array(he_bc_ref('sh', 0, 5, HE_TIP - 2)), 'unconfirmed' => array(), 'counters' => array('n_tx' => 4));
he_ok('  a last page listing fewer transactions than counted: incomplete', he_scan('DOGE', 'DHeEvidenceShort', $since)['coverage'] === 'incomplete');
$GLOBALS['he_bc']['DHeEvidenceEmptyNext'] = array('refs' => $busy, 'unconfirmed' => array(), 'truncate_after' => 0);
$s = he_scan('DOGE', 'DHeEvidenceEmptyNext', he_time_at(HE_TIP - 380), null, 10);
he_ok('  an empty continuation page: incomplete',                          $s['coverage'] === 'incomplete' && strpos($s['reason'], 'continuation') !== false, $s['reason']);
$GLOBALS['he_bc']['DHeEvidenceCutShort'] = array('refs' => $busy, 'unconfirmed' => array(), 'truncate_after' => 3);
$s = he_scan('DOGE', 'DHeEvidenceCutShort', 0, null, 10);
he_ok('  a whole history cut short on a later page: incomplete',           $s['coverage'] === 'incomplete' && strpos($s['reason'], 'shorter') !== false, $s['reason']);
he_ok('  control: the same history in full is complete',                   he_scan('DOGE', 'DHeEvidenceBusy', 0, null, 10)['coverage'] === 'complete');

$GLOBALS['he_esplora']['bc1qemptiedchain'] = array('chain' => array(he_esplora_tx('ec', HE_TIP - 3, array(array('bc1qemptiedchain', 9)))), 'mempool' => array(), 'empty_chain' => true);
$s = he_scan('BTC', 'bc1qemptiedchain', $since);
he_ok('Esplora: an empty chain list for an address with history: incomplete', $s['coverage'] === 'incomplete' && $s['outputs'] === array(), $s['reason']);
// Resuming from saved progress there is no whole-history count to compare,
// so the first page's own check is what stands between this and "complete".
$s = he_scan('BTC', 'bc1qemptiedchain', $since, array('to' => HE_TIP - 3, 'floor' => true, 'cursor' => null, 'source' => 'mempool.space'));
he_ok('  also when resuming from saved progress',                          $s['coverage'] === 'incomplete' && strpos($s['reason'], 'lists no confirmed') !== false, $s['reason']);
$GLOBALS['he_bc']['DHeEvidenceBareResumed'] = array('raw' => array('address' => 'DHeEvidenceBareResumed'), 'refs' => array(), 'unconfirmed' => array());
$s = he_scan('DOGE', 'DHeEvidenceBareResumed', $since, array('to' => HE_TIP - 3, 'floor' => true, 'cursor' => null, 'source' => 'blockcypher'));
he_ok('  (and a bare BlockCypher answer when resuming)',                   $s['coverage'] === 'incomplete', $s['reason']);
$GLOBALS['he_esplora']['bc1qhiddenmempool'] = array('chain' => array(), 'mempool' => array(he_esplora_tx('hm', 0, array(array('bc1qhiddenmempool', 9)), false)), 'hide_mempool' => true);
$s = he_scan('BTC', 'bc1qhiddenmempool', $since);
he_ok('  an empty mempool list while one is pending: incomplete',          $s['coverage'] === 'incomplete' && strpos($s['reason'], 'mempool') !== false, $s['reason']);
$GLOBALS['he_esplora']['LTCcutshort'] = array('chain' => $chain, 'mempool' => array(), 'truncate_after' => 4);
$s = he_scan('LTC', 'LTCcutshort', 0, null, 10);
he_ok('  a whole history cut short on a later page: incomplete',           $s['coverage'] === 'incomplete' && strpos($s['reason'], 'shorter') !== false, $s['reason']);
$s = he_scan('BTC', 'bc1qnothinghere', $since);
he_ok('  control: a genuinely empty address is complete',                  $s['coverage'] === 'complete' && $s['outputs'] === array(), $s['reason']);

// =====================================================================
echo "--- 9. the height floor ---\n";
// Fifty spends on the first page, one in a block whose miner wrote a time a
// month early; the payment is on the second page.
$warp = array();
for ($i = 0; $i < 50; $i++) {
	$warp[] = he_bc_ref('warp-spend-' . $i, -1, 1, HE_TIP - 1 - $i, array('tx_input_n' => 0, 'tx_output_n' => -1)
		+ ($i === 20 ? array('confirmed' => he_iso(he_time_at(HE_TIP - 5000))) : array()));
}
$warp[] = he_bc_ref('warp-pay', 0, 777, HE_TIP - 55);
$GLOBALS['he_bc']['DHeEvidenceWarp'] = array('refs' => $warp, 'unconfirmed' => array());
$warpSince = he_time_at(HE_TIP - 60);
$s = he_scan('DOGE', 'DHeEvidenceWarp', $warpSince, null, 5, HE_TIP - 60 - NMMPRO_Hd_Evidence::HEIGHT_MARGIN);
he_ok('by height, an early-stamped block does not end the scan',           $s['coverage'] === 'complete' && in_array(he_hash('warp-pay') . ':0', he_keys($s), true), count($s['outputs']) . ' ' . $s['reason']);
$s = he_scan('DOGE', 'DHeEvidenceWarp', $warpSince);
he_ok('  control: by time alone it stops above the payment',               $s['coverage'] === 'complete' && !in_array(he_hash('warp-pay') . ':0', he_keys($s), true));
$s = he_scan('DOGE', 'DHeEvidencePaged', 0, null, 10, HE_TIP - 40);
$lowest = min(array_map(function ($o) { return $o['block_height']; }, $s['outputs']));
he_ok('the height floor stops the scan below it, not at the end of history', $s['coverage'] === 'complete' && $lowest < HE_TIP - 40 && count($GLOBALS['he_requests']) === 3, 'lowest=' . $lowest . ' requests=' . count($GLOBALS['he_requests']));
$s = he_scan('DOGE', 'DHeEvidenceAddrOne', $since, null, 5, HE_TIP + 500);
he_ok('a floor above the chain tip is not trusted (BlockCypher)',           $s['coverage'] === 'incomplete' && strpos($s['reason'], 'above the chain tip') !== false, $s['reason']);
$s = he_scan('BTC', $btc, $since, null, 5, HE_TIP + 500);
he_ok('  nor through Esplora',                                              $s['coverage'] === 'incomplete' && strpos($s['reason'], 'above the chain tip') !== false, $s['reason']);

echo "--- 10. chain height and page budget ---\n";
$tipCheck = function ($coin) {
	global $wpdb;
	$wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE '%nmmpro_backoff%' OR `option_name` LIKE '%nmmpro_apifail%'");
	wp_cache_flush();
	add_filter('pre_http_request', $GLOBALS['he_mock'], 10, 3);
	try { return NMMPRO_Hd_Evidence::chain_tip($coin); } finally { remove_filter('pre_http_request', $GLOBALS['he_mock'], 10); }
};
he_ok('the chain height is read from BlockCypher (DOGE)',                   $tipCheck('DOGE')['height'] === HE_TIP);
he_ok('  and from Esplora (BTC)',                                           $tipCheck('BTC')['height'] === HE_TIP && $tipCheck('BTC')['source'] === 'mempool.space');
$GLOBALS['he_fail'] = array('api.blockcypher.com' => '500');
he_ok('  an unreadable height is null, never a guess',                      $tipCheck('DOGE')['height'] === null);
$GLOBALS['he_fail'] = array('api.blockcypher.com' => 'malformed');
he_ok('  so is a malformed answer',                                         $tipCheck('DOGE')['height'] === null);
$GLOBALS['he_fail'] = array();
$s = he_scan('DOGE', 'DHeEvidenceBusy', 0, null, 2);
he_ok('a page-budget stop says so (exhausted)',                             $s['coverage'] === 'incomplete' && $s['exhausted'] === true);
he_ok('  other incomplete answers do not',                                  he_scan('DOGE', 'DHeEvidenceBare', $since)['exhausted'] === false && he_scan('DOGE', 'DHeEvidenceOneHeight', $since)['exhausted'] === false);

echo "--- 11. counting across resumed scans ---\n";
// A resumed scan used to skip the whole-history count: a continuation page
// cut short (some references, no hasMore) passed as the end of history.
$long = array();
for ($i = 0; $i < 400; $i++) { $long[] = he_bc_ref('long-' . $i, 0, 1, HE_TIP - 1 - $i); }
$GLOBALS['he_bc']['DHeEvidenceLong'] = array('refs' => $long, 'unconfirmed' => array());
$r1 = he_scan('DOGE', 'DHeEvidenceLong', 0, null, 3);
$GLOBALS['he_bc']['DHeEvidenceLong']['truncate_after'] = 3;
$r2 = he_scan('DOGE', 'DHeEvidenceLong', 0, $r1['state'], 10);
he_ok('BlockCypher: a resumed scan cut short on a later page is incomplete', $r1['coverage'] === 'incomplete' && $r2['coverage'] === 'incomplete' && strpos($r2['reason'], 'shorter') !== false, $r2['reason']);
he_ok('  and its saved progress is dropped, so the next scan starts afresh', is_array($r2['state']) && !isset($r2['state']['to']));
unset($GLOBALS['he_bc']['DHeEvidenceLong']['truncate_after']);
$r3 = he_scan('DOGE', 'DHeEvidenceLong', 0, $r2['state'], 10);
he_ok('  which then completes',                                            $r3['coverage'] === 'complete' && count($r3['outputs']) === 400, $r3['coverage'] . ' ' . count($r3['outputs']) . ' ' . $r3['reason']);
$legacy = he_scan('DOGE', 'DHeEvidenceLong', 0, array('to' => HE_TIP - 1, 'floor' => true, 'cursor' => null, 'source' => 'blockcypher'), 10);
he_ok('  progress saved before transactions were counted is not trusted',  $legacy['coverage'] === 'complete' && count($legacy['outputs']) === 400 && count($GLOBALS['he_requests']) > 1, count($legacy['outputs']) . ' outputs, ' . count($GLOBALS['he_requests']) . ' requests');

// One transaction missing from the part a resumed scan reads: the count is
// exact, so even a single omission is caught.
$GLOBALS['he_bc']['DHeEvidenceDropOne'] = array('refs' => $long, 'unconfirmed' => array());
$d1 = he_scan('DOGE', 'DHeEvidenceDropOne', 0, null, 3);
$GLOBALS['he_bc']['DHeEvidenceDropOne']['drop'] = 'long-300';
$d2 = he_scan('DOGE', 'DHeEvidenceDropOne', 0, $d1['state'], 10);
he_ok('  a single transaction missing from a resumed scan is caught',      $d1['coverage'] === 'incomplete' && $d2['coverage'] === 'incomplete' && strpos($d2['reason'], 'shorter') !== false, $d2['coverage'] . ' ' . $d2['reason']);

// A scan that joins a finished whole history: new transactions the top page
// does not list (but the counter reports) are caught.
$small = array();
for ($i = 0; $i < 60; $i++) { $small[] = he_bc_ref('small-' . $i, 0, 1, HE_TIP - 100 - $i); }
$GLOBALS['he_bc']['DHeEvidenceHideNew'] = array('refs' => $small, 'unconfirmed' => array());
$h1 = he_scan('DOGE', 'DHeEvidenceHideNew', 0, null, 5);
for ($i = 0; $i < 5; $i++) { $GLOBALS['he_bc']['DHeEvidenceHideNew']['refs'][] = he_bc_ref('small-new-' . $i, 0, 1, HE_TIP - 10 - $i); }
$GLOBALS['he_bc']['DHeEvidenceHideNew']['hide_newest'] = 5;
$h2 = he_scan('DOGE', 'DHeEvidenceHideNew', 0, $h1['state'], 5);
he_ok('  a joined scan whose top page hides new transactions is incomplete', $h1['coverage'] === 'complete' && $h2['coverage'] === 'incomplete' && strpos($h2['reason'], 'shorter') !== false, $h2['coverage'] . ' ' . $h2['reason']);
unset($GLOBALS['he_bc']['DHeEvidenceHideNew']['hide_newest']);
$h3 = he_scan('DOGE', 'DHeEvidenceHideNew', 0, $h2['state'], 5);
he_ok('  control: listed in full, the new transactions complete it',       $h3['coverage'] === 'complete' && count($h3['outputs']) === 65, $h3['coverage'] . ' ' . count($h3['outputs']));

// The count must be exact, never short, or a genuine history would never
// complete: two pages per scan, pages cut part-way through a block, and new
// blocks arriving between scans.
$converge = function ($coin, $address, &$book, $grow) {
	$state = null; $seen = array();
	for ($round = 0; $round < 40; $round++) {
		$r = he_scan($coin, $address, 0, $state, 2);
		foreach ($r['outputs'] as $o) { $seen[$o['tx_hash'] . ':' . $o['output_index']] = true; }
		if ($r['coverage'] === 'complete') { return array($r, $seen, $round + 1); }
		if ($r['reason'] !== 'page limit reached') { return array($r, $seen, $round + 1); }
		$state = $r['state'];
		if ($grow && $round < 3) { $grow($round); }
	}
	return array($r, $seen, $round);
};
$three = array();
for ($i = 0; $i < 200; $i++) { $three[] = he_bc_ref('three-' . $i, 0, 1, HE_TIP - 40 - intdiv($i, 3)); }
$GLOBALS['he_bc']['DHeEvidenceThree'] = array('refs' => $three, 'unconfirmed' => array());
list($r, $seen, $rounds) = $converge('DOGE', 'DHeEvidenceThree', $GLOBALS['he_bc']['DHeEvidenceThree'], function ($round) {
	for ($k = 0; $k < 4; $k++) { $GLOBALS['he_bc']['DHeEvidenceThree']['refs'][] = he_bc_ref('three-new-' . $round . '-' . $k, 0, 1, HE_TIP - 30 + $round); }
});
he_ok('BlockCypher: resumed scans count exactly and converge (blocks cut, new arrivals)', $r['coverage'] === 'complete' && count($seen) === 212, $r['coverage'] . ' ' . $r['reason'] . ' seen=' . count($seen) . ' rounds=' . $rounds);
$esp = array();
for ($i = 0; $i < 100; $i++) { $esp[] = he_esplora_tx('esp3-' . $i, HE_TIP - 40 - intdiv($i, 3), array(array('LTCthreeperblock', 10 + $i))); }
$GLOBALS['he_esplora']['LTCthreeperblock'] = array('chain' => $esp, 'mempool' => array());
list($r, $seen, $rounds) = $converge('LTC', 'LTCthreeperblock', $GLOBALS['he_esplora']['LTCthreeperblock'], function ($round) {
	$GLOBALS['he_esplora']['LTCthreeperblock']['chain'][] = he_esplora_tx('esp3-new-' . $round, HE_TIP - 30 + $round, array(array('LTCthreeperblock', 5)));
});
he_ok('Esplora: resumed scans count exactly and converge (blocks cut, new arrivals)', $r['coverage'] === 'complete' && count($seen) === 103, $r['coverage'] . ' ' . $r['reason'] . ' seen=' . count($seen) . ' rounds=' . $rounds);
$bigBlock = array();
for ($i = 0; $i < 70; $i++) { $bigBlock[] = he_esplora_tx('big-' . $i, HE_TIP - 20 - ($i < 60 ? 0 : $i), array(array('LTCbigblock', 1))); }
$GLOBALS['he_esplora']['LTCbigblock'] = array('chain' => $bigBlock, 'mempool' => array());
list($r, $seen, $rounds) = $converge('LTC', 'LTCbigblock', $GLOBALS['he_esplora']['LTCbigblock'], null);
he_ok('Esplora: a block spanning several pages is counted once',            $r['coverage'] === 'complete' && count($seen) === 70, $r['coverage'] . ' ' . $r['reason'] . ' seen=' . count($seen) . ' rounds=' . $rounds);
$GLOBALS['he_esplora']['LTCcutresumed'] = array('chain' => $esp, 'mempool' => array());
$e1 = he_scan('LTC', 'LTCcutresumed', 0, null, 2);
$GLOBALS['he_esplora']['LTCcutresumed']['truncate_after'] = 4;
$e2 = he_scan('LTC', 'LTCcutresumed', 0, $e1['state'], 10);
he_ok('Esplora: a resumed scan cut short on a later page is incomplete',    $e1['coverage'] === 'incomplete' && $e2['coverage'] === 'incomplete' && strpos($e2['reason'], 'shorter') !== false, $e2['reason']);

echo "--- 12. the chain tip from BlockCypher ---\n";
he_ok('a BlockCypher scan reports the chain tip (for confirmations)',       he_scan('DOGE', 'DHeEvidenceAddrOne', $since)['tip_height'] === HE_TIP);

echo "--- 13. no transaction counted twice (independent review, round 3) ---\n";
// The reviewer's sequence: an explorer leaves out the newest transaction once
// and lists it again later. It must not come back as "new" and inflate the
// count, which would let a later omission - a payment - pass as complete.
$GLOBALS['he_bc']['DHeEvidenceFlicker'] = array('refs' => array(he_bc_ref('fl-A', 0, 1, HE_TIP - 90), he_bc_ref('fl-B', 0, 1, HE_TIP - 91)), 'unconfirmed' => array());
$f1 = he_scan('DOGE', 'DHeEvidenceFlicker', 0);
$GLOBALS['he_bc']['DHeEvidenceFlicker']['drop'] = 'fl-A';
$f2 = he_scan('DOGE', 'DHeEvidenceFlicker', 0, $f1['state']);
unset($GLOBALS['he_bc']['DHeEvidenceFlicker']['drop']);
$f3 = he_scan('DOGE', 'DHeEvidenceFlicker', 0, $f2['state']);
$GLOBALS['he_bc']['DHeEvidenceFlicker']['refs'][] = he_bc_ref('fl-C', 0, 777, HE_TIP - 80);
$GLOBALS['he_bc']['DHeEvidenceFlicker']['drop'] = 'fl-C';
$f4 = he_scan('DOGE', 'DHeEvidenceFlicker', 0, $f3['state']);
he_ok('an omitted-then-relisted transaction is not counted twice',          $f1['coverage'] === 'complete' && $f2['coverage'] === 'incomplete' && $f3['coverage'] === 'complete' && $f3['state']['n'] === 2, $f2['coverage'] . ' ' . $f3['coverage'] . ' n=' . (isset($f3['state']['n']) ? $f3['state']['n'] : '-'));
he_ok('  so a later payment left out is still caught',                     $f4['coverage'] === 'incomplete', $f4['coverage'] . ' ' . $f4['reason']);
unset($GLOBALS['he_bc']['DHeEvidenceFlicker']['drop']);
$f5 = he_scan('DOGE', 'DHeEvidenceFlicker', 0, $f4['state']);
he_ok('  control: listed properly, the payment is found',                  $f5['coverage'] === 'complete' && in_array(he_hash('fl-C') . ':0', he_keys($f5), true));

// The same with a history deeper than the recount window, so the resumed scan
// joins its saved range rather than re-reading everything.
$deep = array();
for ($i = 0; $i < 80; $i++) { $deep[] = he_bc_ref('dp-' . $i, 0, 1, HE_TIP - 100 - $i); }   // two pages: the first says hasMore
$GLOBALS['he_bc']['DHeEvidenceDeepFlicker'] = array('refs' => $deep, 'unconfirmed' => array());
$g1 = he_scan('DOGE', 'DHeEvidenceDeepFlicker', 0);
$GLOBALS['he_bc']['DHeEvidenceDeepFlicker']['drop'] = 'dp-0';
$g2 = he_scan('DOGE', 'DHeEvidenceDeepFlicker', 0, $g1['state']);
he_ok('a joined scan whose newest transaction went missing starts afresh',  $g2['coverage'] === 'incomplete' && strpos($g2['reason'], 'older than one it reported') !== false && !isset($g2['state']['to']), $g2['reason']);
unset($GLOBALS['he_bc']['DHeEvidenceDeepFlicker']['drop']);
$g3 = he_scan('DOGE', 'DHeEvidenceDeepFlicker', 0, $g2['state']);
$GLOBALS['he_bc']['DHeEvidenceDeepFlicker']['refs'][] = he_bc_ref('dp-pay', 0, 777, HE_TIP - 50);
$GLOBALS['he_bc']['DHeEvidenceDeepFlicker']['drop'] = 'dp-pay';
$g4 = he_scan('DOGE', 'DHeEvidenceDeepFlicker', 0, $g3['state']);
he_ok('  and after it returns, a later payment left out is still caught',   $g3['coverage'] === 'complete' && $g3['state']['n'] === 80 && $g4['coverage'] === 'incomplete' && strpos($g4['reason'], 'shorter') !== false, $g3['coverage'] . ' n=' . (isset($g3['state']['n']) ? $g3['state']['n'] : '-') . ' / ' . $g4['coverage']);

// Resumed coverage is advisory - a reorganisation can mislead its counts - so
// a scan says when it relied on saved progress. The verifier never claims or
// cancels on it (test-hd-verify section 19).
$reorg = array();
for ($i = 0; $i < 80; $i++) { $reorg[] = he_bc_ref('ro-' . $i, 0, 1, HE_TIP - 5 - $i); }
$GLOBALS['he_bc']['DHeEvidenceReorg'] = array('refs' => $reorg, 'unconfirmed' => array());
$o1 = he_scan('DOGE', 'DHeEvidenceReorg', 0);
$o2 = he_scan('DOGE', 'DHeEvidenceReorg', 0, $o1['state']);
he_ok('a scan made from scratch is not marked resumed',                     $o1['coverage'] === 'complete' && $o1['resumed'] === false);
he_ok('  one that joins saved progress is',                                 $o2['coverage'] === 'complete' && $o2['resumed'] === true);

// The reviewer's dense history: 150 transactions, 25 to a block, Esplora's 25
// per page, the default five-page budget. Resumed scans must converge with no
// help from new blocks.
$dense = array();
for ($i = 0; $i < 150; $i++) { $dense[] = he_esplora_tx('dense-' . $i, HE_TIP - 10 - intdiv($i, 25), array(array('LTCdense', 1 + $i))); }
$GLOBALS['he_esplora']['LTCdense'] = array('chain' => $dense, 'mempool' => array());
$state = null;
for ($round = 1; $round <= 10; $round++) {
	$dr = he_scan('LTC', 'LTCdense', 0, $state);
	if ($dr['coverage'] === 'complete') { break; }
	$state = $dr['state'];
}
he_ok('Esplora: a dense history (25 to a block) converges within the budget', $dr['coverage'] === 'complete' && count($dr['outputs']) > 0 && $round <= 3, 'rounds=' . $round . ' ' . $dr['reason']);
$denseBc = array();
for ($i = 0; $i < 400; $i++) { $denseBc[] = he_bc_ref('dbc-' . $i, 0, 1, HE_TIP - 10 - intdiv($i, 25)); }
$GLOBALS['he_bc']['DHeEvidenceDense'] = array('refs' => $denseBc, 'unconfirmed' => array());
$state = null;
for ($round = 1; $round <= 10; $round++) {
	$dr = he_scan('DOGE', 'DHeEvidenceDense', 0, $state, 3);
	if ($dr['coverage'] === 'complete') { break; }
	$state = $dr['state'];
}
// 100 on the first scan, then 50 a scan (each page repeats a block of 25): 7.
he_ok('BlockCypher: a dense history converges with a small budget',         $dr['coverage'] === 'complete' && $round === 7, 'rounds=' . $round . ' ' . $dr['reason']);

echo $GLOBALS['he_ok'] ? "\nHD-EVIDENCE CHECKS PASSED\n" : "\nHD-EVIDENCE CHECKS FAILED\n";
