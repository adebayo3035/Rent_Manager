<?php
// tenant/backend/wallet/inquiry_beneficiary.php
// Return the tenant's saved beneficiary details for confirmation before withdrawal.
// SECURITY: The name is read from the DB, never from the client.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'                   => 'POST',
    'rate_key'                 => 'tenant_inquiry',
    'rate_limit'               => [20, 60],
    'csrf'                     => ['enabled' => true, 'form_name' => 'tenant_withdrawal_form'],
    'roles'                    => ['Tenant'],
    'require_active_tenancy'   => true,
    'allowed_tenancy_statuses' => [1, 3],   // Active + Evacuated — must match request_withdrawal.php
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) {
    json_error("Session expired", 401);
}

$input = $GLOBALS['__REQUEST_BODY'] ?? [];
if (!is_array($input)) {
    json_error("Invalid request body", 400);
}

$accountNo = trim($input['account_number'] ?? '');
$bankCode  = trim($input['bank_code'] ?? '');

if (!preg_match('/^\d{10}$/', $accountNo)) {
    json_error("Account number must be 10 digits", 400);
}
if ($bankCode === '') {
    json_error("Bank is required", 400);
}

logActivity("[TENANT_INQUIRY] Tenant={$tenantCode} account=****" . substr($accountNo, -4));

$stmt = $conn->prepare("
    SELECT bank_name, bank_account_name, bank_account_number, bank_code
    FROM tenants
    WHERE tenant_code = ?
    LIMIT 1
");
if (!$stmt) {
    logActivity("[TENANT_INQUIRY] Prepare failed: " . $conn->error, 'ERROR');
    json_error("Unable to verify beneficiary. Please try again.", 500);
}
$stmt->bind_param("s", $tenantCode);
if (!$stmt->execute()) {
    logActivity("[TENANT_INQUIRY] Execute failed: " . $stmt->error, 'ERROR');
    json_error("Unable to verify beneficiary. Please try again.", 500);
}
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenant || empty($tenant['bank_account_number'])) {
    logActivity("[TENANT_INQUIRY] No bank on file for {$tenantCode}", 'WARN');
    json_error("No bank account on file. Please add one first.", 404);
}

if (
    $tenant['bank_account_number'] !== $accountNo
    || (string) $tenant['bank_code'] !== (string) $bankCode
) {
    logActivity("[TENANT_INQUIRY] Mismatch for {$tenantCode}", 'WARN');
    json_error("No matching beneficiary found for that account and bank.", 404);
}

$masked = '******' . substr($tenant['bank_account_number'], -4);

logActivity("[TENANT_INQUIRY] Verified beneficiary for {$tenantCode} ({$masked})");

json_success([
    'beneficiary_name' => strtoupper($tenant['bank_account_name']),
    'bank_name'        => $tenant['bank_name'],
    'bank_code'        => $tenant['bank_code'],
    'masked_account'   => $masked,
], "Beneficiary verified");