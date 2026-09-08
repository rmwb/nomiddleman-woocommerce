<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMMPRO_Validation {

	/**
	 * Sanitize callback for register_setting on the NMMPRO_REDUX_ID option.
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

		$oldValues = NMMPRO_Compat::get_option(NMMPRO_REDUX_ID, array());

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

		// The RPC password field is write-only: the admin page always renders it
		// blank so the secret never appears in page source. An empty submission
		// therefore means "keep the stored password", and clearing is an explicit
		// checkbox action instead.
		$clearRpcPassword = !empty($newValues['XMR_wallet_rpc_password_clear']);
		// The checkbox is an action, not a state - it must never persist in the
		// saved options or every future save would silently re-clear the password.
		unset($newValues['XMR_wallet_rpc_password_clear']);

		if ((NMMPRO_Compat::config('NMMPRO_XMR_RPC_PASSWORD') !== null)) {
			// Constant wins at read time (NMMPRO_Settings::get_xmr_rpc_password); the
			// admin field is disabled, so leave the stored option untouched and
			// removing the constant restores the previous behaviour.
			if (array_key_exists('XMR_wallet_rpc_password', (array) $oldValues)) {
				$newValues['XMR_wallet_rpc_password'] = $oldValues['XMR_wallet_rpc_password'];
			}
			else {
				unset($newValues['XMR_wallet_rpc_password']);
			}
		}
		else if (isset($newValues['XMR_wallet_rpc_password']) && $newValues['XMR_wallet_rpc_password'] !== '') {
			// A typed-in password beats the clear checkbox when both are
			// submitted: honouring clear here would silently discard the new
			// credential and break authenticated RPC access to a wallet that
			// may have funded orders pending verification.
		}
		else if ($clearRpcPassword) {
			$newValues['XMR_wallet_rpc_password'] = '';
		}
		else {
			$newValues['XMR_wallet_rpc_password'] = isset($oldValues['XMR_wallet_rpc_password'])
				? (string) $oldValues['XMR_wallet_rpc_password']
				: '';
		}

		// The gateway title is printed by WooCommerce without escaping, so it
		// must be plain text. Strip all markup here (site admins on multisite
		// lack unfiltered_html and must not be able to inject <script>).
		if (isset($newValues['payment_label'])) {
			$newValues['payment_label'] = sanitize_text_field($newValues['payment_label']);
		}

		// Merchants routinely paste wallet addresses with stray whitespace.
		// Address validation is now strict (checksums, anchored patterns), so
		// an invisible trailing space would fail the save; whitespace can
		// never be part of any supported address format, so trimming here is
		// always safe and must happen BEFORE validate() reads the values.
		foreach (array_keys(NMMPRO_Cryptocurrencies::get()) as $cryptoId) {
			$addressesKey = $cryptoId . '_addresses';
			if (isset($newValues[$addressesKey]) && is_array($newValues[$addressesKey])) {
				$newValues[$addressesKey] = array_map(function($address) {
					return is_string($address) ? trim($address, " \n\r\t\v\x00") : $address;
				}, $newValues[$addressesKey]);
			}
		}

		return self::validate($newValues, (array) $oldValues);
	}

	private static function validate($newValues, $oldValues) {
		$oldSettings = new NMMPRO_Settings($oldValues);
		$newSettings = new NMMPRO_Settings($newValues);

		$atLeastOneInvalidCrypto = false;
		$errorMessages = [];

		// Solana RPC endpoint. Blank means "use the built-in public default", so
		// only a value the merchant actually typed is checked - with the same
		// guard that runs before every request (scheme, no embedded credentials,
		// and no private/loopback/link-local target), so a URL that would be
		// refused at fetch time can never be saved and silently break Autopay.
		// An unusable value is reverted rather than stored: verification keeps
		// working against the previous endpoint while the merchant fixes it.
		$solRpcUrl = $newSettings->get_sol_rpc_url();

		if ($solRpcUrl !== '' && class_exists('NMMPRO_Blockchain')) {
			$solTarget = NMMPRO_Blockchain::validate_sol_rpc_url($solRpcUrl);

			if (is_wp_error($solTarget)) {
				$oldSolRpcUrl = $oldSettings->get_sol_rpc_url();
				$newValues['SOL_rpc_url'] = $oldSolRpcUrl;

				/* translators: 1: the rejected URL, 2: the reason it was rejected */
				$errorMessages[] = sprintf(__('The Solana RPC endpoint %1$s was not saved: %2$s', 'nomiddleman-crypto-payments-for-woocommerce'),
										   esc_html($solRpcUrl),
										   esc_html($solTarget->get_error_message()));

				if ($oldSolRpcUrl === '') {
					/* translators: %s: the default public Solana RPC endpoint URL */
					$errorMessages[] = sprintf(__('Solana verification is using the default public endpoint %s until a valid one is saved.', 'nomiddleman-crypto-payments-for-woocommerce'),
											   esc_html(NMMPRO_Blockchain::sol_default_rpc_url()));
				}
			}
		}

		foreach (NMMPRO_Cryptocurrencies::get() as $crypto) {
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

				// Autopay confirms an order by looking the payment address up
				// on a public block explorer, so in Autopay mode a merely
				// well-formed address is not enough - it also has to be a form
				// the explorer can report on. Zcash shielded/Unified/TEX
				// addresses are not (see
				// NMMPRO_Address::is_autopay_verifiable_form): the merchant would
				// RECEIVE the money while the order sat unpaid and was then
				// auto-cancelled. This is the layer that must enforce it,
				// because it is the only place that knows the coin's mode AND
				// the only writer of the carousel buffer - filtering here means
				// an unverifiable address is never stocked, so NMMPRO_Carousel
				// physically cannot hand one out at checkout.
				//
				// These addresses stay in the saved settings (they are valid,
				// just not Autopay-usable), so switching the coin back to
				// Classic mode restores them untouched.
				$requireAutopayVerifiable = $newSettings->autopay_enabled($cryptoId);
				$unverifiableAddresses = [];
				$addresses = $newSettings->get_addresses($cryptoId);

				foreach ($addresses as $ind => $address) {
					if (NMMPRO_Cryptocurrencies::is_valid_wallet_address($cryptoId, $address)) {
						$address = trim($address, " \n\r\t\v\x00");

						if ($requireAutopayVerifiable && !NMMPRO_Address::is_autopay_verifiable_form($cryptoId, $address)) {
							$unverifiableAddresses[] = $address;
							continue;
						}

                        $carouselAddresses[] = $address;
                        $hasValidWalletAddress = true;
                    }
				}

				if (count($unverifiableAddresses) > 0) {
					/* translators: 1: cryptocurrency name, 2: comma-separated list of the affected addresses */
					$errorMessages[] = sprintf(__('%1$s Autopay confirms payments by looking the address up on a public block explorer, and these saved addresses cannot be looked up that way, so they will not be given to customers: %2$s. Use a transparent t-address (t1... or t3...) for Zcash Autopay - shielded (zs1... or z...), Unified (u1...) and TEX (tex1...) addresses cannot be automatically verified - or switch this cryptocurrency to Classic mode.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName, esc_html(implode(', ', $unverifiableAddresses)));
				}

				if (! $hasValidWalletAddress) {
					$invalidCryptoSettings = true;
					$atLeastOneInvalidCrypto = true;

					if (count($unverifiableAddresses) > 0) {
						/* translators: %1$s: cryptocurrency name */
						$errorMessages[] = sprintf(__('%1$s has no Autopay-verifiable wallet address. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);

						// The coin is disabled just below, but a buffer stocked
						// by an EARLIER save could still be holding the now
						// unusable addresses. Clear it so no code path can
						// reach one.
						$carouselRepo = new NMMPRO_Carousel_Repo();
						$carouselRepo->set_buffer($cryptoId, array());
					}
					else {
						/* translators: %1$s: cryptocurrency name */
						$errorMessages[] = sprintf(__('%1$s has no valid wallet addresses. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
					}
				}
				else {
					$carouselRepo = new NMMPRO_Carousel_Repo();
					$carouselRepo->set_buffer($cryptoId, $carouselAddresses);
				}
			}
			else if ($cryptoSelected && $newSettings->hd_enabled($cryptoId)) {
				$mpk = $newSettings->get_mpk($cryptoId);

				if (NMMPRO_Util::p_enabled()) {
					if (!NMMPRO_Hd::is_valid_mpk($cryptoId, $mpk)) {
						$invalidCryptoSettings = true;
						$atLeastOneInvalidCrypto = true;
						/* translators: %1$s: cryptocurrency name */
							$errorMessages[] = sprintf(__('%1$s has an invalid HD MPK. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
					}
				}
				else {
					if (NMMPRO_Hd::is_valid_ypub($mpk) || NMMPRO_Hd::is_valid_zpub($mpk)) {
						$invalidCryptoSettings = true;
						$atLeastOneInvalidCrypto = true;
						if (NMMPRO_Hd::is_valid_mpk($cryptoId, $mpk)) {
							/* translators: %s: cryptocurrency name */
							$errorMessages[] = sprintf(__('Please use an xpub MPK. Disabling %s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
						}
						else {
							/* translators: %1$s: cryptocurrency name */
							$errorMessages[] = sprintf(__('%1$s has an invalid HD MPK. Disabling %1$s.', 'nomiddleman-crypto-payments-for-woocommerce'), $cryptoName);
						}
					}
					else {
						if (!NMMPRO_Hd::is_valid_xpub($mpk)) {
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
				if (!NMMPRO_Cryptocurrencies::is_valid_wallet_address($cryptoId, $address)) {
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

			if (NMMPRO_Util::p_enabled()) {
				if (!NMMPRO_Hd::is_valid_mpk($cryptoId, $newSettings->get_mpk($cryptoId))) {
					unset($newValues[$cryptoId . '_hd_mpk']);
				}
			}
			else {
				if (!NMMPRO_Hd::is_valid_xpub($newSettings->get_mpk($cryptoId))) {
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
