<?php
// client/backend/wallet/get_requests.php
// Client sees their own top-up/withdrawal request history

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'GET',
    'rate_key' => 'client_wallet_requests',
    'roles'    => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

try {
    $stmt = $conn->prepare("
        SELECT request_code, request_type, amount, status,
               rejection_reason, notes, approved_at, created_at
        FROM wallet_requests
        WHERE user_type = 'client' AND user_code = ?
        ORDER BY id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("sii", $clientCode, $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $requests = [];
    while ($row = $res->fetch_assoc()) {
        $requests[] = [
            'request_code'       => $row['request_code'],
            'type'               => $row['request_type'],
            'amount'             => (float) $row['amount'],
            'amount_formatted'   => '₦' . number_format((float) $row['amount'], 2),
            'status'             => $row['status'],
            'rejection_reason'   => $row['rejection_reason'],
            'notes'              => $row['notes'],
            'approved_at'        => $row['approved_at'],
            'created_at'         => $row['created_at'],
        ];
    }
    $stmt->close();

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM wallet_requests
        WHERE user_type = 'client' AND user_code = ?
    ");
    $countStmt->bind_param("s", $clientCode);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    json_success([
        'requests' => $requests,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ],
    ], 'Requests retrieved');

} catch (Throwable $e) {
    logActivity("Client wallet requests error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load requests", 500);
}