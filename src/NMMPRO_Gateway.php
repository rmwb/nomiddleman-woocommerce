<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMMPRO_Gateway extends WC_Payment_Gateway {
    private $cryptos;
    private $gapLimit;

    public function __construct() {


        $cryptoArray = NMMPRO_Cryptocurrencies::get();

        $nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

        $this->cryptos = $cryptoArray;
        $this->gapLimit = 2;

        $this->id = 'nmm_gateway';
        $this->icon = NMMPRO_Compat::filter('nmmpro_gateway_icon', NMMPRO_PLUGIN_DIR . '/assets/img/bitcoin_logo_small.png');
        $this->title = sanitize_text_field($nmmSettings->get_customer_gateway_message());
        $this->has_fields = true;
        $this->method_title = __('Nomiddleman Crypto Payments', 'nomiddleman-crypto-payments-for-woocommerce');
        $this->method_description = __('Allow customers to pay using cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce');
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page'));
        // The order-pay receipt renderer is registered in the bootstrap
        // (NMMPRO_render_order_receipt), NOT here: the receipt template's
        // do_action fires without anything having instantiated the payment
        // gateways first, so a constructor-registered hook would never exist
        // on that page.

        // Hooked here, not from thank_you_page(): emails resent from admin or
        // dispatched by cron never pass through the thank-you page, so hooking
        // there meant only the very first email carried the payment details.
        // WooCommerce instantiates each registered gateway once per request
        // (WC_Payment_Gateways::init()), but guard with a static flag anyway so
        // a second instantiation can never render the details twice per email.
        static $emailDetailsHooked = false;
        if (!$emailDetailsHooked) {
            add_action('woocommerce_email_order_details', array($this, 'additional_email_details'), 10, 4);
            $emailDetailsHooked = true;
        }
    }

    public function admin_options() {

        ?>
        <h2><?php esc_html_e('Nomiddleman Crypto Payments', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
        <div class="nmm-options">
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . NMMPRO_REDUX_SLUG . '&tab=general')); ?>"><?php esc_html_e('Nomiddleman Plugin Settings', 'nomiddleman-crypto-payments-for-woocommerce'); ?></a>
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

        $nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

        $validCryptos = $nmmSettings->get_valid_selected_cryptos();
        $excludedCryptoIds = array();

        foreach ($validCryptos as $crypto) {
            $cryptoId = $crypto->get_id();

            if ($nmmSettings->hd_enabled($cryptoId)) {

                $mpk = $nmmSettings->get_mpk($cryptoId);
                $hdMode = $nmmSettings->get_hd_mode($cryptoId);
                $hdRepo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);

                $count = $hdRepo->count_ready();

                if ($count < 1) {
                    try {
                        NMMPRO_Hd::force_new_address($cryptoId, $mpk, $hdMode);
                    }
                    catch ( \Exception $e) {
                        NMMPRO_Util::log(__FILE__, __LINE__, 'UNABLE TO GENERATE HD ADDRESS FOR ' . $crypto->get_name() . ' ADMIN MUST BE NOTIFIED. REMOVING CRYPTO FROM PAYMENT OPTIONS' . $e->getTraceAsString());
                        $excludedCryptoIds[] = $cryptoId;
                    }
                }
            }
        }

        $selectOptions = $this->get_select_options_for_valid_cryptos($excludedCryptoIds);

        woocommerce_form_field(
            'nmmpro_currency_id', array(
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
            if (empty($_POST['nmmpro_currency_id'])) {
                wc_add_notice(__('Please choose a cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
                return;
            }
            try {
                $chosenCryptoId = sanitize_text_field($_POST['nmmpro_currency_id']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checked against the configured crypto list below; a slashed value cannot match and is rejected.
                if (!array_key_exists($chosenCryptoId, $this->cryptos)) {
                    wc_add_notice(__('Please choose a valid cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
                    return;
                }
                $crypto = $this->cryptos[$chosenCryptoId];
                $curr = get_woocommerce_currency();
                $cryptoPerUsd = $this->get_crypto_value_in_usd($crypto->get_id(), $crypto->get_update_interval());

                // this is just a check to make sure we can hit the currency exchange if we need to
                $usdTotal = NMMPRO_Exchange::get_order_total_in_usd(1.0, $curr);
            }
            catch ( \Exception $e) {
                NMMPRO_Util::log(__FILE__, __LINE__, $e->getMessage());
                wc_add_notice($e->getMessage(), 'error');
            }
            // phpcs:enable
        }
    }

    // This is called when the user clicks Place Order, after validate_fields
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Classic checkout posts nmmpro_currency_id directly; the Blocks checkout
        // delivers it via the Store API's paymentMethodData, which WooCommerce
        // also surfaces through $_POST for legacy gateways.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce/Store API verify their own nonces before process_payment runs.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the value must match a configured crypto id exactly; a slashed value cannot match and is rejected, so wp_unslash() would be a no-op and is deferred to avoid any data-flow change in this release.
        if (empty($_POST['nmmpro_currency_id']) || !array_key_exists(sanitize_text_field($_POST['nmmpro_currency_id']), $this->cryptos)) {
            wc_add_notice(__('Please choose a cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $selectedCryptoId = sanitize_text_field($_POST['nmmpro_currency_id']);
        // phpcs:enable
        WC()->session->set('chosen_crypto_id', $selectedCryptoId);

        // Allocate the payment address, monitoring row and on-hold transition
        // NOW, not on the order-received render: a customer who closed the
        // browser between "Place order" and that page used to get a pending
        // order with no address, no payment row and no email - and no recovery
        // path, since the cron only sweeps rows that exist. Doing it here also
        // means bots and link-prefetchers hitting the order-received URL no
        // longer trigger side-effectful allocation.
        //
        // The chosen coin is passed INTO the initializer and committed to
        // order meta under the init lock, not written here: a duplicate
        // submission carrying a different coin could otherwise overwrite the
        // meta after the lock holder had already read it, leaving the order
        // labelled with one coin while the allocated address and amount
        // belong to another. Under the lock, first commit wins.
        $initResult = $this->initialize_order_payment($order_id, $selectedCryptoId, true);

        if ($initResult['outcome'] === 'failed') {
            // The order was already marked failed under the init lock. Surface
            // the message as a checkout notice (classic checkout renders it
            // in place; the Store API returns it to the blocks checkout) so
            // the customer can correct and try again instead of landing on an
            // order page showing an error.
            wc_add_notice($initResult['message'], 'error');
            return array('result' => 'failure');
        }
        if ($initResult['outcome'] === 'missing' || $initResult['outcome'] === 'not_payable') {
            // Should not happen at checkout (WooCommerce has just created this
            // order as pending), so if it does, something is wrong enough that
            // returning success - and sending the customer to an order page
            // with no payment details - would be worse than failing here.
            // Always say something: WooCommerce only redirects on success, so
            // without a notice the customer gets a silently reloaded page.
            wc_add_notice(__('This order is no longer awaiting payment, so no payment address can be issued. Please place a new order or contact the store.', 'nomiddleman-crypto-payments-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        // 'initialized' and 'already' have a committed address to display;
        // 'busy' (a concurrent double-submit holds the lock) proceeds too -
        // the order-received fallback shows a refresh notice until the lock
        // holder commits.
        return array(
                      'result' => 'success',
                      'redirect'  => $this->get_return_url( $order ),
                    );
    }

    // This is called after process payment, when the customer places the order
    public function thank_you_page($order_id) {
        $cssPath = NMMPRO_PLUGIN_DIR . '/assets/css/nmm-thank-you-page.css';
        wp_enqueue_style('nmm-styles', $cssPath, array(), NMMPRO_VERSION);
        wp_enqueue_script('nmm-pay', NMMPRO_PLUGIN_DIR . '/assets/js/nmm-pay.js', array(), NMMPRO_VERSION, true);
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
            if (!$order) {
                // Deleted (or never existed) mid-flight: render nothing, like
                // WooCommerce's own templates when an order is missing. Calling
                // get_meta() on the false return would throw an Error that the
                // \Exception catches below do not cover, and 500 the page.
                return;
            }

            // Fast path: the address is already allocated - normally by
            // process_payment since 2.10.0, otherwise by an earlier fallback
            // init or refresh. No lock is needed just to re-display it.
            if (!empty($order->get_meta('wallet_address'))) {
                $this->display_existing_payment($order, $order_id);
                return;
            }

            // No address: an order placed before allocation moved into
            // process_payment (2.10.0), or one whose checkout-time
            // initialization failed. Initialize now, under the same lock,
            // then display what it committed.
            $result = $this->initialize_order_payment($order_id);

            if ($result['outcome'] === 'initialized' || $result['outcome'] === 'already') {
                $order = wc_get_order($order_id);
                if ($order) {
                    // Fresh meta read: this request's pre-lock cache still
                    // holds the empty wallet_address from the fast-path check.
                    $order->read_meta_data(true);
                    if (!empty($order->get_meta('wallet_address'))) {
                        $this->display_existing_payment($order, $order_id);
                    }
                }
                return;
            }
            if ($result['outcome'] === 'busy') {
                echo '<p class="nmm-status-pending">' . esc_html__('We are preparing your payment details. This will be ready in a few seconds - please refresh this page.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
                return;
            }
            if ($result['outcome'] === 'failed') {
                $this->render_checkout_error($result['message']);
                return;
            }
            if ($result['outcome'] === 'not_payable') {
                // Render something rather than a blank page - this is reachable
                // from the order-pay link of a cancelled or refunded order.
                echo '<p class="nmm-status-cancelled">' . esc_html__('This order is no longer awaiting payment. Please do not send any funds. If you believe this is an error, contact the store.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
            }
            return;
        }
        catch ( \Throwable $e ) {
            // Errors from the display path. Do not fail the order for a
            // display hiccup - it may already be paid; just surface the
            // message. Initialization failures are handled inside
            // initialize_order_payment, which fails the order under the lock.
            // \Throwable, not \Exception: on PHP 8 an undefined-index/null
            // dereference in the display path raises an \Error, which must
            // render this notice instead of a 500 on the customer's order page.
            NMMPRO_Util::log(__FILE__, __LINE__, 'Error rendering the payment page: ' . $e->getMessage());
            $this->render_checkout_error($e->getMessage());
        }
    }

    /**
     * Allocate the order's payment address, monitoring row and on-hold
     * transition, exactly once, under the per-order advisory lock. Called from
     * process_payment() (the normal path since 2.10.0) and from
     * thank_you_page() as a fallback for orders that predate that move or
     * whose checkout-time initialization failed.
     *
     * Outcomes:
     *  - 'initialized': the address was allocated and the order moved on-hold
     *  - 'already':     initialization had already completed (possibly by a
     *                   concurrent request while we waited on the lock)
     *  - 'busy':        another request holds the lock mid-initialization
     *  - 'missing':     the order does not exist (deleted mid-flight)
     *  - 'not_payable': the order is not awaiting payment (cancelled, failed,
     *                   refunded or already paid) - never initialize it
     *  - 'failed':      initialization failed; the order was marked wc-failed
     *                   under the lock; 'message' is the customer-facing error
     *
     * $requestedCryptoId (checkout only) is the coin the submitting request
     * chose; it is committed to order meta UNDER the lock so a duplicate
     * submission with a different coin can never relabel an order whose
     * address another request is allocating - first commit wins, and an
     * 'already' outcome deliberately leaves the winner's coin in place.
     */
    /**
     * May this order have a payment address allocated?
     *
     * Normally that means it is awaiting payment. The exception is a FAILED
     * order that never got an address: that is precisely the state this
     * gateway's own error handling creates when initialization throws (an
     * exchange-rate blip, the Monero RPC down, the carousel exhausted), and
     * WooCommerce supports paying for a failed order - its default payable
     * statuses are pending AND failed, and the order-pay flow re-checks stock
     * for exactly this case. Refusing it would leave the customer holding a
     * dead order they cannot retry.
     *
     * A failed order that DOES carry an address stays refused: that address
     * may since have been recycled to someone else, so re-displaying it could
     * credit a stranger's order.
     */
    private function order_can_initialize($order, $allowFailedRetry = false) {
        if (NMMPRO_Hd::order_awaits_payment($order)) {
            return true;
        }

        // The failed-order carve-out is ONLY for a real payment submission -
        // checkout or WooCommerce's order-pay form, both of which re-check
        // stock for a failed order before they reach us. The order-received
        // page must never use it: WooCommerce fires the thank-you hook for a
        // failed order too, so a customer holding the order key could revisit
        // that URL after the goods sold out and quietly move the order back to
        // on-hold - reserving stock the merchant no longer has and soliciting
        // payment for something unfulfillable.
        return $allowFailedRetry
            && $order->has_status('failed')
            && empty($order->get_meta('wallet_address'));
    }

    public function initialize_order_payment($order_id, $requestedCryptoId = null, $allowFailedRetry = false) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('outcome' => 'missing', 'message' => '');
        }
        // Only an order that is actually awaiting payment may be initialized.
        // Without this, hitting the order-received or order-pay URL of a
        // cancelled, failed, refunded or already-paid order would allocate a
        // fresh address and push it back to on-hold - reviving a dead order,
        // asking the customer to pay again, and putting a monitored address on
        // an order nobody is watching.
        if (!$this->order_can_initialize($order, $allowFailedRetry)) {
            NMMPRO_Util::log(__FILE__, __LINE__, 'Not initializing payment for order ' . $order_id . ': status is ' . $order->get_status() . ', which is not awaiting payment.');
            return array('outcome' => 'not_payable', 'message' => '');
        }
        if (!empty($order->get_meta('wallet_address'))) {
            return array('outcome' => 'already', 'message' => '');
        }

            // Serialize per-order initialization so two near-
            // simultaneous first loads of the same order cannot both allocate an
            // address: with Monero subaddresses or carousel addresses each worker
            // would mint a DIFFERENT address, and the last meta write could differ
            // from the address recorded in the payment table, leaving the
            // displayed address unmonitored. Uses the same crash-safe MySQL
            // advisory lock the cron uses.
            $lockResult = NMMPRO_Util::acquire_order_init_lock($order_id);
            $initializing = false; // becomes true once we commit to allocating

            try {
                // Re-fetch under the lock: another worker may have finished
                // initializing while we waited for it. If so, just display that
                // address and never allocate a second one. Force a fresh meta read
                // (bypassing the request-local cache the pre-lock get_meta() above
                // populated with an empty wallet_address) so we actually see what
                // the worker that held the lock just committed - a stale cache here
                // would let us allocate a second, unmonitored address.
                $order = wc_get_order($order_id);
                if (!$order) {
                    // Deleted while we waited for the lock. Nothing to
                    // allocate; the finally below still releases the lock.
                    return array('outcome' => 'missing', 'message' => '');
                }
                $order->read_meta_data(true);
                if (!empty($order->get_meta('wallet_address'))) {
                    return array('outcome' => 'already', 'message' => '');
                }
                // Re-check the status under the lock, not just before it. We may
                // have waited seconds for the lock, and in that time the order
                // could have been cancelled, failed, refunded or paid - by an
                // admin, a webhook, or the verifier. Allocating now would revive
                // a dead order and push it back to on-hold.
                if (!$this->order_can_initialize($order, $allowFailedRetry)) {
                    NMMPRO_Util::log(__FILE__, __LINE__, 'Order ' . $order_id . ' became ' . $order->get_status() . ' while waiting for the init lock; not initializing payment.');
                    return array('outcome' => 'not_payable', 'message' => '');
                }

                if ($lockResult === '0') {
                    // The lock works and another request holds it: that request is
                    // still initializing this order (a slow first load - exchange
                    // rate or wallet RPC). We must NOT allocate a second address -
                    // that is exactly the race this lock prevents. The caller
                    // decides what "busy" means for its context (the thank-you
                    // fallback shows a refresh notice; process_payment just
                    // redirects and lets the order page catch up).
                    NMMPRO_Util::log(__FILE__, __LINE__, 'Order-init lock busy for order ' . $order_id . '; another request is still initializing.', 'warning');
                    return array('outcome' => 'busy', 'message' => '');
                }

                if ($lockResult !== '1') {
                    // null: advisory locks are unavailable on this host. Degrade to
                    // initializing without overlap protection (matching the pre-lock
                    // behaviour) rather than never allocating an address at all.
                    NMMPRO_Util::log(__FILE__, __LINE__, 'Advisory locks unavailable on this host; initializing order ' . $order_id . ' without overlap protection.', 'warning');
                }

            // From here we are allocating; a throw past this point must fail the
            // order while we still hold the lock (see the catch below).
            $initializing = true;

            // Reaching here means the order has no wallet_address, so it was never
            // initialized successfully - yet an earlier attempt may have inserted
            // an Autopay payment row and then failed before persisting the meta.
            // Clear those unpaid leftovers under the lock: otherwise
            // UNIQUE(order_id, order_amount) would silently reject this attempt's
            // insert, leaving the previous attempt's address monitored while we
            // show the customer a different, unwatched one. Paid/cancelled rows
            // are real records and are never touched.
            $staleRepo = new NMMPRO_Payment_Repo();
            $staleRepo->delete_unpaid_for_order($order_id);

            $nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

            if ($requestedCryptoId !== null && array_key_exists($requestedCryptoId, $this->cryptos)) {
                // Checkout path: persist the submitting request's coin here,
                // under the lock, so it can never be overwritten between
                // another worker reading it and committing an address for it.
                $order->update_meta_data('nmmpro_chosen_crypto_id', $requestedCryptoId);
                $order->save();
                $chosenCryptoId = $requestedCryptoId;
            }
            else {
                $chosenCryptoId = ($order->get_meta('nmmpro_chosen_crypto_id') ?: $order->get_meta('nmm_chosen_crypto_id'));
                if (empty($chosenCryptoId) && $this->session_usable()) {
                    // Legacy fallback only: orders written since the meta was
                    // introduced always carry it, and this initializer can now
                    // run without a browsing session (order-pay, admin tools).
                    $chosenCryptoId = WC()->session->get('chosen_crypto_id');
                }
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

            $usdTotal = NMMPRO_Exchange::get_order_total_in_usd($order->get_total(), $curr);

            $cryptoMarkupPercent = $nmmSettings->get_markup($cryptoId);

            if (!is_numeric($cryptoMarkupPercent)) {
                $cryptoMarkupPercent = 0.0;
            }

            $usdRate = NMMPRO_Exchange::get_order_total_in_usd('1', $curr);
            $cryptoTotal = NMMPRO_Amount::quote($order->get_total(), $usdRate, $cryptoPerUsd,
                $cryptoMarkupPercent, $crypto->get_round_precision());
            $dustAmount = NMMPRO_Compat::filter('nmmpro_dust_amount', '0', $cryptoId, $cryptoPerUsd,
                $crypto->get_round_precision(), $usdTotal, $cryptoTotal);
            $units = NMMPRO_Amount::add(
                NMMPRO_Amount::to_units($cryptoTotal, $crypto->get_round_precision()),
                NMMPRO_Amount::to_units(NMMPRO_Amount::rounded($dustAmount, $crypto->get_round_precision()), $crypto->get_round_precision())
            );
            if ($units === '0') {
                throw new \Exception(esc_html__('We could not work out a valid payment amount for this order. Please try again shortly, or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
            }
            $formattedCryptoTotal = NMMPRO_Amount::from_units($units, $crypto->get_round_precision());
            $cryptoTotal = $formattedCryptoTotal;

            $order->update_meta_data('crypto_amount', $formattedCryptoTotal);

            NMMPRO_Util::log(__FILE__, __LINE__, 'Crypto total: ' . $cryptoTotal . ' Formatted Total: ' . $formattedCryptoTotal);

            // if hd is enabled we have stuff to do
            if ($nmmSettings->hd_enabled($cryptoId)) {
                $mpk = $nmmSettings->get_mpk($cryptoId);
                $hdMode = $nmmSettings->get_hd_mode($cryptoId);
                $hdRepo = new NMMPRO_Hd_Repo($cryptoId, $mpk, $hdMode);

                // Atomically claim the oldest ready address for this order.
                $orderWalletAddress = $hdRepo->claim_oldest_ready($order_id, $formattedCryptoTotal);

                // if none was available, derive one and try to claim again
                if (!$orderWalletAddress) {
                    try {
                        NMMPRO_Hd::force_new_address($cryptoId, $mpk, $hdMode);
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
                if ($this->session_usable()) {
                    WC()->session->set('hd_wallet_address', $orderWalletAddress);
                }

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
                    $orderWalletAddress = NMMPRO_Monero::create_subaddress($order_id);
                }
                else {
                    $orderWalletAddress = $nmmSettings->get_next_carousel_address($cryptoId);
                }

                // handle payment verification feature
                if ($nmmSettings->autopay_enabled($cryptoId)) {
                    $paymentRepo = new NMMPRO_Payment_Repo();

                    // The row IS the monitoring: Autopay only ever sweeps addresses
                    // it finds in this table. If the insert fails we must fail the
                    // order rather than fall through and display the address - an
                    // unmonitored address would take the customer's funds and never
                    // credit the order. Throwing here is before the wallet_address
                    // meta write below, so nothing is ever shown or persisted.
                    if (!$paymentRepo->insert($orderWalletAddress, $cryptoId, $order_id, $formattedCryptoTotal, 'unpaid')) {
                        throw new \Exception(esc_html__('We could not set up payment monitoring for your order, so no payment address has been issued. This order has been cancelled and you have not been charged. Please try again or contact the site administrator.', 'nomiddleman-crypto-payments-for-woocommerce'));
                    }
                }

                $orderNote = sprintf(
                    /* translators: 1: amount, 2: cryptocurrency ticker, 3: wallet address */
                    __('Awaiting payment of %1$s %2$s to payment address %3$s.', 'nomiddleman-crypto-payments-for-woocommerce'),
                    $formattedCryptoTotal,
                    $cryptoId,
                    $orderWalletAddress);
            }

            // Legacy session copy (emails read order meta first since 2.9.9)
            if ($this->session_usable()) {
                WC()->session->set($cryptoId . '_amount', $formattedCryptoTotal);
            }

            // For customer reference and to handle refresh of thank you page
            $order->update_meta_data('wallet_address', $orderWalletAddress);

            // Emails fire once we update status to on-hold; additional_email_details
            // is already hooked from the constructor and reads the meta saved here.
            $order->update_status('wc-on-hold', $orderNote);

            return array('outcome' => 'initialized', 'message' => '');
            }
            catch ( \Throwable $e ) {
                // \Throwable, not \Exception: a TypeError/Error on PHP 8 (bad
                // registry data, null dereference) must fail the order the same
                // way an Exception does - escaping here would skip the failure
                // handling and 500 the customer mid-initialization.
                // Initialization failed. Mark the order failed HERE, while we still
                // hold the lock, so a concurrent first-load request that is waiting
                // cannot acquire the lock, allocate a fresh address, reach on-hold,
                // and then have this delayed failure overwrite it - which would
                // leave a monitored payment address on a failed order. Only fail if
                // we had actually begun allocating ($initializing); an error while
                // re-displaying an already-initialized order must not fail it.
                if ($initializing) {
                    $failedOrder = wc_get_order($order_id);
                    if ($failedOrder) {
                        /* translators: %s: error message */
                        $failedOrder->update_status('wc-failed', sprintf(__('Error Message: %s', 'nomiddleman-crypto-payments-for-woocommerce'), $e->getMessage()));
                    }
                }
                NMMPRO_Util::log(__FILE__, __LINE__, 'Something went wrong during checkout: ' . $e->getMessage());
                return array('outcome' => 'failed', 'message' => $e->getMessage());
            }
            finally {
                // Release only the lock we actually acquired ('1'); on '0'/null we
                // never held it. Runs on the normal path, on an early return above,
                // and AFTER the failure handling above - so the lock covers the
                // whole of initialization and its error handling together.
                if ($lockResult === '1') {
                    NMMPRO_Util::release_order_init_lock($order_id);
                }
            }
    }

    private function render_checkout_error($message) {
        echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
        echo '<ul class="woocommerce-error">';
        echo '<li>';
        echo esc_html__('Something went wrong.', 'nomiddleman-crypto-payments-for-woocommerce') . '<br>';
        echo esc_html($message);
        echo '</li>';
        echo '</ul>';
        echo '</div>';
    }

    // Re-display an order whose payment address was already allocated (page
    // refresh, or a concurrent first load that lost the init lock). Shows a
    // terminal message for paid/cancelled/failed orders, otherwise the live
    // payment status. Never allocates - allocation happens once, under the lock.
    private function display_existing_payment($order, $order_id) {
        if ($order->is_paid()) {
            echo '<p class="nmm-status-paid">' . esc_html__('Payment received - thank you! Your order is being processed.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
            return;
        }

        // Do not re-display payment instructions for an order that is no longer
        // awaiting payment. Its address may have been recycled to a different
        // order, so a late payment would credit someone else. Ask the shared
        // helper rather than naming statuses here: a denylist of cancelled and
        // failed missed 'refunded' (WooCommerce does not count it as paid, so
        // is_paid() above returns false for it) and every custom non-payable
        // status a site might declare.
        if (!NMMPRO_Hd::order_awaits_payment($order)) {
            echo '<p class="nmm-status-cancelled">' . esc_html__('This order is no longer awaiting payment. Please do not send any funds to the address shown previously. If you believe this is an error, contact the store.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
            return;
        }

        $this->handle_thank_you_refresh(
            $order->get_meta('crypto_type_id'),
            $order->get_meta('wallet_address'),
            $order->get_meta('crypto_amount'),
            $order_id);
    }

    // WC()->session is null when an email is dispatched from cron, WP-CLI or an
    // admin resend - there is no customer browsing session. Calling ->get() on
    // null raises an \Error on PHP 8, so never dereference it unchecked.
    private function session_usable() {
        return function_exists('WC') && WC() && WC()->session && is_callable(array(WC()->session, 'get'));
    }

    public function additional_email_details($order, $sent_to_admin, $plain_text, $email) {
        // Hooked unconditionally (see constructor), so this now fires for every
        // order email: bail quietly for orders not paid through this gateway.
        if (!($order instanceof WC_Order) || $order->get_payment_method() !== $this->id) {
            return;
        }
        $chosenCrypto = ($order->get_meta('nmmpro_chosen_crypto_id') ?: $order->get_meta('nmm_chosen_crypto_id'));
        if (empty($chosenCrypto)) {
            $chosenCrypto = $order->get_meta('crypto_type_id');
        }
        if (empty($chosenCrypto) && $this->session_usable()) {
            $chosenCrypto = WC()->session->get('chosen_crypto_id');
        }
        if (empty($chosenCrypto) || !array_key_exists($chosenCrypto, $this->cryptos)) {
            return; // nothing reliable to attach; the order note still has details
        }
        $crypto =  $this->cryptos[$chosenCrypto];
        // Order meta is authoritative: it survives admin resends and cron
        // dispatch, where the session thank_you_page populated no longer
        // exists. The session copy is only a fallback for legacy orders placed
        // before the meta was written.
        $orderCryptoTotal = $order->get_meta('crypto_amount');
        if (empty($orderCryptoTotal) && $this->session_usable()) {
            $orderCryptoTotal = WC()->session->get($crypto->get_id() . '_amount');
        }
        $orderWalletAddress = $order->get_meta('wallet_address');
        if (empty($orderCryptoTotal) || empty($orderWalletAddress)) {
            return; // order never finished payment setup; no details to show
        }
        $orderId = $order->get_id();

        $formattedTotal = NMMPRO_Cryptocurrencies::get_price_string($crypto->get_id(), $orderCryptoTotal);
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

        $qrData = NMMPRO_Qr::payment_uri($crypto, $orderWalletAddress, $orderCryptoTotal);

        // embedded as an inline (CID) attachment when PHPMailer sends this
        // email; mailers that bypass PHPMailer simply show the text details
        $cid = NMMPRO_Qr::stash_email_image($orderId, $qrData);

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

    // convert array of cryptos to option array, excluding any coin IDs we know
    // cannot currently accept payment (e.g. HD address generation failed)
    private function get_select_options_for_valid_cryptos($excludedCryptoIds = array()) {
        $selectOptionArray = array();

        $nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

        foreach (NMMPRO_Cryptocurrencies::get_alpha() as $crypto) {
            if (in_array($crypto->get_id(), $excludedCryptoIds, true)) {
                continue;
            }
            if ($nmmSettings->crypto_selected_and_valid($crypto->get_id())) {
                $selectOptionArray[$crypto->get_id()] = $crypto->get_name();
            }
        }

        return $selectOptionArray;
    }

    private function output_thank_you_html($crypto, $orderWalletAddress, $cryptoTotal, $orderId) {
        $formattedPrice = NMMPRO_Cryptocurrencies::get_price_string($crypto->get_id(), $cryptoTotal);
        $nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

        $customerMessage = NMMPRO_Compat::filter('nmmpro_customer_message', $nmmSettings->get_customer_payment_message($crypto), $crypto, $orderId, $formattedPrice, $orderWalletAddress);

        $qrData = NMMPRO_Qr::payment_uri($crypto, $orderWalletAddress, $cryptoTotal);

        // admin-entered HTML; allow post-safe markup but never scripts
        echo wp_kses_post($customerMessage);
        ?>

        <h2><?php esc_html_e('Cryptocurrency payment details', 'nomiddleman-crypto-payments-for-woocommerce'); ?></h2>
        <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
            <li class="woocommerce-order-overview__qr-code">
                <p style="word-wrap: break-word;"><?php esc_html_e('QR Code payment:', 'nomiddleman-crypto-payments-for-woocommerce'); ?></p>
                <div class="qr-code-container">
                    <?php echo NMMPRO_Qr::svg($qrData, 200); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SVG markup generated in memory by this plugin. ?>
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
                        data-chain="<?php echo esc_attr(NMMPRO_Cryptocurrencies::evm_chain_id($crypto->get_id())); ?>"
                        data-units="<?php echo esc_attr(NMMPRO_Qr::to_base_units($cryptoTotal, $crypto->get_round_precision())); ?>">
                    <?php esc_html_e('Pay in browser wallet', 'nomiddleman-crypto-payments-for-woocommerce'); ?>
                </button>
                <p id="nmm-wallet-msg" aria-live="polite"></p>
            <?php elseif ($crypto->get_id() === 'SOL') : ?>
                <p><a class="button alt" href="<?php echo esc_url($qrData, array('solana')); ?>"><?php esc_html_e('Open in Solana wallet', 'nomiddleman-crypto-payments-for-woocommerce'); ?></a></p>
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
        // The coin id comes from order meta: it can be missing on a legacy
        // order, or name a coin removed from the registry in an update.
        // Indexing $this->cryptos with it unguarded would raise an \Error on
        // PHP 8 and 500 the customer's order page on refresh.
        if (!is_string($chosenCrypto) || !array_key_exists($chosenCrypto, $this->cryptos)) {
            NMMPRO_Util::log(__FILE__, __LINE__, 'Unknown crypto_type_id for order ' . $orderId . '; cannot re-display payment details.', 'warning');
            echo '<p class="nmm-status-pending">' . esc_html__('We could not display your payment details for this order. Please contact the store for assistance.', 'nomiddleman-crypto-payments-for-woocommerce') . '</p>';
            return;
        }
        $this->output_thank_you_html($this->cryptos[$chosenCrypto], $orderWalletAddress, $cryptoTotal, $orderId);
    }

    // this function hits all the crypto exchange APIs that the user selected, then averages them and returns a conversion rate for USD
    // if the user has selected no exchanges to fetch data from it instead takes the average from all of them
    private function get_crypto_value_in_usd($cryptoId, $updateInterval) {
        $reduxSettings = NMMPRO_Compat::get_option(NMMPRO_REDUX_ID);
        if (!array_key_exists('selected_price_apis', $reduxSettings)) {
            throw new \Exception(esc_html__('No price API selected. Please contact plug-in support.', 'nomiddleman-crypto-payments-for-woocommerce'));
        }

        return NMMPRO_Exchange::get_average_usd_price($cryptoId, $updateInterval, $reduxSettings['selected_price_apis']);
    }
}

?>
