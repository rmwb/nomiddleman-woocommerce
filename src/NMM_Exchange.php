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
        // Rate lookups run inside page renders, so a short explicit timeout keeps
        // one slow API from stalling checkout (WP's default is 5s per call).
        $response = wp_remote_get('https://api.frankfurter.dev/v1/latest?base=' . rawurlencode($fromCurr) . '&symbols=USD', array('timeout' => 3));

        if (!is_wp_error($response) && $response['response']['code'] === 200) {
            $body = json_decode($response['body']);

            if (isset($body->rates->USD) && $body->rates->USD > 0) {
                $conversionRate = (float) $body->rates->USD;
                set_transient($transientKey, $conversionRate, 600);

                return $total * $conversionRate;
            }
        }

        // Fallback: open.er-api.com (no API key, wider currency coverage)
        $response = wp_remote_get('https://open.er-api.com/v6/latest/' . rawurlencode($fromCurr), array('timeout' => 3));

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
     * How far one source may sit from the median before it is discarded
     * (fraction: 0.05 = 5%). Also the agreement threshold for the two-source
     * rule. Honest venues quoting the same asset differ by fractions of a
     * percent - even the USDT-quoted ones, outside a depeg - so 5% is far
     * above normal cross-exchange spread and far below the gap a stale cache,
     * a wrong pair or a hijacked endpoint produces.
     */
    const RATE_OUTLIER_TOLERANCE = 0.05;

    /**
     * How far a fresh consensus may move from a fresh last-known-good rate
     * before it must be corroborated (fraction: 0.10 = 10%). Sized above the
     * largest move the market realistically makes inside one warm interval and
     * below the size of move that indicates a broken or hostile feed.
     */
    const RATE_JUMP_THRESHOLD = 0.10;

    /**
     * Maximum age of a last-known-good rate that may still be charged when the
     * live fetches produce no trusted price - the "stale tier".
     *
     * 30 minutes. The floor is set by how long a price-API outage or rate-limit
     * lockout typically lasts: CoinGecko 429s and exchange maintenance windows
     * routinely run several minutes, and the cache warmer only re-tries every
     * few minutes, so anything under ~10 minutes would turn a routine blip into
     * a dead checkout. The ceiling is set by how wrong the charge may be: BTC
     * moves under ~1% in a normal half hour and rarely more than ~10% even in a
     * crash, which is the same order as the drift the merchant already accepts
     * by holding a quote open for the whole payment window. Past 30 minutes we
     * are no longer quoting a price anyone can vouch for, so checkout errors
     * instead of charging it. A filter can shorten this (or return 0 to disable
     * the tier entirely); it is hard-capped at RATE_STALE_HARD_CAP so no filter
     * can make the plugin charge yesterday's price.
     */
    const RATE_MAX_STALE_SECONDS = 1800;

    // Absolute ceiling for the stale tier, whatever the filter returns (6 hours).
    const RATE_STALE_HARD_CAP = 21600;

    /**
     * When only one source is configured, a jump it reports cannot be
     * corroborated by a second source - so it is corroborated in TIME instead:
     * the same level has to still be there this many seconds later before it is
     * charged. Long enough that a single bad tick (stale cache, one poisoned
     * response) expires first, short enough that a genuine crash is picked up
     * inside the stale tier's 30-minute window.
     */
    const RATE_JUMP_CORROBORATION_SECONDS = 900;

    // How long a last-known-good rate is retained. Longer than the stale tier
    // on purpose: an expired record is no anchor at all, and the age checks are
    // done against the stored timestamp, not against transient expiry.
    const RATE_GOOD_RETENTION_SECONDS = 86400;

    /**
     * USD price for one unit of $cryptoId, agreed across the merchant's
     * selected exchange APIs. Shared entry point for both callers: the checkout
     * page (NMM_Gateway) and the background cache warmer (NMM_warm_price_caches).
     *
     * The name is historical - this is no longer an arithmetic mean, because a
     * mean lets a single wrong-but-nonzero source drag the price the customer is
     * charged in proportion to how wrong it is. See consensus_price() and
     * evaluate_rate() for the rules; both are pure and unit-tested in
     * tests/test-exchange-rate.php.
     *
     * Each fetcher caches its result in a transient for $updateInterval, so
     * repeat calls inside that window cost no HTTP requests.
     *
     * @throws \Exception when no rate can be trusted (checkout must fail rather
     *                    than charge an unverifiable price).
     */
    public static function get_average_usd_price($cryptoId, $updateInterval, $selectedPriceApis) {
        $candidates = self::collect_prices($cryptoId, $updateInterval, $selectedPriceApis);

        $maxStale = self::filtered_seconds('nmm_rate_max_stale_seconds', self::RATE_MAX_STALE_SECONDS, $cryptoId, 0, self::RATE_STALE_HARD_CAP);
        $options = array(
            'tolerance'     => self::filtered_fraction('nmm_rate_outlier_tolerance', self::RATE_OUTLIER_TOLERANCE, $cryptoId),
            'jump'          => self::filtered_fraction('nmm_rate_jump_threshold', self::RATE_JUMP_THRESHOLD, $cryptoId),
            'max_stale'     => $maxStale,
            'corroboration' => self::filtered_seconds('nmm_rate_jump_corroboration_seconds', self::RATE_JUMP_CORROBORATION_SECONDS, $cryptoId, 60, self::RATE_STALE_HARD_CAP),
        );

        $goodKey = 'nmm_rate_good_' . $cryptoId;
        $pendingKey = 'nmm_rate_pending_' . $cryptoId;

        $lastGood = get_transient($goodKey);
        $lastGood = is_array($lastGood) ? $lastGood : null;
        $pending = get_transient($pendingKey);
        $pending = is_array($pending) ? $pending : null;

        $now = time();
        $state = self::evaluate_rate($candidates, $lastGood, $pending, $now, $options);

        // Persist (or clear) the pending single-source move.
        if ($state['pending'] === null) {
            if ($pending !== null) {
                delete_transient($pendingKey);
            }
        }
        else {
            set_transient($pendingKey, $state['pending'], 2 * HOUR_IN_SECONDS);
        }

        foreach ($state['warnings'] as $warning) {
            self::log_rate_warning($cryptoId, $warning);
        }

        if ($state['price'] !== null) {
            set_transient($goodKey, array('price' => $state['price'], 'time' => $now), self::RATE_GOOD_RETENTION_SECONDS);

            return $state['price'];
        }

        // Stale tier: nothing live can be trusted, so serve the last rate that
        // WAS trusted - but only while it is still young enough to be roughly
        // right, and never silently.
        if ($maxStale > 0 && $lastGood !== null && isset($lastGood['price'], $lastGood['time'])
            && (float) $lastGood['price'] > 0 && ($now - (int) $lastGood['time']) <= $maxStale) {

            NMM_Util::log(__FILE__, __LINE__, 'Serving last-known-good ' . $cryptoId . ' rate '
                . ($now - (int) $lastGood['time']) . 's old: no trusted live price (' . $state['status'] . ').', 'warning');

            return (float) $lastGood['price'];
        }

        if ($state['status'] === 'none') {
            throw new \Exception(esc_html__('No cryptocurrency exchanges could be reached, please try again.', 'nomiddleman-crypto-payments-for-woocommerce'));
        }

        throw new \Exception(esc_html__('The exchange rate could not be confirmed right now. Please try again in a few minutes.', 'nomiddleman-crypto-payments-for-woocommerce'));
    }

    /**
     * Fetches one price per selected API. Labels are used in the log only; the
     * USDT note records that the venue quotes against Tether, not dollars (a
     * rounding error normally, a real gap during a depeg - which the outlier
     * rule below is what actually contains).
     *
     * @return array label => price (only usable, positive prices are returned)
     */
    private static function collect_prices($cryptoId, $updateInterval, $selectedPriceApis) {
        $selectedPriceApis = (array) $selectedPriceApis;
        $fetchers = array(
            '0' => array('CoinGecko', 'get_coingecko_price'),
            '1' => array('HitBTC', 'get_hitbtc_price'),
            '2' => array('Gate.io (USDT)', 'get_gateio_price'),
            '3' => array('Binance (USDT)', 'get_binance_price'),
            '4' => array('Poloniex (USDT)', 'get_poloniex_price'),
        );

        $prices = array();

        foreach ($fetchers as $apiId => $fetcher) {
            // Loose in_array is deliberate: stored values have been strings and
            // ints over the plugin's life.
            if (!in_array($apiId, $selectedPriceApis)) {
                continue;
            }

            $price = (float) call_user_func(array(__CLASS__, $fetcher[1]), $cryptoId, $updateInterval);

            if ($price > 0) {
                $prices[$fetcher[0]] = $price;
            }
        }

        return $prices;
    }

    /**
     * Median of a list of positive numbers (0.0 when there is nothing to take a
     * median of). Even counts return the midpoint of the two central values.
     */
    public static function median(array $values) {
        $clean = array();

        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                $clean[] = (float) $value;
            }
        }

        $count = count($clean);

        if ($count === 0) {
            return 0.0;
        }

        sort($clean, SORT_NUMERIC);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $clean[$middle];
        }

        // Halve BEFORE adding: two finite quotes near PHP_FLOAT_MAX would
        // overflow to INF through (a + b) / 2, manufacturing the same
        // zero-priced order out of inputs that were each individually sane.
        return ($clean[$middle - 1] / 2) + ($clean[$middle] / 2);
    }

    /**
     * Agrees a single USD price from the candidate prices. Pure: no HTTP, no
     * transients, no logging - the caller does that with what it returns.
     *
     * The rules, and why:
     *
     *   0 sources - nothing to charge. status 'none'; the caller falls back to
     *               the stale tier and otherwise fails checkout. Never 0.
     *   1 source  - no cross-check exists at all, which is also the shipped
     *               default (selected_price_apis => ['0']). The price is used,
     *               because refusing it would break every default install, but
     *               a warning is emitted so the merchant can see they are
     *               trusting one endpoint with the price of every order.
     *   2 sources - a two-element median is just their mean, so a wrong source
     *               still drags it by half its error: there is no median to be
     *               had here. Instead the two must AGREE to within the outlier
     *               tolerance; if they do, the LOWER is used, and if they do not,
     *               the pair is untrusted (status 'disagree') because nothing
     *               present can say which one is lying. The lower quote is the
     *               safe side of a bounded (<= tolerance) disagreement: a too-high
     *               USD price means the customer sends too little crypto and the
     *               merchant is silently underpaid, while a too-low one means the
     *               customer overpays slightly - visible, and refundable.
     *   3+        - median first (one wrong source cannot move a median the way
     *               it moves a mean), then discard every source further than the
     *               tolerance from it, then take the median of the survivors. At
     *               least two sources must survive: if a 3-way split leaves only
     *               the middle value standing, nothing is corroborated and the
     *               set is untrusted.
     *
     * @param array $candidates label => price
     * @return array{price: float|null, status: string, sources: int, kept: array, rejected: array, warnings: array}
     */
    public static function consensus_price(array $candidates, $tolerance = self::RATE_OUTLIER_TOLERANCE) {
        $tolerance = (float) $tolerance;
        $clean = array();

        foreach ($candidates as $label => $price) {
            // is_finite is load-bearing, not belt and braces: a source that
            // answers 1e309 decodes to INF, and INF is both is_numeric() and
            // > 0. An INF rate is stored as last-known-good and then divides
            // the order total to ZERO crypto - which Privacy Mode reads as
            // fully paid (received >= 0), completing an order that received
            // no funds at all.
            if (is_numeric($price) && is_finite((float) $price) && (float) $price > 0) {
                $clean[$label] = (float) $price;
            }
        }

        $result = array(
            'price'    => null,
            'status'   => 'none',
            'sources'  => count($clean),
            'kept'     => array(),
            'rejected' => array(),
            'warnings' => array(),
        );

        if ($result['sources'] === 0) {
            return $result;
        }

        if ($result['sources'] === 1) {
            $labels = array_keys($clean);
            $result['price'] = (float) reset($clean);
            $result['status'] = 'single';
            $result['kept'] = $labels;
            $result['warnings'][] = array(
                'code'    => 'single_source',
                'message' => 'Exchange rate came from a single price source (' . $labels[0] . '): no cross-check is possible, so a stale, mispaired or hijacked response would be charged as-is. Select at least two price APIs under Pricing Options.',
            );

            return $result;
        }

        if ($result['sources'] === 2) {
            $labels = array_keys($clean);
            $values = array_values($clean);
            $low = min($values);
            $high = max($values);
            $spread = ($high - $low) / (($high + $low) / 2);

            if ($spread > $tolerance) {
                $result['status'] = 'disagree';
                $result['rejected'] = $labels;
                $result['warnings'][] = array(
                    'code'    => 'two_source_disagree',
                    'message' => 'The two selected price sources disagree by ' . round($spread * 100, 2) . '% ('
                        . $labels[0] . ' ' . $values[0] . ' vs ' . $labels[1] . ' ' . $values[1]
                        . '); with only two sources neither can be adjudicated, so neither is used.',
                );

                return $result;
            }

            $result['price'] = $low;
            $result['status'] = 'ok';
            $result['kept'] = $labels;

            return $result;
        }

        $median = self::median($clean);
        $kept = array();

        foreach ($clean as $label => $price) {
            if (abs($price - $median) / $median > $tolerance) {
                $result['rejected'][] = $label;
                continue;
            }
            $kept[$label] = $price;
        }

        if (count($kept) < 2) {
            $result['status'] = 'disagree';
            $result['warnings'][] = array(
                'code'    => 'no_consensus',
                'message' => 'No two of the ' . $result['sources'] . ' price sources agree within '
                    . round($tolerance * 100, 2) . '% of their median (' . $median . '); no rate is trusted.',
            );

            return $result;
        }

        if (count($result['rejected']) > 0) {
            $result['warnings'][] = array(
                'code'    => 'outlier_rejected',
                'message' => 'Discarded price source(s) ' . implode(', ', $result['rejected'])
                    . ' as more than ' . round($tolerance * 100, 2) . '% from the median of ' . $median . '.',
            );
        }

        $result['price'] = self::median($kept);
        $result['status'] = 'ok';
        $result['kept'] = array_keys($kept);

        return $result;
    }

    /**
     * consensus_price() plus the movement guard: a fresh consensus that jumps
     * more than the jump threshold away from a FRESH last-known-good rate is not
     * charged until something corroborates it - a second surviving source, or
     * (when the merchant has only one source configured) the same level still
     * being reported at least $corroboration seconds later.
     *
     * The anchor is only honoured while it is itself younger than the stale-tier
     * max age. An older anchor says nothing about what the market should be doing
     * now, and holding checkout to it would reject genuine overnight drift.
     *
     * Pure: the caller loads/stores $lastGood and $pending and does the logging.
     *
     * @param array      $candidates label => price
     * @param array|null $lastGood   array('price' => float, 'time' => int)
     * @param array|null $pending    array('price' => float, 'first_seen' => int)
     * @param int        $now        unix time
     * @param array      $options    tolerance, jump, max_stale, corroboration
     */
    public static function evaluate_rate(array $candidates, $lastGood, $pending, $now, array $options = array()) {
        $tolerance = isset($options['tolerance']) ? (float) $options['tolerance'] : self::RATE_OUTLIER_TOLERANCE;
        $jump = isset($options['jump']) ? (float) $options['jump'] : self::RATE_JUMP_THRESHOLD;
        $maxStale = isset($options['max_stale']) ? (int) $options['max_stale'] : self::RATE_MAX_STALE_SECONDS;
        $corroboration = isset($options['corroboration']) ? (int) $options['corroboration'] : self::RATE_JUMP_CORROBORATION_SECONDS;

        $state = self::consensus_price($candidates, $tolerance);
        $state['pending'] = null;

        if ($state['price'] === null) {
            // Keep any pending move alive: a tick that reached nobody must not
            // erase the corroboration clock a single-source move is running.
            $state['pending'] = is_array($pending) ? $pending : null;

            return $state;
        }

        $anchor = null;
        if (is_array($lastGood) && isset($lastGood['price'], $lastGood['time'])
            && (float) $lastGood['price'] > 0 && ($now - (int) $lastGood['time']) <= $maxStale) {
            $anchor = (float) $lastGood['price'];
        }

        if ($anchor === null) {
            return $state;
        }

        $move = abs($state['price'] - $anchor) / $anchor;

        if ($move <= $jump) {
            return $state;
        }

        if (count($state['kept']) >= 2) {
            $state['warnings'][] = array(
                'code'    => 'jump_corroborated',
                'message' => 'Rate moved ' . round($move * 100, 2) . '% from the last known good value ('
                    . $anchor . ' -> ' . $state['price'] . '); accepted, corroborated by '
                    . count($state['kept']) . ' agreeing sources.',
            );

            return $state;
        }

        $heldLevel = is_array($pending) && isset($pending['price'], $pending['first_seen'])
            && (float) $pending['price'] > 0
            && abs($state['price'] - (float) $pending['price']) / (float) $pending['price'] <= $tolerance;

        if ($heldLevel && ($now - (int) $pending['first_seen']) >= $corroboration) {
            $state['warnings'][] = array(
                'code'    => 'jump_confirmed_over_time',
                'message' => 'Rate moved ' . round($move * 100, 2) . '% from the last known good value ('
                    . $anchor . ' -> ' . $state['price'] . '); accepted after the only configured source held the new level for '
                    . ($now - (int) $pending['first_seen']) . 's.',
            );

            return $state;
        }

        // Keep the ORIGINAL level while the clock runs, not the latest quote.
        // Storing the newest price alongside the original first_seen let a
        // single source ratchet: each tick only had to land within tolerance
        // of the PREVIOUS pending price, so walking up ~4.9% a minute promoted
        // a price four times the anchor after the corroboration window - while
        // never once holding a level. Comparing against the original means a
        // drifting source falls out of tolerance and restarts the clock.
        $state['pending'] = array(
            'price'      => $heldLevel ? (float) $pending['price'] : $state['price'],
            'first_seen' => $heldLevel ? (int) $pending['first_seen'] : (int) $now,
        );
        $state['price'] = null;
        $state['status'] = 'jump_rejected';
        $state['warnings'][] = array(
            'code'    => 'jump_rejected',
            'message' => 'Rejected a ' . round($move * 100, 2) . '% rate move (' . $anchor . ' -> '
                . $state['pending']['price'] . ') reported by a single source with nothing to corroborate it.',
        );

        return $state;
    }

    // Warnings from the pure evaluators. All are 'warning' level - they mean the
    // price a customer is charged is less trustworthy than it should be. The
    // single-source notice is additionally throttled to once an hour per coin:
    // it is a standing configuration fact, not an event, and the cache warmer
    // would otherwise repeat it every few minutes on every default install.
    private static function log_rate_warning($cryptoId, $warning) {
        $message = $cryptoId . ': ' . $warning['message'];

        if ($warning['code'] === 'single_source') {
            $throttleKey = 'nmm_rate_single_warned_' . $cryptoId;

            if (get_transient($throttleKey) !== false) {
                return;
            }

            set_transient($throttleKey, 1, HOUR_IN_SECONDS);
        }

        NMM_Util::log(__FILE__, __LINE__, $message, 'warning');
    }

    // Filtered fraction, clamped so a bad filter cannot disable the guard it
    // configures (a 0 tolerance would reject every source, a 5.0 jump threshold
    // would wave through anything).
    private static function filtered_fraction($filter, $default, $cryptoId) {
        $value = (float) apply_filters($filter, $default, $cryptoId);

        if (!is_finite($value) || $value < 0.001) {
            return 0.001;
        }

        return $value > 1.0 ? 1.0 : $value;
    }

    private static function filtered_seconds($filter, $default, $cryptoId, $min, $max) {
        $value = (int) apply_filters($filter, $default, $cryptoId);

        if ($value < $min) {
            return $min;
        }

        return $value > $max ? $max : $value;
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

        $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($geckoId) . '&vs_currencies=usd', array('timeout' => 3));

        if ( is_wp_error( $response ) || $response['response']['code'] !== 200) {
            NMM_Util::log(__FILE__, __LINE__, 'FAILED API CALL ( coingecko simple/price ): ' . NMM_Util::summarize_response($response));
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

        $response = wp_remote_get('https://api.hitbtc.com/api/2/public/ticker/' . $cryptoId . 'USD', array('timeout' => 3));

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

        $response = wp_remote_get('https://data.gate.io/api2/1/ticker/' . strtolower($cryptoId) . '_usdt', array('timeout' => 3));

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

        $response = wp_remote_get('https://api.binance.com/api/v3/ticker/24hr?symbol=' . $cryptoId . 'USDT', array('timeout' => 3));

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

        $response = wp_remote_get('https://api.poloniex.com/markets/' . rawurlencode($cryptoId) . '_USDT/price', array('timeout' => 3));

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