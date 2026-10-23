<?php
// admin/backend/wallet/reject_request.php
// Admin rejects a top-up or withdrawal.
//   - Withdrawal (tenant/client): refund the reserved funds, insert a reversal
//     ledger row, mark the original ledger row 'Rejected'.
//   - Top-up: no money moved at request time, so just mark rejected.

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

$adminId = (int) $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode     = trim($input['request_code'] ?? '');
$rejectionReason = trim($input['rejection_reason'] ?? '');

if ($requestCode === '')          json_error("Request code is required", 400);
if (strlen($rejectionReason) < 5) json_error("Please provide a rejection reason (min 5 chars)", 400);

// ---- Log entry ----
logActivity("[REJECT_REQUEST] ========== START ==========");
logActivity("[REJECT_REQUEST] Admin: {$adminId} | Request: {$requestCode}");
logActivity("[REJECT_REQUEST] Reason: " . substr($rejectionReason, 0, 200));

$conn->begin_transaction();
logActivity("[REJECT_REQUEST] Transaction started");

try {
    // ---- 1) Load request (locked) ----
    $stmt = $conn->prepare("
        SELECT id, user_type, user_code, request_type, amount, status
        FROM wallet_requests
        WHERE request_code = ?
        FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (load request): " . $conn->error);
    $stmt->bind_param("s", $requestCode);
    if (!$stmt->execute()) throw new Exception("Load request failed: " . $stmt->error);
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        logActivity("[REJECT_REQUEST] Request not found: {$requestCode}", 'WARN');
        throw new Exception("Request not found", 404);
    }
    if ($req['status'] !== 'pending') {
        logActivity("[REJECT_REQUEST] Request already {$req['status']}: {$requestCode}", 'WARN');
        throw new Exception("Request is already {$req['status']}", 400);
    }

    $requestPk = (int) $req['id'];
    $userType  = $req['user_type'];
    $userCode  = $req['user_code'];
    $amount    = (float) $req['amount'];
    $reqType   = $req['request_type'];

    logActivity("[REJECT_REQUEST] Loaded: user_type={$userType}, user_code={$userCode}, "
        . "request_type={$reqType}, amount=₦" . number_format($amount, 2) . ", status=pending");

    // ---- 2) Validate request_type / user_type pairing ----
    $validPairs = [
        'withdrawal' => ['tenant', 'client'],
        'topup'      => ['client'],
    ];
    if (!isset($validPairs[$reqType])) {
        logActivity("[REJECT_REQUEST] Unknown request_type '{$reqType}' on {$requestCode}", 'ERROR');
        throw new Exception("Unknown request_type '{$reqType}' on request {$requestCode}", 500);
    }
    if (!in_array($userType, $validPairs[$reqType], true)) {
        logActivity("[REJECT_REQUEST] Invalid pair request_type={$reqType}, "
            . "user_type={$userType} on {$requestCode}", 'ERROR');
        throw new Exception(
            "Invalid user_type '{$userType}' for request_type '{$reqType}' on request {$requestCode}",
            500
        );
    }

    // ---- 3) If withdrawal: refund + reversal + flip original ----
    if ($reqType === 'withdrawal') {
        if ($userType === 'tenant') {
            $walletTable = 'tenant_wallet_transactions';
            $userTable   = 'tenants';
            $codeColumn  = 'tenant_code';
        } else { // client
            $walletTable = 'client_wallet_transactions';
            $userTable   = 'clients';
            $codeColumn  = 'client_code';
        }

        logActivity("[REJECT_REQUEST] Refunding {$userType} {$userCode}: "
            . "+₦" . number_format($amount, 2) . " (via {$userTable})");

        // Lock user row + read current balance
        $lockStmt = $conn->prepare("
            SELECT wallet_balance FROM {$userTable} WHERE {$codeColumn} = ? FOR UPDATE
        ");
        if (!$lockStmt) throw new Exception("Prepare failed (lock user): " . $conn->error);
        $lockStmt->bind_param("s", $userCode);
        $lockStmt->execute();
        $userRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if (!$userRow) {
            logActivity("[REJECT_REQUEST] {$userType} not found: {$userCode}", 'ERROR');
            throw new Exception("{$userType} not found for code {$userCode}", 404);
        }

        $oldBalance = round((float) $userRow['wallet_balance'], 2);
        $newBalance = round($oldBalance + $amount, 2);

        // Refund
        $refund = $conn->prepare("
            UPDATE {$userTable} SET wallet_balance = wallet_balance + ? WHERE {$codeColumn} = ?
        ");
        if (!$refund) throw new Exception("Prepare failed (refund): " . $conn->error);
        $refund->bind_param("ds", $amount, $userCode);
        if (!$refund->execute()) throw new Exception("Failed to refund wallet: " . $refund->error);
        $refund->close();

        logActivity("[REJECT_REQUEST] Wallet refunded: {$userCode} "
            . "₦" . number_format($oldBalance, 2) . " → ₦" . number_format($newBalance, 2));

        // Reversal ledger row — status explicit
        $note = "Withdrawal request rejected — funds refunded. Reason: {$rejectionReason}";
        $ledger = $conn->prepare("
            INSERT INTO {$walletTable}
                ({$codeColumn}, transaction_type, amount, running_balance,
                 request_id, status, notes, created_by)
            VALUES (?, 'admin_adjustment', ?, ?, ?, 'Approved', ?, ?)
        ");
        if (!$ledger) throw new Exception("Prepare failed (ledger): " . $conn->error);
        $ledger->bind_param("sddssi", $userCode, $amount, $newBalance, $requestCode, $note, $adminId);
        if (!$ledger->execute()) throw new Exception("Failed to write refund ledger: " . $ledger->error);
        $ledgerId = $ledger->insert_id;
        $ledger->close();

        logActivity("[REJECT_REQUEST] Reversal ledger inserted: id={$ledgerId}, "
            . "{$walletTable}, amount=+₦" . number_format($amount, 2)
            . ", running_balance=₦" . number_format($newBalance, 2));

        // Flip original withdrawal row Pending → Rejected
        $updOriginal = $conn->prepare("
            UPDATE {$walletTable}
            SET status = 'Rejected', created_by = ?
            WHERE request_id = ? AND status = 'Pending'
        ");
        if (!$updOriginal) throw new Exception("Prepare failed (flip original): " . $conn->error);
        $updOriginal->bind_param("is", $adminId, $requestCode);
        if (!$updOriginal->execute()) throw new Exception("Failed to update original ledger: " . $updOriginal->error);
        if ($updOriginal->affected_rows === 0) {
            logActivity("[REJECT_REQUEST] No pending row found in {$walletTable} "
                . "for request {$requestCode}", 'WARN');
            throw new Exception(
                "No pending withdrawal ledger row found for request {$requestCode}. "
                . "The wallet debit may be missing or already marked. Aborting rejection.",
                409
            );
        }
        $updOriginal->close();

        logActivity("[REJECT_REQUEST] Original ledger flipped: {$walletTable} "
            . "request_id={$requestCode} → status='Rejected'");

    } else {
        // topup — no wallet activity
        logActivity("[REJECT_REQUEST] Top-up rejection — no wallet activity "
            . "(money was never credited for {$requestCode})");
    }

    // ---- 4) Mark request rejected ----
    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    if (!$update) throw new Exception("Prepare failed (reject request): " . $conn->error);
    $update->bind_param("sii", $rejectionReason, $adminId, $requestPk);
    if (!$update->execute()) throw new Exception("Failed to reject: " . $update->error);
    if ($update->affected_rows === 0) {
        logActivity("[REJECT_REQUEST] Concurrent modification detected for {$requestCode}", 'WARN');
        throw new Exception("Request was modified concurrently", 409);
    }
    $update->close();
    logActivity("[REJECT_REQUEST] Request marked rejected in wallet_requests: {$requestCode}");

    // ---- 5) Notify the correct party (best-effort) ----
    try {
        $title = $reqType === 'topup' ? 'Top-Up Request Rejected' : 'Withdrawal Request Rejected';
        $msg = "Your {$reqType} request of ₦" . number_format($amount, 2)
             . " was rejected. Reason: {$rejectionReason}.";
        if ($reqType === 'withdrawal') {
            $msg .= " The funds have been returned to your wallet.";
        }
        $details = ['request_code' => $requestCode, 'reason' => $rejectionReason];

        if ($userType === 'tenant') {
            createNotification(
                $conn, $userCode, 'wallet', $title, $msg, $details,
                'normal', '../wallet.php', 'View Wallet'
            );
            logActivity("[REJECT_REQUEST] Tenant notification sent: {$userCode}");
        } else { // client
            if (function_exists('createClientNotification')) {
                createClientNotification(
                    $conn, $userCode, 'wallet', $title, $msg, $details,
                    'normal', '../wallet.php', 'View Wallet'
                );
                logActivity("[REJECT_REQUEST] Client notification sent via createClientNotification: {$userCode}");
            } else {
                $detailsJson = json_encode($details);
                $notif = $conn->prepare("
                    INSERT INTO client_notifications
                        (client_code, notification_type, title, message, details,
                         priority, action_url, action_text, created_at)
                    VALUES (?, 'wallet', ?, ?, ?, 'normal', '../wallet.php', 'View Wallet', NOW())
                ");
                if (!$notif) throw new Exception("Prepare failed (client notification): " . $conn->error);
                $notif->bind_param("ssss", $userCode, $title, $msg, $detailsJson);
                if (!$notif->execute()) throw new Exception("Client notification insert failed: " . $notif->error);
                $notif->close();
                logActivity("[REJECT_REQUEST] Client notification sent via raw insert (helper missing): {$userCode}");
            }
        }
    } catch (Throwable $notifErr) {
        logActivity("[REJECT_REQUEST] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();
    logActivity("[REJECT_REQUEST] Transaction committed");

    logActivity("[REJECT_REQUEST] ========== SUCCESS ==========");
    logActivity("[REJECT_REQUEST] request_code={$requestCode}, type={$reqType}, "
        . "user_type={$userType}, user_code={$userCode}, "
        . "amount=₦" . number_format($amount, 2)
        . ", refunded=" . ($reqType === 'withdrawal' ? 'yes' : 'no')
        . ", admin={$adminId}");

    json_success('Request rejected', [
        'request_code' => $requestCode,
        'status'       => 'rejected',
        'refunded'     => $reqType === 'withdrawal',
        'user_type'    => $userType,
        'user_code'    => $userCode,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[REJECT_REQUEST] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[REJECT_REQUEST] Stack: " . $e->getTraceAsString(), 'ERROR');
    logActivity("[REJECT_REQUEST] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}