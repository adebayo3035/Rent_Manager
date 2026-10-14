<?php
// admin/backend/debts/write_off.php

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
    'rate_key'   => 'admin_debt_writeoff',
    'rate_limit' => [10, 60],
    'csrf'       => ['enabled' => true, 'form_name' => 'admin_debt_form'],
    'roles'      => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) $input = $_POST;
if (!is_array($input)) json_error("Invalid input", 400);
$GLOBALS['__REQUEST_BODY'] = $input;

$debtCode = trim($input['debt_code'] ?? '');
$reason   = trim($input['reason'] ?? '');

if ($debtCode === '') json_error("Debt code is required", 400);
if (strlen($reason) < 5) json_error("Please provide a reason (min 5 chars)", 400);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT id, tenant_code, balance, notes
        FROM tenant_debts
        WHERE debt_code = ? AND status IN ('unpaid','partial','disputed')
        FOR UPDATE
    ");
    $stmt->bind_param("s", $debtCode);
    $stmt->execute();
    $debt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$debt) throw new Exception("Debt not found or cannot be written off", 404);

    $existingNotes = $debt['notes'] ?? '';
    $newNotes = trim($existingNotes . "\n[WRITTEN OFF] " . date('Y-m-d H:i') . " by admin #{$adminId}: {$reason}");

    $update = $conn->prepare("
        UPDATE tenant_debts
        SET status = 'written_off',
            notes = ?,
            resolved_at = NOW(),
            resolved_by = ?
        WHERE debt_code = ?
    ");
    $update->bind_param("sis", $newNotes, $adminId, $debtCode);
    if (!$update->execute()) throw new Exception("Failed to write off: " . $update->error);
    $update->close();

    $conn->commit();

    try {
        createNotification(
            $conn,
            $debt['tenant_code'],
            'wallet',
            'Debt Written Off',
            "Your outstanding debt of ₦" . number_format((float) $debt['balance'], 2)
            . " ({$debtCode}) has been written off. You no longer owe this amount.",
            ['debt_code' => $debtCode, 'reason' => $reason],
            'normal',
            '../wallet.php',
            'View Wallet'
        );
    } catch (Throwable $notifErr) {
        logActivity("Write-off notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    logActivity("Debt written off: {$debtCode} | Reason: {$reason}");

    json_success('Debt written off successfully', [
        'debt_code' => $debtCode,
        'status'    => 'written_off',
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Write-off error: " . $e->getMessage(), 'ERROR');
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}