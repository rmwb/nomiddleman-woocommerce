# Adoption request email — send to plugins@wordpress.org

Send this **after** the contact attempts (see contact-attempts.md) have gone
unanswered for at least 2–4 weeks. Fill in the two placeholders first.

---

**To:** plugins@wordpress.org
**Subject:** Adoption request: nomiddleman-crypto-payments-for-woocommerce (abandoned since 2020)

Hi Plugin Review team,

I'd like to request adoption of the plugin **Nomiddleman Bitcoin and Crypto
Payments for WooCommerce**:

https://wordpress.org/plugins/nomiddleman-crypto-payments-for-woocommerce/

**State of the plugin.** It was last updated in December 2020 (v2.4.8) by the
author `rgostic` and has not been touched since. It fatals on PHP 8 (the
bundled phpqrcode and Redux Framework libraries use `create_function`), its
support forum has reviews from 2021 reporting activation failures that were
never answered, and the developer's website (nomiddlemancrypto.io) no longer
exists. The ~100+ sites still running it are on a version that cannot work on
a modern host.

**Contact attempts.** I have tried to reach the original developer via:

- A post on the plugin's wordpress.org support forum on 2026-07-11
  ("Attempting to contact the plugin author about adoption")
- An issue on the original GitHub repository on 2026-07-11:
  https://github.com/nomiddleman/nomiddleman-woocommerce/issues/40
- A direct message to the author on the official WordPress Slack on
  2026-07-11 (screenshot available)

I have received no response. Screenshots of these attempts are available on
request.

**My version.** I have maintained a public fork for some time and it is in
active production use:

https://github.com/rmwb/nomiddleman-woocommerce

Since v2.4.8 it has had (all changelogged in readme.txt, currently v2.9.1):

- PHP 8.0–8.4 compatibility (bundled Redux Framework removed entirely and
  replaced with a native Settings API page; no migration needed)
- WooCommerce HPOS and Checkout Blocks support; tested to WP 7.0 / WC 10.8
- A security pass: all queries through `$wpdb->prepare`, nonce +
  capability checks on AJAX, full output escaping (WPCS
  `WordPress.Security.*` sniffs pass clean), ABSPATH guards in every file
- Replacement of every dead external API (the 2020 version's exchange-rate
  and verification endpoints are all gone) with working, documented services
  — see the readme's "External services" section
- Full internationalization (WordPress.WP.I18n sniff clean, POT shipped)
- A test suite and CI: offline BIP32-derivation and QR regression tests on
  every push, a lint matrix across PHP 7.4–8.4, and a weekly live smoke test
  of every verification API

A release zip built exactly as it would be deployed is attached / available
at: https://github.com/rmwb/nomiddleman-woocommerce/releases/latest

My wordpress.org username is **rmwb**.

I understand the plugin will be reviewed as if it were a new submission, and
that you will contact the original developer and give them 30 days to
respond. Happy to make any changes the review requires.

Thanks for your time,

Ross Bennetts
ross.bennetts@gmail.com
https://github.com/rmwb
