# Chrome policy audit — 14–15 September 2026

Checked 54 URLs in local Google Chrome using automated headless browser sessions: 46 policy/documentation URLs and eight provider home/API pages. Three discovered legal links were also followed. This is browser-rendered evidence, not the text-only fetch used earlier. A denied automated Chrome session does not prove the page fails in a normal signed-in or regional browser; it does not establish why access was denied.

## Findings

- Full content now renders for mempool, Waves, Blockstream and Poloniex. Waves returns HTTP 201 but still serves substantive terms and privacy text.
- CoinGecko still shows a human-verification screen in automated Chrome, but the maintainer confirmed both policy links work in normal Chrome on 15 September. HitBTC, Blockchair and TronScan showed restriction/denial pages in the automated check. Gate replacement legal URLs were subsequently content-verified through the web reader, although automated Chrome still denied access. Binance privacy portal renders. Its terms were subsequently verified as an embedded PDF; the initial navigation-only capture missed the iframe. The linked full privacy notice still needs embedded-document inspection.
- TzKT footer links to Baking Bad terms/privacy. Both expressly exclude API services and say those have separate policies; they cannot substitute for the API policies.
- No terms/privacy links were exposed in the captured LitecoinSpace, EOS Rio, dcrdata, Qtum or BlackCoin pages. DigiExplorer rendered blank. These captures do not establish policy absence.
- Poloniex privacy is verified, but its current User Agreement limits API use to trading on Poloniex and restricts other commercial use. Operator clarification is needed for merchant price lookups; this is a use-permission question, not a missing-policy question.
- All payment modes remain enabled. No operators have been contacted.

## Per-URL results

| URL | HTTP | Rendered result |
|---|---|---|
| https://www.coingecko.com/en/terms | 403 (automated session) | Maintainer confirms working in Chrome; automated recheck receives human-verification screen |
| https://www.coingecko.com/en/privacy | 403 (automated session) | Maintainer confirms working in Chrome; automated recheck receives human-verification screen |
| https://hitbtc.com/terms-of-use | 403 | Regional restriction |
| https://hitbtc.com/privacy-policy | 403 | Regional restriction |
| https://www.gate.io/user-agreement | 403 | Access denied |
| https://www.gate.io/privacy-policy | 403 | Access denied |
| https://www.binance.com/en/terms | 200 (PDF) | Embedded 73-page Terms of Use verified; effective 21 July 2026 |
| https://www.binance.com/en/about-legal/privacy-portal | 202 | Privacy portal and regional links; not a single complete global policy |
| https://poloniex.com/terms | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.poloniex.com/support/privacy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://frankfurter.dev/#faq | 200 | API documentation with commercial-use and privacy FAQ |
| https://www.exchangerate-api.com/terms | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://mempool.space/terms-of-service | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://mempool.space/privacy-policy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://blockstream.com/terms | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://blockstream.com/privacy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.blockchain.com/legal/terms | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.blockchain.com/legal/privacy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.blockcypher.com/terms-of-service.html | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.blockcypher.com/privacy-policy.html | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://eaas.blockscout.com/terms-and-conditions | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://eaas.blockscout.com/privacy-notice | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://whatsonchain.com/terms | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://whatsonchain.com/privacy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.dash.org/terms-of-use/ | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.dash.org/privacy/ | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://xrpscan.com/tos | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://xrpscan.com/privacy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://koios.rest/terms.html | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://koios.rest/privacy.html | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://blockchair.com/terms | 401 | Access denied |
| https://github.com/Blockchair/Blockchair.Support/blob/master/PRIVACY.md | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://chainz.cryptoid.info/terms.dws | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://stellar.org/terms-of-service | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://stellar.org/privacy-policy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://waves.tech/docs/terms | 201 | Substantive policy text rendered; scope must be assessed separately |
| https://waves.tech/docs/privacy-policy | 201 | Substantive policy text rendered; scope must be assessed separately |
| https://greymass.com/privacy_policy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://groestlcoin.org/privacy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://tronscan.org/contracts/terms | 403 | Access denied |
| https://tronscan.org/aboutUs/privacyPolicy | 403 | Access denied |
| https://solana.com/tos | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://solana.com/privacy-policy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.helius.dev/terms | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://www.helius.dev/privacy-policy | 200 | Substantive policy text rendered; scope must be assessed separately |
| https://blockchair.com/privacy | 401 | Access denied |
| https://litecoinspace.org | 200 | Provider/API page; no applicable policy verified |
| https://api.tzkt.io | 200 | Provider/API page; no applicable policy verified |
| https://tzkt.io | 200 | Provider/API page; no applicable policy verified |
| https://eosrio.io | 200 | Provider/API page; no applicable policy verified |
| https://explorer.dcrdata.org | 200 | Provider/API page; no applicable policy verified |
| https://digiexplorer.info | 200 | Blank capture |
| https://qtum.info | 200 | Provider/API page; no applicable policy verified |
| https://explorer.blackcoin.nl | 200 | Provider/API page; no applicable policy verified |

## Discovered links

- https://bakingbad.dev/terms/ — substantive; explicitly excludes API services.
- https://bakingbad.dev/privacy/ — substantive; explicitly excludes API services.
- https://www.binance.com/en/about-legal/New-Privacy-Notice-05January2026 — navigation-only capture; content not verified.

Rendered source captures are retained locally in `.verification/logs/round3/chrome-policies/`. Only these findings, not full third-party policy text or diagnostic IP data, are included in the repository.

## Binance embedded-PDF correction — 15 September 2026

The maintainer identified the PDF embedded within the terms page. Chrome iframe and response inspection confirmed a publicly accessible PDF (HTTP 200, application/pdf), and document extraction confirmed 73 pages headed Terms of Use, effective 21 July 2026. The earlier top-level body-text capture missed the PDF viewer; it did not establish missing or inaccessible terms. Keep the stable https://www.binance.com/en/terms link in README. This resolves document availability, not every product-specific use condition.

Verified embedded document: https://bin.bnbstatic.com/static/cms/cg08ou2ak0tn7mcplvfg/file/bf4879710c904b991848972ec4818ba2cf9e4ce314c09adae84fa2750d3477f7.pdf

## CoinGecko maintainer confirmation — 15 September 2026

The maintainer confirmed that both https://www.coingecko.com/en/terms and https://www.coingecko.com/en/privacy work in their Chrome browser. A fresh automated Chrome recheck returned HTTP 403 with a human-verification screen for both pages. Retain the existing README links: this is an automated-access limitation, not a broken-link finding. Current policy content was not independently reverified by that automated session.

## Gate replacement links — 15 September 2026

The maintainer supplied https://www.gate.com/legal/privacy-policy and https://www.gate.com/legal/user-agreement. Both documents were readable through the web reader, with substantive privacy and user-agreement content. Their opening sections explicitly include Gate APIs in the described services. Automated local Chrome still returned HTTP 403 Access Denied for both, so browser access may vary. README now uses these gate.com legal URLs in place of the older gate.io links. This verifies policy content and corrects the links; it is not a blanket approval of all API uses or a change to the configured price endpoint.
