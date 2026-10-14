<?php
// client/backend/wallet/get_balance.php
// Returns client wallet balance + recent transactions

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
    'rate_key' => 'client_wallet_balance',
    'roles'    => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) {
    json_error("Session expired", 401);
}

try {
    // Balance
    $stmt = $conn->prepare("
        SELECT wallet_balance, settlement_balance, total_settlement_earned,
               last_settlement_date
        FROM clients
        WHERE client_code = ? AND client_status = '1'
        LIMIT 1
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) {
        json_error("Client not found", 404);
    }

    // Recent transactions (last 30)
    $txStmt = $conn->prepare("
        SELECT
            id, transaction_type, amount, running_balance,
            request_id, notes, created_at
        FROM client_wallet_transactions
        WHERE client_code = ?
        ORDER BY id DESC
        LIMIT 30
    ");
    $txStmt->bind_param("s", $clientCode);
    $txStmt->execute();
    $txResult = $txStmt->get_result();
    $transactions = [];
    while ($row = $txResult->fetch_assoc()) {
        $transactions[] = [
            'id'              => (int) $row['id'],
            'type'            => $row['transaction_type'],
            'amount'          => (float) $row['amount'],
            'amount_formatted'=> formatNaira((float) $row['amount']),
            'running_balance' => (float) $row['running_balance'],
            'request_id'      => $row['request_id'],
            'notes'           => $row['notes'],
            'created_at'      => $row['created_at'],
        ];
    }
    $txStmt->close();

    // Pending requests (top-ups + withdrawals awaiting admin action)
    $reqStmt = $conn->prepare("
        SELECT request_code, request_type, amount, status, created_at
        FROM wallet_requests
        WHERE user_type = 'client' AND user_code = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $reqStmt->bind_param("s", $clientCode);
    $reqStmt->execute();
    $reqResult = $reqStmt->get_result();
    $pendingRequests = [];
    while ($row = $reqResult->fetch_assoc()) {
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
        'wallet_balance'            => (float) $client['wallet_balance'],
        'wallet_balance_formatted'  => formatNaira((float) $client['wallet_balance']),
        'settlement_balance'        => (float) $client['settlement_balance'],
        'total_settlement_earned'   => (float) $client['total_settlement_earned'],
        'last_settlement_date'      => $client['last_settlement_date'],
        'transactions'              => $transactions,
        'pending_requests'          => $pendingRequests,
    ], 'Wallet data retrieved',);

} catch (Throwable $e) {
    logActivity("Wallet balance error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load wallet data", 500);
}

function formatNaira(float $amount): string {
    $sign = $amount < 0 ? '-' : '';
    $abs  = abs($amount);
    return $sign . '₦' . number_format($abs, 2);
}