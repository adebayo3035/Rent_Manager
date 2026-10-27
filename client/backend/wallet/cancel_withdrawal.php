<?php
// client/backend/wallet/cancel_withdrawal.php
// Client cancels their own pending withdrawal.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_cancel_withdrawal',
    'rate_limit' => [5, 60],
    'csrf'       => ['enabled' => true, 'form_name' => 'client_withdrawal_form'],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode = trim($input['request_code'] ?? '');
if ($requestCode === '') json_error("Request code is required", 400);

logActivity("[CLIENT_CANCEL_WD] ========== START ==========");
logActivity("[CLIENT_CANCEL_WD] Client: {$clientCode} | Request: {$requestCode}");

$conn->begin_transaction();
logActivity("[CLIENT_CANCEL_WD] Transaction started");

try {
    $stmt = $conn->prepare("
        SELECT id, user_code, request_type, amount, status
        FROM wallet_requests
        WHERE request_code = ?
          AND user_type = 'client'
          AND user_code = ?
        FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (load request): " . $conn->error);
    $stmt->bind_param("ss", $requestCode, $clientCode);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        logActivity("[CLIENT_CANCEL_WD] Request not found or not owned: {$requestCode}", 'WARN');
        throw new Exception("Request not found", 404);
    }
    if ($req['request_type'] !== 'withdrawal') {
        throw new Exception("Only withdrawal requests can be cancelled", 400);
    }
    if ($req['status'] !== 'pending') {
        logActivity("[CLIENT_CANCEL_WD] Request already {$req['status']}: {$requestCode}", 'WARN');
        throw new Exception("Request is already {$req['status']} — it can no longer be cancelled", 400);
    }

    $requestPk = (int) $req['id'];
    $amount    = (float) $req['amount'];

    logActivity("[CLIENT_CANCEL_WD] Loaded: amount=₦" . number_format($amount, 2) . ", status=pending");

    $lockStmt = $conn->prepare("
        SELECT wallet_balance FROM clients WHERE client_code = ? FOR UPDATE
    ");
    if (!$lockStmt) throw new Exception("Prepare failed (lock client): " . $conn->error);
    $lockStmt->bind_param("s", $clientCode);
    $lockStmt->execute();
    $clientRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if (!$clientRow) throw new Exception("Client not found", 404);

    $oldBalance = round((float) $clientRow['wallet_balance'], 2);
    $newBalance = round($oldBalance + $amount, 2);

    $refund = $conn->prepare("
        UPDATE clients SET wallet_balance = wallet_balance + ? WHERE client_code = ?
    ");
    if (!$refund) throw new Exception("Prepare failed (refund): " . $conn->error);
    $refund->bind_param("ds", $amount, $clientCode);
    if (!$refund->execute()) throw new Exception("Refund failed: " . $refund->error);
    $refund->close();

    logActivity("[CLIENT_CANCEL_WD] Wallet refunded: {$clientCode} "
        . "₦" . number_format($oldBalance, 2) . " → ₦" . number_format($newBalance, 2));

    $note = "Withdrawal cancelled by client — funds refunded";
    $ledger = $conn->prepare("
        INSERT INTO client_wallet_transactions
            (client_code, transaction_type, amount, running_balance,
             request_id, status, notes, created_by)
        VALUES (?, 'admin_adjustment', ?, ?, ?, 'Approved', ?, NULL)
    ");
    if (!$ledger) throw new Exception("Prepare failed (ledger): " . $conn->error);
    $ledger->bind_param("sddss", $clientCode, $amount, $newBalance, $requestCode, $note);
    if (!$ledger->execute()) throw new Exception("Reversal ledger insert failed: " . $ledger->error);
    $ledgerId = $ledger->insert_id;
    $ledger->close();
    logActivity("[CLIENT_CANCEL_WD] Reversal ledger inserted: id={$ledgerId}, "
        . "amount=+₦" . number_format($amount, 2));

    $updOriginal = $conn->prepare("
        UPDATE client_wallet_transactions
        SET status = 'Rejected', created_by = NULL
        WHERE request_id = ? AND status = 'Pending'
    ");
    if (!$updOriginal) throw new Exception("Prepare failed (flip original): " . $conn->error);
    $updOriginal->bind_param("s", $requestCode);
    if (!$updOriginal->execute()) throw new Exception("Failed to flip original ledger: " . $updOriginal->error);
    if ($updOriginal->affected_rows === 0) {
        logActivity("[CLIENT_CANCEL_WD] No pending ledger row found for {$requestCode}", 'WARN');
        throw new Exception(
            "Unable to cancel: no pending ledger entry found for this request. "
            . "Please contact support.",
            409
        );
    }
    $updOriginal->close();
    logActivity("[CLIENT_CANCEL_WD] Original ledger row flipped to 'Rejected'");

    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'cancelled', approved_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    if (!$update) throw new Exception("Prepare failed (cancel request): " . $conn->error);
    $update->bind_param("i", $requestPk);
    if (!$update->execute()) throw new Exception("Failed to cancel request: " . $update->error);
    if ($update->affected_rows === 0) {
        throw new Exception("Request was modified concurrently", 409);
    }
    $update->close();
    logActivity("[CLIENT_CANCEL_WD] Request marked cancelled: {$requestCode}");

    try {
        $admins = $conn->query("SELECT unique_id FROM admin_tbl WHERE status = 1 LIMIT 10");
        while ($adm = $admins->fetch_assoc()) {
            createNotification(
                $conn, $adm['unique_id'], 'wallet',
                'Client Cancelled Withdrawal',
                "Client {$clientCode} cancelled withdrawal {$requestCode} of ₦"
                . number_format($amount, 2) . ". Funds have been refunded to the client's wallet.",
                ['request_code' => $requestCode, 'amount' => $amount],
                'normal', '../wallet_requests.php', 'View Requests'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("[CLIENT_CANCEL_WD] Admin notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();
    logActivity("[CLIENT_CANCEL_WD] Transaction committed");
    logActivity("[CLIENT_CANCEL_WD] ========== SUCCESS ==========");
    logActivity("[CLIENT_CANCEL_WD] request_code={$requestCode}, client={$clientCode}, "
        . "amount=₦" . number_format($amount, 2) . ", new_balance=₦" . number_format($newBalance, 2));

    json_success([
        'request_code'       => $requestCode,
        'amount'             => $amount,
        'amount_formatted'   => '₦' . number_format($amount, 2),
        'new_wallet_balance' => $newBalance,
        'new_wallet_balance_formatted' => '₦' . number_format($newBalance, 2),
        'status'             => 'cancelled',
    ], 'Withdrawal cancelled. Funds have been returned to your wallet.');

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[CLIENT_CANCEL_WD] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_CANCEL_WD] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}