<?php
// client/backend/evacuations/fetch_evacuations.php
// Client-scoped list of evacuations driven by evacuation_requests.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

try {
    if (!isset($_SESSION['client_code'])) {
        json_error("Not logged in", 401);
    }
    $clientCode = $_SESSION['client_code'];
    logActivity("[EVAC_LIST] Client: {$clientCode}");

    $page     = isset($_GET['page'])          ? max(1, (int) $_GET['page']) : 1;
    $limit    = isset($_GET['limit'])         ? min(100, max(1, (int) $_GET['limit'])) : 20;
    $offset   = ($page - 1) * $limit;
    $search   = isset($_GET['search'])        ? trim($_GET['search'])        : '';
    $propCode = isset($_GET['property_code']) ? trim($_GET['property_code']) : '';
    $dateFrom = isset($_GET['date_from'])     ? trim($_GET['date_from'])     : '';
    $dateTo   = isset($_GET['date_to'])       ? trim($_GET['date_to'])       : '';
    $debt     = isset($_GET['debt'])          ? trim($_GET['debt'])          : '';

    // ----------------------------------------------------------------
    // Base WHERE:
    //   - request_type = 'evacuation'
    //   - status = 'completed' (only fully settled evacuations; exclude
    //     pending_review / approved / rejected)
    //   - scoped to client via properties.client_code
    // ----------------------------------------------------------------
    $where = [
        "er.request_type = 'evacuation'",
        "er.status = 'completed'",
        "p.client_code = ?",
    ];
    $params = [$clientCode];
    $types  = 's';

    if ($search !== '') {
        $where[] = "(
            t.firstname LIKE ?
            OR t.lastname LIKE ?
            OR CONCAT(t.firstname, ' ', t.lastname) LIKE ?
            OR t.tenant_code LIKE ?
            OR er.request_id LIKE ?
        )";
        $like = "%{$search}%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sssss';
    }

    if ($propCode !== '') {
        $where[] = "p.property_code = ?";
        $params[] = $propCode;
        $types .= 's';
    }

    if ($dateFrom !== '') {
        $where[] = "DATE(COALESCE(er.approved_move_out_date, er.requested_move_out_date)) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }

    if ($dateTo !== '') {
        $where[] = "DATE(COALESCE(er.approved_move_out_date, er.requested_move_out_date)) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    if ($debt === 'with_debt') {
        $where[] = "EXISTS (
            SELECT 1 FROM tenant_fees tf
            WHERE tf.tenant_code = er.tenant_code
              AND tf.status IN ('pending', 'overdue')
        )";
    } elseif ($debt === 'clear') {
        $where[] = "NOT EXISTS (
            SELECT 1 FROM tenant_fees tf
            WHERE tf.tenant_code = er.tenant_code
              AND tf.status IN ('pending', 'overdue')
        )";
    }

    $whereSQL = "WHERE " . implode(" AND ", $where);

    // ----------------------------------------------------------------
    // Count
    // ----------------------------------------------------------------
    $countSQL = "
        SELECT COUNT(DISTINCT er.request_id) AS total
        FROM evacuation_requests er
        INNER JOIN tenants t    ON er.tenant_code    = t.tenant_code
        INNER JOIN apartments a ON er.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code   = p.property_code
        {$whereSQL}
    ";
    $countStmt = $conn->prepare($countSQL);
    if (!$countStmt) throw new Exception("Prepare failed (count): " . $conn->error);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    // ----------------------------------------------------------------
    // Data
    // ----------------------------------------------------------------
    $dataSQL = "
        SELECT
            er.request_id,
            er.tenant_code,
            er.apartment_code,
            er.status,
            er.request_type,
            er.requested_move_out_date,
            er.approved_move_out_date,
            er.reason,
            er.notes,
            er.final_settlement_amount,
            er.security_deposit_refund,
            er.settlement_receipt_number,
            er.outstanding_amount,
            er.has_outstanding_balance,
            er.processed_at,
            er.reviewed_at,
            er.created_at,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            t.photo,
            a.apartment_number,
            a.security_deposit,
            p.name AS property_name,
            p.property_code,
            (
                SELECT COALESCE(SUM(tf.amount), 0)
                FROM tenant_fees tf
                WHERE tf.tenant_code = er.tenant_code
                  AND tf.status IN ('pending', 'overdue')
            ) AS current_fees_outstanding,
            (
                SELECT COUNT(*)
                FROM tenant_fees tf
                WHERE tf.tenant_code = er.tenant_code
                  AND tf.status IN ('pending', 'overdue')
            ) AS current_fees_count
        FROM evacuation_requests er
        INNER JOIN tenants t    ON er.tenant_code    = t.tenant_code
        INNER JOIN apartments a ON er.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code   = p.property_code
        {$whereSQL}
        ORDER BY er.processed_at DESC, er.reviewed_at DESC, er.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';

    $dataStmt = $conn->prepare($dataSQL);
    if (!$dataStmt) throw new Exception("Prepare failed (data): " . $conn->error);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $moveOutDate = $row['approved_move_out_date'] ?: $row['requested_move_out_date'];
        $processedAt = $row['processed_at'] ?: $row['reviewed_at'];

        $rows[] = [
            'request_id'                => $row['request_id'],
            'tenant_code'               => $row['tenant_code'],
            'tenant_name'               => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'email'                     => $row['email'],
            'phone'                     => $row['phone'],
            'photo'                     => $row['photo'],
            'apartment_code'            => $row['apartment_code'],
            'apartment_number'          => $row['apartment_number'],
            'property_code'             => $row['property_code'],
            'property_name'             => $row['property_name'],
            'security_deposit'          => (float) ($row['security_deposit'] ?? 0),
            'move_out_date'             => $moveOutDate,
            'processed_at'              => $processedAt,
            'created_at'                => $row['created_at'],
            'reason'                    => $row['reason'],
            'final_settlement_amount'   => $row['final_settlement_amount'] !== null
                ? (float) $row['final_settlement_amount'] : null,
            'security_deposit_refund'   => $row['security_deposit_refund'] !== null
                ? (float) $row['security_deposit_refund'] : null,
            'settlement_receipt_number' => $row['settlement_receipt_number'],
            'has_outstanding_balance'   => (int) ($row['has_outstanding_balance'] ?? 0) === 1,
            'outstanding_amount'        => (float) ($row['outstanding_amount'] ?? 0),
            'current_fees_outstanding'  => (float) $row['current_fees_outstanding'],
            'current_fees_count'        => (int) $row['current_fees_count'],
        ];
    }
    $dataStmt->close();

    // ----------------------------------------------------------------
    // Stats (client-wide, unfiltered)
    // ----------------------------------------------------------------
    $statsSQL = "
        SELECT
            COUNT(DISTINCT er.request_id) AS total_evacuated,
            COALESCE(SUM(CASE WHEN er.processed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                              THEN 1 ELSE 0 END), 0) AS evacuated_last_30d,
            COALESCE(SUM(er.final_settlement_amount), 0) AS total_settled,
            COALESCE(SUM(CASE WHEN er.has_outstanding_balance = 1
                              THEN er.outstanding_amount ELSE 0 END), 0) AS total_outstanding_from_evac,
            COALESCE(SUM(
                (SELECT COALESCE(SUM(tf.amount), 0)
                 FROM tenant_fees tf
                 WHERE tf.tenant_code = er.tenant_code
                   AND tf.status IN ('pending', 'overdue'))
            ), 0) AS total_current_fees
        FROM evacuation_requests er
        INNER JOIN apartments a ON er.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code   = p.property_code
        WHERE p.client_code = ?
          AND er.request_type = 'evacuation'
          AND er.status = 'completed'
    ";
    $statsStmt = $conn->prepare($statsSQL);
    $statsStmt->bind_param("s", $clientCode);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    $totalOutstanding = (float) ($stats['total_outstanding_from_evac'] ?? 0)
                      + (float) ($stats['total_current_fees']        ?? 0);

    json_success([
        'evacuations' => $rows,
        'pagination'  => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
        'stats' => [
            'total_evacuated'        => (int)   ($stats['total_evacuated']  ?? 0),
            'evacuated_last_30d'     => (int)   ($stats['evacuated_last_30d'] ?? 0),
            'total_settled'          => (float) ($stats['total_settled']    ?? 0),
            'total_outstanding_debt' => $totalOutstanding,
        ],
        'filters' => [
            'search'        => $search,
            'property_code' => $propCode,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'debt'          => $debt,
        ],
    ], "Evacuations loaded");

} catch (Throwable $e) {
    logActivity("[EVAC_LIST] ERROR: " . $e->getMessage(), 'ERROR');
    $code = (int) $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}