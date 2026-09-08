<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class NMMPRO_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'nmm_gateway';

    public function initialize() {
        $this->settings = NMMPRO_Compat::get_option('woocommerce_nmm_gateway_settings', array());
    }

    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'nmm-blocks',
            plugins_url('assets/js/nmm-blocks.js', NMMPRO_PLUGIN_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            NMMPRO_VERSION,
            true
        );

        return array('nmm-blocks');
    }

    public function get_payment_method_data() {
        $nmmSettings = new NMMPRO_Settings(NMMPRO_Compat::get_option(NMMPRO_REDUX_ID));

        $cryptos = array();
        foreach (NMMPRO_Cryptocurrencies::get_alpha() as $crypto) {
            if ($nmmSettings->crypto_selected_and_valid($crypto->get_id())) {
                $cryptos[] = array(
                    'id' => $crypto->get_id(),
                    'name' => $crypto->get_name(),
                );
            }
        }

        return array(
            'title' => $nmmSettings->get_customer_gateway_message(),
            'cryptos' => $cryptos,
            'supports' => array('products'),
            'i18n' => array(
                'defaultTitle' => __('Pay with cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce'),
                'chooseLabel' => __('Choose a cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce'),
                'chooseError' => __('Please choose a cryptocurrency.', 'nomiddleman-crypto-payments-for-woocommerce'),
            ),
        );
    }
}
