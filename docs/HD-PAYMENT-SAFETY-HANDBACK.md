# Privacy Mode payment safety: handback for independent review

This is the handback that section 15 of the specification asks for, `HD-PAYMENT-SAFETY-HANDOFF.md`. It was written for independent testing and review, and last updated on 30 September 2026, after the release.

**Status.** The owner merged this work into `master` (pull requests #23 and #24) and released it as **2.13.0** on 30 September 2026. It has not been deployed to a store as part of this work: no live store, customer, order or mail server was touched. Deployment is a separate step with its own runbook, [HD-DEPLOYMENT.md](HD-DEPLOYMENT.md). The sections below are the record as handed back, with the results and review status that led to the release.

Design and step-by-step evidence are in [HD-PAYMENT-SAFETY-REPORT.md](HD-PAYMENT-SAFETY-REPORT.md). This page is the summary and index.

**About the commit references.** The commit hashes in this document and in the report are from the author's working history, which is not published: some of its earlier commits named the real order and address from the incident. The Privacy Mode work is in `master` as one commit (merged by #24), so those hashes cannot be looked up there. The Autopay work was merged with its own history (#23), and its hashes can.

## 1. Branch, baseline and diff

- **Branch:** `hd-payment-safety` in the author's working copy. Its files were published as one commit on top of the Autopay branch and merged into `master` by #24 (see "About the commit references" above). The two published branches were deleted after merging.
- **Baseline:** `90f8787`. It is the tip of the Autopay safety work, which has its own review track. The specification's baseline, `e0dda61`, is an ancestor.
- **Commits** (`git log 90f8787..hd-payment-safety`):

| Commit | Step |
|---|---|
| `a43b556` | A: reproduce the incident (tests fail on the baseline) |
| `ceaedf4` | B: schema 1.5, legacy policy, the automatic-Privacy-Mode gate |
| `7d5bb22` | B follow-up: log the legacy-row count without `var_export` |
| `71a4066` | C: fresh assignment and permanent non-reuse |
| `f83232e` | D: evidence contract and coin capabilities |
| `79bdb06` | E: attributable-payment verifier, atomic claim, evidence-based expiry (includes Step F) |
| `005949c` | G: late-payment monitor, order panel, log hygiene |
| `d3dc8f6` | H: read-only audit, manual reconciliation, operator docs |
| `724a203` | Cleanup: dead legacy repository methods removed; Plugin Check triage re-scanned |

- **Review-round fixes:** the commits after `724a203` up to `1dc3c48` are the fixes from the eight Privacy Mode review rounds (§ 9) and this handback.
- **Merges of the Autopay track (30 Sep):** `d44d252` merges `integrate/post-2.12.0` at `a7fb569` into this branch, and `80decf4` merges its one later commit, `ae8da74`. The baseline `90f8787` was that branch's tip when this work started; the Autopay review has since added five commits, which the merges bring in:

| Commit | Autopay change |
|---|---|
| `593ebad` | Codex round 4, six must-change: stored-status check in the cancellation save, a refused cancellation aborts the save, settlement compares with the exact status read, no schema change from an order event, sweep-start and pause-read failures stop the pass |
| `b2b95f0` | Codex round 5: doc correction, and no lease is settled or cancellation started until the lease migration has recorded itself |
| `889a2f4` | A regression from `593ebad` under HPOS, found by the author: WooCommerce's own second save of a cancelled order was refused |
| `a7fb569` | Codex round 6, M1: only the canceller's own save approves the cancellation, and later cancelled saves are allowed only while the order is stored as cancelled |
| `ae8da74` | Codex round 7's two optional notes: a wording fix, and tests that a later save still needs both locks. No behaviour change. |

  Each merge had one conflict, in `tests/README.md`, resolved by keeping both sides' rows. Source files merged without conflict. Details: [HD-PAYMENT-SAFETY-REPORT.md § Merge of the Autopay safety track](HD-PAYMENT-SAFETY-REPORT.md#merge-of-the-autopay-safety-track).
- **Diff:** `git diff 90f8787..80decf4`, 50 files, +10,727 / −2,197, of which the Autopay track is 8 files, +720 / −151 (`git diff 1dc3c48..80decf4`, leaving out the `docs/HD-*` files). This handback document is committed on top.

## 2. Behaviour in brief

- **Gate.** Privacy Mode runs only when three things hold: schema 1.5 is verified, the coin has a reviewed transaction-evidence adapter, and the coin is not suspended by the used-address limit (`NMMPRO_Hd::automatic_available()`). Otherwise nothing is issued, completed or cancelled.
- **Allocation.** An address is issued only after an on-chain check of its full transaction history shows no activity at all: no receipts, no pending transfer, and not merely a zero balance.
  - An unknown answer is never treated as clean.
  - Used addresses are retired permanently and never returned to the pool.
  - The binding time comes from the database clock.
  - A retried checkout keeps its own address.
- **Verification.** Only outputs confirmed at or after the binding count toward the order (or, if unconfirmed, first seen after the clean check). Everything else contributes zero or goes to review:
  - history before the binding;
  - outputs owned by another order or an unknown earlier owner;
  - outputs that vanished;
  - unconfirmed or conflicting outputs.

  Timing within the block-clock slack before the binding goes to review, as does an output above the chain height recorded at binding whose timestamp claims it came earlier. Scans read down to that height rather than trusting block times, and every listing is checked against the explorer's own transaction counters. Before a claim, the evidence is re-read in full; an address with too much history for that is held for review. Before `payment_complete()`, each output is claimed atomically in the ledger shared with Autopay. A crash at any point resumes the same order and never a different one.
- **Expiry.** An order is cancelled only when a complete scan shows nothing eligible. An underpaid expired order goes to review; an explorer failure never cancels.
- **Migration.**
  - Addresses left unused (pool or quarantine) are retired.
  - Orders assigned by an earlier release are held for review. They are never completed or cancelled automatically.
  - Nothing is deleted, and derivation never rewinds.
- **Retries.** Explorer failures back off. Scans resume from a saved, source-bound cursor, and incomplete coverage never counts as "nothing received".

## 3. Schema, states and capability matrix

- **Schema and states:** see [HD-PAYMENT-SAFETY-REPORT.md § Schema and state design](HD-PAYMENT-SAFETY-REPORT.md#schema-and-state-design). It covers the address-table columns, the evidence table, ownership and the state diagram.
- **Capability matrix:** see [§ Capability matrix](HD-PAYMENT-SAFETY-REPORT.md#capability-matrix). In brief:

| Coin | Privacy Mode | Evidence sources |
|---|---|---|
| BTC | yes | mempool.space, then blockstream.info (Esplora) |
| LTC | yes | litecoinspace.org, then BlockCypher |
| DOGE | yes | BlockCypher |
| DASH | yes | BlockCypher |
| QTUM, BTX | **withdrawn** | only lifetime totals are available |
| XMY | no (unchanged) | none |

## 4. Regression matrix → tests

All the suites below are database suites (`wp eval-file`). "§n" is the numbered section a suite prints.

| Group (spec § 13) | Tests |
|---|---|
| Reported incident | `test-hd-historical` §1 (3800 DOGE against 456.80863668, through the real verifier; fails on the baseline, passes now); `test-hd-verify` §2 |
| Historical amounts | `test-hd-verify` §2 (exact match, overpayment, several and spent old receipts contribute 0; lifetime total diagnostic only) |
| Mixed history | `test-hd-verify` §3 (old 3800 + new 10 credits exactly 10; remainder requested, then completes) |
| Real payment | `test-hd-verify` §1 (completes once, ledger owner, output index, note); §15 (BTC via Esplora); `test-hd-historical` control |
| Timing | `test-hd-verify` §4 (exact boundary, just before → review, pending-first-seen rule, multi-day downtime), §16 (the exact classification), §17 (early-stamped blocks: the scan is not cut short, early-stamped payments go to review, expiry is blocked, the height rule); `test-hd-evidence` §2 (UTC block time, never the fetch time), §6 (block above tip), §9 (height floor, floor above tip); `test-hd-allocation` §2 (height recorded at binding) |
| Confirmations | `test-hd-verify` §5 (one short waits, exact completes, legacy 0 needs a block); `test-hd-allocation` §1–2 (pending transfer blocks allocation) |
| Exact amounts | `test-hd-verify` §6 (one unit short, exact, zero expected, amounts beyond native integers) |
| Split payments | `test-hd-verify` §3 (three parts across sweeps complete once; repeated sweeps add nothing) |
| Output identity | `test-hd-verify` §7; `test-hd-evidence` §3–4 (pages cut mid-block, duplicates, conflicts defer) |
| Replay | `test-hd-verify` §8 (another order's transaction, unknown earlier owner → review), §11 (same-order recovery); `test-hd-audit` §5 (reconcile refuses a foreign owner); `test-consumed-history` (Autopay side) |
| Allocation | `test-hd-allocation` §1–2 (stale ready row, spent zero balance, pending, empty/malformed/foreign answer, HTTP 500, unreadable chain height, DB reservation and binding failure, key mismatch); `test-hd-historical` §2 |
| Non-reuse | `test-hd-allocation` §3 (ended order's address never reissued, retried checkout stable, switched checkout), §5; `test-hd-verify` §12 (late payment to a dead order is noted only) |
| Reservation races | `test-hd-allocation` §4, using a second DB connection: held candidate, expired reservation and stale worker, crash after binding resumes, concurrent pool fill |
| Verification races | `test-hd-verify` §9 (a held address lock; order cancelled through a second connection mid-verification); §11 (completion in flight not expired) |
| Failures | `test-hd-verify` §10 (failed claim rolls back, evidence-write failure, explorer failure); `test-hd-evidence` §5–6 (malformed, 429/500, no tip, unsupported); `test-hd-migration` §6 (unsupported coin) |
| Pagination | `test-hd-evidence` §3 and §6 (empty history, full page at one height, budget exhaustion and resume, repeated cursor, full mempool list, source-bound cursor), §8 (listings checked against counters: bare, empty and cut-short answers, also when resuming), §10 (budget-exhaustion flag); `test-hd-verify` §14 (no expiry on incomplete scan), §17 (a degraded answer never cancels; six pages complete; a flooded address → review) |
| Recovery | `test-hd-verify` §11 (crash before claim, after claim, after completion; WooCommerce exception / no transition; no duplicate success note) |
| Order states | `test-hd-verify` §12 (pending/on-hold/custom payable; failed, cancelled, refunded, deleted, changed gateway/address/currency never completed) |
| Reorg | `test-hd-verify` §13 (evidence vanishing before completion); `test-hd-audit` §1 (after paid → `credited_evidence_missing` finding only) |
| Migration | `test-hd-migration` §1–8 (fresh, old schema with every legacy state, repeated, partial failure and retry, MyISAM, gate, second-site isolation, wallet scoping); `test-upgrade-compat` |
| Presentation | `test-hd-verify` §2–3 (notes deduplicated, remainder shown); `test-hd-monitor` §4–5 (order panel escapes output, reason labels, no key material in logs); `test-dashboard-escaping` (offline) |
| Wallet recovery | `test-hd-allocation` §5 (no rewind, retired rows kept, used-address cap suspends instead of recycling, re-save lifts it); `test-hd-migration` §2 (index preserved) |

**Known gaps in the matrix** (also in § 6):

- **Uncertain commit:** a lost or uncertain response to the claim's commit is not simulated.
- **HD vs Autopay:** no test runs the real Autopay matcher and the Privacy Mode verifier concurrently on one address. The tests cover the two shared mechanisms: ledger ownership and the per-address lock that both paths take.
- **Named locks:** for Privacy Mode, "no named locks at all" (`GET_LOCK` unsupported) is covered by design only (processing waits). The tests exercise a held lock.
- **Lease takeover:** this is an Autopay concept, covered by `test-autopay-safety`. Privacy Mode's equivalent is the `completing` claim, and resuming it is tested.

## 5. Test commands, versions and results

**Environment:** WordPress 7.1.2, WooCommerce 11.1.2, MariaDB 13.0.2 (and, for the MySQL rows below, MySQL 8.4.11 and 8.0.46), PHP 8.5.10 (macOS). It is an isolated local install with no outgoing mail, and the plugin is symlinked from this checkout.

**Commands:**

```sh
# offline (CI "offline" job)
php tests/test-amount.php
php tests/test-hd-derivation.php
php tests/test-hd-addrtype.php
php tests/test-settings-bounds.php
php tests/test-dashboard-escaping.php
# ...and the other offline suites in .github/workflows/ci.yml

# database: each must print its "... CHECKS PASSED" banner
wp eval-file tests/test-hd-historical.php --path=/abs/path/to/isolated-wp
wp eval-file tests/test-hd-migration.php  --path=...
wp eval-file tests/test-hd-allocation.php --path=...
wp eval-file tests/test-hd-evidence.php   --path=...
wp eval-file tests/test-hd-verify.php     --path=...
wp eval-file tests/test-hd-monitor.php    --path=...
wp eval-file tests/test-hd-audit.php      --path=...
# plus the 11 existing DB suites listed in ci.yml

# static (CI jobs)
vendor/bin/phpstan analyse -c phpstan.neon.dist
vendor/bin/phpcs -p --standard=<wpcs-security.xml from ci.yml>
vendor/bin/phpcs -p   # PHPCompatibility, testVersion 7.4-
bash tests/plugin-check.sh
php tests/smoke-explorers.php   # live, read-only
```

**Results** (the raw logs are kept by the author, outside this repository; the Log column names the folder for each run):

| Check | Result | Log |
|---|---|---|
| 18 DB suites, CPT, REPEATABLE READ, code as committed at `724a203` | all PASS (twice) | `cpt-rr/`, `cpt-rr-run2/` (each has `summary.txt`) |
| After the round-1 fixes (`579e898`): the 7 Privacy Mode suites, static checks, Plugin Check, live smoke | all PASS; static checks clean; Plugin Check 0 errors, no change from `724a203`; live smoke passes for all four coins, including the chain-height read | `r1-fixes-579e898/` |
| After the round-2 fixes (`ecf5304`): the 7 Privacy Mode suites, static checks, Plugin Check, live smoke | all PASS; static checks clean; Plugin Check 0 errors and 4 more warnings, all from the one new query, in the existing categories; live smoke passes for all four coins | `r2-fixes-ecf5304/` |
| **Final code (`0ff4b68`, 28 Sep): all 18 DB suites, CPT, READ COMMITTED** (global isolation switched and verified from inside WordPress, then restored) | all PASS | `final-0ff4b68/cpt-read-committed/` |
| **Final code (`0ff4b68`, 28 Sep): all 18 DB suites, HPOS** (compatibility sync off, REPEATABLE READ; verified `OrderUtil::custom_orders_table_usage_is_enabled()`, then switched back to CPT) | all PASS | `final-0ff4b68/hpos-repeatable-read/` |
| **Merged code (`80decf4`, 30 Sep): all 18 DB suites, CPT, REPEATABLE READ** | all PASS (`test-autopay-safety` now 146 checks) | `merge-80decf4/cpt-repeatable-read.txt` |
| **Merged code (`80decf4`): all 18 DB suites, HPOS** (compatibility sync off; verified from inside WordPress, then switched back) | all PASS | `merge-80decf4/hpos-repeatable-read.txt` |
| **Merged code (`80decf4`): all 18 DB suites, CPT, READ COMMITTED** (switched, verified, restored) | all PASS | `merge-80decf4/cpt-read-committed.txt` |
| Merged code (`80decf4`): PHPStan, WPCS security, PHPCompatibility, Plugin Check | clean; Plugin Check 0 errors, 557 warnings (555 before the merges; the 2 new ones are from one new Autopay query, in existing categories) | `merge-80decf4/` |
| **MySQL 8.4.11 (`7fa58fd`, 30 Sep): all 18 DB suites** under CPT, HPOS, and CPT with READ COMMITTED. A fresh WordPress and WooCommerce install in an empty database, so the plugin's activation and migrations ran on MySQL too. | all PASS, with the same check counts as on MariaDB; all six plugin tables created as InnoDB; both migrations recorded | `mysql84-7fa58fd/` |
| **MySQL 8.0.46 (`7fa58fd`): the same** | all PASS, same counts | `mysql80-7fa58fd/` |
| **GitHub CI on the published branch (30 Sep):** `php -l` on PHP 7.4, 8.0, 8.2 and 8.4; PHPStan; WPCS security; PHPCompatibility; the offline suites; and all 18 DB suites on Linux with PHP 8.3.35, MariaDB 10.6.28, WordPress 7.1.2 and WooCommerce 11.1.2 | all 13 jobs pass. The first run failed in one job: PHPStan reached CI's 1 GB memory limit. The limit is now 2 GB (`.github/workflows/ci.yml`) and the second run passed. | GitHub Actions. The same 13 jobs later passed on both pull requests, on `master` after each merge, and in the release workflow for `v2.13.0`. |
| The first merge (`d44d252`), the same three runs and checks | all PASS (`test-autopay-safety` 140 checks); same static and Plugin Check results | `merge-d44d252/` |
| Autopay track before the merges (`ae8da74`): `test-autopay-safety` under CPT, HPOS, HPOS with compatibility sync, and READ COMMITTED; mutation 16/16 | all PASS; every mutation fails the suite | `autopay-r5-fixes/`, `autopay-hpos-fence/`, `autopay-r6-fix/`, `autopay-r7-tidy/` |
| (Historical) HD suites + `test-autopay-safety` under HPOS | PASS, but **before Step H's final commit** (22:40–23:08 on 26 Sep; `d3dc8f6` was committed at 23:11, `724a203` at 23:20) | `earlier-hpos-pre-H/` |
| Incident suite, CPT and HPOS, twice each; baseline run | PASS; the baseline run fails as intended | `earlier-hpos-pre-H/` |
| Offline suites | PASS (QR decoding skips without `zbarimg`) | not saved; run as above |
| PHPStan level 5, WPCS security, PHPCompatibility | clean (no new baseline entries; two stale ones removed) | not saved |
| Plugin Check (release zip), at `724a203` | 0 errors, 573 warnings, all in the justified categories (557 on the merged code; see above) | `docs/PLUGIN-CHECK-TRIAGE.md` |
| Live explorer smoke (read-only), 26 Sep 13:15 UTC | all four HD evidence checks PASS. LTC was served by the BlockCypher fallback because litecoinspace.org failed. | not saved |
| Mutation testing, per step | B 11/11, C 16/16 (plus 1 equivalent), D 29/29, E 19/21, G 13/13, H 12/13, review round 1 14/14, round 2 10/10, round 3 6/6 (window since removed), round 4 4/4, round 5 6/6, round 6 6/6, expiry cursor 2/2, round 7 6/6. The survivors are guarded by a second or third independent layer. | report, each step's Evidence section |

**Skipped or blocked:**

- **PHP versions.** The syntax lint ran in CI on PHP 7.4, 8.0, 8.2 and 8.4, and passes. The test suites ran only on PHP 8.3 (CI) and 8.5 (locally), not on 7.4 to 8.2.
- **MySQL:** tested on 8.4 and 8.0 only (see the table). MySQL 5.7 and earlier were not tested; the plugin's handling of servers before 5.7.5, which lack usable named locks, is covered by tests that simulate it.
- **Upgrade on MySQL:** the MySQL runs used a fresh install. The 1.4 → 1.5 upgrade ran there only through `test-hd-migration`'s scratch tables, not on a copy of a real older store.
- **Order panel:** not viewed in a browser. Its HTML is asserted in `test-hd-monitor` §4.
- **Real payments:** none were made, as the specification requires.

## 6. Audit

The audit is read-only (`wp nmmpro-hd audit`); usage is in [HD-RECONCILIATION.md](HD-RECONCILIATION.md). There is a sanitized example report in [HD-PAYMENT-SAFETY-REPORT.md § Example report](HD-PAYMENT-SAFETY-REPORT.md#example-report-sanitized). `test-hd-audit` §1 asserts that it writes nothing: it snapshots the plugin tables, the ledger, the options and the orders before and after the run, and compares them.

## 7. Deployment, legacy orders and wallet gap

- **Runbook and rollback:** [HD-DEPLOYMENT.md](HD-DEPLOYMENT.md).
  - **Prerequisites:** a BlockCypher token for DOGE/DASH (it also helps LTC's fallback); working `GET_LOCK`; `ALTER`/`CREATE` privileges.
  - **Rehearse on a copy:** run the upgrade and the audit on an isolated copy before deploying.
- **Legacy orders:** every order still awaiting payment on an address assigned by an earlier release is held for review. It becomes manual work ([HD-RECONCILIATION.md](HD-RECONCILIATION.md)) and is never completed or cancelled automatically. Already-paid orders are unchanged; the audit flags false positives such as the incident order (`false_positive_confirmed`), but nothing reverses them.
- **Wallet gap:** addresses are never reused, so abandoned checkouts leave unused addresses. A wallet restored from its seed with a small gap limit may miss funds. See [HD-WALLET-RECOVERY.md](HD-WALLET-RECOVERY.md); the Status screen shows each wallet's derivation range.

## 8. Unresolved limitations

1. **The wallet must be dedicated to the store.** Funds sent to a store address by the owner's own wallet software, or by another store, are indistinguishable from customer payments made after the binding.
2. **Block-time slack.** A genuine payment whose block timestamp falls inside the slack before the binding, or before the binding in a block above the recorded height, goes to review, not completion. This is a false negative by design, never a false positive.
   - **Busy addresses.** An address with more than 20 pages of history since its binding goes to review.
   - **Scans that start afresh.** A count shortfall, or a reorganisation that replaced transactions at heights already read, drops the saved progress, so the next scan re-reads from the top. On an address with more history than one scan's budget, repeated inconsistencies from the explorer would keep it incomplete, and it waits: late, never wrong.
   - **Resumed coverage is advisory.** A resumed scan's counts can be misled by a reorganisation or an inconsistent explorer. That can delay a payment's recognition or a late-payment note, but it cannot pay or cancel anything, because both are decided on fresh scans.
   - **Explorer outages.** An address unreadable for 24 hours gets one note on its order. The order is neither completed nor cancelled until the explorer answers completely.
   - **Busy stores.** Each wallet's verification, expiry and monitoring pass spends about 30 seconds on explorer work per cron cycle (filterable). The limit is checked between addresses, so a pass can run over by one address's work (at most one fresh scan of 20 pages). With many open orders and slow explorers, some are checked in a later cycle; each pass carries on where it stopped.
   - **Expired orders on busy addresses go to review.** An expired order whose address has more history than one fresh scan reads (20 pages) is held for review instead of being cancelled.
   - **Scheduling fairness is bounded, not absolute.** Each pass rotates through its rows. Arrivals sustained faster than a pass can process them postpone its wrap-around, and monitoring stops 90 days after binding. If cron runs overlap because named locks are unavailable, a pass's saved position can be overwritten by an older one (Codex round 8, SHOULD). The effect is re-work, not a skipped decision.
   - **Records bound without a height.** These are records bound before the round-1 fixes, and none exist in production. They keep the time-based rules.
3. **DOGE and DASH trust one provider (BlockCypher).** No second source was reviewed and found adequate. An outage pauses verification; it never produces a wrong answer. LTC and BTC have a fallback.
4. **BlockCypher rate limits** without a token (about 100 requests an hour) can delay checkout and verification.
5. **Named locks are required.** Without `GET_LOCK`, Privacy Mode processing waits indefinitely (safe, but no progress).
6. **Late-payment monitoring stops after 90 days.** Payments to retired addresses after that are not noted.
7. **Third-party hooks are not exactly-once.** `payment_complete()` side effects of other plugins can repeat if WooCommerce fails between its save and our claim settlement. This is the same limit documented for Autopay.
8. **QTUM and BTX lose Privacy Mode.** Stores using it must switch to Classic Mode.
9. **Manual work.** Legacy held orders and audit findings need a person.
10. **Changelog size.** With the 2.13.0 entry, the README changelog is at about 4,990 of Plugin Check's 5,000 words; the next release must trim it.
11. **Untested paths** (from § 4 and § 5): the uncertain claim commit; a concurrent real Autopay/Privacy Mode run on one address; MySQL before 8.0; the test suites on PHP before 8.3.
12. **Autopay limits come with the merge.** They are listed in [AUTOPAY-SAFETY.md § Known limits](AUTOPAY-SAFETY.md#known-limits). Two are new since the baseline:
    - **Lease migration.** Until the payment table's lease migration has recorded itself (`nmmpro_payment_lease_schema`), Autopay settles no in-flight record and expires no order. Payments are still matched and their orders completed. The migration retries on every page load, but a site where it can never record stays in that state. Privacy Mode does not depend on it.
    - **A refused cancellation leaves a note.** When the cancellation check refuses a save, WooCommerce adds an internal error note to the order.

## 9. Independent review status

**The first Codex review (read-only, 27 Sep) stopped at its usage limit without a verdict.** Its prompt and log are kept by the author, outside this repository, as are those of the later rounds. Before stopping it reported three defects. All three were confirmed, and all three are **fixed**. The details and the evidence are in [HD-PAYMENT-SAFETY-REPORT.md § Independent review, round 1](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-1-fixes).

1. **A degraded explorer answer read as "nothing received".** Every listing is now checked against the explorer's own transaction counters (BlockCypher `n_tx`/`unconfirmed_n_tx`; Esplora `tx_count`). A shortfall is incomplete, never empty, so expiry can no longer cancel a paid order over it.
2. **Re-validation was stranded on a busy address.** Re-validation now has its own 20-page budget. An address with more history than that is held for review (`history_too_large`), never retried for ever.
3. **Scans stopped on the first old block time.** The chain height is recorded at binding (`validated_height`), and scans stop by height. An output above that height is never historical: if its timestamp says it came earlier, it goes to review, and expiry does not cancel over it.

**The second Codex review (13:12) also stopped at its usage limit without a verdict.** It confirmed the round-1 counter checks and budget were in place, and reported four more defects, all confirmed and **fixed**. See [the report's round-2 section](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-2-fixes).

4. **A sweep between binding and the order's record sent a new checkout to review.** It now waits up to 15 minutes for the order to record its address.
5. **Expiry cancelled from an order read before the chain scan.** The order is re-read after the scan, and only a still-unpaid order awaiting payment is cancelled.
6. **Resumed scans skipped the count check.** Saved progress carries an exact running count. Any shortfall over a whole history is incomplete, and the saved progress is dropped.
7. **Confirmations stuck on outputs below the newest page.** Confirmations are recomputed from the chain tip after every scan.

**The third Codex review (19:21, narrowed) finished: NOT MERGEABLE with one MUST-CHANGE, M1, now fixed.** It found no other must-change, and the section 16 searches came back clean. See [the report's round-3 section](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-3-verdict-and-fix).

8. **M1: a transaction could be counted twice across resumed scans.** This could let a later omitted payment pass as complete. Now the saved range never shrinks, and the recent 12 blocks are recounted rather than added to.

**The fourth Codex review (M1 only) returned NOT MERGEABLE.** M1 remained through Esplora's edge carry, and the recount window added M2, a dense history that could stall for ever. **Both are now addressed by a redesign**, [report § round 4](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-4-verdict-and-redesign):

9. **Claiming a payment and cancelling an order are decided only on a fresh scan made from scratch in one call.** Coverage resumed from saved progress is advisory. If one fresh scan cannot read an expired order's history, the order goes to review. The recount window is removed.

**The fifth Codex review verified the redesign**: claims and cancellations never rest on resumed coverage. It returned NOT MERGEABLE for one existing liveness gap, **now fixed**. See [report § round 5](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-5-verdict-and-fix).

10. **An address the explorer can never list completely** (such as 51 transactions in one block) goes to review. An address unreadable for 24 hours for any other reason gets a note, and retrying continues. Each cron cycle's explorer work is bounded, and addresses are taken least recently checked first.

**The sixth Codex review** found two MUST-CHANGE: the shared cycle budget could starve other wallets and passes, and an unreadable completion in flight was never escalated. **Both are fixed.** See [report § round 6](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-6-verdict-and-fix).

11. **Per-pass budgets and stamping.** Each wallet's verification, expiry and monitoring has its own budget, and every attempt is stamped, so nothing starves. A claimed completion that can no longer be re-checked keeps its claim, and its order is told to complete it by hand. The monitor gives the same day-long notice.

12. **Expiry is round-robin per wallet.** It carries on after where it stopped, so expired orders that cannot be cancelled cannot keep an empty expired order from being reached. The seventh Codex round hit its usage limit before starting; this was found by the author while checking the questions put to it.

**The seventh Codex review** found two scheduling MUST-CHANGE: expiry's timestamps could starve verification, and failed or busy monitor attempts kept their place. **Both are fixed.** See [report § round 7](HD-PAYMENT-SAFETY-REPORT.md#independent-review-round-7-verdict-and-fix).

13. **Each pass keeps its own round-robin position per wallet.** This applies to verification, expiry and monitoring, each in its own option. Every attempt moves the position on, so no row or other pass can keep the rest from being reached.

**The eighth Codex review (28 Sep, on `e564cb9`) returned VERDICT: MERGEABLE, with no MUST-CHANGE.** It found:
- both round-7 findings resolved;
- the cursor edge cases sound: ids below the cursor, wrap-around, rows leaving and re-entering, a deleted cursor row, a budget break before the first row, multisite and uninstall;
- cross-coin scheduling free of starvation.

One SHOULD remains, listed in § 8: overlapping workers on the same wallet and pass can overwrite each other's newer position when the cron lock is unavailable. The result is kept with the other review records (`hd-round8-result.md`).

### The Autopay track and the merge

The Autopay work has its own review rounds, on `integrate/post-2.12.0`. Results are kept with the other review records (`round4-result.md` to `round7-result.md`).

- **Round 4: NOT MERGEABLE, six MUST-CHANGE.** All fixed in `593ebad`.
- **Round 5 (on `593ebad`): MERGEABLE.** Two SHOULD items and a wording note, addressed in `b2b95f0`.
- **A regression the reviews missed.** While verifying `b2b95f0`, the author ran `test-autopay-safety` under HPOS and one check failed; it failed on `593ebad` too, which had only been run under CPT. Under HPOS, WooCommerce's cancellation handling saves a second copy of the order for coupon bookkeeping, and the cancellation check refused that save. The order was still cancelled, but the customer's cancellation note was skipped and an error note added. Fixed in `889a2f4`.
- **Round 6 (on `889a2f4`): NOT MERGEABLE, one MUST-CHANGE.** That fix was too broad: once one cancelled save passed, later ones were not checked, so a stale cancellation could overwrite a payment. Fixed in `a7fb569`.
- **Round 7 (on `a7fb569`): MERGEABLE, no MUST-CHANGE.** Its two optional notes are addressed in `ae8da74`: a wording fix, and tests and mutations showing a later save still needs each lock.
- **Merge review (on `d44d252`, 30 Sep): MERGEABLE, no MUST-CHANGE and no new SHOULD.** It was a narrow review of the merge alone. It found nothing lost or duplicated, every Privacy Mode pass behind both pause checks, and no way for the Autopay cancellation check, order-event handler or migration gate to affect a Privacy Mode order. The result is in `merge-d44d252-result.md`. The round-8 SHOULD above still stands.
- **Not reviewed:** `ae8da74` and its merge `80decf4` came after these reviews. They change tests, docs and one code comment.

Codex did not re-run the database suites in any of these rounds; it read the code and the author's logs.

### Merge and release

A reviewer's verdict is not the owner's approval. On 30 September 2026 the owner merged the Autopay work (#23) and the Privacy Mode work (#24) into `master`, then the release preparation (#25), and tagged `v2.13.0`. The release workflow passed and published the GitHub release with the installable zip. Before publication, the incident's real order number and address were removed from the files, and the Privacy Mode work was published without its working history for the same reason.

Deployment remains a separate decision (`docs/HD-DEPLOYMENT.md`).

Passing mocks and a local install do not show that production is fixed. That requires a rehearsal on a copy of the store, and a small real payment per coin after deployment.
