<?php
// get_agent_properties.php - Get all properties managed by a specific agent

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';

if (!isset($_SESSION)) session_start();
 rateLimiter();


// ==================== LOGGING ====================
$requestId = uniqid('agent_props_', true);
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ========== START ==========");
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// ==================== AUTHENTICATION ====================
if (!isset($_SESSION['unique_id'])) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Unauthorized request");
    json_error('Not logged in.', 401);
}

$userId = $_SESSION['unique_id'];
$userRole =$_SESSION['role'] ?? "Staff";

logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] User: {$userId} | Role: {$userRole}");

// ==================== GET PARAMETERS ====================
$agentCode = isset($_GET['agent_code']) ? trim($_GET['agent_code']) : '';
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$allowedStatus = ['0', '1'];

logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Parameters - agent_code: {$agentCode}, limit: {$limit}, page: {$page}, search: " . (empty($search) ? 'empty' : $search) . ", status: " . (empty($status) ? 'all' : $status));

// ==================== VALIDATE AGENT CODE ====================
if (empty($agentCode)) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Agent code is required");
    json_error('Agent code is required', 400);
}

if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $agentCode)) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Invalid agent code format: {$agentCode}");
    json_error('Invalid agent code', 400);
}

if ($status !== '' && !in_array($status, $allowedStatus, true)) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Invalid status filter: {$status}");
    json_error('Invalid status filter', 400);
}

// ==================== GET AGENT DETAILS ====================
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Fetching agent details for: {$agentCode}");

$agentQuery = "
    SELECT 
        agent_code,
        firstname,
        lastname,
        email,
        phone,
        photo,
        avg_rating,
        total_ratings,
        address,
        status,
        date_created,
        (SELECT COUNT(*) FROM properties WHERE agent_code = a.agent_code AND status = '1') as total_properties
    FROM agents a
    WHERE agent_code = ? AND status = 1
";

$agentStmt = $conn->prepare($agentQuery);
if (!$agentStmt) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Prepare failed for agent query: " . $conn->error);
    json_error('Database error', 500);
}

$agentStmt->bind_param("s", $agentCode);
$agentStmt->execute();
$agent = $agentStmt->get_result()->fetch_assoc();
$agentStmt->close();

if (!$agent) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Agent not found: {$agentCode}");
    json_error('Agent not found', 404);
}

logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Agent found: {$agent['firstname']} {$agent['lastname']}");

// ==================== BUILD PROPERTIES QUERY ====================
$where = ["p.agent_code = ?"];
$params = [$agentCode];
$types = "s";

// Add search filter
if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.property_code LIKE ? OR p.address LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Search filter: {$search}");
}

// Add status filter
if ($status !== '') {
    $where[] = "p.status = ?";
    $params[] = $status;
    $types .= "s";
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Status filter: {$status}");
}

$whereClause = "WHERE " . implode(" AND ", $where);

// ==================== GET TOTAL COUNT ====================
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Getting total property count");

$countQuery = "
    SELECT COUNT(*) as total
    FROM properties p
    {$whereClause}
";

$countStmt = $conn->prepare($countQuery);
if (!$countStmt) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Count prepare failed: " . $conn->error);
    json_error('Database error', 500);
}

if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRecords = $countResult->fetch_assoc()['total'];
$countStmt->close();

logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Total properties found: {$totalRecords}");

// ==================== GET PROPERTIES ====================
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Fetching properties with pagination");

$query = "
    SELECT 
        p.id,
        p.property_code,
        p.name,
        p.address,
        p.city,
        p.state,
        p.country,
        p.photo,
        p.contact_name,
        p.contact_phone,
        p.status,
        p.created_at,
        p.occupied_apartments,
        p.apartments_created,
        (p.apartments_created - p.occupied_apartments) as vacant_apartments,
        c.client_code,
        CONCAT(c.firstname, ' ', c.lastname) as client_name,
        c.photo as client_photo,
        c.phone as client_phone,
        c.email as client_email
    FROM properties p
    LEFT JOIN clients c ON p.client_code = c.client_code
    {$whereClause}
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!$stmt) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Prepare failed for property query: " . $conn->error);
    json_error('Database error', 500);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$properties = [];
while ($row = $result->fetch_assoc()) {
    // Calculate occupancy percentage
    $row['occupancy_percentage'] = $row['apartments_created'] > 0 
        ? round(($row['occupied_apartments'] / $row['apartments_created']) * 100, 1) 
        : 0;
    
    // Format dates
    $row['created_at_formatted'] = date('M d, Y', strtotime($row['created_at']));
    
    $properties[] = $row;
}
$stmt->close();

logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Properties fetched: " . count($properties));

// ==================== CALCULATE SUMMARY ====================
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Calculating summary statistics");

$summary = [
    'total_properties' => $totalRecords,
    'total_units' => 0,
    'total_occupied' => 0,
    'total_vacant' => 0,
    'clients' => 0,
    'active_properties' => 0,
    'inactive_properties' => 0
];

$uniqueClients = [];
$activeProperties = 0;
$inactiveProperties = 0;

// We need to calculate summary from ALL properties, not just current page
$summaryQuery = "
    SELECT 
        SUM(p.apartments_created) as total_units,
        SUM(p.occupied_apartments) as total_occupied,
        SUM(p.apartments_created - p.occupied_apartments) as total_vacant,
        COUNT(DISTINCT p.client_code) as unique_clients,
        SUM(CASE WHEN p.status = '1' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN p.status = '0' THEN 1 ELSE 0 END) as inactive_count
    FROM properties p
    {$whereClause}
";

$summaryStmt = $conn->prepare($summaryQuery);
if (!$summaryStmt) {
    logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ERROR: Summary prepare failed: " . $conn->error);
    $summaryStmt = null;
} else {
    // Remove limit and offset params for summary
    $summaryParams = array_slice($params, 0, count($params) - 2);
    $summaryTypes = substr($types, 0, strlen($types) - 2);
    
    if (!empty($summaryParams)) {
        $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
    }
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    $summaryData = $summaryResult->fetch_assoc();
    $summaryStmt->close();
    
    if ($summaryData) {
        $summary['total_units'] = (int)($summaryData['total_units'] ?? 0);
        $summary['total_occupied'] = (int)($summaryData['total_occupied'] ?? 0);
        $summary['total_vacant'] = (int)($summaryData['total_vacant'] ?? 0);
        $summary['clients'] = (int)($summaryData['unique_clients'] ?? 0);
        $summary['active_properties'] = (int)($summaryData['active_count'] ?? 0);
        $summary['inactive_properties'] = (int)($summaryData['inactive_count'] ?? 0);
    }
}

// Calculate occupancy rate
$summary['overall_occupancy_rate'] = $summary['total_units'] > 0 
    ? round(($summary['total_occupied'] / $summary['total_units']) * 100, 1) 
    : 0;

logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Summary calculated: " . json_encode($summary));

// ==================== RESPONSE ====================
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] Building response");
logActivity("[AGENT_PROPERTIES_API] [ID:{$requestId}] ========== END - SUCCESS ==========");

json_success('Agent properties retrieved successfully', [
    'agent' => $agent,
    'properties' => $properties,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_records' => $totalRecords,
        'total_pages' => ceil($totalRecords / $limit)
    ],
    'summary' => $summary
]);
