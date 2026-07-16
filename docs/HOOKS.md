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

### `nmm_hd_quarantine_seconds`

Privacy Mode (HD) only. When an order dies without paying (cancelled, failed,
expired, or deleted), its derived address is not reused immediately. Instead it
is **quarantined** and re-checked against the block explorer; only an address
that is confirmed to have *no* on-chain history after two successful fresh
checks — spaced at least this many seconds apart, and past the payment expiry —
is returned to the ready pool for reuse. Anything that received funds (or that
cannot be verified) is never reused. This filter sets the minimum spacing
between those checks (default: the coin's order-cancellation window, or 6 hours,
whichever is larger). Raise it to be more conservative.

```php
apply_filters( 'nmm_hd_quarantine_seconds', $seconds, $cryptoId );
```

### `nmm_hd_quarantine_batch`

The maximum number of quarantined addresses re-checked per coin per cron tick
(default `25`). Each one costs a fresh explorer request, so this bounds the
external work a large abandonment burst can trigger under the background job's
lock. The oldest-due addresses are processed first; the rest wait for later
ticks. Raise it if you have a big backlog and headroom, lower it to be gentler
on explorers.

```php
apply_filters( 'nmm_hd_quarantine_batch', $limit, $cryptoId );
```

Reusing a **pristine, never-used** address is deliberate: it keeps a long run of
abandoned checkouts from pushing a later *paid* address beyond your wallet's
**gap limit** (the number of consecutive unused addresses a wallet scans from
the seed — 20 by default in Electrum). See the "gap limit" note in the FAQ for
the recommended wallet-side safeguard.

### `nmm_autopay_scan_budget`

The **baseline** number of distinct unpaid payment addresses the Autopay verifier
checks per cron tick (default `50`). Each non-Monero address costs a block-explorer
request, so spreading the work with a persisted fair cursor (which resumes after
the last address checked and wraps around) keeps a large backlog of abandoned,
unpaid orders from holding the background job's lock for one long tick and starving
payment, expiry, HD and Solana work.

This is a floor, not a hard cap: the plugin raises the budget above it when a
backlog is large enough that sweeping it at the baseline would take longer than the
payment-matching window, because an address must be re-checked within that window
or a just-arrived payment could age out before it is seen. Monero is cheap
regardless of the value — an account's recent incoming transfers are fetched once
per tick and grouped by subaddress locally. Raise the baseline if you want more
frequent checks and have explorer headroom; lower it to be gentler on explorers for
small backlogs.

```php
apply_filters( 'nmm_autopay_scan_budget', $limit );
```

### `nmm_autopay_priority_window`

Seconds of recency that qualify an unpaid payment record for the priority lane
(default `1800`, 30 minutes). Addresses whose payment record was created within
this window are checked on **every** tick, ahead of the fair sweep, so a fresh
customer watching the thank-you page gets their first check on the next tick
even while a large backlog is being swept. The lane is additive to the sweep
budget and capped at the baseline budget, and it never advances the sweep
cursor. Return `0` to disable the lane.

```php
apply_filters( 'nmm_autopay_priority_window', $seconds );
```

### `nmm_sol_retry_global_retention_seconds`

How long a durable Solana retry entry is kept before a global cleanup pass may
delete it, measured from its first failed detail lookup (default: 7 days). This
pass reclaims rows for addresses that are no longer scanned at all — after SOL
Autopay is disabled, or a carousel address is removed or replaced — which the
per-address expiry would otherwise never revisit. The cleanup runs at most once
an hour.

```php
apply_filters( 'nmm_sol_retry_global_retention_seconds', $seconds );
```

For safety the effective value is clamped to at least the Autopay transaction
lifetime plus 30 minutes, so a filter returning zero, a negative number, or a
value shorter than the payment window can never delete a still-live retry entry.
Use it to lengthen retention (e.g. to keep evidence for longer), not to shorten
it below the matching window.

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

### `nmm_xmr_allow_private_rpc`

Whether the merchant-configured Monero wallet RPC URL may point at a private,
loopback, or link-local address. Default: allowed on single-site (the merchant
controls the host), blocked on multisite (site admins are not host admins). The
`NMM_XMR_ALLOW_PRIVATE_RPC` constant sets the default; this filter can override
per URL. Only ever loosen this for an RPC endpoint you intentionally run on a
trusted private network.

```php
apply_filters( 'nmm_xmr_allow_private_rpc', $allowed, $url, $host, $ip );
// or: define( 'NMM_XMR_ALLOW_PRIVATE_RPC', true );
```

### `nmm_debug_logging`

Whether verbose debug/info tracing is written to the log (WooCommerce > Status >
Logs, source `nomiddleman`). Warnings and errors are always logged regardless.
Default follows `WP_DEBUG`; the `NMM_DEBUG_LOG` constant overrides it.

```php
apply_filters( 'nmm_debug_logging', $enabled );
// or: define( 'NMM_DEBUG_LOG', true );
```

## Advanced / extension hooks

These exist for the legacy paid Privacy extension and are rarely useful
otherwise:

- `nmm_get_hd_address( $cryptoId, $mpk, $index, $hdMode )` — delegates HD
  address derivation to an extension when one is installed.
- `nmm_hd_mode( $mode, $cryptoId )` — the HD derivation mode passed to
  address generation (default `'0'`).
