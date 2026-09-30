<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only "Privacy Mode" panel on the WooCommerce order screen (legacy post
 * storage and HPOS alike): the order's payment address, what state it is in,
 * why it is held or retired, the amount due and the amount attributable to
 * this order, and the transactions that paid it. Everything printed is
 * escaped; nothing here changes an order.
 */
class NMMPRO_Hd_Order_Panel {

	/**
	 * Hooked on add_meta_boxes. WordPress passes ($postType, $post) on the
	 * legacy screen; WooCommerce's HPOS screen passes ($screenId, $order).
	 */
	public static function register($screen, $object = null) {
		$orderId = self::order_id($object);
		if ($orderId === 0 || self::row($orderId) === null) {
			return;
		}
		$hposScreen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
		foreach (array('shop_order', $hposScreen) as $target) {
			if ($screen === $target) {
				add_meta_box('nmmpro-hd-payment', __('Privacy Mode payment', 'nomiddleman-crypto-payments-for-woocommerce'),
					array(__CLASS__, 'render'), $target, 'side', 'default');
			}
		}
	}

	public static function render($object) {
		$orderId = self::order_id($object);
		$row = $orderId ? self::row($orderId) : null;
		if ($row === null) {
			return;
		}

		$cryptos = NMMPRO_Cryptocurrencies::get();
		$precision = isset($cryptos[$row['cryptocurrency']]) ? $cryptos[$row['cryptocurrency']]->get_round_precision() : 8;
		$credited = $row['credited_units'] === null ? null : NMMPRO_Amount::from_units($row['credited_units'], $precision);
		$order = wc_get_order($orderId);
		$outputs = $order ? $order->get_meta(NMMPRO_Hd_Verifier::TX_META) : array();
		$outputs = is_array($outputs) ? $outputs : array();

		$states = array(
			'assigned'   => __('Awaiting payment', 'nomiddleman-crypto-payments-for-woocommerce'),
			'underpaid'  => __('Partly paid', 'nomiddleman-crypto-payments-for-woocommerce'),
			'completing' => __('Completing', 'nomiddleman-crypto-payments-for-woocommerce'),
			'complete'   => __('Paid', 'nomiddleman-crypto-payments-for-woocommerce'),
			'review'     => __('Held for manual review', 'nomiddleman-crypto-payments-for-woocommerce'),
			'retired'    => __('Retired (never reused)', 'nomiddleman-crypto-payments-for-woocommerce'),
		);
		$state = isset($states[$row['status']]) ? $states[$row['status']] : (string) $row['status'];
		$validated = $row['assignment_version'] !== null;
		?>
		<p><strong><?php echo esc_html($state); ?></strong></p>
		<?php if ($row['review_reason'] !== null && $row['review_reason'] !== '') : ?>
			<p><?php echo esc_html(NMMPRO_Hd::reason_label($row['review_reason'])); ?></p>
		<?php endif; ?>
		<?php if (!$validated) : ?>
			<p><?php esc_html_e('Assigned by an earlier release: payments to this address are not verified automatically.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
		<?php endif; ?>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e('Address', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td><code><?php echo esc_html($row['address']); ?></code> (<?php echo esc_html($row['cryptocurrency']); ?>)</td></tr>
				<tr><th scope="row"><?php esc_html_e('Amount due', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td><?php echo esc_html(NMMPRO_Amount::normalize($row['order_amount'])); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e('Received for this order', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
					<td><?php echo esc_html($credited === null ? '-' : $credited); ?></td></tr>
				<?php if ($row['bound_at'] !== null) : ?>
					<tr><th scope="row"><?php esc_html_e('Issued (UTC)', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
						<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $row['bound_at'])); ?></td></tr>
				<?php endif; ?>
				<?php if ((int) $row['last_checked'] > 0) : ?>
					<tr><th scope="row"><?php esc_html_e('Last checked (UTC)', 'nomiddleman-crypto-payments-for-woocommerce'); ?></th>
						<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $row['last_checked'])); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php if ($outputs !== array()) : ?>
			<p><?php esc_html_e('Paid by (transaction:output):', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
			<ul>
				<?php foreach ($outputs as $output) : ?>
					<li><code><?php echo esc_html((string) $output); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="description"><?php esc_html_e('"Received for this order" counts only confirmed payments made after this address was issued. Funds the address received earlier never count.', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
		<?php
	}

	private static function order_id($object) {
		if ($object instanceof WC_Order) {
			return (int) $object->get_id();
		}
		if ($object instanceof WP_Post) {
			return (int) $object->ID;
		}
		return 0;
	}

	/** The order's most recent Privacy Mode address row, or null. */
	private static function row($orderId) {
		global $wpdb;
		if (!NMMPRO_Hd_Schema::ready()) {
			return null; // the columns below arrive with schema 1.5
		}
		$table = $wpdb->prefix . NMMPRO_HD_TABLE;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT `address`, `cryptocurrency`, `status`, `review_reason`, `order_amount`, `credited_units`, `bound_at`, `last_checked`, `assignment_version`
			 FROM `$table` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1",
			$orderId
		), ARRAY_A);
		return is_array($row) ? $row : null;
	}
}
