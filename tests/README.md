# Tests

No framework needed — plain PHP scripts, exit code 0 = pass.

| Script | Network | What it proves |
|---|---|---|
| `test-hd-derivation.php` | none | HD (xpub) address derivation produces the known-good BIP32 addresses on both GMP and BCMATH backends. **If this fails, customer funds could go to wrong addresses — never ship.** |
| `smoke-explorers.php` | live APIs | Every blockchain explorer and exchange-rate API the plugin depends on still works, verified with real addresses. Run weekly via CI (`explorer-smoke.yml`) or manually. Accepts coin IDs as args to test a subset, e.g. `php tests/smoke-explorers.php BTC RATES`. |
| `verify_bip32.py` | none | Independent pure-Python BIP32/secp256k1 implementation used to originally cross-verify the expected addresses in `test-hd-derivation.php`. Kept for regenerating them. |
| `test-dashboard-escaping.php` | none | The read-only status screen escapes every value it prints - including a cryptocurrency read back from the payment table and a log message copied from a third-party API - and renders nothing without `manage_options`. Uses real `htmlspecialchars` stubs, not the identity helpers in `wp-stubs.php`. |
| `wp-stubs.php` | — | Minimal WordPress function stubs so plugin classes run standalone. |

CI (`.github/workflows/ci.yml`) runs on push: `php -l` across PHP 7.4–8.4, PHPCompatibility (`phpcs.xml.dist`), and the HD derivation test.
