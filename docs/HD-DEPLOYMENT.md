# Privacy Mode safety release: deployment and rollback

This release changes how Privacy Mode (HD wallet) decides that an order is paid, and it changes stored data on upgrade. Deploying it is the owner's decision, after independent review. This runbook assumes that approval.

## What the upgrade does

- **Adds data:** schema 1.4 → 1.5 adds columns to the Privacy Mode address table and creates `nmmpro_hd_evidence` (InnoDB). A MyISAM address table is converted to InnoDB. If any part fails, for example through missing `ALTER` privileges, the upgrade retries on every page load. Until it succeeds, Privacy Mode is paused (no address issued, nothing completed or cancelled) and the admin notice says so.
- **Legacy policy.** Nothing is deleted, and derivation indexes are unchanged.
  - **Unused addresses:** pool (`ready`) and quarantined addresses are **retired**. An older release may already have issued them, and their history cannot be proven.
  - **Orders in flight:** addresses assigned to orders still awaiting payment (`assigned`, `underpaid`, `completing`) are **held for manual review**. They are never completed or cancelled automatically, and they are listed in the admin notice and by the audit.
  - **Finished records:** paid (`complete`) and `dirty` records are untouched.
- **Capability changes:** Privacy Mode is **no longer available for QTUM and BTX**, whose only sources report lifetime totals. It was already unavailable for XMY. BTC, LTC, DOGE and DASH remain, using transaction evidence from mempool.space and blockstream.info (BTC), litecoinspace.org then BlockCypher (LTC), and BlockCypher (DOGE, DASH).
- **Confirmations:** the Privacy Mode minimum is now 1. A saved 0 reads as 1.
- **Autopay changes in the same release.** The Autopay safety work ships with this release; see `docs/AUTOPAY-SAFETY.md`.
  - The payment table gets a `lease_gen` column, added on the first page load after the update and recorded in the `nmmpro_payment_lease_schema` option.
  - Until that option is recorded, Autopay still matches payments and completes their orders, but it settles no in-flight record and expires no order.
- **Wallet gap:** addresses are never reused, so abandoned checkouts leave unused addresses between paid ones. See `docs/HD-WALLET-RECOVERY.md`. The Status screen shows each wallet's scan range.

## Prerequisites

- A **BlockCypher API token** for stores using Privacy Mode for DOGE or DASH. It also helps LTC, which falls back to BlockCypher. Without a token, BlockCypher allows about 100 requests an hour. Every checkout needs at least two (the address check and the chain height), and every verification at least one.
- **MySQL/MariaDB named locks** (`GET_LOCK`). Without them, Privacy Mode addresses are never processed automatically: they wait, safely.
- **Database privileges** to `ALTER` the plugin's own tables and create one table.
- The plugin's declared PHP and WordPress versions.

## Runbook

1. **Preserve.** Take a full database backup. Keep the web server, PHP and plugin logs.
2. **Contain.** Before touching production, stop new Privacy Mode checkouts: switch the affected coins to Classic Mode, or disable them. If an incident is under way, stop fulfilment of Privacy Mode orders that were completed automatically until they are audited.
3. **Rehearse on an isolated copy** of production, with outgoing email and fulfilment integrations disabled:
   1. Install this release.
   2. Load any admin page, so the upgrade runs.
   3. Confirm `wp option get nmmpro_hd_table_version` prints `1.5`.
   4. Run `wp nmmpro-hd audit` to see the upgrade's counts (retired, held for review) and each coin's capability.
   5. Run `wp nmmpro-hd audit --chain --findings-only` for the paid-order findings (`false_positive_confirmed`, `paid_evidence_absent`, `paid_underfunded`). Budget the explorer requests with `--max-scans`, and continue with `--after`.
   6. Record the counts. **Report them, and the capability changes, to the owner before deployment.**
4. **Review.** Decide what to do about:
   - the held orders;
   - any paid orders the audit flags;
   - stores configured for QTUM or BTX Privacy Mode (switch them to Classic Mode).
5. **Deploy** in a controlled window. Update the plugin, load an admin page, and confirm:
   - schema `1.5` (`wp option get nmmpro_hd_table_version`);
   - no "paused" admin notice;
   - `wp option get nmmpro_payment_lease_schema` prints `1`. If it does not, Autopay will not expire orders; check the plugin log for the migration error.

   On multisite, each site upgrades on its own first load; check each site.
6. **Verify a fresh payment flow** with a small real payment per enabled coin:
   - the address is shown only after its on-chain check;
   - the order completes after the required confirmations;
   - its note names the transaction;
   - the Privacy Mode panel shows the amount received.

   If the store uses Autopay, also let one unpaid test order expire. It should be cancelled with the customer's cancellation note and no "Error saving order" note. This matters most on stores using HPOS.
7. **Restore operation and monitor.** Re-enable Privacy Mode checkout. For the first days, watch:
   - the admin notice (held orders);
   - `wp nmmpro-hd audit --findings-only`;
   - the plugin log (warnings about unknown evidence, rate limits or held orders).
8. **Reconcile** held and flagged orders using `docs/HD-RECONCILIATION.md`.

## Rollback

Rolling back must not erase new evidence or bring back unsafe automatic verification.

- **Do not restore an old database snapshot** that discards orders, payments or the evidence and ownership records created since the backup. Restore only onto an isolated copy, for investigation.
- **If the code must be rolled back:**
  - First switch every Privacy Mode coin to **Classic Mode**. Earlier releases pay Privacy Mode orders from lifetime totals, and would issue and verify new addresses that way.
  - Then roll back the plugin. The Autopay part of the downgrade has its own steps in `docs/DOWNGRADE.md`.
  - Keep Privacy Mode in Classic Mode until this release, or a later one, is reinstalled.
- **What earlier releases leave alone:** they ignore the `retired` and `review` states, so they neither recycle retired addresses nor pay held orders. They also ignore the new columns and the evidence table.
- **Leftover data:** the new columns and `nmmpro_hd_evidence` are kept for forward recovery. Reinstalling this release re-applies the legacy policy to anything an earlier release wrote in the meantime.
