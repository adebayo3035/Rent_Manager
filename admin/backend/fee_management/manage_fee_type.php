<?php
// manage_fee_type.php - Complete Fee Type Management

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
rateLimit("manage_fee_type", 10, 60); 

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('manage_fee_type_', true);
logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ========== START ==========");
logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id'])) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Not logged in");
        json_error("Not logged in", 401);
    }
    
    $userRole = $_SESSION['role'] ?? '';
    $user_id = $_SESSION['unique_id'];
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] User ID: {$user_id}, Role: {$userRole}");
    
    // Check authorization - Super Admin or Admin
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Unauthorized access - Role: {$userRole}");
        json_error("Unauthorized access", 403);
    }
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Authorization passed");

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));
    
    $action = $input['action'] ?? '';
    $fee_type_id = $input['fee_type_id'] ?? null;
    $fee_code = isset($input['fee_code']) ? trim($input['fee_code']) : '';
    $fee_name = isset($input['fee_name']) ? trim($input['fee_name']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $is_mandatory = isset($input['is_mandatory']) ? (int)$input['is_mandatory'] : 1;
    $calculation_type = $input['calculation_type'] ?? 'fixed';
    $is_recurring = isset($input['is_recurring']) ? (int)$input['is_recurring'] : 0;
    $recurrence_period = $input['recurrence_period'] ?? 'one-time';
    $display_order = isset($input['display_order']) ? (int)$input['display_order'] : 0;
    $status = isset($input['status']) ? (int)$input['status'] : 1;
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Action: {$action}, Fee Type ID: {$fee_type_id}");
    
    // ==================== STEP 3: VALIDATE ACTION ====================
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Step 3: Validating action");
    
    $allowed_actions = ['create', 'update', 'toggle_status', 'delete'];
    if (!in_array($action, $allowed_actions)) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Invalid action: {$action}");
        json_error("Invalid action. Allowed: " . implode(', ', $allowed_actions), 400);
    }
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Action validated: {$action}");

    // ==================== STEP 4: PROCESS ACTION ====================
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Step 4: Processing action");
    
    switch ($action) {
        case 'create':
            handleCreate($conn, $user_id, $requestId, $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order);
            break;
            
        case 'update':
            handleUpdate($conn, $user_id, $requestId, $fee_type_id, $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order, $status);
            break;
            
        case 'toggle_status':
            handleToggleStatus($conn, $user_id, $requestId, $fee_type_id, $status);
            break;
            
        case 'delete':
            handleDelete($conn, $user_id, $requestId, $fee_type_id);
            break;
    }
    
} catch (Exception $e) {
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}

// ==================== HANDLER FUNCTIONS ====================

/**
 * Handle creating a new fee type
 */
function handleCreate($conn, $user_id, $requestId, $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order) {
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Handling CREATE action");
    
    // Validate required fields
    if (empty($fee_code)) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee code is required");
        json_error("Fee code is required", 400);
    }
    
    if (empty($fee_name)) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee name is required");
        json_error("Fee name is required", 400);
    }
    
    // Check if fee code already exists
    $check_query = "SELECT fee_type_id FROM fee_types WHERE fee_code = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $fee_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee code already exists: {$fee_code}");
        json_error("Fee code '{$fee_code}' already exists", 409);
    }
    $check_stmt->close();
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Creating fee type: {$fee_name} ({$fee_code})");
    
    $query = "INSERT INTO fee_types (fee_code, fee_name, description, is_mandatory, calculation_type, is_recurring, recurrence_period, display_order, status, created_at) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssisssi", $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order);
    
    if (!$stmt->execute()) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Failed to create fee type: " . $stmt->error);
        throw new Exception("Failed to create fee type: " . $stmt->error);
    }
    
    $fee_type_id = $stmt->insert_id;
    $stmt->close();
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Fee type created successfully - ID: {$fee_type_id}, Name: {$fee_name}, Code: {$fee_code}");
    
    json_success([
        'fee_type_id' => $fee_type_id,
        'fee_code' => $fee_code,
        'fee_name' => $fee_name
    ], "Fee type created successfully");
}

/**
 * Handle updating an existing fee type
 */
function handleUpdate($conn, $user_id, $requestId, $fee_type_id, $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order, $status) {
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Handling UPDATE action");
    
    // Validate required fields
    if (!$fee_type_id) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee type ID is required");
        json_error("Fee type ID is required", 400);
    }
    
    if (empty($fee_code)) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee code is required");
        json_error("Fee code is required", 400);
    }
    
    if (empty($fee_name)) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee name is required");
        json_error("Fee name is required", 400);
    }
    
    // Check if fee type exists
    $check_query = "SELECT fee_type_id, fee_code, fee_name, status FROM fee_types WHERE fee_type_id = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $fee_type_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $existing = $check_result->fetch_assoc();
    
    if (!$existing) {
        $check_stmt->close();
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee type not found - ID: {$fee_type_id}");
        json_error("Fee type not found", 404);
    }
    $check_stmt->close();
    
    // Check if fee code conflicts with another record
    $conflict_query = "SELECT fee_type_id FROM fee_types WHERE fee_code = ? AND fee_type_id != ? LIMIT 1";
    $conflict_stmt = $conn->prepare($conflict_query);
    $conflict_stmt->bind_param("si", $fee_code, $fee_type_id);
    $conflict_stmt->execute();
    $conflict_result = $conflict_stmt->get_result();
    
    if ($conflict_result->num_rows > 0) {
        $conflict_stmt->close();
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee code already exists: {$fee_code}");
        json_error("Fee code '{$fee_code}' already exists", 409);
    }
    $conflict_stmt->close();
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Updating fee type: {$fee_name} (ID: {$fee_type_id})");
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Old values - Code: {$existing['fee_code']}, Name: {$existing['fee_name']}");
    
    $query = "UPDATE fee_types SET 
              fee_code = ?, 
              fee_name = ?, 
              description = ?, 
              is_mandatory = ?, 
              calculation_type = ?, 
              is_recurring = ?, 
              recurrence_period = ?, 
              display_order = ?,
              status = ?,
              updated_at = NOW()
              WHERE fee_type_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssissssii", $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order, $status, $fee_type_id);
    
    if (!$stmt->execute()) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Failed to update fee type: " . $stmt->error);
        throw new Exception("Failed to update fee type: " . $stmt->error);
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Fee type updated - Affected rows: {$affected_rows}, New values - Code: {$fee_code}, Name: {$fee_name}, Status: {$status}");
    
    json_success([
        'fee_type_id' => $fee_type_id,
        'fee_code' => $fee_code,
        'fee_name' => $fee_name,
        'status' => $status
    ], "Fee type updated successfully");
}

/**
 * Handle toggling fee type status (activate/deactivate)
 */
function handleToggleStatus($conn, $user_id, $requestId, $fee_type_id, $status) {
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Handling TOGGLE_STATUS action");
    
    if (!$fee_type_id) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee type ID is required");
        json_error("Fee type ID is required", 400);
    }
    
    // Check if fee type exists
    $check_query = "SELECT fee_type_id, fee_name, fee_code, status FROM fee_types WHERE fee_type_id = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $fee_type_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $existing = $check_result->fetch_assoc();
    
    if (!$existing) {
        $check_stmt->close();
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee type not found - ID: {$fee_type_id}");
        json_error("Fee type not found", 404);
    }
    $check_stmt->close();
    
    $new_status = ($status == 1) ? 1 : 0;
    $action_text = ($new_status == 1) ? 'activated' : 'deactivated';
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Toggling fee type status - ID: {$fee_type_id}, Name: {$existing['fee_name']}, Old Status: {$existing['status']}, New Status: {$new_status}");
    
    $query = "UPDATE fee_types SET status = ?, updated_at = NOW() WHERE fee_type_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $new_status, $fee_type_id);
    
    if (!$stmt->execute()) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Failed to toggle status: " . $stmt->error);
        throw new Exception("Failed to toggle fee type status: " . $stmt->error);
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Fee type {$action_text} - ID: {$fee_type_id}, Name: {$existing['fee_name']}, Affected rows: {$affected_rows}");
    
    json_success([
        'fee_type_id' => $fee_type_id,
        'status' => $new_status,
        'status_text' => ($new_status == 1) ? 'active' : 'inactive'
    ], "Fee type {$action_text} successfully");
}

/**
 * Handle deleting a fee type (soft delete)
 */
function handleDelete($conn, $user_id, $requestId, $fee_type_id) {
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Handling DELETE action");
    
    if (!$fee_type_id) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee type ID is required");
        json_error("Fee type ID is required", 400);
    }
    
    // Check if fee type exists and get name for logging
    $check_query = "SELECT fee_type_id, fee_name, fee_code FROM fee_types WHERE fee_type_id = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $fee_type_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $existing = $check_result->fetch_assoc();
    
    if (!$existing) {
        $check_stmt->close();
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Fee type not found - ID: {$fee_type_id}");
        json_error("Fee type not found", 404);
    }
    $check_stmt->close();
    
    // Check if fee type is being used by any property fee configurations
    $usage_query = "SELECT COUNT(*) as count FROM property_apartment_type_fees WHERE fee_type_id = ? AND is_active = 1";
    $usage_stmt = $conn->prepare($usage_query);
    $usage_stmt->bind_param("i", $fee_type_id);
    $usage_stmt->execute();
    $usage_result = $usage_stmt->get_result();
    $usage = $usage_result->fetch_assoc();
    $usage_stmt->close();
    
    if ($usage['count'] > 0) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Cannot delete - Fee type is in use by {$usage['count']} property configurations");
        json_error("Cannot delete fee type. It is currently being used by {$usage['count']} property fee configuration(s). Please deactivate it first.", 409);
    }
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Deleting fee type - ID: {$fee_type_id}, Name: {$existing['fee_name']}, Code: {$existing['fee_code']}");
    
    // Hard delete (or soft delete if you prefer)
    $query = "DELETE FROM fee_types WHERE fee_type_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $fee_type_id);
    
    if (!$stmt->execute()) {
        logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] ERROR: Failed to delete fee type: " . $stmt->error);
        throw new Exception("Failed to delete fee type: " . $stmt->error);
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    logActivity("[MANAGE_FEE_TYPE] [ID:{$requestId}] Fee type deleted - ID: {$fee_type_id}, Name: {$existing['fee_name']}, Affected rows: {$affected_rows}");
    
    json_success([
        'fee_type_id' => $fee_type_id,
        'deleted' => true
    ], "Fee type deleted successfully");
}
?>