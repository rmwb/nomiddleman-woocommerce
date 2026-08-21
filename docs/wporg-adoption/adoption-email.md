# Adoption request email — send to plugins@wordpress.org

Status: **SENT to plugins@wordpress.org on 2026-08-21.** The three contact
attempts were made 2026-07-11 (see contact-attempts.md) and none had a reply
in the six weeks before sending. The text below is exactly what was sent —
keep it as the record; if the review team replies, log the exchange in
contact-attempts.md rather than editing this file.

Facts below were re-verified against the wordpress.org plugin API on
2026-08-21: listing still open, version 2.4.8, last updated 2020-12-07,
"tested up to" 5.5.20, requires PHP 5.2.4, ~100 active installs, 22,640
downloads, 18 ratings (3.5 average), 2 unresolved support threads.

---

**To:** plugins@wordpress.org
**Subject:** Plugin adoption request: nomiddleman-crypto-payments-for-woocommerce

Hi Plugin Review team,

I would like to request adoption of **Nomiddleman Bitcoin and Crypto Payments
for WooCommerce**:

https://wordpress.org/plugins/nomiddleman-crypto-payments-for-woocommerce/

**State of the plugin.** The listing is still open but has not been updated
since 7 December 2020 (v2.4.8, author `rgostic`). It declares "tested up to"
WordPress 5.5 and "requires PHP 5.2.4", and it fatals on PHP 8 — the bundled
Redux Framework copy calls `create_function()`, which was removed in PHP 8.0.
Its support threads are unanswered, and the plugin's homepage
(nomiddlemancrypto.io) no longer serves anything — the domain resolves, but
the server returns an empty reply. The upstream GitHub repository
(github.com/nomiddleman/nomiddleman-woocommerce) has had no commits since
May 2024 and has 30 open issues.

This is a payment gateway that handles customer funds, so the roughly 100
sites still running it are on a four-and-a-half-year-old release that cannot
run on a supported PHP version and whose exchange-rate and blockchain
verification endpoints have since gone offline.

**Contact attempts.** I tried to reach the original developer three ways on
11 July 2026, and have had no reply in the six weeks since:

- Support-forum post as @rmwb, "Attempting to contact the plugin author about
  adoption":
  https://wordpress.org/support/plugin/nomiddleman-crypto-payments-for-woocommerce/
- GitHub issue on the upstream repository (still zero comments):
  https://github.com/nomiddleman/nomiddleman-woocommerce/issues/40
- A direct message to rgostic on the official WordPress Slack

Screenshots of all three are available on request.

**My version.** I maintain a public GPLv3 fork that is in active production
use on my own store, and I have kept the original author credited in the
readme:

https://github.com/rmwb/nomiddleman-woocommerce

The current release is 2.9.9 (25 July 2026). Everything below is changelogged
in readme.txt:

- PHP 8.0–8.4 compatibility. The bundled Redux Framework was removed entirely
  and replaced with a native Settings API page, keeping the same option key
  and page slug, so existing sites need no migration.
- WooCommerce HPOS and Checkout Blocks support; tested to WordPress 7.0 and
  WooCommerce 10.8, PHP 7.4 minimum.
- A security pass: every query through `$wpdb->prepare()`, nonce and
  capability checks on AJAX, full output escaping, ABSPATH guards in every
  file, and SSRF protection on the merchant-configured Monero wallet RPC URL.
  The WPCS `WordPress.Security.*` sniffs pass clean and run in CI.
- Every dead external API replaced with working, documented services — the
  2020 version's exchange-rate and payment-verification endpoints are all
  gone. The services the plugin contacts are documented in the readme's
  "External services" section.
- A long series of payment-correctness fixes: atomic address allocation so
  two simultaneous checkouts can never be handed the same address, atomic
  claims so a cancellation and a verified payment cannot both win, and
  guards against crediting a late payment to the wrong order.
- Full internationalization (`WordPress.WP.I18n` sniff clean, POT shipped).
- A test suite and CI: offline BIP32-derivation, QR and payment-matcher
  regression tests plus database integration tests on every push, a lint
  matrix across PHP 7.4–8.4, and a weekly live smoke test of every
  verification API.

An installable zip, built exactly as it would be deployed, is at:
https://github.com/rmwb/nomiddleman-woocommerce/releases/latest

My wordpress.org username is **rmwb**.

I understand the code will be reviewed as if it were a new submission, and
that you will attempt to contact the original developer and give them time to
respond — if rgostic wants the plugin back, or would rather add me as a
committer directly, I am happy either way. My goal is simply that the sites
still running v2.4.8 can get a working, maintained update.

Happy to make any changes the review asks for.

Thanks for your time,

Ross Bennetts
ross.bennetts@gmail.com
https://github.com/rmwb
