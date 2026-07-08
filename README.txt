=== Nomiddleman Bitcoin and Crypto Payments for WooCommerce ===
Contributors: nomiddleman
Tags: bitcoin, cryptocurrency, woocommerce, bitcoin payment, crypto, btc, payments, ethereum, ether, ethereum token, token, gas, e-commerce, ecommerce, monero, dogecoin, pay with crypto, pay with bitcoin, bitcoin payments, bitcoin payment gateway, crypto woo, accept, dash, litecoin, cash, gateway, payment gateway, woocommerce gateway, wordpress, electrum, mpk, master public key, hd wallet, address, zcash, bitcore, bitcoin cash, bitcoin gold, blackcoin, dash, deeponion, ethereum classic, ripple, vericoin, eos, bitcoin sv, vechain, tron, stellar, rep, bch, btg, blk, dash, onion, doge, eth, etc, ltc, xmr, xrp, vrc, zec, eos, bsv, vet, trx, xlm, no fees, no middleman, freedom, nomiddleman, no fees, free, for free, free crypto plugin, plugin, plug-in, no middleman, binance coin, bnb, iota, miota, maker, mkr, nem, xem, waves, ontology, ont, omisego, omg, holo, hot, chainlink, link, decred, dcr, basic attention token, bat, 0x, zrx, lisk, lsk, bytecoin, bcn, bitcoin diamond, bcd, digibyte, dgb, gemini dollar, gusd, potcoin, pot, risk, high-risk, coin, mineable, erc20 token, erc20, KYC, No KYC, No registration, No login, processing, processor, groestlcoin, bitcore
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
License: GPL v3
Stable Tag: 2.7.0

Absolutely the easiest setup in the industry. No registration. No API keys. No middleman. Accept bitcoin, ethereum, litecoin, and more.

== Description ==
Utilizing the power of blockchain, we provide the only WooCommerce Cryptocurrency Gateway that truly takes out the middleman. Empowering you to accept all major cryptocurrencies directly to your own wallets for free. No middleman fees and open source on <a target="_blank" href="https://github.com/rmwb/nomiddleman-woocommerce" alt="WordPress Cryptocurrency Payment Gateway">GitHub</a>.

Accept customer payments in Bitcoin, Ethereum, Tether (USDT on Ethereum or Tron), Solana, Litecoin, XRP and 44 other cryptocurrencies. Tested with WordPress 7.0 and WooCommerce 10.8 on PHP 7.4-8.4.

== Supported Cryptocurrencies ==

51 cryptocurrencies. Every coin can be accepted in Classic Mode (the customer pays, you confirm receipt in your own wallet). Coins listed under automatic verification also support Autopay Mode, which watches the blockchain and completes orders on its own.

Privacy Mode (a fresh HD-wallet address generated from your master public key for every order) is available for: Bitcoin, Bitcore, Dash, Dogecoin, Litecoin, Qtum.

= Automatic payment verification =

* Cardano - ADA
* Basic Attention Token - BAT
* Bitcoin Cash - BCH
* BlackCoin - BLK
* Bitcoin SV - BSV
* Bitcoin - BTC
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
* Augur - REP
* Solana - SOL
* Tron - TRX
* USDC - USDC
* Tether - USDT
* Tether (TRC-20) - USDTTRX
* Waves - WAVES
* Stellar - XLM
* XRP - XRP
* Tezos - XTZ
* Zcash - ZEC
* 0x - ZRX

= Classic Mode only (manual confirmation) =

Public verification APIs for these coins no longer exist or were never available; you confirm payments in your own wallet.

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
* Monero - XMR
* Myriad - XMY

== Installation ==

* Install and activate
* Navigate to WooCommerce » Settings » Payments
* Click Manage for "Pay using cryptocurrency", Select "Enable cryptocurrency payments", and save
* Click the link to open Nomiddleman Settings
* Select your cryptocurrencies, enter in valid wallet addresses, and save
* Your customers can now pay with cryptocurrency!

== Features ==

* 51 supported cryptocurrencies (BTC, ETH, USDT, SOL, LTC, XRP, BCH and more)
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

== Changelog ==

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