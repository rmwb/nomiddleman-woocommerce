# Plugin Check warning triage — 24 September 2026 (revised)

The packaged scan of 6 September contained 342 warnings and zero errors after that round's fixes (missing stylesheet version, HTML-wrapped translation and three verbose data-dump log calls). This revision re-scans the payload after the read-only Status screen and three rounds of Autopay safety changes: still zero errors, 403 warnings, every one of them in the categories below. It does not hide the database warnings.

- **140 direct queries / 137 no-caching notices:** these predominantly operate on plugin-owned payment, HD-address, address-rotation and retry tables. Conditional claims, current status checks and retry selection must observe current database state. WordPress's order APIs are still used for WooCommerce orders. Adding a cache to these claim paths would undermine their concurrency guarantees. Read-only reporting queries can be optimized separately with explicit invalidation.
- **91 interpolation notices:** the reported variables are table identifiers or `$def`. Constructors/helpers derive identifiers from `$wpdb->prefix` plus fixed plugin constants. The three `$def` instances are selected from local literal index-definition arrays. Values (order IDs, addresses, amounts and hashes) are passed through placeholders. The duplicate-HD reconciliation helper is called with the internally built HD table name. No request input was found reaching these interpolated identifiers or index definitions in this review.
- **Added since 6 September (27 direct-query, 24 no-caching, 7 interpolation, 2 unescaped-parameter, 1 schema-change):**
  - **Cron fence:** advisory-lock ownership checks in NMMPRO_Util: `GET_LOCK`, `IS_USED_LOCK`, `RELEASE_LOCK`, and the fenced option write. That write is an `INSERT ... SELECT ... WHERE IS_USED_LOCK() = CONNECTION_ID()` on the options table, so it only lands while the cron lock is held.
  - **Payment-record changes:** conditional lease claims, generation-checked settlements (`lease_gen`) and under-lock expiry re-reads on the plugin's payment table (NMMPRO_Payment_Repo, NMMPRO_Payment), plus per-address expiry deferrals: one options row each, paged with an option_id cursor and deleted with compare-and-delete.
  - **Authoritative order reads:** an existence check for an order in WooCommerce's own storage (posts or the HPOS orders table), used only after WooCommerce returned no order.
  - **Payment-claim transaction:** a strict INSERT and a row-locked status read inside it (NMMPRO_Consumed_Repo).
  - **Uninstall:** removes the deferral rows by prefix.
  - **Status screen:** read-only queries (NMMPRO_Payment_Repo backlog, and NMMPRO_Log_Repo reading WooCommerce's log table only after confirming it exists).

  Lock, claim and settlement queries must see the live database state, so none of them can be cached. Every value is bound through placeholders. The interpolated identifiers are table names built from `$wpdb->prefix` and plugin constants, or supplied by WooCommerce.
- **14 unescaped-parameter notices:** repeat the table-identifier cases above in the consumed ledger, retry repository and duplicate-HD migration, plus the HPOS orders table name that NMMPRO_Payment reads from WooCommerce's own `OrderUtil::get_table_for_orders()` when confirming whether an order exists, and the payment table name in the `lease_gen` migration's column check and ALTER.
- **14 schema-change notices:** installation, repair (including the verify-then-record migration that adds the payment table's `lease_gen` column) and uninstall intentionally create/alter/drop the plugin's own tables. These must remain scoped to the current site prefix.
- **One unfinished-prepare notice:** Carousel_Repo builds a list of literal `(%s)` placeholders and passes every currency as a separate bound value; the scanner cannot infer those placeholders across the concatenation.
- **Four dynamic-hook notices:** the compatibility wrapper deliberately fires the old and new names. Callers pass fixed plugin hook names.
- **Translation loading and error_log fallback:** retained for bundled translations/older WordPress support and operational failure logging when the WooCommerce logger is unavailable. Debug messages are gated and entries bounded/throttled.

Identifier placeholders introduced in newer WordPress versions are not used unconditionally because the plugin still declares WordPress 5.3 support. This is a source review and scoped justification, not a blanket guarantee that every database access is safe. No blanket suppression was added.

## Scan locations retained for reviewer inspection

| File | Line | Warning | Message |
|---|---:|---|---|
| nomiddleman-crypto-woocommerce.php | 314 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 314 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 370 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 370 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 393 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 393 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 395 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 417 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 417 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 425 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 425 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 425 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW COLUMNS FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 439 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 439 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 439 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 445 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 445 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 446 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "DELETE t1 FROM `$tableName` t1\n |
| nomiddleman-crypto-woocommerce.php | 447 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at                  INNER JOIN `$tableName` t2\n |
| nomiddleman-crypto-woocommerce.php | 451 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 451 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 451 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 451 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $def at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 451 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 454 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 454 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 454 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 490 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 490 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 543 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 543 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 555 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 555 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 555 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 564 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 564 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 564 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 572 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 572 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 572 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 581 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 581 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 581 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 616 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 616 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 628 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 628 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 628 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW COLUMNS FROM `$tableName` LIKE 'hd_mode'" |
| nomiddleman-crypto-woocommerce.php | 630 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 630 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 630 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` ADD `hd_mode` bigint(10) NOT NULL default '0'" |
| nomiddleman-crypto-woocommerce.php | 630 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 633 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 633 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 633 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW COLUMNS FROM `$tableName` LIKE 'hd_mode'" |
| nomiddleman-crypto-woocommerce.php | 657 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 657 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 657 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'hd_address'" |
| nomiddleman-crypto-woocommerce.php | 659 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 659 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 659 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` ADD UNIQUE KEY `hd_address` (`cryptocurrency`, `address`)" |
| nomiddleman-crypto-woocommerce.php | 659 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 666 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 666 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 666 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'hd_address'" |
| nomiddleman-crypto-woocommerce.php | 679 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 679 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 679 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'status_checked'" |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` ADD KEY `status_checked` (`status`, `last_checked`)" |
| nomiddleman-crypto-woocommerce.php | 681 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 684 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 684 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 684 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'status_checked'" |
| nomiddleman-crypto-woocommerce.php | 704 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 704 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 704 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 707 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 707 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 707 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 707 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $def at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 707 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 711 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 711 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 711 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 728 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 728 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 728 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb->get_results() |
| nomiddleman-crypto-woocommerce.php | 729 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT cryptocurrency, address FROM `$tableName`\n |
| nomiddleman-crypto-woocommerce.php | 734 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 734 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 734 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb->get_results() |
| nomiddleman-crypto-woocommerce.php | 735 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT id, status, order_id, total_received FROM `$tableName`\n |
| nomiddleman-crypto-woocommerce.php | 758 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 758 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 808 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 808 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 822 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 822 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 830 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 830 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 830 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 833 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 833 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 833 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 833 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $def at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 833 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 837 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 837 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 837 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 860 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 860 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 865 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 865 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 865 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb->get_results() |
| nomiddleman-crypto-woocommerce.php | 868 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 868 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 868 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 893 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 893 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 1073 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 1073 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hooks.php | 133 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hooks.php | 133 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hooks.php | 134 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT `status`, `total_received`, `order_amount` FROM `$tableName` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1" |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT * FROM `$table` WHERE status='completing' AND id>%d ORDER BY id LIMIT 25" |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT status FROM `$table` WHERE id=%d" |
| src/NMMPRO_Payment.php | 119 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 119 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 119 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->get_var()\n$sql assigned unsafely at line 114. |
| src/NMMPRO_Payment.php | 231 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 231 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 241 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 241 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 241 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT status FROM `$table` WHERE id=%d" |
| src/NMMPRO_Payment.php | 818 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 818 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 837 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 837 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 867 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 867 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 882 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 157 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 157 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 160 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 160 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 227 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 237 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 280 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 280 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 294 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 294 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 303 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 303 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 331 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 331 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 337 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 337 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 378 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 378 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 393 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 393 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 46 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)\n |
| src/NMMPRO_Consumed_Repo.php | 50 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)\n |
| src/NMMPRO_Consumed_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 69 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 69 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 69 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $payments at "SELECT order_id,tx_hash FROM `$payments`\n |
| src/NMMPRO_Consumed_Repo.php | 86 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 86 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 86 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb->get_var()\n$table assigned unsafely at line 85. |
| src/NMMPRO_Consumed_Repo.php | 86 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT identity FROM `$table` WHERE identity=%s" |
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
| src/NMMPRO_Consumed_Repo.php | 138 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $payments at "SELECT status FROM `$payments` WHERE order_id=%d AND order_amount=%s FOR UPDATE" |
| src/NMMPRO_Consumed_Repo.php | 147 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 147 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 147 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $payments at "UPDATE `$payments` SET status='completing',tx_hash=%s WHERE order_id=%d AND order_amount=%s AND status='paid'" |
| src/NMMPRO_Consumed_Repo.php | 151 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 151 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 154 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 154 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 162 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 162 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 163 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb->query()\n$table assigned unsafely at line 161. |
| src/NMMPRO_Consumed_Repo.php | 163 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "DROP TABLE IF EXISTS `$table`" |
| src/NMMPRO_Consumed_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 41 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 41 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 42 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "INSERT INTO `$tableName` (`cryptocurrency`) VALUES " |
| src/NMMPRO_Carousel_Repo.php | 42 | WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare | Replacement variables found, but no valid placeholders found in the query. |
| src/NMMPRO_Carousel_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 54 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 55 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT count(*) FROM `$tableName` WHERE `cryptocurrency` = %s" |
| src/NMMPRO_Carousel_Repo.php | 107 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 107 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 108 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `current_index` FROM `$this->tableName` WHERE `cryptocurrency` = %s" |
| src/NMMPRO_Carousel_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 137 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Carousel_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 163 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 164 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `current_index` = %d WHERE `cryptocurrency` = %s" |
| src/NMMPRO_Carousel_Repo.php | 172 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 172 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 173 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `current_index` FROM `$this->tableName` WHERE `cryptocurrency` = %s" |
| src/NMMPRO_Carousel_Repo.php | 186 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 186 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 187 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `buffer` = %s WHERE `cryptocurrency` = %s" |
| src/NMMPRO_Carousel_Repo.php | 195 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Carousel_Repo.php | 195 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Carousel_Repo.php | 196 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `buffer` FROM `$this->tableName` WHERE `cryptocurrency` = %s" |
| src/NMMPRO_Sol_Retry_Repo.php | 40 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 40 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 40 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->get_results()\n$t assigned unsafely at line 39. |
| src/NMMPRO_Sol_Retry_Repo.php | 41 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "SELECT `signature`, `first_failed_at`, `attempts`, `block_time` FROM `$t`\n |
| src/NMMPRO_Sol_Retry_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 59 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->query()\n$t assigned unsafely at line 58. |
| src/NMMPRO_Sol_Retry_Repo.php | 60 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "INSERT INTO `$t` (`address`, `signature`, `first_failed_at`, `attempts`, `next_retry_at`, `block_time`)\n |
| src/NMMPRO_Sol_Retry_Repo.php | 79 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 79 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 79 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->query()\n$t assigned unsafely at line 78. |
| src/NMMPRO_Sol_Retry_Repo.php | 80 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "UPDATE `$t` SET `attempts` = %d, `next_retry_at` = %d WHERE `address` = %s AND `signature` = %s" |
| src/NMMPRO_Sol_Retry_Repo.php | 97 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 97 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 97 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->query()\n$t assigned unsafely at line 96. |
| src/NMMPRO_Sol_Retry_Repo.php | 98 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "DELETE FROM `$t` WHERE `address` = %s AND `signature` = %s" |
| src/NMMPRO_Sol_Retry_Repo.php | 125 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 125 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 125 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->query()\n$t assigned unsafely at line 123. |
| src/NMMPRO_Sol_Retry_Repo.php | 126 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "DELETE FROM `$t` WHERE `address` = %s AND `block_time` > 0 AND `block_time` < %d LIMIT %d" |
| src/NMMPRO_Sol_Retry_Repo.php | 134 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 134 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 134 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->query()\n$t assigned unsafely at line 123. |
| src/NMMPRO_Sol_Retry_Repo.php | 135 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "DELETE FROM `$t` WHERE `address` = %s AND `first_failed_at` < %d LIMIT %d" |
| src/NMMPRO_Sol_Retry_Repo.php | 158 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 158 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 158 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->query()\n$t assigned unsafely at line 157. |
| src/NMMPRO_Sol_Retry_Repo.php | 159 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "DELETE FROM `$t` WHERE `first_failed_at` < %d LIMIT %d" |
| src/NMMPRO_Sol_Retry_Repo.php | 199 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Sol_Retry_Repo.php | 199 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Sol_Retry_Repo.php | 199 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $t used in $wpdb->get_var()\n$t assigned unsafely at line 198. |
| src/NMMPRO_Sol_Retry_Repo.php | 200 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $t at "SELECT COUNT(*) FROM `$t` WHERE `address` = %s" |
| src/NMMPRO_Payment_Repo.php | 32 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 32 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 33 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "INSERT INTO `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 67 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 67 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 70 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 70 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 82 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 82 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 82 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT DISTINCT `cryptocurrency` FROM `$this->tableName` WHERE `status` = 'unpaid'" |
| src/NMMPRO_Payment_Repo.php | 95 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 95 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 97 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 132 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 132 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT COUNT(*) FROM (SELECT DISTINCT `cryptocurrency`, `address` FROM `$this->tableName` WHERE `status` = 'unpa |
| src/NMMPRO_Payment_Repo.php | 170 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 170 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 172 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 187 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 187 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 189 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 209 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 209 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 211 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 244 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 244 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 267 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 267 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 268 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "DELETE FROM `$this->tableName` WHERE `order_id` = %d AND `status` = 'unpaid'" |
| src/NMMPRO_Payment_Repo.php | 281 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 281 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 282 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT COUNT(*) FROM `$this->tableName` WHERE `cryptocurrency` = %s AND `address` = %s" |
| src/NMMPRO_Payment_Repo.php | 302 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 302 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 308 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 322 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 322 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 323 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 353 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 353 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 354 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 373 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 373 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 374 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `status`, `lease_gen` FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 398 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 398 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 399 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `status`, `ordered_at` FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 421 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 421 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 422 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 453 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 453 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 454 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 502 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 502 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 503 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 520 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 520 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 521 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 538 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 538 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 539 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 59 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 60 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "INSERT INTO `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 87 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 87 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 88 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT COUNT(*) FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 103 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 103 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 104 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT MAX(`mpk_index`) FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 122 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 122 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 123 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `address` FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 150 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 150 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 151 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `id` FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 165 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 165 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 166 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 173 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 173 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 174 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `address` FROM `$this->tableName` WHERE `id` = %d" |
| src/NMMPRO_Hd_Repo.php | 214 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 214 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 215 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 252 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 252 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 253 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 271 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 271 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 272 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `order_id`, `address`, `order_amount`, `status`, `total_received` FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 298 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 298 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 299 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `order_id`, `address`, `assigned_at`, `total_received` FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 317 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 317 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 318 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT `order_id`, `address`, `status`, `last_checked`, `total_received` FROM `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 335 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 335 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 336 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `status` = %s, `last_checked` = %d WHERE `address` = %s AND `cryptocurrency` = %s  |
| src/NMMPRO_Hd_Repo.php | 349 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 349 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 350 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 369 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 369 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 370 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `total_received` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` |
| src/NMMPRO_Hd_Repo.php | 386 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 386 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 387 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `order_amount` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = |
| src/NMMPRO_Hd_Repo.php | 396 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 396 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 397 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `status` = %s, `assigned_at` = %d WHERE `address` = %s AND `cryptocurrency` = %s A |
| src/NMMPRO_Hd_Repo.php | 402 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 402 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 403 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `status` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Hd_Repo.php | 413 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 413 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 414 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `order_id` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Cron.php | 31 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 31 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Cron.php | 146 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 146 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Log_Repo.php | 61 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Log_Repo.php | 61 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Log_Repo.php | 110 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Log_Repo.php | 110 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
