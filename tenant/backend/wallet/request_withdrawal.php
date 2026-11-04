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
if (!is_array($input)) $input = $_POST;

$amountRaw = trim($input['amount'] ?? '');
$accountNo = trim($input['account_number'] ?? '');
$bankCode  = trim($input['bank_code'] ?? '');
$secretAns = trim($input['secret_answer'] ?? '');
// NOTE: bank_name and beneficiary_name from the client are IGNORED — always
// resolved server-side from the tenant's saved bank record.

logActivity("[TENANT_WITHDRAW] ========== START ==========");
logActivity("[TENANT_WITHDRAW] Tenant: {$tenantCode}");

// ---- Input validation ----
// Strict amount: digits + up to 2 decimals only. Rejects "1e5", "0x1A", etc.
$amountClean = str_replace(',', '', $amountRaw);
if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amountClean)) {
    logActivity("[TENANT_WITHDRAW] Invalid amount format: '{$amountRaw}'", 'WARN');
    json_error("Please enter a valid amount (numbers only, up to 2 decimal places)", 400);
}
$amount = round((float) $amountClean, 2);
if ($amount < 1000)       json_error("Minimum withdrawal is ₦1,000", 400);
if ($amount > 50_000_000) json_error("Maximum withdrawal is ₦50,000,000", 400);
if ($accountNo === '')    json_error("Account number is required", 400);
if (!preg_match('/^\d{10}$/', $accountNo)) json_error("Account number must be 10 digits", 400);
if ($bankCode === '')     json_error("Bank is required", 400);
if ($secretAns === '')    json_error("Please provide your secret answer", 400);

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
    if (!$stmt) throw new Exception("Prepare failed (load tenant): " . $conn->error);
    $stmt->bind_param("s", $tenantCode);
    if (!$stmt->execute()) throw new Exception("Load tenant failed: " . $stmt->error);
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

    // ---- 3) Lockout check FIRST ----
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
    $pendingCount = countPendingWithdrawals($conn, 'tenant', $tenantCode);
    if ($pendingCount === PHP_INT_MAX) {
        logActivity("[TENANT_WITHDRAW] Pending check failed (DB error) for {$tenantCode}", 'ERROR');
        throw new Exception("Unable to verify pending withdrawals — please try again", 500);
    }
    if ($pendingCount > 0) {
        logActivity("[TENANT_WITHDRAW] Blocked: pending withdrawal for {$tenantCode}", 'WARN');
        throw new Exception("You already have a pending withdrawal request", 409);
    }

    // ---- 7) Reserve funds + generate identifiers ----
    $requestCode     = 'WRQ_' . strtoupper(bin2hex(random_bytes(6)));
    $referenceNumber = 'WRQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    $newBalance      = round($walletBalance - $amount, 2);
    $maskedAcct      = '******' . substr($accountNo, -4);

    $debit = $conn->prepare("UPDATE tenants SET wallet_balance = wallet_balance - ? WHERE tenant_code = ?");
    if (!$debit) throw new Exception("Prepare failed (debit): " . $conn->error);
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
    if (!$ledger->execute()) {
        if ($ledger->errno === 1062) {
            logActivity("[TENANT_WITHDRAW] DB rejected: duplicate withdrawal ledger for {$requestCode}", 'WARN');
            throw new Exception("Duplicate withdrawal ledger entry — please retry", 409);
        }
        throw new Exception("Ledger insert failed: " . $ledger->error);
    }
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
    if (!$reqStmt->execute()) {
        if ($reqStmt->errno === 1062) {
            $errText = $reqStmt->error;
            if (stripos($errText, 'pending_marker') !== false
                || stripos($errText, 'uniq_pending_per_user_type') !== false) {
                logActivity("[TENANT_WITHDRAW] DB rejected: pending withdrawal already exists (concurrent insert) for {$tenantCode}", 'WARN');
                throw new Exception("You already have a pending withdrawal request", 409);
            }
            logActivity("[TENANT_WITHDRAW] DB rejected: request_code collision {$requestCode}", 'WARN');
            throw new Exception("Please try again (transient issue)", 409);
        }
        throw new Exception("Failed to save request: " . $reqStmt->error);
    }
    $reqStmt->close();
    logActivity("[TENANT_WITHDRAW] wallet_requests row inserted: request_code={$requestCode}");

    // ---- 10) Reset lockout on success ----
    setSecretAttempts($conn, 'tenant', $tenantCode, 0, null);

    $conn->commit();
    logActivity("[TENANT_WITHDRAW] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[TENANT_WITHDRAW] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_WITHDRAW] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    exit;
} 
// finally {
//     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
//         $conn->close();
//     }
// }

// ---- Notify admins (best-effort, post-commit) ----
try {
    $admins = $conn->query("SELECT unique_id FROM admin_tbl
    WHERE status = '1' AND role IN ('Super Admin', 'Admin')
    LIMIT 20");
    if ($admins) {
        while ($adm = $admins->fetch_assoc()) {
            if (function_exists('createAdminNotification')) {
                createAdminNotification(
                    $conn,
                    (int) $adm['unique_id'],
                    'payment_confirmation',
                    'New Tenant Withdrawal Request',
                    "Tenant {$tenantCode} requested a withdrawal of ₦" . number_format($amount, 2)
                        . " to {$bankName} ({$maskedAcct}).",
                    'info',
                    0
                );
            }
        }
    }
} catch (Throwable $notifErr) {
    logActivity("[TENANT_WITHDRAW] Admin notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
}

// ---- Acknowledge to the tenant (best-effort) ----
try {
    createNotification(
        $conn,
        $tenantCode,
        'wallet',
        'Withdrawal Request Received',
        "We received your withdrawal request of ₦" . number_format($amount, 2)
            . " to {$bankName} ({$maskedAcct}). Funds have been reserved. "
            . "An admin will process the payment within 1-2 business days.",
        [
            'request_code'       => $requestCode,
            'amount'             => $amount,
            'new_wallet_balance' => $newBalance,
            'status'             => 'pending',
        ],
        'medium',
        '../wallet.php',
        'View Wallet'
    );
    logActivity("[TENANT_WITHDRAW] Tenant acknowledgment sent: {$tenantCode}");
} catch (Throwable $notifErr) {
    logActivity("[TENANT_WITHDRAW] Tenant acknowledgment failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
}

// ---- Close connection LAST ----
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $conn->close();
}

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