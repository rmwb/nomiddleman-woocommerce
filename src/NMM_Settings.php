<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Settings {

	/**
	 * Bounds for every numeric setting, keyed by option-name suffix (the stored
	 * key is the crypto id plus this suffix). Single source of truth: the
	 * getters below clamp against it and NMM_Admin renders its min/max/step
	 * from it, so the constraint the browser shows and the one the server
	 * enforces cannot drift apart.
	 *
	 * These values are merchant-supplied, and an HTML min/max is only a hint to
	 * a cooperating browser - a crafted POST, a direct database edit, a bad
	 * import or a damaged option row all bypass it. Left unchecked, the results
	 * are not cosmetic: a negative confirmation requirement accepts unconfirmed
	 * payments, a zero cancellation timer cancels orders the moment they are
	 * placed, and a low processing percentage matches payments well under what
	 * was owed. Clamping in the getter protects every consumer without each one
	 * having to remember to validate.
	 *
	 * Defaults deliberately live at the call sites rather than here: the getter
	 * default (used when a key is absent) and the admin's displayed default
	 * already differ for some settings, and reconciling them would change
	 * behaviour on stores that have never saved the field.
	 */
	const NUMERIC_BOUNDS = array(
		'_markup'                                 => array('min' => -99.9, 'max' => 100.0, 'step' => '0.1'),
		'_hd_percent_to_process'                  => array('min' => 0.8,   'max' => 1.0,   'step' => '0.001'),
		'_hd_required_confirmations'              => array('min' => 0,     'max' => 100,   'step' => '1'),
		'_hd_order_cancellation_time_hr'          => array('min' => 0.01,  'max' => 168.0, 'step' => '0.01'),
		'_autopayment_percent_to_process'         => array('min' => 0.985, 'max' => 1.0,   'step' => '0.0001'),
		'_autopayment_required_confirmations'     => array('min' => 0,     'max' => 100,   'step' => '1'),
		'_autopayment_order_cancellation_time_hr' => array('min' => 0.01,  'max' => 168.0, 'step' => '0.01'),
	);

	private $settings;

	public function __construct($settings) {
		$this->settings = $settings;
	}

	/**
	 * Clamp a stored numeric setting into its documented range.
	 *
	 * @param string $suffix   Key into NUMERIC_BOUNDS.
	 * @param mixed  $value    The raw stored value.
	 * @param string $fallback Returned when the stored value is not a number at
	 *                         all (missing, blank, an array, or junk) - there is
	 *                         no sensible way to clamp a non-number, and the
	 *                         documented default is safer than 0.
	 * @return string
	 */
	private static function clamp_numeric($suffix, $value, $fallback) {
		$bounds = self::NUMERIC_BOUNDS[$suffix];

		$candidate = is_string($value) ? trim($value) : $value;

		if (!is_scalar($candidate) || !is_numeric($candidate)) {
			NMM_Util::log(__FILE__, __LINE__, 'Setting ' . $suffix . ' is not numeric; falling back to ' . $fallback . '.', 'warning');
			return $fallback;
		}

		$number = (float) $candidate;

		if ($number < $bounds['min']) {
			NMM_Util::log(__FILE__, __LINE__, 'Setting ' . $suffix . ' is below its minimum; clamping ' . $number . ' to ' . $bounds['min'] . '.', 'warning');
			return (string) $bounds['min'];
		}

		if ($number > $bounds['max']) {
			NMM_Util::log(__FILE__, __LINE__, 'Setting ' . $suffix . ' is above its maximum; clamping ' . $number . ' to ' . $bounds['max'] . '.', 'warning');
			return (string) $bounds['max'];
		}

		// In range: hand back the stored text, so the merchant's own precision
		// survives the round trip.
		return (string) $candidate;
	}

	public function get_selected_cryptos() {		
		if (is_array($this->settings)) {
			if (array_key_exists('crypto_select', $this->settings)) {
				if (is_array($this->settings['crypto_select'])) {
					return $this->settings['crypto_select'];
				}			
			}
		}
		
		return [];
	}

	public function crypto_selected($cryptoId) {
		if (is_array($this->get_selected_cryptos())) {
			if (in_array($cryptoId, $this->get_selected_cryptos())) {
				return true;
			}
		}
		
		return false;
	}

	public function crypto_selected_and_valid($cryptoId) {
		$modeEnabled = $this->basic_enabled($cryptoId) || $this->autopay_enabled($cryptoId) || $this->hd_enabled($cryptoId);
		$cryptos = NMM_Cryptocurrencies::get();
		$validHd = $cryptos[$cryptoId]->has_hd();
		if ($this->hd_enabled($cryptoId) && !$validHd) {
			return false;
		}
		return $this->crypto_selected($cryptoId) && $modeEnabled;
	}



	public function get_valid_selected_cryptos() {
		$validSelectedCryptos = [];

		foreach (NMM_Cryptocurrencies::get_alpha() as $crypto) {
			if ($this->crypto_selected_and_valid($crypto->get_id())) {
				$validSelectedCryptos[] = $crypto;
			}
		}

		return $validSelectedCryptos;
	}

	public function basic_enabled($cryptoId) {
		return $this->_get_mode($cryptoId) === '0';
	}
	public function autopay_enabled($cryptoId) {
		return $this->_get_mode($cryptoId) === '1';
	}
	public function hd_enabled($cryptoId) {
		return $this->_get_mode($cryptoId) === '2';
	}
	public function get_addresses($cryptoId) {
		$addressesKey = $cryptoId . '_addresses';
		if (is_array($this->settings)) {
			if (array_key_exists($addressesKey, $this->settings)) {
				if (is_array($this->settings[$addressesKey])) {
					return $this->settings[$addressesKey];
				}
			}
		}
		
		return [];		
	}

	public function get_customer_gateway_message() {
		$paymentLabelKey = 'payment_label';
		if (is_array($this->settings)) {
			if (array_key_exists($paymentLabelKey, $this->settings)) {
				return $this->settings[$paymentLabelKey];
			}
		}

		return __('Pay with cryptocurrency', 'nomiddleman-crypto-payments-for-woocommerce');
	}

	public function get_customer_payment_message($crypto) {
		$paymentMessageKey = 'payment_message_html';		

		if (is_array($this->settings)) {
			if (array_key_exists($paymentMessageKey, $this->settings)) {
				return $this->settings[$paymentMessageKey];
			}
		}

		return 'Once you have paid, please check your email for payment confirmation.';
	}

	public function get_next_carousel_address($cryptoId) {
		$carousel = new NMM_Carousel($cryptoId);

		return $carousel->get_next_address();
	}

	public function get_mpk($cryptoId) {
		$mpkKey = $cryptoId . '_hd_mpk';
		if (is_array($this->settings)) {
			if (array_key_exists($mpkKey, $this->settings)) {
				return trim($this->settings[$mpkKey]);
			}
		}
		
		return '';		
	}

	public function get_hd_mode($cryptoId) {
		return apply_filters('nmm_hd_mode', '0', $cryptoId);
	}

	public function get_markup($cryptoId) {
		$markupKey = $cryptoId . '_markup';
		if (is_array($this->settings)) {
			if (array_key_exists($markupKey, $this->settings)) {
				return self::clamp_numeric('_markup', $this->settings[$markupKey], '0.0');
			}
		}

		return '0.0';
	}

	public function get_hd_processing_percent($cryptoId) {
		$hdPercentKey = $cryptoId . '_hd_percent_to_process';

		if (is_array($this->settings)) {
			if (array_key_exists($hdPercentKey, $this->settings)) {
				return self::clamp_numeric('_hd_percent_to_process', $this->settings[$hdPercentKey], '0.99');
			}
		}

		return '0.99';
	}

	public function get_hd_required_confirmations($cryptoId) {
		$hdConfirmationsKey = $cryptoId . '_hd_required_confirmations';

		if (is_array($this->settings)) {
			if (array_key_exists($hdConfirmationsKey, $this->settings)) {
				return round(self::clamp_numeric('_hd_required_confirmations', $this->settings[$hdConfirmationsKey], '2'));
			}
		}

		return '2';
	}

	public function get_hd_cancellation_time($cryptoId) {
		$hdCancellationKey = $cryptoId . '_hd_order_cancellation_time_hr';

		if (is_array($this->settings)) {
			if (array_key_exists($hdCancellationKey, $this->settings)) {
				return self::clamp_numeric('_hd_order_cancellation_time_hr', $this->settings[$hdCancellationKey], '24');
			}
		}

		return '24';
	}

	public function get_autopay_processing_percent($cryptoId) {
		$autopayPercentKey = $cryptoId . '_autopayment_percent_to_process';

		if (is_array($this->settings)) {
			if (array_key_exists($autopayPercentKey, $this->settings)) {
				return self::clamp_numeric('_autopayment_percent_to_process', $this->settings[$autopayPercentKey], '0.999');
			}
		}

		return '0.999';
	}

	public function get_autopay_required_confirmations($cryptoId) {
		$autopayConfirmationsKey = $cryptoId . '_autopayment_required_confirmations';

		if (is_array($this->settings)) {
			if (array_key_exists($autopayConfirmationsKey, $this->settings)) {
				return round(self::clamp_numeric('_autopayment_required_confirmations', $this->settings[$autopayConfirmationsKey], '2'));
			}
		}

		return '2';
	}

	public function get_autopay_cancellation_time($cryptoId) {
		$autopayCancellationKey = $cryptoId . '_autopayment_order_cancellation_time_hr';

		if (is_array($this->settings)) {
			if (array_key_exists($autopayCancellationKey, $this->settings)) {
				return self::clamp_numeric('_autopayment_order_cancellation_time_hr', $this->settings[$autopayCancellationKey], '24');
			}
		}

		return '24';
	}
	
	public function get_xmr_rpc_url() {
		if (is_array($this->settings) && array_key_exists('XMR_wallet_rpc_url', $this->settings)) {
			return trim((string) $this->settings['XMR_wallet_rpc_url']);
		}

		return '';
	}

	public function get_xmr_rpc_user() {
		if (is_array($this->settings) && array_key_exists('XMR_wallet_rpc_user', $this->settings)) {
			return trim((string) $this->settings['XMR_wallet_rpc_user']);
		}

		return '';
	}

	public function get_xmr_rpc_password() {
		if (is_array($this->settings) && array_key_exists('XMR_wallet_rpc_password', $this->settings)) {
			return (string) $this->settings['XMR_wallet_rpc_password'];
		}

		return '';
	}

	public function get_blockcypher_token() {
		$tokenKey = 'blockcypher_token';

		if (is_array($this->settings)) {
			if (array_key_exists($tokenKey, $this->settings)) {
				return trim((string) $this->settings[$tokenKey]);
			}
		}

		return '';
	}

	public function get_selected_price_apis() {
		$priceApiKey = 'selected_price_apis';

		if (is_array($this->settings) && array_key_exists($priceApiKey, $this->settings) && is_array($this->settings[$priceApiKey])) {
			return $this->settings[$priceApiKey];
		}

		return array();
	}

	public function price_api_selected() {
		$priceApiKey = 'selected_price_apis';
		
		if (is_array($this->settings)) {
			if (array_key_exists($priceApiKey, $this->settings)) {
				if (is_array($this->settings[$priceApiKey])) {
					if (count($this->settings[$priceApiKey]) > 0) {
						return true;
					}
				}
			}
		}
		
		return false;
	}

	public function _get_mode($cryptoId) {
		$modeKey = $cryptoId . '_mode';

		if (is_array($this->settings)) {
			if (array_key_exists($modeKey, $this->settings)) {
				return $this->settings[$modeKey];
			}
		}
		
		return '';
	}

	public function add_consumed_tx($cryptoId, $address, $txHash) {
		$settingsKey = 'nmmpro_' . $cryptoId . '_transactions_consumed_for_' . $address;

		$consumedTxs = get_option($settingsKey, array());
		$consumedTxs[] = $txHash;

		// the matcher ignores transactions older than the 3-hour lifetime, so
		// hashes beyond the most recent ones can never be re-matched; cap the
		// list so these options don't grow forever on busy stores
		if (count($consumedTxs) > 200) {
			$consumedTxs = array_slice($consumedTxs, -200);
		}

		update_option($settingsKey, $consumedTxs, false);
	}

	public function tx_already_consumed($cryptoId, $address, $txHash) {
		$settingsKey = 'nmmpro_' . $cryptoId . '_transactions_consumed_for_' . $address;
		$consumedTxs = get_option($settingsKey, array());
		
		if (in_array($txHash, $consumedTxs)) {
			return true;
		}

		return false;
	}
}

?>