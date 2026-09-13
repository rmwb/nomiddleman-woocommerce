=== Nomiddleman Bitcoin and Crypto Payments for WooCommerce ===
Contributors: nomiddleman, rmwb, claude
Tags: bitcoin, cryptocurrency, woocommerce, payment gateway, crypto
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
License: GPL v3
Stable Tag: 2.12.0

Absolutely the easiest setup in the industry. No registration. No API keys. No middleman. Accept bitcoin, ethereum, litecoin, and more.

== Description ==
Utilizing the power of blockchain, we provide the only WooCommerce Cryptocurrency Gateway that truly takes out the middleman. Empowering you to accept all major cryptocurrencies directly to your own wallets for free. No middleman fees and open source on <a target="_blank" href="https://github.com/rmwb/nomiddleman-woocommerce" alt="WordPress Cryptocurrency Payment Gateway">GitHub</a>.

Accept customer payments in Bitcoin, Ethereum, Tether (USDT on Ethereum or Tron), Solana, Litecoin, XRP and 51 other cryptocurrencies. The automated compatibility workflow targets PHP 7.4-8.4.

== Supported Cryptocurrencies ==

58 cryptocurrencies. Every coin can be accepted in Classic Mode (the customer pays, you confirm receipt in your own wallet). Coins listed under automatic verification also support Autopay Mode, which watches the blockchain and completes orders on its own. Monero Autopay verifies through your own monero-wallet-rpc (view-only wallet), so each order gets a fresh subaddress and your view key never leaves your server.

Privacy Mode (a fresh HD-wallet address generated from your master public key for every order) is available for: Bitcoin, Bitcore, Dash, Dogecoin, Litecoin, Qtum.

= Automatic payment verification =

* Cardano - ADA
* Basic Attention Token - BAT
* Bitcoin Cash - BCH
* BlackCoin - BLK
* Bitcoin SV - BSV
* Bitcoin - BTC
* Dai - DAI
* Dash - DASH
* Decred - DCR
* Digibyte - DGB
* Dogecoin - DOGE
* EOS - EOS
* Ethereum Classic - ETC
* Ethereum - ETH
* Gnosis - GNO
* Groestlcoin - GRS
* Holochain - HOT
* Chainlink - LINK
* Litecoin - LTC
* Maker - MKR
* Melon - MLN
* OmiseGO - OMG
* PayPal USD - PYUSD
* Augur - REP
* Solana - SOL
* Tron - TRX
* USDC - USDC
* USDC (Arbitrum) - USDCARB
* USDC (Base) - USDCBAS
* USDC (Polygon) - USDCPOL
* Tether - USDT
* Tether (Arbitrum) - USDTARB
* Tether (Polygon) - USDTPOL
* Tether (TRC-20) - USDTTRX
* Waves - WAVES
* Stellar - XLM
* Monero - XMR
* XRP - XRP
* Tezos - XTZ
* Zcash - ZEC
* 0x - ZRX

= No Autopay (manual confirmation) =

Public transaction APIs for these coins no longer exist or were never available, so you confirm payments in your own wallet. Bitcore and Qtum still support Privacy Mode balance checks.

* Apollo Currency - APL
* Bitcoin Diamond - BCD
* Bytecoin - BCN
* Binance Coin - BNB
* Bitcoin Gold - BTG
* Bitcore - BTX
* Gemini Dollar - GUSD
* Lisk - LSK
* Iota - MIOTA
* DeepOnion - ONION
* Ontology - ONT
* Potcoin - POT
* Qtum - QTUM
* VeChain - VET
* Vericoin - VRC
* NEM - XEM
* Myriad - XMY

== Installation ==

* Install and activate
* Navigate to WooCommerce » Settings » Payments
* Click Manage for "Pay using cryptocurrency", Select "Enable cryptocurrency payments", and save
* Click the link to open Nomiddleman Settings
* Select your cryptocurrencies, enter in valid wallet addresses, and save
* Your customers can now pay with cryptocurrency!

== Features ==

* 58 supported cryptocurrencies and stablecoins across Ethereum, Tron, Polygon, Arbitrum, Base and more (BTC, ETH, USDT, USDC, SOL, XMR...)
* Absolute easiest and quickest setup in the industry
* You control your wallets, you control your keys, you control your crypto
* No third party punchouts
* No website registration
* No plugin API key required
* No middleman fees
* MPK Support - Unique address for every order\*
* Automatic order processing\*
* Real-time crypto valuation
* Customer QR code on checkout - Amount Included
* Markup/Markdown customer orders when paying with crypto
* Customizable customer messages
* Supports all WooCommerce fiat currencies

\* varies by cryptocurrency

== Screenshots ==

1. Selecting Your Cryptocurrencies
2. Adding Addresses
3. Customer Thank-You Page

== External Services ==

This plugin contacts third-party blockchain explorers and price APIs from your store's server, never from the customer's browser. The request payloads contain public blockchain or market-query data: payment addresses, public transaction identifiers or signatures, cryptocurrency tickers, and ISO currency codes. A BlockCypher token is also sent if you configure one. As with any server-to-server HTTP request, the receiving operator can see the connecting server's IP address and request metadata. The plugin does not put customer name, email address, order contents or other WooCommerce customer fields in these requests. Monero wallet-RPC credentials and JSON-RPC commands go only to the wallet-RPC URL you configure.

Requests happen during checkout/order payment setup when a needed exchange rate is not cached, and during scheduled background rate warm-ups when cached rates expire. The background job also checks whether an unpaid Autopay or Privacy Mode order has been paid (a verification lookup, repeated until the order is paid or its payment window closes). Verification services are contacted only for the cryptocurrencies and modes that need them; Classic Mode does not perform verification lookups.

Where terms of service or a privacy policy could not be verified, that is stated below. Frankfurter's API FAQ and ExchangeRate-API's combined terms/privacy document were checked on 13 September 2026. Some other links remain unverified; availability of a page does not by itself establish that its policy applies to the API endpoint.

= Exchange rate services =

Used to convert your store's prices into cryptocurrency. Only a coin ticker or an ISO currency code is placed in the query. You choose which price APIs are enabled on the Pricing Options tab. The plugin uses a screened consensus/median of usable cryptocurrency quotes (not a simple average), with special handling when only one or two sources are usable; fiat conversion uses Frankfurter first and ExchangeRate-API as fallback.

* CoinGecko (api.coingecko.com) - cryptocurrency to USD prices. Terms: https://www.coingecko.com/en/terms - Privacy: https://www.coingecko.com/en/privacy
* HitBTC (api.hitbtc.com) - cryptocurrency to USD prices. Terms: https://hitbtc.com/terms-of-use - Privacy: https://hitbtc.com/privacy-policy
* Gate.io (data.gate.io) - cryptocurrency to USD prices. Terms: https://www.gate.io/user-agreement - Privacy: https://www.gate.io/privacy-policy
* Binance (api.binance.com) - cryptocurrency to USD prices. Terms: https://www.binance.com/en/terms - Privacy: https://www.binance.com/en/about-legal/privacy-portal
* Poloniex (api.poloniex.com) - cryptocurrency to USD prices. Terms: https://poloniex.com/terms - Privacy: https://www.poloniex.com/support/privacy
* Frankfurter (api.frankfurter.dev) - fiat exchange rates (European Central Bank reference rates) when your store currency is not USD. Requests send the store currency code and USD as the target currency. Usage terms: https://frankfurter.dev/#faq - the operator permits commercial use, subject to the underlying data providers' terms. Privacy policy: https://frankfurter.dev/#faq - the API FAQ states that the service does not log personal data, IP addresses or request URLs, and uses Cloudflare for caching and DDoS protection with aggregate traffic statistics. These statements are published in the operator's API FAQ rather than separate legal documents.
* ExchangeRate-API (open.er-api.com), operated by AYR Tech (Pty) Ltd - fiat exchange rates, used as a fallback when Frankfurter does not answer. Only the currency code is sent. Terms of service: https://www.exchangerate-api.com/terms - Privacy policy: https://www.exchangerate-api.com/terms (the Privacy Policy section of this combined document). The document explicitly covers er-api.com and its subdomains.

= Payment verification services =

Contacted only in Autopay and Privacy Mode, and only for the coins you enable. Each request carries the order's public payment address, and for confirmation checks the public transaction identifiers seen at that address.

* mempool.space - Bitcoin. Terms: https://mempool.space/terms-of-service - Privacy: https://mempool.space/privacy-policy
* Blockstream (blockstream.info) - Bitcoin, as a fallback. Terms: https://blockstream.com/terms - Privacy: https://blockstream.com/privacy
* Blockchain.com (blockchain.info, api.blockchain.info) - Bitcoin and Bitcoin Cash. Terms: https://www.blockchain.com/legal/terms - Privacy: https://www.blockchain.com/legal/privacy
* Litecoin Space (litecoinspace.org) - Litecoin. Terms and a privacy policy were not located in this review.
* BlockCypher (api.blockcypher.com) - Litecoin and Dogecoin. If you configure a BlockCypher API token, it is sent with these requests. Terms: https://www.blockcypher.com/terms-of-service.html - Privacy: https://www.blockcypher.com/privacy-policy.html
* Blockscout (eth.blockscout.com, polygon.blockscout.com, arbitrum.blockscout.com, base.blockscout.com, blockscout.com) - Ethereum, Ethereum Classic, ERC-20 tokens and the multi-network stablecoins. Terms: https://eaas.blockscout.com/terms-and-conditions - Privacy: https://eaas.blockscout.com/privacy-notice
* WhatsOnChain (api.whatsonchain.com) - Bitcoin SV. Terms: https://whatsonchain.com/terms - Privacy: https://whatsonchain.com/privacy
* Dash Insight (insight.dash.org) - Dash. Terms: https://www.dash.org/terms-of-use/ - Privacy: https://www.dash.org/privacy/
* XRPSCAN (api.xrpscan.com) - XRP. Terms: https://xrpscan.com/tos - Privacy: https://xrpscan.com/privacy
* Koios (api.koios.rest) - Cardano. Terms: https://koios.rest/terms.html - Privacy: https://koios.rest/privacy.html
* Blockchair (api.blockchair.com) - Zcash. Terms: https://blockchair.com/terms (returned HTTP 401 during review; a publicly accessible terms document is being sought). Privacy policy, published in the operator's official support repository: https://github.com/Blockchair/Blockchair.Support/blob/master/PRIVACY.md - this policy describes short-term IP storage for API rate limiting.
* chainz.cryptoid.info - Bitcore balance checks in Privacy Mode. Terms and privacy policy (one document): https://chainz.cryptoid.info/terms.dws
* Stellar Horizon (horizon.stellar.org) - Stellar. Terms: https://stellar.org/terms-of-service - Privacy: https://stellar.org/privacy-policy
* Waves public nodes (nodes.wavesnodes.com) - Waves. Website terms: https://waves.tech/docs/terms - Website privacy policy: https://waves.tech/docs/privacy-policy (not independently content-verified in this review). The website terms do not clearly establish coverage for the separate public-node API; endpoint policy confirmation is being sought.
* Greymass (eos.greymass.com) - EOS. Privacy: https://greymass.com/privacy_policy - separate terms of service were not located in this review.
* Groestlsight (groestlsight.groestlcoin.org) - Groestlcoin. Privacy: https://groestlcoin.org/privacy - separate terms of service were not located in this review.
* TzKT (api.tzkt.io) - Tezos. Terms and a privacy policy were not located in this review.
* TronScan (apilist.tronscan.org) - Tron and USDT on Tron. Terms: https://tronscan.org/contracts/terms - Privacy: https://tronscan.org/aboutUs/privacyPolicy
* EOSRIO Hyperion (eos.hyperion.eosrio.io) - EOS, as a fallback. Terms and a privacy policy were not located in this review.
* dcrdata (explorer.dcrdata.org) - Decred. Terms and a privacy policy were not located in this review.
* DigiExplorer (digiexplorer.info) - DigiByte. Terms and a privacy policy were not located in this review.
* qtum.info - Qtum. Terms and a privacy policy were not located in this review.
* BlackCoin explorer (explorer.blackcoin.nl) - BlackCoin. Terms and a privacy policy were not located in this review.

= Endpoints you configure yourself =

These endpoints are configured by you; Solana uses a public default if its setting is left blank:

* Monero Autopay talks only to your own monero-wallet-rpc instance, at the URL you enter in settings. A view-only wallet is enough. The plugin sends JSON-RPC commands and configured authentication credentials to that endpoint; it does not send your view key in these requests.
* Solana Autopay uses the JSON-RPC endpoint entered on the Solana settings tab. If left blank, it uses Solana's public mainnet RPC at `api.mainnet-beta.solana.com`; this endpoint is rate-limited and the Solana documentation recommends a dedicated provider for production workloads. The Solana Foundation's [Terms](https://solana.com/tos) and [Privacy Policy](https://solana.com/privacy-policy) are published for its website/service; they do not clearly state that they govern this community RPC endpoint, so applicability to RPC traffic is uncertain. You can point it at Helius (the settings screen example: `mainnet.helius-rpc.com`; [Terms](https://www.helius.dev/terms), [Privacy](https://www.helius.dev/privacy-policy)), QuickNode, Alchemy, Triton, or your own validator. Only the endpoint you actually use receives the JSON-RPC request, including the payment address and transaction signatures needed for the scan.
* Any verification request above can be redirected to your own node or explorer with the nmm_api_url filter, so verification traffic goes to the endpoint returned by your filter. Exchange-rate services are configured separately.

QR codes are generated locally in memory by the bundled phpqrcode library. No QR or image service is contacted.

== Frequently Asked Questions ==

= Privacy Mode says "Address creation failed, please check your MPK" - what's wrong? =

This almost always means the server is missing the PHP math extension Privacy Mode needs, not that your MPK is wrong. Generating HD wallet addresses requires either the **gmp** extension (preferred, and faster) or **bcmath**. If neither is enabled - common right after a PHP upgrade - address generation fails with that misleading message. Ask your host to enable gmp (or bcmath) and try again. Tools > Site Health will also flag this. Classic Mode and Autopay Mode do not need these extensions.

= Can I run payment verification against my own node instead of a public explorer? =

Yes. The `nmm_api_url` filter lets you redirect any verification request to your own self-hosted instance (for example your own mempool, Blockscout, or Insight server), as long as it runs the same software the plugin expects. See the developer hooks documentation for examples: https://github.com/rmwb/nomiddleman-woocommerce/blob/master/docs/HOOKS.md

Note that this filter currently covers blockchain verification requests only, not exchange-rate lookups.

= Does the plugin provide developer hooks? =

Yes. Filters are available for redirecting verification requests, customizing the customer payment message, adjusting Autopay matching tolerances, changing the checkout icon, and white-labeling the settings page. The full reference with code examples is at https://github.com/rmwb/nomiddleman-woocommerce/blob/master/docs/HOOKS.md

= Privacy Mode (HD): should I raise my wallet's gap limit? =

Yes - as a safeguard. Privacy Mode derives a fresh address per order from your master public key. To avoid handing out an ever-growing range of addresses, the plugin returns an address to the pool for reuse **only** if the order was abandoned without paying and fresh block-explorer checks confirm the address never received anything on-chain; any address that saw funds is retired permanently. This keeps a run of abandoned checkouts from advancing the derivation index unnecessarily. As defense-in-depth, set your receiving wallet's **gap limit** (the number of consecutive unused addresses it scans from the seed - 20 by default in Electrum) comfortably above the longest run of abandoned checkouts you would expect between payments, so a paid address is always discovered on seed recovery. In Electrum this is `wallet.change_gap_limit` / the `gap_limit_for_change` and address gap-limit settings; other HD wallets have an equivalent. This wallet setting should be a backstop, not the plugin's primary protection.

== Changelog ==

= 2.12.0 =
* Use a distinct NMMPRO prefix with migration of retained settings and legacy hook compatibility.
* Persist consumed transactions and atomically bind payment claims; recover interrupted order completion.
* Match payments and calculate requested amounts using exact decimal units.
* Expand external-service data and timing disclosures and require CI before release.

= 2.11.0 =
* Exchange rates: the USD price is now the median of the price APIs you have enabled, with any source more than 5% away from it rejected, instead of a plain average - one exchange returning a bad quote can no longer drag the price your customers are charged. Small source counts are handled explicitly rather than pretending a median exists: with two sources they must agree and the lower is used, and with three or more at least two must corroborate each other
* Exchange rates: a price that has moved more than 10% from the last known good rate is not used until a second source confirms it - or, on a store with only one price API enabled, until the same price still reads 15 minutes later. A running drift limit stops a series of small sub-threshold moves from walking the charged rate somewhere it was never allowed to jump in one step, and non-finite or absurd quotes are refused outright
* Exchange rates: when no live price can be trusted the last good rate is served for up to 30 minutes, after which checkout errors rather than quote a price nothing can vouch for - a routine rate-limit response from one API no longer takes checkout down. Four new filters expose the tolerances (see docs/HOOKS.md), and the settings screen now shows which venues quote in USDT rather than USD
* Solana: the JSON-RPC endpoint is now a setting instead of a hard-coded public URL, defaulting to the same endpoint so existing installs are unaffected. Because the server fetches whatever URL you enter, it is vetted by the same checks as the Monero wallet RPC - the connection is pinned to the validated address, and private, loopback and cloud-metadata addresses are refused. An endpoint that cannot be reached safely marks the address sweep incomplete rather than looking like a completed one
* Loading: the plugin's classes are now autoloaded from absolute paths instead of around thirty include-path-dependent require_once calls, so it loads correctly under open_basedir, on symlinked plugin directories and with unusual include_path settings. The per-request scan of the extensions folder is cached against that folder's timestamp
* Readme: the External Services section now documents every third-party explorer and price API the plugin contacts - what each one is used for, what is sent to it and when, and a link to that operator's terms of service and privacy policy, or a plain statement where an operator publishes neither
* Removed the payment-verification code for the five coins whose public APIs no longer exist anywhere: Lisk (its layer-1 service is gone), NEM, DeepOnion, Myriad, and Bitcore Autopay. Autopay was already unavailable for these coins on the settings screen, so no working configuration changes; Classic Mode is unaffected, and Bitcore Privacy Mode balance checks continue to work through chainz.cryptoid.info
* Monero wallet RPC requests now go through WordPress's HTTP API instead of a hand-rolled cURL call. The connection pin to the validated IP address, the protocol restriction, and the digest credentials monero-wallet-rpc needs are applied to the cURL handle through the standard http_api_curl action; if WordPress would have sent the request over a transport that cannot be pinned, the request is refused rather than made unpinned
* Exchange-rate cache entries are now stored under prefixed names (nmm_rate_*, nmm_fx_*) and the admin address-preview AJAX action is now nmm_first_mpk_address, so none of the plugin's stored names can collide with another plugin's
* Readme tags reduced to the five the plugin directory uses

= 2.10.0 =
* Fund safety: wallet addresses are now fully validated when you save them - real checksum verification (base58check, bech32/bech32m including taproot, and Bitcoin Cash CashAddr) instead of loose pattern matching. A mistyped or truncated address can no longer be saved, shown to customers and silently swallow their payments. Addresses already stored that fail the new checks are flagged with an admin notice (never deleted) so you can review them
* Fund safety: the payment address is now allocated when the customer places the order, not when the order-received page renders. A customer who closes the browser right after paying no longer ends up with an order that has no payment address, no monitoring and no email; automated visits to the order-received link no longer trigger any allocation; and the WooCommerce "Pay" link on a pending order now shows the payment details
* Monero Autopay: payments split across several transactions (exchange withdrawal limits, wallets that split coins, topping up after a fee shortfall) are now added together and credited once the total covers the order - previously both halves landed on-chain and the order was still cancelled as unpaid. This applies to Monero, where every order gets its own fresh subaddress. On a shared address (a single static address, or a carousel address handed out again later) there is no reliable way to tell whose partial payment is whose, so those are deliberately left for you to reconcile by hand, exactly as before - the plugin will not guess with your customers' money. Privacy Mode already credited split payments correctly and is unchanged
* Multisite: network uninstall now removes the plugin's tables on every subsite, subsites created after network activation get their tables automatically, broken subsites self-repair, and a new Site Health check reports missing tables
* Developer/CI: WordPress security sniffs and PHPStan static analysis now run on every push; a new address-validation suite (400+ vectors) and a direct payment-matcher unit suite (split payments, collisions, concurrent verifiers, database-error retries) gate releases; explorer outages from the weekly smoke run now file a tracked issue

Upgrade notes: stricter address validation may reject a previously accepted address in your settings. Your saved settings are never changed or deleted, but the plugin will not hand a failing address to a customer - a failing address is skipped and the next valid address for that cryptocurrency is used instead, so checkout stops only for a cryptocurrency that has no valid address left at all. An admin notice lists exactly which addresses to check. This is deliberate: an address that fails a checksum is almost always mistyped or truncated, and payments sent to it would be unrecoverable. If you accept crypto on a single coin, check for that notice immediately after upgrading. Zcash merchants: shielded (zs1... or z...), Unified (u1...) and TEX (tex1...) addresses are now accepted, but only in Classic mode - Autopay confirms payments by looking the address up on a public block explorer and cannot see them, so Autopay requires a transparent t-address and will tell you if a saved address is not usable. Orders now also receive their payment address at checkout rather than on the order-received page.

= 2.9.9 =
* Fund safety: Privacy Mode address generation now refuses to produce an address for any coin or address type it has no version byte mapped for, instead of silently emitting a plausible-looking address that no wallet controls. A new release-gating test verifies the mapping for every HD-capable coin on both math backends
* Privacy Mode (HD): a database error while stocking a fresh payment address is now logged with the database's own error message and surfaced through the normal checkout error path - previously it was completely silent and the customer just saw a generic "unable to get payment address" with nothing in the logs
* Reliability: an unexpected PHP error while rendering the order-received page now shows the customer a clear notice (and, during payment setup, safely fails the order) instead of a blank error page; an order whose chosen coin was removed from the plugin in an update now shows a "contact the store" notice on refresh instead of an error page
* Emails: the payment details block now reads the amount and address from the order itself, so emails resent from the admin screen or sent by background processes include the payment details instead of a blank total
* Privacy: block-explorer API tokens and similar credentials are stripped from URLs before they are written to the WooCommerce logs, and failed API responses are logged as a short summary instead of a full dump - log excerpts pasted into support threads can no longer leak a paid API token
* Security: the Monero wallet RPC password is no longer embedded in the settings page HTML. The field is write-only - leave it blank to keep the saved password, tick the new checkbox to clear it - and the password can now be defined as the NMM_XMR_RPC_PASSWORD constant in wp-config.php so it never lives in the database
* Performance: all exchange-rate lookups now use a 3-second timeout and block-explorer calls an 8-second timeout, so an unresponsive third-party API can no longer stall checkout or the order-received page while several APIs are tried in turn

= 2.9.8 =
* Checkout: if the payment address cannot be recorded for monitoring, the order now fails with a clear message instead of displaying an address that Autopay is not watching - previously a database error at this moment could send a customer to an address whose payment would never be credited
* Privacy Mode (HD): a late payment to an order that was already cancelled, failed or refunded no longer completes that order. The payment is recorded as an order note for manual reconciliation, and each verified payment is now claimed atomically so two overlapping background runs cannot both complete the same order. If completing an order fails (most often a third-party plugin erroring on the payment event), the address is handed back for a later run to retry instead of being left settled over an unpaid order
* Privacy Mode (HD): an address that received a partial payment for an order that was later cancelled - or an order that was refunded - is now retired properly, instead of being re-checked on every background run indefinitely
* Privacy Mode (HD): before auto-cancelling an unpaid order whose time has run out, the plugin now confirms the payment address is genuinely empty - including funds that have not yet reached your required confirmation count - using the balance observed moments earlier in the same background run where possible (no extra explorer traffic); if any payment exists the order is kept rather than cancelled
* Privacy Mode (HD): a partial payment arriving after an order was cancelled, failed or refunded no longer emails the customer asking for the remaining amount - the merchant gets a reconciliation note instead and the address is retired
* Privacy Mode (HD): stores that accept zero-confirmation payments no longer fall back to confirmed-only block explorers, which cannot see an unconfirmed payment and could report a paid order as unpaid; custom order statuses declared payable via WooCommerce's filter are now also respected before an address is retired
* Carousel: payment addresses are now handed out through an atomic database counter, so two simultaneous checkouts can no longer be given the same address. A carousel with no usable address fails the order with a clear message instead of hanging the checkout page
* Hardening: confirmation counts, cancellation timers, markups and processing percentages are now validated on the server against the same limits the settings screen shows, so an out-of-range stored value can no longer weaken payment matching or cancel orders immediately
* Hardening: the Privacy Mode verifier no longer aborts part-way through when an order has been deleted

= 2.9.7 =
* Autopay: verification completeness is now tracked per address instead of per currency - a single very busy (or deliberately dust-flooded) payment address can no longer pause automatic order expiry for every order of that cryptocurrency; only orders on the affected address wait until it can be conclusively checked
* Autopay: bulk transaction lookups (Cardano, Bitcoin SV) now verify that the explorer returned details for every requested transaction - a partial reply is retried instead of being mistaken for a completed check
* Hardening: a malformed explorer reply can no longer abort the background verification run part-way through - it is treated as an ordinary failed lookup and retried

= 2.9.6 =
* Performance: the Autopay verifier now checks a bounded number of unpaid addresses per cron tick and advances a persisted fair cursor so every address is still eventually checked - a large backlog of abandoned orders can no longer hold the background job's lock and delay payment, expiry, HD and Solana work. Monero verification fetches an account's incoming transfers once per tick and groups them by subaddress locally, instead of two wallet-RPC calls per address
* Autopay: the per-tick scan budget is derived from the observed cron cadence instead of assuming one tick per minute, and the payment-matching window is widened to match the real revisit gap, so a store whose cron runs infrequently cannot silently miss a payment
* Autopay: an order is never auto-cancelled before the verifier has checked its address at least once after its cancellation window closed - protecting aged unpaid backlogs at upgrade time and after long cron outages from being cancelled unverified
* Autopay: a bounded priority lane checks recently placed orders on every tick, so a new customer's payment is confirmed promptly even while a large backlog is being swept
* Hardening: the thank-you page no longer errors if the order was deleted while the page was loading
* Hardening: the batched Monero transfer query sets an explicit upper block height (some older wallet-RPC builds silently return nothing without it), and the background job's lock is scoped per site so multisite subsites no longer skip their payment checks while another subsite's cycle runs

= 2.9.5 =
* Autopay/Privacy Mode: the thank-you page now allocates a payment address under an order-scoped database lock and re-reads the order after acquiring it, so two near-simultaneous first loads of the same order can no longer each allocate a different address (most visible with Monero subaddresses and carousel addresses) - exactly one address is assigned, and the address shown to the customer is always the one being monitored. If a second request finds the order already being set up it shows a brief "preparing your payment details" notice instead of allocating, and a failed set-up is recorded before the lock is released so it can never overwrite a concurrent success

= 2.9.4 =
* Autopay: the background job no longer cancels an order that was paid (by the merchant, a webhook, or the verifier) after its unpaid record was read but before cancellation - it re-checks the live order and reconciles the payment record instead. This is the Autopay counterpart of the HD-address cancellation fix in 2.9.3
* Operational logging is restored and now routes to WooCommerce > Status > Logs (source "nomiddleman"), with error_log() as a fallback. Warnings and above (durable-queue write failures, schema-migration failures, cron-lock degradation, address-claim exhaustion, payment collisions, ...) are always recorded; verbose tracing is emitted only when debugging is enabled; repeated messages are de-duplicated and over-long entries truncated
* Performance: added the composite database indexes the hot paths need - the 15-second customer status poll now looks up by order id, the cron's HD pool/claim/pending/assigned queries and the Autopay unpaid-address matcher use covering indexes instead of scanning as those tables grow (added to new installs and to existing ones through verified migrations)
* Security: the merchant-configured Monero wallet RPC URL is now validated before use to prevent server-side request forgery - only http/https is allowed, redirects are not followed, the connection is protocol-restricted and pinned to the validated address, and private/loopback targets require an explicit opt-in on multisite (where site admins are not host admins)

= 2.9.3 =
* Privacy Mode (HD): payment addresses are now claimed atomically at checkout, so two customers checking out at the same moment can never be handed the same address
* Privacy Mode (HD): the background job no longer cancels an order that was already paid or verified out-of-band (e.g. during a block-explorer outage), and a cancelled order's order-received page no longer re-displays a payment address that may have been recycled to another order
* Privacy Mode (HD): an abandoned checkout's address is now quarantined and re-verified with fresh block-explorer checks before it can be reused - it returns to the pool only if it never received anything on-chain, and any address that saw funds is retired permanently, so a late payment can never be credited to the wrong order (see the FAQ note about your wallet's gap limit)
* Autopay: overpayments are now correctly recognised as paid (the match tolerance previously divided by the received amount, rejecting anything more than a fraction over the expected total), and a zero-value inbound transaction can no longer abort the verification cycle on PHP 8 (zero/dust TRC-20 transfers are now also ignored at the source)
* Autopay (Solana): payments are no longer missed when the carousel address has more recent activity than a single lookup returned - the signature history is now paged back through the payment window instead of only the 12 most recent signatures, and any transaction whose detail lookup fails transiently (rate limit, timeout) is retried from a durable queue until it succeeds or ages out of the window, so a temporary RPC hiccup can no longer drop a payment
* Reliability: the background job's overlap guard is now an atomic MySQL advisory lock instead of a non-atomic transient, so two ticks firing together can no longer both run, and a crashed run releases the lock automatically (no stale lock can wedge the cron)
* Privacy Mode (HD): during a primary block-explorer outage, fallback explorers are no longer allowed to confirm a payment on fewer confirmations than the merchant requires (BTC/LTC); verification pauses until the primary source recovers instead
* EVM/ERC-20 orders now render correctly on servers that have gmp but not bcmath (amount conversion no longer calls bcmath unconditionally)
* The thank-you page now shows a clear message instead of a fatal error if the selected cryptocurrency cannot be determined (e.g. a lost session); the chosen coin is also stored on the order so a lost session cannot orphan a checkout
* Security: the payment label (gateway title) is now sanitised on save and on output, preventing a site admin without unfiltered_html from injecting markup at checkout
* Checkout: a coin whose address generation is currently failing is now correctly removed from the payment dropdown instead of only failing later
* Fixed the "Open in Solana wallet" button, whose solana: link was being stripped by URL escaping
* Internal hardening and dead-code removal in the admin status-change and gateway-filter hooks

= 2.9.2 =
* Fixed repeated PHP warnings ("Undefined property: stdClass::$last") when HitBTC, Gate.io, or Binance doesn't list a selected coin: those APIs answer HTTP 200 with an error object, which is now handled silently (prices were never affected - the empty result was already discarded before averaging)
* Reliability: fixed a runaway in Privacy Mode HD address buffering that could pin CPU and exhaust memory. The address-uniqueness loop no longer resets PHP's execution timer on every iteration and is now capped at a 20-address gap limit, and the background job takes a lock so cycles can no longer overlap and stack up when a block explorer is slow or rate-limiting.
* Privacy Mode now fails clearly when the server lacks the required PHP math extension: a Tools > Site Health check and a settings-page notice explain that gmp (or bcmath) must be enabled, instead of the misleading "check your MPK" error
* Documented developer hooks (docs/HOOKS.md) and the External Services the plugin contacts; added a Frequently Asked Questions section

= 2.9.1 =
* Performance: exchange rates are refreshed in the background job, so the thank-you page is a cache hit for nearly every customer instead of the first one after expiry waiting on up to five APIs
* Performance: the address-carousel table no longer runs a COUNT query on every instantiation (replaced with an autoloaded option check)
* Performance: consumed-transaction lists are capped at the 200 most recent hashes per address so they can no longer grow without bound
* Monero: transfer lookups now ask the wallet RPC for just the order's subaddress (subaddr_indices) instead of every transfer in the account
* The checkout payment method now shows an icon (filterable via nmm_gateway_icon)
* Removed the orphaned admin flash-notice queue (a Redux-era leftover with no remaining callers; settings feedback uses the WordPress Settings API) - stale my_flash_notices options are cleaned up on activation
* Removed other dead code found in an orphan sweep: two never-called registry helpers dating back to v2.4.8, an unused Monero helper, an unused extension-registry lookup, three unused constants, and an orphaned logo file

= 2.9.0 =
* Internationalization: every customer- and merchant-facing string is now translatable (text domain: nomiddleman-crypto-payments-for-woocommerce), including checkout, the thank-you page, emails, order notes, admin settings, validation errors, and the JavaScript wallet/status messages
* Translation template (.pot) shipped in /languages - translators can build .po/.mo files against it

= 2.8.1 =
* Hardening: every PHP file now blocks direct web access (ABSPATH guard)
* Hardening: all templated output is escaped with WordPress escaping functions; admin flash notices are sanitized before display
* Performance: the cryptocurrency registry is built once per request instead of on every call (previously rebuilt ~27 times per page load)
* Removed leftover debug logging from order-status hooks

= 2.8.0 =
* Pay in browser wallet: one-click MetaMask/injected-wallet payments for ETH and all ERC-20 tokens on the thank-you page, with automatic network switching; Solana Pay link for SOL
* Live payment status: the thank-you page now updates itself when payment arrives (and shows partial-payment progress for Privacy Mode orders) - no more manual refreshing
* Monero Autopay: point the plugin at your own monero-wallet-rpc (view-only wallet is enough) and every order gets a fresh subaddress with automatic verification; your view key never leaves your server
* New stablecoins and networks: Dai and PayPal USD on Ethereum, USDT and USDC on Polygon and Arbitrum, USDC on Base
* Correct wallet-scannable QR codes: EIP-681 URIs for ETH and tokens (previous ethereum: QRs used a nonstandard amount parameter wallets ignored), Solana Pay for SOL, monero: for XMR
* Security: ERC-20 payments are now verified by token contract address instead of token symbol - fake tokens sharing a symbol can no longer be mistaken for payment
* New nmm_api_url filter lets you point any coin's verification at your own node or explorer instance

= 2.7.0 =
* New coins: Tether on Ethereum (USDT), Tether on Tron (TRC-20 USDT, the most-used crypto payment rail), and Solana (SOL) - all with Autopay verification via keyless public APIs (Blockscout, Tronscan, Solana mainnet RPC). SOL checkout QR codes use the Solana Pay URI scheme
* Fixed ERC-20 payment verification for busy addresses: token transfers are now fetched newest-first (previously oldest-first, which could miss recent payments entirely)

= 2.6.0 =
* QR codes are now rendered in memory: inline SVG on the thank-you page, and embedded directly inside emails as inline (CID) attachments. No QR file is ever written to disk, and the third-party qrserver.com fallback is gone
* Fixes a privacy issue: previous versions wrote one world-readable QR image per order (payment address and amount, guessable filename) into the plugin folder and never deleted them; existing files are swept automatically on upgrade
* Plain-text order emails now contain proper plain-text payment details instead of raw HTML
* Settings page no longer offers Autopay/Privacy Mode for coins whose verification APIs no longer exist anywhere (LSK, XEM, ONION, XMY autopay; XMY privacy; BTX autopay), and warns loudly if a previously saved mode can no longer verify payments
* Payment checks now run through Action Scheduler (bundled with WooCommerce) every 60 seconds instead of a 30-second WP-Cron loop, with automatic migration and WP-Cron fallback
* Explorer API calls now use per-host exponential backoff when rate-limited or failing, and respect per-host request spacing (chainz)
* New optional BlockCypher API token setting raises LTC/DOGE verification rate limits for busier stores
* Security hardening: all plugin database queries now use $wpdb->prepare(); the MPK preview AJAX endpoint requires a nonce and admin capability; stored address buffers are unserialized with object instantiation disabled; customer-facing output (payment message, addresses, QR URLs) is escaped
* EOS payment verification restored: dead EOSPark API (and its hardcoded key) replaced with the public Hyperion history API, with Greymass as fallback
* Restored payment verification for 6 more coins whose explorers had shut down: ADA (Koios), BSV (WhatsOnChain), DGB (digiexplorer.info), XTZ (TzKT), ZEC (Blockchair), BLK (explorer.blackcoin.nl)
* BTX Privacy Mode balance checks restored via chainz.cryptoid.info; BTX Autopay remains unavailable (no free transaction API)
* LSK, XEM, ONION, and XMY verification remains unavailable: their chains or last public explorers no longer exist
* Removed the bundled Redux Framework (outdated, known CVEs, PHP 8 first-load errors)
* Settings page rebuilt with native WordPress admin UI at the same location; existing settings are preserved automatically (same option storage, no migration needed)
* Privacy Mode sample address generation, wallet address validation, and all per-crypto options carried over
* Import/export and reset buttons from the old Redux panel are no longer available

= 2.5.0 =
* PHP 8 compatibility (fixed fatal parse error on modern PHP)
* Declared WooCommerce High-Performance Order Storage (HPOS) compatibility; order metadata now uses the WooCommerce CRUD API
* Added WooCommerce Blocks (block-based) checkout support
* Replaced dead exchange-rate services: CoinGecko replaces CryptoCompare, Frankfurter/open.er-api replace currconv and fixer.io (removed embedded API keys)
* Updated Poloniex pricing to the current api.poloniex.com endpoint
* Replaced defunct blockchain explorers for payment verification: mempool.space and blockstream.info (BTC), litecoinspace.org (LTC), BlockCypher (DOGE), Blockscout (ETH and ERC-20 tokens), XRPSCAN (XRP), blockchain.info haskoin store (BCH), insight.dash.org (DASH)

= 2.4.8 =
* Fixed settings bug

= 2.4.4 =
* Order status now correctly synced when updated manually
* Updated Privacy Mode warning for address generation to be more detailed and visible

= 2.4.3 =
* Added fallback fiat conversion

= 2.4.2 =
* Added Groestlcoin (GRS)
* Added local QR code, fall back to third party

= 2.4.1 =
* Autopay beta mode - please read details in plugin settings
* Added Bitcore (BTX)
* Updated DCR Service

= 2.4.0 =
* Can now customize text on customer payment page
* Added MyriadCoin (XMY)
* Added Yoroi ADA address validation

= 2.3.7 =
* Updated customer cancellation message to be in hours and added order cryptoId

= 2.3.6 =
* Removed settings from top admin bar

= 2.3.5 =
* Added markup/markdown settings for each crypto

= 2.3.4 =
* Gateway will always show up in WooCommerce settings even if no valid cryptos exists

= 2.3.3 =
* Added setting to change the Checkout Payment Gateway Label
* Changed underpayment display in customer email

= 2.3.2 =
* Updated to Wordpress 5.2

= 2.3.1 =
* Fiat exchange rate upgrades

= 2.3.0 =
* Added first three generated HD Addresses to settings page via AJAX
* MPK Validation is more strict

= 2.2.1 =
* Improved the way we handle enabling/disabling of WooCommerce gateway

= 2.2.0 =
* Added Privacy Mode support for DASH

= 2.1.0 =
* Added Privacy Mode support for DOGE

= 2.0.3 =
* Improved selection of address and crypto amount on customer thank-you page

= 2.0.2 =
* Update filepaths to canonical form

= 2.0.1 =
* Updated readme and removed banner

= 2.0.0 =
* Initial Plugin Upload
