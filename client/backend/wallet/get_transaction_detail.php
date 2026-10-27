<?php
// client/backend/wallet/get_transaction_detail.php
// Return a single wallet transaction + its originating request (if any).

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
    'rate_key' => 'client_wallet_tx_detail',
    'roles'    => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

$txId = (int) ($_GET['id'] ?? 0);
if ($txId <= 0) json_error("Invalid transaction", 400);

try {
    $stmt = $conn->prepare("
        SELECT id, client_code, transaction_type, amount, running_balance,
               request_id, status, notes, created_by, created_at
        FROM client_wallet_transactions
        WHERE id = ? AND client_code = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("is", $txId, $clientCode);
    $stmt->execute();
    $tx = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tx) throw new Exception("Transaction not found", 404);

    $txAmount = (float) $tx['amount'];

    $request = null;
    if (!empty($tx['request_id'])) {
        $rstmt = $conn->prepare("
            SELECT request_code, user_type, request_type, amount, status,
                   reference_number, bank_name, bank_account_name, bank_account_number,
                   rejection_reason, notes, approved_at, created_at
            FROM wallet_requests
            WHERE request_code = ? AND user_code = ?
            LIMIT 1
        ");
        if ($rstmt) {
            $rstmt->bind_param("ss", $tx['request_id'], $clientCode);
            $rstmt->execute();
            $rrow = $rstmt->get_result()->fetch_assoc();
            $rstmt->close();
            if ($rrow) {
                $request = [
                    'request_code'      => $rrow['request_code'],
                    'type'              => $rrow['request_type'],
                    'amount'            => (float) $rrow['amount'],
                    'amount_formatted'  => '₦' . number_format((float) $rrow['amount'], 2),
                    'status'            => $rrow['status'],
                    'reference_number'  => $rrow['reference_number'],
                    'bank_name'         => $rrow['bank_name'],
                    'bank_account_name' => $rrow['bank_account_name'],
                    'bank_account_last4'=> $rrow['bank_account_number']
                        ? substr($rrow['bank_account_number'], -4) : null,
                    'rejection_reason'  => $rrow['rejection_reason'],
                    'notes'             => $rrow['notes'],
                    'approved_at'       => $rrow['approved_at'],
                    'created_at'        => $rrow['created_at'],
                ];
            }
        }
    }

    $related = [];
    if (!empty($tx['request_id'])) {
        $relStmt = $conn->prepare("
            SELECT id, transaction_type, amount, running_balance, status, notes, created_at
            FROM client_wallet_transactions
            WHERE client_code = ? AND request_id = ?
            ORDER BY id ASC
        ");
        if ($relStmt) {
            $relStmt->bind_param("ss", $clientCode, $tx['request_id']);
            $relStmt->execute();
            $relRes = $relStmt->get_result();
            while ($row = $relRes->fetch_assoc()) {
                $amt = (float) $row['amount'];
                $related[] = [
                    'id'               => (int) $row['id'],
                    'type'             => $row['transaction_type'],
                    'amount'           => $amt,
                    'amount_formatted' => ($amt >= 0 ? '+' : '') . '₦' . number_format(abs($amt), 2),
                    'running_balance'  => (float) $row['running_balance'],
                    'status'           => $row['status'],
                    'notes'            => $row['notes'],
                    'created_at'       => $row['created_at'],
                ];
            }
            $relStmt->close();
        }
    }

    json_success([
        'transaction' => [
            'id'               => (int) $tx['id'],
            'type'             => $tx['transaction_type'],
            'direction'        => $txAmount >= 0 ? 'in' : 'out',
            'amount'           => $txAmount,
            'amount_formatted' => ($txAmount >= 0 ? '+' : '') . '₦' . number_format(abs($txAmount), 2),
            'running_balance'  => (float) $tx['running_balance'],
            'request_id'       => $tx['request_id'],
            'status'           => $tx['status'],
            'notes'            => $tx['notes'],
            'created_at'       => $tx['created_at'],
        ],
        'request' => $request,
        'related_transactions' => $related,
    ], 'Transaction detail retrieved');

} catch (Throwable $e) {
    logActivity("[CLIENT_TX_DETAIL] ERROR: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}