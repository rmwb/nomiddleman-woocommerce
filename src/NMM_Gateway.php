<?php

class NMM_Gateway extends WC_Payment_Gateway {
    private $cryptos;
    private $gapLimit;

    public function __construct() {
        
        
        $cryptoArray = NMM_Cryptocurrencies::get();

        $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));  

        $this->cryptos = $cryptoArray;
        $this->gapLimit = 2;

        $this->id = 'nmmpro_gateway';        
        $this->title = $nmmSettings->get_customer_gateway_message();
        $this->has_fields = true;
        $this->method_title = 'Nomiddleman Crypto Payments';
        $this->method_description = 'Allow customers to pay using cryptocurrency';
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page'));
    }

    public function admin_options() {
        
        ?>
        <h2>Nomiddleman Crypto Payments</h2>
        <div class="nmm-options">
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . NMM_REDUX_SLUG . '&tab=general')); ?>">Nomiddleman Plugin Settings</a>
        </div>
        <?php        
    }

    // WooCommerce Admin Payment Method Settings
    public function init_form_fields() {
                
        // general settings
        $generalSettings = array(
            'general_settings' => array(
                'title' => 'General settings',
                'type' => 'title',
                'class' => 'section-title',
            ),
            'enabled' => array(
                'title' => 'Enable/Disable', 'woocommerce',
                'type' => 'checkbox',
                'label' => 'Enable cryptocurrency payments', 'woocommerce',
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
                'label'    => 'Choose a cryptocurrency',
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
            if (empty($_POST['nmm_currency_id'])) {
                wc_add_notice('Please choose a cryptocurrency.', 'error');
                return;
            }
            try {
                $chosenCryptoId = sanitize_text_field($_POST['nmm_currency_id']);
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
        }
    }

    // This is called when the user clicks Place Order, after validate_fields
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Classic checkout posts nmm_currency_id directly; the Blocks checkout
        // delivers it via the Store API's paymentMethodData, which WooCommerce
        // also surfaces through $_POST for legacy gateways.
        if (empty($_POST['nmm_currency_id']) || !array_key_exists(sanitize_text_field($_POST['nmm_currency_id']), $this->cryptos)) {
            wc_add_notice('Please choose a cryptocurrency.', 'error');
            return array('result' => 'failure');
        }

        $selectedCryptoId = sanitize_text_field($_POST['nmm_currency_id']);
        WC()->session->set('chosen_crypto_id', $selectedCryptoId);

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
        
        try {
            $order = wc_get_order($order_id);
            $existingWalletAddress = $order->get_meta('wallet_address');

            // if we already set this then we are on a page refresh, so handle refresh
            if (!empty($existingWalletAddress)) {

                if ($order->is_paid()) {
                    echo '<p class="nmm-status-paid">Payment received - thank you! Your order is being processed.</p>';
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

            $chosenCryptoId = WC()->session->get('chosen_crypto_id');
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

                // get fresh hd wallet
                $orderWalletAddress = $hdRepo->get_oldest_ready();
                
                // if we couldnt find a fresh one, force a new one
                if (!$orderWalletAddress) {
                    
                    try {
                        NMM_Hd::force_new_address($cryptoId, $mpk, $hdMode);
                        $orderWalletAddress = $hdRepo->get_oldest_ready();
                    }
                    catch ( \Exception $e) {
                        throw new \Exception('Unable to get payment address for order. This order has been cancelled. Please try again or contact the site administrator... Inner Exception: ' . $e->getMessage());
                    }
                }

                // set hd wallet address to get later
                WC()->session->set('hd_wallet_address', $orderWalletAddress);

                // update the database
                $hdRepo->set_status($orderWalletAddress, 'assigned');
                $hdRepo->set_order_id($orderWalletAddress, $order_id);
                $hdRepo->set_order_amount($orderWalletAddress, $formattedCryptoTotal);

                $orderNote = sprintf(
                    'Privacy Mode (HD wallet) address %s is awaiting payment of %s %s.',
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
                    'Awaiting payment of %s %s to payment address %s.',
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
            $order->update_status('wc-failed', 'Error Message: ' . $e->getMessage());
            NMM_Util::log(__FILE__, __LINE__, 'Something went wrong during checkout: ' . $e->getMessage());
            echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
            echo '<ul class="woocommerce-error">';
            echo '<li>';
            echo 'Something went wrong.<br>';
            echo $e->getMessage();
            echo '</li>';
            echo '</ul>';
            echo '</div>';
        }
    }

    public function additional_email_details($order, $sent_to_admin, $plain_text, $email) {
        $chosenCrypto = WC()->session->get('chosen_crypto_id');
        $crypto =  $this->cryptos[$chosenCrypto];
        $orderCryptoTotal = WC()->session->get($crypto->get_id() . '_amount');
        $orderWalletAddress = $order->get_meta('wallet_address');
        $orderId = $order->get_id();

        $formattedTotal = NMM_Cryptocurrencies::get_price_string($crypto->get_id(), $orderCryptoTotal);
        $totalLine = ($crypto->get_symbol() === '')
            ? $formattedTotal . ' ' . $crypto->get_id()
            : $crypto->get_symbol() . $formattedTotal;

        if ($plain_text) {
            echo "\nPAYMENT DETAILS\n\n";
            echo 'Address: ' . $orderWalletAddress . "\n";
            echo 'Currency: ' . $crypto->get_name() . "\n";
            echo 'Total: ' . $totalLine . "\n";
            echo 'Scan a QR code for this payment on your order page: ' . $order->get_checkout_order_received_url() . "\n\n";
            return;
        }

        $qrData = NMM_Qr::payment_uri($crypto, $orderWalletAddress, $orderCryptoTotal);

        // embedded as an inline (CID) attachment when PHPMailer sends this
        // email; mailers that bypass PHPMailer simply show the text details
        $cid = NMM_Qr::stash_email_image($orderId, $qrData);

        ?>
        <h2>Additional Details</h2>
        <?php if ($cid !== '') : ?>
        <p>QR Code Payment: </p>
        <div style="margin-bottom:12px;">
            <img src="cid:<?php echo esc_attr($cid); ?>" width="196" height="196" alt="Payment QR code" />
        </div>
        <?php endif; ?>
        <p>
            Address: <?php echo esc_html($orderWalletAddress) ?>
        </p>
        <p>
            Currency: <?php echo '<img src="' . esc_url($crypto->get_logo_file_path()) . '" alt="" />' . esc_html($crypto->get_name()); ?>
        </p>
        <p>
            Total: <?php echo esc_html($totalLine); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($order->get_checkout_order_received_url()); ?>">View payment details and QR code on your order page</a>
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

        <h2>Cryptocurrency payment details</h2>
        <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
            <li class="woocommerce-order-overview__qr-code">
                <p style="word-wrap: break-word;">QR Code payment:</p>
                <div class="qr-code-container">
                    <?php echo NMM_Qr::svg($qrData, 200); // built in memory, no file written ?>
                </div>
            </li>
            <li>
                <p style="word-wrap: break-word;">Wallet Address:
                    <strong>
                        <span class="woocommerce-Price-amount amount">
                            <?php echo '<span class="all-copy">' . esc_html($orderWalletAddress) . '</span>' ?>
                        </span>
                    </strong>
                </p>
            </li>
            <li>
                <p>Currency: 
                    <strong>
                        <?php
                            echo '<img style="display:inline;height:23px;width:23px;vertical-align:middle;" src="' . $crypto->get_logo_file_path() . '" />';
                        ?>
                        <span style="padding-left: 4px; vertical-align: middle;" class="woocommerce-Price-amount amount" style="vertical-align: middle;">
                            <?php echo $crypto->get_name() ?>
                        </span>
                    </strong>
                </p>
            </li>
            <li>
                <p style="word-wrap: break-word;">Total: 
                    <strong>
                        <span class="woocommerce-Price-amount amount">
                            <?php 
                                if ($crypto->get_symbol() === '') {
                                    echo '<span class="all-copy">' . $formattedPrice . '</span><span class="no-copy">&nbsp' . $crypto->get_id() . '</span>';
                                }
                                else {
                                    echo '<span class="no-copy">' . $crypto->get_symbol() . '</span>' . '<span class="all-copy">' . $formattedPrice . '</span>';
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
                    Pay in browser wallet
                </button>
                <p id="nmm-wallet-msg" aria-live="polite"></p>
            <?php elseif ($crypto->get_id() === 'SOL') : ?>
                <p><a class="button alt" href="<?php echo esc_url($qrData); ?>">Open in Solana wallet</a></p>
            <?php endif; ?>

            <p id="nmm-payment-status" class="nmm-payment-status"
               data-order="<?php echo esc_attr($orderId); ?>"
               data-key="<?php echo esc_attr($orderKey); ?>"
               data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                Waiting for payment&hellip; this page updates automatically.
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

        $prices = array();
        $reduxSettings = get_option(NMM_REDUX_ID);
        if (!array_key_exists('selected_price_apis', $reduxSettings)) {
            throw new \Exception('No price API selected. Please contact plug-in support.');
        }

        $selectedPriceApis = $reduxSettings['selected_price_apis'];

        if (in_array('0', $selectedPriceApis)) {
            $coingeckoPrice = NMM_Exchange::get_coingecko_price($cryptoId, $updateInterval);

            if ($coingeckoPrice > 0) {
                $prices[] = $coingeckoPrice;
            }
        }

        if (in_array('1', $selectedPriceApis)) {
            $hitbtcPrice = NMM_Exchange::get_hitbtc_price($cryptoId, $updateInterval);

            if ($hitbtcPrice > 0) {
                $prices[] = $hitbtcPrice;
            }        
        }

        if (in_array('2', $selectedPriceApis)) {
            $gateioPrice = NMM_Exchange::get_gateio_price($cryptoId, $updateInterval);

            if ($gateioPrice > 0) {
                $prices[] = $gateioPrice;
            }        
        }

        if (in_array('3', $selectedPriceApis)) {
            $binancePrice = NMM_Exchange::get_binance_price($cryptoId, $updateInterval);

            if ($binancePrice > 0) {
                $prices[] = $binancePrice;  
            }        
        }

        if (in_array('4', $selectedPriceApis)) {
            $poloniexPrice = NMM_Exchange::get_poloniex_price($cryptoId, $updateInterval);

            // if there were no trades do not use this pricing method
            if ($poloniexPrice > 0) {
                $prices[] = $poloniexPrice;
            }        
        }

        $sum = 0;
        $count = count($prices);

        if ($count === 0) {        
            throw new \Exception('No cryptocurrency exchanges could be reached, please try again.');
        }

        foreach ($prices as $price) {
            $sum += $price;        
        }

        $average_price = $sum / $count;

        return $average_price;
    }    
}

?>