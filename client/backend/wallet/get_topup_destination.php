<?php
// client/backend/wallet/get_topup_destination.php
// Returns the platform's bank account for top-up transfers, plus the
// server-derived reference number the client should include in their
// bank transfer description.
//
// The reference returned here is purely informational for the client.
// topup_initiate.php re-derives the same value server-side, so tampering
// with the client-side display has zero effect.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/reference_helper.php';   // ← new

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_topup_destination',
    'rate_limit' => [10, 60],
    'csrf'       => ['enabled' => true, 'form_name' => 'client_topup_form'],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

// Platform bank account — from config, NOT hardcoded in JS
$bankDetails = [
    'bank_name'      => defined('TOPUP_BANK_NAME')      ? TOPUP_BANK_NAME      : 'GTBank',
    'account_name'   => defined('TOPUP_ACCOUNT_NAME')   ? TOPUP_ACCOUNT_NAME   : 'RentEase Properties Ltd',
    'account_number' => defined('TOPUP_ACCOUNT_NUMBER') ? TOPUP_ACCOUNT_NUMBER : '0123456789',
];

// Server-derived reference. This is the ONLY source of truth.
$reference = clientTopupReference($clientCode);

json_success([
    'bank_name'           => $bankDetails['bank_name'],
    'account_name'        => $bankDetails['account_name'],
    'account_number'      => $bankDetails['account_number'],
    'suggested_reference' => $reference,   // display-only, client cannot change
    'min_amount'          => 1000,
    'max_amount'          => 50000000,
], 'Top-up destination retrieved');