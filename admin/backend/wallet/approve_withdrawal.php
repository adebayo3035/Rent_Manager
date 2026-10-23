<?php
// admin/backend/wallet/approve_withdrawal.php
// Admin confirms a withdrawal request. The wallet was debited at request time
// (status 'Pending'); approval flips the ledger row to 'Approved'.

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

$adminId = (int) $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode   = trim($input['request_code'] ?? '');
$notes         = trim($input['notes'] ?? '');
$bankReference = trim($input['bank_reference'] ?? '');

if ($requestCode === '') json_error("Request code is required", 400);

// ---- Log entry ----
logActivity("[APPROVE_WITHDRAWAL] ========== START ==========");
logActivity("[APPROVE_WITHDRAWAL] Admin: {$adminId} | Request: {$requestCode}");
logActivity("[APPROVE_WITHDRAWAL] Notes: " . ($notes !== '' ? substr($notes, 0, 100) : '(none)')
    . " | Bank ref: " . ($bankReference !== '' ? $bankReference : '(none)'));

$conn->begin_transaction();
logActivity("[APPROVE_WITHDRAWAL] Transaction started");

try {
    // ---- 1) Load request ----
    $stmt = $conn->prepare("
        SELECT id, user_code, user_type, amount, status, request_code
        FROM wallet_requests
        WHERE request_code = ? AND request_type = 'withdrawal'
        FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (load request): " . $conn->error);
    $stmt->bind_param("s", $requestCode);
    if (!$stmt->execute()) throw new Exception("Load request failed: " . $stmt->error);
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        logActivity("[APPROVE_WITHDRAWAL] Request not found: {$requestCode}", 'WARN');
        throw new Exception("Request not found", 404);
    }
    if ($req['status'] !== 'pending') {
        logActivity("[APPROVE_WITHDRAWAL] Request already {$req['status']}: {$requestCode}", 'WARN');
        throw new Exception("Request is already {$req['status']}", 400);
    }

    $requestPk  = (int) $req['id'];
    $userCode   = $req['user_code'];
    $userType   = $req['user_type'];
    $amount     = (float) $req['amount'];
    $requestCode = $req['request_code'];

    logActivity("[APPROVE_WITHDRAWAL] Loaded: user_type={$userType}, user_code={$userCode}, "
        . "amount=₦" . number_format($amount, 2) . ", status=pending");

    // ---- 2) Mark request approved ----
    $updateNotes = $notes;
    if ($bankReference !== '') {
        $updateNotes .= ($updateNotes ? ' | ' : '') . "Bank ref: {$bankReference}";
    }

    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
        WHERE id = ?
    ");
    if (!$update) throw new Exception("Prepare failed (approve request): " . $conn->error);
    $update->bind_param("isi", $adminId, $updateNotes, $requestPk);
    if (!$update->execute()) throw new Exception("Failed to update request: " . $update->error);
    $update->close();
    logActivity("[APPROVE_WITHDRAWAL] Request marked approved in wallet_requests: {$requestCode}");

    // ---- 3) Update the matching ledger row ----
    if ($userType === 'tenant') {
        $ledgerTable = 'tenant_wallet_transactions';
        $upd = $conn->prepare("
            UPDATE {$ledgerTable}
            SET status = 'Approved', created_by = ?
            WHERE request_id = ? AND status = 'Pending'
        ");
        if (!$upd) throw new Exception("Prepare failed (tenant ledger): " . $conn->error);
        $upd->bind_param("is", $adminId, $requestCode);
        if (!$upd->execute()) throw new Exception("Failed to update tenant ledger: " . $upd->error);
        if ($upd->affected_rows === 0) {
            logActivity("[APPROVE_WITHDRAWAL] No pending ledger row found in {$ledgerTable} "
                . "for request {$requestCode}", 'WARN');
            throw new Exception(
                "No pending withdrawal ledger row found for request {$requestCode}. "
                . "The wallet debit may be missing or already marked. Aborting approval.",
                409
            );
        }
        logActivity("[APPROVE_WITHDRAWAL] Ledger flipped: {$ledgerTable} "
            . "request_id={$requestCode} → status='Approved'");
        $upd->close();

    } else if ($userType === 'client') {
        $ledgerTable = 'client_wallet_transactions';
        $upd = $conn->prepare("
            UPDATE {$ledgerTable}
            SET status = 'Approved', created_by = ?
            WHERE request_id = ? AND status = 'Pending'
        ");
        if (!$upd) throw new Exception("Prepare failed (client ledger): " . $conn->error);
        $upd->bind_param("is", $adminId, $requestCode);
        if (!$upd->execute()) throw new Exception("Failed to update client ledger: " . $upd->error);
        if ($upd->affected_rows === 0) {
            logActivity("[APPROVE_WITHDRAWAL] No pending ledger row found in {$ledgerTable} "
                . "for request {$requestCode}", 'WARN');
            throw new Exception(
                "No pending withdrawal ledger row found for request {$requestCode}. "
                . "The wallet debit may be missing or already marked. Aborting approval.",
                409
            );
        }
        logActivity("[APPROVE_WITHDRAWAL] Ledger flipped: {$ledgerTable} "
            . "request_id={$requestCode} → status='Approved'");
        $upd->close();

    } else {
        logActivity("[APPROVE_WITHDRAWAL] Unknown user_type='{$userType}' on request {$requestCode}", 'ERROR');
        throw new Exception("Invalid user_type '{$userType}' on withdrawal request", 500);
    }

    // ---- 4) Notify the correct party ----
    try {
        $title = 'Withdrawal Processed';
        $message = "Your withdrawal of ₦" . number_format($amount, 2)
            . " has been processed. Please allow 1-2 business days for the funds to reflect.";
        $details = ['request_code' => $requestCode, 'amount' => $amount];

        if ($userType === 'tenant') {
            createNotification(
                $conn, $userCode, 'wallet', $title, $message, $details,
                'normal', '../wallet.php', 'View Wallet'
            );
            logActivity("[APPROVE_WITHDRAWAL] Tenant notification sent: {$userCode}");
        } else if ($userType === 'client') {
            if (function_exists('createClientNotification')) {
                createClientNotification(
                    $conn, $userCode, 'wallet', $title, $message, $details,
                    'normal', '../wallet.php', 'View Wallet'
                );
                logActivity("[APPROVE_WITHDRAWAL] Client notification sent via createClientNotification: {$userCode}");
            } else {
                $detailsJson = json_encode($details);
                $notif = $conn->prepare("
                    INSERT INTO client_notifications
                        (client_code, notification_type, title, message, details,
                         priority, action_url, action_text, created_at)
                    VALUES (?, 'wallet', ?, ?, ?, 'normal', '../wallet.php', 'View Wallet', NOW())
                ");
                if (!$notif) throw new Exception("Prepare failed (client notification): " . $conn->error);
                $notif->bind_param("ssss", $userCode, $title, $message, $detailsJson);
                if (!$notif->execute()) throw new Exception("Client notification insert failed: " . $notif->error);
                $notif->close();
                logActivity("[APPROVE_WITHDRAWAL] Client notification sent via raw insert (helper missing): {$userCode}");
            }
        }
    } catch (Throwable $notifErr) {
        logActivity("[APPROVE_WITHDRAWAL] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();
    logActivity("[APPROVE_WITHDRAWAL] Transaction committed");

    logActivity("[APPROVE_WITHDRAWAL] ========== SUCCESS ==========");
    logActivity("[APPROVE_WITHDRAWAL] request_code={$requestCode}, user_type={$userType}, "
        . "user_code={$userCode}, amount=₦" . number_format($amount, 2) . ", admin={$adminId}");

    json_success('Withdrawal marked as processed', [
        'request_code' => $requestCode,
        'amount'       => $amount,
        'user_type'    => $userType,
        'user_code'    => $userCode,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[APPROVE_WITHDRAWAL] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[APPROVE_WITHDRAWAL] Stack: " . $e->getTraceAsString(), 'ERROR');
    logActivity("[APPROVE_WITHDRAWAL] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}