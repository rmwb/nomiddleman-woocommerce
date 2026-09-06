<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
require_once __DIR__ . '/../src/NMMPRO_Amount.php';
$checks = 0;
function nmmpro_amount_check($actual, $expected, $label) {
    $GLOBALS['checks']++;
    if ($actual !== $expected) { throw new Exception($label . ': got ' . var_export($actual, true) . ', wanted ' . var_export($expected, true)); }
}
foreach (array('100.1'=>'100.1','100.00'=>'100','0.000'=>'0','1.25e3'=>'1250','1e-18'=>'0.000000000000000001') as $input=>$expected) {
    nmmpro_amount_check(NMMPRO_Amount::normalize($input), $expected, 'normalize');
}
foreach (array(6,8,12,18) as $precision) {
    $units = '100' . str_repeat('0', $precision - 1) . '1';
    nmmpro_amount_check(NMMPRO_Amount::to_units(NMMPRO_Amount::from_units($units, $precision), $precision), $units, 'roundtrip');
}
nmmpro_amount_check(NMMPRO_Amount::from_units('10000000000', 8), '100', 'preserve whole zeroes');
nmmpro_amount_check(NMMPRO_Amount::to_units('0.000000001', 8), '0', 'sub-unit truncation');
nmmpro_amount_check(NMMPRO_Amount::clears('999999999999999999','1000000000000000000','1.0'), false, 'one wei short');
nmmpro_amount_check(NMMPRO_Amount::clears('1000000000000000000','1000000000000000000','1'), true, 'exact');
nmmpro_amount_check(NMMPRO_Amount::clears('999','1000','0.999'), true, 'tolerance inclusive');
nmmpro_amount_check(NMMPRO_Amount::clears('998','1000','0.999'), false, 'outside tolerance');
nmmpro_amount_check(NMMPRO_Amount::add('9007199254740993','9'), '9007199254741002', 'large sum');
nmmpro_amount_check(NMMPRO_Amount::multiply('123456789','987654321'), '121932631112635269', 'large product');
nmmpro_amount_check(NMMPRO_Amount::divide('121932631112635269','987654321'), '123456789', 'division');
nmmpro_amount_check(NMMPRO_Amount::quote('1','1','3','1.00',2), '0.34', 'quote');
nmmpro_amount_check(NMMPRO_Amount::quote('100','0.65','65000','0',8), '0.001', 'fiat conversion');
nmmpro_amount_check(NMMPRO_Amount::quote('100','1','100','-10',18), '0.9', 'markdown');
nmmpro_amount_check(NMMPRO_Amount::rounded('9.995',2), '10', 'carry rounding');
foreach (array('NaN','INF','-1','1e9999','1.2.3',array(),INF) as $bad) {
    try { NMMPRO_Amount::normalize($bad); throw new Exception('Accepted invalid input'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
try { NMMPRO_Amount::quote('1','1','0','0',8); throw new Exception('Accepted zero divisor'); }
catch (InvalidArgumentException $e) { $checks++; }
echo "AMOUNT CHECKS PASSED ($checks)\n";
