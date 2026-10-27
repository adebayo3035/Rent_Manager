<?php
// client/backend/wallet/get_bank_history.php
// Bank account history for the logged-in client.

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
    'rate_key'   => 'client_bank_history',
    'rate_limit' => [10, 60],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

try {
    $stmt = $conn->prepare("
        SELECT id, bank_code, account_number, account_name,
               is_current, added_at, removed_at
        FROM client_bank_history
        WHERE client_code = ?
        ORDER BY added_at DESC
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("s", $clientCode);
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
    logActivity("[CLIENT_BANK_HISTORY] ERROR: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load bank history", 500);
}