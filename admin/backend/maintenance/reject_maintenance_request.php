<?php
// admin/backend/maintenance/reject_maintenance_request.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/logMaintenanceHistory.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('reject_request_', true);
logActivity("[REJECT_REQUEST] [ID:{$requestId}] ========== START ==========");
logActivity("[REJECT_REQUEST] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}");

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));

    $request_id = $input['request_id'] ?? null;
    $rejection_reason = $input['rejection_reason'] ?? null;
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Parameters - request_id: {$request_id}");
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] rejection_reason length: " . strlen($rejection_reason ?? ''));

    // ==================== STEP 3: VALIDATE REQUIRED FIELDS ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 3: Validating required fields");
    
    if (!$request_id) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Request ID is required");
        json_error("Request ID is required", 400);
    }
    
    if (empty($rejection_reason) || trim($rejection_reason) === '') {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Rejection reason is required or empty");
        json_error("Rejection reason is required", 400);
    }
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Required fields validated");

    // ==================== STEP 4: FETCH MAINTENANCE REQUEST ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 4: Fetching maintenance request details");
    
    $query = "
        SELECT mr.request_id, mr.status, mr.assigned_admin_id, mr.assigned_to, mr.tenant_code, mr.issue_type
        FROM maintenance_requests mr
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Failed to prepare select query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Maintenance request not found - ID: {$request_id}");
        json_error("Maintenance request not found", 404);
    }
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Request found - Status: {$request['status']}, Assigned Admin ID: {$request['assigned_admin_id']}");
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Issue type: {$request['issue_type']}");

    // ==================== STEP 5: VALIDATE ADMIN AUTHORIZATION ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 5: Validating admin authorization");
    
    // Check if the current admin is the auto-assigned admin
    if ($request['assigned_admin_id'] != $admin_id) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Authorization failed - Admin {$admin_id} not assigned to request");
        json_error("You are not authorized to reject this request", 403);
    }
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Admin authorization verified");

    // ==================== STEP 6: CHECK REQUEST STATUS ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 6: Checking request status");
    
    // Check if already accepted
    if ($request['assigned_to'] == $admin_id) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Request already accepted by this admin");
        json_error("You have already accepted this request. Cannot reject.", 400);
    }
    
    // Check if request status is still pending
    if ($request['status'] !== 'pending') {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR: Invalid status '{$request['status']}' for rejection");
        json_error("Cannot reject request that is already {$request['status']}", 400);
    }
    
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Status validation passed - Current status: pending");

    // ==================== STEP 7: START TRANSACTION ====================
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 7: Starting database transaction");
    $conn->begin_transaction();
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Transaction started");

    try {
        // ==================== STEP 8: UPDATE MAINTENANCE REQUEST ====================
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 8: Updating maintenance_requests table");
        
        $update_query = "
            UPDATE maintenance_requests 
            SET assigned_to = NULL,
                status = 'pending_reassignment',
                rejection_reason = ?,
                rejected_at = NOW(),
                rejected_by = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE request_id = ?
        ";

        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) {
            throw new Exception("Failed to prepare update query: " . $conn->error);
        }
        
        $update_stmt->bind_param("siii", $rejection_reason, $admin_id, $admin_id, $request_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to execute update: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();
        
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Update completed - Affected rows: {$affected_rows}");
        
        if ($affected_rows === 0) {
            throw new Exception("No changes made to the request");
        }

        // ==================== STEP 9: LOG TO HISTORY TABLE ====================
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 9: Logging to maintenance_history");
        
        $history_notes = "Admin ID {$admin_id} rejected the request. Reason: {$rejection_reason}";
        logMaintenanceHistory($conn, $request_id, 'rejected', 'pending', 'pending_reassignment', $admin_id, 'admin', $history_notes);
        
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] History logged successfully");

        // ==================== STEP 10: CREATE NOTIFICATION FOR SUPER ADMIN ====================
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 10: Creating notification for Super Admin");
        
        $notification_title = "Maintenance Request Rejected - Needs Reassignment";
        $notification_message = "Admin has rejected maintenance request #{$request_id}.\n" .
                                "Rejection reason: {$rejection_reason}\n" .
                                "Please review and reassign to another admin.";
        
        // Get Super Admin(s) - can be expanded to get all super admins
        $super_admin_query = "SELECT unique_id FROM admin_tbl WHERE role = 'Super Admin' AND status = '1' LIMIT 1";
        $super_stmt = $conn->prepare($super_admin_query);
        if ($super_stmt) {
            $super_stmt->execute();
            $super_result = $super_stmt->get_result();
            while ($super_admin = $super_result->fetch_assoc()) {
                createNotification($conn, (string)$super_admin['unique_id'], 'maintenance', $notification_title, $notification_message, 
                    ['request_id' => $request_id, 'rejection_reason' => $rejection_reason], 'high');
                logActivity("[REJECT_REQUEST] [ID:{$requestId}] Notification sent to Super Admin ID: {$super_admin['unique_id']}");
            }
            $super_stmt->close();
        }

        // ==================== STEP 11: COMMIT TRANSACTION ====================
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Step 11: Committing transaction");
        $conn->commit();
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 12: LOG COMPLETION ====================
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Admin {$admin_id} rejected maintenance request #{$request_id}");
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Rejection reason: {$rejection_reason}");
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] New status: pending_reassignment");

        // ==================== STEP 13: RETURN SUCCESS RESPONSE ====================
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'request_id' => $request_id,
            'status' => 'pending_reassignment',
            'rejection_reason' => $rejection_reason,
            'rejected_by' => $admin_id,
            'rejected_at' => date('Y-m-d H:i:s')
        ], "Maintenance request rejected. Super Admin will reassign.");

    } catch (Exception $e) {
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        logActivity("[REJECT_REQUEST] [ID:{$requestId}] Rolling back transaction");
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[REJECT_REQUEST] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>