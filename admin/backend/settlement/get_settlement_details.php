<?php
// /backend/settlement/get_settlement_details.php
// Get detailed information for a single settlement

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// ==================== LOGGING ====================
$requestId = uniqid('settlement_details_', true);
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ========== START ==========");
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// ==================== AUTHENTICATION ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 1: Checking authentication");

if (!isset($_SESSION['unique_id']) && !isset($_SESSION['client_code']) && !isset($_SESSION['agent_code'])) {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ERROR: No session found");
    json_error("Unauthorized access", 401);
}

$userRole = $_SESSION['role'] ?? '';
$userId = $_SESSION['unique_id'] ?? '';
$clientCode = $_SESSION['client_code'] ?? '';
$agentCode = $_SESSION['agent_code'] ?? '';

logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] User: Role={$userRole}, ID={$userId}, ClientCode={$clientCode}, AgentCode={$agentCode}");

// ==================== GET PARAMETERS ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 2: Getting parameters");

$settlementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Settlement ID: {$settlementId}");

if ($settlementId <= 0) {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ERROR: Invalid settlement ID");
    json_error("Invalid settlement ID", 400);
}

// ==================== BUILD QUERY ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 3: Building query");

// FIX: Use COLLATE to handle collation mismatch
$query = "
    SELECT 
        s.id,
        s.tracker_id,
        s.payment_id,
        s.rent_payment_id,
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
        s.notes as settlement_notes,
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
        ten.apartment_code,
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
    LEFT JOIN properties p ON s.property_id = p.id
    LEFT JOIN tenants ten ON s.tenant_id = ten.id
    LEFT JOIN apartments a ON ten.apartment_code = a.apartment_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN clients cl ON s.client_code = cl.client_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN agents ag ON s.agent_code = ag.agent_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN admin_tbl adm ON s.processed_by = adm.unique_id
    WHERE s.id = ?
    LIMIT 1
";

logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Query prepared");

// ==================== EXECUTE QUERY ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 4: Executing query");

$stmt = $conn->prepare($query);
if (!$stmt) {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ERROR: Prepare failed: " . $conn->error);
    json_error("Database error occurred", 500);
}

$stmt->bind_param("i", $settlementId);

if (!$stmt->execute()) {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ERROR: Execute failed: " . $stmt->error);
    $stmt->close();
    json_error("Database error occurred", 500);
}

$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

// ==================== CHECK IF SETTLEMENT EXISTS ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 5: Checking if settlement exists");

if (!$row) {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ERROR: Settlement not found: ID={$settlementId}");
    json_error("Settlement not found", 404);
}

logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Settlement found: ID={$settlementId}, Status={$row['settlement_status']}");

// ==================== AUTHORIZATION CHECK ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 6: Authorization check");

$isAuthorized = false;

if (in_array($userRole, ['Super Admin', 'Admin'])) {
    // Super Admin: Can view ALL settlements
    // Regular Admin: Can view only settlements they processed
    if ($userRole === 'Super Admin') {
        $isAuthorized = true;
        logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Super Admin access granted");
    } else {
        // Regular Admin - check if they processed this settlement
        $processedBy = $row['processed_by'] ?? '';
        if ($processedBy == $userId) {
            $isAuthorized = true;
            logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Admin access granted: processed_by={$processedBy}");
        } else {
            logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Admin access denied: processed_by={$processedBy}, user_id={$userId}");
        }
    }
} elseif ($userRole === 'Client' && !empty($clientCode)) {
    // Client can only see their own settlements
    $settlementClientCode = $row['client_code'] ?? '';
    if ($settlementClientCode === $clientCode) {
        $isAuthorized = true;
        logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Client access granted: {$clientCode}");
    } else {
        logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Client access denied: Settlement client={$settlementClientCode}, Session client={$clientCode}");
    }
} elseif ($userRole === 'Agent' && !empty($agentCode)) {
    // Agent can only see their own settlements
    $settlementAgentCode = $row['agent_code'] ?? '';
    if ($settlementAgentCode === $agentCode) {
        $isAuthorized = true;
        logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Agent access granted: {$agentCode}");
    } else {
        logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Agent access denied: Settlement agent={$settlementAgentCode}, Session agent={$agentCode}");
    }
} else {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Unknown role: {$userRole}");
}

if (!$isAuthorized) {
    logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ERROR: Unauthorized access attempt");
    json_error("You do not have permission to view this settlement", 403);
}

// ==================== BUILD RESPONSE ====================
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Step 7: Building response");

$settlement = [
    'id' => (int)$row['id'],
    'tracker_id' => (int)$row['tracker_id'],
    'payment_id' => (int)$row['payment_id'],
    'rent_payment_id' => $row['rent_payment_id'],
    'total_amount' => (float)$row['total_rent_amount'],
    'admin_share' => (float)$row['admin_share'],
    'agent_share' => (float)$row['agent_share'],
    'client_share' => (float)$row['client_share'],
    'admin_percentage_used' => (float)$row['admin_percentage_used'],
    'agent_percentage_used' => (float)$row['agent_percentage_used'],
    'client_percentage_used' => (float)$row['client_percentage_used'],
    'settlement_status' => $row['settlement_status'],
    'admin_paid' => (bool)$row['admin_paid'],
    'agent_paid' => (bool)$row['agent_paid'],
    'client_paid' => (bool)$row['client_paid'],
    'settlement_date' => $row['settlement_date'],
    'admin_payment_date' => $row['admin_payment_date'],
    'agent_payment_date' => $row['agent_payment_date'],
    'client_payment_date' => $row['client_payment_date'],
    'notes' => $row['settlement_notes'],
    'created_at' => $row['created_at'],
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
        'apartment_code' => $row['apartment_code'],
        'apartment_number' => $row['apartment_number']
    ],
    'client' => [
        'code' => $row['client_code'],
        'name' => trim(($row['client_firstname'] ?? '') . ' ' . ($row['client_lastname'] ?? ''))
    ],
    'agent' => [
        'code' => $row['agent_code'],
        'name' => trim(($row['agent_firstname'] ?? '') . ' ' . ($row['agent_lastname'] ?? ''))
    ]
];

logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] Response built successfully");
logActivity("[SETTLEMENT_DETAILS] [ID:{$requestId}] ========== END - SUCCESS ==========");

// ==================== RESPONSE ====================
json_success($settlement, 'Settlement details retrieved successfully');
?>