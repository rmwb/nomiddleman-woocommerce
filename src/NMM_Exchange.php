<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Class that communicates with various exchanges via HTTP
class NMM_Exchange {

    // Maps plugin crypto IDs (ticker symbols) to CoinGecko coin IDs
    private static $coingeckoIds = array(
        'BTC' => 'bitcoin',
        'LTC' => 'litecoin',
        'QTUM' => 'qtum',
        'DASH' => 'dash',
        'DOGE' => 'dogecoin',
        'XMY' => 'myriadcoin',
        'BTX' => 'bitcore',
        'ETH' => 'ethereum',
        'DGB' => 'digibyte',
        'ZEC' => 'zcash',
        'DCR' => 'decred',
        'ADA' => 'cardano',
        'XTZ' => 'tezos',
        'TRX' => 'tron',
        'XLM' => 'stellar',
        'BCH' => 'bitcoin-cash',
        'EOS' => 'eos',
        'BSV' => 'bitcoin-cash-sv',
        'XRP' => 'ripple',
        'ONION' => 'deeponion',
        'BLK' => 'blackcoin',
        'ETC' => 'ethereum-classic',
        'LSK' => 'lisk',
        'XEM' => 'nem',
        'WAVES' => 'waves',
        'GRS' => 'groestlcoin',
        'APL' => 'apollo',
        'HOT' => 'holotoken',
        'LINK' => 'chainlink',
        'BAT' => 'basic-attention-token',
        'MKR' => 'maker',
        'OMG' => 'omisego',
        'REP' => 'augur',
        'GNO' => 'gnosis',
        'MLN' => 'melon',
        'ZRX' => '0x',
        'USDC' => 'usd-coin',
        'XMR' => 'monero',
        'VRC' => 'vericoin',
        'BTG' => 'bitcoin-gold',
        'VET' => 'vechain',
        'BCD' => 'bitcoin-diamond',
        'BCN' => 'bytecoin',
        'BNB' => 'binancecoin',
        'GUSD' => 'gemini-dollar',
        'POT' => 'potcoin',
        'ONT' => 'ontology',
        'MIOTA' => 'iota',
        'USDT' => 'tether',
        'USDTTRX' => 'tether',
        'USDTPOL' => 'tether',
        'USDTARB' => 'tether',
        'USDCPOL' => 'usd-coin',
        'USDCARB' => 'usd-coin',
        'USDCBAS' => 'usd-coin',
        'DAI' => 'dai',
        'PYUSD' => 'paypal-usd',
        'SOL' => 'solana',
    );

	// this function converts other WooCommerce currencies to USD because the crypto exchanges only have prices in USD
    public static function get_order_total_in_usd($total, $fromCurr) {

        if ($fromCurr === 'USD') {
            return $total;
        }

        $transientKey = $fromCurr . '_to_USD';
        $conversionRate = get_transient( $transientKey );

        if ($conversionRate !== false && is_numeric($conversionRate)) {
            return $total * $conversionRate;
        }

        // Primary: Frankfurter (ECB reference rates, no API key required)
        $response = wp_remote_get('https://api.frankfurter.dev/v1/latest?base=' . rawurlencode($fromCurr) . '&symbols=USD');

        if (!is_wp_error($response) && $response['response']['code'] === 200) {
            $body = json_decode($response['body']);

            if (isset($body->rates->USD) && $body->rates->USD > 0) {
                $conversionRate = (float) $body->rates->USD;
                set_transient($transientKey, $conversionRate, 600);

                return $total * $conversionRate;
            }
        }

        // Fallback: open.er-api.com (no API key, wider currency coverage)
        $response = wp_remote_get('https://open.er-api.com/v6/latest/' . rawurlencode($fromCurr));

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            throw new \Exception(esc_html__('Could not reach the currency conversion service. Please try again.', 'nomiddleman-crypto-payments-for-woocommerce'));
        }

        $body = json_decode($response['body']);

        if (!isset($body->rates->USD) || $body->rates->USD <= 0) {
            /* translators: %s: currency code */
            throw new \Exception(sprintf(esc_html__('Could not convert %s to USD. Please try again.', 'nomiddleman-crypto-payments-for-woocommerce'), esc_html($fromCurr)));
        }

        $conversionRate = (float) $body->rates->USD;

        set_transient($transientKey, $conversionRate, 600);

        return $total * $conversionRate;
    }

    /**
     * Average USD price across the merchant's selected exchange APIs.
     * Each fetcher caches its result in a transient for $updateInterval, so
     * repeat calls inside that window cost no HTTP requests.
     */
    public static function get_average_usd_price($cryptoId, $updateInterval, $selectedPriceApis) {
        $prices = array();

        if (in_array('0', $selectedPriceApis)) {
            $price = self::get_coingecko_price($cryptoId, $updateInterval);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        if (in_array('1', $selectedPriceApis)) {
            $price = self::get_hitbtc_price($cryptoId, $updateInterval);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        if (in_array('2', $selectedPriceApis)) {
            $price = self::get_gateio_price($cryptoId, $updateInterval);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        if (in_array('3', $selectedPriceApis)) {
            $price = self::get_binance_price($cryptoId, $updateInterval);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        if (in_array('4', $selectedPriceApis)) {
            $price = self::get_poloniex_price($cryptoId, $updateInterval);
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        if (count($prices) === 0) {
            throw new \Exception(esc_html__('No cryptocurrency exchanges could be reached, please try again.', 'nomiddleman-crypto-payments-for-woocommerce'));
        }

        return array_sum($prices) / count($prices);
    }

    // gets crypto to USD conversion from an API
    public static function get_coingecko_price($cryptoId, $updateInterval) {
        $transientKey = 'coingecko_' . $cryptoId . '_price';
        $coingeckoPrice = get_transient($transientKey);

        // if transient is found in database just return it
        if ($coingeckoPrice !== false) {
            return $coingeckoPrice;
        }

        $geckoId = array_key_exists($cryptoId, self::$coingeckoIds) ? self::$coingeckoIds[$cryptoId] : strtolower($cryptoId);

        $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($geckoId) . '&vs_currencies=usd');

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            NMM_Util::log(__FILE__, __LINE__, print_r($response, true));
            return 0;
        }

        $responseBody = json_decode( $response['body'] );

        if (!isset($responseBody->{$geckoId}->usd)) {
            NMM_Util::log(__FILE__, __LINE__, 'CoinGecko returned no USD price for ' . $geckoId);
            return 0;
        }

        $coingeckoPrice = (float) $responseBody->{$geckoId}->usd;

        //cache value for X min to reduce api calls
        set_transient($transientKey, $coingeckoPrice, $updateInterval);

        return $coingeckoPrice;
    }

    // Deprecated: CryptoCompare now requires an API key. Kept for back-compat with
    // code that calls the old method name; delegates to CoinGecko.
    public static function get_cryptocompare_price($cryptoId, $updateInterval) {
        return self::get_coingecko_price($cryptoId, $updateInterval);
    }

    // gets crypto to USD conversion from an API
    public static function get_hitbtc_price($cryptoId, $updateInterval) {
        $transientKey = 'hitbtc_' . $cryptoId . '_price';
        $hitbtcPrice = get_transient($transientKey);

        if ($hitbtcPrice !== false) {
            return $hitbtcPrice;
        }

        $response = wp_remote_get('https://api.hitbtc.com/api/2/public/ticker/' . $cryptoId . 'USD');

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            return 0;
        }

        $responseBody = json_decode( $response['body']);

        // symbols HitBTC doesn't list get a 200 with an error object
        if (!isset($responseBody->{'last'})) {
            return 0;
        }

        $hitbtcPrice = (float) $responseBody->{'last'};

        set_transient($transientKey, $hitbtcPrice, $updateInterval);
        return $hitbtcPrice;
    }

    // gets crypto to USD conversion from an API
    public static function get_gateio_price($cryptoId, $updateInterval) {
        $transientKey = 'gateio_' . $cryptoId . '_price';
        $gateioPrice = get_transient($transientKey);

        if ($gateioPrice !== false) {
            return $gateioPrice;
        }

        $response = wp_remote_get('https://data.gate.io/api2/1/ticker/' . strtolower($cryptoId) . '_usdt');

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            return 0;
        }

        $responseBody = json_decode( $response['body'] );

        // pairs Gate.io doesn't list get a 200 with an error object
        if (!isset($responseBody->{'last'})) {
            return 0;
        }

        $gateioPrice = (float) $responseBody->{'last'};

        set_transient($transientKey, $gateioPrice, $updateInterval);

        return $gateioPrice;
    }

    // gets crypto to USD conversion from an API
    public static function get_binance_price($cryptoId, $updateInterval) {
        $transientKey = 'binance_' . $cryptoId . '_price';
        $binancePrice = get_transient($transientKey);

        if ($binancePrice !== false) {
            return $binancePrice;
        }

        $response = wp_remote_get('https://api.binance.com/api/v3/ticker/24hr?symbol=' . $cryptoId . 'USDT');

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            return 0;
        }

        $responseBody = json_decode( $response['body']);
        // symbols Binance doesn't list get an error object without lastPrice
        if (!isset($responseBody->{'lastPrice'})) {
            return 0;
        }

        $binancePrice = (float) $responseBody->{'lastPrice'};

        set_transient($transientKey, $binancePrice, $updateInterval);

        return $binancePrice;
    }

    // gets crypto to USD conversion from an API
    public static function get_poloniex_price($cryptoId, $updateInterval) {
        $transientKey = 'poloniex_' . $cryptoId . '_price';
        $poloniexPrice = get_transient($transientKey);

        if ($poloniexPrice !== false) {
            return $poloniexPrice;
        }

        $response = wp_remote_get('https://api.poloniex.com/markets/' . rawurlencode($cryptoId) . '_USDT/price');

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            return 0;
        }

        $responseBody = json_decode($response['body']);

        if (!isset($responseBody->{'price'})) {
            return 0;
        }

        $poloniexPrice = (float) $responseBody->{'price'};

        // if there is no usable price return 0 so it is not used
        if ($poloniexPrice <= 0) {
            return 0;
        }

        set_transient($transientKey, $poloniexPrice, $updateInterval);

        return $poloniexPrice;
    }
}

?>