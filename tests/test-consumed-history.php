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
echo $ok ? "CONSUMED-HISTORY CHECKS PASSED\n" : "CONSUMED-HISTORY CHECKS FAILED\n";
exit($ok ? 0 : 1);
