<?php
// admin/backend/wallet/approve_topup.php
// Admin credits the client wallet after confirming bank transfer

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
    'rate_key' => 'admin_approve_topup',
    'csrf'     => ['enabled' => true, 'form_name' => 'admin_wallet_form'],
    'roles'    => ['Super Admin', 'Admin'],
]);

$adminId = $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode = trim($input['request_code'] ?? '');
$notes       = trim($input['notes'] ?? '');

if ($requestCode === '') json_error("Request code is required", 400);

$conn->begin_transaction();

try {
    // Lock the request row
    $stmt = $conn->prepare("
        SELECT id, user_type, user_code, amount, status, reference_number
        FROM wallet_requests
        WHERE request_code = ? AND request_type = 'topup'
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
    if ($req['user_type'] !== 'client') {
        throw new Exception("Top-ups are for clients only", 400);
    }

    $amount     = (float) $req['amount'];
    $clientCode = $req['user_code'];

    // Lock and update client wallet
    $lockStmt = $conn->prepare("
        SELECT wallet_balance FROM clients WHERE client_code = ? FOR UPDATE
    ");
    $lockStmt->bind_param("s", $clientCode);
    $lockStmt->execute();
    $client = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if (!$client) throw new Exception("Client not found", 404);

    $oldBalance = (float) $client['wallet_balance'];
    $newBalance = round($oldBalance + $amount, 2);

    $credit = $conn->prepare("
        UPDATE clients SET wallet_balance = wallet_balance + ?
        WHERE client_code = ?
    ");
    $credit->bind_param("ds", $amount, $clientCode);
    if (!$credit->execute()) throw new Exception("Failed to credit wallet: " . $credit->error);
    $credit->close();

    // Ledger entry
    $ledger = $conn->prepare("
        INSERT INTO client_wallet_transactions
            (client_code, transaction_type, amount, running_balance,
             request_id, notes, created_by)
        VALUES (?, 'topup', ?, ?, ?, ?, ?)
    ");
    $note = "Top-up approved. Bank reference: " . ($req['reference_number'] ?? 'N/A')
        . ($notes ? " | Admin notes: {$notes}" : '');
    $ledger->bind_param("sddssi", $clientCode, $amount, $newBalance, $requestCode, $note, $adminId);
    if (!$ledger->execute()) throw new Exception("Ledger write failed: " . $ledger->error);
    $ledger->close();

    // Mark request approved
    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
        WHERE id = ?
    ");
    $update->bind_param("isi", $adminId, $notes, $req['id']);
    if (!$update->execute()) throw new Exception("Failed to update request: " . $update->error);
    $update->close();

    // Notify client
    try {
        createNotification(
            $conn,
            $clientCode,
            'wallet',
            'Wallet Topped Up',
            "Your wallet has been credited with ₦" . number_format($amount, 2)
            . ". New balance: ₦" . number_format($newBalance, 2) . ".",
            ['request_code' => $requestCode, 'amount' => $amount, 'new_balance' => $newBalance],
            'normal',
            '../wallet.php',
            'View Wallet'
        );
    } catch (Throwable $notifErr) {
        logActivity("Top-up notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    json_success('Top-up approved and wallet credited', [
        'request_code'      => $requestCode,
        'amount'            => $amount,
        'amount_formatted'  => '₦' . number_format($amount, 2),
        'new_balance'       => $newBalance,
        'new_balance_formatted' => '₦' . number_format($newBalance, 2),
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Approve top-up error: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}