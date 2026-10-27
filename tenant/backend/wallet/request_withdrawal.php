<?php
// tenant/backend/wallet/request_withdrawal.php
// Tenant requests withdrawal to their saved bank account.
//
// SECURITY MODEL:
//   - Beneficiary is looked up server-side from the tenant's saved bank record.
//     Client-supplied bank_name / beneficiary_name are IGNORED.
//   - Secret answer verified with lockout (3 fails → 15 min lock).
//   - Lockout state is SHARED across all tenant wallet operations.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/wallet_security.php';   // shared helpers

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'tenant_withdrawal',
    'rate_limit' => [3, 600],
    'csrf'       => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles'      => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) $input = $_POST;

$amountRaw = trim($input['amount'] ?? '');
$accountNo = trim($input['account_number'] ?? '');
$bankCode  = trim($input['bank_code'] ?? '');
$secretAns = trim($input['secret_answer'] ?? '');
// NOTE: bank_name and beneficiary_name from the client are IGNORED — always
// resolved server-side from the tenant's saved bank record.

logActivity("[TENANT_WITHDRAW] ========== START ==========");
logActivity("[TENANT_WITHDRAW] Tenant: {$tenantCode}");

// ---- Input validation ----
$amount = round((float) str_replace(',', '', $amountRaw), 2);
if ($amount <= 0)       json_error("Enter a valid amount", 400);
if ($amount < 1000)     json_error("Minimum withdrawal is ₦1,000", 400);
if ($accountNo === '')  json_error("Account number is required", 400);
if (!preg_match('/^\d{10}$/', $accountNo)) json_error("Account number must be 10 digits", 400);
if ($bankCode === '')   json_error("Bank is required", 400);
if ($secretAns === '')  json_error("Please provide your secret answer", 400);

$conn->begin_transaction();
logActivity("[TENANT_WITHDRAW] Transaction started");

try {
    // ---- 1) Lock tenant row ----
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

    if (!$tenant) throw new Exception("Tenant not found", 404);

    // ---- 2) Bank must match saved account ----
    if (empty($tenant['bank_account_number'])) {
        throw new Exception("No bank account on file. Please add one first.", 400);
    }
    if ($tenant['bank_account_number'] !== $accountNo) {
        logActivity("[TENANT_WITHDRAW] Account mismatch for {$tenantCode}: submitted ******"
            . substr($accountNo, -4) . ", on file ******"
            . substr($tenant['bank_account_number'], -4), 'WARN');
        throw new Exception("Account number does not match the account on file. Please update your bank details first.", 400);
    }
    if ((string)$tenant['bank_code'] !== (string)$bankCode) {
        throw new Exception("Bank does not match the account on file.", 400);
    }

    $accountName = $tenant['bank_account_name'];   // server-side truth
    $bankName    = $tenant['bank_name'];

    // ---- 3) Lockout check FIRST (before verifying) ----
    $lockState = checkSecretLockout($conn, 'tenant', $tenantCode);
    if ($lockState['locked']) {
        logActivity("[TENANT_WITHDRAW] Blocked: account locked for {$tenantCode}", 'WARN');
        throw new Exception($lockState['message'], 429);
    }

    // ---- 4) Secret verification with lockout ----
    if ((int)$tenant['has_secret_set'] !== 1 || empty($tenant['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    $verify = verifySecretAnswer($conn, 'tenant', $tenantCode, $tenant['secret_answer'], $secretAns);
    if (!$verify['ok']) {
        logActivity("[TENANT_WITHDRAW] Secret answer failed for {$tenantCode}: "
            . ($verify['message'] ?? ''), 'WARN');
        throw new Exception(
            $verify['message'] ?? 'Incorrect secret answer',
            $verify['locked'] ? 429 : 401
        );
    }

    // ---- 5) Balance check ----
    $walletBalance = round((float) $tenant['wallet_balance'], 2);
    if ($amount > $walletBalance) {
        logActivity("[TENANT_WITHDRAW] Insufficient balance: requested ₦" . number_format($amount, 2)
            . ", available ₦" . number_format($walletBalance, 2), 'WARN');
        throw new Exception(
            "Insufficient wallet balance. Available: ₦" . number_format($walletBalance, 2),
            400
        );
    }

    // ---- 6) One pending withdrawal at a time ----
    if (countPendingWithdrawals($conn, 'tenant', $tenantCode) > 0) {
        logActivity("[TENANT_WITHDRAW] Blocked: pending withdrawal for {$tenantCode}", 'WARN');
        throw new Exception("You already have a pending withdrawal request", 409);
    }

    // ---- 7) Reserve funds + generate identifiers ----
    $requestCode     = 'WRQ_' . strtoupper(bin2hex(random_bytes(6)));
    $referenceNumber = 'WRQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    $newBalance      = round($walletBalance - $amount, 2);
    $maskedAcct      = '******' . substr($accountNo, -4);

    $debit = $conn->prepare("UPDATE tenants SET wallet_balance = wallet_balance - ? WHERE tenant_code = ?");
    $debit->bind_param("ds", $amount, $tenantCode);
    if (!$debit->execute()) throw new Exception("Failed to reserve funds: " . $debit->error);
    $debit->close();

    logActivity("[TENANT_WITHDRAW] Wallet debited: {$tenantCode} ₦" . number_format($walletBalance, 2)
        . " → ₦" . number_format($newBalance, 2));

    // ---- 8) Ledger row (Pending) ----
    $note = "Withdrawal request to {$bankName} ({$maskedAcct}) — pending admin approval";
    $ledger = $conn->prepare("
        INSERT INTO tenant_wallet_transactions
            (tenant_code, transaction_type, amount, running_balance,
             request_id, status, notes, created_by)
        VALUES (?, 'withdrawal', ?, ?, ?, 'Pending', ?, NULL)
    ");
    if (!$ledger) throw new Exception("Prepare failed (ledger): " . $conn->error);
    $debitAmt = -$amount;
    $ledger->bind_param("sddss", $tenantCode, $debitAmt, $newBalance, $requestCode, $note);
    if (!$ledger->execute()) throw new Exception("Ledger insert failed: " . $ledger->error);
    $ledgerId = $ledger->insert_id;
    $ledger->close();
    logActivity("[TENANT_WITHDRAW] Ledger inserted: id={$ledgerId}, request_id={$requestCode}, "
        . "running_balance=₦" . number_format($newBalance, 2));

    // ---- 9) wallet_requests row ----
    $reqStmt = $conn->prepare("
        INSERT INTO wallet_requests
            (request_code, user_type, user_code, request_type, amount, reference_number,
             bank_name, bank_account_name, bank_account_number,
             status, created_at)
        VALUES (?, 'tenant', ?, 'withdrawal', ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    if (!$reqStmt) throw new Exception("Prepare failed (request): " . $conn->error);
    $reqStmt->bind_param(
        "ssdssss",
        $requestCode, $tenantCode, $amount, $referenceNumber,
        $bankName, $accountName, $accountNo
    );
    if (!$reqStmt->execute()) throw new Exception("Failed to save request: " . $reqStmt->error);
    $reqStmt->close();
    logActivity("[TENANT_WITHDRAW] wallet_requests row inserted: request_code={$requestCode}");

    // ---- 10) Reset lockout on success ----
    setSecretAttempts($conn, 'tenant', $tenantCode, 0, null);

    // ---- 11) Notify admins (best-effort) ----
    try {
        $admins = $conn->query("SELECT unique_id FROM admin_tbl WHERE status = 1 LIMIT 10");
        while ($adm = $admins->fetch_assoc()) {
            createNotification(
                $conn, $adm['unique_id'], 'wallet',
                'New Tenant Withdrawal Request',
                "Tenant {$tenantCode} requested a withdrawal of ₦" . number_format($amount, 2)
                . " to {$bankName} ({$maskedAcct}).",
                ['request_code' => $requestCode, 'amount' => $amount],
                'high', '../wallet_requests.php', 'Review Request'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("[TENANT_WITHDRAW] Admin notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();
    logActivity("[TENANT_WITHDRAW] Transaction committed");
    logActivity("[TENANT_WITHDRAW] ========== SUCCESS ==========");
    logActivity("[TENANT_WITHDRAW] request_code={$requestCode}, tenant={$tenantCode}, "
        . "amount=₦" . number_format($amount, 2));

    json_success([
        'request_code'       => $requestCode,
        'amount'             => $amount,
        'amount_formatted'   => '₦' . number_format($amount, 2),
        'beneficiary_name'   => $accountName,
        'bank_name'          => $bankName,
        'masked_account'     => $maskedAcct,
        'new_wallet_balance' => $newBalance,
        'status'             => 'pending',
    ], 'Withdrawal request submitted. Admin will review and process.');

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[TENANT_WITHDRAW] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_WITHDRAW] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}