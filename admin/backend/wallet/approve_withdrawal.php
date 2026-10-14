<?php
// admin/backend/wallet/approve_withdrawal.php
// Admin confirms the bank transfer was made; request is marked approved
// NOTE: Funds were already debited when the tenant requested (reserved).
// This endpoint just marks the request approved.

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
    'rate_key' => 'admin_approve_withdrawal',
    'csrf'     => ['enabled' => true, 'form_name' => 'admin_wallet_form'],
    'roles'    => ['Super Admin', 'Admin'],
]);

$adminId = $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode = trim($input['request_code'] ?? '');
$notes       = trim($input['notes'] ?? '');
$bankReference = trim($input['bank_reference'] ?? '');

if ($requestCode === '') json_error("Request code is required", 400);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT id, user_code, amount, status
        FROM wallet_requests
        WHERE request_code = ? AND request_type = 'withdrawal'
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

    $tenantCode = $req['user_code'];
    $amount     = (float) $req['amount'];

    // Update request
    $updateNotes = $notes;
    if ($bankReference !== '') {
        $updateNotes .= ($updateNotes ? ' | ' : '') . "Bank ref: {$bankReference}";
    }

    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
        WHERE id = ?
    ");
    $update->bind_param("isi", $adminId, $updateNotes, $req['id']);
    if (!$update->execute()) throw new Exception("Failed to update request: " . $update->error);
    $update->close();

    // Notify tenant
    try {
        createNotification(
            $conn,
            $tenantCode,
            'wallet',
            'Withdrawal Processed',
            "Your withdrawal of ₦" . number_format($amount, 2)
            . " has been processed. Please allow 1-2 business days for the funds to reflect.",
            ['request_code' => $requestCode, 'amount' => $amount],
            'normal',
            '../wallet.php',
            'View Wallet'
        );
    } catch (Throwable $notifErr) {
        logActivity("Withdrawal notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    json_success('Withdrawal marked as processed', [
        'request_code' => $requestCode,
        'amount'       => $amount,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Approve withdrawal error: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}