<?php
// tenant/backend/wallet/get_bank_history.php
// Return the tenant's bank account history — every bank on file, past and present.
//
// SECURITY:
//   - Session-bound: only the current tenant's history is returned.
//   - Account numbers are masked to last 4 digits.
//   - Read-only — no mutation allowed from this endpoint.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'GET',
    'rate_key'   => 'tenant_bank_history',
    'rate_limit' => [10, 60],
    'roles'      => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) json_error("Session expired", 401);

try {
    $stmt = $conn->prepare("
        SELECT id, bank_code, account_number, account_name,
               is_current, added_at, removed_at
        FROM tenant_bank_history
        WHERE tenant_code = ?
        ORDER BY added_at DESC
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $res = $stmt->get_result();

    $history = [];
    $current = null;

    while ($row = $res->fetch_assoc()) {
        $masked = '******' . substr($row['account_number'], -4);
        $entry = [
            'id'                 => (int) $row['id'],
            'bank_code'          => $row['bank_code'],
            'account_masked'     => $masked,
            'account_last4'      => substr($row['account_number'], -4),
            'account_name'       => $row['account_name'],
            'is_current'         => (bool) $row['is_current'],
            'added_at'           => $row['added_at'],
            'removed_at'         => $row['removed_at'],
        ];
        $history[] = $entry;
        if ($entry['is_current'] && $current === null) {
            $current = $entry;
        }
    }
    $stmt->close();

    json_success([
        'current_bank' => $current,
        'history'      => $history,
        'total'        => count($history),
    ], 'Bank history retrieved');

} catch (Throwable $e) {
    logActivity("[TENANT_BANK_HISTORY] ERROR: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load bank history", 500);
}