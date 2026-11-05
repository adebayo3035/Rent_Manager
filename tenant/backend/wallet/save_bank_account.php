<?php
// tenant/backend/wallet/save_bank_account.php
// Save a tenant's first bank account.
//
// IMPORTANT: Secret-answer verification runs OUTSIDE the transaction.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../../../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'tenant_setup_bank',
    'rate_limit' => [5, 600],
    'csrf'       => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles'      => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) $input = $_POST;

$bankName    = trim($input['bank_name'] ?? '');
$bankCode    = trim($input['bank_code'] ?? '');
$accountName = trim($input['account_name'] ?? '');
$accountNo   = trim($input['account_number'] ?? '');
$secretAns   = trim($input['secret_answer'] ?? '');

logActivity("[TENANT_SAVE_BANK] ========== START ==========");
logActivity("[TENANT_SAVE_BANK] Tenant: {$tenantCode}");

// ---- Input validation ----
if ($bankName === '')    json_error("Bank name is required", 400);
if ($bankCode === '')    json_error("Bank code is required", 400);
if ($accountName === '') json_error("Account name is required", 400);
if (strlen($accountName) > 100) json_error("Account name is too long", 400);
if (!preg_match('/^\d{10}$/', $accountNo)) json_error("Account number must be 10 digits", 400);
if ($secretAns === '')   json_error("Please provide your secret answer", 400);

// ============ OUTSIDE TRANSACTION: LOCKOUT + SECRET VERIFICATION ============

$lockState = checkSecretLockout($conn, 'tenant', $tenantCode);
if ($lockState['locked']) {
    logActivity("[TENANT_SAVE_BANK] Blocked: account locked for {$tenantCode}", 'WARN');
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
    SELECT secret_answer, has_secret_set, bank_account_number
    FROM tenants WHERE tenant_code = ? AND status = 1 LIMIT 1
");
if (!$stmt) throw new Exception("Prepare failed (load tenant): " . $conn->error);
$stmt->bind_param("s", $tenantCode);
if (!$stmt->execute()) throw new Exception("Load tenant failed: " . $stmt->error);
$tenantSecret = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenantSecret) json_error("Tenant not found", 404);

if (!empty($tenantSecret['bank_account_number'])) {
    logActivity("[TENANT_SAVE_BANK] Refused: bank already on file for {$tenantCode}", 'WARN');
    json_error("A bank account is already on file. Please use the change-bank flow to update it.", 409);
}

if ((int)$tenantSecret['has_secret_set'] !== 1 || empty($tenantSecret['secret_answer'])) {
    json_error("Please set up your secret question first", 400);
}

$verify = verifySecretAnswer($conn, 'tenant', $tenantCode, $tenantSecret['secret_answer'], $secretAns);

if (!$verify['ok']) {
    $isLocked = !empty($verify['locked']);
    logActivity(
        "[TENANT_SAVE_BANK] Secret answer rejected for {$tenantCode}: " . ($verify['message'] ?? ''),
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
logActivity("[TENANT_SAVE_BANK] Secret answer verified for {$tenantCode}");

// ============ INSIDE TRANSACTION ============

$conn->begin_transaction();
logActivity("[TENANT_SAVE_BANK] Transaction started");

try {
    $stmt = $conn->prepare("
        SELECT bank_account_number FROM tenants WHERE tenant_code = ? FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (lock tenant): " . $conn->error);
    $stmt->bind_param("s", $tenantCode);
    if (!$stmt->execute()) throw new Exception("Lock tenant failed: " . $stmt->error);
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) throw new Exception("Tenant not found", 404);

    if (!empty($tenant['bank_account_number'])) {
        logActivity("[TENANT_SAVE_BANK] Concurrent: bank appeared for {$tenantCode}", 'WARN');
        throw new Exception("A bank account is already on file. Please use the change-bank flow to update it.", 409);
    }

    $bankAvail = checkBankAccountAvailability($conn, $bankCode, $accountNo, $tenantCode);
    if (!$bankAvail['available']) {
        logActivity("[TENANT_SAVE_BANK] Bank reuse refused for {$tenantCode}: " . ($bankAvail['reason'] ?? ''), 'WARN');
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

    $save = $conn->prepare("
        UPDATE tenants
        SET bank_name = ?, bank_code = ?, bank_account_name = ?, bank_account_number = ?
        WHERE tenant_code = ?
    ");
    if (!$save) throw new Exception("Prepare failed (save): " . $conn->error);
    $save->bind_param("sssss", $bankName, $bankCode, $accountName, $accountNo, $tenantCode);
    if (!$save->execute()) throw new Exception("Failed to save bank details: " . $save->error);
    $save->close();

    recordBankClaim($conn, $tenantCode, $bankCode, $accountNo, $accountName);
    setSecretAttempts($conn, 'tenant', $tenantCode, 0, null);

    $conn->commit();
    logActivity("[TENANT_SAVE_BANK] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[TENANT_SAVE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_SAVE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    exit;
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}

$masked = '******' . substr($accountNo, -4);
logActivity("[TENANT_SAVE_BANK] ========== SUCCESS ==========");
logActivity("[TENANT_SAVE_BANK] tenant={$tenantCode}, bank={$bankName} ({$masked})");

json_success([
    'bank_name'           => $bankName,
    'bank_code'           => $bankCode,
    'bank_account_name'   => $accountName,
    'bank_account_number' => $accountNo,
    'masked_account'      => $masked,
    'has_bank_account'    => true,
], "Bank account saved");