<?php
// tenant/backend/wallet/change_bank_account.php
// Change a tenant's destination bank account.
//
// SECURITY:
//   - Refuses if pending withdrawal exists.
//   - Enforces 24h cooldown between changes.
//   - Secret answer is checked with lockout (3 fails → 15 min lock).
//   - Refuses bank accounts already used by another tenant.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
rateLimiter();

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'tenant_change_bank',
    'rate_limit' => [3, 600],
    'csrf' => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles' => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode)
    json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input))
    $input = $_POST;

$newBankName = trim($input['bank_name'] ?? '');
$newBankCode = trim($input['bank_code'] ?? '');
$newAccountName = trim($input['account_name'] ?? '');
$newAccountNo = trim($input['account_number'] ?? '');
$currentSecret = trim($input['secret_answer'] ?? '');

logActivity("[TENANT_CHANGE_BANK] ========== START ==========");
logActivity("[TENANT_CHANGE_BANK] Tenant: {$tenantCode}");

// ---- Validation ----
if ($newBankName === '')
    json_error("Bank name is required", 400);
if ($newBankCode === '')
    json_error("Bank code is required", 400);
if ($newAccountName === '')
    json_error("Account name is required", 400);
if (!preg_match('/^\d{10}$/', $newAccountNo))
    json_error("Account number must be 10 digits", 400);
if ($currentSecret === '')
    json_error("Current secret answer is required", 400);

$conn->begin_transaction();
logActivity("[TENANT_CHANGE_BANK] Transaction started");

try {
    // ---- 1) Lock tenant row ----
    $stmt = $conn->prepare("
        SELECT wallet_balance, secret_answer, has_secret_set,
               bank_name, bank_code, bank_account_name, bank_account_number, bank_changed_at
        FROM tenants WHERE tenant_code = ? FOR UPDATE
    ");
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant)
        throw new Exception("Tenant not found", 404);

    // ---- 2) Lockout check FIRST (before touching anything) ----
    $lockState = checkSecretLockout($conn, 'tenant', $tenantCode);
    if ($lockState['locked']) {
        logActivity("[TENANT_CHANGE_BANK] Blocked: account locked for {$tenantCode}", 'WARN');
        throw new Exception($lockState['message'], 429);
    }

    // ---- 3) No pending withdrawal ----
    if (countPendingWithdrawals($conn, 'tenant', $tenantCode) > 0) {
        logActivity("[TENANT_CHANGE_BANK] Blocked: pending withdrawal for {$tenantCode}", 'WARN');
        throw new Exception(
            "You have a pending withdrawal. Please wait for it to be processed before changing your bank.",
            409
        );
    }

    // ---- 4) 24h cooldown ----
    if (!empty($tenant['bank_changed_at']) && strtotime($tenant['bank_changed_at']) > time() - 86400) {
        $nextAllowed = date('Y-m-d H:i', strtotime($tenant['bank_changed_at']) + 86400);
        throw new Exception("You can only change your bank once every 24 hours. Next allowed: {$nextAllowed}", 429);
    }

    // ---- 5) Secret answer verification with lockout ----
    if ((int) $tenant['has_secret_set'] !== 1 || empty($tenant['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    $verify = verifySecretAnswer($conn, 'tenant', $tenantCode, $tenant['secret_answer'], $currentSecret);
    if (!$verify['ok']) {
        logActivity("[TENANT_CHANGE_BANK] Secret answer failed for {$tenantCode}: " . ($verify['message'] ?? ''), 'WARN');
        throw new Exception($verify['message'] ?? 'Incorrect secret answer', $verify['locked'] ? 429 : 401);
    }

    $isSameBank = (
        $tenant['bank_account_number'] === $newAccountNo
        && (string) $tenant['bank_code'] === (string) $newBankCode
    );
    if ($isSameBank) {
        logActivity("[TENANT_CHANGE_BANK] No-op: same bank submitted for {$tenantCode}", 'WARN');
        throw new Exception(
            "This is already your current bank account. No change was made.",
            400
        );
    }

    // ---- 7) Bank account uniqueness ----
    $bankAvail = checkBankAccountAvailability($conn, $newBankCode, $newAccountNo, $tenantCode);
    if (!$bankAvail['available']) {
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

    // ---- 7) Apply change ----
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
    $upd->bind_param("sssss", $newBankName, $newBankCode, $newAccountName, $newAccountNo, $tenantCode);
    if (!$upd)
        throw new Exception("Prepare failed (update): " . $conn->error);
    if (!$upd->execute())
        throw new Exception("Update failed: " . $upd->error);
    $upd->close();

    // ---- 8) Record in bank history ----
    recordBankClaim($conn, $tenantCode, $newBankCode, $newAccountNo, $newAccountName);

    // ---- 9) Reset lockout attempts on success (defense in depth) ----
    setSecretAttempts($conn, 'tenant', $tenantCode, 0, null);

    $conn->commit();
    logActivity("[TENANT_CHANGE_BANK] Transaction committed");
    logActivity("[TENANT_CHANGE_BANK] ========== SUCCESS ==========");
    logActivity("[TENANT_CHANGE_BANK] tenant={$tenantCode}, new_bank={$newBankName} ({$newMasked})");

    json_success([
        'bank_name' => $newBankName,
        'bank_code' => $newBankCode,
        'bank_account_name' => $newAccountName,
        'masked_account' => $newMasked,
    ], 'Bank account updated successfully');

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[TENANT_CHANGE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_CHANGE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}