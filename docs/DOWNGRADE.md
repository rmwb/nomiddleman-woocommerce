# Downgrading to 2.12.0 or earlier

This release adds one payment-record state, `cancelling`: automatic expiry holds it for the moment between claiming an expired order and WooCommerce confirming the cancellation. Every tick, `NMMPRO_Payment::recover_interrupted_cancellations()` settles any such row left behind by an interrupted request.

**2.12.0 does not know this state.** Its matcher and expiry read only `unpaid` rows, and its recovery reads only `completing` ones. A row still in `cancelling` when you downgrade is invisible to all of them. If a customer then pays that order, the payment is never credited, and the order is never cancelled either.

Do **not** rewrite these rows to `unpaid` by hand. Whether the order was actually cancelled is recorded on the order, not the payment record. Let the plugin settle each row from its order, then downgrade.

## Procedure

1. **Stop new work.** Put the store in maintenance mode, or otherwise make sure no checkout is in progress.
2. **Settle outstanding cancellations** with this version still active, until none are left:

   ```bash
   wp eval 'NMMPRO_Payment::recover_interrupted_cancellations();'
   ```

   ```bash
   wp db query "SELECT COUNT(*) FROM $(wp db prefix)nmmpro_payments WHERE status='cancelling'"
   ```

   Repeat the first command until the count is 0. A row stays if its order cannot be read or its address is busy, so if one persists, check that order by hand.
3. **Let any in-progress completion finish** the same way. 2.12.0 already understands `completing`:

   ```bash
   wp eval 'NMMPRO_Payment::resume_verified_orders();'
   ```

4. **Deactivate this version and install 2.12.0.** Pause the background job (Tools > Scheduled Actions, hook `NMMPRO_cron_hook`) between steps 2 and 4, if your store is busy enough that expiry could start a new cancellation in the meantime.

## Leftover options

These options are unused by 2.12.0 and harmless to keep. Uninstalling the plugin removes them:

- `nmmpro_autopay_scan_cursor_unfenced`
- `nmmpro_autopay_scan_retry_unfenced`
- `nmmpro_autopay_unfenced`
- `nmmpro_cancellation_cursor`
- `nmmpro_defer_*`
- `nmmpro_lease_event_*`
