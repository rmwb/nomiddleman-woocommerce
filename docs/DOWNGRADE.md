# Downgrading to 2.12.0 or earlier

This release adds one payment-record state, `cancelling`: automatic expiry holds it for the moment between claiming an expired order and WooCommerce confirming the cancellation. Every tick, `NMMPRO_Payment::recover_interrupted_cancellations()` settles any such row left behind by an interrupted request.

**2.12.0 does not know this state.** Its matcher and expiry read only `unpaid` rows, and its recovery reads only `completing` ones. A row still in `cancelling` when 2.12.0 takes over is invisible to all of them. If a customer then pays that order, the payment is never credited, and the order is never cancelled either.

Do **not** rewrite these rows to `unpaid` by hand. Whether the order was actually cancelled is recorded on the order, not the payment record. Let the plugin settle each row from its order, and only while nothing can create new ones.

## Before you start

- **Only the background job creates `cancelling` rows**, and only in a pass that holds the cron lock. On a server without usable named locks, or before MySQL 5.7.5, no pass is ever exclusive (the Status screen says "Automatic cancellation ... is paused"), so this version never creates `cancelling` rows there. Check with the query in step 3. If it shows none, you can skip straight to step 5.
- **Multisite:** every step applies to every site. Run each command once per site, with `--url=`:

  ```bash
  wp site list --field=url | xargs -I{} wp --url={} option update nmmpro_background_paused 1
  ```

## Procedure

Keep this version installed and active until step 5.

1. **Pause the background job.** Every pass checks this option before taking the cron lock, and again, straight from the database, after taking it. A pass that was already waiting for the lock also exits without doing any work:

   ```bash
   wp option update nmmpro_background_paused 1
   ```

2. **Wait for any pass already running to finish.** Repeat until this prints exactly `false`:

   ```bash
   wp eval 'var_export(NMMPRO_Util::cron_pass_running());'
   ```

   - `true` means a pass is still running.
   - `NULL` means the server cannot tell. Treat it as not drained. Stop the job runners themselves (WP-Cron's system cron entry, and any Action Scheduler runner such as a queue worker), and confirm their processes have exited before going on.
3. **Settle every outstanding lease** with this version:

   ```bash
   wp eval 'NMMPRO_Payment::recover_interrupted_cancellations(); NMMPRO_Payment::resume_verified_orders();'
   ```

   ```bash
   wp db query "SELECT status, COUNT(*) FROM $(wp db prefix)nmmpro_payments WHERE status IN ('cancelling','completing') GROUP BY status"
   ```

   Repeat until no `cancelling` rows remain. 2.12.0 can recover `completing` rows itself, but letting them finish now is tidier. A row that stays put has an order that cannot be read, so check that order by hand. With the job paused, no other worker can be holding its address.
4. **Verify once more, still paused**, by running the `SELECT` again. It must show no `cancelling` rows. Nothing creates one while the job is paused and drained.
5. **Replace the plugin with 2.12.0.** 2.12.0 ignores the pause option, so its background job starts on its next tick. Delete the option afterwards:

   ```bash
   wp option delete nmmpro_background_paused
   ```

   If you stopped any runners in step 2, restart them.

## Leftover data

- The payment table keeps its `lease_gen` column. 2.12.0 does not use it, and its `DEFAULT 0` keeps 2.12.0's inserts working.
- These options are unused by 2.12.0 and harmless to keep. Uninstalling the plugin removes them:
  - `nmmpro_autopay_scan_cursor_unfenced`, `nmmpro_autopay_scan_retry_unfenced`, `nmmpro_autopay_scan_lane` and `nmmpro_autopay_unfenced`
  - `nmmpro_cancellation_cursor` and `nmmpro_deferral_purge_cursor`
  - `nmmpro_payment_lease_schema`
  - the `nmmpro_defer_*` rows

If you later upgrade again, the `lease_gen` column is already in place and the migration simply records it.
