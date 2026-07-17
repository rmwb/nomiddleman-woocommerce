=== Nomiddleman Bitcoin and Crypto Payments for WooCommerce ===
Contributors: nomiddleman, rmwb, claude
Tags: bitcoin, cryptocurrency, woocommerce, bitcoin payment, crypto, btc, payments, ethereum, ether, ethereum token, token, gas, e-commerce, ecommerce, monero, dogecoin, pay with crypto, pay with bitcoin, bitcoin payments, bitcoin payment gateway, crypto woo, accept, dash, litecoin, cash, gateway, payment gateway, woocommerce gateway, wordpress, electrum, mpk, master public key, hd wallet, address, zcash, bitcore, bitcoin cash, bitcoin gold, blackcoin, dash, deeponion, ethereum classic, ripple, vericoin, eos, bitcoin sv, vechain, tron, stellar, rep, bch, btg, blk, dash, onion, doge, eth, etc, ltc, xmr, xrp, vrc, zec, eos, bsv, vet, trx, xlm, no fees, no middleman, freedom, nomiddleman, no fees, free, for free, free crypto plugin, plugin, plug-in, no middleman, binance coin, bnb, iota, miota, maker, mkr, nem, xem, waves, ontology, ont, omisego, omg, holo, hot, chainlink, link, decred, dcr, basic attention token, bat, 0x, zrx, lisk, lsk, bytecoin, bcn, bitcoin diamond, bcd, digibyte, dgb, gemini dollar, gusd, potcoin, pot, risk, high-risk, coin, mineable, erc20 token, erc20, KYC, No KYC, No registration, No login, processing, processor, groestlcoin, bitcore
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
License: GPL v3
Stable Tag: 2.9.6

Absolutely the easiest setup in the industry. No registration. No API keys. No middleman. Accept bitcoin, ethereum, litecoin, and more.

== Description ==
Utilizing the power of blockchain, we provide the only WooCommerce Cryptocurrency Gateway that truly takes out the middleman. Empowering you to accept all major cryptocurrencies directly to your own wallets for free. No middleman fees and open source on <a target="_blank" href="https://github.com/rmwb/nomiddleman-woocommerce" alt="WordPress Cryptocurrency Payment Gateway">GitHub</a>.

Accept customer payments in Bitcoin, Ethereum, Tether (USDT on Ethereum or Tron), Solana, Litecoin, XRP and 51 other cryptocurrencies. Tested with WordPress 7.0 and WooCommerce 10.8 on PHP 7.4-8.4.

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

All requests below are made server-side by the store. The only data ever transmitted are public blockchain wallet addresses used for orders, public transaction identifiers, cryptocurrency tickers, and ISO currency codes. No customer names, emails, IP addresses, or order contents are ever sent to any third-party service.

**Exchange rates.** Fetched at checkout and refreshed by the background job; the merchant chooses which price APIs are used on the Pricing Options tab:

* CoinGecko (api.coingecko.com), HitBTC (api.hitbtc.com), Gate.io (data.gate.io), Binance (api.binance.com), Poloniex (api.poloniex.com) - cryptocurrency to USD prices (only the coin's ticker is sent)
* Frankfurter (api.frankfurter.dev) and Open ER-API (open.er-api.com) - fiat exchange rates when the store currency is not USD (only the currency code is sent)

**Payment verification.** Used only in Autopay and Privacy modes, and only for coins the merchant enables; each service is queried with the order's public wallet address:

* Bitcoin: mempool.space, blockstream.info, blockchain.info
* Litecoin: litecoinspace.org, BlockCypher (api.blockcypher.com)
* Dogecoin: BlockCypher (api.blockcypher.com; optional merchant API token is sent if configured)
* Ethereum, ERC-20 tokens, Ethereum Classic and multi-network stablecoins: Blockscout (eth.blockscout.com, polygon.blockscout.com, arbitrum.blockscout.com, base.blockscout.com, blockscout.com)
* Bitcoin Cash: blockchain.info
* Bitcoin SV: WhatsOnChain (api.whatsonchain.com)
* Dash: insight.dash.org
* XRP: XRPSCAN (api.xrpscan.com)
* Cardano: Koios (api.koios.rest)
* Tezos: TzKT (api.tzkt.io)
* Tron and USDT TRC-20: Tronscan (apilist.tronscan.org)
* Solana: public mainnet RPC (api.mainnet-beta.solana.com)
* EOS: Greymass (eos.greymass.com) and EOSRIO Hyperion (eos.hyperion.eosrio.io)
* Stellar: Horizon (horizon.stellar.org)
* Waves: nodes.wavesnodes.com
* Qtum: qtum.info
* Decred: explorer.dcrdata.org
* Groestlcoin: groestlsight.groestlcoin.org
* DigiByte: digiexplorer.info
* Zcash: Blockchair (api.blockchair.com)
* Blackcoin: explorer.blackcoin.nl
* Bitcore: insight.bitcore.cc, chainz.cryptoid.info
* Myriad: blockbook.myralicious.com
* Lisk (defunct network): node08.lisk.io
* DeepOnion (defunct explorer): explorer.deeponion.org

**Merchant-configured endpoints.** Monero Autopay talks only to the merchant's own monero-wallet-rpc URL entered in settings - no third party is involved. Any verification endpoint above can be redirected to the merchant's own node or explorer instance with the nmm_api_url filter.

**QR codes** are generated locally in memory. No QR or image service is contacted.

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