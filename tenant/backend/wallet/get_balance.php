<?php
// tenant/backend/wallet/get_balance.php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'GET',
    'rate_key' => 'tenant_wallet_balance',
    'roles'    => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) json_error("Session expired", 401);

try {
    $stmt = $conn->prepare("
        SELECT wallet_balance, bank_name, bank_code, bank_account_name, bank_account_number
        FROM tenants WHERE tenant_code = ? LIMIT 1
    ");
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) json_error("Tenant not found", 404);

    // --- Has bank on file? ---
    // A bank account is considered "on file" only when we have both the
    // account number and the bank code. A half-saved row (e.g. from an
    // older flow) shouldn't trigger the withdrawal path.
    $hasBankAccount = !empty($tenant['bank_account_number']) && !empty($tenant['bank_code']);

    $accountNumberMasked = $hasBankAccount
        ? '******' . substr($tenant['bank_account_number'], -4)
        : null;

    // Recent transactions
    $txStmt = $conn->prepare("
        SELECT id, transaction_type, amount, running_balance, status,
               request_id, notes, created_at
        FROM tenant_wallet_transactions
        WHERE tenant_code = ?
        ORDER BY id DESC
        LIMIT 30
    ");
    $txStmt->bind_param("s", $tenantCode);
    $txStmt->execute();
    $txRes = $txStmt->get_result();
    $transactions = [];
    while ($row = $txRes->fetch_assoc()) {
        $transactions[] = [
            'id'              => (int) $row['id'],
            'type'            => $row['transaction_type'],
            'amount'          => (float) $row['amount'],
            'amount_formatted'=> formatNaira((float) $row['amount']),
            'running_balance' => (float) $row['running_balance'],
            'request_id'      => $row['request_id'],
            'notes'           => $row['notes'],
            'created_at'      => $row['created_at'],
            'status'          =>$row['status']
        ];
    }
    $txStmt->close();

    // Pending withdrawal requests
    $reqStmt = $conn->prepare("
        SELECT request_code, request_type, amount, status, created_at
        FROM wallet_requests
        WHERE user_type = 'tenant' AND user_code = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $reqStmt->bind_param("s", $tenantCode);
    $reqStmt->execute();
    $reqRes = $reqStmt->get_result();
    $pendingRequests = [];
    while ($row = $reqRes->fetch_assoc()) {
        $pendingRequests[] = [
            'request_code' => $row['request_code'],
            'type'         => $row['request_type'],
            'amount'       => (float) $row['amount'],
            'amount_formatted' => formatNaira((float) $row['amount']),
            'status'       => $row['status'],
            'created_at'   => $row['created_at'],
        ];
    }
    $reqStmt->close();

    json_success([
        'wallet_balance'           => (float) $tenant['wallet_balance'],
        'wallet_balance_formatted' => formatNaira((float) $tenant['wallet_balance']),

        // Bank details
        'has_bank_account'         => $hasBankAccount,
        'bank_name'                => $tenant['bank_name'],
        'bank_code'                => $tenant['bank_code'],
        'bank_account_name'        => $tenant['bank_account_name'],
        'bank_account_number'      => $tenant['bank_account_number'],       // full — used to submit withdrawal
        'bank_account_number_masked' => $accountNumberMasked,                // display

        'transactions'             => $transactions,
        'pending_requests'         => $pendingRequests,
    ], 'Wallet data retrieved');

} catch (Throwable $e) {
    logActivity("Tenant wallet balance error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load wallet", 500);
}

function formatNaira(float $amount): string {
    $sign = $amount < 0 ? '-' : '';
    return $sign . '₦' . number_format(abs($amount), 2);
}