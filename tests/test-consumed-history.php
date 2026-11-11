<?php
if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) { echo "test-consumed-history: skipped (requires WordPress DB)\n"; exit(0); }
require_once dirname(__DIR__) . '/src/NMMPRO_Consumed_Repo.php';
$ok = true; $coin = 'TEST'; $address = 'consumed-history-address';
NMMPRO_Consumed_Repo::drop();
for ($i = 0; $i < 250; $i++) NMMPRO_Consumed_Repo::add($coin, $address, 'tx_' . $i . str_repeat('x', 32));
$ok = $ok && NMMPRO_Consumed_Repo::contains($coin, $address, 'tx_0' . str_repeat('x', 32));
$ok = $ok && !NMMPRO_Consumed_Repo::contains($coin, 'other-address', 'tx_0' . str_repeat('x', 32));
NMMPRO_Consumed_Repo::add_many($coin, $address, array('atomic_a' . str_repeat('a', 32), 'atomic_b' . str_repeat('b', 32)));
$ok = $ok && NMMPRO_Consumed_Repo::contains($coin, $address, 'atomic_a' . str_repeat('a', 32));
$check = function ($label, $pass) use (&$ok) { echo str_pad($label, 72) . ($pass ? 'ok' : 'FAIL') . "\n"; $ok = $ok && $pass; };

// EVM hashes (0x + 64 hex) are one transaction in any case: a hash recorded in
// one case is found in another, whichever EVM source served it.
$evm = '0x' . str_repeat('Ab', 32);
NMMPRO_Consumed_Repo::add('USDTPOL', '0xaddr', $evm);
$check('an EVM hash recorded in mixed case is found in lower case', NMMPRO_Consumed_Repo::contains('USDTPOL', '0xaddr', strtolower($evm)));
$check('  and in upper case', NMMPRO_Consumed_Repo::contains('USDTPOL', '0xaddr', '0x' . strtoupper(substr($evm, 2))));
$check('  but not for another address', !NMMPRO_Consumed_Repo::contains('USDTPOL', '0xother', strtolower($evm)));
$other = 'AbCdEf' . str_repeat('1', 58); // not an EVM hash: case is significant
NMMPRO_Consumed_Repo::add('XMR', 'addr', $other);
$check('another coin\'s hash is still matched exactly', NMMPRO_Consumed_Repo::contains('XMR', 'addr', $other) && !NMMPRO_Consumed_Repo::contains('XMR', 'addr', strtolower($other)));

// A row recorded by an earlier release, whose identity came from the hash
// exactly as served, gets its folded twin before anything is read.
global $wpdb;
$table = NMMPRO_Consumed_Repo::table();
$legacy = '0x' . str_repeat('Cd', 32);
$legacyIdentity = hash('sha256', json_encode(array('USDCBAS', '0xlegacy', $legacy)));
$wpdb->query($wpdb->prepare("INSERT INTO `$table` (identity,transaction_hash,address,coin,order_id,created_at) VALUES (%s,%s,'0xlegacy','USDCBAS',4242,1)", $legacyIdentity, $legacy));
delete_option(NMMPRO_Consumed_Repo::CASE_MIGRATION);
$ready = new ReflectionProperty('NMMPRO_Consumed_Repo', 'ready');
$ready->setAccessible(true);
$ready->setValue(null, array());
$check('a legacy mixed-case identity is found in lower case after the upgrade', NMMPRO_Consumed_Repo::contains('USDCBAS', '0xlegacy', strtolower($legacy)));
$check('  its twin keeps the original owner', NMMPRO_Consumed_Repo::owners('USDCBAS', '0xlegacy', array(strtolower($legacy))) === array(strtolower($legacy) => 4242));
$check('  and the folding is marked done', get_option(NMMPRO_Consumed_Repo::CASE_MIGRATION) === '1');
$ready->setValue(null, array());
delete_option(NMMPRO_Consumed_Repo::CASE_MIGRATION);
$rowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
NMMPRO_Consumed_Repo::contains('USDCBAS', '0xlegacy', $legacy);
$check('  running it again adds nothing', (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`") === $rowsBefore);

// A request that loses the race to set the folding marker: its options cache
// still says "absent" while another request has stored it. Its work is done
// (the folding is idempotent), so it must carry on, not throw.
delete_option(NMMPRO_Consumed_Repo::CASE_MIGRATION);
$ready->setValue(null, array());
get_option(NMMPRO_Consumed_Repo::CASE_MIGRATION); // caches "absent" in this request
// Stored exactly as the winner's update_option() stores it, so this request's
// own write changes no row (as in a real race) and refreshes no cache.
$winnerAutoload = function_exists('wp_autoload_values_to_autoload') ? 'on' : 'yes';
$wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, '1', %s)", NMMPRO_Consumed_Repo::CASE_MIGRATION, $winnerAutoload));
try {
    $found = NMMPRO_Consumed_Repo::contains('USDCBAS', '0xlegacy', strtolower($legacy));
    $check('a request that lost the race to set the folding marker carries on', $found === true);
} catch (Throwable $e) {
    $check('a request that lost the race to set the folding marker carries on (' . $e->getMessage() . ')', false);
}
$check('  and then reads the marker as set', get_option(NMMPRO_Consumed_Repo::CASE_MIGRATION) === '1');

// The same race on an address's history-import marker.
$raceAddress = 'consumed-history-race';
$importMarker = 'nmmpro_history_import_' . hash('sha256', $coin . '|' . $raceAddress);
NMMPRO_Consumed_Repo::add($coin, $raceAddress, 'race_tx' . str_repeat('r', 32));
delete_option($importMarker);
get_option($importMarker); // caches "absent" in this request
// As stored by update_option($marker, '1', false), so this request's write changes no row.
$wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, '1', %s)", $importMarker, function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no'));
try {
    $found = NMMPRO_Consumed_Repo::contains($coin, $raceAddress, 'race_tx' . str_repeat('r', 32));
    $check('a request that lost the race to set an import marker carries on', $found === true);
} catch (Throwable $e) {
    $check('a request that lost the race to set an import marker carries on (' . $e->getMessage() . ')', false);
}
$check('  and then reads the marker as set', get_option($importMarker) === '1');

echo $ok ? "CONSUMED-HISTORY CHECKS PASSED\n" : "CONSUMED-HISTORY CHECKS FAILED\n";
exit($ok ? 0 : 1);
