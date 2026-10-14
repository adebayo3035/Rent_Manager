<?php
// tenant/backend/wallet/request_withdrawal.php
// Tenant requests withdrawal to a bank account, authenticated via secret answer

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'tenant_withdrawal',
    'rate_limit' => [3, 600],   // 3 per 10 min
    'csrf'       => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles'      => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

$amountRaw   = trim($input['amount'] ?? '');
$bankName    = trim($input['bank_name'] ?? '');
$accountName = trim($input['bank_account_name'] ?? '');
$accountNo   = trim($input['bank_account_number'] ?? '');
$secretAns   = trim($input['secret_answer'] ?? '');

// ---- Validation ----
$amount = round((float) str_replace(',', '', $amountRaw), 2);
if ($amount <= 0) json_error("Enter a valid amount", 400);
if ($amount < 1000) json_error("Minimum withdrawal is ₦1,000", 400);
if ($bankName === '') json_error("Bank name is required", 400);
if ($accountName === '') json_error("Account name is required", 400);
if ($accountNo === '') json_error("Account number is required", 400);
if (!preg_match('/^\d{10}$/', $accountNo)) {
    json_error("Account number must be 10 digits", 400);
}
if ($secretAns === '') json_error("Please provide your secret answer", 400);

$conn->begin_transaction();

try {
    // Lock tenant row
    $stmt = $conn->prepare("
        SELECT wallet_balance, secret_answer, has_secret_set
        FROM tenants
        WHERE tenant_code = ?
        FOR UPDATE
    ");
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) throw new Exception("Tenant not found", 404);

    // Verify secret answer
    if ((int) $tenant['has_secret_set'] !== 1 || empty($tenant['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    // Answer is hashed? Depends on your schema. If plain text, compare directly.
    // If hashed, use password_verify.
    $storedAnswer = $tenant['secret_answer'];
    $answerMatches = false;

    // Support both plain and hashed (bcrypt) storage
    if (strlen($storedAnswer) >= 60 && strpos($storedAnswer, '$') === 0) {
        // Looks like a bcrypt hash
        $answerMatches = password_verify(strtolower($secretAns), $storedAnswer);
    } else {
        // Plain text comparison (case-insensitive)
        $answerMatches = (strcasecmp(trim($secretAns), trim($storedAnswer)) === 0);
    }

    if (!$answerMatches) {
        logActivity("Failed withdrawal auth for {$tenantCode}", 'WARN');
        throw new Exception("Incorrect secret answer", 401);
    }

    $walletBalance = (float) $tenant['wallet_balance'];
    if ($amount > $walletBalance) {
        throw new Exception(
            "Insufficient wallet balance. Available: ₦" . number_format($walletBalance, 2),
            400
        );
    }

    // Block if there's already a pending withdrawal
    $pendingCheck = $conn->prepare("
        SELECT id FROM wallet_requests
        WHERE user_type = 'tenant' AND user_code = ?
          AND request_type = 'withdrawal' AND status = 'pending'
        LIMIT 1
    ");
    $pendingCheck->bind_param("s", $tenantCode);
    $pendingCheck->execute();
    if ($pendingCheck->get_result()->num_rows > 0) {
        $pendingCheck->close();
        throw new Exception("You already have a pending withdrawal request", 409);
    }
    $pendingCheck->close();

    // Reserve the funds: debit the wallet NOW; refund if rejected
    $newBalance = round($walletBalance - $amount, 2);

    $debit = $conn->prepare("
        UPDATE tenants
        SET wallet_balance = wallet_balance - ?
        WHERE tenant_code = ?
    ");
    $debit->bind_param("ds", $amount, $tenantCode);
    if (!$debit->execute()) throw new Exception("Failed to reserve funds: " . $debit->error);
    $debit->close();

    // Ledger entry (debit with 'withdrawal' type, note pending)
    $ledger = $conn->prepare("
        INSERT INTO tenant_wallet_transactions
            (tenant_code, transaction_type, amount, running_balance,
             request_id, notes, created_by)
        VALUES (?, 'withdrawal', ?, ?, NULL, ?, NULL)
    ");
    $note = "Withdrawal request to {$bankName} ({$accountNo}) — pending admin approval";
    $debitAmt = -$amount;
    $ledger->bind_param("sdds", $tenantCode, $debitAmt, $newBalance, $note);
    $ledger->execute();
    $ledger->close();

    // Create the wallet request
    $requestCode = 'WRQ_' . strtoupper(bin2hex(random_bytes(6)));

    $reqStmt = $conn->prepare("
        INSERT INTO wallet_requests
            (request_code, user_type, user_code, request_type, amount,
             bank_name, bank_account_name, bank_account_number,
             status, created_at)
        VALUES (?, 'tenant', ?, 'withdrawal', ?, ?, ?, ?, 'pending', NOW())
    ");
    $reqStmt->bind_param(
        "ssdsss",
        $requestCode,
        $tenantCode,
        $amount,
        $bankName,
        $accountName,
        $accountNo
    );
    if (!$reqStmt->execute()) throw new Exception("Failed to save request: " . $reqStmt->error);
    $reqStmt->close();

    // Save bank details as defaults for next time
    $saveBank = $conn->prepare("
        UPDATE tenants
        SET bank_name = ?, bank_account_name = ?, bank_account_number = ?
        WHERE tenant_code = ?
    ");
    $saveBank->bind_param("ssss", $bankName, $accountName, $accountNo, $tenantCode);
    $saveBank->execute();
    $saveBank->close();

    // Notify admins
    try {
        $admins = $conn->query("SELECT unique_id FROM admin_tbl WHERE status = 1 LIMIT 10");
        while ($adm = $admins->fetch_assoc()) {
            createNotification(
                $conn,
                $adm['unique_id'],
                'wallet',
                'New Tenant Withdrawal Request',
                "Tenant {$tenantCode} requested a withdrawal of ₦" . number_format($amount, 2)
                . " to {$bankName} ({$accountNo}).",
                ['request_code' => $requestCode, 'amount' => $amount],
                'high',
                '../wallet_requests.php',
                'Review Request'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("Withdrawal notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    json_success('Withdrawal request submitted. Admin will review and process.', [
        'request_code'         => $requestCode,
        'amount'               => $amount,
        'amount_formatted'     => '₦' . number_format($amount, 2),
        'new_wallet_balance'   => $newBalance,
        'status'               => 'pending',
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Withdrawal request error: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}