# Plugin Check warning triage — 6 September 2026

The previous packaged scan contained 347 warnings and zero errors. This round fixes the missing stylesheet version, HTML-wrapped translation and three verbose data-dump log calls. It does not hide the database warnings.

- **113 direct queries / 113 no-caching notices:** these predominantly operate on plugin-owned payment, HD-address, address-rotation and retry tables. Conditional claims, current status checks and retry selection must observe current database state. WordPress's order APIs are still used for WooCommerce orders. Adding a cache to these claim paths would undermine their concurrency guarantees. Read-only reporting queries can be optimized separately with explicit invalidation.
- **84 interpolation notices:** the reported variables are table identifiers or `$def`. Constructors/helpers derive identifiers from `$wpdb->prefix` plus fixed plugin constants. The three `$def` instances are selected from local literal index-definition arrays. Values (order IDs, addresses, amounts and hashes) are passed through placeholders. The duplicate-HD reconciliation helper is called with the internally built HD table name. No request input was found reaching these interpolated identifiers or index definitions in this review.
- **12 unescaped-parameter notices:** repeat the table-identifier cases above in the consumed ledger, retry repository and duplicate-HD migration.
- **13 schema-change notices:** installation, repair and uninstall intentionally create/alter/drop the plugin's own tables. These must remain scoped to the current site prefix.
- **One unfinished-prepare notice:** Carousel_Repo builds a list of literal `(%s)` placeholders and passes every currency as a separate bound value; the scanner cannot infer those placeholders across the concatenation.
- **Four dynamic-hook notices:** the compatibility wrapper deliberately fires the old and new names. Callers pass fixed plugin hook names.
- **Translation loading and error_log fallback:** retained for bundled translations/older WordPress support and operational failure logging when the WooCommerce logger is unavailable. Debug messages are gated and entries bounded/throttled.

Identifier placeholders introduced in newer WordPress versions are not used unconditionally because the plugin still declares WordPress 5.3 support. This is a source review and scoped justification, not a blanket guarantee that every database access is safe. No blanket suppression was added.

## Scan locations retained for reviewer inspection

| File | Line | Warning | Message |
|---|---:|---|---|
| nomiddleman-crypto-woocommerce.php | 312 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 312 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 368 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 368 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 391 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 391 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 393 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 415 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 415 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 423 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 423 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 423 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW COLUMNS FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 437 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 437 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 437 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 443 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 443 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 444 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "DELETE t1 FROM `$tableName` t1\n |
| nomiddleman-crypto-woocommerce.php | 445 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at                  INNER JOIN `$tableName` t2\n |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $def at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 449 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 452 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 452 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 452 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 488 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 488 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 539 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 539 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 539 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 548 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 548 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 548 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 556 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 556 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 556 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 564 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 564 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 564 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 599 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 599 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 611 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 611 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 611 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW COLUMNS FROM `$tableName` LIKE 'hd_mode'" |
| nomiddleman-crypto-woocommerce.php | 613 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 613 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 613 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` ADD `hd_mode` bigint(10) NOT NULL default '0'" |
| nomiddleman-crypto-woocommerce.php | 613 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 616 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 616 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 616 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW COLUMNS FROM `$tableName` LIKE 'hd_mode'" |
| nomiddleman-crypto-woocommerce.php | 640 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 640 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 640 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'hd_address'" |
| nomiddleman-crypto-woocommerce.php | 642 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 642 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 642 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` ADD UNIQUE KEY `hd_address` (`cryptocurrency`, `address`)" |
| nomiddleman-crypto-woocommerce.php | 642 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 649 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 649 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 649 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'hd_address'" |
| nomiddleman-crypto-woocommerce.php | 662 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 662 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 662 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'status_checked'" |
| nomiddleman-crypto-woocommerce.php | 664 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 664 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 664 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` ADD KEY `status_checked` (`status`, `last_checked`)" |
| nomiddleman-crypto-woocommerce.php | 664 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 667 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 667 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 667 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName` WHERE Key_name = 'status_checked'" |
| nomiddleman-crypto-woocommerce.php | 687 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 687 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 687 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 690 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 690 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 690 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 690 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $def at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 690 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 694 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 694 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 694 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 711 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 711 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 711 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb->get_results() |
| nomiddleman-crypto-woocommerce.php | 712 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT cryptocurrency, address FROM `$tableName`\n |
| nomiddleman-crypto-woocommerce.php | 717 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 717 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 717 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $tableName used in $wpdb->get_results() |
| nomiddleman-crypto-woocommerce.php | 718 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT id, status, order_id, total_received FROM `$tableName`\n |
| nomiddleman-crypto-woocommerce.php | 741 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 741 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 790 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 790 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 804 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 804 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 812 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 812 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 812 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 815 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 815 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 815 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 815 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $def at "ALTER TABLE `$tableName` $def" |
| nomiddleman-crypto-woocommerce.php | 815 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| nomiddleman-crypto-woocommerce.php | 819 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 819 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 819 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SHOW INDEX FROM `$tableName`" |
| nomiddleman-crypto-woocommerce.php | 842 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 842 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| nomiddleman-crypto-woocommerce.php | 1022 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| nomiddleman-crypto-woocommerce.php | 1022 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hooks.php | 133 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hooks.php | 133 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hooks.php | 134 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $tableName at "SELECT `status`, `total_received`, `order_amount` FROM `$tableName` WHERE `order_id` = %d ORDER BY `id` DESC LIMIT 1" |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 18 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT * FROM `$table` WHERE status='completing' AND id>%d ORDER BY id LIMIT 25" |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment.php | 25 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT status FROM `$table` WHERE id=%d" |
| src/NMMPRO_Util.php | 145 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 145 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 151 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 151 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 183 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 183 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Util.php | 189 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Util.php | 189 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 27 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 36 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at)\n |
| src/NMMPRO_Consumed_Repo.php | 40 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 40 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 55 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 55 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 55 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $payments at "SELECT order_id,tx_hash FROM `$payments`\n |
| src/NMMPRO_Consumed_Repo.php | 72 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 72 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 72 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb->get_var()\n$table assigned unsafely at line 71. |
| src/NMMPRO_Consumed_Repo.php | 72 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "SELECT identity FROM `$table` WHERE identity=%s" |
| src/NMMPRO_Consumed_Repo.php | 84 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 84 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 87 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 87 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 88 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 88 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 99 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 99 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 101 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 101 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 107 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 107 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 107 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $payments at "UPDATE `$payments` SET status='completing',tx_hash=%s WHERE order_id=%d AND order_amount=%s AND status='paid'" |
| src/NMMPRO_Consumed_Repo.php | 111 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 111 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 114 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 114 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 122 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 122 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 123 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Consumed_Repo.php | 123 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Consumed_Repo.php | 123 | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $table used in $wpdb->query()\n$table assigned unsafely at line 121. |
| src/NMMPRO_Consumed_Repo.php | 123 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $table at "DROP TABLE IF EXISTS `$table`" |
| src/NMMPRO_Consumed_Repo.php | 123 | WordPress.DB.DirectDatabaseQuery.SchemaChange | Attempting a database schema change is discouraged. |
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
| src/NMMPRO_Payment_Repo.php | 119 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 119 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 119 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT COUNT(*) FROM (SELECT DISTINCT `cryptocurrency`, `address` FROM `$this->tableName` WHERE `status` = 'unpaid') t" |
| src/NMMPRO_Payment_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 136 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 138 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 153 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 155 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 175 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 175 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 177 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 210 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 210 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 233 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 233 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 234 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "DELETE FROM `$this->tableName` WHERE `order_id` = %d AND `status` = 'unpaid'" |
| src/NMMPRO_Payment_Repo.php | 247 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 247 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 248 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "SELECT COUNT(*) FROM `$this->tableName` WHERE `cryptocurrency` = %s AND `address` = %s" |
| src/NMMPRO_Payment_Repo.php | 268 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 268 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 274 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at \t\t\t FROM `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 288 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 288 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 289 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 318 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 318 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 319 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 352 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 352 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 353 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 370 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 370 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 371 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Payment_Repo.php | 384 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Payment_Repo.php | 384 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Payment_Repo.php | 385 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
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
| src/NMMPRO_Hd_Repo.php | 336 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `status` = %s, `last_checked` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Hd_Repo.php | 349 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 349 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 350 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName`\n |
| src/NMMPRO_Hd_Repo.php | 369 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 369 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 370 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `total_received` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Hd_Repo.php | 386 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 386 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 387 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `order_amount` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Hd_Repo.php | 396 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 396 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 397 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `status` = %s, `assigned_at` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Hd_Repo.php | 402 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 402 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 403 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `status` = %s WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Hd_Repo.php | 413 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Hd_Repo.php | 413 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Hd_Repo.php | 414 | WordPress.DB.PreparedSQL.InterpolatedNotPrepared | Use placeholders and $wpdb->prepare(); found interpolated variable $this->tableName at "UPDATE `$this->tableName` SET `order_id` = %d WHERE `address` = %s AND `cryptocurrency` = %s AND `hd_mode` = %d" |
| src/NMMPRO_Cron.php | 22 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 22 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
| src/NMMPRO_Cron.php | 106 | WordPress.DB.DirectDatabaseQuery.DirectQuery | Use of a direct database call is discouraged. |
| src/NMMPRO_Cron.php | 106 | WordPress.DB.DirectDatabaseQuery.NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete(). |
