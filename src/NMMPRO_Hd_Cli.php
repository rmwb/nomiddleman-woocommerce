<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy Mode (HD) operator commands.
 *
 * `wp nmmpro-hd audit` is read-only. `wp nmmpro-hd reconcile` records a
 * person's verified reconciliation and changes nothing without --yes.
 * See docs/HD-RECONCILIATION.md.
 */
class NMMPRO_Hd_Cli {

	/**
	 * Report Privacy Mode state and findings. Read-only.
	 *
	 * Writes nothing to the plugin's tables, options or orders. With --chain it
	 * makes read-only explorer requests (within --max-scans) and never records
	 * their answers; a failed or incomplete answer is reported as unknown.
	 * Output carries order ids, statuses and amounts, never customer details.
	 *
	 * ## OPTIONS
	 *
	 * [--order=<id>]
	 * : Only this order's address records.
	 *
	 * [--address=<address>]
	 * : Only this address.
	 *
	 * [--coin=<ticker>]
	 * : Only this cryptocurrency.
	 *
	 * [--limit=<n>]
	 * : Records per page (default 100, at most 1000).
	 *
	 * [--after=<row>]
	 * : Continue after this record id (printed as the next cursor).
	 *
	 * [--chain]
	 * : Also read each address's full history from its explorers (read-only).
	 *
	 * [--max-scans=<n>]
	 * : Explorer budget for --chain (default 50); records past it are marked chain_skipped.
	 *
	 * [--findings-only]
	 * : List only records with findings.
	 *
	 * [--format=<format>]
	 * : table (default), json or csv. json includes the summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nmmpro-hd audit
	 *     wp nmmpro-hd audit --order=1234 --chain
	 *     wp nmmpro-hd audit --coin=DOGE --chain --findings-only --format=csv > doge-audit.csv
	 *
	 * @when after_wp_load
	 */
	public function audit($args, $assoc) {
		$page = NMMPRO_Hd_Audit::rows(array(
			'order'     => isset($assoc['order']) ? $assoc['order'] : '',
			'address'   => isset($assoc['address']) ? $assoc['address'] : '',
			'coin'      => isset($assoc['coin']) ? $assoc['coin'] : '',
			'limit'     => isset($assoc['limit']) ? $assoc['limit'] : NMMPRO_Hd_Audit::DEFAULT_LIMIT,
			'after'     => isset($assoc['after']) ? $assoc['after'] : 0,
			'chain'     => !empty($assoc['chain']),
			'max_scans' => isset($assoc['max-scans']) ? $assoc['max-scans'] : NMMPRO_Hd_Audit::DEFAULT_MAX_SCANS,
		));
		$rows = $page['rows'];
		if (!empty($assoc['findings-only'])) {
			$rows = array_values(array_filter($rows, function ($r) { return $r['findings'] !== array(); }));
		}
		$format = isset($assoc['format']) ? $assoc['format'] : 'table';

		if ($format === 'json') {
			WP_CLI::line(wp_json_encode(array(
				'summary' => NMMPRO_Hd_Audit::summary(), 'records' => $rows, 'next' => $page['next'],
				'explorer_scans' => $page['scans'], 'legend' => NMMPRO_Hd_Audit::finding_labels(),
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}

		$flat = array_map(function ($r) { $r['findings'] = implode(' ', $r['findings']); return $r; }, $rows);
		if ($format === 'csv') {
			WP_CLI\Utils\format_items('csv', $flat, array_keys(self::columns()));
			return;
		}

		self::print_summary(NMMPRO_Hd_Audit::summary());
		WP_CLI::line('');
		if ($flat === array()) {
			WP_CLI::line('No address records matched.');
		}
		else {
			WP_CLI\Utils\format_items('table', $flat, array('row', 'coin', 'address', 'order', 'order_status', 'expected', 'credited', 'lifetime', 'state', 'reason', 'findings'));
		}
		$seen = array();
		foreach ($rows as $r) { foreach ($r['findings'] as $f) { $seen[$f] = true; } }
		foreach (array_intersect_key(NMMPRO_Hd_Audit::finding_labels(), $seen) as $code => $label) {
			WP_CLI::line(sprintf('  %-26s %s', $code, $label));
		}
		if ($page['next'] !== null) {
			WP_CLI::line('');
			WP_CLI::line('More records: repeat with --after=' . $page['next']);
		}
	}

	/**
	 * Record a manual reconciliation of a Privacy Mode order.
	 *
	 * Records the order as the owner of the given transactions (verified on a
	 * block explorer by you) in the ledger shared with Autopay, so they can
	 * never pay another order; with --complete, then completes an order that is
	 * still awaiting payment. Refuses a transaction already owned elsewhere.
	 * Shows the plan and changes nothing unless --yes is given.
	 *
	 * ## OPTIONS
	 *
	 * --order=<id>
	 * : The order.
	 *
	 * --tx=<txid>
	 * : A transaction id (optionally txid:output). Separate several with commas.
	 *
	 * [--complete]
	 * : Also complete the order if it is still awaiting payment.
	 *
	 * [--yes]
	 * : Carry it out. Without this, only the plan is shown.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nmmpro-hd reconcile --order=1234 --tx=<txid>
	 *     wp nmmpro-hd reconcile --order=1234 --tx=<txid>:1,<txid2> --complete --yes
	 *
	 * @when after_wp_load
	 */
	public function reconcile($args, $assoc) {
		$txs = array_filter(array_map('trim', explode(',', isset($assoc['tx']) ? (string) $assoc['tx'] : '')));
		try {
			$result = NMMPRO_Hd_Reconcile::run(isset($assoc['order']) ? $assoc['order'] : 0, $txs, !empty($assoc['complete']), !empty($assoc['yes']));
		}
		catch (\Throwable $t) {
			WP_CLI::error($t->getMessage());
			return;
		}

		WP_CLI::line(sprintf('Order %d, %s address %s', $result['order'], $result['coin'], $result['address']));
		foreach ($result['actions'] as $action) {
			WP_CLI::line('  - ' . $action);
		}
		if (!$result['applied']) {
			WP_CLI::warning('Dry run: nothing was changed. Verify the transactions on a block explorer, then repeat with --yes.');
			return;
		}
		WP_CLI::success(sprintf('Recorded %d transaction(s)%s; order %s.',
			count($result['result']['recorded']),
			$result['result']['already'] ? ' (' . count($result['result']['already']) . ' already owned by this order)' : '',
			$result['result']['order_paid'] ? 'is paid' : 'is not paid'));
	}

	private static function columns() {
		return array_flip(array('row', 'coin', 'address', 'index', 'order', 'order_status', 'prior_orders', 'expected', 'credited', 'lifetime', 'assigned_utc', 'bound_utc', 'state', 'reason', 'findings', 'chain'));
	}

	private static function print_summary($s) {
		WP_CLI::line(sprintf('Plugin %s; Privacy Mode schema %s (%s)', $s['plugin_version'], $s['hd_schema'], $s['schema_ready'] ? 'ready' : 'NOT ready: automatic Privacy Mode is off'));
		if (is_array($s['upgrade_legacy'])) {
			WP_CLI::line(sprintf('Upgrade on %s UTC: retired %d pool and %d quarantined address(es); held %d in-flight order(s) for review.',
				gmdate('Y-m-d H:i', (int) $s['upgrade_legacy']['at']), $s['upgrade_legacy']['retired_pool'], $s['upgrade_legacy']['retired_quarantine'], $s['upgrade_legacy']['review_unvalidated']));
		}
		foreach ($s['coins'] as $id => $c) {
			WP_CLI::line(sprintf('  %-5s automatic: %-3s %s%s', $id, $c['automatic'] ? 'yes' : 'no',
				$c['automatic'] ? '(' . $c['source'] . ')' : $c['reason'],
				$c['configured_privacy'] && $c['unavailable_reason'] !== null ? '  [configured for Privacy Mode but unavailable: ' . $c['unavailable_reason'] . ']' : ''));
		}
		foreach ($s['wallets'] as $id => $w) {
			if (is_array($w)) {
				WP_CLI::line(sprintf('  %-5s wallet: highest index issued %s, derived %s; %d issued and retired unpaid',
					$id, $w['issued'] === null ? '-' : $w['issued'], $w['derived'] === null ? '-' : $w['derived'], $w['unused_issued']));
			}
		}
		foreach ($s['states'] as $st) {
			WP_CLI::line(sprintf('  %-5s %-10s %-22s %d', $st['coin'], $st['state'], $st['reason'], $st['count']));
		}
	}
}
