<?php
// admin/backend/wallet/list_requests.php
// Lists top-ups and withdrawals for admin review

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'GET',
    'rate_key' => 'admin_wallet_list',
    'roles'    => ['Super Admin', 'Admin'],
]);

$type   = in_array($_GET['type'] ?? '', ['topup','withdrawal']) ? $_GET['type'] : 'topup';
$status = in_array($_GET['status'] ?? '', ['pending','approved','rejected','cancelled']) 
    ? $_GET['status'] : 'pending';
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

try {
    // -- Build query
    $sql = "
        SELECT
            wr.id, wr.request_code, wr.user_type, wr.user_code,
            wr.request_type, wr.amount, wr.status,
            wr.bank_proof_path, wr.reference_number,
            wr.bank_name, wr.bank_account_name, wr.bank_account_number,
            wr.rejection_reason, wr.notes, wr.approved_by, wr.approved_at,
            wr.created_at,
            -- join names
            CASE
                WHEN wr.user_type = 'client' THEN CONCAT(c.firstname, ' ', c.lastname)
                WHEN wr.user_type = 'tenant' THEN CONCAT(t.firstname, ' ', t.lastname)
                ELSE NULL
            END AS full_name
        FROM wallet_requests wr
        LEFT JOIN clients c ON wr.user_type = 'client' AND wr.user_code = c.client_code
        LEFT JOIN tenants t ON wr.user_type = 'tenant' AND wr.user_code = t.tenant_code
        WHERE wr.request_type = ? AND wr.status = ?
        ORDER BY wr.created_at ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $type, $status, $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $requests = [];
    while ($row = $res->fetch_assoc()) {
        $requests[] = [
            'id'                   => (int) $row['id'],
            'request_code'         => $row['request_code'],
            'user_type'            => $row['user_type'],
            'user_code'            => $row['user_code'],
            'full_name'            => $row['full_name'],
            'request_type'         => $row['request_type'],
            'amount'               => (float) $row['amount'],
            'amount_formatted'     => '₦' . number_format((float) $row['amount'], 2),
            'status'               => $row['status'],
            'bank_proof_path'      => $row['bank_proof_path'],
            'reference_number'     => $row['reference_number'],
            'bank_name'            => $row['bank_name'],
            'bank_account_name'    => $row['bank_account_name'],
            'bank_account_number'  => $row['bank_account_number'],
            'rejection_reason'     => $row['rejection_reason'],
            'notes'                => $row['notes'],
            'approved_at'          => $row['approved_at'],
            'created_at'           => $row['created_at'],
        ];
    }
    $stmt->close();

    // -- Total count
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM wallet_requests
        WHERE request_type = ? AND status = ?
    ");
    $countStmt->bind_param("ss", $type, $status);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // -- Stats for tabs
    $statsStmt = $conn->query("
        SELECT
            SUM(CASE WHEN request_type='topup' AND status='pending' THEN 1 ELSE 0 END) AS pending_topups,
            SUM(CASE WHEN request_type='withdrawal' AND status='pending' THEN 1 ELSE 0 END) AS pending_withdrawals
        FROM wallet_requests
    ");
    $stats = $statsStmt->fetch_assoc();

    json_success('Requests retrieved', [
        'requests' => $requests,
        'stats' => [
            'pending_topups'      => (int) $stats['pending_topups'],
            'pending_withdrawals' => (int) $stats['pending_withdrawals'],
        ],
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ],
    ]);

} catch (Throwable $e) {
    logActivity("Admin wallet list error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load requests", 500);
}