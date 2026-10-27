<?php
// admin/backend/wallet/report.php
// Unified reporting endpoint for the redesigned wallet requests page.
//
// Modes (via ?tab=):
//   - inflows  : top-up requests (money entering the platform)
//   - outflows : withdrawal requests (money leaving the platform)
//   - ledger   : all wallet movements across all user types
//
// Common params:
//   status=pending|approved|rejected|cancelled|all
//   user_type=all|tenant|client|agent|admin   (ledger only)
//   search=<text>                              (request_code, user_code, name)
//   date_from=YYYY-MM-DD, date_to=YYYY-MM-DD   (both optional)
//   page=1, limit=20

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
    'rate_key' => 'admin_wallet_report',
    'roles'    => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];

// ---- Parse params ----
$tab       = in_array($_GET['tab'] ?? '', ['inflows', 'outflows', 'ledger']) ? $_GET['tab'] : 'inflows';
$status    = $_GET['status'] ?? 'pending';
$userType  = $_GET['user_type'] ?? 'all';
$search    = trim($_GET['search'] ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$limit     = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
$offset    = ($page - 1) * $limit;

// Validate enums
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'all'];
if (!in_array($status, $allowedStatuses, true)) $status = 'pending';

$allowedUserTypes = ['all', 'tenant', 'client', 'agent', 'admin'];
if (!in_array($userType, $allowedUserTypes, true)) $userType = 'all';

// Validate dates
$dateFromValid = $dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom);
$dateToValid   = $dateTo !== ''   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

logActivity("[WALLET_REPORT] ========== START ==========");
logActivity("[WALLET_REPORT] Admin: {$adminId} | tab={$tab}, status={$status}, "
    . "user_type={$userType}, search='" . substr($search, 0, 30) . "', "
    . "date_from={$dateFrom}, date_to={$dateTo}, page={$page}");

try {
    if ($tab === 'ledger') {
        $response = buildLedgerReport($conn, $userType, $search, $dateFromValid ? $dateFrom : null, $dateToValid ? $dateTo : null, $page, $limit, $offset);
    } else {
        $requestType = ($tab === 'inflows') ? 'topup' : 'withdrawal';
        $response = buildRequestReport($conn, $requestType, $status, $search, $dateFromValid ? $dateFrom : null, $dateToValid ? $dateTo : null, $page, $limit, $offset);
    }

    logActivity("[WALLET_REPORT] ========== SUCCESS ==========");
    logActivity("[WALLET_REPORT] Returned " . count($response['items']) . " rows, total=" . $response['pagination']['total']);

    json_success('Report retrieved', $response);

} catch (Throwable $e) {
    logActivity("[WALLET_REPORT] ERROR: " . $e->getMessage(), 'ERROR');
    logActivity("[WALLET_REPORT] Stack: " . $e->getTraceAsString(), 'ERROR');
    json_error("Failed to load report", 500);
}

// ============================================================
// BUILDERS
// ============================================================

function buildRequestReport(
    mysqli $conn,
    string $requestType,
    string $status,
    string $search,
    ?string $dateFrom,
    ?string $dateTo,
    int $page,
    int $limit,
    int $offset
): array {
    $where = ['wr.request_type = ?'];
    $params = [$requestType];
    $types = 's';

    if ($status !== 'all') {
        $where[] = 'wr.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($search !== '') {
        $where[] = "(wr.request_code LIKE ? OR wr.user_code LIKE ? "
                 . "OR c.firstname LIKE ? OR c.lastname LIKE ? "
                 . "OR t.firstname LIKE ? OR t.lastname LIKE ?)";
        $like = "%{$search}%";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        $types .= 'ssssss';
    }

    if ($dateFrom) {
        $where[] = 'wr.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if ($dateTo) {
        $where[] = 'wr.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Count
    $countSql = "
        SELECT COUNT(*) AS total
        FROM wallet_requests wr
        LEFT JOIN clients c ON wr.user_type = 'client' AND wr.user_code = c.client_code
        LEFT JOIN tenants t ON wr.user_type = 'tenant' AND wr.user_code = t.tenant_code
        {$whereSql}
    ";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) throw new Exception("Count prepare failed: " . $conn->error);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Rows
    $sql = "
        SELECT
            wr.id, wr.request_code, wr.user_type, wr.user_code,
            wr.request_type, wr.amount, wr.status,
            wr.bank_proof_path, wr.reference_number,
            wr.bank_name, wr.bank_account_name, wr.bank_account_number,
            wr.rejection_reason, wr.notes, wr.approved_by, wr.approved_at,
            wr.created_at,
            CASE
                WHEN wr.user_type = 'client' THEN CONCAT(c.firstname, ' ', c.lastname)
                WHEN wr.user_type = 'tenant' THEN CONCAT(t.firstname, ' ', t.lastname)
                ELSE NULL
            END AS full_name
        FROM wallet_requests wr
        LEFT JOIN clients c ON wr.user_type = 'client' AND wr.user_code = c.client_code
        LEFT JOIN tenants t ON wr.user_type = 'tenant' AND wr.user_code = t.tenant_code
        {$whereSql}
        ORDER BY wr.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("List prepare failed: " . $conn->error);
    $allParams = [...$params, $limit, $offset];
    $stmt->bind_param($types . 'ii', ...$allParams);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
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

    return [
        'items' => $items,
        'stats' => buildRequestStats($conn, $requestType),
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / max(1, $limit)),
        ],
    ];
}

function buildRequestStats(mysqli $conn, string $requestType): array
{
    $result = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'pending_amount' => 0.0];
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
        FROM wallet_requests
        WHERE request_type = ?
    ");
    if (!$stmt) return $result;
    $stmt->bind_param("s", $requestType);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $result['pending']         = (int) ($row['pending_count'] ?? 0);
        $result['approved']        = (int) ($row['approved_count'] ?? 0);
        $result['rejected']        = (int) ($row['rejected_count'] ?? 0);
        $result['pending_amount']  = (float) ($row['pending_amount'] ?? 0);
    }
    return $result;
}

function buildLedgerReport(
    mysqli $conn,
    string $userType,
    string $search,
    ?string $dateFrom,
    ?string $dateTo,
    int $page,
    int $limit,
    int $offset
): array {
    // Build a UNION of all wallet transaction tables
    $unions = [];
    $params = [];
    $types  = '';

    $buildBranch = function (string $table, string $typeLabel, string $codeCol) use ($userType, $search, $dateFrom, $dateTo, &$params, &$types) {
        $where = [];

        if ($userType === 'all' || $userType === $typeLabel) {
            // Branch is active; if userType is specific, filter to it
            if ($userType !== 'all') {
                // Matched
            }
        } else {
            return null; // Branch filtered out entirely
        }

        if ($search !== '') {
            $where[] = "({$codeCol} LIKE ? OR request_id LIKE ? OR notes LIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }
        if ($dateFrom) {
            $where[] = "created_at >= ?";
            $params[] = $dateFrom . ' 00:00:00';
            $types .= 's';
        }
        if ($dateTo) {
            $where[] = "created_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $aliasCol = "'{$typeLabel}'";

        return "
            SELECT
                t.id, {$aliasCol} AS user_type, t.{$codeCol} AS user_code,
                t.transaction_type, t.amount, t.running_balance,
                t.request_id, t.status, t.notes, t.created_at
            FROM {$table} t
            {$whereSql}
        ";
    };

    $branches = [
        $buildBranch('tenant_wallet_transactions', 'tenant', 'tenant_code'),
        $buildBranch('client_wallet_transactions', 'client', 'client_code'),
        $buildBranch('admin_wallet_transactions',  'admin',  'unique_id'),
        $buildBranch('agent_wallet_transactions',  'agent',  'agent_code'),
    ];
    $branches = array_filter($branches);   // drop nulls
    if (!$branches) {
        return [
            'items' => [],
            'stats' => ['inflow_total' => 0, 'outflow_total' => 0, 'net' => 0],
            'pagination' => ['total' => 0, 'page' => $page, 'limit' => $limit, 'total_pages' => 0],
        ];
    }

    $unionSql = implode(' UNION ALL ', $branches);

    // ---- Count ----
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ({$unionSql}) AS combined");
    if (!$countStmt) throw new Exception("Ledger count prepare failed: " . $conn->error);
    if ($params) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // ---- Rows ----
    $sql = $unionSql . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Ledger prepare failed: " . $conn->error);
    $allParams = [...$params, $limit, $offset];
    $stmt->bind_param($types . 'ii', ...$allParams);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $amount = (float) $row['amount'];
        $items[] = [
            'id'             => (int) $row['id'],
            'user_type'      => $row['user_type'],
            'user_code'      => $row['user_code'],
            'transaction_type' => $row['transaction_type'],
            'direction'      => $amount >= 0 ? 'in' : 'out',
            'amount'         => $amount,
            'amount_formatted' => ($amount >= 0 ? '+' : '') . '₦' . number_format(abs($amount), 2),
            'running_balance' => (float) $row['running_balance'],
            'request_id'     => $row['request_id'],
            'status'         => $row['status'],
            'notes'          => $row['notes'],
            'created_at'     => $row['created_at'],
        ];
    }
    $stmt->close();

    return [
        'items' => $items,
        'stats' => buildLedgerStats($conn),
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / max(1, $limit)),
        ],
    ];
}

function buildLedgerStats(mysqli $conn): array
{
    // Sum all inflows vs outflows across all four tables, last 30 days
    $stats = ['inflow_total' => 0, 'outflow_total' => 0, 'net' => 0];

    foreach (['tenant', 'client', 'admin', 'agent'] as $type) {
        $table = "{$type}_wallet_transactions";
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow,
                COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS outflow
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            $stats['inflow_total']  += (float) $row['inflow'];
            $stats['outflow_total'] += abs((float) $row['outflow']);
        }
    }
    $stats['net'] = round($stats['inflow_total'] - $stats['outflow_total'], 2);
    return $stats;
}