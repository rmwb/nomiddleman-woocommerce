<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function NMM_change_cancelled_email_note_subject_line($subject, $order) {
	/* translators: %d: order number */
	$subject = sprintf(__('Order %d has been cancelled due to non-payment', 'nomiddleman-crypto-payments-for-woocommerce'), $order->get_id());

	return $subject;

}

function NMM_change_cancelled_email_heading($heading, $order) {
	$heading = __('Your order has been cancelled. Do not send any cryptocurrency to the payment address.', 'nomiddleman-crypto-payments-for-woocommerce');

	return $heading;
}

function NMM_change_partial_email_note_subject_line($subject, $order) {
	/* translators: %d: order number */
	$subject = sprintf(__('Partial payment received for Order %d', 'nomiddleman-crypto-payments-for-woocommerce'), $order->get_id());

	return $subject;
}

function NMM_change_partial_email_heading($heading, $order) {
	/* translators: %d: order number */
	$heading = sprintf(__('Partial payment received for Order %d', 'nomiddleman-crypto-payments-for-woocommerce'), $order->get_id());

	return $heading;
}

function NMM_update_database_when_admin_changes_order_status( $orderId, $oldOrderStatus, $newOrderStatus ) {	
  
	$paymentAmount = 0.0;

	$order = wc_get_order($orderId);

	if (!$order) {
		return;
	}

	$paymentAmount = $order->get_meta('crypto_amount');

	// this order was not made by us
	if (empty($paymentAmount)) {
		return;
	}
	

	$paymentRepo = new NMM_Payment_Repo();

	// If admin updates from needs-payment to has-payment, stop looking for matching transactions
	if ($oldOrderStatus === 'pending' && $newOrderStatus === 'processing') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'paid');
	}
	if ($oldOrderStatus === 'pending' && $newOrderStatus === 'completed') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'paid');
	}
	if ($oldOrderStatus === 'on-hold' && $newOrderStatus === 'processing') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'paid');
	}
	if ($oldOrderStatus === 'on-hold' && $newOrderStatus === 'completed') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'paid');
	}

	// If admin updates from has-payment to needs-payment, start looking for matching transactions
	if ($oldOrderStatus === 'processing' && $newOrderStatus === 'pending') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
	}
	if ($oldOrderStatus === 'processing' && $newOrderStatus === 'on-hold') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
	}
	if ($oldOrderStatus === 'completed' && $newOrderStatus === 'pending') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
	}
	if ($oldOrderStatus === 'completed' && $newOrderStatus === 'on-hold') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
	}

	// If admin updates from needs-payment to cancelled, stop looking for matching transactions
	if ($oldOrderStatus === 'pending' && $newOrderStatus === 'cancelled') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'cancelled');
	}
	if ($oldOrderStatus === 'pending' && $newOrderStatus === 'failed') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'cancelled');
	}
	if ($oldOrderStatus === 'on-hold' && $newOrderStatus === 'cancelled') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'cancelled');
	}
	if ($oldOrderStatus === 'on-hold' && $newOrderStatus === 'failed') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'cancelled');
	}

	// If admin updates from cancelled to needs-payment, start looking for matching transactions
	if ($oldOrderStatus === 'cancelled' && $newOrderStatus === 'on-hold') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
		$paymentRepo->set_ordered_at($orderId, $paymentAmount, time());
	}
	if ($oldOrderStatus === 'cancelled' && $newOrderStatus === 'pending') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
		$paymentRepo->set_ordered_at($orderId, $paymentAmount, time());
	}
	if ($oldOrderStatus === 'failed' && $newOrderStatus === 'on-hold') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
		$paymentRepo->set_ordered_at($orderId, $paymentAmount, time());
	}
	if ($oldOrderStatus === 'failed' && $newOrderStatus === 'pending') {
		$paymentRepo->set_status($orderId, $paymentAmount, 'unpaid');
		$paymentRepo->set_ordered_at($orderId, $paymentAmount, time());
	}
}

// Order-key-authenticated payment status for the thank-you page poller.
// The key is only known to the customer who placed the order.
function NMM_order_status_ajax() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the WooCommerce order key below, which only the purchaser has.
	$orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
	$key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
	// phpcs:enable

	$order = wc_get_order($orderId);

	if (!$order || $key === '' || !hash_equals($order->get_order_key(), $key)) {
		wp_send_json_error(null, 403);
	}

	$paid = $order->is_paid();
	$underpaid = false;
	$received = '';
	$expected = '';

	if (!$paid) {
		global $wpdb;
		$tableName = $wpdb->prefix . NMM_HD_TABLE;

		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT `status`, `total_received`, `order_amount` FROM `$tableName` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1",
			$orderId
		), ARRAY_A);

		if ($row && $row['status'] === 'underpaid') {
			$underpaid = true;
			$received = $row['total_received'];
			$expected = $row['order_amount'];
		}
	}

	wp_send_json_success(array(
		'paid' => $paid,
		'underpaid' => $underpaid,
		'received' => $received,
		'expected' => $expected,
	));
}

function NMM_first_mpk_address_ajax() {

		check_ajax_referer('nmm_first_mpk_address'); // phpcs:ignore -- the referer check IS the nonce verification for the $_POST reads below.

		if (!current_user_can('manage_options')) {
			wp_die('', '', 403);
		}

		if (!isset($_POST) || !is_array($_POST) || !array_key_exists('mpk', $_POST) || !array_key_exists('cryptoId', $_POST)) {
			return;
		}

		$mpk = sanitize_text_field($_POST['mpk']);
		$cryptoId = sanitize_text_field($_POST['cryptoId']);
		$hdMode = sanitize_text_field($_POST['hdMode']);		
		
		if (!NMM_Hd::is_valid_mpk($cryptoId, $mpk)) {
			return;
		}
		
		if (!NMM_Util::p_enabled() && (NMM_Hd::is_valid_ypub($mpk) || NMM_Hd::is_valid_zpub($mpk))) {
			$message = __('You have entered a valid Segwit MPK.', 'nomiddleman-crypto-payments-for-woocommerce');
			$message2 = __('Segwit MPKs (ypub/zpub) are not supported - please use an xpub.', 'nomiddleman-crypto-payments-for-woocommerce');

			echo json_encode([$message, $message2, '']);
			wp_die();
		}
		else {
			$firstAddress = NMM_Hd::create_hd_address($cryptoId, $mpk, 0, $hdMode);
			$secondAddress = NMM_Hd::create_hd_address($cryptoId, $mpk, 1, $hdMode);
			$thirdAddress = NMM_Hd::create_hd_address($cryptoId, $mpk, 2, $hdMode);

			echo json_encode([$firstAddress, $secondAddress, $thirdAddress]);

			wp_die();
		}
}

function NMM_filter_gateways($gateways){	
    global $woocommerce;
    
    $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

    foreach (NMM_Cryptocurrencies::get() as $crypto) {
        if ($nmmSettings->crypto_selected_and_valid($crypto->get_id())) {
        	$gateways[] = 'NMM_Gateway';
            return $gateways;
        }
    }
    
    if (is_checkout()) {
	    unset($gateways['NMM_Gateway']);
	}
	else {
		$gateways[] = 'NMM_Gateway';
	}

    return $gateways;
}
?>