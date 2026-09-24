# Downgrading to 2.12.0 or earlier

This release adds one payment-record state, `cancelling`: automatic expiry holds it for the moment between claiming an expired order and WooCommerce confirming the cancellation. Every tick, `NMMPRO_Payment::recover_interrupted_cancellations()` settles any such row left behind by an interrupted request.

**2.12.0 does not know this state.** Its matcher and expiry read only `unpaid` rows, and its recovery reads only `completing` ones. A row still in `cancelling` when 2.12.0 takes over is invisible to all of them. If a customer then pays that order, the payment is never credited, and the order is never cancelled either.

Do **not** rewrite these rows to `unpaid` by hand. Whether the order was actually cancelled is recorded on the order, not the payment record. Let the plugin settle each row from its order, and only while nothing else can create new ones.

## Procedure

The background job is the only code that matches payments, finishes completions or expires orders. Checkout never does. So pausing the job and waiting out any pass already running leaves no Autopay worker at all. Work through these steps in order, with this version still installed and active.

1. **Pause the background job.** From the next tick on, every pass returns immediately, without taking any lock:

   ```bash
   wp option update nmmpro_background_paused 1
   ```

2. **Wait for any pass already running to finish.** Repeat until this prints `false`:

   ```bash
   wp eval 'var_export(NMMPRO_Util::cron_pass_running());'
   ```

   On a database server without named locks the check cannot see a running pass (the Status screen says so when this applies). There, wait longer than PHP's `max_execution_time` after pausing instead. If your host runs WP-Cron from a system cron with no time limit, wait at least as long as your longest cron run.
3. **Settle every outstanding lease** with this version:

   ```bash
   wp eval 'NMMPRO_Payment::recover_interrupted_cancellations(); NMMPRO_Payment::resume_verified_orders();'
   ```

   ```bash
   wp db query "SELECT status, COUNT(*) FROM $(wp db prefix)nmmpro_payments WHERE status IN ('cancelling','completing') GROUP BY status"
   ```

   Repeat until no `cancelling` rows remain. 2.12.0 can recover `completing` rows itself, but letting them finish now is tidier. A row that stays put either has an order that cannot be read or an address held by another worker; the pause should have ruled the second out, so check that order by hand.
4. **Verify once more, still paused**, by running the `SELECT` again. It must show no `cancelling` rows. Nothing can create one while the job is paused.
5. **Replace the plugin with 2.12.0.** 2.12.0 ignores the pause option, so its background job starts on its next tick. Delete the option afterwards:

   ```bash
   wp option delete nmmpro_background_paused
   ```

## Leftover data

- The payment table keeps its `lease_gen` column. 2.12.0 does not use it, and its `DEFAULT 0` keeps 2.12.0's inserts working.
- These options are unused by 2.12.0 and harmless to keep. Uninstalling the plugin removes them:
  - `nmmpro_autopay_scan_cursor_unfenced`, `nmmpro_autopay_scan_retry_unfenced` and `nmmpro_autopay_unfenced`
  - `nmmpro_cancellation_cursor` and `nmmpro_deferral_purge_cursor`
  - `nmmpro_payment_lease_schema`
  - the `nmmpro_defer_*` rows

If you later upgrade again, the `lease_gen` column is already in place and the migration simply records it.
