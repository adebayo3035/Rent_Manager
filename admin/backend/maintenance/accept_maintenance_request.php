<?php
// admin/backend/maintenance/accept_maintenance_request.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/logMaintenanceHistory.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('accept_request_', true);
logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ========== START ==========");
logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}");

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));

    $request_id = $input['request_id'] ?? null;
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Parameters - request_id: {$request_id}");

    // ==================== STEP 3: VALIDATE REQUIRED FIELDS ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 3: Validating required fields");
    
    if (!$request_id) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Request ID is required");
        json_error("Request ID is required", 400);
    }
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Required fields validated");

    // ==================== STEP 4: FETCH MAINTENANCE REQUEST ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 4: Fetching maintenance request details");
    
    $query = "
        SELECT mr.request_id, mr.status, mr.assigned_admin_id, mr.assigned_to, mr.tenant_code, mr.issue_type
        FROM maintenance_requests mr
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Failed to prepare select query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Maintenance request not found - ID: {$request_id}");
        json_error("Maintenance request not found", 404);
    }
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Request found - Status: {$request['status']}, Assigned Admin ID: {$request['assigned_admin_id']}");
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Issue type: {$request['issue_type']}, Assigned To: " . ($request['assigned_to'] ?? 'Not assigned'));

    // ==================== STEP 5: VALIDATE ADMIN AUTHORIZATION ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 5: Validating admin authorization");
    
    // Check if the current admin is the auto-assigned admin
    if ($request['assigned_admin_id'] != $admin_id) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Authorization failed - Admin {$admin_id} not assigned to request {$request_id}");
        json_error("You are not authorized to accept this request. This request was assigned to another admin.", 403);
    }
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Admin authorization verified");

    // ==================== STEP 6: CHECK ACCEPTANCE STATUS ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 6: Checking acceptance status");
    
    // Check if already accepted
    if ($request['assigned_to'] == $admin_id) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Request already accepted by this admin");
        json_error("You have already accepted this request", 400);
    }
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Acceptance status check passed");

    // ==================== STEP 7: CHECK REQUEST STATUS ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 7: Checking request status");
    
    // Check if request is still pending
    if ($request['status'] !== 'pending') {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR: Invalid status '{$request['status']}' - Cannot accept");
        json_error("Cannot accept request that is already {$request['status']}", 400);
    }
    
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Status validation passed - Request is pending");

    // ==================== STEP 8: START TRANSACTION ====================
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 8: Starting database transaction");
    $conn->begin_transaction();
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Transaction started");

    try {
        // ==================== STEP 9: UPDATE MAINTENANCE REQUEST ====================
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 9: Updating maintenance_requests table");
        
        $update_query = "
            UPDATE maintenance_requests 
            SET assigned_to = ?,
                status = 'in_progress',
                assigned_at = NOW(),
                updated_at = NOW(),
                updated_by = ?
            WHERE request_id = ?
        ";

        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) {
            throw new Exception("Failed to prepare update query: " . $conn->error);
        }
        
        $update_stmt->bind_param("iii", $admin_id, $admin_id, $request_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to execute update: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();
        
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Update completed - Affected rows: {$affected_rows}, New status: in_progress");

        // ==================== STEP 10: LOG TO MAINTENANCE HISTORY ====================
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 10: Logging to maintenance_history");
        
        logMaintenanceHistory($conn, $request_id, 'accepted', 'pending', 'in_progress', $admin_id, 'admin', 'Admin accepted the maintenance request');
        
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] History logged successfully");

        // ==================== STEP 11: CREATE NOTIFICATION FOR TENANT ====================
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 11: Creating notification for tenant");
        
        $notification_title = "Maintenance Request Accepted";
        $notification_message = "Your maintenance request #{$request_id} for '{$request['issue_type']}' has been accepted and is now in progress.";
        
        createNotification($conn, $request['tenant_code'], 'maintenance', $notification_title, $notification_message, 
            ['request_id' => $request_id], 'high');
        
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Notification created for tenant: {$request['tenant_code']}");

        // ==================== STEP 12: CREATE NOTIFICATION FOR ADMIN (Self) ====================
        $admin_notification_title = "Maintenance Request Accepted - #{$request_id}";
        $admin_notification_message = "You have accepted maintenance request #{$request_id}. Status is now 'In Progress'.";
        
        createNotification($conn, (string)$admin_id, 'maintenance', $admin_notification_title, $admin_notification_message, 
            ['request_id' => $request_id], 'medium');
        
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Notification created for admin: {$admin_id}");

        // ==================== STEP 13: COMMIT TRANSACTION ====================
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Step 13: Committing transaction");
        $conn->commit();
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 14: LOG COMPLETION ====================
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Admin {$admin_id} accepted maintenance request #{$request_id}");
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] New status: in_progress, Assigned to: {$admin_id}");

        // ==================== STEP 15: RETURN SUCCESS RESPONSE ====================
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'request_id' => $request_id,
            'assigned_to' => $admin_id,
            'status' => 'in_progress',
            'accepted_at' => date('Y-m-d H:i:s')
        ], "Maintenance request accepted successfully");

    } catch (Exception $e) {
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Rolling back transaction");
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[ACCEPT_REQUEST] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}
?>