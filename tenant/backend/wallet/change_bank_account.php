<?php
// tenant/backend/wallet/change_bank_account.php
// Change a tenant's destination bank account.
//
// IMPORTANT: Secret-answer verification runs OUTSIDE the transaction.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'tenant_change_bank',
    'rate_limit' => [3, 600],
    'csrf'       => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles'      => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$newBankName    = trim($input['bank_name'] ?? '');
$newBankCode    = trim($input['bank_code'] ?? '');
$newAccountName = trim($input['account_name'] ?? '');
$newAccountNo   = trim($input['account_number'] ?? '');
$currentSecret  = trim($input['secret_answer'] ?? '');

logActivity("[TENANT_CHANGE_BANK] ========== START ==========");
logActivity("[TENANT_CHANGE_BANK] Tenant: {$tenantCode}");

// ---- Validation ----
if ($newBankName === '')     json_error("Bank name is required", 400);
if ($newBankCode === '')     json_error("Bank code is required", 400);
if ($newAccountName === '')  json_error("Account name is required", 400);
if (!preg_match('/^\d{10}$/', $newAccountNo)) json_error("Account number must be 10 digits", 400);
if ($currentSecret === '')   json_error("Current secret answer is required", 400);

// ============ OUTSIDE TRANSACTION: LOCKOUT + SECRET VERIFICATION ============

$lockState = checkSecretLockout($conn, 'tenant', $tenantCode);
if ($lockState['locked']) {
    logActivity("[TENANT_CHANGE_BANK] Blocked: account locked for {$tenantCode}", 'WARN');
    json_error(
        $lockState['message'],
        423,
        null,
        'ACCOUNT_LOCKED',
        [
            'tenant_code'  => $tenantCode,
            'locked_until' => $lockState['locked_until'],
            'source'       => 'secret_answer',
        ]
    );
}

$stmt = $conn->prepare("
    SELECT secret_answer, has_secret_set
    FROM tenants WHERE tenant_code = ? AND status = 1 LIMIT 1
");
if (!$stmt) throw new Exception("Prepare failed (load tenant): " . $conn->error);
$stmt->bind_param("s", $tenantCode);
if (!$stmt->execute()) throw new Exception("Load tenant failed: " . $stmt->error);
$tenantSecret = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenantSecret) json_error("Tenant not found", 404);
if ((int)$tenantSecret['has_secret_set'] !== 1 || empty($tenantSecret['secret_answer'])) {
    json_error("Please set up your secret question first", 400);
}

$verify = verifySecretAnswer($conn, 'tenant', $tenantCode, $tenantSecret['secret_answer'], $currentSecret);

if (!$verify['ok']) {
    $isLocked = !empty($verify['locked']);
    logActivity(
        "[TENANT_CHANGE_BANK] Secret answer rejected for {$tenantCode}: " . ($verify['message'] ?? ''),
        'WARN'
    );

    // Pull the real lockout expiry so the frontend countdown matches the
    // 15-minute secret-answer lock (not the 60-minute password default).
    $lockoutData = null;
    if ($isLocked) {
        $attemptState = getSecretAttempts($conn, 'tenant', $tenantCode);
        $lockoutData = [
            'tenant_code'  => $tenantCode,
            'locked_until' => $attemptState['locked_until'] ?? date('Y-m-d H:i:s', time() + 900),
            'source'       => 'secret_answer',
        ];
    }

    json_error(
        $verify['message'] ?? 'Incorrect secret answer',
        $isLocked ? 423 : 401,
        null,
        $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_SECRET_ANSWER',
        $lockoutData
    );
}
logActivity("[TENANT_CHANGE_BANK] Secret answer verified for {$tenantCode}");

// ============ INSIDE TRANSACTION ============

$conn->begin_transaction();
logActivity("[TENANT_CHANGE_BANK] Transaction started");

try {
    $stmt = $conn->prepare("
        SELECT wallet_balance, bank_name, bank_code, bank_account_name, bank_account_number, bank_changed_at
        FROM tenants WHERE tenant_code = ? FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (load tenant): " . $conn->error);
    $stmt->bind_param("s", $tenantCode);
    if (!$stmt->execute()) throw new Exception("Load tenant failed: " . $stmt->error);
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) throw new Exception("Tenant not found", 404);

    $pending = countPendingWithdrawals($conn, 'tenant', $tenantCode);
    if ($pending === PHP_INT_MAX) {
        throw new Exception("Unable to verify pending withdrawals — please try again", 500);
    }
    if ($pending > 0) {
        logActivity("[TENANT_CHANGE_BANK] Blocked: pending withdrawal for {$tenantCode}", 'WARN');
        throw new Exception("You have a pending withdrawal. Please wait for it to be processed before changing your bank.", 409);
    }

    if (!empty($tenant['bank_changed_at']) && strtotime($tenant['bank_changed_at']) > time() - 86400) {
        $nextAllowed = date('Y-m-d H:i', strtotime($tenant['bank_changed_at']) + 86400);
        throw new Exception("You can only change your bank once every 24 hours. Next allowed: {$nextAllowed}", 429);
    }

    $isSameBank = (
        $tenant['bank_account_number'] === $newAccountNo
        && (string) $tenant['bank_code'] === (string) $newBankCode
    );
    if ($isSameBank) {
        logActivity("[TENANT_CHANGE_BANK] No-op: same bank submitted for {$tenantCode}", 'WARN');
        throw new Exception("This is already your current bank account. No change was made.", 400);
    }

    $bankAvail = checkBankAccountAvailability($conn, $newBankCode, $newAccountNo, $tenantCode);
    if (!$bankAvail['available']) {
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

    $oldMasked = !empty($tenant['bank_account_number'])
        ? '******' . substr($tenant['bank_account_number'], -4)
        : 'none';
    $newMasked = '******' . substr($newAccountNo, -4);
    logActivity("[TENANT_CHANGE_BANK] Changing bank for {$tenantCode}: "
        . "{$tenant['bank_name']} ({$oldMasked}) → {$newBankName} ({$newMasked})");

    $upd = $conn->prepare("
        UPDATE tenants
        SET bank_name = ?, bank_code = ?, bank_account_name = ?, bank_account_number = ?,
            bank_changed_at = NOW()
        WHERE tenant_code = ?
    ");
    if (!$upd) throw new Exception("Prepare failed (update): " . $conn->error);
    $upd->bind_param("sssss", $newBankName, $newBankCode, $newAccountName, $newAccountNo, $tenantCode);
    if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
    $upd->close();

    recordBankClaim($conn, $tenantCode, $newBankCode, $newAccountNo, $newAccountName);
    setSecretAttempts($conn, 'tenant', $tenantCode, 0, null);

    $conn->commit();
    logActivity("[TENANT_CHANGE_BANK] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[TENANT_CHANGE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_CHANGE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    exit;
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}

$newMasked = '******' . substr($newAccountNo, -4);
logActivity("[TENANT_CHANGE_BANK] ========== SUCCESS ==========");
logActivity("[TENANT_CHANGE_BANK] tenant={$tenantCode}, new_bank={$newBankName} ({$newMasked})");

json_success([
    'bank_name'         => $newBankName,
    'bank_code'         => $newBankCode,
    'bank_account_name' => $newAccountName,
    'masked_account'    => $newMasked,
], 'Bank account updated successfully');