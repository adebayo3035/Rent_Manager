<?php
// client/backend/wallet/get_balance.php

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
if (!$clientCode) json_error("Session expired", 401);

try {
    // ---- Wallet snapshot ----
    $stmt = $conn->prepare("
        SELECT wallet_balance, settlement_balance, total_settlement_earned,
               last_settlement_date, bank_code, bank_name, bank_account_name, bank_account_number
        FROM clients
        WHERE client_code = ? AND client_status = '1'
        LIMIT 1
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client) json_error("Client not found", 404);

    $hasBankAccount = !empty($client['bank_account_number']) && !empty($client['bank_code']);
    $accountNumberMasked = $hasBankAccount
        ? '******' . substr($client['bank_account_number'], -4)
        : null;

    // ---- Parse transaction filters ----
    $status   = in_array($_GET['status'] ?? '', ['all', 'Pending', 'Approved', 'Rejected', 'Failed'])
        ? $_GET['status'] : 'all';
    $type     = in_array($_GET['type'] ?? '', [
        'all', 'withdrawal', 'refund_credit', 'rent_credit', 'admin_adjustment',
        'deposit_release', 'debt_recovery', 'settlement_debit', 'topup'
    ]) ? $_GET['type'] : 'all';
    $search   = trim($_GET['search'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $limit    = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset   = ($page - 1) * $limit;

    $dateFromValid = $dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom);
    $dateToValid   = $dateTo !== ''   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

    // ---- Build filter clauses (all prefixed with t.) ----
    $where  = ['t.client_code = ?'];
    $params = [$clientCode];
    $types  = 's';

    if ($status !== 'all') {
        $where[] = 't.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($type !== 'all') {
        $where[] = 't.transaction_type = ?';
        $params[] = $type;
        $types .= 's';
    }
    if ($search !== '') {
        $where[] = '(t.request_id LIKE ? OR t.notes LIKE ?)';
        $like = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }
    if ($dateFromValid) {
        $where[] = 't.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if ($dateToValid) {
        $where[] = 't.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // ---- Count ----
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM client_wallet_transactions t
        {$whereSql}
    ");
    if (!$countStmt) throw new Exception("Count prepare failed: " . $conn->error);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // ---- Transactions (paginated, joined with wallet_requests) ----
    $txStmt = $conn->prepare("
        SELECT
            t.id,
            t.transaction_type,
            t.amount,
            t.running_balance,
            t.status,
            t.request_id,
            t.notes,
            t.created_at,
            wr.status AS request_status
        FROM client_wallet_transactions t
        LEFT JOIN wallet_requests wr
            ON wr.request_code = t.request_id
            AND wr.user_type = 'client'
            AND wr.user_code = t.client_code
        {$whereSql}
        ORDER BY t.id DESC
        LIMIT ? OFFSET ?
    ");
    if (!$txStmt) throw new Exception("List prepare failed: " . $conn->error);
    $rowParams = [...$params, $limit, $offset];
    $txStmt->bind_param($types . 'ii', ...$rowParams);
    $txStmt->execute();
    $txRes = $txStmt->get_result();

    $transactions = [];
    while ($row = $txRes->fetch_assoc()) {
        $amt = (float) $row['amount'];
        $transactions[] = [
            'id'               => (int) $row['id'],
            'type'             => $row['transaction_type'],
            'amount'           => $amt,
            'amount_formatted' => formatNaira($amt),
            'running_balance'  => (float) $row['running_balance'],
            'request_id'       => $row['request_id'],
            'request_status'   => $row['request_status'],   // ← NEW
            'notes'            => $row['notes'],
            'created_at'       => $row['created_at'],
            'status'           => $row['status'],
        ];
    }
    $txStmt->close();

    // ---- Wallet totals ----
    $sumStmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS total_out
        FROM client_wallet_transactions
        WHERE client_code = ?
    ");
    $sumStmt->bind_param("s", $clientCode);
    $sumStmt->execute();
    $sumRow = $sumStmt->get_result()->fetch_assoc();
    $sumStmt->close();

    // ---- Pending requests ----
    $reqStmt = $conn->prepare("
        SELECT request_code, request_type, amount, status, created_at,
               reference_number, bank_name, bank_account_number
        FROM wallet_requests
        WHERE user_type = 'client' AND user_code = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $reqStmt->bind_param("s", $clientCode);
    $reqStmt->execute();
    $reqRes = $reqStmt->get_result();
    $pendingRequests = [];
    while ($row = $reqRes->fetch_assoc()) {
        $pendingRequests[] = [
            'request_code'      => $row['request_code'],
            'type'              => $row['request_type'],
            'amount'            => (float) $row['amount'],
            'amount_formatted'  => formatNaira((float) $row['amount']),
            'status'            => $row['status'],
            'created_at'        => $row['created_at'],
            'reference_number'  => $row['reference_number'],
            'bank_name'         => $row['bank_name'],
            'bank_account_last4'=> $row['bank_account_number']
                ? substr($row['bank_account_number'], -4) : null,
        ];
    }
    $reqStmt->close();

    json_success([
        'has_bank_account'           => $hasBankAccount,
        'bank_account_number_masked' => $accountNumberMasked,
        'wallet_balance'             => (float) $client['wallet_balance'],
        'wallet_balance_formatted'   => formatNaira((float) $client['wallet_balance']),
        'settlement_balance'         => (float) $client['settlement_balance'],
        'total_settlement_earned'    => (float) $client['total_settlement_earned'],
        'total_settlement_earned_formatted' => formatNaira((float) $client['total_settlement_earned']),
        'last_settlement_date'       => $client['last_settlement_date'],
        'bank_code'                  => $client['bank_code'],
        'bank_name'                  => $client['bank_name'],
        'bank_account_number'        => $client['bank_account_number'],
        'bank_account_name'          => $client['bank_account_name'],
        'total_in'                   => (float) $sumRow['total_in'],
        'total_in_formatted'         => formatNaira((float) $sumRow['total_in']),
        'total_out'                  => (float) $sumRow['total_out'],
        'total_out_formatted'        => formatNaira((float) $sumRow['total_out']),
        'transactions'               => $transactions,
        'pending_requests'           => $pendingRequests,
        'pagination'                 => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / max(1, $limit)),
        ],
    ], 'Wallet data retrieved');

} catch (Throwable $e) {
    logActivity("Wallet balance error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load wallet data", 500);
}

function formatNaira(float $amount): string {
    $sign = $amount < 0 ? '-' : '';
    return $sign . '₦' . number_format(abs($amount), 2);
}