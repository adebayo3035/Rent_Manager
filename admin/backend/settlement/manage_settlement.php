<?php
// settlement_api.php - API endpoints for property settlement management with approval workflow

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

// ==================== LOGGING HELPER ====================
function logSettlement($message, $data = null) {
    $logMsg = "[SETTLEMENT_API] " . $message;
    if ($data !== null) {
        $logMsg .= " | " . json_encode($data);
    }
    logActivity($logMsg);
}

// ==================== INPUT VALIDATION HELPERS ====================
function validatePropertyId($id) {
    $id = filter_var($id, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) {
        return ['valid' => false, 'message' => 'Property ID must be a positive integer'];
    }
    return ['valid' => true, 'value' => $id];
}

function validatePercentage($value, $fieldName) {
    $value = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($value === false || $value === null) {
        return ['valid' => false, 'message' => "{$fieldName} must be a valid number"];
    }
    if ($value < 0 || $value > 100) {
        return ['valid' => false, 'message' => "{$fieldName} must be between 0 and 100"];
    }
    return ['valid' => true, 'value' => $value];
}

function validatePercentagesTotal($admin, $agent, $client) {
    $total = $admin + $agent + $client;
    if (abs($total - 100) > 0.01) {
        return ['valid' => false, 'message' => "Percentages must total 100%. Current total: {$total}%"];
    }
    return ['valid' => true, 'total' => $total];
}

function sanitizeNotes($notes) {
    if ($notes === null || $notes === '') {
        return '';
    }
    $notes = strip_tags($notes);
    $notes = preg_replace('/[^\w\s\-.,?!()\'\"]/', '', $notes);
    $notes = substr($notes, 0, 500);
    return trim($notes);
}

function sanitizeString($input) {
    if ($input === null || $input === '') {
        return '';
    }
    return trim(htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8'));
}

// ==================== AUTHENTICATION ====================
$userId = $_SESSION['unique_id'] ?? '';
$adminRole = $_SESSION['role'] ?? 'User';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

logSettlement("Request started", [
    'user_id' => $userId,
    'role' => $adminRole,
    'action' => $_GET['action'] ?? 'unknown',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'ip' => $ipAddress
]);

if (empty($userId)) {
    logSettlement("Authentication failed: No user ID in session");
    json_error('User not authenticated', 401);
}

if ($adminRole !== "Super Admin") {
    logSettlement("Authorization failed", [
        'user_id' => $userId,
        'role' => $adminRole,
        'required_role' => 'Super Admin'
    ]);
    json_error("You are not eligible to view this page", 403);
}

// ==================== GET ACTION ====================
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$logInput = $input;
if (isset($logInput['password'])) unset($logInput['password']);
logSettlement("Input received", [
    'action' => $action,
    'input' => $logInput
]);

try {
    switch ($action) {
        case 'get_properties':
            getProperties($userId);
            break;
            
        case 'update':
            updateSettlement($input, $userId);
            break;
            
        case 'reset':
            resetToDefault($input, $userId);
            break;
            
        case 'reset_all':
            resetAllToDefault($userId);
            break;
            
        case 'get_pending':
            getPendingRequests($userId);
            break;
            
        default:
            logSettlement("Invalid action requested", ['action' => $action]);
            json_error('Invalid action', 400);
    }
} catch (Exception $e) {
    logSettlement("FATAL ERROR", [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    json_error($e->getMessage(), 500);
}

// ==================== GET PROPERTIES ====================
function getProperties($userId) {
    global $conn;
    
    logSettlement("Fetching properties", ['user_id' => $userId]);
    
    try {
        $query = "
            SELECT 
                p.id,
                p.property_code,
                p.name AS property_name,
                p.client_code,
                p.agent_code,
                p.status,
                COALESCE(s.admin_percentage, 10.00) AS admin_percentage,
                COALESCE(s.agent_percentage, 5.00) AS agent_percentage,
                COALESCE(s.client_percentage, 85.00) AS client_percentage,
                COALESCE(s.status, 'active') AS settlement_status,
                s.updated_at,
                u.firstname AS updated_by_name
            FROM properties p
            LEFT JOIN property_settlement s ON p.id = s.property_id
            LEFT JOIN admin_tbl u ON s.updated_by = u.unique_id
            WHERE p.status = '1' 
            ORDER BY p.created_at DESC
        ";
        
        $result = $conn->query($query);
        
        if (!$result) {
            logSettlement("Database error fetching properties", [
                'error' => $conn->error
            ]);
            json_error('Failed to fetch properties: ' . $conn->error, 500);
        }
        
        $properties = [];
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $properties[] = $row;
            $count++;
        }
        
        logSettlement("Properties fetched successfully", [
            'count' => $count,
            'user_id' => $userId
        ]);
        
        json_success($properties, 'Properties retrieved successfully');
        
    } catch (Exception $e) {
        logSettlement("Error fetching properties", [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== CREATE SETTLEMENT (Helper Function) ====================
function createSettlementConfig($conn, $propertyId, $adminPct, $agentPct, $clientPct, $userId) {
    $insertSql = "
        INSERT INTO property_settlement 
        (property_id, admin_percentage, agent_percentage, client_percentage, updated_by, created_at, updated_at, status)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'active')
    ";
    
    $insertStmt = $conn->prepare($insertSql);
    if (!$insertStmt) {
        throw new Exception("Failed to prepare settlement insert: " . $conn->error);
    }
    
    $insertStmt->bind_param("iddds", $propertyId, $adminPct, $agentPct, $clientPct, $userId);
    
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create settlement: " . $insertStmt->error);
    }
    
    $insertStmt->close();
    logSettlement("Settlement configuration created", [
        'property_id' => $propertyId,
        'admin' => $adminPct,
        'agent' => $agentPct,
        'client' => $clientPct
    ]);
}

// ==================== UPDATE SETTLEMENT (Creates Proposal) ====================
function updateSettlement($input, $userId) {
    global $conn;
    
    logSettlement("Update settlement requested", ['user_id' => $userId]);
    
    // ==================== INPUT SANITIZATION ====================
    $propertyId = isset($input['property_id']) ? filter_var($input['property_id'], FILTER_SANITIZE_NUMBER_INT) : 0;
    $adminPct = isset($input['admin_percentage']) ? filter_var($input['admin_percentage'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : 0;
    $agentPct = isset($input['agent_percentage']) ? filter_var($input['agent_percentage'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : 0;
    $clientPct = isset($input['client_percentage']) ? filter_var($input['client_percentage'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : 0;
    $notes = isset($input['notes']) ? sanitizeNotes($input['notes']) : '';
    
    logSettlement("Sanitized update input", [
        'property_id' => $propertyId,
        'admin_percentage' => $adminPct,
        'agent_percentage' => $agentPct,
        'client_percentage' => $clientPct,
        'notes_length' => strlen($notes)
    ]);
    
    // ==================== INPUT VALIDATION ====================
    $propertyValidation = validatePropertyId($propertyId);
    if (!$propertyValidation['valid']) {
        logSettlement("Validation failed: " . $propertyValidation['message']);
        json_error($propertyValidation['message'], 400);
    }
    $propertyId = $propertyValidation['value'];
    
    $adminValidation = validatePercentage($adminPct, 'Admin percentage');
    if (!$adminValidation['valid']) {
        logSettlement("Validation failed: " . $adminValidation['message']);
        json_error($adminValidation['message'], 400);
    }
    $adminPct = $adminValidation['value'];
    
    $agentValidation = validatePercentage($agentPct, 'Agent percentage');
    if (!$agentValidation['valid']) {
        logSettlement("Validation failed: " . $agentValidation['message']);
        json_error($agentValidation['message'], 400);
    }
    $agentPct = $agentValidation['value'];
    
    $clientValidation = validatePercentage($clientPct, 'Client percentage');
    if (!$clientValidation['valid']) {
        logSettlement("Validation failed: " . $clientValidation['message']);
        json_error($clientValidation['message'], 400);
    }
    $clientPct = $clientValidation['value'];
    
    $totalValidation = validatePercentagesTotal($adminPct, $agentPct, $clientPct);
    if (!$totalValidation['valid']) {
        logSettlement("Validation failed: " . $totalValidation['message']);
        json_error($totalValidation['message'], 400);
    }
    
    logSettlement("All validations passed", [
        'property_id' => $propertyId,
        'admin' => $adminPct,
        'agent' => $agentPct,
        'client' => $clientPct
    ]);
    
    // ==================== CHECK PROPERTY EXISTS ====================
    $checkStmt = $conn->prepare("SELECT id, client_code, property_code FROM properties WHERE id = ? AND status = '1' ");
    if (!$checkStmt) {
        logSettlement("Database prepare error", ['error' => $conn->error]);
        json_error('Database error', 500);
    }
    
    $checkStmt->bind_param("i", $propertyId);
    if (!$checkStmt->execute()) {
        logSettlement("Database execute error", ['error' => $checkStmt->error]);
        $checkStmt->close();
        json_error('Database error', 500);
    }
    
    $result = $checkStmt->get_result();
    $property = $result->fetch_assoc();
    $checkStmt->close();
    
    if (!$property) {
        logSettlement("Property not found", ['property_id' => $propertyId]);
        json_error('Property not found', 404);
    }
    
    logSettlement("Property found", [
        'property_id' => $propertyId,
        'property_code' => $property['property_code'],
        'client_code' => $property['client_code']
    ]);
    
    // ==================== GET CURRENT SETTLEMENT VALUES ====================
    $currentStmt = $conn->prepare("SELECT admin_percentage, agent_percentage, client_percentage, status FROM property_settlement WHERE property_id = ?");
    if (!$currentStmt) {
        logSettlement("Database prepare error", ['error' => $conn->error]);
        json_error('Database error', 500);
    }
    
    $currentStmt->bind_param("i", $propertyId);
    $currentStmt->execute();
    $currentResult = $currentStmt->get_result();
    $current = $currentResult->fetch_assoc();
    $currentStmt->close();
    
    // ==================== CREATE CONFIG IF NOT EXISTS ====================
    if (!$current) {
        logSettlement("Settlement configuration not found - creating new one", ['property_id' => $propertyId]);
        
        $conn->begin_transaction();
        try {
            createSettlementConfig($conn, $propertyId, $adminPct, $agentPct, $clientPct, $userId);
            $conn->commit();
            
            logSettlement("Settlement configuration created successfully", ['property_id' => $propertyId]);
            logActivity("Admin {$userId} created settlement config for property ID: {$propertyId}");
            
            json_success([
                'property_id' => $propertyId,
                'property_code' => $property['property_code'],
                'admin_percentage' => $adminPct,
                'agent_percentage' => $agentPct,
                'client_percentage' => $clientPct,
                'status' => 'active'
            ], 'Settlement configuration created successfully.');
            return;
        } catch (Exception $e) {
            $conn->rollback();
            logSettlement("Failed to create settlement config", [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to create settlement configuration: ' . $e->getMessage(), 500);
        }
    }
    
    logSettlement("Current settlement values", [
        'property_id' => $propertyId,
        'admin' => $current['admin_percentage'],
        'agent' => $current['agent_percentage'],
        'client' => $current['client_percentage'],
        'status' => $current['status']
    ]);
    
    // ==================== CHECK IF VALUES ARE DIFFERENT ====================
    if (abs($current['admin_percentage'] - $adminPct) < 0.01 && 
        abs($current['agent_percentage'] - $agentPct) < 0.01 && 
        abs($current['client_percentage'] - $clientPct) < 0.01) {
        logSettlement("No changes detected", ['property_id' => $propertyId]);
        json_error('No changes detected. Values are already the same.', 400);
    }
    
    // ==================== CHECK FOR PENDING REQUEST ====================
    $pendingStmt = $conn->prepare("SELECT id FROM settlement_change_requests WHERE property_id = ? AND status = 'pending'");
    if (!$pendingStmt) {
        logSettlement("Database prepare error", ['error' => $conn->error]);
        json_error('Database error', 500);
    }
    
    $pendingStmt->bind_param("i", $propertyId);
    $pendingStmt->execute();
    $pendingStmt->store_result();
    
    if ($pendingStmt->num_rows > 0) {
        $pendingStmt->close();
        logSettlement("Pending request already exists", ['property_id' => $propertyId]);
        json_error('There is already a pending change request for this property. Please wait for client approval.', 400);
    }
    $pendingStmt->close();
    
    // ==================== CREATE PROPOSAL ====================
    $conn->begin_transaction();
    logSettlement("Transaction started for update proposal", ['property_id' => $propertyId]);
    
    try {
        $insertSql = "
            INSERT INTO settlement_change_requests 
            (property_id, proposed_admin_percentage, proposed_agent_percentage, proposed_client_percentage,
             current_admin_percentage, current_agent_percentage, current_client_percentage,
             proposed_by, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ";
        
        $insertStmt = $conn->prepare($insertSql);
        if (!$insertStmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $insertStmt->bind_param(
            "idddddsis",
            $propertyId,
            $adminPct,
            $agentPct,
            $clientPct,
            $current['admin_percentage'],
            $current['agent_percentage'],
            $current['client_percentage'],
            $userId,
            $notes
        );
        
        if (!$insertStmt->execute()) {
            throw new Exception("Execute failed: " . $insertStmt->error);
        }
        
        $requestId = $insertStmt->insert_id;
        $insertStmt->close();
        
        logSettlement("Change request inserted", [
            'request_id' => $requestId,
            'property_id' => $propertyId
        ]);
        
        // Update property_settlement status to 'pending'
        $updateSql = "UPDATE property_settlement SET status = 'pending' WHERE property_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $propertyId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update status: " . $updateStmt->error);
        }
        $updateStmt->close();
        
        $conn->commit();
        logSettlement("Transaction committed", [
            'request_id' => $requestId,
            'property_id' => $propertyId,
            'user_id' => $userId
        ]);
        
        logActivity("Admin {$userId} proposed settlement change for property ID: {$propertyId} (Request ID: {$requestId})");
        
        json_success([
            'request_id' => $requestId,
            'property_id' => $propertyId,
            'status' => 'pending',
            'property_code' => $property['property_code']
        ], 'Settlement change proposal submitted. Awaiting client approval.');
        
    } catch (Exception $e) {
        $conn->rollback();
        logSettlement("Transaction failed", [
            'property_id' => $propertyId,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== RESET TO DEFAULT (Creates Proposal) ====================
function resetToDefault($input, $userId) {
    global $conn;
    
    logSettlement("Reset to default requested", ['user_id' => $userId]);
    
    // ==================== INPUT SANITIZATION ====================
    $propertyId = isset($input['property_id']) ? filter_var($input['property_id'], FILTER_SANITIZE_NUMBER_INT) : 0;
    $notes = isset($input['notes']) ? sanitizeNotes($input['notes']) : 'Reset to default (10%, 5%, 85%)';
    
    logSettlement("Sanitized reset input", [
        'property_id' => $propertyId,
        'notes_length' => strlen($notes)
    ]);
    
    // ==================== INPUT VALIDATION ====================
    $propertyValidation = validatePropertyId($propertyId);
    if (!$propertyValidation['valid']) {
        logSettlement("Validation failed: " . $propertyValidation['message']);
        json_error($propertyValidation['message'], 400);
    }
    $propertyId = $propertyValidation['value'];
    
    // ==================== CHECK PROPERTY EXISTS ====================
    $checkStmt = $conn->prepare("SELECT id, client_code, property_code FROM properties WHERE id = ? AND status = '1' ");
    $checkStmt->bind_param("i", $propertyId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $property = $result->fetch_assoc();
    $checkStmt->close();
    
    if (!$property) {
        logSettlement("Property not found", ['property_id' => $propertyId]);
        json_error('Property not found', 404);
    }
    
    // ==================== GET CURRENT SETTLEMENT VALUES ====================
    $currentStmt = $conn->prepare("SELECT admin_percentage, agent_percentage, client_percentage, status FROM property_settlement WHERE property_id = ?");
    $currentStmt->bind_param("i", $propertyId);
    $currentStmt->execute();
    $currentResult = $currentStmt->get_result();
    $current = $currentResult->fetch_assoc();
    $currentStmt->close();
    
    $defaultAdmin = 10.00;
    $defaultAgent = 5.00;
    $defaultClient = 85.00;
    
    // ==================== CREATE CONFIG IF NOT EXISTS ====================
    if (!$current) {
        logSettlement("Settlement configuration not found - creating default one", ['property_id' => $propertyId]);
        
        $conn->begin_transaction();
        try {
            createSettlementConfig($conn, $propertyId, $defaultAdmin, $defaultAgent, $defaultClient, $userId);
            $conn->commit();
            
            logSettlement("Default settlement configuration created", ['property_id' => $propertyId]);
            logActivity("Admin {$userId} created default settlement config for property ID: {$propertyId}");
            
            json_success([
                'property_id' => $propertyId,
                'property_code' => $property['property_code'],
                'admin_percentage' => $defaultAdmin,
                'agent_percentage' => $defaultAgent,
                'client_percentage' => $defaultClient,
                'status' => 'active'
            ], 'Default settlement configuration created successfully (10%, 5%, 85%).');
            return;
        } catch (Exception $e) {
            $conn->rollback();
            logSettlement("Failed to create default settlement config", [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to create settlement configuration: ' . $e->getMessage(), 500);
        }
    }
    
    // ==================== CHECK IF ALREADY AT DEFAULT ====================
    if (abs($current['admin_percentage'] - $defaultAdmin) < 0.01 && 
        abs($current['agent_percentage'] - $defaultAgent) < 0.01 && 
        abs($current['client_percentage'] - $defaultClient) < 0.01) {
        logSettlement("Already at default values", ['property_id' => $propertyId]);
        json_error('Settlement is already at default values (10%, 5%, 85%)', 400);
    }
    
    // ==================== CHECK FOR PENDING REQUEST ====================
    $pendingStmt = $conn->prepare("SELECT id FROM settlement_change_requests WHERE property_id = ? AND status = 'pending'");
    $pendingStmt->bind_param("i", $propertyId);
    $pendingStmt->execute();
    $pendingStmt->store_result();
    
    if ($pendingStmt->num_rows > 0) {
        $pendingStmt->close();
        logSettlement("Pending request exists", ['property_id' => $propertyId]);
        json_error('There is already a pending change request for this property. Please wait for client approval.', 400);
    }
    $pendingStmt->close();
    
    // ==================== CREATE PROPOSAL ====================
    $conn->begin_transaction();
    logSettlement("Transaction started for reset proposal", ['property_id' => $propertyId]);
    
    try {
        $insertSql = "
            INSERT INTO settlement_change_requests 
            (property_id, proposed_admin_percentage, proposed_agent_percentage, proposed_client_percentage,
             current_admin_percentage, current_agent_percentage, current_client_percentage,
             proposed_by, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ";
        
        $insertStmt = $conn->prepare($insertSql);
        $insertStmt->bind_param(
            "idddddsis",
            $propertyId,
            $defaultAdmin,
            $defaultAgent,
            $defaultClient,
            $current['admin_percentage'],
            $current['agent_percentage'],
            $current['client_percentage'],
            $userId,
            $notes
        );
        
        if (!$insertStmt->execute()) {
            throw new Exception("Execute failed: " . $insertStmt->error);
        }
        
        $requestId = $insertStmt->insert_id;
        $insertStmt->close();
        
        // Update property_settlement status to 'pending'
        $updateSql = "UPDATE property_settlement SET status = 'pending' WHERE property_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $propertyId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update status: " . $updateStmt->error);
        }
        $updateStmt->close();
        
        $conn->commit();
        logSettlement("Transaction committed", [
            'request_id' => $requestId,
            'property_id' => $propertyId
        ]);
        
        logActivity("Admin {$userId} proposed reset to default for property ID: {$propertyId} (Request ID: {$requestId})");
        
        json_success([
            'request_id' => $requestId,
            'property_id' => $propertyId,
            'status' => 'pending',
            'property_code' => $property['property_code']
        ], 'Reset to default proposal submitted. Awaiting client approval.');
        
    } catch (Exception $e) {
        $conn->rollback();
        logSettlement("Transaction failed", [
            'property_id' => $propertyId,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== RESET ALL (Creates Individual Proposals) ====================
function resetAllToDefault($userId) {
    global $conn;
    
    logSettlement("Reset all to default requested", ['user_id' => $userId]);
    
    // Get all properties (including those WITHOUT settlement config)
    $query = "
        SELECT 
            p.id,
            p.client_code,
            p.property_code,
            s.admin_percentage,
            s.agent_percentage,
            s.client_percentage,
            s.status
        FROM properties p
        LEFT JOIN property_settlement s ON p.id = s.property_id
        WHERE p.status = '1'
    ";
    
    $result = $conn->query($query);
    
    if (!$result || $result->num_rows === 0) {
        logSettlement("No properties found");
        json_error('No properties found', 404);
    }
    
    $totalProperties = $result->num_rows;
    logSettlement("Total properties found", ['count' => $totalProperties]);
    
    $defaultAdmin = 10.00;
    $defaultAgent = 5.00;
    $defaultClient = 85.00;
    $processed = 0;
    $skipped = 0;
    $created = 0;
    $errors = [];
    
    $conn->begin_transaction();
    
    try {
        while ($row = $result->fetch_assoc()) {
            $propertyId = $row['id'];
            
            // If no config exists, create one with defaults
            if (!isset($row['admin_percentage']) || $row['admin_percentage'] === null) {
                logSettlement("Creating default config for property (no existing config)", [
                    'property_id' => $propertyId,
                    'property_code' => $row['property_code']
                ]);
                
                createSettlementConfig($conn, $propertyId, $defaultAdmin, $defaultAgent, $defaultClient, $userId);
                $created++;
                continue;
            }
            
            // Check if already at default
            if (abs($row['admin_percentage'] - $defaultAdmin) < 0.01 && 
                abs($row['agent_percentage'] - $defaultAgent) < 0.01 && 
                abs($row['client_percentage'] - $defaultClient) < 0.01) {
                $skipped++;
                logSettlement("Skipped property (already at default)", [
                    'property_id' => $propertyId,
                    'property_code' => $row['property_code']
                ]);
                continue;
            }
            
            // Check if there's already a pending request for this property
            $pendingStmt = $conn->prepare("SELECT id FROM settlement_change_requests WHERE property_id = ? AND status = 'pending'");
            $pendingStmt->bind_param("i", $propertyId);
            $pendingStmt->execute();
            $pendingStmt->store_result();
            
            if ($pendingStmt->num_rows > 0) {
                $pendingStmt->close();
                $errors[] = "Property ID {$propertyId} ({$row['property_code']}) has a pending request. Skipping.";
                logSettlement("Skipped property (has pending request)", [
                    'property_id' => $propertyId,
                    'property_code' => $row['property_code']
                ]);
                continue;
            }
            $pendingStmt->close();
            
            // Insert change request
            $insertSql = "
                INSERT INTO settlement_change_requests 
                (property_id, proposed_admin_percentage, proposed_agent_percentage, proposed_client_percentage,
                 current_admin_percentage, current_agent_percentage, current_client_percentage,
                 proposed_by, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ";
            
            $notes = 'Reset all to default (10%, 5%, 85%)';
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param(
                "idddddsis",
                $propertyId,
                $defaultAdmin,
                $defaultAgent,
                $defaultClient,
                $row['admin_percentage'],
                $row['agent_percentage'],
                $row['client_percentage'],
                $userId,
                $notes
            );
            
            if (!$insertStmt->execute()) {
                $errors[] = "Failed to create request for property ID {$propertyId} ({$row['property_code']}): " . $insertStmt->error;
                $insertStmt->close();
                logSettlement("Failed to create request", [
                    'property_id' => $propertyId,
                    'error' => $insertStmt->error
                ]);
                continue;
            }
            $insertStmt->close();
            
            // Update property_settlement status to 'pending'
            $updateSql = "UPDATE property_settlement SET status = 'pending' WHERE property_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("i", $propertyId);
            $updateStmt->execute();
            $updateStmt->close();
            
            $processed++;
            logSettlement("Created reset proposal", [
                'property_id' => $propertyId,
                'property_code' => $row['property_code']
            ]);
        }
        
        $conn->commit();
        
        $message = "{$processed} properties reset to default.";
        if ($created > 0) {
            $message .= " {$created} new settlement configs created with defaults.";
        }
        if ($skipped > 0) {
            $message .= " {$skipped} properties were already at default values.";
        }
        if (!empty($errors)) {
            $message .= " Errors: " . implode("; ", $errors);
        }
        
        logSettlement("Reset all completed", [
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'errors_count' => count($errors)
        ]);
        
        logActivity("Admin {$userId} proposed reset all to default. Processed: {$processed}, Created: {$created}, Skipped: {$skipped}, Errors: " . count($errors));
        
        json_success([
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors
        ], $message);
        
    } catch (Exception $e) {
        $conn->rollback();
        logSettlement("Reset all failed", [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ==================== GET PENDING REQUESTS ====================
function getPendingRequests($userId) {
    global $conn;
    
    logSettlement("Fetching pending requests", ['user_id' => $userId]);
    
    $query = "
        SELECT 
            scr.*,
            p.property_code,
            p.name AS property_name,
            p.client_code,
            u.firstname AS proposed_by_name
        FROM settlement_change_requests scr
        JOIN properties p ON scr.property_id = p.id
        LEFT JOIN admin_tbl u ON scr.proposed_by = u.unique_id
        WHERE scr.status = 'pending'
        ORDER BY scr.proposed_at DESC
    ";
    
    $result = $conn->query($query);
    $requests = [];
    $count = 0;
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
            $count++;
        }
    }
    
    logSettlement("Pending requests fetched", [
        'count' => $count,
        'user_id' => $userId
    ]);
    
    json_success($requests, 'Pending requests retrieved successfully');
}