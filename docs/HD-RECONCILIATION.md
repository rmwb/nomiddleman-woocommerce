# Privacy Mode: audit and manual reconciliation

Privacy Mode completes an order automatically only on transactions confirmed after the order's address was issued to it, and never cancels an order when the evidence is incomplete. Anything it cannot decide is **held for manual review**:
- orders assigned by an earlier release;
- a payment confirmed at about the time the address was issued;
- conflicting explorer data;
- a transaction already recorded as used;
- an expired order that received only part of its payment.

This page is how you settle such orders.

## 1. Audit

The audit is read-only. It writes nothing to the plugin's tables, options or orders.

```bash
wp nmmpro-hd audit                                   # summary + every address record
wp nmmpro-hd audit --findings-only                   # only records that need attention
wp nmmpro-hd audit --order=1234 --chain             # one order, checked against the blockchain
wp nmmpro-hd audit --coin=DOGE --chain --findings-only --format=csv > doge-audit.csv
```

- **Chain checks:** `--chain` reads each address's full history from its explorers. These are read-only requests, limited by `--max-scans` (default 50), and their answers are never recorded. A failed or incomplete answer is reported as `chain_unknown`: it means "not known", never "not paid". After a failure the plugin backs off from that explorer for a while, so later records in the same run may also show `chain_unknown`. Re-run later, continuing with `--after`.
- **Paging:** the audit reads 100 records at a time (`--limit`, up to 1000). When there are more, it prints the `--after=` value to continue from.
- **Summary:** it shows the plugin and schema versions, what the upgrade did (addresses retired, orders held), each coin's capability, each wallet's derivation range, and record counts by state and reason.
- **Output:** order ids, statuses and amounts only. Customer details are never printed.

### Findings

| Finding | Meaning |
|---|---|
| `missing_provenance` | Assigned by a release that judged payments by lifetime totals; no validated binding. |
| `reused_address` | The record lists more than one order: an earlier release issued the address again. |
| `order_missing` | The order no longer exists. |
| `row_order_disagree` | The record and the order disagree about whether it was paid. |
| `paid_no_evidence` | Paid, but no credited transaction was recorded. |
| `false_positive_confirmed` | Paid, but **every** receipt at the address was confirmed before the order was placed: the payment cannot have been for this order. |
| `paid_evidence_absent` | Paid, but the address has received nothing at all. |
| `paid_underfunded` | Paid, but less than the amount due arrived after the order was placed. |
| `credited_evidence_missing` | A transaction credited to the order is no longer reported on chain (for example after a reorganisation). |
| `unpaid_but_funded` | Not paid, but at least the amount due arrived after the order was placed. |
| `pool_row_has_history` | An unissued pool address already has transactions. |
| `chain_unknown` | The chain could not be read completely. |
| `chain_skipped` | Not checked: the run's explorer budget was used up. |

For addresses assigned before this release there is no trustworthy assignment time. The audit uses the time WooCommerce recorded for the **order's creation** instead: a receipt confirmed before the order existed cannot be payment for it. That is what separates `false_positive_confirmed` from `paid_evidence_absent` and `paid_underfunded`.

The audit reports. It does not reverse fulfilment, refund, or change any order.

## 2. Reconcile an order

For each order that needs a decision:

1. **Verify the evidence yourself.** Open the order's address on a block explorer (the order's Privacy Mode panel shows it). Note every incoming transaction and its confirmation time, and compare them with when the order was placed and the amount due.
2. **Confirm the wallet is yours.** The address must belong to this store's receiving wallet, and the funds must be there.
3. **Check fulfilment.** Has anything already shipped or been delivered for this order?
4. **Decide the outcome:**
   - pay the order from specific transactions;
   - cancel it;
   - refund outside the plugin;
   - or contact the customer.
5. **Record the transactions before acknowledging the payment.** Record the transactions that belong to the order *before* you mark it paid, so the same funds can never be credited to another order, in Privacy Mode or Autopay:

   ```bash
   wp nmmpro-hd reconcile --order=<id> --tx=<txid>[:<output>],<txid2>            # dry run: shows the plan
   wp nmmpro-hd reconcile --order=<id> --tx=<txid>[:<output>] --complete --yes   # record, then complete
   ```

   **What the command does:**
   - With `--yes`, it records the order as the owner of each transaction in the ledger shared with Autopay. Without `--yes` it only prints the plan.
   - It **refuses** a transaction already recorded against another order, or against an unknown earlier owner, and then records nothing.
   - `--complete` then completes the order through WooCommerce, but only if the order is still awaiting payment. A cancelled or refunded order is never completed by the command; change its status yourself if the goods should be released.
   - It adds a note to the order with the transactions and settles the address record. The address is **never** returned to the pool.

   It does not check the blockchain for you. Step 1 is yours.
6. **Cancelling** needs no command: cancel the order in WooCommerce. Its address record retires on the next background run and is never reused. If funds later arrive at it, the order gets a note.

If you mark an order paid in WooCommerce without step 5, its address record still settles as paid. But its transactions are not recorded as used, so an Autopay order on the same address could still claim them. Record them with `wp nmmpro-hd reconcile` (without `--complete`) afterwards.

A manual action never enables address reuse: no command or status change returns an issued address to the pool.

## 3. Late payments

The background job keeps watching retired and held addresses for 90 days after they were issued. When a payment arrives after the address was issued, it adds one note per transaction to the order, marked "unconfirmed" while it is pending. It never applies such a payment to any order. Reconcile it as above, or refund it outside the plugin.
