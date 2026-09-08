# External-service documentation audit

Reviewed 5 September 2026 against the first-party HTTP call sites in `src/NMMPRO_Exchange.php`, `src/NMMPRO_Blockchain.php`, `src/NMMPRO_Monero.php`, and `src/NMMPRO_Cron.php`.

## Corrections made

- README timing now includes checkout/order setup, scheduled exchange-rate warm-ups, and repeated background verification.
- README no longer says that every request contains only public data or that no API key is ever sent: a configured BlockCypher token is included, and HTTP operators can observe the store server's IP and request metadata.
- README now describes the current screened median/consensus pricing behavior rather than an average.
- Solana's blank setting is documented as the built-in `api.mainnet-beta.solana.com` default. The linked Solana Foundation policies are marked as policies for its website/service; their applicability to the public RPC endpoint is uncertain.

## Coverage and link review

The README lists every hard-coded exchange-rate and explorer host found in the source, including fallback hosts. Each listed service has a terms/privacy URL where an operator publishes one; entries without a located policy explicitly say so. The following fetch evidence was recorded on 5 September 2026. A policy title confirms a reachable policy page, not applicability to every API endpoint. HTML shells and failed fetches remain unverified. Stellar’s original /privacy link resolved to a product page and was corrected to /privacy-policy after checking the official policy.

| URL | Fetch result / page title |
|---|---|
| https://blockchair.com/privacy | <urlopen error [SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol (_ssl.c:1082)> |
| https://blockchair.com/terms | <urlopen error [SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol (_ssl.c:1082)> |
| https://blockstream.com/privacy | <urlopen error [SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol (_ssl.c:1082)> |
| https://blockstream.com/terms | <urlopen error [SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol (_ssl.c:1082)> |
| https://chainz.cryptoid.info/terms.dws | 200 — Disclaimer, Terms of service and Privacy Policy |
| https://eaas.blockscout.com/privacy-notice | 200 — Privacy Notice |
| https://eaas.blockscout.com/terms-and-conditions | 200 — Terms &amp; Conditions |
| https://frankfurter.dev/ | 200 — Frankfurter / Free exchange rates API |
| https://greymass.com/privacy_policy | 200 — Privacy Policy / Greymass |
| https://groestlcoin.org/privacy | 200 — Groestlcoin Privacy Policy • Groestlcoin (GRS) |
| https://hitbtc.com/privacy-policy | 200 — Privacy Policy / HitBTC |
| https://hitbtc.com/terms-of-use | 200 — Terms of Service / HitBTC |
| https://koios.rest/privacy.html | 200 — Privacy Policy / Koios Distributed API |
| https://koios.rest/terms.html | 200 — Terms&amp;Conditions / Koios Distributed API |
| https://mempool.space/privacy-policy | 200 — mempool - Bitcoin Explorer |
| https://mempool.space/terms-of-service | 200 — mempool - Bitcoin Explorer |
| https://poloniex.com/support/privacy | 200 — Poloniex Exchange - Trade BTC, ETH, and Hot Cryptos |
| https://poloniex.com/terms | 200 — User Agreement - Terms of Service / Poloniex |
| https://solana.com/privacy-policy | 200 — Solana Privacy Policy / Solana |
| https://solana.com/tos | 200 — Terms of Service / Solana |
| https://stellar.org/privacy | 200 — Stellar / Privacy: Configurable, Compliant &amp; Open by Default |
| https://stellar.org/terms-of-service | 200 — Stellar / Terms of Service |
| https://waves.tech/docs/privacy-policy | 201 — Privacy Policy |
| https://waves.tech/docs/terms | 201 — Terms and conditions |
| https://whatsonchain.com/privacy | 200 — Privacy Policy - WhatsOnChain.com - BSV Explorer |
| https://whatsonchain.com/terms | 200 — Terms of Use - WhatsOnChain.com - BSV Explorer |
| https://www.binance.com/en/about-legal/privacy-portal | 202 —  |
| https://www.binance.com/en/terms | 202 —  |
| https://www.blockchain.com/legal/privacy | 200 — Privacy / Blockchain |
| https://www.blockchain.com/legal/terms | 200 — Terms / Blockchain |
| https://www.blockcypher.com/privacy-policy.html | 200 — Privacy Policy - BlockCypher |
| https://www.blockcypher.com/terms-of-service.html | 200 — Terms of Services - BlockCypher |
| https://www.coingecko.com/en/privacy | 200 — Privacy Policy / CoinGecko |
| https://www.coingecko.com/en/terms | 200 — Terms and Conditions / CoinGecko |
| https://www.dash.org/privacy/ | 200 — Dash Core Group Privacy Statement / Dash |
| https://www.dash.org/terms-of-use/ | 200 — Terms of Use - Dash |
| https://www.exchangerate-api.com/terms | 200 — ExchangeRate-API - Terms &amp; Conditions of Use |
| https://www.gate.io/privacy-policy | HTTP Error 403: Forbidden |
| https://www.gate.io/user-agreement | HTTP Error 403: Forbidden |
| https://www.helius.dev/privacy-policy | 200 — Privacy Policy - Helius |
| https://www.helius.dev/terms | 200 — Terms of Service - Helius |
| https://xrpscan.com/privacy | 200 — Privacy Policy / XRPSCAN |
| https://xrpscan.com/tos | 200 — Terms of Service / XRPSCAN |

Other listed services without located terms/privacy pages remain unresolved for directory review. In particular, the Poloniex privacy URL returned a generic exchange page; mempool returned an unreadable HTML shell; several providers blocked this fetch. Solana Foundation website policies do not establish the public RPC endpoint’s policy coverage.

The source also supports merchant-configured endpoints: Monero wallet RPC, Solana JSON-RPC, and the `nmm_api_url` verification redirect. Their terms and privacy policies depend on the endpoint operator and cannot be verified by this plugin. The README therefore describes those policies as configuration-dependent rather than inventing links.

## Data and timing notes for review

Exchange requests carry ticker/currency query values and are made on cache misses during payment setup plus scheduled cache warming. Explorer requests carry public payment addresses and, where required, public transaction IDs/signatures; the background job repeats them until payment, expiry, or an implementation-specific retry window. BlockCypher's optional token is sent when configured. Monero sends wallet-RPC credentials and commands only to the merchant's configured wallet RPC. Normal HTTP metadata, including the store server's IP address, is visible to each receiving operator.

## Follow-up on 6 September 2026

ExchangeRate-API's https://www.exchangerate-api.com/terms includes a Privacy Policy section and explicitly covers er-api.com and subdomains; README now links to the combined document. The generic Poloniex privacy result is no longer presented as a verified policy. Frankfurter and other missing-policy entries now say “not located” rather than asserting no policy exists.

Official TronScan links identified: https://tronscan.org/contracts/terms and https://tronscan.org/aboutUs/privacyPolicy. A subsequent content fetch received HTTP 403, so these are identified policy URLs, not independently content-verified policies. The existing apilist.tronscan.org integration passed both native TRX and USDTTRX public-fixture checks on this date; this does not establish future anonymous API availability.

The self-configured endpoint section now acknowledges Solana's public default and avoids promising that a remotely hosted Monero wallet RPC is on the merchant's server. Verification redirects do not redirect pricing requests.

The detailed follow-up evidence is retained in the local review bundle. TzKT and ExchangeRate-API attribution requirements need to remain part of the service-use review; do not infer policy absence or endpoint permission from a successful HTTP response.
