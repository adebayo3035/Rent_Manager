<?php
// client/backend/reports/fetch_transactions.php
// Client (landlord) transaction history — settlements + wallet movements.

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
    'rate_key' => 'client_transaction_report',
    'rate_limit' => [30, 60],
    'roles'    => ['Client'],
]);

try {
    $clientCode = $_SESSION['client_code'] ?? null;
    if (!$clientCode) json_error("Session expired", 401);

    $type      = isset($_GET['type'])      ? trim($_GET['type'])      : '';
    $direction = isset($_GET['direction']) ? trim($_GET['direction']) : '';
    $status    = isset($_GET['status'])    ? trim($_GET['status'])    : '';
    $dateFrom  = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo    = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
    $search    = isset($_GET['search'])    ? trim($_GET['search'])    : '';
    $isExport  = !empty($_GET['export']);
    $page      = isset($_GET['page'])  ? max(1, (int)$_GET['page']) : 1;
    $limit     = $isExport ? 5000 : (isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 25);
    $offset    = $isExport ? 0 : ($page - 1) * $limit;

    // ---------- UNION across settlement_transactions + client_wallet_transactions ----------
    $union = "
        SELECT
            s.settlement_date AS transaction_date,
            'settlement_credit' AS transaction_type,
            'in' AS direction,
            s.client_share AS amount,
            CONCAT('Settlement share — ', COALESCE(p.property_code,'Property'), ' (', COALESCE(ten.tenant_code,'—'), ')') AS description,
            COALESCE(s.rent_payment_id, CONCAT('SET-', LPAD(s.id,6,'0'))) AS reference,
            COALESCE(s.settlement_status, 'completed') AS status,
            NULL AS balance_after,
            JSON_OBJECT('settlement_id', s.id, 'rent_payment_id', s.rent_payment_id, 'tracker_id', s.tracker_id, 'property_code', p.property_code, 'tenant_code', ten.tenant_code) AS metadata_json,
            'settlement_transactions' AS source_table,
            s.id AS source_id
        FROM settlement_transactions s
        LEFT JOIN properties p ON p.id = s.property_id
        LEFT JOIN tenants ten ON ten.id = s.tenant_id
        WHERE s.client_code = ?

        UNION ALL

        SELECT
            wt.created_at,
            CASE
                WHEN wt.transaction_type = 'topup'             THEN 'topup'
                WHEN wt.transaction_type = 'withdrawal'        THEN 'withdrawal'
                WHEN wt.transaction_type = 'rent_credit'       THEN 'settlement_credit'
                WHEN wt.transaction_type = 'settlement_debit'  THEN 'settlement_debit'
                WHEN wt.transaction_type = 'admin_adjustment'  THEN 'adjustment'
                WHEN wt.transaction_type = 'debt_recovery'     THEN 'debt_recovery'
                WHEN wt.transaction_type = 'refund'            THEN 'refund'
                ELSE wt.transaction_type
            END,
            CASE WHEN wt.amount >= 0 THEN 'in' ELSE 'out' END,
            ABS(wt.amount),
            COALESCE(wt.notes, wt.transaction_type),
            COALESCE(wt.request_id, CONCAT('WTX-', LPAD(wt.id,6,'0'))),
            COALESCE(wt.status, 'completed'),
            wt.running_balance,
            JSON_OBJECT('wallet_txn_id', wt.id, 'original_type', wt.transaction_type),
            'client_wallet_transactions',
            wt.id
        FROM client_wallet_transactions wt
        WHERE wt.client_code = ?
    ";

    $outerWhere = [];
    $outerParams = [$clientCode, $clientCode];
    $outerTypes  = 'ss';

    if ($type !== '') {
        $outerWhere[] = "u.transaction_type = ?";
        $outerParams[] = $type;
        $outerTypes .= 's';
    }
    if ($direction === 'in' || $direction === 'out') {
        $outerWhere[] = "u.direction = ?";
        $outerParams[] = $direction;
        $outerTypes .= 's';
    }
    if ($status !== '') {
        $outerWhere[] = "u.status = ?";
        $outerParams[] = $status;
        $outerTypes .= 's';
    }
    if ($dateFrom !== '') {
        $outerWhere[] = "DATE(u.transaction_date) >= ?";
        $outerParams[] = $dateFrom;
        $outerTypes .= 's';
    }
    if ($dateTo !== '') {
        $outerWhere[] = "DATE(u.transaction_date) <= ?";
        $outerParams[] = $dateTo;
        $outerTypes .= 's';
    }
    if ($search !== '') {
        $outerWhere[] = "(u.description LIKE ? OR u.reference LIKE ?)";
        $like = "%{$search}%";
        $outerParams[] = $like; $outerParams[] = $like;
        $outerTypes .= 'ss';
    }

    $outerWhereSql = $outerWhere ? 'WHERE ' . implode(' AND ', $outerWhere) : '';

    // ---------- Count ----------
    $countSql = "SELECT COUNT(*) AS total FROM ({$union}) u {$outerWhereSql}";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) throw new Exception("Prepare failed (count): " . $conn->error);
    $countStmt->bind_param($outerTypes, ...$outerParams);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    // ---------- Data ----------
    $dataSql = "SELECT u.* FROM ({$union}) u {$outerWhereSql} ORDER BY u.transaction_date DESC, u.source_id DESC LIMIT ? OFFSET ?";
    $dataParams = $outerParams;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $outerTypes . 'ii';
    $dataStmt = $conn->prepare($dataSql);
    if (!$dataStmt) throw new Exception("Prepare failed (data): " . $conn->error);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = [
            'transaction_id'   => $row['source_table'] . ':' . $row['source_id'],
            'transaction_date' => $row['transaction_date'],
            'transaction_type' => $row['transaction_type'],
            'direction'        => $row['direction'],
            'amount'           => (float)$row['amount'],
            'description'      => $row['description'],
            'reference'        => $row['reference'],
            'status'           => $row['status'],
            'balance_after'    => $row['balance_after'] !== null ? (float)$row['balance_after'] : null,
            'metadata'         => $row['metadata_json'] ? json_decode($row['metadata_json'], true) : [],
            'source_table'     => $row['source_table'],
            'source_id'        => (int)$row['source_id'],
        ];
    }
    $dataStmt->close();

    // ---------- Summary ----------
    $summarySql = "
        SELECT
            COALESCE(SUM(CASE WHEN u.direction='in'  THEN u.amount ELSE 0 END),0) AS total_in,
            COALESCE(SUM(CASE WHEN u.direction='out' THEN u.amount ELSE 0 END),0) AS total_out,
            COUNT(*) AS total_count
        FROM ({$union}) u {$outerWhereSql}
    ";
    $summaryStmt = $conn->prepare($summarySql);
    $summaryStmt->bind_param($outerTypes, ...$outerParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    json_success([
        'transactions' => $transactions,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 0,
        ],
        'summary' => [
            'total_in'    => (float)$summary['total_in'],
            'total_out'   => (float)$summary['total_out'],
            'net'         => (float)$summary['total_in'] - (float)$summary['total_out'],
            'total_count' => (int)$summary['total_count'],
        ],
        'export_mode' => $isExport,
        'filters' => [
            'type' => $type, 'direction' => $direction, 'status' => $status,
            'date_from' => $dateFrom, 'date_to' => $dateTo, 'search' => $search,
        ],
    ], "Transactions loaded");

} catch (Throwable $e) {
    logActivity("[CLIENT_TXN] ERROR: " . $e->getMessage(), 'ERROR');
    $code = (int)$e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}