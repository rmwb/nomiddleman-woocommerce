# HD payment safety: implementation report

Working record for the Privacy Mode (HD wallet) fix specified in `HD-PAYMENT-SAFETY-HANDOFF.md` (22 September 2026). The patch is not approved. It stops before release for independent review.

## Baseline

- The branch is `hd-payment-safety`, cut from `integrate/post-2.12.0` at `90f8787`.
- The spec's inspected baseline, `e0dda61`, is an ancestor of that branch. Between the two lie the unreleased Autopay safety work (payment leases, fenced expiry, strict consumed-history claims) and the Plugin Check work.
- Those Autopay changes touch `NMMPRO_Consumed_Repo` and `NMMPRO_Payment`, which this fix must share. Building on them avoids a second merge of the same code.
- The HD code path itself (`NMMPRO_Hd`, `NMMPRO_Hd_Repo`, the HD branch of `NMMPRO_Gateway::initialize_order_payment()`) is unchanged since `e0dda61`.
- The Autopay review continued on `integrate/post-2.12.0` after this branch was cut. Its later commits were merged in on 30 September as `d44d252`; see [Merge of the Autopay safety track](#merge-of-the-autopay-safety-track).

## Step A: reproduction

`tests/test-hd-historical.php` runs against an isolated WordPress 7.1.2 / WooCommerce 11.1.2 / MariaDB 13.0.2 / PHP 8.5 installation. It answers BlockCypher offline, with fixed UTC times, amounts, transaction ids and output indexes, and it refuses and records any request it does not recognise. Every assertion states the required behaviour.

**Incident fixture**, through the real verifier (`NMMPRO_Hd::check_all_pending_addresses_for_payment`):
- An on-hold DOGE order owes `456.80863668`.
- Its synthetic address received 2000 DOGE on 2021-05-08 (spent the same day) and 1800 DOGE on 2022-01-19. Lifetime receipts are 3800 DOGE and the current balance is 1800.
- The address was assigned on 2026-09-22T07:30:00Z. Nothing has arrived since.

**Ready-pool fixture**, through the real checkout (`NMMPRO_Gateway::initialize_order_payment`):
- The lowest ready row is index 2 of a synthetic xpub, and it received 50 DOGE in 2023.
- Index 3 onwards has no history.

**Control:** a new, confirmed, full payment made after assignment completes. This guards the incident assertions against passing only because the mock or fixture lets nothing through.

Result at `90f8787`, before any fix (raw log: harness `out-test-hd-historical-baseline.log`):

```
--- 1. historical receipts (the reported incident) ---
fixture: lifetime receipts are 3800 DOGE                               ok  koinu=380000000000
fixture: every receipt predates the assignment                         ok
the incident order is NOT marked paid                                  FAIL  status=completed
  payment_complete() never ran for it                                  FAIL  calls=1
  no payment-verified note was written                                 FAIL
  its address row is not settled or completing                         FAIL  row=complete
  the order carries no transaction id                                  ok
  no old transaction was recorded as consumed                          ok
  a repeat sweep still leaves it unpaid                                FAIL  status=completed
--- 1b. control: a real post-assignment payment ---
control: a new, confirmed, full payment completes                      ok  status=completed
control: the mock refused no request                                   ok
--- 2. stale ready-pool address at checkout ---
fixture: DOGE Privacy Mode is enabled for checkout                     ok
fixture: derived two distinct DOGE addresses                           ok
fixture: the stale address is in the ready pool                        ok
  checkout outcome: initialized; shown address: DHowNtEwyHMTW62PieLLzPvaVYDpDvxFm8
the stale address was checked on chain before exposure                 FAIL
the customer is NOT shown the stale address                            FAIL
  it is not in the order notes either                                  FAIL
  its row is not assigned to this order                                FAIL  order_id='7244'
  and it has left the ready pool for good                              FAIL  row=assigned
checkout still issues an address                                       ok  outcome=initialized
  that address was checked on chain first                              FAIL
  and has no chain history                                             FAIL
the mock refused no request                                            ok

HD-HISTORICAL CHECKS FAILED
```

What this confirms:
- **Lifetime totals authorize payment.** Receipts confirmed years before assignment completed the order, and a second sweep changed nothing.
- **Checkout trusts the ready pool.** It showed the customer an address with chain history, without making a single explorer request.

Two assertions pass today only because the current HD path records no evidence at all: no transaction id and no consumed rows. They stay as requirements for the patched evidence path.

The twelve existing database suites still pass with this suite added, and it restores the settings, orders, rows and transients it touches.

## Schema and state design

Names are provisional until Step B lands.

### Payment-address table (`nmmpro_hd_addresses`, schema 1.4 → 1.5)

| Meaning | Column | Legacy rows |
|---|---|---|
| Lifetime observed receipts (diagnostic only) | `total_received` (existing) | kept |
| Confirmed amount attributable to this order | `credited_units` varchar(40), smallest units | NULL |
| Evidence format / assignment version | `assignment_version` tinyint | NULL = unvalidated |
| Derived by the new allocator | `pool_version` tinyint | NULL: a `ready` row without it may have been issued and recycled before |
| Authoritative assignment time (database UTC clock) | `bound_at` bigint | NULL, never back-filled |
| Clean-check time and the chain height read just before binding | `validated_at` bigint, `validated_height` bigint | NULL |
| Temporary reservation | `reservation_token` char(32), `reservation_expires` bigint | NULL |
| Resumable scan state | `scan_state` text (JSON) | NULL |
| Review reason (machine-readable code) | `review_reason` varchar(48) | set by the migration policy below |

The admin explanation is derived from the reason code in code, so it is translatable and consistent.

The existing `assigned_at` stays as the display and expiry clock. The migration must not copy it into `bound_at`: an old row's assignment time is not a trustworthy baseline, and filling `bound_at` would make legacy rows look validated.

### Evidence table (`nmmpro_hd_evidence`, new, InnoDB)

- **Columns:** one row per incoming output: `hd_id`, `order_id`, `coin`, `address`, `tx_hash` varchar(255), `output_index`, `amount_units` varchar(40), `confirmations`, `block_height`, `block_time` (UTC), `source`, `observed_at`, `state`.
- **`state` values:** observed, eligible, pre_assignment, unconfirmed, conflict, credited.
- **Unique key:** `(hd_id, tx_hash, output_index)`. Overlapping pages cannot double-count.
- **Conflicts:** a duplicate with a different amount is a conflict and defers the row.
- **Why a separate table:** evidence is never truncated into a comma-separated column, and it survives an explorer that drops old transactions from its newest page.

### Ownership

- **One shared ledger:** HD reuses `nmmpro_consumed_transactions`, the identity already shared with Autopay: coin, address and transaction.
- **Grouping:** outputs are grouped per transaction before an identity is claimed.
- **New owner lookup:** returns one of five answers:
  - unclaimed;
  - owned by this order;
  - owned by another order;
  - legacy unknown owner (`order_id = 0`, from imported history), which blocks automatic reuse;
  - database error.
- **One transaction per claim:** the HD claim writes the strict consumed rows and the HD `completing` state in one InnoDB transaction. Checkout refuses to enable the new path if either table is not InnoDB.

### States

```
ready ──reserve──▶ reserved ──clean fresh check──▶ assigned (validated, bound) ──▶ underpaid ──▶ completing ──▶ complete
  │                   │  └──unknown evidence──▶ stays reserved until expiry, then ready (it was never shown)
  │                   └──chain history found──▶ retired (never reused)
  └──migration──▶ retired (legacy pool)

assigned / underpaid / completing ──order dead or expired──▶ retired   (bounded late-payment monitoring; review notes only)
legacy assigned / underpaid / completing ──migration──▶ review         (no automatic completion or expiry)
```

- **Removed:** the `quarantine → quarantine_verified → ready` recycling path. Existing quarantine rows retire.
- **Binding:**
  - an address becomes `assigned` only once the binding and the order's metadata are durable, and before it is shown;
  - the verifier ignores `reserved` rows and any `assigned` row without `assignment_version`.

### Evidence contract (all automatic HD coins)

A payment counts only when every one of these holds:
- a supported adapter reports validated incoming outputs to the destination, with exact smallest-unit amounts, a transaction hash and a stable output index (or a documented complete per-transaction sum);
- the output has at least one confirmation and at least the merchant's setting;
- its UTC block time is at or after `bound_at`;
- coverage back to `bound_at` is complete;
- its identity is unclaimed or already owned by this order.

Anything unknown, malformed, rate-limited or partial defers the order or sends it to review. It is never treated as zero, and it never falls back to lifetime totals.

### Capability outlook

Step D verifies each adapter against current provider documentation. From the code as it stands:

| Coin | Current HD source | Transaction evidence available in code | Expected outcome |
|---|---|---|---|
| DOGE | BlockCypher `/balance` (lifetime) | BlockCypher address `txrefs` (Autopay; one page, no output identity kept) | automatic, via a new paginated adapter |
| LTC | BlockCypher / litecoinspace (lifetime) | BlockCypher `txrefs` (Autopay) | automatic, same adapter |
| BTC | blockchain.info / mempool.space / Blockstream (lifetime) | mempool.space (Esplora) transactions (Autopay) | automatic, via an Esplora adapter |
| DASH | dashblockexplorer (lifetime) | dashblockexplorer transactions (Autopay) | to verify; unavailable if it lacks output identity or paging |
| QTUM | qtum.info (lifetime) | none | probably unavailable for new automatic checkout |
| BTX | chainz `getreceivedbyaddress` (lifetime) | none | probably unavailable for new automatic checkout |
| XMY | none (already unverifiable) | none | unavailable, as now |

Every reduction is reported to the owner before deployment.

## Step B: schema 1.5 and the migration gate

### What changed

- **Upgrade (`NMMPRO_Hd_Schema::upgrade`, called from `NMMPRO_update_hd_table` as 1.4 → 1.5):**
  - adds the ten nullable columns above in one `ALTER`;
  - converts a non-InnoDB HD table to InnoDB, the plugin's own table;
  - creates `nmmpro_hd_evidence` (InnoDB, unique `output_identity`) and confirms the consumed-transaction table is InnoDB;
  - applies the legacy policy.

  It re-reads each of these and returns false on anything short of complete, and the version moves only on true. A refused `ALTER`, a refused engine conversion, missing privileges or a failed policy write leaves the site at 1.4. Automatic HD stays off, and the next load retries.
- **Legacy policy (`apply_legacy_policy`):**
  - `ready` rows without `pool_version`, and all `quarantine` / `quarantine_verified` rows, become `retired`;
  - `assigned` / `underpaid` / `completing` rows without `assignment_version` become `review`;
  - `complete` and `dirty` rows are untouched.

  Nothing is back-filled and no row is deleted, and the highest derivation index is unchanged. The policy also runs at the top of every cron tick, so rows an older release writes during a downgrade are caught before any HD pass. The first run that moves rows records its counts in `nmmpro_hd_legacy_migration`, for the admin report and the Step H audit.
- **Gate (`NMMPRO_Hd::automatic_available`):** false until the schema is 1.5 and the coin is in `NMMPRO_Cryptocurrencies::$hdAutomatic`. That list replaces the old `$hdUnverifiable` denylist and is empty until Step D's adapters land. While the gate is false:
  - the coin is not offered at checkout in Privacy Mode;
  - an order placed for it earlier fails before any address is claimed or shown;
  - the cron skips its HD passes, logging this at most hourly per coin;
  - the verifier, expiry, quarantine and buffer entry points return immediately.

  Independently of the gate, `get_pending()` and `get_reconcilable()` return only validated assignments.
- **Held rows (`NMMPRO_Hd::settle_review_rows`):** a bounded, cursor-paged cron step. A held row follows the merchant's own decision on its order: `complete` once the order is paid, `retired` once it is cancelled, refunded, failed, trashed or verifiably deleted. It never changes the order, and an order it cannot read keeps the row held.
- **Wallet scoping:** every `NMMPRO_Hd_Repo` setter now also matches `mpk`, so a repository for another wallet cannot change the row.
- **Admin:**
  - a notice explains a pending schema, coins that lost automatic Privacy Mode, and the number of held orders, with what to do about each;
  - the per-coin panel wording now says why Privacy Mode is unavailable;
  - uninstall drops the evidence table and the new options, and the self-heal and Site Health checks include the evidence table.

### Consequences at this commit

- **Privacy Mode is unavailable for every coin** until Step D adds reviewed adapters. That is intended for an intermediate commit, which must not be deployed.
- **`test-hd-verify.php` fails 25 checks.** Each one needs the lifetime-total verifier to complete or expire an unvalidated row, which the gate now forbids. Step E moves its fixtures to transaction evidence; no check is deleted.
- **`test-hd-historical.php`:** the incident checks now pass, for the legacy-gate reason only. The control and checkout checks fail until Steps C–E exist.

### Evidence

- `test-hd-migration.php`: 61 checks pass under CPT and HPOS.
- **Mutation tests.** Each of these, reverted alone, fails the suite:
  - the legacy policy in the upgrade;
  - the conditional version bump;
  - the engine conversion;
  - the column check (proved with a single missing column);
  - the checkout-list gate;
  - the gateway gate;
  - the verifier gate together with the pending filter;
  - the expiry gate together with the reconcilable filter;
  - the held-row existence check;
  - setter wallet scoping;
  - the adapter allowlist.

  The two gate/filter pairs are deliberate double layers: each layer alone still protects.
- The 11 suites unrelated to HD pass.
- PHPStan (level 5), WPCS security and PHPCompatibility are clean.

## Step C: fresh assignment and permanent non-reuse

### What changed

- **Allocator (`NMMPRO_Hd_Allocator::allocate`):** checkout calls it under the per-order init lock. It replaces `claim_oldest_ready()`, which trusted the pool. For each candidate, within a budget (5 candidates, 2 inconclusive answers, 25 seconds):
  1. **Reserve.** An atomic, token-guarded reservation (`status = 'reserved'`, 120 s) takes the lowest-index row this release derived (`pool_version`), or a reservation that expired unbound. A reservation is never shown, verified or expired.
  2. **Confirm the derivation.** The row's address must equal what the configured key derives at its index. A mismatch is retired unchecked (`derivation_mismatch`).
  3. **Check on chain now.** This happens outside any database transaction, with no application cache (`NMMPRO_Hd_Evidence::address_activity`).
  4. **Act on the answer:**
     - *clean*: bind;
     - *used*: retire for good (`chain_history`) and try the next;
     - *unknown*: set aside for 5 minutes, never recorded clean.

     Two unknowns end the attempt, so an outage fails the checkout quickly.
  5. **Bind (`bind_reserved`):** only the reservation's holder can. The bind sets `assignment_version`, and sets `bound_at` and `assigned_at` from the database clock once. It then re-reads the row, and returns bound, conflict or database error. The checkout shows the address only after the bind.
  6. **Retries:** a retried checkout of the same order resumes its binding. It is re-priced if nothing has been credited, and the boundary is never replaced. A binding the order holds in another wallet was never shown, so it is retired (`superseded`). A credited one refuses the checkout instead.
- **Clean-address evidence:** DOGE via BlockCypher's address summary; the other coins come in Step D. Clean requires all of:
  - the answer is about the requested address;
  - `total_received`, `n_tx`, `unconfirmed_n_tx`, `final_n_tx` and `unconfirmed_balance` are all present and all zero.

  BlockCypher documents `total_received` and `n_tx` as confirmed-only, which is why the mempool counters are required too. A spent address with a zero balance counts as used. Anything absent, non-integer, negative or about another address is unknown.
- **Non-reuse:**
  - the quarantine pass and `recycle_quarantined` are removed;
  - an address whose order ends (deleted, cancelled, failed, refunded, expired) is retired;
  - an order that cannot be read is left for the next cycle rather than treated as deleted;
  - nothing returns an issued address to `ready`.
- **Pool:**
  - the background buffer checks each new derivation and stops a cycle at the first failure;
  - the checkout derives unchecked when the pool is dry, since the allocator checks it anyway, and absorbs a concurrent derivation of the same index;
  - checkout-page rendering no longer derives or queries anything.
- **Operational limit:** 20 consecutive derivations with chain history (filter `nmmpro_hd_max_used_skips`) suspend the coin's Privacy Mode (`allocation_limit`), with an admin notice. Re-saving the settings is the acknowledgement that lifts it. Nothing is reused to get around the limit.
- **Wallet recovery:** the Status screen shows each Privacy Mode wallet's highest issued index, highest derived index, and the issued addresses retired without payment. `docs/HD-WALLET-RECOVERY.md` explains the gap-limit consequence of non-reuse and how to restore past it. Derivation never rewinds.

### Consequences at this commit

- The gate is still closed for every coin (Step D), so none of this is reachable from a live checkout yet. The allocator suite drives it directly.
- With DOGE temporarily allowed (not committed), `test-hd-historical.php` section 2 passes through the real gateway:
  - the stale pool address is checked, retired and never shown;
  - a clean address is issued after its own check;
  - a legacy pool row with history is never shown.
- The section 2 fixture now creates the stale address as a pool row of this release, which is the incident's shape: derived clean, then funded while waiting. It falls back to the plain insert on older code. A separate case keeps the legacy-row scenario. No assertion was changed.

### Evidence

- `test-hd-allocation.php`: 65 checks under CPT and HPOS.
- **Mutation tests.** Each of these, reverted alone, fails the suite:
  - mempool counters ignored;
  - spends ignored;
  - wrong-address answers accepted;
  - no chain check;
  - unknown treated as clean;
  - no unknown budget;
  - bind without the token;
  - a zero-row bind reported as bound;
  - retire without the token;
  - legacy rows eligible;
  - live reservations stealable;
  - no derivation check;
  - no resume;
  - a credited binding discarded;
  - no used-address cap;
  - a pool-fill race failing the checkout.

  One further mutation, dropping the affected-row check, is equivalent: the confirming re-read alone still returns conflict.
- All other suites are unchanged: 13 pass, and `test-hd-verify.php` still fails the same 25 checks by design.
- PHPStan, WPCS security and PHPCompatibility are clean.

## Step D: evidence contract and coin capabilities

### Provider review

Each fact below was taken from the provider's current documentation, then confirmed against one read-only live request on 26 September 2026.

- **BlockCypher** (`api.blockcypher.com/v1/{btc,ltc,doge,dash}/main`):
  - **Endpoints:** the address summary (`/addrs/{a}/balance`) and the address history (`/addrs/{a}`).
  - **Summary counters:** `total_received` and `n_tx` count *confirmed* activity only. `unconfirmed_n_tx`, `unconfirmed_balance` and `final_n_tx` cover the mempool.
  - **History:** confirmed references are listed newest first in `txrefs`, and unconfirmed ones separately in `unconfirmed_txrefs`.
  - **Reference fields:**
    - `tx_hash`;
    - `tx_output_n` for outputs, where inputs have `tx_input_n` ≥ 0 and `tx_output_n` = −1;
    - `value` in the smallest unit;
    - `block_height`, which is −1 when unconfirmed;
    - `confirmations`;
    - `confirmed`, the ISO-8601 UTC time the transaction was included in a block, present only once confirmed;
    - `double_spend`.
  - **Paging:** by `before` (block height), with `limit` capped at 200 (50 used here) and `hasMore`.
  - **Rate limit:** 3 requests a second and **100 an hour without a token**.
- **Esplora** (mempool.space and blockstream.info for BTC, litecoinspace.org for LTC):
  - **Clean check:** `/address/{a}` returns `chain_stats` and `mempool_stats`, each with `tx_count`, `funded_txo_count/sum` and `spent_txo_count/sum`.
  - **Confirmed history:** `/address/{a}/txs/chain[/{last_txid}]` returns 25 per page, newest first.
  - **Mempool:** `/address/{a}/txs/mempool` returns at most 50 entries and does not page.
  - **Chain tip:** `/blocks/tip/height`.
  - **Transaction fields:** `vout[i].scriptpubkey_address` and `value` (satoshis), plus `status.confirmed`, `block_height` and `block_time` (the block header's time).
  - **Confirmations** are computed as tip − height + 1.

### Capability matrix

| Coin | Automatic Privacy Mode | Evidence sources (in order) | Basis |
|---|---|---|---|
| BTC | **yes** | mempool.space, then blockstream.info (Esplora) | per-output identity, amounts in satoshis, tip-based confirmations, block time, txid paging, capped mempool detected |
| LTC | **yes** | litecoinspace.org (Esplora), then BlockCypher | as BTC; BlockCypher fallback added because litecoinspace answered in 7–25 s or timed out during this review |
| DOGE | **yes** | BlockCypher | `txrefs` output identity, explorer confirmations and confirmed time, height paging |
| DASH | **yes** | BlockCypher | as DOGE. Replaces the Insight (`insight.dash.org`) lifetime total, whose paging and field semantics could not be confirmed from maintained documentation |
| QTUM | **no** | none | only a lifetime total from qtum.info was in use; no per-output, paginated adapter was reviewed |
| BTX | **no** | none | chainz only offers `getreceivedbyaddress`, a lifetime total with no transaction list or confirmations |
| XMY | **no** (as before) | none | no working public API |

**Capability reductions for the owner:** QTUM and BTX lose automatic Privacy Mode:
- they will not be offered in Privacy Mode at checkout;
- existing Privacy Mode orders for them are held for manual review;
- Classic Mode is unaffected.

The matrix is derived from the coin registry, so a coin added to the registry with Privacy Mode support but no reviewed adapter is unavailable by default.

**Operational note:** DOGE and DASH depend on BlockCypher alone, and LTC falls back to it. Without an API token BlockCypher allows 100 requests an hour. Every allocation costs at least two requests (the clean check and the chain height), and every verifier scan at least one, so stores using Privacy Mode for these coins should set the BlockCypher token in the plugin settings. Without one, rate-limit answers are treated as unknown: checkouts fail with "try again", and verification waits.

### What changed

- **`NMMPRO_Hd_Evidence`:**
  - **`scan()`** returns every incoming output from the tip, and the mempool, down to the assignment boundary minus a 3-hour block-time slack. It states the coverage (complete or incomplete) and the reason, the tip height and a resumable state.
  - **Output format:** each output is normalised to `tx_hash`, `output_index`, `amount_units` (an exact decimal string), `confirmed`, `confirmations`, `block_height` and `block_time`. An unconfirmed output has no time: the fetch time is never substituted.
  - **Duplicates:** identical repeats merge by output identity. A disagreeing repeat or a reported double spend stops the scan.
  - **Paging:**
    - a BlockCypher page that ends part-way through a block is re-entered at that height;
    - a full page at one height, a repeated cursor, a full mempool list or an exhausted page budget each makes coverage incomplete;
    - resumable state (`to`, `floor`, `cursor`, `source`) lets a busy address converge, and a later scan re-joins the covered range from one page;
    - an inconsistent saved state is discarded.
  - **Sources:** only an unreachable source hands over to the next reviewed one. Malformed data or a conflict is final for that call. Saved progress belongs to one source, and a total outage returns it unchanged.
- **Clean-address check:** now covers BTC and LTC through Esplora stats as well as DOGE and DASH through BlockCypher, with the same source order. The first definite answer wins.
- **Confirmations:** the minimum for Privacy Mode is now 1, and a legacy saved 0 is read as 1. The settings field explains why: a payment is attributed to its order by the time of the block that confirmed it.
- **Live smoke checks:** added to `tests/smoke-explorers.php` for all four adapters, as a separate integration check. They passed on 26 September 2026:
  - **BTC:** 52 outputs with the page limit reached on a busy address.
  - **LTC:** 3 outputs, matching the address's `funded_txo_count` and the value fetched by hand.
  - **DOGE and DASH:** complete.

  The old lifetime-total LTC smoke check failed at the same time because litecoinspace was timing out. That path is removed in Step E.

### Consequences at this commit

- The gate (`NMMPRO_Cryptocurrencies::$hdAutomatic`) is still empty. It switches to this matrix in Step E, at the same time as the verifier stops using lifetime totals, so no intermediate commit can pay an order from a lifetime total.
- The Autopay adapters and `NMMPRO_Transaction` are unchanged.

### Evidence

- `test-hd-evidence.php`: 69 checks.
- **Mutation tests.** Each of these, reverted alone, fails the suite:
  - same-height re-entry;
  - stuck-page detection;
  - identity merging;
  - conflicts;
  - outputs collapsed per transaction;
  - spends counted as receipts;
  - double spends;
  - substituted fetch time;
  - time-format checks;
  - implausible times;
  - fractional amounts;
  - the floor stop;
  - budget exhaustion reported as complete;
  - resume;
  - overlap;
  - the full-mempool check;
  - the missing tip;
  - the Esplora destination check;
  - a repeated cursor;
  - wrong-address pages;
  - inconsistent state;
  - QTUM given an adapter;
  - fallback on any incomplete answer;
  - an outage discarding progress;
  - progress not bound to its source.

  In the first run, four of these survived because a later check or the page budget still produced "incomplete". The checks now assert the specific reason each safeguard reports.
- `test-hd-allocation.php` still passes all 65 checks.
- The offline settings-bounds test covers the new confirmation floor.
- PHPStan, WPCS security and PHPCompatibility are clean. PHPCompatibility caught a `trim()` without explicit characters, which PHP 8.6 treats differently; it is fixed.
- **Step C regression, fixed here.** The offline `test-dashboard-escaping.php` crashed from Step C on: the Status screen's new wallet-recovery section uses `NMMPRO_Hd_Schema` and `NMMPRO_Hd_Repo`, which that stand-alone test did not load. CI runs this test and would have failed. Step C's evidence covered the database suites only, which is how it was missed.
  - The test now loads both classes and renders the new section.
  - It checks the scan range is shown, escaped, and without the master public key.
  - From now on, every step runs the offline suites too.

## Step E: eligible payments (with Step F's claim and recovery)

Completing an order needs its payment ownership claimed first (Step E, item 7), so Step F's ownership-aware claim and crash recovery landed here. The gate opens in this commit for BTC, LTC, DOGE and DASH, the coins with a reviewed adapter. The lifetime-total verifier is deleted in the same commit, so no commit pays from lifetime totals.

### Verification (`NMMPRO_Hd_Verifier`)

For each open, validated assignment, under the per-address match lock shared with Autopay (the address waits if the lock is unavailable):

1. **Order and binding.** The order is read past WooCommerce's caches (`NMMPRO_Payment::read_order_authoritatively`, now public). "Unreadable" is never treated as "deleted". The order must still carry this gateway, this coin and this address; if not, the row goes to review (`binding_mismatch`). The checkout writes the binding to the row before the order gets its address, so an order is never completed between the two writes.
2. **Scan and record.** Every incoming output since the binding is scanned and recorded in `nmmpro_hd_evidence`. The record includes when the plugin first saw the output and whether it was unconfirmed then. Resumable scan state is saved. Incomplete coverage stops here, for completion and expiry alike. A disagreeing duplicate goes to review (`evidence_conflict`).
3. **Eligibility (`evaluate()`, exact and pure).** An output counts when both of these hold:
   - **it arrived after the binding**: its block time is at or after `bound_at`, or the plugin first saw it unconfirmed after the address was proven clean (so it cannot have existed at assignment);
   - **it is confirmed enough**: at least the merchant's confirmation count, and never fewer than one.

   The other outcomes:

   | Output | Outcome |
   |---|---|
   | Confirmed more than 3 hours before the binding, in a block at or below the binding height | **historical**: contributes exactly zero, with one note to the merchant |
   | Confirmed within the 3 hours before the binding, or stamped before it in a block above the binding height | **ambiguous**: review (`timestamp_ambiguous`) |
   | Owned by another order, including Autopay | excluded |
   | Owned by an unknown earlier owner (imported history) | review (`legacy_owner`) |
   | Vanished (left the chain) | counts for nothing |
4. **Amounts.** The attributable amount is summed exactly and recorded (`credited_units`). The lifetime total is recorded only as a diagnostic. Below the threshold, the order is marked underpaid and the customer is told the credited amount and the remainder, once per increase.
5. **Re-validation.** On clearing the threshold, a fresh full scan re-validates every output. Outputs no longer reported are marked vanished, and the decision is made again from the fresh evidence.
6. **The claim (`NMMPRO_Consumed_Repo::claim_hd`).** One InnoDB transaction does three things:
   - it writes the strict consumed rows (the ledger shared with Autopay), keeping identities this order already owns;
   - it marks the contributing evidence credited;
   - it moves the row to `completing`.

   Any failure rolls all of it back. MySQL reports only changed rows, so a same-second resume of this order's own claim is confirmed by re-reading the row under its lock.
7. **Completion.** The order is re-read, and completed only if it still awaits payment. `payment_complete()` receives the earliest contributing transaction's hash. The order must then actually be paid. All contributing outputs are kept in `_nmmpro_hd_transactions`. The note states:
   - the amount;
   - the outputs;
   - the minimum confirmations;
   - the last block time (UTC);
   - the verification time, separately from the block time.

### Crash points

| Point | Behaviour |
|---|---|
| Before the claim commits | Nothing is paid and no ownership is recorded (the rollback is tested). |
| After the claim, before WooCommerce | The row stays `completing` and the funds stay owned by this order. The next cycle completes the same order once. Expiry never touches it. |
| After WooCommerce, before the row settles | The next cycle sees a paid order and settles the row: no second `payment_complete()` and no second note. |
| Hook throws, `false`, or "true" without a transition | The claim and ownership are kept for a retry. No success note is written. |
| Order cancelled mid-claim (by another request) | It is not completed. The funds stay recorded against it, and the late payment is noted for the merchant. |

### Expiry

An expired order is cancelled only on a **complete** scan that shows nothing arrived since the binding: no confirmed output, no pending one, of any amount. When nothing proves that:
- an incomplete scan, a failed request or a busy address never cancels;
- an underpaid expired order is held for review (`expired_underpaid`);
- a `completing` row is never touched.

Receipts from well before the binding do not keep an order alive. The verifier's complete scan from the same cycle is reused, so it costs one request per address per cycle. Ended orders retire their addresses.

### Also

- **Removed:** the lifetime-total verifier and expiry, the per-cycle balance observations, and the nine `NMMPRO_Blockchain` lifetime fetchers (blockchain.info, mempool.space, Blockstream, BlockCypher LTC/DOGE, litecoinspace, qtum.info, Insight, chainz). Every remaining `payment_complete()` on the HD path is in `NMMPRO_Hd_Verifier::complete()`.
- **Customer display:** the order-status poll reports the credited amount, not the address's lifetime receipts.
- **Evidence table:** schema 1.5 gains `first_seen_at` and `first_seen_pending`. It is unreleased, so the definition changed in place, and the upgrade now also verifies these columns.
- **Held rows:** settling them uses the same cache-bypassing order read.
- **Not yet done:** after an order is paid, its address is no longer rescanned, so contradictory evidence arriving later is not flagged automatically. The Step H audit reports previously paid orders with absent or contradictory evidence.

### Evidence

- **`test-hd-verify.php`, rewritten:** 83 checks under CPT and HPOS, run twice back to back in each. Every safety property of the old suite is kept on transaction evidence:
  - no resurrection of cancelled, failed or refunded orders;
  - a deleted order does not abort the sweep;
  - a custom payable status is not completed by a lying return value;
  - a failed hook keeps the claim and is not cancelled;
  - interrupted completions resume;
  - expiry never cancels over funds.

  It also covers the matrix groups listed in `tests/README.md`.
- **`test-hd-historical.php` passes in full** and is now in CI.
  - The incident order stays unpaid through attribution: its fixture is a properly validated assignment, not the legacy gate.
  - The control payment completes.
- **Mutation tests.** 19 of 21 fail the suite when reverted alone:
  - historical receipts counted;
  - ambiguous receipts counted;
  - the first-seen-in-mempool rule dropped;
  - confirmations ignored;
  - another order's transactions counted;
  - an unknown owner treated as unclaimed;
  - no fresh re-validation;
  - no binding check;
  - a claim that records no ownership;
  - `payment_complete()` trusted;
  - no re-read before completing;
  - the same-second resume not re-read;
  - expiry trusting an incomplete scan;
  - expiry cancelling underpaid orders;
  - notes not deduplicated;
  - vanished outputs not marked;
  - dead orders completed;
  - the threshold on the lifetime total (both checks);
  - no address lock.

  The two that are not caught alone are deliberate double layers:
  - expiry's pending flag duplicates the pending evidence rows from the same scan;
  - expiry's `completing` skip is backed by its credited-amount check, and a claimed row always has a credited amount.
- **Two defects found by the tests and fixed:**
  - the same-second resume, described above;
  - the historical suite never cleared its synthetic ledger entries, so a previous run's control transaction, still owned by a deleted order, correctly blocked the next run. Both suites now clear their synthetic entries at start and end.
- **Everything else:**
  - all 16 database suites pass;
  - the offline suites pass (the dashboard test now loads `NMMPRO_Hd_Evidence`, which its "Privacy verifiable" column needs);
  - PHPStan, WPCS security and PHPCompatibility are clean. Two stale PHPStan baseline entries for code deleted from `NMMPRO_Hd.php` were removed; no entry was added.
- **Plugin Check** on the release payload first reported **17 errors**, all `WordPress.DB.PreparedSQL.NotPrepared`: values concatenated into the SQL passed to `prepare()`.
  - The evidence INSERT and UPDATE are now two literal statements each, confirmed and unconfirmed, because `prepare()` cannot bind `NULL`.
  - Two queries now name their columns and table literally.
  - The re-scan reports 0 errors and 544 warnings, in the same categories as before. The triage document is regenerated at the final checkpoint.

## Step G: expiry, UI and operational reporting

Several Step G items landed with Step E, because opening the gate required them:
- evidence-based expiry, with an underpaid expired order held for review;
- the customer's underpayment display from the attributable amount;
- separate diagnostic and attributable totals;
- a per-cycle scan cache reset by the cron.

This step adds the rest.

### What changed

- **Late-payment monitor (`NMMPRO_Hd_Verifier::monitor_wallet`, every cron cycle).**
  - **What it watches:** retired and held addresses, validated and bound in the last 90 days. It checks at most 25 a cycle, each at most every 6 hours, least recently checked first, under the shared address lock. A busy address keeps its turn; a failed check still spaces the next one.
  - **What it reports:** it records evidence and tells the merchant, once per transaction, about payments that arrived after the binding.
    - Pending and confirmed sightings are reported separately.
    - It reports only what it first saw after monitoring began, so the part payment that got an order held is not reported again.
    - Receipts from before the binding are never reported.
  - **What it never does:** complete, cancel or claim anything. Late funds are for the merchant to reconcile, and they cannot pay this or any other order.
  - Legacy held rows have no trustworthy boundary, so they are left to the Step H audit.
- **Order screen:** a read-only "Privacy Mode payment" panel on legacy and HPOS order screens. It shows:
  - the state (awaiting, partly paid, completing, paid, held for review, retired);
  - the reason, in words (`NMMPRO_Hd::reason_label`, covering every stored code);
  - the amount due and the amount received for this order;
  - the issue and last-check times (UTC);
  - the contributing outputs.

  It is escaped, and it is only shown for orders with a Privacy Mode address on schema 1.5.
- **Notes:** every Privacy Mode note is deduplicated through order meta. The success note keeps transaction time and verification time separate. The historical-receipt note is merchant-only and concise.
- **Log hygiene:**
  - `create_hd_address()` embedded `getTraceAsString()` in its error. A trace carries the call arguments, which include the master public key, and the message reached logs. It now carries only the error message.
  - Explorer URLs were already logged through `redact_url()`, which strips API tokens.
  - No new log line includes a key, a token, an email address or a full HTTP response.
- **Pre-existing robustness bug, fixed:** the math backends reject a malformed key with a `ValueError` (an `\Error`). `create_hd_address()` and the background buffer caught only `\Exception`, so one mis-entered key aborted the entire cron cycle for every coin, Autopay included. `create_hd_address()` now converts any `\Throwable` into a plain `\Exception`.

### Evidence

- `test-hd-monitor.php`: 32 checks under CPT and HPOS.
- **Mutation tests.** All 13 fail the suite when reverted alone:
  - pending and confirmed sharing one report;
  - already-known evidence re-reported;
  - pre-binding receipts reported;
  - reports repeated every check;
  - no check interval;
  - no 90-day window;
  - no batch limit;
  - failures not spacing retries;
  - busy addresses not skipped;
  - the monitor claiming the late payment;
  - the panel printing the address raw;
  - the key error carrying a trace;
  - the malformed-key `\Error` escaping.
- **Mutations I had to redo.** In a first run, three results were not trustworthy:
  - the batch-limit mutation produced invalid SQL, so it failed for the wrong reason;
  - the interval mutation was not equivalent within one second;
  - a leftover ledger entry from the "monitor claims" mutation made two later mutations fail on the leftover rather than their own checks.

  The suite now clears its ledger entries at start and end, and all three were re-run properly.
- Every other suite passes: 17 database suites, the offline suites, and PHPStan, WPCS security and PHPCompatibility.
- The order panel was verified by rendering it in the test suite. The isolated harness has no web server, so it was not viewed in a browser.

## Step H: safe upgrades and incident audit

### What changed

- **Migration policy** (from Step B, now reported):
  - legacy pool and quarantine rows are retired;
  - unvalidated in-flight assignments are held for review;
  - paid and dirty rows are audit-only;
  - nothing is back-filled, deleted or rewound.

  The upgrade's counts are stored once (`nmmpro_hd_legacy_migration`) and printed by the audit, so they can be reported before deployment. The runbook produces them on an isolated copy.
- **`wp nmmpro-hd audit` (`NMMPRO_Hd_Audit`, `NMMPRO_Hd_Cli`):**
  - **Read-only.** It writes nothing to the plugin's tables, options or orders; the test compares checksums before and after.
  - **Bounded and resumable:** `--limit`, `--after` cursor, `--max-scans`.
  - **Scopes:** `--order`, `--address` or `--coin`.
  - **Formats:** table, CSV or JSON.
  - **Summary:**
    - plugin and schema versions;
    - the upgrade's counts;
    - per-coin capability and whether a configured coin is unavailable;
    - each wallet's derivation range;
    - record counts by state and reason.
  - **Per record:**
    - coin, address and index;
    - order and its status, read through the WooCommerce API (HPOS or legacy);
    - prior orders from the legacy `all_order_ids`;
    - expected, credited and lifetime amounts (lifetime is diagnostic only);
    - assignment and binding times;
    - state and reason;
    - findings.
  - **Chain checks (`--chain`)** read the full history read-only. For legacy rows they use the order's creation time as the boundary: a receipt confirmed before its order existed cannot be for it. That separates `false_positive_confirmed` (every receipt predates the order) from `paid_evidence_absent` and `paid_underfunded`. They also report `credited_evidence_missing` for validated paid orders, closing the Step E gap on contradictory evidence after payment, and `unpaid_but_funded` and `pool_row_has_history`.
  - **Explorer failures** are `chain_unknown` and never zero. Records beyond the budget are `chain_skipped`.
  - **Customer details** are never printed; the test checks for the email and name.
  - **Incident order:** it can be inspected with `--order=<id>` on the store. The patch does not fetch the live store.
- **`wp nmmpro-hd reconcile` (`NMMPRO_Hd_Reconcile`):**
  - a dry run by default;
  - with `--yes`, records the order as the owner of operator-verified transactions in the shared ledger (`NMMPRO_Consumed_Repo::record_manual`, one transaction, strict inserts);
  - refuses a transaction owned by another order or an unknown earlier owner;
  - with `--complete`, completes the order only if it still awaits payment, and checks it is actually paid afterwards;
  - notes the order, settles the address record, and never returns an address to the pool.
- **Operator documentation:**
  - `docs/HD-RECONCILIATION.md`: the audit, the finding legend, and the manual workflow (verify chain evidence and wallet ownership, check fulfilment, decide, record ownership before acknowledging);
  - `docs/HD-DEPLOYMENT.md`: what the upgrade does, prerequisites, the runbook and the rollback rules;
  - a Privacy Mode section in `docs/DOWNGRADE.md`;
  - `README.txt`: FAQ entries for held orders and the gap limit, the changelog, and a corrected External Services list. chainz and qtum.info are no longer contacted; BlockCypher now also serves Dash, plus LTC as a fallback, with a token recommendation. The list also discloses the checkout-time clean check and the 90-day late-payment monitoring.
- **Toolchain:** PHPStan now scans `php-stubs/wp-cli-stubs` 2.12.0, pinned in CI like the other stubs, for the new commands. Exception messages that carry values are escaped, as WPCS requires.

### Example report (sanitized)

This is the real `wp nmmpro-hd audit --coin=DOGE --chain --findings-only` output, run against the synthetic fixtures of `test-hd-audit.php` and an offline explorer. Addresses and order numbers are synthetic; the order owing 10 DOGE with 3800 DOGE of pre-order receipts is the incident's shape. Tables print tab-separated when not attached to a terminal.

```
Plugin 2.12.0; Privacy Mode schema 1.5 (ready)
  BTC   automatic: yes (mempool.space, then blockstream.info)
  LTC   automatic: yes (litecoinspace.org, then blockcypher)
  QTUM  automatic: no  The only source in use (qtum.info) was used for lifetime totals; no reviewed per-output, paginated evidence adapter exists.
  DASH  automatic: yes (blockcypher)
  DOGE  automatic: yes (blockcypher)
  XMY   automatic: no  No working public explorer API remains for Myriad.
  BTX   automatic: no  The only source (chainz getreceivedbyaddress) reports a lifetime total and cannot list transactions or confirmations.
  DOGE  complete                          8
  DOGE  ready                             1
  DOGE  review     legacy_unvalidated     3

row	coin	address	order	order_status	expected	credited	lifetime	state	reason	findings
5050	DOGE	hau_incident	11414	processing	10		3800	complete		missing_provenance false_positive_confirmed
5051	DOGE	hau_absent	11415	processing	10		0	complete		missing_provenance paid_evidence_absent
5052	DOGE	hau_under	11416	completed	10		0	complete		missing_provenance paid_underfunded
5054	DOGE	hau_vanished	11418	processing	10	10	0	complete		credited_evidence_missing
5055	DOGE	hau_funded	11419	on-hold	10		0	review	legacy_unvalidated	missing_provenance unpaid_but_funded
5056	DOGE	hau_reused	11420	on-hold	10		0	review	legacy_unvalidated	missing_provenance reused_address
5057	DOGE	hau_pool			10		0	ready		pool_row_has_history
5058	DOGE	hau_ghost	11421	(absent)	10		0	review	legacy_unvalidated	missing_provenance order_missing chain_unknown
5059	DOGE	hau_disagree	11422	on-hold	10		0	complete		missing_provenance row_order_disagree
5060	DOGE	hau_noev	11423	processing	10		0	complete		paid_no_evidence
5061	DOGE	hau_down	11424	processing	10		0	complete		missing_provenance chain_unknown
  missing_provenance         Assigned by a release that judged payments by lifetime totals; no validated binding.
  reused_address             The record lists more than one order: the address was issued again after an earlier order.
  order_missing              The order this address was issued to no longer exists.
  row_order_disagree         The address record and the order disagree about whether it was paid.
  paid_no_evidence           The order is paid but no credited transaction was recorded for it.
  false_positive_confirmed   The order is paid, but every receipt at its address was confirmed before the order existed: a confirmed false positive.
  paid_evidence_absent       The order is paid, but the address has received nothing at all.
  paid_underfunded           The order is paid, but less than the amount due arrived after the order existed.
  credited_evidence_missing  A transaction credited to this order is no longer reported on chain.
  unpaid_but_funded          The order is not paid, but at least the amount due arrived after the order existed.
  pool_row_has_history       An unissued pool address already has chain history.
  chain_unknown              The chain could not be read completely (provider failure or incomplete answer): unknown, not zero.
```

### Evidence

- `test-hd-audit.php`: 36 checks under CPT and HPOS, including the real `wp nmmpro-hd` commands run in-process.
- **Mutation tests.** 12 of 13 fail the suite when reverted alone:
  - the audit recording evidence;
  - false positives not told apart from absent evidence;
  - the budget ignored;
  - an incomplete read reported as known;
  - reused addresses, vanished credits or funded unpaid orders not detected;
  - the paging cursor skipping a record;
  - customer details in the report;
  - reconcile recording no ownership;
  - reconcile completing a cancelled order;
  - a dry run applying changes.

  The one not caught alone, reconcile's own refusal of another order's transaction, is a triple layer: `record_manual()` refuses too, and the ledger's strict INSERT fails on an owned identity underneath both.
- Every other suite passes: 18 database suites and the offline suites. PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 1: fixes

The first Codex review stopped at its usage limit, but reported three defects first. All three were confirmed against the code and are fixed here.

### 1. A degraded explorer answer read as "nothing received"

BlockCypher omits empty lists, and the scanner did not compare a listing with the address's own transaction counters. An answer naming only the address therefore read as a complete, empty history, and expiry could cancel an order that was paid. Esplora had the same gap: an empty `[]` list was accepted as complete.

**Fix (`NMMPRO_Hd_Evidence`).** Every listing is checked against the explorer's counters, and any shortfall is incomplete, never empty:
- **BlockCypher:** every page must carry `n_tx` and `unconfirmed_n_tx`. The first page must list exactly the pending transactions it counts and, when it is also the last page, exactly the confirmed ones. A continuation page always repeats the height it continues from, so an empty one is truncated. BlockCypher's counters were verified live on DOGE, LTC and DASH (distinct hashes equal `n_tx` on a last page).
- **Esplora:** each scan first reads `/address` (`chain_stats.tx_count`, `mempool_stats.tx_count`). The mempool list must be at least as long as the mempool count, and an empty first chain page is refused when the address reports confirmed transactions.
- **Whole history:** a history read from top to bottom in one call must list at least as many transactions as the address reports. More are allowed (a block mined between requests); fewer are not.

### 2. Re-validation restarted from page one within the normal budget

The fresh re-read before a claim used the normal five-page budget and had no saved progress. An address with more history since its binding (a flood of dust, say) never completed and was never flagged.

**Fix (`NMMPRO_Hd_Verifier`).** Re-validation has its own budget, `REVALIDATE_MAX_PAGES` (20 pages: about 1,000 BlockCypher references or 500 Esplora transactions). A re-validation that runs out of pages holds the order for review (`history_too_large`), with a note, instead of retrying for ever. Nothing is claimed on a partial read.

### 3. Scans stopped on the first old block time

Block times are set by miners and are not monotonic. A later block carrying an early timestamp could end the scan above an older block that held the payment. The same timestamps also decided eligibility and expiry.

**Fix.** Anchor on block height, recorded at binding:
- **At binding:** the allocator reads the chain height, `NMMPRO_Hd_Evidence::chain_tip()`, after the clean check and before binding, and stores it in `validated_height`. If the height cannot be read, the candidate is set aside like any inconclusive check and is never bound without it.
- **Scans stop by height:** they read down to `validated_height − 100` (`HEIGHT_MARGIN`, which covers a reorganisation) instead of by time. A recorded height above the chain tip the explorer reports is not trusted: the scan is incomplete.
- **One timing rule** (`NMMPRO_Hd_Verifier::timing()`) is shared by eligibility, expiry and the late-payment monitor. An output in a block above `validated_height` is never historical. If its time says it came before the binding, it is ambiguous and goes to review. So expiry never cancels over it, and it never pays automatically. Old receipts, deep below the binding height, remain historical.
- **Records without a height** (bound before this change; none exist in production) keep the time rules, and the monitor keeps the time floor for them.

### Evidence

- **New checks:**
  - `test-hd-evidence` §8–10: 24 checks covering counter mismatches (bare, empty and truncated answers), resumed scans, the height floor, a floor above the tip, chain-height reads and the `exhausted` flag.
  - `test-hd-verify` §17: 18 checks. A degraded answer never cancels, and the order completes once the explorer answers properly. Six pages of history complete; a flooded address goes to review. An early-stamped block does not end the scan, with a control showing that the time floor alone misses the payment. An early-stamped payment above the binding height goes to review and blocks expiry, while deep old receipts still do not count. The timing rule is also tested directly.
  - `test-hd-allocation`: 4 checks. The chain height is recorded at binding; if it is unreadable, no address is bound.
- **Fakes:** the fakes in five suites now return the explorers' counters and paging exactly as the live APIs do, and the verify and incident suites bind with a realistic chain height.
- **Mutation tests:** 14 of 14 fail a suite when reverted alone. They cover each counter check (BlockCypher and Esplora), the re-validation budget, the review hold, the height floor in the verifier and in the scanner, the height rule in `timing()`, the floor-above-tip guard, and binding with no height or an unreadable one. The first run left the Esplora empty-first-page check uncaught, because the whole-history count also caught it; a resumed-scan check now covers it.
- **Results:** all seven Privacy Mode suites pass (CPT, REPEATABLE READ). PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 2: fixes

The second Codex review also stopped at its usage limit before a verdict. It said the round-1 counter checks and re-validation budget were in place. Before stopping, it reported four more defects. All four were confirmed and are fixed here.

### 1. A sweep between binding and the order's own record sent a new checkout to review

The checkout binds the address (`NMMPRO_Gateway.php:514`), then records it on the order (`:568`). A cron verify in between found the order not matching its address and held it for review. The verifier's own comment says such a row should just wait.

**Fix.** While the order has no `wallet_address` yet, the row is left alone for up to `BINDING_GRACE_SEC` (15 minutes) after binding. After that, the checkout evidently failed after binding, and the row goes to review (`binding_mismatch`) as before. A different recorded address, gateway or coin still goes to review at once.

### 2. Expiry cancelled from an order read before the chain scan

`expire_row()` read the order, scanned the chain (network time), then cancelled the object it had read before the scan. An order paid in the meantime could be cancelled.

**Fix.** After the scan, and before retiring the row or cancelling, the order is read again past every cache (`read_order_authoritatively`). The cancellation proceeds only if that fresh copy is still unpaid and awaiting payment, and it is applied to the fresh copy. The instant between that read and WooCommerce's own save cannot be closed. It is the same limit documented for Autopay (WooCommerce has no conditional save).

### 3. Resumed scans skipped the count check

The round-1 count check ran only when one call read the whole history. A scan resuming from saved progress accepted a cut-short page that still listed something.

**Fix.** Saved progress now carries an exact running count of distinct confirmed transactions:
- **`n`:** the count so far, excluding the edge (the lowest height reached, which a page may have cut part-way);
- **`n_edge`:** the count at the edge;
- **`whole`:** whether the covered range reaches the end of history.

The explorers differ at the edge:
- BlockCypher's continuation re-reads the edge height in full, so the edge count is replaced.
- Esplora's continues after the last transaction read, so the edge count is carried.

A scan that joins its saved range counts only the heights above it. Whenever the covered range is the whole history, the count must reach the explorer's counter. A shortfall is incomplete, **and the saved progress is dropped**, so the next scan starts afresh instead of repeating the shortfall. The same applies after a reorganisation that replaced transactions at already-read heights. Progress saved before counting existed is not trusted: that record's next scan starts afresh.

### 4. Confirmations stuck on outputs below the newest page

Evidence stored the confirmation count seen when an output was last read. A resumed scan re-reads only the newest part of the history, so an older output never reached the merchant's requirement, and the order never completed.

**Fix.** Every scan reports the chain tip (BlockCypher: height + confirmations − 1 of its newest references; Esplora: `/blocks/tip/height`). After each scan, the verifier sets every confirmed output's count to `tip − block_height + 1` (`NMMPRO_Hd_Evidence_Repo::refresh_confirmations`). Whether the output is still on chain is checked by the full re-validation before any claim.

### Evidence

- **New checks:**
  - `test-hd-evidence` §11–12: 12 checks. A resumed scan cut short, a single missing transaction, a joined scan hiding new transactions, the dropped progress and its recovery, and untrusted old progress. Exact counting converges for BlockCypher and Esplora with blocks cut across pages, a block spanning several pages, and new blocks arriving between scans. BlockCypher reports the tip.
  - `test-hd-verify` §18: 8 checks. A sweep before the order records its address, and after the grace period. An order paid while expiry reads the chain, with a control that is still cancelled. A payment below the newest page reaching 35 confirmations thirty blocks later.
- **Fixture corrected:** `test-hd-monitor` had added a new confirmed transaction to an already-read block, which cannot happen on a real chain. The count check flagged it as a shortfall, which is the intended behaviour. The fixture now puts it in a newer block.
- **Mutation tests:** 10 of 10 fail a suite when reverted alone. They cover the grace period, the expiry re-read, each count path (continuation, joined finished range, Esplora edge carry, edge overcount, untrusted old progress, dropped progress on mismatch), the confirmation refresh and BlockCypher's tip. Three survived the first run and were caught once sharper tests were added: the joined range, the edge overcount and the old progress.
- **Results:** all seven Privacy Mode suites pass. PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 3: verdict and fix

The third Codex review, deliberately narrowed, finished. **Verdict: NOT MERGEABLE, one MUST-CHANGE (M1).** The other findings:
- **Resolved:** R2, R3, Q1 and Q2.
- **Resolved for the reported defect:** R1 and Q4.
- **Partial:** Q3, which is M1.
- **Section 16 searches, clean:**
  - `payment_complete()` is reached only through the verifier (after the fresh assessment and `claim_hd`) and the explicit reconcile command.
  - The only write that makes an address `ready` is the fresh pool insert.
  - `total_received` feeds only the allocation classification, the audit display and diagnostics.
- **No other must-change** in the allocator, `claim_hd` or the schema upgrade.

### M1: a transaction could be counted twice across resumed scans

A resumed scan counted everything above the saved top height as new, and the saved top could move down. If an explorer left out the newest transaction once and listed it again later, that transaction was counted twice. The inflated count could then hide a later omission (a payment), so the scan reported complete coverage and expiry could cancel a paid order. Codex reproduced it in four scans. It also noted that keeping the top from moving down would not by itself make the count safe across a reorganisation, which can re-mine a counted transaction in a later block.

**Fix (`NMMPRO_Hd_Evidence`):**
- **The saved range never shrinks.** If a joined scan's newest transaction is older than the saved top, the explorer has contradicted itself (or the chain reorganised). The scan is incomplete and the saved progress is dropped.
- **Recent blocks are recounted, not added to.** Saved progress keeps the transactions per block for its top `RECOUNT_WINDOW` (12) blocks. A resumed scan re-reads that window in full, and its fresh count *replaces* the saved one. The count kept for deeper blocks stands, because a reorganisation cannot reach them. A transaction left out, re-mined higher or dropped is therefore recounted, never counted twice.
- **Pages are joined by transaction id before counting.** Consecutive BlockCypher pages repeat the block between them.
- **Saved progress whose recent counts exceed its total** is corrupt, and is discarded.

The window is 12 blocks, not 100. With 100, re-reading the window used up a busy address's page budget on every scan, so scans never converged. The convergence tests caught this.

### Evidence

- **New checks:** `test-hd-evidence` §13, 8 checks.
  - The reviewer's four-scan sequence: no inflation, the later payment is caught, and a control.
  - A two-page history whose newest transaction goes missing, then returns while a later payment is left out.
  - A transaction re-mined higher, counted once; and one re-mined while another is left out.
  - Corrupt saved counts.
- **A bug in this fix, caught before commit:** the first version summed continuation pages' per-block counts, so BlockCypher's repeated boundary block was counted twice. The single-omission test caught it (399 against 400 counted as 404). It now joins by transaction id.
- **Mutation tests:** 6 of 6 fail a suite when reverted alone: the restart on an older top, recount-not-add, a whole kept count, summed pages, a zero window and the corrupt-state check. The corrupt-state check survived the first run; the test above was added for it.
- **Results:** all seven Privacy Mode suites pass. PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 4: verdict and redesign

The fourth Codex review (M1 only) returned **NOT MERGEABLE with two MUST-CHANGE findings**. It confirmed that the lowered-top sequence was fixed and that the saved counts survive the JSON round-trip.

- **M1 remained.** Esplora carries a count for the edge height (where a page cut a block), and it was not deduplicated against the 12-block recount. A shallow reorganisation that re-mined an edge transaction counted 126 transactions as 127, which could hide one omitted payment. Expiry trusted resumed coverage, so it could cancel a paid order.
- **M2, new, introduced by the recount window.** On a dense history (25 transactions to a block), re-reading the window used up the whole page budget, so every scan saved the same state and the order stayed incomplete for ever, with no review hold.

Codex's advice was to require a fresh, txid-deduplicated scan before expiry, with a review hold when it cannot finish. A larger window would fix neither problem.

### Redesign: irreversible decisions never rest on resumed coverage

Scans resume from saved progress so they stay cheap. Their counts are numbers, not transaction ids, so a reorganisation or an inconsistent explorer can mislead them, however carefully they are kept. Rather than keep patching that bookkeeping, the design now makes resumed coverage **advisory**:

- **Claiming a payment** was already decided on a fresh scan made from scratch in one call (the re-validation, `REVALIDATE_MAX_PAGES`).
- **Cancelling an order** now is too. `expire_row()` uses only a scan that did not rely on saved progress: this cycle's cached scan if it was made from scratch, otherwise a fresh scan of its own. The scanner marks coverage that relied on saved progress (`resumed`). If one fresh scan cannot read the whole history, the order is held for review (`history_too_large`), never cancelled and never left waiting silently.
- **Everything else** a resumed scan feeds only delays or informs. The underpayment notes, the move to `underpaid` (which only prevents cancellation) and the late-payment notes can never pay or cancel anything.

In the scanner, the 12-block recount window from `7eeb0f9` is **removed**, which removes M2. The round-2 counting (exact across pages, txid-joined, with the edge carry) is kept, as is the round-3 rule that a scan's saved top never moves down. The counts remain an early warning against degraded answers. They are no longer a safety boundary.

### Evidence

- **New checks:**
  - `test-hd-evidence` §13: resumed coverage is marked, and a dense history converges. This is the reviewer's case (150 transactions, 25 to a block, Esplora, default budget), plus a BlockCypher dense case with a small budget.
  - `test-hd-verify` §19:
    - Saved progress claims coverage past a payment it never recorded. The fixture verifies that the resumed scan really does report complete coverage without the payment. Expiry does not cancel: its fresh scan finds and records the payment.
    - An expired order with too much history for one fresh scan goes to review.
    - After a sweep that cached a resumed scan, expiry scans afresh and still cancels a genuinely unpaid order (no stall).
- **Fixture corrected:** `test-hd-verify` §5 had modelled a new confirmation by moving the payment to a lower block with the tip unchanged, which cannot happen on a real chain. The "top never moves down" rule rightly restarted on it. The fixture now advances the tip.
- **Removed:** the window-specific tests (re-mined counted once, corrupt recent counts). What they guarded no longer exists.
- **Mutation tests:** 4 of 4 fail a suite when reverted alone: expiry deciding on a resumed scan, expiry reusing a resumed cached scan, no restart on an older top, and resumed coverage not marked. The round-2 counting mutations still fail as before. The one exception, dropping the saved progress on a shortfall, now shares its code with the restart, so it can no longer be reverted separately.
- **Results:** all seven Privacy Mode suites pass. PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 5: verdict and fix

The fifth Codex review verified the round-4 redesign:
- **Resolved:** M1, for irreversible decisions, and M2.
- **Every `claim_hd` and `payment_complete`** goes through fresh re-validation, including rows that are `completing` or `underpaid`.
- **Expiry** never uses resumed coverage, and the cache cannot hand it any.
- **The monitor, the reconcile command and retirement** do not rely on resumed coverage.

**Verdict: NOT MERGEABLE, one MUST-CHANGE: an existing liveness gap, not a regression.** A scan that can never be complete for a reason other than the page budget left an order stranded silently. Codex reproduced it with 51 confirmed DOGE transactions in one block. BlockCypher cannot page past a full page at one height, so every scan, fresh or resumed, returned "incomplete", and nothing ever held or reported the order, even one fully paid. It also asked for persistent failures to escalate, and flagged (SHOULD) that open assignments had no cycle-wide work budget.

### Fix

- **Unreadable answers go to review.** The scanner marks an answer that can never be read completely (`unreadable`), currently a full BlockCypher page within one block. The verifier then holds the order for review (`history_unreadable`) with a note. This happens whether the scan came from verification or expiry.
- **Persistent failures are reported, and retrying continues.** A record's saved scan state keeps `incomplete_since`. After `INCOMPLETE_NOTICE_SEC` (24 hours) of unbroken incompleteness for any other reason, the order gets one note and the log a warning. Retrying continues, so an explorer outage delays orders without sending every one of them to review. A complete scan clears the marker.
- **Each cycle's explorer work is bounded.** A time budget (`CYCLE_BUDGET_SEC`, 90 s; filter `nmmpro_hd_cycle_budget_sec`) covers verification, expiry and monitoring across all wallets. Open assignments are taken least recently checked first (`ORDER BY last_checked, id`), so what one cycle does not reach goes first in the next.

### Evidence

- **New checks:**
  - `test-hd-evidence`: 2 checks. A one-height page is unreadable; an explorer failure is not.
  - `test-hd-verify` §20: 9 checks.
    - A paid order at an address with 60 transactions in one block goes to review with a note, and so does an expired one.
    - A failing explorer: `incomplete_since` is recorded; after a day there is exactly one note; the order is still retried; on recovery it completes and the marker clears.
    - A spent budget stops the pass, and three slow paid addresses complete one per cycle.
    - Three slow unpaid addresses are all reached within two cycles (fair rotation).
- **Mutation tests:** 6 of 6 meaningful mutations fail a suite when reverted alone: unreadable not held, not flagged, the marker reset each scan, the notice repeated, the budget ignored, and id order instead of least recently checked. A seventh, clearing the marker on recovery, proved to be redundant code (a complete scan never carries it), and the line was removed.
- **Results:** all seven Privacy Mode suites pass. PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 6: verdict and fix

The sixth Codex review found the round-5 one-block case resolved for open orders. It judged the day-long note plus retrying adequate for:
- a repeated Esplora cursor;
- malformed answers;
- "nothing to page from";
- repeating counter mismatches;
- a binding height above the tip.

**Verdict: NOT MERGEABLE, two MUST-CHANGE:**

1. **The shared budget could starve other wallets and passes (introduced by `860065a`).** One 90-second budget covered every wallet and pass in a cycle. Cron visits wallets in a fixed order, verification before expiry and monitoring, and rotation applied only within a wallet. A busy first wallet could therefore stop later wallets, including paid or `completing` orders, from ever being checked, and could starve expiry and monitoring. Attempts that bailed out before scanning (a busy address, an unreadable order) never advanced `last_checked`, so they stayed first.
2. **An unreadable `completing` row was never escalated.** `hold_for_review()` rightly leaves a claimed completion in place. But then no note was written, the log wrongly said "held for review", and the day-long notice was skipped, so recovery was blocked with no word to the merchant.

It also recommended (SHOULD):
- that only a fresh success end an unreadable spell;
- that the monitor's own scanner path give the same notice.

### Fix

- **A budget per wallet per pass.** Verification, expiry and monitoring each have their own budget per wallet per cycle (`PASS_BUDGET_SEC`, 30 s; filter `nmmpro_hd_pass_budget_sec`, which replaces the cycle filter). No wallet or pass can starve another, and a cycle is bounded by wallets × 3 × budget.
- **Every attempt is stamped.** The verification pass stamps `last_checked` when it attempts a row (`NMMPRO_Hd_Repo::touch_checked`), whatever the attempt comes to. An early bail-out therefore moves the row to the back.
- **Escalation for completions in flight.** `hold_or_escalate()` holds an open row for review. For a `completing` row it keeps the claim, logs the truth, and puts one note on the order saying automatic recovery is blocked and it must be completed by hand. `hold_for_review()` now reports whether it moved the row.
- **Only a fresh complete scan ends an unreadable spell.** An advisory resumed success keeps `incomplete_since`.
- **The monitor tracks unreadability too.** It keeps `incomplete_since` in its own state and, after a day, notes once on the order that late payments cannot be checked. A complete check ends the spell.

### Evidence

- **New checks:**
  - `test-hd-verify` §21, 7 checks:
    - In one cron cycle, a wallet whose slow addresses spend its budget does not stop a second wallet's paid order from completing, or its expired order from being cancelled.
    - An address skipped because it was busy is stamped as attempted.
    - An unreadable completion in flight keeps its claim, and its order is told once.
    - A resumed success does not reset the timer.
  - `test-hd-monitor`, 3 checks: the spell is recorded, one note after a day, and a complete check ends it.
- **Mutation tests:** 6 of 6 fail a suite when reverted alone:
  - one shared cycle budget (a two-part revert);
  - no stamp on an early bail-out;
  - no escalation in flight;
  - a resumed success resetting the timer;
  - no monitor notice;
  - the monitor restarting its spell on every check.
- **Results:** all seven Privacy Mode suites pass. PHPStan, WPCS security and PHPCompatibility are clean.

### Addendum: expiry carries on where it stopped

The seventh Codex round hit its usage limit before starting. Reviewing the round-6 fix against the questions put to it, the author found a remaining starvation case in expiry. An expiry pass processed rows in `open_assignments()` order (`last_checked`, then `id`). A verification pass stamps every row within the same second, and ties are broken by id. An expired order that cannot be cancelled (a payment is pending, or it is otherwise unsettled) is re-scanned by every expiry pass. Several such orders at the front of the list could therefore use the expiry budget every cycle, so an expired, empty order further down was never reached. It stayed open: late, never wrong, but unbounded.

**Fix.** Expiry keeps its own round-robin position per wallet (`EXPIRY_CURSOR_OPTION`, wallet hash to the last row id processed). It takes rows by id starting after that position and wrapping around, and saves where it stopped. Uninstall removes the option.

**Evidence:**
- **New checks:** `test-hd-verify` §22, 2 checks. Three expired orders with a pending payment sit ahead of one expired, empty order. Every address is stamped in the same second, and each pass has a budget of about two fresh scans. The empty order is cancelled within two passes, and the three are not cancelled.
- **Mutation tests:** 2 of 2 fail the suite when reverted alone: the cursor ignored, and the cursor never saved.
- **Results:** all seven Privacy Mode suites and `test-upgrade-compat` pass. PHPStan, WPCS security and PHPCompatibility are clean.

## Independent review, round 7: verdict and fix

The seventh Codex review:
- found the escalation for an unreadable `completing` row resolved;
- found the expiry cursor sound (deleted or closed rows, wrap-around, exceptions, per-site uninstall);
- confirmed that no payment, claim, address reuse or cancellation depends on `last_checked`.

**Verdict: NOT MERGEABLE, two MUST-CHANGE, both scheduling:**

1. **Expiry's timestamps could starve verification.** Verification ordered rows by `last_checked`, and expiry's fresh scans advance it too. Codex reproduced a loop over five cycles:
   - Row A's slow failure spent verification's budget every cycle.
   - Row B, expired and paid, was scanned and stamped by expiry, which leaves completion to verification.
   - A therefore stayed oldest, and B was never verified.
2. **Monitoring had no rotation for unsuccessful attempts.** An exception before its state was saved, or a busy lock (which deliberately keeps a row due), left the row first in line. A batch of such rows could hide later late payments until their 90-day window closed.

It also noted (SHOULD) two things:
- cursor writes should be atomic per wallet, because the shared expiry option could be overwritten when the cron lock's fallback lets runs overlap;
- the documented cycle bound was inaccurate, because budgets are checked between rows.

### Fix: each pass keeps its own place

- **Verification, expiry and monitoring** each keep a round-robin position per wallet, in their own option (`CURSOR_OPTION_PREFIX` . pass . `_` . wallet hash). One option per pass per wallet means no read-modify-write of a shared array. Each pass takes rows by id after its position, wrapping around, and every attempt moves the position on: success, a busy address, a failure or an exception. No row, and no other pass's timestamps, can keep the rest from being reached. `last_checked` remains only for the monitor's interval and diagnostics.
- **Monitoring** queries due rows after its position first, then from the start (`NMMPRO_Hd_Repo::monitored_rows($days, $interval, $limit, $afterId)`). Busy rows stay due, as before, but no longer hold their place.
- **Uninstall** deletes every cursor option per site (a `LIKE` on the prefix). The round-6 addendum's single `EXPIRY_CURSOR_OPTION` is replaced; it was never released.
- **Documentation corrected:** budgets are checked between addresses, so a pass can run over by one address's work (at most one fresh scan of `REVALIDATE_MAX_PAGES` pages).

### Evidence

- **New checks:**
  - `test-hd-verify` §23, 2 checks: the reviewer's loop, in its own wallet. A's slow failure comes first; B is expired and paid; each cycle verifies, then expires. B completes by the second cycle, and A stays open.
  - `test-hd-monitor`, 2 checks: three busy addresses and two throwing ones sit ahead of one with a late payment, with a batch of two. The late payment is reported by the third cycle, and the busy rows remain due.
- **Mutation tests:** 6 of 6 fail a suite when reverted alone: verification ignoring or never saving its position, the monitor ignoring its position, not moving past an exception, and not rotating its query, plus the expiry position re-checked through the shared helper.
- **Results:** all seven Privacy Mode suites and `test-upgrade-compat` pass. PHPStan, WPCS security and PHPCompatibility are clean.
- **Environment note (28 Sep):** the local harness, then kept in the system's temporary folder, was partly deleted by the system's temporary-file clean-up. That included WordPress core, `wp-config.php`, part of WooCommerce and the static-analysis vendors. It was restored from the wp-cli cache (WordPress 7.1.2, WooCommerce 11.1.2 and Plugin Check 2.1.0, with WordPress and WooCommerce verified against their checksums), with the same database (`nmm_int`) and the same Composer pins as CI. Each rebuilt static tool was checked to fail on a deliberately bad file before its clean result was trusted.

## Merge of the Autopay safety track

On 30 September `integrate/post-2.12.0` (at `a7fb569`) was merged into this branch as `d44d252`. This branch was cut from that one at `90f8787`; the Autopay review then continued there and added four commits.

### What came in

- **`593ebad`, Codex round 4 (six must-change):**
  - the cancellation check inside WooCommerce's save also requires the order to be stored with the status the canceller read;
  - a refused cancellation aborts the save, where before it wrote the canceller's older status;
  - settlement requires the order to be stored exactly as it was read, which covers custom statuses and per-order paid filters;
  - an order event never runs the lease migration (an `ALTER TABLE` would commit a caller's transaction);
  - a refused sweep-start write, and a failed read of the pause switch, each stop the pass.
- **`b2b95f0`, Codex round 5:** a doc correction, and the lease migration gate. Until `nmmpro_payment_lease_schema` is recorded, no lease is settled and expiry starts no cancellation.
- **`889a2f4`:** a regression from `593ebad` that only shows under HPOS. WooCommerce's `wc_update_coupon_usage_counts()` runs on `woocommerce_order_status_cancelled` and, under HPOS, saves a second copy of the order. The cancellation check saw the order already stored as cancelled and refused that save.
- **`a7fb569`, Codex round 6 (M1):** `889a2f4` exempted every later cancelled save once one had passed. Now only the canceller's own save can approve, and a later cancelled save needs both locks and the order stored as cancelled.

The design is described in `docs/AUTOPAY-SAFETY.md`, guarantees 5 to 8.

### The merge itself

- **One conflict,** in `tests/README.md`: both branches had edited around the `test-autopay-safety` row. The Autopay branch's row (140 checks) and this branch's Privacy Mode rows were both kept.
- **Shared source files merged without conflict:** `NMMPRO_Payment.php`, `NMMPRO_Cron.php`, `nomiddleman-crypto-woocommerce.php` and `docs/DOWNGRADE.md`. After the merge, `NMMPRO_Payment.php` and `NMMPRO_Cron.php` differ from the Autopay branch only by this branch's own changes: `read_order_authoritatively()` made public, and the Privacy Mode passes in the cron job.

### What the two sides share

- **The cron job.** Both pause checks come before any Privacy Mode work, and that work is inside the `try`/`finally` that releases the cron lock. A tick that is paused, or cannot read the pause switch, does no Privacy Mode work.
- **Order events.** A Privacy Mode order's status change reaches the Autopay order-event handler, which updates the Autopay payment table by order id and amount. A Privacy Mode order has no row there, so nothing changes.
- **The cancellation check** is installed only around Autopay's own cancellation, and ignores other orders.
- **The lease migration gate** applies to Autopay settlement and expiry only. Privacy Mode keeps its own records.
- **`read_order_authoritatively()`** is identical on both sides.

### Evidence

- **All 18 database suites pass on `d44d252`** under CPT, under HPOS, and under READ COMMITTED (logs in `merge-d44d252/`).
- **Static checks:** PHPStan, WPCS security and PHPCompatibility are clean. Plugin Check reports 0 errors and 557 warnings, 2 more than before the merge, both from one new Autopay query in existing categories.
- **Autopay mutation tests:** 14 of 14 fail `test-autopay-safety` when applied alone (`a7fb569`, before the merge).
- **Codex merge review:** MERGEABLE, no MUST-CHANGE, no new SHOULD. It read the code and these logs and did not re-run the suites.
- **Lesson recorded:** `test-autopay-safety` must be run under HPOS after any change to the cancellation check. Its section 33 now fails under CPT as well if the check refuses a later save.

### Addendum: round 7's optional notes (`ae8da74`, merged as `80decf4`)

Codex round 7 left two optional notes on the Autopay track. Both are addressed in `ae8da74`, which changes tests, docs and one code comment, and no behaviour.

- **Wording.** A later cancelled save is allowed only while the order is stored as cancelled. That shows the cancellation is in storage, whichever save wrote it. The docs no longer say it proves the approved save landed.
- **Lock checks on later saves.** `test-autopay-safety` section 35 (140 to 146 checks): after the cancellation is approved and written, the address lock or the cron lock is lost and a copy of the order is saved. The save is refused, and the record is left for recovery, which settles it cancelled. Two mutations each drop one lock from the later-save check, and each fails its own test.

**Evidence for `80decf4`:**
- all 18 database suites pass under CPT, HPOS and READ COMMITTED (`merge-80decf4/`);
- PHPStan, WPCS security and PHPCompatibility are clean, and Plugin Check is unchanged at 0 errors and 557 warnings;
- 16 of 16 Autopay mutations fail the suite (`autopay-r7-tidy/`).

The merge had the same `tests/README.md` conflict as the first, resolved the same way. Neither `ae8da74` nor `80decf4` has had a Codex review.

### Addendum: MySQL (30 September, on `7fa58fd`)

Everything before this ran on MariaDB 13.0.2. The 18 database suites were then run on Oracle MySQL 8.4.11 and 8.0.46.

- **Setup:** each server had an empty database, its own data folder and socket, and default settings (`sql_mode` strict, REPEATABLE READ). WordPress and WooCommerce were installed fresh and the plugin activated, so its table creation and both migrations ran on MySQL. All six plugin tables were created as InnoDB, the Privacy Mode schema recorded 1.5, and the payment lease migration recorded itself.
- **Runs, per server:** CPT, HPOS (compatibility sync off), and CPT with READ COMMITTED. The isolation level was switched globally and read back from inside WordPress before each run.
- **Result:** all 18 suites pass in all six runs, with the same number of checks per suite as on MariaDB (logs in `mysql84-7fa58fd/` and `mysql80-7fa58fd/`).
- **Not covered:** MySQL 5.7 and earlier, and an upgrade of a real older store on MySQL.

