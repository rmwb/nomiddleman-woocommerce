# Privacy Mode: address non-reuse and wallet recovery

Privacy Mode derives each order's payment address from your master public key (xpub), on the external (receive) chain: `xpub/0/i`. The plugin starts at index 2.

## Every issued address is used once

An address shown to a customer, or bound to an order, is never given to another order. That holds whatever happens to the order: paid, unpaid, cancelled, failed, refunded or deleted. A former customer can pay an old address at any time, and no waiting period can rule that out.

Before this release, an abandoned order's address went into quarantine and could return to the pool after two clean explorer checks. That path is gone. Existing pool and quarantine rows are retired when the plugin upgrades.

Each address is also checked on chain immediately before it is issued. An address with any history (receipts, spends, or a pending transaction) is retired without being shown to anyone.

## Consequence: gaps between paid addresses

Abandoned checkouts now leave unused addresses between paid ones. Wallet software that restores from a seed stops scanning after a run of unused addresses, called the **gap limit** (Electrum's default is 20). A payment beyond a longer run of unused addresses will not appear in the restored wallet, even though the funds are safe on chain.

**What to do:**
- **Find the scan range.** The plugin's Status screen shows, for each Privacy Mode wallet:
  - the highest index issued to an order;
  - the highest index derived;
  - how many issued addresses were retired without payment.
- **Scan far enough when you restore.** Make the wallet scan at least to the highest index derived.
  - **Electrum:** raise the gap limit from the console with `wallet.change_gap_limit(N)`, where N is more than the highest index derived. Then let it synchronise.
  - **Other wallets:** use their equivalent "address lookahead" or "gap limit" setting.
- **Keep one key per store.** Keep the xpub dedicated to this store. Payments to it from anywhere else look like history to the plugin, and those addresses get retired.

The plugin never resets or rewinds derivation, and never deletes an address record. The highest index only grows.

## Suspension on repeated history

If many consecutive newly derived addresses already have chain history (20 by default, filter `nmmpro_hd_max_used_skips`), the plugin suspends Privacy Mode for that coin:
- it stops offering the coin in Privacy Mode at checkout;
- it shows an admin notice.

Usually this means another wallet or store is using the same key. Nothing is reused to get around it. Confirm the key belongs to this store alone, or enter a dedicated one, then save the settings to resume.
