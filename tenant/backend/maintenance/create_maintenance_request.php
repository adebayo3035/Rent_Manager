<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/logMaintenanceHistory.php';

session_start();

// Generate unique request ID for tracking
$requestTraceId = uniqid('create_req_', true);
logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ========== START ==========");
logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Request Time: " . date('Y-m-d H:i:s'));

// Helper function to auto-assign agent and admin
function autoAssignMaintenanceRequest($conn, $property_code, $requestTraceId) {
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Auto-assigning maintenance request for property: {$property_code}");
    
    // 1. Get agent assigned to this property
    $agentQuery = "
        SELECT agent_code 
        FROM properties 
        WHERE property_code = ? AND status = 1
        LIMIT 1
    ";
    $agentStmt = $conn->prepare($agentQuery);
    $agentStmt->bind_param("s", $property_code);
    $agentStmt->execute();
    $agentResult = $agentStmt->get_result();
    $agent = $agentResult->fetch_assoc();
    $agentStmt->close();
    
    $assigned_agent_code = $agent ? $agent['agent_code'] : null;
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Found agent: " . ($assigned_agent_code ?? 'None'));
    
    // 2. Get least busy admin (round-robin based on active requests)
    $adminQuery = "
        SELECT unique_id,
               (SELECT COUNT(*) FROM maintenance_requests 
                WHERE assigned_admin_id = admin_tbl.unique_id 
                AND status IN ('pending', 'in_progress')) as active_requests
        FROM admin_tbl 
        WHERE role != 'Super Admin' AND status = '1'
        ORDER BY active_requests ASC, unique_id ASC
        LIMIT 1
    ";
    $adminStmt = $conn->prepare($adminQuery);
    $adminStmt->execute();
    $adminResult = $adminStmt->get_result();
    $admin = $adminResult->fetch_assoc();
    $adminStmt->close();
    
    $assigned_admin_id = $admin ? $admin['unique_id'] : null;
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Found admin: " . ($assigned_admin_id ?? 'None'));
    
    return [
        'agent_code' => $assigned_agent_code,
        'admin_id' => $assigned_admin_id
    ];
}

// Helper function to get expected resolution days based on priority
function getExpectedResolutionDays($priority) {
    return match($priority) {
        'emergency' => 1,
        'high' => 3,
        'medium' => 7,
        'low' => 14,
        default => 7
    };
}

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: Tenant not logged in");
        json_error("Not logged in", 401);
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: Unauthorized role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Tenant code: {$tenant_code}");

    if (!$tenant_code) {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: Tenant code not found");
        json_error("Tenant code not found", 400);
    }

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Input keys: " . implode(', ', array_keys($input)));

    // ==================== STEP 3: VALIDATE REQUIRED FIELDS ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 3: Validating required fields");
    
    $required_fields = ['issue_type', 'priority', 'description'];
    $validation_errors = [];
    
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            $validation_errors[$field] = "$field is required";
            logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Missing field: {$field}");
        }
    }
    
    if (!empty($validation_errors)) {
        json_validation_error($validation_errors, "Validation failed");
    }

    $issue_type = htmlspecialchars(trim($input['issue_type']));
    $priority = htmlspecialchars(trim($input['priority']));
    $description = htmlspecialchars(trim($input['description']));
    $images = $input['images'] ?? null;
    
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Issue type: {$issue_type}, Priority: {$priority}");
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Description length: " . strlen($description));

    // ==================== STEP 4: VALIDATE PRIORITY ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 4: Validating priority");
    
    $allowed_priorities = ['low', 'medium', 'high', 'emergency'];
    if (!in_array($priority, $allowed_priorities)) {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: Invalid priority value: {$priority}");
        json_error("Invalid priority value", 400, null, 'INVALID_PRIORITY');
    }
    
    // Calculate expected resolution days based on priority
    $expected_resolution_days = getExpectedResolutionDays($priority);
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Expected resolution days: {$expected_resolution_days}");

    // ==================== STEP 5: GET TENANT APARTMENT DETAILS ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 5: Getting tenant apartment details");
    
    $tenantQuery = "
        SELECT t.apartment_code, a.property_code, p.name as property_name
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";
    $stmt = $conn->prepare($tenantQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenantData = $result->fetch_assoc();
    $stmt->close();

    if (!$tenantData || !$tenantData['apartment_code']) {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: No apartment assigned to tenant");
        json_error("No apartment assigned to this tenant", 400, null, 'NO_APARTMENT');
    }

    $apartment_code = $tenantData['apartment_code'];
    $property_code = $tenantData['property_code'];
    $property_name = $tenantData['property_name'];
    
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Apartment: {$apartment_code}, Property: {$property_name}");

    // ==================== STEP 6: AUTO-ASSIGN AGENT AND ADMIN ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 6: Auto-assigning agent and admin");
    
    $assignment = autoAssignMaintenanceRequest($conn, $property_code, $requestTraceId);
    
    // Handle images as JSON
    $images_json = !empty($images) ? json_encode($images) : null;

    // ==================== STEP 7: INSERT MAINTENANCE REQUEST ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 7: Inserting maintenance request");
    
    $insertQuery = "
        INSERT INTO maintenance_requests (
            tenant_code,
            apartment_code,
            issue_type,
            description,
            priority,
            status,
            assigned_agent_code,
            assigned_admin_id,
            auto_assigned,
            expected_resolution_days,
            images,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, TRUE, ?, ?, ?, NOW())
    ";
    
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param(
        "sssssssiss", 
        $tenant_code, 
        $apartment_code, 
        $issue_type, 
        $description, 
        $priority,
        $assignment['agent_code'],
        $assignment['admin_id'],
        $expected_resolution_days,
        $images_json,
        $tenant_code
    );
    
    if (!$stmt->execute()) {
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ERROR: Insert failed: " . $stmt->error);
        throw new Exception("Failed to create maintenance request: " . $stmt->error);
    }

    $request_id = $stmt->insert_id;
    $stmt->close();
    
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Request created successfully. Request ID: {$request_id}");

    // ==================== STEP 8: LOG MAINTENANCE HISTORY ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 8: Logging to maintenance history");
    
    $history_notes = "Maintenance request created with priority: {$priority}, Expected SLA: {$expected_resolution_days} days";
    logMaintenanceHistory($conn, $request_id, 'created', 'null', 'pending', $tenant_code, 'tenant', $history_notes);
    
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] History logged successfully");

    // ==================== STEP 9: LOG ASSIGNMENT HISTORY ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 9: Logging assignment history");
    
    if ($assignment['agent_code']) {
        $historyQuery = "
            INSERT INTO maintenance_assignments (request_id, assigned_to_type, assigned_to_id, assigned_by, is_auto, notes)
            VALUES (?, 'agent', ?, ?, TRUE, 'Auto-assigned by system')
        ";
        $historyStmt = $conn->prepare($historyQuery);
        $historyStmt->bind_param("iss", $request_id, $assignment['agent_code'], $tenant_code);
        $historyStmt->execute();
        $historyStmt->close();
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Agent assignment logged: {$assignment['agent_code']}");
    }
    
    if ($assignment['admin_id']) {
        $historyQuery = "
            INSERT INTO maintenance_assignments (request_id, assigned_to_type, assigned_to_id, assigned_by, is_auto, notes)
            VALUES (?, 'admin', ?, ?, TRUE, 'Auto-assigned by system')
        ";
        $historyStmt = $conn->prepare($historyQuery);
        $historyStmt->bind_param("iis", $request_id, $assignment['admin_id'], $tenant_code);
        $historyStmt->execute();
        $historyStmt->close();
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Admin assignment logged: {$assignment['admin_id']}");
    }

    // ==================== STEP 10: CREATE NOTIFICATIONS ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Step 10: Creating notifications");
    
    createMaintenanceNotification($conn, $tenant_code, $request_id, $issue_type, 'submitted');
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Tenant notification created");
    
    // Notify assigned agent
    if ($assignment['agent_code']) {
        createNotification(
            $conn, 
            $assignment['agent_code'], 
            'maintenance', 
            'New Maintenance Request', 
            "New maintenance request #{$request_id} from {$property_name}. Priority: {$priority}. Expected SLA: {$expected_resolution_days} days",
            ['request_id' => $request_id, 'priority' => $priority, 'expected_sla' => $expected_resolution_days],
            $priority === 'emergency' ? 'urgent' : 'high',
            '../agent/maintenance.php',
            'View Request'
        );
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Agent notification sent to: {$assignment['agent_code']}");
    }
    
    // Notify assigned admin
    if ($assignment['admin_id']) {
        createNotification(
            $conn, 
            (string)$assignment['admin_id'], 
            'maintenance', 
            'New Maintenance Request', 
            "New maintenance request #{$request_id} from {$property_name}. Priority: {$priority}. Expected SLA: {$expected_resolution_days} days",
            ['request_id' => $request_id, 'priority' => $priority, 'expected_sla' => $expected_resolution_days],
            $priority === 'emergency' ? 'urgent' : 'high',
            '../admin/maintenance.php',
            'View Request'
        );
        logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Admin notification sent to: {$assignment['admin_id']}");
    }

    // ==================== STEP 11: LOG ACTIVITY ====================
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Maintenance request created | Tenant: {$tenant_code} | Request ID: {$request_id}");
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Priority: {$priority} | Expected SLA: {$expected_resolution_days} days");
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Agent: {$assignment['agent_code']} | Admin: {$assignment['admin_id']}");

    // ==================== STEP 12: PREPARE RESPONSE ====================
    $responseData = [
        'request_id' => $request_id,
        'tenant_code' => $tenant_code,
        'apartment_code' => $apartment_code,
        'issue_type' => $issue_type,
        'priority' => $priority,
        'expected_resolution_days' => $expected_resolution_days,
        'status' => 'pending',
        'assigned_agent_code' => $assignment['agent_code'],
        'assigned_admin_id' => $assignment['admin_id'],
        'created_at' => date('Y-m-d H:i:s')
    ];

    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ========== SUCCESS ==========");
    json_created($responseData, "Maintenance request submitted successfully");

} catch (Exception $e) {
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] ========== ERROR ==========");
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Error Message: " . $e->getMessage());
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Error Code: " . $e->getCode());
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Error File: " . $e->getFile());
    logActivity("[CREATE_REQUEST] [ID:{$requestTraceId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}
?>