<?php
// /admin/backend/settlement/get_settlement.php
// Admin settlement tracking endpoint with role-based filtering

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// ==================== LOGGING ====================
$requestId = uniqid('admin_settlements_', true);
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ========== START ==========");
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// ==================== AUTHENTICATION ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 1: Checking authentication");

if (!isset($_SESSION['unique_id'])) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: No session found");
    json_error("Unauthorized access", 401);
}

$userRole = $_SESSION['role'] ?? '';
$userId = $_SESSION['unique_id'] ?? '';

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] User: Role={$userRole}, ID={$userId}");

// Only Super Admin and Admin can access
if (!in_array($userRole, ['Super Admin', 'Admin'])) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Access denied - Role={$userRole}");
    json_error("Access denied. Admin privileges required.", 403);
}

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Authentication successful");

// ==================== GET PARAMETERS ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 2: Getting parameters");

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$payable_type = isset($_GET['payable_type']) ? trim($_GET['payable_type']) : '';

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Parameters: limit={$limit}, page={$page}, status={$status}, date_from={$date_from}, date_to={$date_to}, search={$search}, payable_type={$payable_type}");

// ==================== BUILD WHERE CLAUSE ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 3: Building WHERE clause");

$where = [];
$params = [];
$types = "";

// Role-based filtering
if ($userRole === 'Super Admin') {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Super Admin: Viewing ALL settlements");
} else {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Admin: Viewing only settlements processed by ID={$userId}");
    $where[] = "s.processed_by = ?";
    $params[] = (string)$userId;
    $types .= "s";
}

// Status filter
if (!empty($status)) {
    $where[] = "s.settlement_status = ?";
    $params[] = $status;
    $types .= "s";
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: status={$status}");
}

// Date from filter
if (!empty($date_from)) {
    $where[] = "DATE(s.settlement_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: date_from={$date_from}");
}

// Date to filter
if (!empty($date_to)) {
    $where[] = "DATE(s.settlement_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: date_to={$date_to}");
}

// Payable type filter
if (!empty($payable_type)) {
    if ($payable_type === 'admin') {
        $where[] = "s.admin_share > 0";
        logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: payable_type=admin");
    } elseif ($payable_type === 'client') {
        $where[] = "s.client_share > 0";
        logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: payable_type=client");
    } elseif ($payable_type === 'agent') {
        $where[] = "s.agent_share > 0";
        logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: payable_type=agent");
    }
}

// Search filter
if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.property_code LIKE ? OR ten.tenant_code LIKE ? OR CONCAT(ten.firstname, ' ', ten.lastname) LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Filter: search={$search}");
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
$whereClauseWithJoins = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Where clause: {$whereClause}");

// ==================== BASE QUERY WITH JOINS ====================
$joins = "
    LEFT JOIN properties p ON s.property_id = p.id
    LEFT JOIN tenants ten ON s.tenant_id = ten.id
    LEFT JOIN apartments a ON ten.apartment_code = a.apartment_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN clients cl ON s.client_code = cl.client_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN agents ag ON s.agent_code = ag.agent_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN admin_tbl adm ON s.processed_by = adm.unique_id
";

// ==================== MAIN QUERY ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 4: Building main query");

$query = "
    SELECT 
        s.id,
        s.tracker_id,
        s.payment_id,
        s.total_rent_amount,
        s.admin_share,
        s.agent_share,
        s.client_share,
        s.admin_percentage_used,
        s.agent_percentage_used,
        s.client_percentage_used,
        s.settlement_status,
        s.admin_paid,
        s.agent_paid,
        s.client_paid,
        s.settlement_date,
        s.admin_payment_date,
        s.agent_payment_date,
        s.client_payment_date,
        s.notes,
        s.created_at,
        s.processed_by,
        p.id as property_id,
        p.property_code,
        p.name as property_name,
        p.client_code,
        p.agent_code,
        ten.id as tenant_id,
        ten.tenant_code,
        CONCAT(ten.firstname, ' ', ten.lastname) as tenant_name,
        a.apartment_number,
        cl.firstname as client_firstname,
        cl.lastname as client_lastname,
        cl.client_code as client_code,
        ag.firstname as agent_firstname,
        ag.lastname as agent_lastname,
        ag.agent_code as agent_code,
        adm.firstname as admin_firstname,
        adm.lastname as admin_lastname
    FROM settlement_transactions s
    {$joins}
    {$whereClause}
    ORDER BY s.settlement_date DESC, s.created_at DESC
    LIMIT ? OFFSET ?
";

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Main query prepared");

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// ==================== GET TOTAL COUNT ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 5: Getting total count");

$countQuery = "
    SELECT COUNT(*) as total
    FROM settlement_transactions s
    {$joins}
    {$whereClause}
";

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Count query: " . $countQuery);

$countStmt = $conn->prepare($countQuery);
if (!$countStmt) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Count prepare failed: " . $conn->error);
    json_error("Database error occurred: " . $conn->error, 500);
}

// Bind parameters for count (without limit and offset)
if (!empty($params) && count($params) > 2) {
    $countParams = array_slice($params, 0, count($params) - 2);
    $countTypes = substr($types, 0, strlen($types) - 2);
    if (!empty($countParams)) {
        $countStmt->bind_param($countTypes, ...$countParams);
        logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Count bound with " . count($countParams) . " parameters");
    }
}

if (!$countStmt->execute()) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Count execute failed: " . $countStmt->error);
    $countStmt->close();
    json_error("Database error occurred: " . $countStmt->error, 500);
}

$totalResult = $countStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalRecords = (int)$totalRow['total'];
$countStmt->close();

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Total records: {$totalRecords}");

// ==================== EXECUTE MAIN QUERY ====================
$stmt = $conn->prepare($query);
if (!$stmt) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Main query prepare failed: " . $conn->error);
    json_error("Database error occurred: " . $conn->error, 500);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Main query bound with " . count($params) . " parameters");
}

if (!$stmt->execute()) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Main query execute failed: " . $stmt->error);
    $stmt->close();
    json_error("Database error occurred: " . $stmt->error, 500);
}

$result = $stmt->get_result();
$settlements = [];
$recordCount = 0;

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 6: Processing results");

while ($row = $result->fetch_assoc()) {
    $recordCount++;
    $settlements[] = [
        'id' => (int)$row['id'],
        'tracker_id' => (int)$row['tracker_id'],
        'payment_id' => (int)$row['payment_id'],
        'total_amount' => (float)$row['total_rent_amount'],
        'admin_share' => (float)$row['admin_share'],
        'agent_share' => (float)$row['agent_share'],
        'client_share' => (float)$row['client_share'],
        'admin_percentage_used' => (float)$row['admin_percentage_used'],
        'agent_percentage_used' => (float)$row['agent_percentage_used'],
        'client_percentage_used' => (float)$row['client_percentage_used'],
        'settlement_status' => $row['settlement_status'],
        'settlement_date' => $row['settlement_date'],
        'admin_paid' => (bool)$row['admin_paid'],
        'agent_paid' => (bool)$row['agent_paid'],
        'client_paid' => (bool)$row['client_paid'],
        'admin_payment_date' => $row['admin_payment_date'],
        'agent_payment_date' => $row['agent_payment_date'],
        'client_payment_date' => $row['client_payment_date'],
        'processed_by' => $row['processed_by'],
        'processed_by_name' => trim(($row['admin_firstname'] ?? '') . ' ' . ($row['admin_lastname'] ?? '')),
        'property' => [
            'id' => (int)$row['property_id'],
            'code' => $row['property_code'],
            'name' => $row['property_name']
        ],
        'tenant' => [
            'id' => (int)$row['tenant_id'],
            'code' => $row['tenant_code'],
            'name' => $row['tenant_name'],
            'apartment' => $row['apartment_number']
        ],
        'client' => [
            'code' => $row['client_code'],
            'name' => trim(($row['client_firstname'] ?? '') . ' ' . ($row['client_lastname'] ?? ''))
        ],
        'agent' => [
            'code' => $row['agent_code'],
            'name' => trim(($row['agent_firstname'] ?? '') . ' ' . ($row['agent_lastname'] ?? ''))
        ],
        'notes' => $row['notes'],
        'created_at' => $row['created_at']
    ];
}

$stmt->close();

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Records fetched: {$recordCount}");

// ==================== GET SUMMARY ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 7: Getting summary");

$summaryQuery = "
    SELECT 
        COUNT(*) as total_count,
        SUM(total_rent_amount) as total_amount,
        SUM(CASE WHEN settlement_status = 'completed' THEN total_rent_amount ELSE 0 END) as completed_amount,
        SUM(CASE WHEN settlement_status = 'pending' THEN total_rent_amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN settlement_status = 'failed' THEN total_rent_amount ELSE 0 END) as failed_amount,
        SUM(admin_share) as total_admin_share,
        SUM(agent_share) as total_agent_share,
        SUM(client_share) as total_client_share
    FROM settlement_transactions s
    {$joins}
    {$whereClause}
";

$summaryStmt = $conn->prepare($summaryQuery);
if (!$summaryStmt) {
    logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Summary prepare failed: " . $conn->error);
    $summary = null;
} else {
    if (!empty($params) && count($params) > 2) {
        $summaryParams = array_slice($params, 0, count($params) - 2);
        $summaryTypes = substr($types, 0, strlen($types) - 2);
        if (!empty($summaryParams)) {
            $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
            logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Summary bound with " . count($summaryParams) . " parameters");
        }
    }

    if ($summaryStmt->execute()) {
        $summaryResult = $summaryStmt->get_result();
        $summary = $summaryResult->fetch_assoc();
        logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Summary retrieved successfully");
    } else {
        logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ERROR: Summary execute failed: " . $summaryStmt->error);
        $summary = null;
    }
    $summaryStmt->close();
}

// ==================== RESPONSE ====================
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Step 8: Building response");
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Total pages: " . ceil($totalRecords / $limit));

$response = [
    'settlements' => $settlements,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_records' => $totalRecords,
        'total_pages' => ceil($totalRecords / $limit)
    ],
    'summary' => [
        'total_count' => (int)($summary['total_count'] ?? 0),
        'total_amount' => (float)($summary['total_amount'] ?? 0),
        'completed_amount' => (float)($summary['completed_amount'] ?? 0),
        'pending_amount' => (float)($summary['pending_amount'] ?? 0),
        'failed_amount' => (float)($summary['failed_amount'] ?? 0),
        'total_admin_share' => (float)($summary['total_admin_share'] ?? 0),
        'total_agent_share' => (float)($summary['total_agent_share'] ?? 0),
        'total_client_share' => (float)($summary['total_client_share'] ?? 0)
    ],
    'user_info' => [
        'role' => $userRole,
        'user_id' => $userId,
        'filter' => ($userRole === 'Super Admin') ? 'all_settlements' : 'my_settlements'
    ]
];

logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] Response built: " . count($settlements) . " settlements");
logActivity("[ADMIN_SETTLEMENTS] [ID:{$requestId}] ========== END - SUCCESS ==========");

json_success($response, 'Admin settlements retrieved successfully');
?>