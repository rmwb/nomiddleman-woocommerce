# Reply to Plugin Review round 1 — draft

Send as a reply on the existing thread (keep the review ID in the subject so
it threads): **Review ID: D nomiddleman-crypto-payments-for-woocommerce/21Aug26/T1 21Aug26/4.3A1**

These fixes ship in **2.11.0**. The review branch was merged into the 2.11.0
line on 2026-08-22; v2.10.0 had already been tagged and released hours earlier
(from master, without these fixes), so the corrected code is the next version
up. Attach or link the 2.11.0 zip. Do not send until the tag is pushed and the
release zip exists.

Note that the 2.11.0 zip also carries the unreleased architecture work on that
branch, not just the review fixes.

---

Hi,

Thanks for the review. All four issues are fixed in 2.11.0:

https://github.com/rmwb/nomiddleman-woocommerce/releases/tag/v2.11.0

Two things are worth flagging, since both touch judgement calls rather than
straight corrections.

**cURL.** The Monero wallet RPC call now goes through `wp_remote_post()`. One
`curl_setopt_array()` call remains, inside an `http_api_curl` callback, and it
configures WordPress's own handle rather than a handle of ours. It is there
because two things that call needs have no HTTP API equivalent: `CURLOPT_RESOLVE`,
and HTTP digest authentication, which monero-wallet-rpc requires when the
merchant sets `--rpc-login`.

The pin matters because that URL is merchant-configurable, so it is an SSRF
surface: the plugin validates the host, resolves it, and then pins the
connection to the address it validated, otherwise a hostname can be re-bound to
a private target between validation and connect. `http_api_curl` only fires when
WordPress selects its cURL transport, and the streams transport resolves the
hostname again at connect time — precisely the window the pin closes. So the
helper filters the streams transport off for the duration of that one call and
refuses to send at all if the options were never installed, rather than
connecting unpinned. It also matches a per-request token and URL before
installing anything, so credentials cannot be applied to another plugin's
request dispatched in the same window. The call carries a `phpcs:ignore` with
that reasoning. If you would rather see this done a different way, I am happy to
change it.

**External services.** The readme now documents every host the code contacts,
with what is sent and when, and links to each operator's terms and privacy
policy. I fetched and checked all of them on 21 August.

Some of these services publish neither a terms of service nor a privacy policy —
several are volunteer-run block explorers. Where that is the case the readme
says so plainly rather than linking to something that does not exist. If you
would prefer those services be removed instead of documented as having no
published policies, tell me and I will drop them.

I also removed the request code for five coins outright, including the
hard-coded NEM node you flagged: I re-checked every endpoint, and Lisk's
layer-1 service, that NEM node, DeepOnion's remaining explorer, Myriad's
blockbook and insight.bitcore.cc are all dead with no replacement carrying
those chains. Autopay was already unavailable for those coins on the settings
screen, so nothing that worked before stopped working.

Plugin Check reports no errors against the built zip, and I tested activation
and the payment flows on a clean WordPress 7.1 install with `WP_DEBUG` on.

Thanks for your time,

Ross Bennetts
