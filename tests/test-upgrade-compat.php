<?php
if (!isset($GLOBALS['wpdb']) || !function_exists('wc_create_order')) { echo "test-upgrade-compat: skipped (requires WordPress/WooCommerce DB)\n"; return; }
function nmmpro_upgrade_ok($pass, $message) { if (!$pass) { throw new RuntimeException($message); } echo "ok: $message\n"; }
$key = 'upgrade_test_' . wp_generate_password(10, false);
$old = 'nmm_' . $key; $new = 'nmmpro_' . $key;
update_option($old, array('cursor'=>42), false);
nmmpro_upgrade_ok(NMMPRO_Compat::get_option($new) === array('cursor'=>42), 'legacy option readable');
nmmpro_upgrade_ok(get_option($new) === array('cursor'=>42), 'legacy option copied');
NMMPRO_Compat::update_option($new, array('cursor'=>43), false);
nmmpro_upgrade_ok(NMMPRO_Compat::get_option($new) === array('cursor'=>43), 'canonical option wins');
NMMPRO_Compat::delete_option($new);
nmmpro_upgrade_ok(get_option($new, null) === null && get_option($old, null) === null, 'deletion removes both versions');
$oldFilter = function($value) { return $value . '-old'; };
$newFilter = function($value) { return $value . '-new'; };
add_filter('nmm_' . $key, $oldFilter); add_filter('nmmpro_' . $key, $newFilter);
nmmpro_upgrade_ok(NMMPRO_Compat::filter('nmmpro_' . $key,'value') === 'value-old-new', 'legacy and new filters are honored');
remove_filter('nmm_' . $key,$oldFilter); remove_filter('nmmpro_' . $key,$newFilter);
define('NMM_TEST_UPGRADE_CONFIG','old');
nmmpro_upgrade_ok(NMMPRO_Compat::config('NMMPRO_TEST_UPGRADE_CONFIG') === 'old', 'legacy configuration fallback');
define('NMMPRO_TEST_UPGRADE_CONFIG','new');
nmmpro_upgrade_ok(NMMPRO_Compat::config('NMMPRO_TEST_UPGRADE_CONFIG') === 'new', 'canonical configuration wins');
wp_schedule_event(time()+300,'hourly','NMM_cron_hook');
as_schedule_recurring_action(time()+300,60,'NMM_cron_hook',array(),'nomiddleman');
NMMPRO_schedule_payment_checks();
nmmpro_upgrade_ok(as_next_scheduled_action('NMMPRO_cron_hook',array(),'nomiddleman') !== false, 'new scheduler installed');
nmmpro_upgrade_ok(as_next_scheduled_action('NMM_cron_hook',array(),'nomiddleman') === false && !wp_next_scheduled('NMM_cron_hook'), 'old schedules retired after replacement');
nmmpro_upgrade_ok(has_action('wp_ajax_nopriv_nmm_order_status') !== false && has_action('wp_ajax_nopriv_nmmpro_order_status') !== false, 'old and new customer AJAX registered');
$temp = sys_get_temp_dir() . '/nmmpro-extension-' . wp_generate_password(10, false);
mkdir($temp . '/sample', 0700, true);
file_put_contents($temp . '/sample/NMM_Sample.php', '<?php // legacy extension fixture');
try {
    nmmpro_upgrade_ok(NMMPRO_extension_file($temp, 'sample') === $temp . '/sample/NMM_Sample.php', 'legacy extension filename discovered');
    nmmpro_upgrade_ok(NMMPRO_extension_file($temp, '../sample') === '', 'extension traversal rejected');
    file_put_contents($temp . '/sample/NMMPRO_Sample.php', '<?php // current extension fixture');
    nmmpro_upgrade_ok(NMMPRO_extension_file($temp, 'sample') === $temp . '/sample/NMMPRO_Sample.php', 'canonical extension filename preferred');
} finally {
    unlink($temp . '/sample/NMM_Sample.php');
    if (file_exists($temp . '/sample/NMMPRO_Sample.php')) { unlink($temp . '/sample/NMMPRO_Sample.php'); }
    rmdir($temp . '/sample'); rmdir($temp);
}
echo "UPGRADE-COMPAT CHECKS PASSED\n";

if ((new NMMPRO_Gateway())->id !== 'nmm_gateway') { throw new RuntimeException('Persisted gateway identity changed'); }
