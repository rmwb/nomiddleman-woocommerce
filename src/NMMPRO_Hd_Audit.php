<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only Privacy Mode (HD) audit: what the upgrade did, what each coin can
 * do, and every address record with its findings - including, with chain
 * checks, previously paid orders whose evidence is absent or contradicts the
 * payment, kept apart from confirmed false positives.
 *
 * It writes nothing to the plugin's tables, options or orders. Chain checks
 * (opt-in) are read-only explorer requests within a budget; their answers are
 * never recorded as evidence, and a failure or incomplete answer is reported
 * as unknown - never as proof that a customer did or did not pay. Orders are
 * read through WooCommerce's API, so HPOS and legacy storage both work.
 * Output carries order ids, statuses and amounts, never customer details.
 *
 * NMMPRO_Hd_Cli prints it; tests call it directly.
 */
class NMMPRO_Hd_Audit {

	const DEFAULT_LIMIT = 100;
	const MAX_LIMIT = 1000;
	const DEFAULT_MAX_SCANS = 50;
	const SCAN_PAGES = 10;

	/** Finding codes and what they mean (also the report's legend). */
	public static function finding_labels() {
		return array(
			'missing_provenance'          => 'Assigned by a release that judged payments by lifetime totals; no validated binding.',
			'reused_address'              => 'The record lists more than one order: the address was issued again after an earlier order.',
			'order_missing'               => 'The order this address was issued to no longer exists.',
			'row_order_disagree'          => 'The address record and the order disagree about whether it was paid.',
			'paid_no_evidence'            => 'The order is paid but no credited transaction was recorded for it.',
			'false_positive_confirmed'    => 'The order is paid, but every receipt at its address was confirmed before the order existed: a confirmed false positive.',
			'paid_evidence_absent'        => 'The order is paid, but the address has received nothing at all.',
			'paid_underfunded'            => 'The order is paid, but less than the amount due arrived after the order existed.',
			'credited_evidence_missing'   => 'A transaction credited to this order is no longer reported on chain.',
			'unpaid_but_funded'           => 'The order is not paid, but at least the amount due arrived after the order existed.',
			'pool_row_has_history'        => 'An unissued pool address already has chain history.',
			'chain_unknown'               => 'The chain could not be read completely (provider failure or incomplete answer): unknown, not zero.',
			'chain_skipped'               => 'Not checked on chain: the request budget for this run was used up.',
		);
	}

	/** Versions, the upgrade's legacy counts, capabilities and state totals. */
	public static function summary() {
		global $wpdb;
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$settings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID, array()));

		$coins = array();
		foreach (NMMPRO_Hd_Evidence::capability_matrix() as $id => $capability) {
			$coins[$id] = array(
				'automatic'          => $capability['automatic'],
				'source'             => $capability['source'],
				'reason'             => $capability['reason'],
				'configured_privacy' => $settings->crypto_selected($id) && $settings->hd_enabled($id),
				'unavailable_reason' => NMMPRO_Hd::automatic_unavailable_reason($id),
			);
		}

		$counts = array();
		foreach ((array) $wpdb->get_results("SELECT `cryptocurrency`, `status`, `review_reason`, COUNT(*) AS n FROM `$table` GROUP BY `cryptocurrency`, `status`, `review_reason` ORDER BY `cryptocurrency`, `status`", ARRAY_A) as $r) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb's prefix and a plugin constant; no variable input.
			$counts[] = array('coin' => $r['cryptocurrency'], 'state' => $r['status'], 'reason' => (string) $r['review_reason'], 'count' => (int) $r['n']);
		}

		$wallets = array();
		foreach (NMMPRO_Cryptocurrencies::get() as $crypto) {
			$id = $crypto->get_id();
			if ($crypto->has_hd() && $settings->crypto_selected($id) && $settings->hd_enabled($id) && $settings->get_mpk($id) !== '' && NMMPRO_Hd_Schema::ready()) {
				$repo = new NMMPRO_Hd_Repo($id, $settings->get_mpk($id), $settings->get_hd_mode($id));
				$wallets[$id] = $repo->index_summary();
			}
		}

		return array(
			'plugin_version'      => defined('NMMPRO_VERSION') ? NMMPRO_VERSION : '',
			'hd_schema'           => NMMPRO_Compat::get_option(NMMPRO_Hd_Schema::VERSION_OPTION, '1.0'),
			'schema_ready'        => NMMPRO_Hd_Schema::ready(),
			'upgrade_legacy'      => NMMPRO_Compat::get_option(NMMPRO_Hd_Schema::LEGACY_REPORT_OPTION, null),
			'coins'               => $coins,
			'states'              => $counts,
			'allocation_limits'   => NMMPRO_Compat::get_option(NMMPRO_Hd::ALLOCATION_LIMIT_OPTION, array()),
			'wallets'             => $wallets,
		);
	}

	/**
	 * Address records with findings, oldest first, in pages.
	 *
	 * $args: order, address, coin (filters); limit; after (row id cursor);
	 * chain (bool, read-only explorer checks); max_scans (explorer budget).
	 *
	 * @return array{rows: array[], next: int|null, scans: int}
	 */
	public static function rows($args = array()) {
		global $wpdb;
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$limit = max(1, min(self::MAX_LIMIT, isset($args['limit']) ? (int) $args['limit'] : self::DEFAULT_LIMIT));
		$after = isset($args['after']) ? max(0, (int) $args['after']) : 0;
		$chain = !empty($args['chain']);
		$budget = isset($args['max_scans']) ? max(0, (int) $args['max_scans']) : self::DEFAULT_MAX_SCANS;
		$ready = NMMPRO_Hd_Schema::ready();

		$order = isset($args['order']) && $args['order'] !== '' ? (int) $args['order'] : 0;
		$address = isset($args['address']) ? (string) $args['address'] : '';
		$coin = isset($args['coin']) ? strtoupper((string) $args['coin']) : '';

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM `$table`
			 WHERE `id` > %d AND (%d = 0 OR `order_id` = %d) AND (%s = '' OR `address` = %s) AND (%s = '' OR `cryptocurrency` = %s)
			 ORDER BY `id` LIMIT %d",
			$after, $order, $order, $address, $address, $coin, $coin, $limit + 1
		), ARRAY_A);
		$rows = is_array($rows) ? $rows : array();

		$next = null;
		if (count($rows) > $limit) {
			array_pop($rows);
			$next = (int) $rows[count($rows) - 1]['id'];
		}

		$scans = 0;
		$out = array();
		foreach ($rows as $row) {
			$out[] = self::inspect($row, $ready, $chain, $budget, $scans);
		}

		return array('rows' => $out, 'next' => $next, 'scans' => $scans);
	}

	private static function inspect($row, $ready, $chain, $budget, &$scans) {
		$cryptos = NMMPRO_Cryptocurrencies::get();
		$precision = isset($cryptos[$row['cryptocurrency']]) ? $cryptos[$row['cryptocurrency']]->get_round_precision() : 8;
		$orderId = $row['order_id'] === null ? 0 : (int) $row['order_id'];
		$validated = $ready && isset($row['assignment_version']);

		$order = null;
		$orderState = '';
		$orderCreated = null;
		$paid = false;
		if ($orderId > 0) {
			$read = NMMPRO_Payment::read_order_authoritatively($orderId);
			$orderState = $read['state'];
			if ($read['state'] === 'ok') {
				$order = $read['order'];
				$paid = $order->is_paid();
				$created = $order->get_date_created();
				$orderCreated = $created ? $created->getTimestamp() : null;
			}
		}

		$findings = array();
		if ($orderId > 0 && !$validated) {
			$findings[] = 'missing_provenance';
		}

		$prior = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $row['all_order_ids'])))));
		if (count($prior) > 1 || ($prior !== array() && ($orderId === 0 || !in_array($orderId, $prior, true)))) {
			$findings[] = 'reused_address';
		}

		if ($orderId > 0 && $orderState === 'absent') {
			$findings[] = 'order_missing';
		}
		if ($order !== null) {
			$rowPaid = $row['status'] === 'complete';
			$rowOpen = in_array($row['status'], array('assigned', 'underpaid', 'completing'), true);
			if (($rowPaid && !$paid) || ($rowOpen && $paid)) {
				$findings[] = 'row_order_disagree';
			}
		}

		$credited = array();
		if ($validated) {
			$evidence = NMMPRO_Hd_Evidence_Repo::rows((int) $row['id']);
			foreach (is_array($evidence) ? $evidence : array() as $e) {
				if ($e['state'] === NMMPRO_Hd_Evidence_Repo::CREDITED) {
					$credited[$e['tx_hash'] . ':' . $e['output_index']] = true;
				}
			}
			if ($paid && $credited === array() && $row['status'] === 'complete') {
				$findings[] = 'paid_no_evidence';
			}
		}

		$chainSummary = '';
		if ($chain) {
			if ($scans >= $budget) {
				$findings[] = 'chain_skipped';
			}
			elseif (!NMMPRO_Hd_Evidence::has_adapter($row['cryptocurrency'])) {
				$findings[] = 'chain_unknown';
				$chainSummary = 'no evidence adapter for ' . $row['cryptocurrency'];
			}
			else {
				$scans++;
				list($chainFindings, $chainSummary) = self::chain_findings($row, $orderId, $validated, $orderCreated, $paid, $credited, $precision);
				$findings = array_merge($findings, $chainFindings);
			}
		}

		return array(
			'row'          => (int) $row['id'],
			'coin'         => $row['cryptocurrency'],
			'address'      => $row['address'],
			'index'        => (int) $row['mpk_index'],
			'order'        => $orderId > 0 ? $orderId : null,
			'order_status' => $order !== null ? $order->get_status() : ($orderId > 0 ? '(' . $orderState . ')' : ''),
			'prior_orders' => implode(',', $prior),
			'expected'     => NMMPRO_Amount::normalize((string) $row['order_amount']),
			'credited'     => ($ready && isset($row['credited_units'])) ? NMMPRO_Amount::from_units($row['credited_units'], $precision) : '',
			'lifetime'     => NMMPRO_Amount::normalize((string) $row['total_received']),
			'assigned_utc' => (int) $row['assigned_at'] > 0 ? gmdate('Y-m-d H:i:s', (int) $row['assigned_at']) : '',
			'bound_utc'    => ($validated && $row['bound_at'] !== null) ? gmdate('Y-m-d H:i:s', (int) $row['bound_at']) : '',
			'state'        => $row['status'],
			'reason'       => $ready && isset($row['review_reason']) ? (string) $row['review_reason'] : '',
			'findings'     => array_values(array_unique($findings)),
			'chain'        => $chainSummary,
		);
	}

	/**
	 * Read-only chain classification. The boundary is the validated binding,
	 * or - for an address assigned before bindings existed - the moment the
	 * ORDER was created (from WooCommerce): a receipt confirmed before its
	 * order existed cannot be payment for it. The whole history is read, so
	 * "everything arrived before the order" is a complete statement.
	 */
	private static function chain_findings($row, $orderId, $validated, $orderCreated, $paid, $credited, $precision) {
		if ($orderId === 0) {
			$activity = NMMPRO_Hd_Evidence::address_activity($row['cryptocurrency'], $row['address']);
			if ($activity['state'] === NMMPRO_Hd_Evidence::UNKNOWN) {
				return array(array('chain_unknown'), $activity['reason']);
			}
			return array($activity['state'] === NMMPRO_Hd_Evidence::USED && $row['status'] === 'ready' ? array('pool_row_has_history') : array(), $activity['state']);
		}

		$scan = NMMPRO_Hd_Evidence::scan($row['cryptocurrency'], $row['address'], 0, null, self::SCAN_PAGES);
		if ($scan['coverage'] !== NMMPRO_Hd_Evidence::COMPLETE) {
			return array(array('chain_unknown'), $scan['reason']);
		}

		$boundary = $validated && $row['bound_at'] !== null ? (int) $row['bound_at'] : $orderCreated;
		if ($boundary === null) {
			return array(array('chain_unknown'), 'no boundary: the order could not be read');
		}

		$after = '0';
		$before = '0';
		$present = array();
		foreach ($scan['outputs'] as $o) {
			$present[$o['tx_hash'] . ':' . $o['output_index']] = true;
			if ($o['block_time'] === null || $o['block_time'] >= $boundary) {
				$after = NMMPRO_Amount::add($after, $o['amount_units']);
			}
			else {
				$before = NMMPRO_Amount::add($before, $o['amount_units']);
			}
		}
		$expected = NMMPRO_Amount::to_units((string) $row['order_amount'], $precision);

		$findings = array();
		if (array_diff_key($credited, $present) !== array()) {
			$findings[] = 'credited_evidence_missing';
		}
		if ($paid) {
			if ($after === '0' && $before !== '0') {
				$findings[] = 'false_positive_confirmed';
			}
			elseif ($after === '0') {
				$findings[] = 'paid_evidence_absent';
			}
			elseif (NMMPRO_Amount::compare($after, $expected) < 0) {
				$findings[] = 'paid_underfunded';
			}
		}
		elseif ($expected !== '0' && NMMPRO_Amount::compare($after, $expected) >= 0 && $row['status'] !== 'complete') {
			$findings[] = 'unpaid_but_funded';
		}

		$summary = sprintf('after order: %s; before order: %s; outputs: %d; source: %s',
			NMMPRO_Amount::from_units($after, $precision), NMMPRO_Amount::from_units($before, $precision), count($scan['outputs']), $scan['source']);
		return array($findings, $summary);
	}
}
