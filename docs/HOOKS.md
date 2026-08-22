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

### Solana: setting first, filter last

Solana verification has both a settings field (**Solana → RPC Endpoint**) and
this filter, and they compose in one order:

1. The **RPC Endpoint** setting replaces the built-in default
   (`https://api.mainnet-beta.solana.com`). Blank means the default.
2. `nmm_api_url` then runs on that URL, so **the filter always wins** — it can
   rewrite or key-stamp whatever the setting produced.

Both are vetted before the request is sent (http/https only, no embedded
credentials, DNS-resolved and checked against private/loopback/link-local
ranges, and the connection is pinned to the vetted IP where cURL allows it).
The one difference: a URL that came from the **setting** may not point into
private space unless the site defines `NMM_SOL_ALLOW_PRIVATE_RPC` (or hooks
`nmm_sol_allow_private_rpc`), whereas a URL produced by `nmm_api_url` may — it
is PHP running on the server, the same trust level as the plugin itself, so a
filter pointing Solana at a validator on `127.0.0.1` or the LAN keeps working
exactly as it did before the setting existed.

```php
// Send Solana verification to a validator on the LAN, whatever the setting says.
add_filter( 'nmm_api_url', function ( $url ) {
    if ( strpos( $url, 'solana' ) !== false ) {
        return 'http://10.0.0.20:8899';
    }
    return $url;
} );
```

### `nmm_sol_allow_private_rpc` — permit a local Solana RPC in the setting

```php
apply_filters( 'nmm_sol_allow_private_rpc', $allow, $url, $host, $ip );
```

Returns `false` by default: a URL typed into the settings screen must not be
able to aim the server at loopback, LAN or cloud-metadata addresses. Return
`true` (or define `NMM_SOL_ALLOW_PRIVATE_RPC` in `wp-config.php`) when the
store legitimately runs its own validator on this machine or network.

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

### `nmm_autopay_scan_retry_cap`

Maximum number of failed-fetch (currency, address) keys retained for next-tick
retry (default `200`). Keys beyond the cap are dropped, and the dropped keys'
currency is excluded from the next coverage stamp so cancellation never treats
an address whose check was discarded as verified. Raise it on very large
stores whose explorers fail in bursts.

```php
apply_filters( 'nmm_autopay_scan_retry_cap', $limit );
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

## Exchange rates

The USD price that sets the customer's crypto total is **agreed** across the
price APIs selected under Pricing Options, not averaged: a mean lets one
wrong-but-nonzero source (stale cache, wrong pair, hijacked endpoint) drag the
total in proportion to how wrong it is.

Before any of that, a quote has to be a plausible *price*: numeric, finite,
above zero, and no higher than `nmm_rate_max_plausible_price`. Anything else is
dropped exactly like a dead source — an absurd quote cannot become a consensus,
and several sources agreeing on one cannot either.

- **3 or more sources** — take the median, discard every source further than
  `nmm_rate_outlier_tolerance` from it, then take the median of the survivors.
  At least two sources must survive, or no rate is trusted.
- **2 sources** — a two-element median is just their mean, so the pair must
  *agree* to within the tolerance instead; if it does, the **lower** quote is
  used (a too-high USD price silently underpays the merchant, a too-low one
  slightly overpays and is visible and refundable). If it does not, neither is
  used — nothing present can say which one is lying.
- **1 source** — used, because it is the shipped default, but logged at
  warning level: there is no cross-check of any kind.
- **0 sources** — checkout fails. A rate is never assumed.

An agreed rate is then checked against **two** guards and stored as the new
last-known-good rate:

- a **per-tick** guard against the last-known-good rate itself
  (`nmm_rate_jump_threshold`), which catches one large step; and
- a **cumulative** guard against a slow-moving reference price
  (`nmm_rate_reference_drift_limit`, re-based every
  `nmm_rate_reference_window_seconds`), which catches many small ones. The
  per-tick guard cannot see drift: ten successive 9.9% moves are each under a
  10% threshold and together take the charged rate from 100 to 257.

The net effect is that a **single** price source can move the rate a store
charges by at most the drift limit per reference window, however small its
individual steps are. Two or more agreeing sources are not subject to that
ceiling, so multi-source stores are unaffected in practice.

When nothing live can be trusted the last-known-good rate is served for a
bounded window (see `nmm_rate_max_stale_seconds`) and checkout fails after that
rather than charging a price nobody can vouch for. Every one of these events is
logged at warning level (WooCommerce > Status > Logs, source `nomiddleman`).

Note that Gate.io, Binance and Poloniex quote against **USDT**, not USD; the
outlier rule is what contains that difference if Tether loses its peg.

### `nmm_rate_outlier_tolerance`

How far one source may sit from the median before it is discarded, as a
fraction (default `0.05`, i.e. 5%). Also the agreement threshold for the
two-source rule above. Honest venues differ by fractions of a percent, so this
is far above normal spread and far below a broken feed. Clamped to
`0.001`–`1.0`.

```php
apply_filters( 'nmm_rate_outlier_tolerance', $fraction, $cryptoId );
```

### `nmm_rate_jump_threshold`

How far a freshly agreed rate may move from a **fresh** last-known-good rate
before it must be corroborated, as a fraction (default `0.10`, i.e. 10%).
A larger move is charged only when at least two surviving sources agree on it,
or — for a single-source store — when the same level is still being reported
`nmm_rate_jump_corroboration_seconds` later. Otherwise it is rejected and the
last-known-good rate is served instead. The guard is skipped when the
last-known-good rate is itself older than `nmm_rate_max_stale_seconds`, since a
stale anchor says nothing about the current market. Clamped to `0.001`–`1.0`.

```php
apply_filters( 'nmm_rate_jump_threshold', $fraction, $cryptoId );
```

### `nmm_rate_max_stale_seconds`

Maximum age of a last-known-good rate that may still be charged when the live
fetches produce no trusted price (default `1800`, 30 minutes). Long enough to
ride out a routine rate-limit lockout or exchange maintenance window without
breaking checkout, short enough that the price charged is still within the drift
the merchant already accepts by holding a quote open. Past it, checkout errors.
Return `0` to disable the stale tier entirely (any failed lookup then fails
checkout). Hard-capped at 6 hours, so no filter can make the plugin charge
yesterday's price.

```php
apply_filters( 'nmm_rate_max_stale_seconds', $seconds, $cryptoId );
```

### `nmm_rate_jump_corroboration_seconds`

For a store with only **one** price API selected, a large move cannot be
corroborated by a second source, so it is corroborated in time instead: the same
level must still be reported this many seconds later before it is charged
(default `900`, 15 minutes). Long enough that a single bad response expires
first, short enough that a genuine crash is picked up inside the stale-rate
window. Clamped to `60`–`21600`.

```php
apply_filters( 'nmm_rate_jump_corroboration_seconds', $seconds, $cryptoId );
```

### `nmm_rate_max_plausible_price`

Highest USD price for one unit of an asset that is still treated as a real quote
(default `10000000`, i.e. $10,000,000). A quote above it is dropped like a zero
or an `INF` one: it never reaches the median, it does not count as a source, and
if nothing usable remains checkout fails rather than returning a price.

The bound sits in the gap between two numbers. BTC is the most expensive
supported asset and its all-time high is on the order of $10^5, so $10M is about
a hundred times above anything that could conceivably be real. At the other end,
the gateway charges `round( $usdTotal / $price, 8 )` (18 decimals for ETH-like
coins), and a $1 order only rounds to **zero** crypto once the price passes
$2×10⁸ — a zero total that Privacy Mode would read as "paid in full". Clamped to
`0.01`–`100000000`; the hard cap is the highest price at which a $1 order still
produces a non-zero amount, so no filter can reopen that hole.

```php
apply_filters( 'nmm_rate_max_plausible_price', $usdPerUnit, $cryptoId );
```

### `nmm_rate_reference_drift_limit`

How far an accepted rate may sit from the slow-moving reference price before it
must be corroborated, as a fraction (default `0.40`, i.e. 40%). This is the
cumulative counterpart to `nmm_rate_jump_threshold`: it bounds the **total**
movement one source can produce inside a reference window, not the size of each
step, so a sequence of individually-innocent moves cannot ratchet the price.

40% is sized from the honest side. No major asset has moved more than that
inside a six-hour window: the worst days on record were roughly −30% to −37%
spread across a whole day, which is under 10% per window and re-bases as it goes.
A genuine 30% day is therefore charged in full, in either direction, even when
the move is concentrated into a couple of hours.

A larger move is charged only when at least two surviving sources agree on it —
time corroboration deliberately does **not** unlock this guard, because waiting
is exactly what a patient single source would do. When it trips, the rate is
simply not trusted: the last-known-good rate is served for the rest of the stale
window and checkout errors after that. It never results in a price being charged.
Clamped to `0.001`–`1.0`.

```php
apply_filters( 'nmm_rate_reference_drift_limit', $fraction, $cryptoId );
```

### `nmm_rate_reference_window_seconds`

How long the reference price stays pinned before it re-bases onto the current
accepted rate (default `21600`, 6 hours). Long enough that the drift limit
actually bites — the cache warmer ticks every few minutes, so hundreds of ticks
fall inside one window — and short enough that a store is never held to an
out-of-date view of the market for more than one window.

The reference re-bases on **time**, not on price, on purpose: if it only moved
when a price was accepted, a store whose market genuinely ran past the limit
would refuse every later quote forever. Ageing out means the worst case is one
window of "rate not trusted", after which the current market becomes the new
reference and checkout recovers by itself. Lengthening the window makes the
bound stricter and that recovery slower; shortening it lets a single source move
the price by the drift limit more often. Clamped to `3600`–`604800`.

```php
apply_filters( 'nmm_rate_reference_window_seconds', $seconds, $cryptoId );
```

Selecting a second price API is a better fix than loosening any of these.

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
