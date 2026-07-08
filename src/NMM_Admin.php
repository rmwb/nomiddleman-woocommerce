<?php

/**
 * Native WordPress settings page for Nomiddleman Crypto Payments.
 *
 * Replaces the previously bundled Redux Framework. Settings are stored in the
 * same option array (NMM_REDUX_ID) with the same field IDs, so existing
 * installs keep their configuration with no migration.
 */
class NMM_Admin {

    const OPTION_GROUP = 'nmmpro_options_group';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function register_menu() {
        $hook = add_menu_page(
            apply_filters('nmm_settings_page_title', 'Nomiddleman Crypto Settings'),
            apply_filters('nmm_settings_menu_title', 'Nomiddleman Crypto Payments'),
            'manage_options',
            NMM_REDUX_SLUG,
            array(__CLASS__, 'render_page'),
            NMM_PLUGIN_DIR . '/assets/img/redux-menu-icon.svg',
            56
        );

        add_action('load-' . $hook, array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets() {
        add_action('admin_enqueue_scripts', function() {
            wp_enqueue_style('nmm-admin-styles', NMM_PLUGIN_DIR . '/assets/css/nmm-admin-settings.css', array(), NMM_VERSION);
            wp_enqueue_script('nmm-admin-scripts', NMM_PLUGIN_DIR . '/assets/js/nmm-admin.js', array('jquery'), NMM_VERSION, true);
        });
    }

    public static function register_settings() {
        register_setting(self::OPTION_GROUP, NMM_REDUX_ID, array(
            'type' => 'array',
            'sanitize_callback' => array('NMM_Validation', 'sanitize_options'),
        ));
    }

    private static function get_option_values() {
        return get_option(NMM_REDUX_ID, array());
    }

    private static function value($values, $key, $default = '') {
        return array_key_exists($key, (array) $values) ? $values[$key] : $default;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $values = self::get_option_values();
        $settings = new NMM_Settings($values);
        $cryptos = NMM_Cryptocurrencies::get_alpha();

        $tabs = array(
            'general' => 'General Settings',
            'cryptos' => 'Select Cryptocurrencies',
        );

        foreach ($cryptos as $crypto) {
            if ($settings->crypto_selected($crypto->get_id())) {
                $tabs['crypto_' . $crypto->get_id()] = $crypto->get_name() . ' (' . $crypto->get_id() . ')';
            }
        }

        $tabs['pricing'] = 'Pricing Options';

        ?>
        <div class="wrap nmm-settings-wrap">
            <h1><?php echo esc_html(apply_filters('nmm_settings_display_name', 'Nomiddleman Crypto Payments for Woocommerce')); ?>
                <span class="nmm-version">v<?php echo esc_html(NMM_VERSION); ?></span></h1>

            <?php settings_errors('nmmpro_options'); ?>

            <form method="post" action="options.php" class="nmm-settings-form">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <div class="nmm-settings-layout">
                    <ul class="nmm-tab-nav">
                        <?php foreach ($tabs as $tabId => $tabLabel) : ?>
                            <li>
                                <a href="#nmm-tab-<?php echo esc_attr($tabId); ?>" data-tab="<?php echo esc_attr($tabId); ?>">
                                    <?php if (strpos($tabId, 'crypto_') === 0) :
                                        $cryptoId = substr($tabId, 7); ?>
                                        <img class="nmm-tab-icon" src="<?php echo esc_url($cryptos[$cryptoId]->get_logo_file_path()); ?>" alt="" />
                                    <?php endif; ?>
                                    <?php echo esc_html($tabLabel); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="nmm-tab-panels">
                        <div class="nmm-tab-panel" id="nmm-tab-general">
                            <h2>General Settings</h2>
                            <table class="form-table" role="presentation">
                                <?php
                                self::render_text_row('payment_label', 'Payment Label',
                                    self::value($values, 'payment_label', 'Pay with cryptocurrency'),
                                    'This will be displayed on the checkout screen when the customer selects their payment option.');
                                self::render_textarea_row('payment_message_html', 'Customer Payment Message',
                                    self::value($values, 'payment_message_html', ''),
                                    'This is displayed above the crypto payment details on the payment screen (After the customer clicks "Checkout").');
                                ?>
                            </table>
                        </div>

                        <div class="nmm-tab-panel" id="nmm-tab-cryptos">
                            <h2>Select Cryptocurrencies</h2>
                            <p class="description">Choose the cryptocurrencies you want to accept, save, then configure each one on its own tab.</p>
                            <div class="nmm-crypto-select">
                                <?php
                                $selected = $settings->get_selected_cryptos();
                                foreach ($cryptos as $crypto) :
                                    $cid = $crypto->get_id(); ?>
                                    <label class="nmm-crypto-choice">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr(NMM_REDUX_ID); ?>[crypto_select][]"
                                               value="<?php echo esc_attr($cid); ?>"
                                               <?php checked(in_array($cid, $selected, true)); ?> />
                                        <img src="<?php echo esc_url($crypto->get_logo_file_path()); ?>" alt="" />
                                        <?php echo esc_html($crypto->get_name() . ' (' . $cid . ')'); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php foreach ($cryptos as $crypto) :
                            if (!$settings->crypto_selected($crypto->get_id())) {
                                continue;
                            }
                            self::render_crypto_panel($crypto, $values);
                        endforeach; ?>

                        <div class="nmm-tab-panel" id="nmm-tab-pricing">
                            <h2>Pricing Options</h2>
                            <p class="description">Price is the average of the APIs selected. At least one must be selected. Adding more can slow down thank you page loading.</p>
                            <?php
                            $priceApis = array(
                                '0' => 'CoinGecko',
                                '1' => 'HitBTC',
                                '2' => 'GateIO',
                                '3' => 'Binance',
                                '4' => 'Poloniex',
                            );
                            $selectedApis = (array) self::value($values, 'selected_price_apis', array('0'));
                            ?>
                            <div class="nmm-price-apis">
                                <?php foreach ($priceApis as $apiId => $apiLabel) : ?>
                                    <label class="nmm-crypto-choice">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr(NMM_REDUX_ID); ?>[selected_price_apis][]"
                                               value="<?php echo esc_attr($apiId); ?>"
                                               <?php checked(in_array((string) $apiId, array_map('strval', $selectedApis), true)); ?> />
                                        <?php echo esc_html($apiLabel); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    private static function render_crypto_panel($crypto, $values) {
        $cid = $crypto->get_id();
        $mode = self::value($values, $cid . '_mode', '');
        ?>
        <div class="nmm-tab-panel nmm-crypto-panel" id="nmm-tab-crypto_<?php echo esc_attr($cid); ?>" data-crypto="<?php echo esc_attr($cid); ?>">
            <h2><img class="nmm-tab-icon" src="<?php echo esc_url($crypto->get_logo_file_path()); ?>" alt="" />
                <?php echo esc_html($crypto->get_name() . ' (' . $cid . ')'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Markup/Markdown %</th>
                    <td>
                        <input type="number" step="0.1" min="-99.9" max="100"
                               name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($cid); ?>_markup]"
                               value="<?php echo esc_attr(self::value($values, $cid . '_markup', '0.0')); ?>" />
                        <p class="description">This will increase/decrease the amount of cryptocurrency the customer will owe for the order. (4.8 = 4.8% markup, -10.0 = 10% markdown). Only the crypto amount changes; the fiat value shown to the customer stays the same.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Mode</th>
                    <td>
                        <fieldset class="nmm-mode-select">
                            <label><input type="radio" name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($cid); ?>_mode]" value="0" <?php checked($mode, '0'); ?> /> Classic Mode</label><br />
                            <?php if ($crypto->has_autopay()) : ?>
                                <label><input type="radio" name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($cid); ?>_mode]" value="1" <?php checked($mode, '1'); ?> /> Autopay Mode <strong>(BETA)</strong></label><br />
                            <?php endif; ?>
                            <?php if ($crypto->has_hd()) : ?>
                                <label><input type="radio" name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($cid); ?>_mode]" value="2" <?php checked($mode, '2'); ?> /> Privacy Mode</label>
                            <?php endif; ?>
                        </fieldset>
                    </td>
                </tr>

                <?php if ($crypto->has_autopay()) : ?>
                <tr class="nmm-requires-mode" data-modes="1">
                    <th scope="row">Autopay Disclaimer</th>
                    <td>
                        <div class="notice notice-warning inline nmm-inline-notice">
                            <p>Please note Autopay Mode is still in <strong>beta</strong>. There is no guarantee every order will be processed correctly. If you have any questions contact us at support@nomiddlemancrypto.io.</p>
                            <p><strong>Adjusting the following settings can improve Autopay accuracy:</strong></p>
                            <ul>
                                <li><strong>Wallet Addresses:</strong> Adding more addresses greatly increases autopay reliability while increasing privacy. <em>We suggest having as many addresses as orders you get an hour in that cryptocurrency.</em></li>
                                <li><strong>Order Cancellation Timer:</strong> Reducing this will not only increase autopay reliability but also reduce the effects of volatility. <em>We suggest a value of 1 hour for high throughput stores.</em></li>
                                <li><strong>Auto-Confirm Percentage:</strong> Do not touch this unless you know what you are doing.</li>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <tr class="nmm-requires-mode" data-modes="0,1">
                    <th scope="row">Wallet Addresses</th>
                    <td>
                        <div class="nmm-multi-text" data-field="<?php echo esc_attr($cid); ?>_addresses">
                            <?php
                            $addresses = self::value($values, $cid . '_addresses', array(''));
                            if (!is_array($addresses) || count($addresses) === 0) {
                                $addresses = array('');
                            }
                            foreach ($addresses as $address) : ?>
                                <div class="nmm-multi-text-row">
                                    <input type="text" class="regular-text"
                                           name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($cid); ?>_addresses][]"
                                           value="<?php echo esc_attr($address); ?>" />
                                    <button type="button" class="button-link nmm-multi-text-remove" aria-label="Remove address">Remove</button>
                                </div>
                            <?php endforeach; ?>
                            <button type="button" class="button nmm-multi-text-add">Add Address</button>
                        </div>
                    </td>
                </tr>

                <?php if ($crypto->has_hd()) : ?>
                <tr class="nmm-requires-mode" data-modes="2">
                    <th scope="row">Privacy Mode MPK</th>
                    <td>
                        <textarea rows="3" class="large-text nmm-mpk-input" id="<?php echo esc_attr($cid); ?>_hd_mpk-textarea"
                                  data-crypto="<?php echo esc_attr($cid); ?>"
                                  name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($cid); ?>_hd_mpk]"><?php echo esc_textarea(self::value($values, $cid . '_hd_mpk', '')); ?></textarea>
                        <p class="description">Your HD Wallet Master Public Key. We highly recommend using a brand new MPK for each store you run. You run the risk of address reuse and incorrectly processed orders if you use your MPK for multiple stores and/or purposes.</p>
                    </td>
                </tr>
                <tr class="nmm-requires-mode" data-modes="2">
                    <th scope="row">Privacy Mode Sample Addresses</th>
                    <td>
                        <p class="description nmm-danger"><strong>PLEASE VERIFY YOU CONTROL THESE ADDRESSES BEFORE SAVING OR ELSE LOSS OF FUNDS WILL OCCUR!</strong></p>
                        <div class="nmm-sample-addresses" data-crypto="<?php echo esc_attr($cid); ?>">
                            <?php for ($i = 0; $i < 3; $i++) : ?>
                                <input type="text" class="regular-text" readonly="readonly"
                                       id="<?php echo esc_attr($cid); ?>_hd_mpk_sample_addresses-<?php echo $i; ?>"
                                       value="" placeholder="Addresses will be generated when a valid MPK is entered" />
                            <?php endfor; ?>
                        </div>
                        <p class="description">Due to lack of convention around MPK xpub prefixes, it is not possible to guess which address format an xpub should generate. Only legacy addresses starting with "1" are generated.</p>
                    </td>
                </tr>
                <?php
                self::render_number_row($cid . '_hd_percent_to_process', 'HD Wallet Auto-Confirm Percentage',
                    self::value($values, $cid . '_hd_percent_to_process', '1.000'),
                    '0.800', '1.000', '0.001', '2',
                    'Privacy Mode will automatically confirm payments that are this percentage of the total amount requested. (1 = 100%), (0.94 = 94%)');
                self::render_number_row($cid . '_hd_required_confirmations', 'Privacy Mode Required Confirmations',
                    self::value($values, $cid . '_hd_required_confirmations', '2'),
                    '0', '100', '1', '2',
                    'The number of confirmations a payment needs before it is considered a valid payment.');
                self::render_number_row($cid . '_hd_order_cancellation_time_hr', 'Privacy Mode Order Cancellation Timer (hr)',
                    self::value($values, $cid . '_hd_order_cancellation_time_hr', '24'),
                    '0.01', '168', '0.01', '2',
                    'Hours that have to elapse before an order is cancelled automatically. (1.5 = 1 hour 30 minutes)');
                endif; ?>

                <?php if ($crypto->has_autopay()) :
                self::render_number_row($cid . '_autopayment_percent_to_process', 'Auto-Confirm Percentage',
                    self::value($values, $cid . '_autopayment_percent_to_process', '0.9999'),
                    '0.9850', '1.0000', '0.0001', '1',
                    'Auto-Payment will automatically confirm payments within this percentage of the total requested. Contact support@nomiddlemancrypto.io before changing this value.');
                if ($crypto->needs_confirmations()) {
                    self::render_number_row($cid . '_autopayment_required_confirmations', 'Required Confirmations',
                        self::value($values, $cid . '_autopayment_required_confirmations', '2'),
                        '0', '100', '1', '1',
                        'The number of confirmations a payment needs before it is considered a valid payment.');
                }
                self::render_number_row($cid . '_autopayment_order_cancellation_time_hr', 'Order Cancellation Timer (hr)',
                    self::value($values, $cid . '_autopayment_order_cancellation_time_hr', '1'),
                    '0.01', '168', '0.01', '1',
                    'Hours that have to elapse before an order is cancelled automatically. (1.5 = 1 hour 30 minutes)');
                endif; ?>
            </table>
        </div>
        <?php
    }

    private static function render_text_row($id, $title, $value, $desc) {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($title); ?></label></th>
            <td>
                <input type="text" class="regular-text" id="<?php echo esc_attr($id); ?>"
                       name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($id); ?>]"
                       value="<?php echo esc_attr($value); ?>" />
                <p class="description"><?php echo esc_html($desc); ?></p>
            </td>
        </tr>
        <?php
    }

    private static function render_textarea_row($id, $title, $value, $desc) {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($title); ?></label></th>
            <td>
                <textarea rows="4" class="large-text" id="<?php echo esc_attr($id); ?>"
                          name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($id); ?>]"><?php echo esc_textarea($value); ?></textarea>
                <p class="description"><?php echo esc_html($desc); ?></p>
            </td>
        </tr>
        <?php
    }

    // $modes: comma-separated list of crypto modes ('0','1','2') the row applies to
    private static function render_number_row($id, $title, $value, $min, $max, $step, $modes, $desc) {
        ?>
        <tr class="nmm-requires-mode" data-modes="<?php echo esc_attr($modes); ?>">
            <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($title); ?></label></th>
            <td>
                <input type="number" id="<?php echo esc_attr($id); ?>"
                       min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>"
                       name="<?php echo esc_attr(NMM_REDUX_ID); ?>[<?php echo esc_attr($id); ?>]"
                       value="<?php echo esc_attr($value); ?>" />
                <p class="description"><?php echo esc_html($desc); ?></p>
            </td>
        </tr>
        <?php
    }
}

NMM_Admin::init();
