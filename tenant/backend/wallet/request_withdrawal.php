<?php
// tenant/backend/wallet/request_withdrawal.php
// Tenant requests withdrawal to their saved bank account, authenticated via secret answer
// SECURITY MODEL: The beneficiary name is looked up server-side from the tenant's saved
// bank record. The client CANNOT supply or override the beneficiary name — it is
// ignored if sent. This prevents DevTools-based tampering.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
rateLimiter();

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'tenant_withdrawal',
    'rate_limit' => [3, 600],   // 3 per 10 min
    'csrf' => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles' => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode)
    json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

$amountRaw = trim($input['amount'] ?? '');
$accountNo = trim($input['account_number'] ?? '');
$bankCode = trim($input['bank_code'] ?? '');   // from bank dropdown
$secretAns = trim($input['secret_answer'] ?? '');
// NOTE: bank_name and beneficiary_name are IGNORED if sent by the client.
// They are always resolved server-side from the tenant's saved bank record.

// ---- Validation ----
$amount = round((float) str_replace(',', '', $amountRaw), 2);
if ($amount <= 0)
    json_error("Enter a valid amount", 400);
if ($amount < 1000)
    json_error("Minimum withdrawal is ₦1,000", 400);
if ($accountNo === '')
    json_error("Account number is required", 400);
if (!preg_match('/^\d{10}$/', $accountNo))
    json_error("Account number must be 10 digits", 400);
if ($bankCode === '')
    json_error("Bank is required", 400);
if ($secretAns === '')
    json_error("Please provide your secret answer", 400);

$conn->begin_transaction();

try {
    // Lock tenant row + fetch saved bank + wallet
    $stmt = $conn->prepare("
        SELECT wallet_balance, secret_answer, has_secret_set,
               bank_name, bank_account_name, bank_account_number, bank_code
        FROM tenants
        WHERE tenant_code = ?
        FOR UPDATE
    ");
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant)
        throw new Exception("Tenant not found", 404);

    // ---- Verify the account number matches the saved beneficiary ----
    // The client cannot transfer to an arbitrary account. It must be the one on file.
    if (empty($tenant['bank_account_number'])) {
        throw new Exception("No bank account on file. Please add one first.", 400);
    }
    if ($tenant['bank_account_number'] !== $accountNo) {
        logActivity("Withdrawal: account number mismatch for {$tenantCode} (submitted: ******" .
            substr($accountNo, -4) . ", on file: ******" . substr($tenant['bank_account_number'], -4) . ")", 'WARN');
        throw new Exception("Account number does not match the account on file. Please update your bank details first.", 400);
    }
    if ((string) $tenant['bank_code'] !== (string) $bankCode) {
        throw new Exception("Bank does not match the account on file.", 400);
    }
    $accountName = $tenant['bank_account_name'];   // <-- server-side truth
    $bankName = $tenant['bank_name'];

    // ---- Verify secret answer ----
    if ((int) $tenant['has_secret_set'] !== 1 || empty($tenant['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    $storedAnswer = $tenant['secret_answer'];
    $answerMatches = false;
    if (strlen($storedAnswer) >= 60 && strpos($storedAnswer, '$') === 0) {
        $answerMatches = password_verify(strtolower($secretAns), $storedAnswer);
    } else {
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


    // Create the wallet request — use server-side name, not client-supplied
    $requestCode = 'WRQ_' . strtoupper(bin2hex(random_bytes(6)));
    $referenceNumber = 'WRQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    // Reserve the funds
    $newBalance = round($walletBalance - $amount, 2);

    $debit = $conn->prepare("UPDATE tenants SET wallet_balance = wallet_balance - ? WHERE tenant_code = ?");
    $debit->bind_param("ds", $amount, $tenantCode);
    if (!$debit->execute())
        throw new Exception("Failed to reserve funds: " . $debit->error);
    $debit->close();

    // Ledger entry — masked account number in the note
    $ledger = $conn->prepare("
    INSERT INTO tenant_wallet_transactions
        (tenant_code, transaction_type, amount,running_balance,
         request_id, status, notes, created_by)
    VALUES (?, 'withdrawal', ?, ?, ?, 'Pending', ?, NULL)
");
    $maskedAcct = '******' . substr($accountNo, -4);
    $note = "Withdrawal request to {$bankName} ({$maskedAcct}) — pending admin approval";
    $debitAmt = -$amount;
    $ledger->bind_param("sddss", $tenantCode, $debitAmt, $newBalance, $requestCode, $note);
    $ledger->execute();
    $ledger->close();

    $reqStmt = $conn->prepare("
        INSERT INTO wallet_requests
            (request_code, user_type, user_code, request_type, amount, reference_number, 
             bank_name, bank_account_name, bank_account_number,
             status, created_at)
        VALUES (?, 'tenant', ?, 'withdrawal', ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $reqStmt->bind_param(
        "ssdssss",
        $requestCode,
        $tenantCode,
        $amount,
        $referenceNumber,
        $bankName,
        $accountName,
        $accountNo
    );
    if (!$reqStmt->execute())
        throw new Exception("Failed to save request: " . $reqStmt->error);
    $reqStmt->close();

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
                . " to {$bankName} ({$maskedAcct}).",
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

    json_success([
        'request_code' => $requestCode,
        'amount' => $amount,
        'amount_formatted' => '₦' . number_format($amount, 2),
        'beneficiary_name' => $accountName,
        'bank_name' => $bankName,
        'masked_account' => $maskedAcct,
        'new_wallet_balance' => $newBalance,
        'status' => 'pending',
    ], 'Withdrawal request submitted. Admin will review and process.');

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Withdrawal request error: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}