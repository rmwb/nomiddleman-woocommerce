<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Validation {

	/**
	 * Sanitize callback for register_setting on the NMM_REDUX_ID option.
	 * Ports the old Redux before_validation filter: strips invalid wallet
	 * addresses, validates MPKs, disables misconfigured cryptos, refreshes
	 * the carousel buffer, and surfaces errors as admin notices.
	 */
	public static function sanitize_options($newValues) {
		// WordPress can invoke the sanitize callback twice on the first save
		static $alreadyRan = false;
		if ($alreadyRan) {
			return $newValues;
		}
		$alreadyRan = true;

		$oldValues = get_option(NMM_REDUX_ID, array());

		if (!is_array($newValues)) {
			return $oldValues;
		}

		// Unchecked checkbox groups are absent from the POST entirely
		if (!array_key_exists('crypto_select', $newValues)) {
			$newValues['crypto_select'] = array();
		}
		if (!array_key_exists('selected_price_apis', $newValues)) {
			$newValues['selected_price_apis'] = array();
		}

		// Keys not present in the submitted form must survive the save
		$newValues = array_merge((array) $oldValues, $newValues);

		// The gateway title is printed by WooCommerce without escaping, so it
		// must be plain text. Strip all markup here (site admins on multisite
		// lack unfiltered_html and must not be able to inject <script>).
		if (isset($newValues['payment_label'])) {
			$newValues['payment_label'] = sanitize_text_field($newValues['payment_label']);
		}

		return self::validate($newValues, (array) $oldValues);
	}

	private static function validate($newValues, $oldValues) {
		$oldSettings = new NMM_Settings($oldValues);
		$newSettings = new NMM_Settings($newValues);

		$atLeastOneInvalidCrypto = false;
		$errorMessages = [];

		foreach (NMM_Cryptocurrencies::get() as $crypto) {
			$invalidCryptoSettings = false;
			$cryptoId = $crypto->get_id();
			$cryptoName = $crypto->get_name();

			// Only enforce mode requirements for cryptos the merchant enabled
			$cryptoSelected = $newSettings->crypto_selected($cryptoId);

			if ($cryptoId === 'XMR' && $cryptoSelected && $newSettings->autopay_enabled('XMR')) {
				$rpcUrl = $newSettings->get_xmr_rpc_url();

				if (filter_var($rpcUrl, FILTER_VALIDATE_URL) === false) {
					$invalidCryptoSettings = true;
					$atLeastOneInvalidCrypto = true;
					$errorMessages[] = __('Monero Autopay needs a valid monero-wallet-rpc URL. Disabling Monero.', 'nomiddleman-crypto-payments-for-woocommerce');
				}
			}
			else if ($cryptoSelected && ($newSettings->basic_enabled($cryptoId) || $newSettings->autopay_enabled($cryptoId))) {
				$carouselAddresses = [];
				$hasValidWalletAddress = false;
				$addresses = $newSettings->get_addresses($cryptoId);

				foreach ($addresses as $ind => $address) {
					if (NMM_Cryptocurrencies::is_valid_wallet_address($cryptoId, $address)) {
                        $carouselAddresses[] = trim($address);
                        $hasValidWalletAddress = true;
                    }
				}
				if (! $hasValidWalletAddress) {
					$invalidCryptoSettings = true;
					$atLeastOneInvalidCrypto = true;
					/* translators: %1$s: cryptocurrency name */
					$errorMessages[] = sprintf(__('%1$s has no valid wallet addresses. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
				}
				else {
					$carouselRepo = new NMM_Carousel_Repo();
					$carouselRepo->set_buffer($cryptoId, $carouselAddresses);
				}
			}
			else if ($cryptoSelected && $newSettings->hd_enabled($cryptoId)) {
				$mpk = $newSettings->get_mpk($cryptoId);

				if (NMM_Util::p_enabled()) {
					if (!NMM_Hd::is_valid_mpk($cryptoId, $mpk)) {
						$invalidCryptoSettings = true;
						$atLeastOneInvalidCrypto = true;
						/* translators: %1$s: cryptocurrency name */
							$errorMessages[] = sprintf(__('%1$s has an invalid HD MPK. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
					}
				}
				else {
					if (NMM_Hd::is_valid_ypub($mpk) || NMM_Hd::is_valid_zpub($mpk)) {
						$invalidCryptoSettings = true;
						$atLeastOneInvalidCrypto = true;
						if (NMM_Hd::is_valid_mpk($cryptoId, $mpk)) {
							/* translators: %s: cryptocurrency name */
							$errorMessages[] = sprintf(__('Please use an xpub MPK. Disabling %s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
						}
						else {
							/* translators: %1$s: cryptocurrency name */
							$errorMessages[] = sprintf(__('%1$s has an invalid HD MPK. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
						}
					}
					else {
						if (!NMM_Hd::is_valid_xpub($mpk)) {
							$invalidCryptoSettings = true;
							$atLeastOneInvalidCrypto = true;
							/* translators: %1$s: cryptocurrency name */
							$errorMessages[] = sprintf(__('%1$s has an invalid HD MPK. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
						}
					}
				}
			}

			// standard validation not determined by what mode is selected
			// strip out invalid data from settings
			$invalidAddressKeys = [];
			foreach ($newSettings->get_addresses($cryptoId) as $k => $address) {
				if (!NMM_Cryptocurrencies::is_valid_wallet_address($cryptoId, $address)) {
					if ($address !== '') {
						$invalidAddressKeys[] = $k;
						/* translators: 1: cryptocurrency name, 2: the invalid address */
						$errorMessages[] = sprintf(__('%1$s has invalid address: %2$s', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName, esc_html($address));
					}
					else {
						$invalidAddressKeys[] = $k;
                    }
                }
			}
			foreach ($invalidAddressKeys as $k) {
				if ($k > 0) {
					unset($newValues[$cryptoId . '_addresses'][$k]);
				}
				else {
					$newValues[$cryptoId . '_addresses'][$k] = '';
				}
			}
			if (array_key_exists($cryptoId . '_addresses', $newValues) && is_array($newValues[$cryptoId . '_addresses'])) {
				$newValues[$cryptoId . '_addresses'] = array_values($newValues[$cryptoId . '_addresses']);
			}

			if (NMM_Util::p_enabled()) {
				if (!NMM_Hd::is_valid_mpk($cryptoId, $newSettings->get_mpk($cryptoId))) {
					unset($newValues[$cryptoId . '_hd_mpk']);
				}
			}
			else {
				if (!NMM_Hd::is_valid_xpub($newSettings->get_mpk($cryptoId))) {
					unset($newValues[$cryptoId . '_hd_mpk']);
				}
			}

			if ($invalidCryptoSettings) {
				$newValues[$cryptoId . '_mode'] = null;
			}
		} // foreach

		if (!$newSettings->price_api_selected()) {
			$newValues['selected_price_apis'] = ['0'];
		}

		foreach ($errorMessages as $msg) {
			add_settings_error('nmmpro_options', 'nmmpro_options_error', $msg, 'error');
		}

		return $newValues;
	}
}

?>
