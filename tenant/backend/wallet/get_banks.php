<?php
// tenant/backend/wallet/get_banks.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/utils.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Static list — extend as needed. Codes follow NIBSS/CBN standard.
$banks = [
    ['code' => '044', 'name' => 'Access Bank'],
    ['code' => '063', 'name' => 'Access Bank (Diamond)'],
    ['code' => '035A', 'name' => 'ALAT by Wema'],
    ['code' => '401', 'name' => 'ASO Savings and Loans'],
    ['code' => '050', 'name' => 'Ecobank Nigeria'],
    ['code' => '084', 'name' => 'Enterprise Bank'],
    ['code' => '070', 'name' => 'Fidelity Bank'],
    ['code' => '011', 'name' => 'First Bank of Nigeria'],
    ['code' => '214', 'name' => 'First City Monument Bank'],
    ['code' => '058', 'name' => 'Guaranty Trust Bank'],
    ['code' => '030', 'name' => 'Heritage Bank'],
    ['code' => '301', 'name' => 'Jaiz Bank'],
    ['code' => '082', 'name' => 'Keystone Bank'],
    ['code' => '526', 'name' => 'Parallex Bank'],
    ['code' => '076', 'name' => 'Polaris Bank'],
    ['code' => '101', 'name' => 'Providus Bank'],
    ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
    ['code' => '068', 'name' => 'Standard Chartered Bank'],
    ['code' => '232', 'name' => 'Sterling Bank'],
    ['code' => '100', 'name' => 'Suntrust Bank'],
    ['code' => '032', 'name' => 'Union Bank of Nigeria'],
    ['code' => '033', 'name' => 'United Bank for Africa'],
    ['code' => '215', 'name' => 'Unity Bank'],
    ['code' => '035', 'name' => 'Wema Bank'],
    ['code' => '057', 'name' => 'Zenith Bank'],
];

json_success( ['banks' => $banks], "Banks retrieved");