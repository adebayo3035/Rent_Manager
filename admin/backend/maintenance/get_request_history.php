<?php
// admin/backend/maintenance/get_request_history.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('get_history_', true);
logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ========== START ==========");
logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id'])) {
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'] ?? 'Unknown';
    
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}");

    // ==================== STEP 2: GET AND VALIDATE REQUEST ID ====================
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Step 2: Getting request ID parameter");
    
    $request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
    
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Request ID: {$request_id}");

    if (!$request_id) {
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ERROR: Request ID is required or invalid");
        json_error("Request ID is required", 400);
    }
    
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Request ID validated");

    // ==================== STEP 3: VERIFY ADMIN HAS PERMISSION TO VIEW HISTORY ====================
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Step 3: Verifying admin permission");
    
    // Check if admin has access to this maintenance request
    $permission_query = "
        SELECT mr.request_id, mr.assigned_admin_id
        FROM maintenance_requests mr
        WHERE mr.request_id = ?
        LIMIT 1
    ";
    
    $perm_stmt = $conn->prepare($permission_query);
    if (!$perm_stmt) {
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] WARNING: Could not prepare permission query: " . $conn->error);
    } else {
        $perm_stmt->bind_param("i", $request_id);
        $perm_stmt->execute();
        $perm_result = $perm_stmt->get_result();
        $request_data = $perm_result->fetch_assoc();
        $perm_stmt->close();
        
        $is_super_admin = ($admin_role === 'Super Admin');
        $is_assigned = ($request_data && $request_data['assigned_admin_id'] == $admin_id);
        
        if (!$is_super_admin && !$is_assigned) {
            logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] WARNING: Admin {$admin_id} attempted to view history for request {$request_id} without permission");
            // Note: We don't block, just log warning (history is not sensitive)
        } else {
            logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Permission check passed - Admin has access");
        }
    }

    // ==================== STEP 4: FETCH HISTORY FROM DATABASE ====================
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Step 4: Fetching history from maintenance_history table");
    
    $query = "
        SELECT 
            id,
            action,
            old_value,
            new_value,
            changed_by,
            changed_by_type,
            notes,
            ip_address,
            created_at,
            DATE_FORMAT(created_at, '%b %d, %Y %h:%i %p') as formatted_date
        FROM maintenance_history
        WHERE request_id = ?
        ORDER BY created_at ASC
    ";

    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Executing query for request_id: {$request_id}");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ERROR: Failed to prepare query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->bind_param("i", $request_id);
    
    if (!$stmt->execute()) {
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ERROR: Failed to execute query: " . $stmt->error);
        $stmt->close();
        json_error("Database error", 500);
    }
    
    $result = $stmt->get_result();
    
    $history = [];
    $row_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
        $row_count++;
    }
    $stmt->close();
    
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Retrieved {$row_count} history records for request_id: {$request_id}");

    // ==================== STEP 5: LOG SUMMARY OF HISTORY ====================
    if ($row_count > 0) {
        $actions = array_unique(array_column($history, 'action'));
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] History actions found: " . implode(', ', $actions));
    } else {
        logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] No history records found for this request");
    }

    // ==================== STEP 6: RETURN SUCCESS RESPONSE ====================
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ========== SUCCESS ==========");
    
    json_success(['history' => $history], "History retrieved successfully");

} catch (Exception $e) {
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[GET_REQUEST_HISTORY] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}
?>