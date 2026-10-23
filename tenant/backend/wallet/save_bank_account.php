<?php
// tenant/backend/wallet/save_bank_account.php
// Save a tenant's first bank account. Refuses if one already exists — changing
// an existing bank is a separate, harder flow (see notes).

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'tenant_setup_bank',
    'rate_limit' => [5, 600],   // 5 per 10 min — enough for retries
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

// ---- Validation ----
if ($bankName === '')    json_error("Bank name is required", 400);
if ($bankCode === '')    json_error("Bank code is required", 400);
if ($accountName === '') json_error("Account name is required", 400);
if (strlen($accountName) > 100) json_error("Account name is too long", 400);
if (!preg_match('/^\d{10}$/', $accountNo)) json_error("Account number must be 10 digits", 400);
if ($secretAns === '')   json_error("Please provide your secret answer", 400);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT secret_answer, has_secret_set, bank_account_number
        FROM tenants
        WHERE tenant_code = ?
        FOR UPDATE
    ");
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) throw new Exception("Tenant not found", 404);

    // Refuse if a bank account already exists — changing bank is a separate flow
    if (!empty($tenant['bank_account_number'])) {
        throw new Exception(
            "A bank account is already on file. Please contact support to change it.",
            409
        );
    }

    // Verify secret answer (same logic as request_withdrawal.php)
    if ((int)$tenant['has_secret_set'] !== 1 || empty($tenant['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }
    $stored = $tenant['secret_answer'];
    $ok = (strlen($stored) >= 60 && strpos($stored, '$') === 0)
        ? password_verify(strtolower($secretAns), $stored)
        : (strcasecmp(trim($secretAns), trim($stored)) === 0);

    if (!$ok) {
        logActivity("Failed bank-setup auth for {$tenantCode}", 'WARN');
        throw new Exception("Incorrect secret answer", 401);
    }

    // Save bank details
    $save = $conn->prepare("
        UPDATE tenants
        SET bank_name = ?, bank_code = ?, bank_account_name = ?, bank_account_number = ?
        WHERE tenant_code = ?
    ");
    $save->bind_param("sssss", $bankName, $bankCode, $accountName, $accountNo, $tenantCode);
    if (!$save->execute()) throw new Exception("Failed to save bank details: " . $save->error);
    $save->close();

    $conn->commit();

    $masked = '******' . substr($accountNo, -4);
    logActivity("Bank account saved for {$tenantCode}: {$bankName} ({$masked})");

    json_success([
        'bank_name'          => $bankName,
        'bank_code'          => $bankCode,
        'bank_account_name'  => $accountName,
        'bank_account_number'=> $accountNo,     // echo back for immediate pre-fill
        'masked_account'     => $masked,
        'has_bank_account'   => true,
    ], "Bank account saved");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Bank setup error for {$tenantCode}: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}