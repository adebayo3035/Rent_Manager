<?php
// admin/backend/debts/list_debts.php
// Lists tenant debts with filters and stats

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
    'rate_key' => 'admin_debts_list',
    'roles'    => ['Super Admin', 'Admin'],
]);

$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

try {
    // ---------- Build WHERE ----------
    $where  = ['1=1'];
    $params = [];
    $types  = '';

    if ($status !== 'all' && in_array($status, ['unpaid','partial','resolved','written_off','disputed'], true)) {
        $where[]  = "td.status = ?";
        $params[] = $status;
        $types   .= 's';
    }

    if ($search !== '') {
        $where[]  = "(td.tenant_code LIKE ? OR td.debt_code LIKE ? OR td.source_id LIKE ? OR CONCAT(t.firstname,' ',t.lastname) LIKE ?)";
        $like     = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ssss';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // ---------- Fetch debts ----------
    $sql = "
        SELECT td.*,
               CONCAT(t.firstname, ' ', t.lastname) AS tenant_name,
               t.phone AS tenant_phone,
               t.email AS tenant_email,
               t.wallet_balance AS tenant_wallet,
               CONCAT(c.firstname, ' ', c.lastname) AS client_name
        FROM tenant_debts td
        LEFT JOIN tenants t ON t.tenant_code = td.tenant_code
        LEFT JOIN clients c ON c.client_code = td.client_code
        {$whereSql}
        ORDER BY 
            CASE td.status 
                WHEN 'unpaid' THEN 1 
                WHEN 'partial' THEN 2 
                WHEN 'disputed' THEN 3
                WHEN 'resolved' THEN 4 
                WHEN 'written_off' THEN 5
                ELSE 6 
            END,
            td.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $bindTypes = $types . 'ii';
    $bindVals  = array_merge($params, [$limit, $offset]);
    $refs = [];
    foreach ($bindVals as $k => $v) $refs[$k] = &$bindVals[$k];
    array_unshift($refs, $bindTypes);
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
    $res = $stmt->get_result();

    $debts = [];
    while ($row = $res->fetch_assoc()) {
        $created = new DateTime($row['created_at']);
        $now     = new DateTime();
        $ageDays = (int) $created->diff($now)->days;

        $debts[] = [
            'id'                       => (int) $row['id'],
            'debt_code'                => $row['debt_code'],
            'tenant_code'              => $row['tenant_code'],
            'tenant_name'              => trim($row['tenant_name'] ?? '') ?: $row['tenant_code'],
            'tenant_phone'             => $row['tenant_phone'],
            'tenant_email'             => $row['tenant_email'],
            'client_name'              => $row['client_name'],
            'source_type'              => $row['source_type'],
            'source_id'                => $row['source_id'],
            'original_amount'          => (float) $row['original_amount'],
            'original_amount_formatted'=> '₦' . number_format((float) $row['original_amount'], 2),
            'amount_paid'              => (float) $row['amount_paid'],
            'amount_paid_formatted'    => '₦' . number_format((float) $row['amount_paid'], 2),
            'balance'                  => (float) $row['balance'],
            'balance_formatted'        => '₦' . number_format((float) $row['balance'], 2),
            'offset_applied'           => (float) $row['offset_applied'],
            'offset_applied_formatted' => '₦' . number_format((float) $row['offset_applied'], 2),
            'status'                   => $row['status'],
            'notes'                    => $row['notes'],
            'age_days'                 => $ageDays,
            'created_at'               => $row['created_at'],
            'created_at_formatted'     => date('M j, Y H:i', strtotime($row['created_at'])),
            'resolved_at'              => $row['resolved_at'],
            'resolved_at_formatted'    => $row['resolved_at']
                                            ? date('M j, Y H:i', strtotime($row['resolved_at']))
                                            : null,
        ];
    }
    $stmt->close();

    // ---------- Total count ----------
    $countSql = "
        SELECT COUNT(*) AS total
        FROM tenant_debts td
        LEFT JOIN tenants t ON t.tenant_code = td.tenant_code
        {$whereSql}
    ";
    $countStmt = $conn->prepare($countSql);
    if ($types) {
        $refs = [];
        foreach ($params as $k => $v) $refs[$k] = &$params[$k];
        array_unshift($refs, $types);
        call_user_func_array([$countStmt, 'bind_param'], $refs);
    }
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // ---------- Stats ----------
    $statsResult = $conn->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = 'unpaid'      THEN 1 ELSE 0 END) AS unpaid_count,
            SUM(CASE WHEN status = 'partial'     THEN 1 ELSE 0 END) AS partial_count,
            SUM(CASE WHEN status = 'resolved'    THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN status = 'written_off' THEN 1 ELSE 0 END) AS written_off_count,
            SUM(CASE WHEN status = 'disputed'    THEN 1 ELSE 0 END) AS disputed_count,
            COALESCE(SUM(CASE WHEN status IN ('unpaid','partial','disputed') THEN balance ELSE 0 END), 0) AS outstanding_total,
            COALESCE(SUM(CASE WHEN status = 'resolved' THEN original_amount ELSE 0 END), 0) AS recovered_total
        FROM tenant_debts
    ");
    $s = $statsResult->fetch_assoc();

    json_success('Debts retrieved Successfully',[
        'debts' => $debts,
        'stats' => [
            'total_count'                 => (int) $s['total_count'],
            'unpaid_count'                => (int) $s['unpaid_count'],
            'partial_count'               => (int) $s['partial_count'],
            'resolved_count'              => (int) $s['resolved_count'],
            'written_off_count'           => (int) $s['written_off_count'],
            'disputed_count'              => (int) $s['disputed_count'],
            'outstanding_total'           => (float) $s['outstanding_total'],
            'outstanding_total_formatted' => '₦' . number_format((float) $s['outstanding_total'], 2),
            'recovered_total'             => (float) $s['recovered_total'],
            'recovered_total_formatted'   => '₦' . number_format((float) $s['recovered_total'], 2),
        ],
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ],
    ]);

} catch (Throwable $e) {
    logActivity("List debts error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load debts", 500);
}