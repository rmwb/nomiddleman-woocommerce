<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Gateway extends WC_Payment_Gateway {
    private $cryptos;
    private $gapLimit;

    public function __construct() {
        
        
        $cryptoArray = NMM_Cryptocurrencies::get();

        $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));  

        $this->cryptos = $cryptoArray;
        $this->gapLimit = 2;

        $this->id = 'nmmpro_gateway';
        $this->icon = apply_filters('nmm_gateway_icon', NMM_PLUGIN_DIR . '/assets/img/bitcoin_logo_small.png');        
        $this->title = sanitize_text_field($nmmSettings->get_customer_gateway_message());
        $this->has_fields = true;
        $this->method_title = __('Nomiddleman Crypto Payments', 'nomiddleman-crypto-payments-for-woocommerce');
        $this->method_description = __('Allow customers to pay using cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce');
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page'));
    }

    public function admin_options() {
        
        ?>
        <h2><?php esc_html_e('Nomiddleman Crypto Payments', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
        <div class="nmm-options">
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . NMM_REDUX_SLUG . '&tab=general')); ?>"><?php esc_html_e('Nomiddleman Plugin Settings', 'nomiddleman-crypto-payments-for-woocommerce'); ?></a>
        </div>
        <?php        
    }

    // WooCommerce Admin Payment Method Settings
    public function init_form_fields() {
                
        // general settings
        $generalSettings = array(
            'general_settings' => array(
                'title' => __('General settings', 'nomiddleman-crypto-payments-for-woocommerce'),
                'type' => 'title',
                'class' => 'section-title',
            ),
            'enabled' => array(
                'title' => __('Enable/Disable', 'nomiddleman-crypto-payments-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable cryptocurrency payments', 'nomiddleman-crypto-payments-for-woocommerce'),
                'default' => 'yes',
                'class' => 'nmm-setting',
            ),
        );
        
        $this->form_fields = $generalSettings;
    }
    
    // This runs when the user hits the checkout page
    // We load our crypto select with valid crypto currencies
    public function payment_fields() {

        $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

        $validCryptos = $nmmSettings->get_valid_selected_cryptos();
        
        foreach ($validCryptos as $crypto) {
            $cryptoId = $crypto->get_id();

            if ($nmmSettings->hd_enabled($cryptoId)) {

                $mpk = $nmmSettings->get_mpk($cryptoId);
                $hdMode = $nmmSettings->get_hd_mode($cryptoId);
                $hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);

                $count = $hdRepo->count_ready();

                if ($count < 1) {
                    try {
                        NMM_Hd::force_new_address($cryptoId, $mpk, $hdMode);                        
                    }
                    catch ( \Exception $e) {
                        NMM_Util::log(__FILE__, __LINE__, 'UNABLE TO GENERATE HD ADDRESS FOR ' . $crypto->get_name() . ' ADMIN MUST BE NOTIFIED. REMOVING CRYPTO FROM PAYMENT OPTIONS' . $e->getTraceAsString());
                        unset($validCryptos[$cryptoId]);
                    }
                }
            }
        }
        
        $selectOptions = $this->get_select_options_for_valid_cryptos($validCryptos);

        woocommerce_form_field(
            'nmm_currency_id', array(
                'type'     => 'select',                
                'label'    => __('Choose a cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce'),
                'required' => true,
                'default' => 'BTC',
                'options'  => $selectOptions,
            )
        );    
    }

    // This runs when the customer selects Place Order, before process_payment, has nothing to do with the other validation methods
    public function validate_fields() {
        // if the currently selected gateway is this gateway we set transients related to conversions and if something goes wrong we prevent the customer from hitting the thank you page  by throwing the WooCommerce Error Notice.
        if (WC()->session->get('chosen_payment_method') === $this->id) {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout nonce before invoking gateway hooks.
            if (empty($_POST['nmm_currency_id'])) {
                wc_add_notice(__('Please choose a cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
                return;
            }
            try {
                $chosenCryptoId = sanitize_text_field($_POST['nmm_currency_id']);
                if (!array_key_exists($chosenCryptoId, $this->cryptos)) {
                    wc_add_notice(__('Please choose a valid cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
                    return;
                }
                $crypto = $this->cryptos[$chosenCryptoId];
                $curr = get_woocommerce_currency();
                $cryptoPerUsd = $this->get_crypto_value_in_usd($crypto->get_id(), $crypto->get_update_interval());
                
                // this is just a check to make sure we can hit the currency exchange if we need to
                $usdTotal = NMM_Exchange::get_order_total_in_usd(1.0, $curr);
            }
            catch ( \Exception $e) {
                NMM_Util::log(__FILE__, __LINE__, $e->getMessage());
                wc_add_notice($e->getMessage(), 'error');
            }
            // phpcs:enable
        }
    }

    // This is called when the user clicks Place Order, after validate_fields
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Classic checkout posts nmm_currency_id directly; the Blocks checkout
        // delivers it via the Store API's paymentMethodData, which WooCommerce
        // also surfaces through $_POST for legacy gateways.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce/Store API verify their own nonces before process_payment runs.
        if (empty($_POST['nmm_currency_id']) || !array_key_exists(sanitize_text_field($_POST['nmm_currency_id']), $this->cryptos)) {
            wc_add_notice(__('Please choose a cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $selectedCryptoId = sanitize_text_field($_POST['nmm_currency_id']);
        // phpcs:enable
        WC()->session->set('chosen_crypto_id', $selectedCryptoId);
        $order->update_meta_data('nmm_chosen_crypto_id', $selectedCryptoId);
        $order->save();

        return array(
                      'result' => 'success',
                      'redirect'  => $this->get_return_url( $order ),
                    );
    }

    // This is called after process payment, when the customer places the order
    public function thank_you_page($order_id) {
        $cssPath = NMM_PLUGIN_DIR . '/assets/css/nmm-thank-you-page.css';
        wp_enqueue_style('nmm-styles', $cssPath);
        wp_enqueue_script('nmm-pay', NMM_PLUGIN_DIR . '/assets/js/nmm-pay.js', array(), NMM_VERSION, true);
        wp_localize_script('nmm-pay', 'nmmPayI18n', array(
            'confirmInWallet' => __('Confirm the payment in your wallet…', 'nomiddleman-crypto-payments-for-woocommerce'),
            /* translators: %s: truncated transaction hash */
            'txSent' => __('Transaction sent (%s…). Waiting for the network to confirm — this page updates automatically.', 'nomiddleman-crypto-payments-for-woocommerce'),
            'unknownNetwork' => __('Your wallet does not know this network. Please add it in your wallet and try again.', 'nomiddleman-crypto-payments-for-woocommerce'),
            'cancelledInWallet' => __('Payment cancelled in wallet.', 'nomiddleman-crypto-payments-for-woocommerce'),
            'walletFailed' => __('Could not start the wallet payment. You can still pay by scanning the QR code or copying the address.', 'nomiddleman-crypto-payments-for-woocommerce'),
            'paid' => __('Payment received — thank you!', 'nomiddleman-crypto-payments-for-woocommerce'),
            /* translators: 1: amount received, 2: amount expected */
            'partial' => __('Partial payment received: %1$s of %2$s. Please send the remaining amount to the same address.', 'nomiddleman-crypto-payments-for-woocommerce'),
        ));
        
        try {
            $order = wc_get_order($order_id);
            $existingWalletAddress = $order->get_meta('wallet_address');

            // if we already set this then we are on a page refresh, so handle refresh
            if (!empty($existingWalletAddress)) {

                if ($order->is_paid()) {
                    echo '<p class="nmm-status-paid">' . esc_html__('Payment received - thank you! Your order is being processed.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
                    return;
                }

                // Do not re-display payment instructions for an order that is no
                // longer awaiting payment. Its address may have been recycled to
                // a different order, so a late payment would credit someone else.
                if ($order->has_status(array('cancelled', 'failed'))) {
                    echo '<p class="nmm-status-cancelled">' . esc_html__('This order is no longer awaiting payment. Please do not send any funds to the address shown previously. If you believe this is an error, contact the store.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
                    return;
                }

                $this->handle_thank_you_refresh(
                    $order->get_meta('crypto_type_id'),
                    $existingWalletAddress,
                    $order->get_meta('crypto_amount'),
                    $order_id);

                return;
            }

            $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

            $chosenCryptoId = $order->get_meta('nmm_chosen_crypto_id');
            if (empty($chosenCryptoId)) {
                $chosenCryptoId = WC()->session->get('chosen_crypto_id');
            }

            if (empty($chosenCryptoId) || !array_key_exists($chosenCryptoId, $this->cryptos)) {
                throw new \Exception(esc_html__('We could not determine which cryptocurrency you selected. Please return to checkout and place the order again.', 'nomiddleman-crypto-payments-for-woocommerce'));
            }

            $crypto = $this->cryptos[$chosenCryptoId];
            $cryptoId = $crypto->get_id();

            $order->update_meta_data('crypto_type_id', $cryptoId);
            // get current price of crypto

            $cryptoPerUsd = $this->get_crypto_value_in_usd($cryptoId, $crypto->get_update_interval());
            
            // handle different woocommerce currencies and get the order total in USD
            $curr = get_woocommerce_currency(); 

            $usdTotal = NMM_Exchange::get_order_total_in_usd($order->get_total(), $curr);            
            
            $cryptoMarkupPercent = $nmmSettings->get_markup($cryptoId);

            if (!is_numeric($cryptoMarkupPercent)) {
                $cryptoMarkupPercent = 0.0;
            }

            $cryptoMarkup = $cryptoMarkupPercent / 100.0;            
            $cryptoPriceRatio = 1.0 + $cryptoMarkup;            
            $cryptoTotalPreMarkup = round($usdTotal / $cryptoPerUsd, $crypto->get_round_precision(), PHP_ROUND_HALF_UP);            
            $cryptoTotal = $cryptoTotalPreMarkup * $cryptoPriceRatio;

            $dustAmount = apply_filters('nmm_dust_amount', 0.000000000000000000, $cryptoId, $cryptoPerUsd, $crypto->get_round_precision(), $usdTotal, $cryptoTotal);
            //error_log('filter dust amount: ' . $dustAmount);
            //error_log('cryptoTotal pre-dust: ' . $cryptoTotal);
            $cryptoTotal += $dustAmount;
            //error_log('cryptoTotal post-dust: ' . $cryptoTotal);
            
            // format the crypto amount based on crypto
            $formattedCryptoTotal = NMM_Cryptocurrencies::get_price_string($cryptoId, $cryptoTotal);

            $order->update_meta_data('crypto_amount', $formattedCryptoTotal);

            NMM_Util::log(__FILE__, __LINE__, 'Crypto total: ' . $cryptoTotal . ' Formatted Total: ' . $formattedCryptoTotal);

            // if hd is enabled we have stuff to do
            if ($nmmSettings->hd_enabled($cryptoId)) {
                $mpk = $nmmSettings->get_mpk($cryptoId);
                $hdMode = $nmmSettings->get_hd_mode($cryptoId);
                $hdRepo = new NMM_Hd_Repo($cryptoId, $mpk, $hdMode);

                // Atomically claim the oldest ready address for this order.
                $orderWalletAddress = $hdRepo->claim_oldest_ready($order_id, $formattedCryptoTotal);

                // if none was available, derive one and try to claim again
                if (!$orderWalletAddress) {
                    try {
                        NMM_Hd::force_new_address($cryptoId, $mpk, $hdMode);
                        $orderWalletAddress = $hdRepo->claim_oldest_ready($order_id, $formattedCryptoTotal);
                    }
                    catch ( \Exception $e) {
                        throw new \Exception(esc_html__('Unable to get payment address for order. This order has been cancelled. Please try again or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce') . ' ' . esc_html($e->getMessage()));
                    }
                }

                if (!$orderWalletAddress) {
                    throw new \Exception(esc_html__('Unable to get payment address for order. This order has been cancelled. Please try again or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
                }

                // keep the session copy other code paths still read
                WC()->session->set('hd_wallet_address', $orderWalletAddress);

                $orderNote = sprintf(
                    /* translators: 1: wallet address, 2: amount, 3: cryptocurrency ticker */
                    __('Privacy Mode (HD wallet) address %1$s is awaiting payment of %2$s %3$s.', 'nomiddleman-crypto-payments-for-woocommerce'),
                    $orderWalletAddress,
                    $formattedCryptoTotal,
                    $cryptoId);
                
            }
            // HD is not enabled, just handle static wallet or carousel mode
            else {
                if ($cryptoId === 'XMR' && $nmmSettings->autopay_enabled('XMR')) {
                    // fresh subaddress per order from the merchant's wallet RPC
                    $orderWalletAddress = NMM_Monero::create_subaddress($order_id);
                }
                else {
                    $orderWalletAddress = $nmmSettings->get_next_carousel_address($cryptoId);
                }

                // handle payment verification feature
                if ($nmmSettings->autopay_enabled($cryptoId)) {
                    $paymentRepo = new NMM_Payment_Repo();

                    $paymentRepo->insert($orderWalletAddress, $cryptoId, $order_id, $formattedCryptoTotal, 'unpaid');
                }

                $orderNote = sprintf(
                    /* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address */
                    __('Awaiting payment of %1$s %2$s to payment address %3$s.', 'nomiddleman-crypto-payments-for-woocommerce'),
                    $formattedCryptoTotal,
                    $cryptoId,
                    $orderWalletAddress);
            }
            
            // For email
            WC()->session->set($cryptoId . '_amount', $formattedCryptoTotal);

            // For customer reference and to handle refresh of thank you page
            $order->update_meta_data('wallet_address', $orderWalletAddress);


            // Emails are fired once we update status to on-hold, so hook additional email details here
            add_action('woocommerce_email_order_details', array( $this, 'additional_email_details' ), 10, 4);
            
            $order->update_status('wc-on-hold', $orderNote);

            // Output additional thank you page html
            $this->output_thank_you_html($crypto, $orderWalletAddress, $formattedCryptoTotal, $order_id);
        }
        catch ( \Exception $e ) {
            $order = wc_get_order($order_id);

            // cancel order if something went wrong
            /* translators: %s: error message */
            $order->update_status('wc-failed', sprintf(__('Error Message: %s', 'nomiddleman-crypto-payments-for-woocommerce'), $e->getMessage()));
            NMM_Util::log(__FILE__, __LINE__, 'Something went wrong during checkout: ' . $e->getMessage());
            echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
            echo '<ul class="woocommerce-error">';
            echo '<li>';
            echo esc_html__('Something went wrong.', 'nomiddleman-crypto-payments-for-woocommerce') . '<br>';
            echo esc_html($e->getMessage());
            echo '</li>';
            echo '</ul>';
            echo '</div>';
        }
    }

    public function additional_email_details($order, $sent_to_admin, $plain_text, $email) {
        $chosenCrypto = $order->get_meta('nmm_chosen_crypto_id');
        if (empty($chosenCrypto)) {
            $chosenCrypto = WC()->session->get('chosen_crypto_id');
        }
        if (empty($chosenCrypto) || !array_key_exists($chosenCrypto, $this->cryptos)) {
            return; // nothing reliable to attach; the order note still has details
        }
        $crypto =  $this->cryptos[$chosenCrypto];
        $orderCryptoTotal = WC()->session->get($crypto->get_id() . '_amount');
        $orderWalletAddress = $order->get_meta('wallet_address');
        $orderId = $order->get_id();

        $formattedTotal = NMM_Cryptocurrencies::get_price_string($crypto->get_id(), $orderCryptoTotal);
        $totalLine = ($crypto->get_symbol() === '')
            ? $formattedTotal . ' ' . $crypto->get_id()
            : $crypto->get_symbol() . $formattedTotal;

        if ($plain_text) {
            echo "\n" . esc_html__('PAYMENT DETAILS', 'nomiddleman-crypto-payments-for-woocommerce') . "\n\n";
            echo esc_html__('Address:', 'nomiddleman-crypto-payments-for-woocommerce') . ' ' . esc_html($orderWalletAddress) . "\n";
            echo esc_html__('Currency:', 'nomiddleman-crypto-payments-for-woocommerce') . ' ' . esc_html($crypto->get_name()) . "\n";
            echo esc_html__('Total:', 'nomiddleman-crypto-payments-for-woocommerce') . ' ' . esc_html($totalLine) . "\n";
            echo esc_html__('Scan a QR code for this payment on your order page:', 'nomiddleman-crypto-payments-for-woocommerce') . ' ' . esc_url($order->get_checkout_order_received_url()) . "\n\n";
            return;
        }

        $qrData = NMM_Qr::payment_uri($crypto, $orderWalletAddress, $orderCryptoTotal);

        // embedded as an inline (CID) attachment when PHPMailer sends this
        // email; mailers that bypass PHPMailer simply show the text details
        $cid = NMM_Qr::stash_email_image($orderId, $qrData);

        ?>
        <h2><?php esc_html_e('Additional Details', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
        <?php if ($cid !== '') : ?>
        <p><?php esc_html_e('QR Code Payment:', 'nomiddleman-crypto-payments-for-woocommerce'); ?> </p>
        <div style="margin-bottom:12px;">
            <img src="cid:<?php echo esc_attr($cid); ?>" width="196" height="196" alt="<?php esc_attr_e('Payment QR code', 'nomiddleman-crypto-payments-for-woocommerce'); ?>" />
        </div>
        <?php endif; ?>
        <p>
            <?php esc_html_e('Address:', 'nomiddleman-crypto-payments-for-woocommerce'); ?> <?php echo esc_html($orderWalletAddress) ?>
        </p>
        <p>
            <?php esc_html_e('Currency:', 'nomiddleman-crypto-payments-for-woocommerce'); ?> <?php echo '<img src="' . esc_url($crypto->get_logo_file_path()) . '" alt="" />' . esc_html($crypto->get_name()); ?>
        </p>
        <p>
            <?php esc_html_e('Total:', 'nomiddleman-crypto-payments-for-woocommerce'); ?> <?php echo esc_html($totalLine); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($order->get_checkout_order_received_url()); ?>"><?php esc_html_e('View payment details and QR code on your order page', 'nomiddleman-crypto-payments-for-woocommerce'); ?></a>
        </p>
        <?php
    }

    // convert array of cryptos to option array
    private function get_select_options_for_valid_cryptos() {
        $selectOptionArray = array();

        $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));
        
        foreach (NMM_Cryptocurrencies::get_alpha() as $crypto) {
            if ($nmmSettings->crypto_selected_and_valid($crypto->get_id())) {
                $selectOptionArray[$crypto->get_id()] = $crypto->get_name();
            }            
        }

        return $selectOptionArray;
    }

    private function output_thank_you_html($crypto, $orderWalletAddress, $cryptoTotal, $orderId) {
        $formattedPrice = NMM_Cryptocurrencies::get_price_string($crypto->get_id(), $cryptoTotal);
        $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

        $customerMessage = apply_filters('nmm_customer_message', $nmmSettings->get_customer_payment_message($crypto), $crypto, $orderId, $formattedPrice, $orderWalletAddress);

        $qrData = NMM_Qr::payment_uri($crypto, $orderWalletAddress, $cryptoTotal);

        // admin-entered HTML; allow post-safe markup but never scripts
        echo wp_kses_post($customerMessage);
        ?>

        <h2><?php esc_html_e('Cryptocurrency payment details', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
        <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
            <li class="woocommerce-order-overview__qr-code">
                <p style="word-wrap: break-word;"><?php esc_html_e('QR Code payment:', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
                <div class="qr-code-container">
                    <?php echo NMM_Qr::svg($qrData, 200); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SVG markup generated in memory by this plugin. ?>
                </div>
            </li>
            <li>
                <p style="word-wrap: break-word;"><?php esc_html_e('Wallet Address:', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
                    <strong>
                        <span class="woocommerce-Price-amount amount">
                            <?php echo '<span class="all-copy">' . esc_html($orderWalletAddress) . '</span>' ?>
                        </span>
                    </strong>
                </p>
            </li>
            <li>
                <p><?php esc_html_e('Currency:', 'nomiddleman-crypto-payments-for-woocommerce'); ?> 
                    <strong>
                        <?php
                            echo '<img style="display:inline;height:23px;width:23px;vertical-align:middle;" src="' . esc_url($crypto->get_logo_file_path()) . '" />';
                        ?>
                        <span style="padding-left: 4px; vertical-align: middle;" class="woocommerce-Price-amount amount" style="vertical-align: middle;">
                            <?php echo esc_html($crypto->get_name()) ?>
                        </span>
                    </strong>
                </p>
            </li>
            <li>
                <p style="word-wrap: break-word;"><?php esc_html_e('Total:', 'nomiddleman-crypto-payments-for-woocommerce'); ?> 
                    <strong>
                        <span class="woocommerce-Price-amount amount">
                            <?php 
                                if ($crypto->get_symbol() === '') {
                                    echo '<span class="all-copy">' . esc_html($formattedPrice) . '</span><span class="no-copy">&nbsp;' . esc_html($crypto->get_id()) . '</span>';
                                }
                                else {
                                    echo '<span class="no-copy">' . esc_html($crypto->get_symbol()) . '</span>' . '<span class="all-copy">' . esc_html($formattedPrice) . '</span>';
                                }
                            ?>
                        </span>
                    </strong>
                </p>
            </li>
        </ul>
        
        <?php
        $order = wc_get_order($orderId);
        $orderKey = $order ? $order->get_order_key() : '';

        $isEvm = ($crypto->get_id() === 'ETH') || ($crypto->is_erc20_token() && $crypto->get_id() !== 'USDTTRX');
        ?>
        <div class="nmm-pay-actions">
            <?php if ($isEvm) : ?>
                <button type="button" id="nmm-wallet-pay" class="button alt" style="display:none;"
                        data-to="<?php echo esc_attr($orderWalletAddress); ?>"
                        data-contract="<?php echo esc_attr($crypto->is_erc20_token() ? $crypto->get_erc20_contract() : ''); ?>"
                        data-chain="<?php echo esc_attr(NMM_Cryptocurrencies::evm_chain_id($crypto->get_id())); ?>"
                        data-units="<?php echo esc_attr(NMM_Qr::to_base_units($cryptoTotal, $crypto->get_round_precision())); ?>">
                    <?php esc_html_e('Pay in browser wallet', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
                </button>
                <p id="nmm-wallet-msg" aria-live="polite"></p>
            <?php elseif ($crypto->get_id() === 'SOL') : ?>
                <p><a class="button alt" href="<?php echo esc_url($qrData); ?>"><?php esc_html_e('Open in Solana wallet', 'nomiddleman-crypto-payments-for-woocommerce'); ?></a></p>
            <?php endif; ?>

            <p id="nmm-payment-status" class="nmm-payment-status"
               data-order="<?php echo esc_attr($orderId); ?>"
               data-key="<?php echo esc_attr($orderKey); ?>"
               data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <?php esc_html_e('Waiting for payment… this page updates automatically.', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
            </p>
        </div>
        <?php
    }

    private function handle_thank_you_refresh($chosenCrypto, $orderWalletAddress, $cryptoTotal, $orderId) {
        $this->output_thank_you_html($this->cryptos[$chosenCrypto], $orderWalletAddress, $cryptoTotal, $orderId);
    }

    // this function hits all the crypto exchange APIs that the user selected, then averages them and returns a conversion rate for USD
    // if the user has selected no exchanges to fetch data from it instead takes the average from all of them
    private function get_crypto_value_in_usd($cryptoId, $updateInterval) {
        $reduxSettings = get_option(NMM_REDUX_ID);
        if (!array_key_exists('selected_price_apis', $reduxSettings)) {
            throw new \Exception(esc_html__('No price API selected. Please contact plug-in support.', 'nomiddleman-crypto-payments-for-woocommerce'));
        }

        return NMM_Exchange::get_average_usd_price($cryptoId, $updateInterval, $reduxSettings['selected_price_apis']);
    }
}

?>