# Tests

Plain PHP scripts. Inspect the success marker as well as the exit status: some WordPress scripts return zero even when assertions print FAIL. Database tests are destructive fixtures: use an isolated WordPress/WooCommerce installation, never a live store.

| Script | Network | What it proves |
|---|---|---|
| `test-hd-derivation.php` | none | HD (xpub) address derivation produces the known-good BIP32 addresses on both GMP and BCMATH backends. **If this fails, customer funds could go to wrong addresses — never ship.** |
| `test-hd-addrtype.php` | none | HD address version-byte mappings remain correct for every HD-capable coin and unsupported coin/type pairs fail closed. |
| `smoke-explorers.php` | live APIs | Every blockchain explorer and exchange-rate API the plugin depends on still works, verified with real addresses. Run weekly via CI (`explorer-smoke.yml`) or manually. Accepts coin IDs as args to test a subset, e.g. `php tests/smoke-explorers.php BTC RATES`. |
| `verify_bip32.py` | none | Independent pure-Python BIP32/secp256k1 implementation used to originally cross-verify the expected addresses in `test-hd-derivation.php`. Kept for regenerating them. |
| `wp-stubs.php` | — | Minimal WordPress function stubs so plugin classes run standalone. |

CI (`.github/workflows/ci.yml`) runs on push and pull requests: `php -l` across PHP 7.4–8.4, PHPCompatibility (`phpcs.xml.dist`), and the offline HD derivation and address version-byte tests. The release workflow calls this same CI at the tagged commit before publishing.

Additional offline tests cover amount arithmetic, QR payloads, address validation, settings bounds, exchange rates, Solana pagination and pinned HTTP requests. QR image decoding requires zbarimg; a skipped decode is not a successful decode test.

WordPress database tests: `test-sol-retry.php`, `test-autopay-cancel.php`, `test-payment-matcher.php`, `test-autopay-scan.php`, `test-order-init-lock.php`, `test-carousel.php`, `test-hd-verify.php`, `test-consumed-history.php`, `test-payment-integrity.php`, and `test-upgrade-compat.php`. Run each with `wp eval-file tests/<script> --path=<isolated WordPress>` after activating WooCommerce and this plugin. Payment integrity checks cover persistent replay prevention, exact thresholds, interrupted completion, database rollback and history import.
