# Autopay payment safety: guarantees and known limits

This document describes how Autopay decides that an order is paid or expired, what that design guarantees, and where it stops. It covers the Autopay path (a static or carousel address matched against explorer transactions). Privacy Mode (HD addresses) has its own completion lease and is not covered here.

## The two records

Every Autopay order has a WooCommerce order and a row in the plugin's payment table. The row's `status` is what matching and expiry act on:

| Status | Meaning |
|---|---|
| `unpaid` | Awaiting payment. The only status that matching and expiry select. |
| `completing` | A payment was verified and recorded, and the order is being completed. |
| `cancelling` | The order's payment window closed, and it is being cancelled. |
| `paid`, `cancelled`, `review` | Terminal. `review` means a verified payment needs a human, for example because the order was cancelled first, or moved to a custom status that is not paid. |

`completing` and `cancelling` are **leases**. Nothing but their owner, and the recovery passes that run every tick, can move them. Neither matching nor expiry can see them.

## Guarantees

1. **A transaction pays at most one order.** A payment claim is one InnoDB transaction. It moves the row out of `unpaid` and records every contributing transaction hash with a strict `INSERT`, which fails on a hash already recorded, at any isolation level. A claim that loses to a `cancelling` row records nothing.
2. **A payment that could not be recorded is not expired.** A claim that hits a database error defers its address from expiry. The deferral is a per-address row, checked again under the address lock, and it lapses only when a sweep that started after it has certified the coin. If the deferral cannot be stored, no order is expired that tick.
3. **Expiry only acts on addresses it has verified since the window closed.** It uses per-coin coverage stamps and per-address exclusions, which only an exclusive cron pass may write. Each write checks lock ownership inside the SQL statement that writes, and the coverage stamp goes last. A pass that cannot be exclusive, including on servers without usable named locks or before MySQL 5.7.5, still matches payments but certifies and cancels nothing. The Status screen says so.
4. **One worker per address.** Matching, completion, cancellation and both recovery passes take the same per-address lock. Code reached from inside an address's work (a WooCommerce hook) cannot take it again in the same process, and expiry passes never nest.
5. **A lease is settled from what WooCommerce stored, not from what it reported.** Current WooCommerce fires its status hooks even when saving the order failed. Settlement therefore:
   - reads the order past WooCommerce's order cache and the HPOS datastore cache;
   - treats an unreadable order as unknown, not deleted;
   - writes only if the row's `lease_gen` is unchanged since it was read (every order event bumps it) **and** the order is still stored exactly as it was when the decision was made (the same status, or still absent), read by the same `UPDATE`. Comparing with what was read, rather than with a list of statuses rebuilt in SQL, keeps the decision and the write in agreement for custom statuses and for a per-order `woocommerce_order_is_paid` filter.

   Any failure leaves the lease for the recovery passes.
6. **A cancellation is checked inside WooCommerce's own save.** Just before WooCommerce writes a cancelled order, a hook on `woocommerce_before_order_object_save` confirms four things on the connection about to write:
   - it still owns the address lock;
   - it still owns the cron lock;
   - the lease generation is unchanged;
   - the order is still stored with the status the canceller read.

   The last check catches a payment saved by another request whose payment-record event failed to land. If any check fails, the save is **aborted**: the hook clears WooCommerce's pending status transition and throws, and WooCommerce catches the exception before its data store writes the order. The order is not changed: not cancelled, and not returned to the canceller's older status over one saved since. No after-save or status hooks fire, so no "cancelled" email is sent and no stock effects run.

   One thing is written: WooCommerce's exception handler adds an internal error note to the order, recording that the save failed. Anything listening for new order notes sees it. Checked against WooCommerce 10.0, 10.8, 11.0 and 11.1.

   Only the canceller's own save can approve the cancellation. Another cancelled save of the same order that reaches the check first is judged by the same rules, but approves nothing.

   Once approved, the order may be saved again within the same cancellation:
   - WooCommerce itself saves a second copy of the order under HPOS, for its coupon usage bookkeeping when an order is cancelled;
   - an integration may re-save the order from a status hook.

   Such a later cancelled save still needs both locks, and is allowed only while the order is stored as cancelled. That also shows the cancellation is stored, whichever save wrote it, instead of assuming the approved save landed. A cancelled save over anything else, such as a payment saved in between, is refused like any stale cancellation.

   Clearing the transition needs a protected WooCommerce property. Before claiming a cancellation, the canceller checks that it exists, and does not cancel at all if it does not.
7. **Order events never run DDL.** The `lease_gen` column comes from a migration that runs only when the site loads and on activation. It never runs from an order event, where `ALTER TABLE` would implicitly commit a caller's open transaction.

   Until the migration has recorded itself (the `nmmpro_payment_lease_schema` option), the generation cannot be relied on, even where the column already exists. Until then:
   - order events apply to ordinary rows and leave leased rows alone;
   - no lease is settled;
   - expiry starts no cancellation.

   A payment is still matched and recorded, and its order is completed; its record stays `completing` until the migration runs. The migration retries on every page load.
8. **A background pass that cannot read the pause switch does nothing.** After taking the cron lock, the pass re-reads `nmmpro_background_paused` from the table. A failed read is treated as paused, and the lock is released.
9. **A public node's answer counts for expiry only up to a proven horizon** (since 2.14.0). ERC-20 transfers come from Blockscout, and from a public JSON-RPC node when Blockscout cannot answer. A node's answer always lets payments be matched. For expiry it counts only when the node first shows that its record of the token's transfers is current: its newest Transfer of that token, to anyone, within seconds of its newest block and checked by hash against that block. Even then, it counts only up to that block's time, minus a minute for a chain clock running ahead of this server's and ten minutes of margin. The sweep keeps the earliest such horizon per coin, passes it on to the next sweep, and stamps coverage no later than it. A node answer that cannot show it is current leaves its address excluded. The node source is all or nothing: any unreadable range, header or field, more than 100 transfers, a block replaced while it was read, or a tip more than five minutes old or more than a minute in the future fails the fetch.
10. **A transaction hash is one transaction in any case** (since 2.14.0). The consumed-transaction ledger folds `0x` + 64-hex hashes to lower case, so a transfer has the same identity whichever source served it. On upgrade, every row recorded in another case gets a lower-case twin once, keeping its order. Where a lower-case row already exists, it keeps its own owner.
11. **What one sweep tick could not verify is carried into the next sweep** (since 2.14.0). The tick that opens a sweep (the one that wraps, or one that covers every address at once) counts as the new sweep's visit to its addresses. Its unverified addresses and its node horizons are therefore passed on to the new sweep, so a later wrap that does not revisit them cannot drop their exclusion or stamp past a horizon.

## Known limits

These are properties of building on WordPress and WooCommerce, or of the outside services Autopay reads, not open defects.

- **No conditional order save.** WooCommerce cannot make "save this order as cancelled" conditional on a database predicate. The save check in guarantee 6 narrows the exposure to the instant between the check (including its read of the stored status) and WooCommerce's own `UPDATE`. A connection lost in that instant, after the check passed, cannot be detected. That needs three things at once:
  - a dropped database connection;
  - a paused PHP process;
  - a second worker completing a payment for the same order in between.
- **Third-party hook side effects are not exactly-once.** When a completion is retried after WooCommerce failed to save the order, `payment_complete()` runs again. WooCommerce's own stock and email guards prevent most duplicates. A third-party integration that ignores them may act twice.
- **A reopened order with a verified payment is completed again.** If you move an order back to Pending while its verified payment is still completing (`completing`), the recovery pass completes it again. The payment was verified on chain, so the verification decides, not the later status edit. To stop that, cancel the order: the payment is then held for manual reconciliation (`review`).
- **Busy or unreadable orders wait.** An address another worker holds, or an order that cannot be read, is left for a later tick. If the condition persists, the order is never auto-expired. Expiry is late in that case, never wrong.
- **Reliable locks are required for automatic expiry.** Without them, Autopay still verifies payments, but you cancel expired orders by hand.
- **Expiry through a node is later.** An order last checked through a public node is cancelled up to about eleven minutes later than through Blockscout. A busy address (more than 100 transfers of the token within the matcher's lookback) cannot be read through a node at all and waits for Blockscout. On Polygon, Arbitrum and Base, whose Blockscout currently answers with a Cloudflare challenge, that means waiting until it is reachable again.
- **What the node check cannot see.** A load-balanced RPC provider whose backend serving one address's logs lags more than about ten minutes, while the backend serving the newest block and the proof is current, is not detected. Nor is an operator that answers falsely but consistently. Blockscout is trusted in the same way.

## Release record

- **2.14.0** (11 November 2026): guarantees 9 to 11, from issue #17. Merged by #27 and released by #29 with tag `v2.14.0`. Reviewed in four Codex rounds and two Claude Fable fund-safety reviews; every must-fix they found is fixed in the release, and Fable's re-review found nothing further. Codex was not run again after its round-4 finding was fixed. Plugin Check: 0 errors. Not deployed to a store as part of this work.
