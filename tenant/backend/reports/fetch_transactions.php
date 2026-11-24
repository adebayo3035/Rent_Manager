<?php
// tenant/backend/reports/fetch_transactions.php
// Unified transaction history — rent, fees, deposits, wallet, settlements, refunds.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
rateLimiter();

$auth = requireAuth([
    'method' => 'GET',
    'rate_key' => 'tenant_transaction_report',
    'rate_limit' => [30, 60],
    'roles' => ['Tenant'],
    'require_active_tenancy' => false,
]);

try {
    $tenantCode = $_SESSION['tenant_code'] ?? null;
    if (!$tenantCode)
        json_error("Session expired", 401);

    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $direction = isset($_GET['direction']) ? trim($_GET['direction']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $isExport = !empty($_GET['export']);
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = $isExport ? 5000 : (isset($_GET['limit']) ? min(100, max(1, (int) $_GET['limit'])) : 25);
    $offset = $isExport ? 0 : ($page - 1) * $limit;

    // ---- Unified UNION query across 5 sources ----
    // Every reference uses COALESCE so no row is missing a reference.
    $union = "
        SELECT
            rp.payment_date AS transaction_date,
            'rent_payment' AS transaction_type,
            'out' AS direction,
            rp.amount_paid AS amount,
            CONCAT('Rent payment for ', rp.payment_period) AS description,
            COALESCE(rp.receipt_number, CONCAT('RCP-', LPAD(rp.payment_id,6,'0'))) AS reference,
            rp.status AS status,
            NULL AS balance_after,
            JSON_OBJECT('rent_payment_id', rp.rent_payment_id, 'period_start', rp.period_start_date, 'period_end', rp.period_end_date) AS metadata_json,
            'rent_payments' AS source_table,
            rp.payment_id AS source_id
        FROM rent_payments rp
        WHERE rp.tenant_code = ?
          AND rp.payment_date IS NOT NULL
          AND rp.amount_paid > 0

        UNION ALL

        SELECT
            t.payment_date,
            'rent_payment',
            'out',
            t.amount_paid,
            CONCAT('Rent paid for Period #', t.period_number),
            COALESCE(t.payment_reference, rp.receipt_number, CONCAT('TRK-', LPAD(t.tracker_id,6,'0'))),
            t.status,
            NULL,
            JSON_OBJECT('tracker_id', t.tracker_id, 'period_number', t.period_number, 'period_start', t.start_date, 'period_end', t.end_date),
            'rent_payment_tracker',
            t.tracker_id
        FROM rent_payment_tracker t
        INNER JOIN rent_payments rp ON rp.rent_payment_id = t.rent_payment_id
        WHERE t.tenant_code = ?
          AND t.payment_date IS NOT NULL
          AND t.amount_paid > 0
          AND t.status IN ('paid','pending_verification','failed')

        UNION ALL

        SELECT
            tf.due_date,
            'fee',
            'out',
            tf.amount,
            CONCAT('Fee: ', COALESCE(ft.fee_name,'Unnamed Fee')),
            COALESCE(tf.receipt_number, CONCAT('FEE-', LPAD(tf.tenant_fee_id,6,'0'))),
            tf.status,
            NULL,
            JSON_OBJECT('tenant_fee_id', tf.tenant_fee_id, 'fee_type', ft.fee_code, 'due_date', tf.due_date),
            'tenant_fees',
            tf.tenant_fee_id
        FROM tenant_fees tf
        LEFT JOIN fee_types ft ON ft.fee_type_id = tf.fee_type_id
        WHERE tf.tenant_code = ?

        UNION ALL

        SELECT
            p.payment_date,
            'deposit',
            'out',
            p.amount,
            COALESCE(p.description, 'Security deposit'),
            COALESCE(p.receipt_number, CONCAT('DEP-', LPAD(p.id,6,'0'))),
            p.payment_status,
            NULL,
            JSON_OBJECT('payment_id', p.id, 'category', p.payment_category),
            'payments',
            p.id
        FROM payments p
        WHERE p.tenant_code = ?
          AND p.payment_category = 'security_deposit'

        UNION ALL

        SELECT
            wt.created_at,
            CASE
                WHEN wt.transaction_type = 'topup'             THEN 'topup'
                WHEN wt.transaction_type = 'withdrawal'        THEN 'withdrawal'
                WHEN wt.transaction_type = 'settlement_credit' THEN 'settlement'
                WHEN wt.transaction_type = 'refund'            THEN 'refund'
                WHEN wt.transaction_type IN ('adjustment','admin_adjustment') THEN 'adjustment'
                ELSE wt.transaction_type
            END,
            CASE WHEN wt.amount >= 0 THEN 'in' ELSE 'out' END,
            ABS(wt.amount),
            COALESCE(wt.notes, wt.transaction_type),
            COALESCE(wt.request_id, CONCAT('WTX-', LPAD(wt.id,6,'0'))),
            COALESCE(wt.status, 'completed'),
            wt.running_balance,
            JSON_OBJECT('wallet_txn_id', wt.id, 'original_type', wt.transaction_type),
            'tenant_wallet_transactions',
            wt.id
        FROM tenant_wallet_transactions wt
        WHERE wt.tenant_code = ?
    ";

    $outerWhere = [];
    $outerParams = [$tenantCode, $tenantCode, $tenantCode, $tenantCode, $tenantCode];
    $outerTypes = 'sssss';

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
        $outerParams[] = $like;
        $outerParams[] = $like;
        $outerTypes .= 'ss';
    }

    $outerWhereSql = $outerWhere ? 'WHERE ' . implode(' AND ', $outerWhere) : '';

    // ---- Count ----
    $countSql = "SELECT COUNT(*) AS total FROM ({$union}) u {$outerWhereSql}";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt)
        throw new Exception("Prepare failed (count): " . $conn->error);
    $countStmt->bind_param($outerTypes, ...$outerParams);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    // ---- Data ----
    $dataSql = "
        SELECT u.* FROM ({$union}) u
        {$outerWhereSql}
        ORDER BY u.transaction_date DESC, u.source_id DESC
        LIMIT ? OFFSET ?
    ";
    $dataParams = $outerParams;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $outerTypes . 'ii';

    $dataStmt = $conn->prepare($dataSql);
    if (!$dataStmt)
        throw new Exception("Prepare failed (data): " . $conn->error);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = [
            'transaction_id' => $row['source_table'] . ':' . $row['source_id'],
            'transaction_date' => $row['transaction_date'],
            'transaction_type' => $row['transaction_type'],
            'direction' => $row['direction'],
            'amount' => (float) $row['amount'],
            'description' => $row['description'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'balance_after' => $row['balance_after'] !== null ? (float) $row['balance_after'] : null,
            'metadata' => $row['metadata_json'] ? json_decode($row['metadata_json'], true) : [],
            'source_table' => $row['source_table'],
            'source_id' => (int) $row['source_id'],
        ];
    }
    $dataStmt->close();

    // ---- Summary ----
    $summarySql = "
        SELECT
            COALESCE(SUM(CASE WHEN u.direction='in'  THEN u.amount ELSE 0 END),0) AS total_in,
            COALESCE(SUM(CASE WHEN u.direction='out' THEN u.amount ELSE 0 END),0) AS total_out,
            COUNT(*) AS total_count
        FROM ({$union}) u
        {$outerWhereSql}
    ";
    $summaryStmt = $conn->prepare($summarySql);
    $summaryStmt->bind_param($outerTypes, ...$outerParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    json_success([
        'transactions' => $transactions,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
        'summary' => [
            'total_in' => (float) $summary['total_in'],
            'total_out' => (float) $summary['total_out'],
            'net' => (float) $summary['total_in'] - (float) $summary['total_out'],
            'total_count' => (int) $summary['total_count'],
        ],
        'filters' => compact('type', 'direction', 'status', 'dateFrom', 'dateTo', 'search'),
        'export_mode' => $isExport,
    ], "Transactions loaded");

} catch (Throwable $e) {
    logActivity("[TXN_REPORT] ERROR: " . $e->getMessage(), 'ERROR');
    $code = (int) $e->getCode();
    if ($code < 400 || $code >= 600)
        $code = 500;
    json_error($e->getMessage(), $code);
}