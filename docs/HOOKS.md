# Developer hooks

All filters provided by Nomiddleman Crypto Payments for WooCommerce.

Add filter callbacks from your theme's `functions.php` or, better, a small
[mu-plugin](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/)
so they survive theme changes.

## `nmm_api_url` — redirect verification requests to your own node

Every **payment-verification** request (the explorer calls listed in the
readme's "External services" section) passes through this filter before it
is sent, for both GET and POST:

```php
$url = apply_filters( 'nmm_api_url', $url );
```

The filter receives a single argument: the full request URL as a string.
There is no coin-ID argument, so match on the hostname:

```php
// Point Bitcoin verification at your own self-hosted mempool instance.
add_filter( 'nmm_api_url', function ( $url ) {
    return str_replace( 'https://mempool.space/', 'https://mempool.example.com/', $url );
} );
```

```php
// Point Ethereum/ERC-20 verification at your own Blockscout instance.
add_filter( 'nmm_api_url', function ( $url ) {
    return str_replace( 'https://eth.blockscout.com/', 'https://blockscout.example.com/', $url );
} );
```

```php
// Append an API key for one specific service.
add_filter( 'nmm_api_url', function ( $url ) {
    if ( strpos( $url, 'api.example-explorer.com' ) !== false ) {
        $url = add_query_arg( 'apikey', 'YOUR-KEY', $url );
    }
    return $url;
} );
```

**Requirements and behavior:**

- The replacement endpoint must speak the **same API** as the service it
  replaces (a self-hosted instance of the same software: mempool/Esplora,
  Blockscout, Insight, Iquidus, etc.). The plugin parses the original
  service's response shape.
- Failure backoff is keyed by hostname, so a redirected host gets its own
  independent backoff state.
- Return the URL unchanged for requests you don't want to touch.

### Known gap: exchange-rate requests are NOT covered

As of v2.9.x the **exchange-rate** fetchers in `NMM_Exchange` (CoinGecko,
HitBTC, Gate.io, Binance, Poloniex, Frankfurter, open.er-api.com) call
`wp_remote_get()` directly and **bypass this filter**. Redirecting rate
lookups to your own price source is not currently possible. This is a known
gap slated to be closed in a future release (along with a second `$context`
argument distinguishing `'verification'` from `'exchange'` calls).

The Monero wallet RPC is deliberately **not** filtered: its URL is already
merchant-configured directly in the settings.

## Checkout and payment behavior

### `nmm_customer_message`

The HTML message shown above the payment details on the thank-you page.

```php
apply_filters( 'nmm_customer_message', $html, $crypto, $orderId, $formattedPrice, $walletAddress );
```

- `$html` (string) — the message configured in settings (post-safe HTML)
- `$crypto` (NMM_Cryptocurrency) — the coin being paid with
- `$orderId` (int), `$formattedPrice` (string), `$walletAddress` (string)

### `nmm_autopay_percent`

The fraction of the requested amount that Autopay accepts as full payment
(e.g. `0.9999`). Lets you loosen or tighten matching per coin or order size.

```php
apply_filters( 'nmm_autopay_percent', $percent, $paymentAmount, $cryptoId, $address );
```

### `nmm_dust_amount`

Extra amount added to the requested crypto total on the thank-you page
(default `0.0`). Can be used to make concurrent order totals unique.

```php
apply_filters( 'nmm_dust_amount', $dust, $cryptoId, $cryptoPerUsd, $roundPrecision, $usdTotal, $cryptoTotal );
```

### `nmm_order_txhash`

The transaction hash as written into the order note when Autopay verifies a
payment — e.g. to turn it into an explorer link.

```php
apply_filters( 'nmm_order_txhash', $txHash, $cryptoId );
```

## Appearance

### `nmm_gateway_icon`

URL of the icon shown next to the payment method at checkout.

```php
add_filter( 'nmm_gateway_icon', function () {
    return 'https://example.com/my-icon.png';
} );
```

### `nmm_settings_page_title`, `nmm_settings_menu_title`, `nmm_settings_display_name`

White-labeling of the admin settings page: the browser title, the wp-admin
menu label, and the heading on the settings screen. Each receives the
default string.

## Advanced / extension hooks

These exist for the legacy paid Privacy extension and are rarely useful
otherwise:

- `nmm_get_hd_address( $cryptoId, $mpk, $index, $hdMode )` — delegates HD
  address derivation to an extension when one is installed.
- `nmm_hd_mode( $mode, $cryptoId )` — the HD derivation mode passed to
  address generation (default `'0'`).
