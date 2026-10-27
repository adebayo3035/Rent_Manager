<?php
// admin/backend/wallet/approve_topup.php
// Admin credits the client wallet after confirming bank transfer.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../../../client/backend/utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'POST',
    'rate_key' => 'admin_approve_topup',
    'csrf'     => ['enabled' => true, 'form_name' => 'admin_wallet_form'],
    'roles'    => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$requestCode = trim($input['request_code'] ?? '');
$notes       = trim($input['notes'] ?? '');

if ($requestCode === '') json_error("Request code is required", 400);

logActivity("[APPROVE_TOPUP] ========== START ==========");
logActivity("[APPROVE_TOPUP] Admin: {$adminId} | Request: {$requestCode}");

$conn->begin_transaction();
logActivity("[APPROVE_TOPUP] Transaction started");

try {
    // ---- 1) Load request ----
    $stmt = $conn->prepare("
        SELECT id, user_type, user_code, amount, status, reference_number
        FROM wallet_requests
        WHERE request_code = ? AND request_type = 'topup'
        FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (load request): " . $conn->error);
    $stmt->bind_param("s", $requestCode);
    if (!$stmt->execute()) throw new Exception("Load request failed: " . $stmt->error);
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        logActivity("[APPROVE_TOPUP] Request not found: {$requestCode}", 'WARN');
        throw new Exception("Request not found", 404);
    }
    if ($req['status'] !== 'pending') {
        logActivity("[APPROVE_TOPUP] Request already {$req['status']}: {$requestCode}", 'WARN');
        throw new Exception("Request is already {$req['status']}", 400);
    }
    if ($req['user_type'] !== 'client') {
        logActivity("[APPROVE_TOPUP] Invalid user_type '{$req['user_type']}' for topup {$requestCode}", 'ERROR');
        throw new Exception("Top-ups are for clients only", 400);
    }

    $requestPk  = (int) $req['id'];
    $amount     = (float) $req['amount'];
    $clientCode = $req['user_code'];
    $reference  = $req['reference_number'] ?? '';

    logActivity("[APPROVE_TOPUP] Loaded: client={$clientCode}, amount=₦" . number_format($amount, 2)
        . ", reference={$reference}, status=pending");

    // ---- 2) Lock and credit client wallet ----
    $lockStmt = $conn->prepare("
        SELECT wallet_balance FROM clients WHERE client_code = ? FOR UPDATE
    ");
    if (!$lockStmt) throw new Exception("Prepare failed (lock client): " . $conn->error);
    $lockStmt->bind_param("s", $clientCode);
    $lockStmt->execute();
    $client = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if (!$client) {
        logActivity("[APPROVE_TOPUP] Client not found: {$clientCode}", 'ERROR');
        throw new Exception("Client not found", 404);
    }
    $oldBalance = round((float) $client['wallet_balance'], 2);

    $credit = $conn->prepare("
        UPDATE clients SET wallet_balance = wallet_balance + ?
        WHERE client_code = ?
    ");
    if (!$credit) throw new Exception("Prepare failed (credit): " . $conn->error);
    $credit->bind_param("ds", $amount, $clientCode);
    if (!$credit->execute()) throw new Exception("Failed to credit wallet: " . $credit->error);
    $credit->close();

    // Re-read balance after credit (FOR UPDATE)
    $balStmt = $conn->prepare("
        SELECT wallet_balance FROM clients WHERE client_code = ? LIMIT 1 FOR UPDATE
    ");
    if (!$balStmt) throw new Exception("Prepare failed (read balance): " . $conn->error);
    $balStmt->bind_param("s", $clientCode);
    $balStmt->execute();
    $balRow = $balStmt->get_result()->fetch_assoc();
    $balStmt->close();
    if (!$balRow) throw new Exception("Failed to read post-credit balance", 500);
    $newBalance = round((float) $balRow['wallet_balance'], 2);

    logActivity("[APPROVE_TOPUP] Wallet credited: {$clientCode} "
        . "₦" . number_format($oldBalance, 2) . " → ₦" . number_format($newBalance, 2)
        . " (+₦" . number_format($amount, 2) . ")");

    // ---- 3) Insert ledger row ----
    $bankRef = trim($req['reference_number'] ?? '');
    $note = "Top-up approved.";
    if ($bankRef !== '') $note .= " Bank reference: {$bankRef}.";
    if ($notes !== '')   $note .= " Admin notes: {$notes}";

    $ledger = $conn->prepare("
        INSERT INTO client_wallet_transactions
            (client_code, transaction_type, amount, running_balance,
             request_id, status, notes, created_by)
        VALUES (?, 'topup', ?, ?, ?, 'Approved', ?, ?)
    ");
    if (!$ledger) throw new Exception("Prepare failed (ledger): " . $conn->error);
    $ledger->bind_param("sddssi", $clientCode, $amount, $newBalance, $requestCode, $note, $adminId);
    if (!$ledger->execute()) throw new Exception("Ledger write failed: " . $ledger->error);
    $ledgerId = $ledger->insert_id;
    $ledger->close();

    logActivity("[APPROVE_TOPUP] Ledger row inserted: id={$ledgerId}, "
        . "client={$clientCode}, amount=₦" . number_format($amount, 2)
        . ", running_balance=₦" . number_format($newBalance, 2));

    // ---- 4) Mark request approved ----
    $update = $conn->prepare("
        UPDATE wallet_requests
        SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
        WHERE id = ? AND status = 'pending'
    ");
    if (!$update) throw new Exception("Prepare failed (approve request): " . $conn->error);
    $update->bind_param("isi", $adminId, $notes, $requestPk);
    if (!$update->execute()) throw new Exception("Failed to update request: " . $update->error);
    if ($update->affected_rows === 0) {
        logActivity("[APPROVE_TOPUP] Concurrent modification detected for {$requestCode}", 'WARN');
        throw new Exception("Request was modified concurrently", 409);
    }
    $update->close();
    logActivity("[APPROVE_TOPUP] Request marked approved: {$requestCode}");

    // ---- 5) Notify client ----
    try {
        $title   = 'Wallet Topped Up';
        $message = "Your wallet has been credited with ₦" . number_format($amount, 2)
                 . ". New balance: ₦" . number_format($newBalance, 2) . ".";
        $details = [
            'request_code' => $requestCode,
            'amount'       => $amount,
            'new_balance'  => $newBalance,
        ];

        if (function_exists('createClientNotification')) {
            createClientNotification(
                $conn, $clientCode, 'wallet', $title, $message, $details,
                'normal', '../wallet.php', 'View Wallet'
            );
            logActivity("[APPROVE_TOPUP] Client notification sent via createClientNotification");
        } else {
            $detailsJson = json_encode($details);
            $notif = $conn->prepare("
                INSERT INTO client_notifications
                    (client_code, notification_type, title, message, details,
                     priority, action_url, action_text, is_read, created_at)
                VALUES (?, 'wallet', ?, ?, ?, 'normal', '../wallet.php', 'View Wallet', 0, NOW())
            ");
            if (!$notif) throw new Exception("Prepare failed (notification): " . $conn->error);
            $notif->bind_param("ssss", $clientCode, $title, $message, $detailsJson);
            if (!$notif->execute()) throw new Exception("Notification insert failed: " . $notif->error);
            $notif->close();
            logActivity("[APPROVE_TOPUP] Client notification sent via raw insert (helper missing)");
        }
    } catch (Throwable $notifErr) {
        logActivity("[APPROVE_TOPUP] Client notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();
    logActivity("[APPROVE_TOPUP] Transaction committed");

    logActivity("[APPROVE_TOPUP] ========== SUCCESS ==========");
    logActivity("[APPROVE_TOPUP] request_code={$requestCode}, client={$clientCode}, "
        . "amount=₦" . number_format($amount, 2)
        . ", old_balance=₦" . number_format($oldBalance, 2)
        . ", new_balance=₦" . number_format($newBalance, 2)
        . ", admin={$adminId}");

    json_success('Top-up approved and wallet credited', [
        'request_code'          => $requestCode,
        'amount'                => $amount,
        'amount_formatted'      => '₦' . number_format($amount, 2),
        'new_balance'           => $newBalance,
        'new_balance_formatted' => '₦' . number_format($newBalance, 2),
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[APPROVE_TOPUP] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[APPROVE_TOPUP] Stack: " . $e->getTraceAsString(), 'ERROR');
    logActivity("[APPROVE_TOPUP] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}