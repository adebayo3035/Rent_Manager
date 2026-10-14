<?php
// admin/backend/wallet/reject_request.php
// Admin rejects a top-up or withdrawal. For withdrawals: refund the reserved funds.

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
    'method'   => 'POST',
    'rate_key' => 'admin_reject_wallet',
    'csrf'     => ['enabled' => true, 'form_name' => 'admin_wallet_form'],
    'roles'    => ['Super Admin', 'Admin'],
]);

$adminId = $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode     = trim($input['request_code'] ?? '');
$rejectionReason = trim($input['rejection_reason'] ?? '');

if ($requestCode === '') json_error("Request code is required", 400);
if (strlen($rejectionReason) < 5) json_error("Please provide a rejection reason (min 5 chars)", 400);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT id, user_type, user_code, request_type, amount, status
        FROM wallet_requests
        WHERE request_code = ?
        FOR UPDATE
    ");
    $stmt->bind_param("s", $requestCode);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) throw new Exception("Request not found", 404);
    if ($req['status'] !== 'pending') {
        throw new Exception("Request is already {$req['status']}", 400);
    }

    $userType = $req['user_type'];
    $userCode = $req['user_code'];
    $amount   = (float) $req['amount'];

    // If withdrawal: refund the reserved funds
    if ($req['request_type'] === 'withdrawal') {
        if ($userType !== 'tenant') {
            throw new Exception("Withdrawal user type must be tenant", 400);
        }

        $lockStmt = $conn->prepare("
            SELECT wallet_balance FROM tenants WHERE tenant_code = ? FOR UPDATE
        ");
        $lockStmt->bind_param("s", $userCode);
        $lockStmt->execute();
        $tenant = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if ($tenant) {
            $oldBalance = (float) $tenant['wallet_balance'];
            $newBalance = round($oldBalance + $amount, 2);

            $refund = $conn->prepare("
                UPDATE tenants SET wallet_balance = wallet_balance + ?
                WHERE tenant_code = ?
            ");
            $refund->bind_param("ds", $amount, $userCode);
            $refund->execute();
            $refund->close();

            // Ledger entry — reversal
            $ledger = $conn->prepare("
                INSERT INTO tenant_wallet_transactions
                    (tenant_code, transaction_type, amount, running_balance,
                     request_id, notes, created_by)
                VALUES (?, 'admin_adjustment', ?, ?, ?, ?, ?)
            ");
            $note = "Withdrawal request rejected — funds refunded. Reason: {$rejectionReason}";
            $ledger->bind_param("sddssi", $userCode, $amount, $newBalance, $requestCode, $note, $adminId);
            $ledger->execute();
            $ledger->close();
        }
    }

    // Mark request rejected
    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW()
        WHERE id = ?
    ");
    $update->bind_param("sii", $rejectionReason, $adminId, $req['id']);
    if (!$update->execute()) throw new Exception("Failed to reject: " . $update->error);
    $update->close();

    // Notify user
    try {
        $title = $req['request_type'] === 'topup'
            ? 'Top-Up Request Rejected'
            : 'Withdrawal Request Rejected';
        $msg = "Your {$req['request_type']} request of ₦" . number_format($amount, 2)
             . " was rejected. Reason: {$rejectionReason}.";
        if ($req['request_type'] === 'withdrawal') {
            $msg .= " The funds have been returned to your wallet.";
        }

        createNotification(
            $conn,
            $userCode,
            'wallet',
            $title,
            $msg,
            ['request_code' => $requestCode, 'reason' => $rejectionReason],
            'normal',
            '../wallet.php',
            'View Wallet'
        );
    } catch (Throwable $notifErr) {
        logActivity("Reject notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    json_success('Request rejected', [
        'request_code' => $requestCode,
        'status'       => 'rejected',
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Reject request error: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}