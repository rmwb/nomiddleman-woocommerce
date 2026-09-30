<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual reconciliation of a Privacy Mode order (docs/HD-RECONCILIATION.md).
 *
 * A person has verified on a block explorer which transactions belong to the
 * order. This records the order as their owner in the transaction ledger
 * shared with Autopay - before any payment is acknowledged - so the same
 * funds can never be credited to another order, then optionally completes
 * the order. It never verifies the chain itself, never touches another order,
 * and never returns an address to the pool. Without $apply it only reports
 * what it would do.
 */
class NMMPRO_Hd_Reconcile {

	/**
	 * @param string[] $transactions "txhash" or "txhash:output"
	 * @return array{applied: bool, order: int, coin: string, address: string, owners: array, actions: string[], result: array}
	 * @throws RuntimeException When the request cannot be carried out; nothing is changed.
	 */
	public static function run($orderId, $transactions, $complete, $apply) {
		global $wpdb;
		$orderId = (int) $orderId;

		$hashes = array();
		$identities = array();
		foreach ((array) $transactions as $tx) {
			$tx = strtolower(trim((string) $tx, " \n\r\t\v\x00"));
			if (!preg_match('/^([0-9a-f]{64})(?::(\d{1,9}))?$/', $tx, $m)) {
				throw new RuntimeException(esc_html('Not a transaction id: ' . $tx . ' (expected 64 hex characters, optionally :output).'));
			}
			$hashes[$m[1]] = true;
			$identities[] = $tx;
		}
		if ($hashes === array()) {
			throw new RuntimeException('Name at least one transaction with --tx.');
		}
		$hashes = array_keys($hashes);

		$read = NMMPRO_Payment::read_order_authoritatively($orderId);
		if ($read['state'] !== 'ok') {
			throw new RuntimeException(esc_html('Order ' . $orderId . ' could not be read (' . $read['state'] . ').'));
		}
		$order = $read['order'];

		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$row = $wpdb->get_row($wpdb->prepare("SELECT `id`, `cryptocurrency`, `address`, `status` FROM `$table` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1", $orderId), ARRAY_A);
		if (!is_array($row)) {
			throw new RuntimeException(esc_html('Order ' . $orderId . ' has no Privacy Mode address.'));
		}

		$owners = NMMPRO_Consumed_Repo::owners($row['cryptocurrency'], $row['address'], $hashes);
		$actions = array();
		foreach ($owners as $hash => $owner) {
			if ($owner === null) {
				$actions[] = 'record ' . $hash . ' as owned by order ' . $orderId;
			}
			elseif ($owner === $orderId) {
				$actions[] = $hash . ' is already owned by order ' . $orderId;
			}
			else {
				throw new RuntimeException(esc_html('Transaction ' . $hash . ' is already recorded against ' . ($owner === 0 ? 'an unknown earlier owner' : 'order ' . $owner) . '. It cannot be credited to order ' . $orderId . '.'));
			}
		}

		$paid = $order->is_paid();
		if ($complete && !$paid) {
			if (!NMMPRO_Hd::order_awaits_payment($order)) {
				throw new RuntimeException(esc_html('Order ' . $orderId . ' is ' . $order->get_status() . ' and is not completed automatically. Change its status yourself if the goods should be released.'));
			}
			$actions[] = 'complete order ' . $orderId . ' (payment_complete with ' . $hashes[0] . ')';
		}
		$actions[] = 'settle address record ' . $row['id'] . ' as paid once the order is paid; the address is never reused';

		$plan = array(
			'applied' => false, 'order' => $orderId, 'coin' => $row['cryptocurrency'], 'address' => $row['address'],
			'owners' => $owners, 'actions' => $actions, 'result' => array(),
		);
		if (!$apply) {
			return $plan;
		}

		// Ownership first: after this the funds belong to this order whatever
		// happens next.
		$recorded = NMMPRO_Consumed_Repo::record_manual($row['cryptocurrency'], $row['address'], $orderId, $hashes);

		if ($complete && !$paid) {
			try {
				$order->payment_complete($hashes[0]);
			}
			catch (\Throwable $t) {
				// Reported below: the order is checked, not the return value.
				NMMPRO_Util::log(__FILE__, __LINE__, 'Manual reconciliation of order ' . $orderId . ': payment_complete raised: ' . $t->getMessage(), 'error');
			}
			$after = NMMPRO_Payment::read_order_authoritatively($orderId);
			if ($after['state'] !== 'ok' || !$after['order']->is_paid()) {
				throw new RuntimeException(esc_html('Ownership was recorded, but WooCommerce did not mark order ' . $orderId . ' paid. Check the order and complete it by hand.'));
			}
			$order = $after['order'];
			$paid = true;
		}

		$existing = $order->get_meta(NMMPRO_Hd_Verifier::TX_META);
		$existing = is_array($existing) ? $existing : array();
		$order->update_meta_data(NMMPRO_Hd_Verifier::TX_META, array_values(array_unique(array_merge($existing, $identities))));
		$order->save();
		$order->add_order_note(sprintf(
			/* translators: 1: transaction ids */
			__('Privacy Mode payment reconciled manually: transaction(s) %1$s recorded as belonging to this order (they can never be credited to another). Recorded with WP-CLI.', 'nomiddleman-crypto-payments-for-woocommerce'),
			implode(', ', $identities)));

		$settled = false;
		if ($paid) {
			$settled = $wpdb->query($wpdb->prepare(
				"UPDATE `$table` SET `status` = 'complete'
				 WHERE `id` = %d AND `order_id` = %d AND `status` IN ('assigned', 'underpaid', 'completing', 'review')",
				$row['id'], $orderId
			)) === 1;
		}

		$plan['applied'] = true;
		$plan['result'] = array('recorded' => $recorded['recorded'], 'already' => $recorded['already'], 'order_paid' => $paid, 'record_settled' => $settled);
		return $plan;
	}
}
