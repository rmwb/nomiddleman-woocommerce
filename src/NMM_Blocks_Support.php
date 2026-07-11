<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class NMM_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'nmmpro_gateway';

    public function initialize() {
        $this->settings = get_option('woocommerce_nmmpro_gateway_settings', array());
    }

    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'nmm-blocks',
            plugins_url('assets/js/nmm-blocks.js', NMM_PLUGIN_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            NMM_VERSION,
            true
        );

        return array('nmm-blocks');
    }

    public function get_payment_method_data() {
        $nmmSettings = new NMM_Settings(get_option(NMM_REDUX_ID));

        $cryptos = array();
        foreach (NMM_Cryptocurrencies::get_alpha() as $crypto) {
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
        );
    }
}
