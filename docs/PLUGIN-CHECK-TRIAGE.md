# Plugin Check warning triage — 26 September 2026 (revised for the Privacy Mode safety work)

The packaged scan of 6 September contained 342 warnings and zero errors after that round's fixes (missing stylesheet version, HTML-wrapped translation and three verbose data-dump log calls). The 24 September revision re-scanned the payload after the read-only Status screen and four rounds of Autopay safety changes: zero errors, 410 warnings. This revision re-scans the release payload (`git archive` of `hd-payment-safety`) after the Privacy Mode (HD) safety work: **still zero errors, 573 warnings**, every one of them in the categories below. It does not hide the database warnings.

- **196 direct queries / 193 no-caching notices** (143 / 140 before the Privacy Mode work): these predominantly operate on plugin-owned payment, HD-address, address-rotation and retry tables. Conditional claims, current status checks and retry selection must observe current database state. WordPress's order APIs are still used for WooCommerce orders. Adding a cache to these claim paths would undermine their concurrency guarantees. Read-only reporting queries can be optimized separately with explicit invalidation.
- **131 interpolation notices** (91 before): the reported variables are table identifiers or `$def`. Constructors/helpers derive identifiers from `$wpdb->prefix` plus fixed plugin constants. The three `$def` instances are selected from local literal index-definition arrays. Values (order IDs, addresses, amounts and hashes) are passed through placeholders. The duplicate-HD reconciliation helper is called with the internally built HD table name. No request input was found reaching these interpolated identifiers or index definitions in this review.
- **Added since 6 September (30 direct-query, 27 no-caching, 7 interpolation, 3 unescaped-parameter, 1 schema-change):**
  - **Cron fence:** advisory-lock ownership checks in NMMPRO_Util: `GET_LOCK`, `IS_USED_LOCK`, `RELEASE_LOCK`, and the fenced option write. That write is an `INSERT ... SELECT ... WHERE IS_USED_LOCK() = CONNECTION_ID()` on the options table, so it only lands while the cron lock is held.
  - **Payment-record changes:** conditional lease claims, settlements checked against both `lease_gen` and the order's persisted status (a subquery on WooCommerce's own posts or HPOS orders table), under-lock expiry re-reads, the pause re-check after the cron lock on the plugin's payment table (NMMPRO_Payment_Repo, NMMPRO_Payment), plus per-address expiry deferrals: one options row each, paged with an option_id cursor and deleted with compare-and-delete.
  - **Authoritative order reads:** an existence check for an order in WooCommerce's own storage (posts or the HPOS orders table), used only after WooCommerce returned no order.
  - **Payment-claim transaction:** a strict INSERT and a row-locked status read inside it (NMMPRO_Consumed_Repo).
  - **Uninstall:** removes the deferral rows by prefix.
  - **Status screen:** read-only queries (NMMPRO_Payment_Repo backlog, and NMMPRO_Log_Repo reading WooCommerce's log table only after confirming it exists).

  Lock, claim and settlement queries must see the live database state, so none of them can be cached. Every value is bound through placeholders. The interpolated identifiers are table names built from `$wpdb->prefix` and plugin constants, or supplied by WooCommerce.
- **Added by the Privacy Mode safety work (53 direct-query, 53 no-caching, 40 interpolation, 14 unescaped-parameter, 3 schema-change):**
  - **Schema 1.5 (`NMMPRO_Hd_Schema`):** verify-before-bump checks (`SHOW COLUMNS`, `SHOW INDEX`, `information_schema` engine reads), the column `ALTER`, the engine conversion, and the evidence table `CREATE`. There is also the legacy row policy's conditional `UPDATE`s, which retire pool rows and hold unvalidated assignments for review; they run at upgrade and on every cron tick and must see live state.
  - **Evidence (`NMMPRO_Hd_Evidence_Repo`):** per-output reads, inserts and updates on the plugin-owned `nmmpro_hd_evidence` table. The insert and update are each two literal statements (confirmed and unconfirmed), because `prepare()` cannot bind `NULL`. The unescaped-parameter notices are the table identifier, built from `$wpdb->prefix` and a plugin constant.
  - **Address records (`NMMPRO_Hd_Repo`):** token-guarded reservation, binding, retirement and deferral; validated-assignment reads; guarded state transitions; resumable scan state; the wallet-range summary. Every write is conditional on row id, wallet and expected state (and token), and its outcome is verified. Caching would defeat those guards.
  - **Shared ledger (`NMMPRO_Consumed_Repo`):** ownership reads, the Privacy Mode claim transaction (strict inserts, evidence credit and the `completing` move in one InnoDB transaction, with a `FOR UPDATE` re-read on a same-second resume), and manual reconciliation.
  - **Verification, audit and admin:**
    - `NMMPRO_Hd` settles held rows from the merchant's decision;
    - `NMMPRO_Hd_Verifier` resolves an address row's wallet;
    - `NMMPRO_Hd_Audit` runs read-only report queries;
    - `NMMPRO_Hd_Reconcile` records an operator's verified reconciliation;
    - `NMMPRO_Hd_Order_Panel` reads the order screen's row;
    - `NMMPRO_Admin` counts held orders for its notice.
  - **Bootstrap:** the self-heal and Site Health checks include the evidence table, and uninstall drops it.

  Every value is bound through placeholders. The interpolated identifiers are the plugin's own table names, and the only other interpolations are fixed column lists and literal placeholder strings. In the first packaged scan of this work, Plugin Check reported 17 `NotPrepared` errors: values concatenated into the SQL passed to `prepare()`. They were fixed by writing literal statements, not suppressed.
- **29 unescaped-parameter notices** (15 before): repeat the table-identifier cases above in the consumed ledger, retry repository and duplicate-HD migration, plus the HPOS orders table name that NMMPRO_Payment reads from WooCommerce's own `OrderUtil::get_table_for_orders()` when confirming whether an order exists, and the payment table name in the `lease_gen` migration's column check and ALTER.
- **17 schema-change notices** (14 before): installation, repair (including the verify-then-record migration that adds the payment table's `lease_gen` column) and uninstall intentionally create/alter/drop the plugin's own tables. These must remain scoped to the current site prefix.
- **One unfinished-prepare notice:** Carousel_Repo builds a list of literal `(%s)` placeholders and passes every currency as a separate bound value; the scanner cannot infer those placeholders across the concatenation.
- **Four dynamic-hook notices:** the compatibility wrapper deliberately fires the old and new names. Callers pass fixed plugin hook names.
- **Translation loading and error_log fallback:** retained for bundled translations/older WordPress support and operational failure logging when the WooCommerce logger is unavailable. Debug messages are gated and entries bounded/throttled.

Identifier placeholders introduced in newer WordPress versions are not used unconditionally because the plugin still declares WordPress 5.3 support. This is a source review and scoped justification, not a blanket guarantee that every database access is safe. No blanket suppression was added.

## Scan locations retained for reviewer inspection

| File | Line | Warning | Message |
|---|---:|---|---|
| nomiddleman-crypto-woocommerce.php | 46 | PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound | load_plugin_textdomain() has been discouraged since WordPress version 4.6. When your plugin is hosted on WordPress.org, you no longer need to manually include this function call for translations under your plugin slug. WordPress will automatically load the translations for you as needed. |
| nomiddleman-crypto-woocommerce.php | 320 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 320 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 380 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 380 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 403 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 403 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 405 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 427 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 427 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 435 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 435 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 435 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW COLUMNS FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 455 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 455 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 456 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;DELETE t1 FROM `$tableName` t1\n |
| nomiddleman-crypto-woocommerce.php | 457 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at                  INNER JOIN `$tableName` t2\n |
| nomiddleman-crypto-woocommerce.php | 461 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 461 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 461 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;ALTER TABLE `$tableName` $def&quot; |
| nomiddleman-crypto-woocommerce.php | 461 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $def at &quot;ALTER TABLE `$tableName` $def&quot; |
| nomiddleman-crypto-woocommerce.php | 461 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 464 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 464 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 464 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 500 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 500 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 554 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 554 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 566 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 566 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 566 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 575 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 575 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 575 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 576 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 576 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 576 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter evidence_table() . &quot;`&quot;) used in $wpdb-&gt;query() |
| nomiddleman-crypto-woocommerce.php | 576 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 587 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 587 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 587 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 596 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 596 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 596 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 631 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 631 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 643 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 643 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 643 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW COLUMNS FROM `$tableName` LIKE &#039;hd_mode&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 645 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 645 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 645 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;ALTER TABLE `$tableName` ADD `hd_mode` bigint(10) NOT NULL default &#039;0&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 645 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 648 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 648 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 648 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW COLUMNS FROM `$tableName` LIKE &#039;hd_mode&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 672 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 672 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 672 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName` WHERE Key_name = &#039;hd_address&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 674 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 674 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 674 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;ALTER TABLE `$tableName` ADD UNIQUE KEY `hd_address` (`cryptocurrency`, `address`)&quot; |
| nomiddleman-crypto-woocommerce.php | 674 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName` WHERE Key_name = &#039;hd_address&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 694 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 694 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 694 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName` WHERE Key_name = &#039;status_checked&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 696 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 696 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 696 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;ALTER TABLE `$tableName` ADD KEY `status_checked` (`status`, `last_checked`)&quot; |
| nomiddleman-crypto-woocommerce.php | 696 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 699 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 699 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 699 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName` WHERE Key_name = &#039;status_checked&#039;&quot; |
| nomiddleman-crypto-woocommerce.php | 719 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 719 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 719 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 722 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 722 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 722 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;ALTER TABLE `$tableName` $def&quot; |
| nomiddleman-crypto-woocommerce.php | 722 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $def at &quot;ALTER TABLE `$tableName` $def&quot; |
| nomiddleman-crypto-woocommerce.php | 722 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 726 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 726 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 726 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 754 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 754 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 754 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb-&gt;get_results() |
| nomiddleman-crypto-woocommerce.php | 755 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SELECT cryptocurrency, address FROM `$tableName`\n |
| nomiddleman-crypto-woocommerce.php | 760 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 760 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 760 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb-&gt;get_results() |
| nomiddleman-crypto-woocommerce.php | 761 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SELECT id, status, order_id, total_received FROM `$tableName`\n |
| nomiddleman-crypto-woocommerce.php | 784 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 784 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 834 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 834 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 848 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 848 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 856 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 856 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 856 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 859 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 859 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 859 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;ALTER TABLE `$tableName` $def&quot; |
| nomiddleman-crypto-woocommerce.php | 859 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $def at &quot;ALTER TABLE `$tableName` $def&quot; |
| nomiddleman-crypto-woocommerce.php | 859 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 863 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 863 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 863 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SHOW INDEX FROM `$tableName`&quot; |
| nomiddleman-crypto-woocommerce.php | 886 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 886 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 891 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 891 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 891 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb-&gt;get_results() |
| nomiddleman-crypto-woocommerce.php | 894 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 894 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 894 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 919 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 919 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 1100 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 1100 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Order_Panel.php | 113 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Order_Panel.php | 113 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Order_Panel.php | 115 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at \t\t\t FROM `$table` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1&quot; |
| src/NMMPRO_Hooks.php | 133 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hooks.php | 133 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hooks.php | 134 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SELECT `status`, `cryptocurrency`, `credited_units`, `order_amount` FROM `$tableName` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1&quot; |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT * FROM `$table` WHERE status=&#039;completing&#039; AND id&gt;%d ORDER BY id LIMIT 25&quot; |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT status FROM `$table` WHERE id=%d&quot; |
| src/NMMPRO_Payment.php | 119 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 119 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 119 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_var()\n$sql assigned unsafely at line 114. |
| src/NMMPRO_Payment.php | 231 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 231 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 241 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 241 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 241 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT status FROM `$table` WHERE id=%d&quot; |
| src/NMMPRO_Payment.php | 831 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 831 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 850 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 850 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 880 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 880 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 895 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 50 | WordPress.PHP.DevelopmentFunctions.error_log_error_log | error_log() found. Debug code should not normally be used in production. |
| src/NMMPRO_Util.php | 157 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 157 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 160 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 160 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 227 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 237 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 281 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 281 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 302 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 302 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 311 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 311 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 339 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 339 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 345 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 345 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 386 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 386 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 401 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 401 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 128 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 128 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 151 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 151 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 151 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;ALTER TABLE `$hdTable` &quot; |
| src/NMMPRO_Hd_Schema.php | 151 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Hd_Schema.php | 166 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 166 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 166 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;ALTER TABLE `$hdTable` ENGINE=InnoDB&quot; |
| src/NMMPRO_Hd_Schema.php | 166 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Hd_Schema.php | 178 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 178 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 178 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $evidenceTable used in $wpdb-&gt;get_results() |
| src/NMMPRO_Hd_Schema.php | 182 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 182 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 182 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $evidenceTable used in $wpdb-&gt;get_col() |
| src/NMMPRO_Hd_Schema.php | 231 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;UPDATE `$hdTable` SET `status` = &#039;retired&#039;, `review_reason` = COALESCE(`review_reason`, %s)\n |
| src/NMMPRO_Hd_Schema.php | 235 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;UPDATE `$hdTable` SET `status` = &#039;retired&#039;, `review_reason` = COALESCE(`review_reason`, %s)\n |
| src/NMMPRO_Hd_Schema.php | 239 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;UPDATE `$hdTable` SET `status` = &#039;review&#039;, `review_reason` = COALESCE(`review_reason`, %s)\n |
| src/NMMPRO_Hd_Schema.php | 246 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 246 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 254 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 254 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 254 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $hdTable used in $wpdb-&gt;get_var() |
| src/NMMPRO_Hd_Schema.php | 255 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;SELECT COUNT(*) FROM `$hdTable`\n |
| src/NMMPRO_Hd_Schema.php | 291 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 291 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Schema.php | 291 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_col() |
| src/NMMPRO_Hd_Schema.php | 305 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Schema.php | 305 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Audit.php | 66 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Audit.php | 66 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Audit.php | 112 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Audit.php | 112 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Audit.php | 113 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT * FROM `$table`\n |
| src/NMMPRO_Hd_Reconcile.php | 51 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Reconcile.php | 51 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Reconcile.php | 51 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT `id`, `cryptocurrency`, `address`, `status` FROM `$table` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1&quot; |
| src/NMMPRO_Hd_Reconcile.php | 118 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Reconcile.php | 118 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Reconcile.php | 119 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `status` = &#039;complete&#039;\n |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 46 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)\n |
| src/NMMPRO_Consumed_Repo.php | 50 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)\n |
| src/NMMPRO_Consumed_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 69 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 69 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 69 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $payments at &quot;SELECT order_id,tx_hash FROM `$payments`\n |
| src/NMMPRO_Consumed_Repo.php | 86 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 86 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 86 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 85. |
| src/NMMPRO_Consumed_Repo.php | 86 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT identity FROM `$table` WHERE identity=%s&quot; |
| src/NMMPRO_Consumed_Repo.php | 98 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 98 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 101 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 101 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 102 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 102 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 113 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 113 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 116 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 116 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 128 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 128 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 138 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 138 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 138 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $payments at &quot;SELECT status FROM `$payments` WHERE order_id=%d AND order_amount=%s FOR UPDATE&quot; |
| src/NMMPRO_Consumed_Repo.php | 147 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 147 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 147 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $payments at &quot;UPDATE `$payments` SET status=&#039;completing&#039;,tx_hash=%s WHERE order_id=%d AND order_amount=%s AND status=&#039;paid&#039;&quot; |
| src/NMMPRO_Consumed_Repo.php | 151 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 151 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 154 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 154 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 174 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 174 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 174 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 171. |
| src/NMMPRO_Consumed_Repo.php | 174 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT order_id FROM `$table` WHERE identity=%s&quot; |
| src/NMMPRO_Consumed_Repo.php | 212 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 212 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 229 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 229 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 232 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 232 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 233 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;UPDATE `$hdTable` SET `status` = &#039;completing&#039;, `last_checked` = UNIX_TIMESTAMP(), `credited_units` = %s\n |
| src/NMMPRO_Consumed_Repo.php | 243 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 243 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 243 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $hdTable at &quot;SELECT `status`, `order_id` FROM `$hdTable` WHERE `id` = %d FOR UPDATE&quot; |
| src/NMMPRO_Consumed_Repo.php | 245 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 245 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 250 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 250 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 253 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 253 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 273 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 273 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 287 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 287 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 290 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 290 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 298 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 298 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 299 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 299 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 299 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 297. |
| src/NMMPRO_Consumed_Repo.php | 299 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;DROP TABLE IF EXISTS `$table`&quot; |
| src/NMMPRO_Consumed_Repo.php | 299 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 41 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 41 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 42 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;INSERT INTO `$tableName` (`cryptocurrency`) VALUES &quot; |
| src/NMMPRO_Carousel_Repo.php | 42 | WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare | Replacement variables found, but no valid placeholders found in the query. |
| src/NMMPRO_Carousel_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 55 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $tableName at &quot;SELECT count(*) FROM `$tableName` WHERE `cryptocurrency` = %s&quot; |
| src/NMMPRO_Carousel_Repo.php | 107 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 107 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 108 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `current_index` FROM `$this-&gt;tableName` WHERE `cryptocurrency` = %s&quot; |
| src/NMMPRO_Carousel_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 137 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Carousel_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 164 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `current_index` = %d WHERE `cryptocurrency` = %s&quot; |
| src/NMMPRO_Carousel_Repo.php | 172 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 172 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 173 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `current_index` FROM `$this-&gt;tableName` WHERE `cryptocurrency` = %s&quot; |
| src/NMMPRO_Carousel_Repo.php | 186 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 186 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 187 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `buffer` = %s WHERE `cryptocurrency` = %s&quot; |
| src/NMMPRO_Carousel_Repo.php | 195 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 195 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 196 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `buffer` FROM `$this-&gt;tableName` WHERE `cryptocurrency` = %s&quot; |
| src/NMMPRO_Sol_Retry_Repo.php | 40 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 40 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 40 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;get_results()\n$t assigned unsafely at line 39. |
| src/NMMPRO_Sol_Retry_Repo.php | 41 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;SELECT `signature`, `first_failed_at`, `attempts`, `block_time` FROM `$t`\n |
| src/NMMPRO_Sol_Retry_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 59 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;query()\n$t assigned unsafely at line 58. |
| src/NMMPRO_Sol_Retry_Repo.php | 60 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;INSERT INTO `$t` (`address`, `signature`, `first_failed_at`, `attempts`, `next_retry_at`, `block_time`)\n |
| src/NMMPRO_Sol_Retry_Repo.php | 79 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 79 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 79 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;query()\n$t assigned unsafely at line 78. |
| src/NMMPRO_Sol_Retry_Repo.php | 80 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;UPDATE `$t` SET `attempts` = %d, `next_retry_at` = %d WHERE `address` = %s AND `signature` = %s&quot; |
| src/NMMPRO_Sol_Retry_Repo.php | 97 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 97 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 97 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;query()\n$t assigned unsafely at line 96. |
| src/NMMPRO_Sol_Retry_Repo.php | 98 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;DELETE FROM `$t` WHERE `address` = %s AND `signature` = %s&quot; |
| src/NMMPRO_Sol_Retry_Repo.php | 125 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 125 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 125 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;query()\n$t assigned unsafely at line 123. |
| src/NMMPRO_Sol_Retry_Repo.php | 126 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;DELETE FROM `$t` WHERE `address` = %s AND `block_time` &gt; 0 AND `block_time` &lt; %d LIMIT %d&quot; |
| src/NMMPRO_Sol_Retry_Repo.php | 134 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 134 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 134 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;query()\n$t assigned unsafely at line 123. |
| src/NMMPRO_Sol_Retry_Repo.php | 135 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;DELETE FROM `$t` WHERE `address` = %s AND `first_failed_at` &lt; %d LIMIT %d&quot; |
| src/NMMPRO_Sol_Retry_Repo.php | 158 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 158 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 158 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;query()\n$t assigned unsafely at line 157. |
| src/NMMPRO_Sol_Retry_Repo.php | 159 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;DELETE FROM `$t` WHERE `first_failed_at` &lt; %d LIMIT %d&quot; |
| src/NMMPRO_Sol_Retry_Repo.php | 199 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 199 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 199 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb-&gt;get_var()\n$t assigned unsafely at line 198. |
| src/NMMPRO_Sol_Retry_Repo.php | 200 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $t at &quot;SELECT COUNT(*) FROM `$t` WHERE `address` = %s&quot; |
| src/NMMPRO_Payment_Repo.php | 32 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 32 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 33 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;INSERT INTO `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 67 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 67 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 70 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 70 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 82 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 82 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 82 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT DISTINCT `cryptocurrency` FROM `$this-&gt;tableName` WHERE `status` = &#039;unpaid&#039;&quot; |
| src/NMMPRO_Payment_Repo.php | 95 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 95 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 97 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 132 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 132 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT COUNT(*) FROM (SELECT DISTINCT `cryptocurrency`, `address` FROM `$this-&gt;tableName` WHERE `status` = &#039;unpaid&#039;) t&quot; |
| src/NMMPRO_Payment_Repo.php | 170 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 170 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 172 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 187 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 187 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 189 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 209 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 209 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 211 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 244 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 244 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 267 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 267 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 268 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;DELETE FROM `$this-&gt;tableName` WHERE `order_id` = %d AND `status` = &#039;unpaid&#039;&quot; |
| src/NMMPRO_Payment_Repo.php | 281 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 281 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 282 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT COUNT(*) FROM `$this-&gt;tableName` WHERE `cryptocurrency` = %s AND `address` = %s&quot; |
| src/NMMPRO_Payment_Repo.php | 302 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 302 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 308 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 322 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 322 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 323 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 366 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 366 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 367 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 376 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 376 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 377 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 400 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 400 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 401 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `status`, `lease_gen` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 425 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 425 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 426 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `status`, `ordered_at` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 462 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 462 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 462 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;query()\n$sql assigned unsafely at line 460. |
| src/NMMPRO_Payment_Repo.php | 545 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 545 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 546 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 594 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 594 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 595 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 612 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 612 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 613 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Payment_Repo.php | 630 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 630 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 631 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd.php | 86 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd.php | 86 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd.php | 87 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT `id`, `order_id` FROM `$table` WHERE `status` = &#039;review&#039; AND `id` &gt; %d ORDER BY `id` LIMIT %d&quot; |
| src/NMMPRO_Hd.php | 112 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd.php | 112 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd.php | 113 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `status` = %s WHERE `id` = %d AND `status` = &#039;review&#039; AND `order_id` &lt;=&gt; %d&quot; |
| src/NMMPRO_Hd_Evidence_Repo.php | 47 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_row()\n$table assigned unsafely at line 44. |
| src/NMMPRO_Hd_Evidence_Repo.php | 47 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 47 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 48 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT `id`, `amount_units`, `state` FROM `$table` WHERE `hd_id` = %d AND `tx_hash` = %s AND `output_index` = %d&quot; |
| src/NMMPRO_Hd_Evidence_Repo.php | 59 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 44. |
| src/NMMPRO_Hd_Evidence_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 60 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;INSERT INTO `$table`\n |
| src/NMMPRO_Hd_Evidence_Repo.php | 69 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 44. |
| src/NMMPRO_Hd_Evidence_Repo.php | 69 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 69 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 70 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;INSERT INTO `$table`\n |
| src/NMMPRO_Hd_Evidence_Repo.php | 85 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 44. |
| src/NMMPRO_Hd_Evidence_Repo.php | 85 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 85 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 85 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `state` = %s WHERE `id` = %d&quot; |
| src/NMMPRO_Hd_Evidence_Repo.php | 95 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 44. |
| src/NMMPRO_Hd_Evidence_Repo.php | 95 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 95 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 96 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `confirmations` = %d, `block_height` = %d, `block_time` = %d, `observed_at` = %d, `source` = %s, `state` = %s\n |
| src/NMMPRO_Hd_Evidence_Repo.php | 102 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 44. |
| src/NMMPRO_Hd_Evidence_Repo.php | 102 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 102 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 103 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `confirmations` = %d, `block_height` = NULL, `block_time` = NULL, `observed_at` = %d, `source` = %s, `state` = %s\n |
| src/NMMPRO_Hd_Evidence_Repo.php | 136 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 126. |
| src/NMMPRO_Hd_Evidence_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 136 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `state` = %s WHERE `id` = %d&quot; |
| src/NMMPRO_Hd_Evidence_Repo.php | 147 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Evidence_Repo.php | 147 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Evidence_Repo.php | 147 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_results()\n$table assigned unsafely at line 146. |
| src/NMMPRO_Hd_Evidence_Repo.php | 150 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at \t\t\t FROM `$table` WHERE `hd_id` = %d ORDER BY `id`&quot; |
| src/NMMPRO_Hd_Verifier.php | 709 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Verifier.php | 709 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Verifier.php | 709 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;SELECT `mpk`, `hd_mode` FROM `$table` WHERE `id` = %d&quot; |
| src/NMMPRO_Hd_Repo.php | 70 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 70 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 71 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;INSERT INTO `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 98 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 98 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 99 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT COUNT(*) FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 115 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 115 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 116 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT MAX(`mpk_index`) FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 141 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 141 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 142 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;INSERT INTO `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 174 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 174 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 175 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `id` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 176 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $eligible at \t\t\t\t WHERE `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND $eligible\n |
| src/NMMPRO_Hd_Repo.php | 186 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 186 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 187 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 189 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $eligible at \t\t\t\t WHERE `id` = %d AND `mpk` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND $eligible&quot; |
| src/NMMPRO_Hd_Repo.php | 197 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 197 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 198 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `id`, `address`, `mpk_index` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 221 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 221 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 222 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 241 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 241 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 242 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT COUNT(*) FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 258 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 258 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 259 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 274 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 274 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 275 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 292 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 292 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 294 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at \t\t\t FROM `$table`\n |
| src/NMMPRO_Hd_Repo.php | 308 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 308 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 309 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `order_amount` = %s\n |
| src/NMMPRO_Hd_Repo.php | 317 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 317 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 318 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `order_amount` FROM `$this-&gt;tableName` WHERE `id` = %d AND `order_id` = %d AND `status` = &#039;assigned&#039;&quot; |
| src/NMMPRO_Hd_Repo.php | 331 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 331 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 332 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $table at &quot;UPDATE `$table` SET `status` = &#039;retired&#039;, `review_reason` = %s\n |
| src/NMMPRO_Hd_Repo.php | 344 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 344 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 345 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `status` = &#039;retired&#039;, `review_reason` = %s\n |
| src/NMMPRO_Hd_Repo.php | 361 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 361 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 362 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `id`, `address`, `order_id`, `order_amount`, `status`, `bound_at`, `validated_at`, `assigned_at`, `scan_state`, `credited_units`, `review_reason`, `last_checked` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 374 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 374 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 375 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `id`, `address`, `order_id`, `order_amount`, `status`, `bound_at`, `validated_at`, `assigned_at`, `scan_state`, `credited_units`, `review_reason`, `last_checked` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 394 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 394 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 396 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 411 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 411 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 412 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `scan_state` = %s, `last_checked` = UNIX_TIMESTAMP()\n |
| src/NMMPRO_Hd_Repo.php | 417 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 417 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 418 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `last_checked` = UNIX_TIMESTAMP()\n |
| src/NMMPRO_Hd_Repo.php | 427 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 427 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 428 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `scan_state` = %s, `last_checked` = UNIX_TIMESTAMP()\n |
| src/NMMPRO_Hd_Repo.php | 441 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 441 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 442 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `credited_units` = %s, `total_received` = %s\n |
| src/NMMPRO_Hd_Repo.php | 463 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 463 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 475 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 475 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 479 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at \t\t\t FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 522 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 522 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 523 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 560 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 560 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 561 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 581 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 581 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 582 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `order_id`, `address`, `order_amount`, `status`, `total_received` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 610 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 610 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 611 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;SELECT `order_id`, `address`, `assigned_at`, `total_received` FROM `$this-&gt;tableName`\n |
| src/NMMPRO_Hd_Repo.php | 635 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 635 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 636 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `total_received` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `mpk` = %s&quot; |
| src/NMMPRO_Hd_Repo.php | 652 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 652 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 653 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `order_amount` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `mpk` = %s&quot; |
| src/NMMPRO_Hd_Repo.php | 662 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 662 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 663 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `status` = %s, `assigned_at` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `mpk` = %s&quot; |
| src/NMMPRO_Hd_Repo.php | 668 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 668 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 669 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `status` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `mpk` = %s&quot; |
| src/NMMPRO_Hd_Repo.php | 679 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 679 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 680 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable $this-&gt;tableName at &quot;UPDATE `$this-&gt;tableName` SET `order_id` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d AND `mpk` = %s&quot; |
| src/NMMPRO_Cron.php | 31 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 31 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Cron.php | 50 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 50 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Cron.php | 54 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 54 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Cron.php | 177 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 177 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Admin.php | 72 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Admin.php | 72 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Log_Repo.php | 61 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Log_Repo.php | 61 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Log_Repo.php | 110 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Log_Repo.php | 110 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Compat.php | 28 | WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$old&quot;. |
| src/NMMPRO_Compat.php | 29 | WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$tag&quot;. |
| src/NMMPRO_Compat.php | 33 | WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$old&quot;. |
| src/NMMPRO_Compat.php | 34 | WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$tag&quot;. |
