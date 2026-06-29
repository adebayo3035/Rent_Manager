<?php
// admin/backend/maintenance/super_admin_reassign.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/logMaintenanceHistory.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

// Generate unique request ID for tracking
$requestId = uniqid('super_admin_reassign_', true);
logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ========== START ==========");
logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}");

    // ==================== STEP 2: CHECK SUPER ADMIN PERMISSION ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 2: Checking Super Admin permission");
    
    if ($admin_role !== 'Super Admin') {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Permission denied - User is not Super Admin");
        json_error("Only Super Admin can reassign maintenance requests", 403);
    }
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Super Admin permission verified");

    // ==================== STEP 3: GET INPUT DATA ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 3: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));

    $request_id = $input['request_id'] ?? null;
    $new_admin_id = $input['new_admin_id'] ?? null;
    $reassignment_notes = $input['reassignment_notes'] ?? null;
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Parameters - request_id: {$request_id}, new_admin_id: {$new_admin_id}");
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] reassignment_notes length: " . strlen($reassignment_notes ?? ''));

    // ==================== STEP 4: VALIDATE REQUIRED FIELDS ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 4: Validating required fields");
    
    if (!$request_id) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Request ID is required");
        json_error("Request ID is required", 400);
    }
    
    if (!$new_admin_id) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: New admin ID is required");
        json_error("New admin ID is required", 400);
    }
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Required fields validated");

    // ==================== STEP 5: FETCH MAINTENANCE REQUEST ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 5: Fetching maintenance request details");
    
    $query = "
        SELECT mr.request_id, mr.status, mr.assigned_admin_id, mr.assigned_to, mr.rejection_reason, mr.tenant_code
        FROM maintenance_requests mr
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Failed to prepare select query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Maintenance request not found - ID: {$request_id}");
        json_error("Maintenance request not found", 404);
    }
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Request found - Status: {$request['status']}, Current Admin ID: {$request['assigned_admin_id']}");

    // ==================== STEP 6: VALIDATE CURRENT STATUS ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 6: Validating current status");
    
    $allowed_statuses = ['pending', 'pending_reassignment'];
    if (!in_array($request['status'], $allowed_statuses)) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Invalid status '{$request['status']}' - Allowed: " . implode(', ', $allowed_statuses));
        json_error("Cannot reassign request with status '{$request['status']}'. Only pending or pending_reassignment requests can be reassigned.", 400);
    }
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Status validation passed");

    // ==================== STEP 7: VERIFY NEW ADMIN EXISTS ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 7: Verifying new admin exists");
    
    $admin_check = $conn->prepare("SELECT unique_id, firstname, lastname FROM admin_tbl WHERE unique_id = ? AND status = '1'");
    if (!$admin_check) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Failed to prepare admin check query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $admin_check->bind_param("i", $new_admin_id);
    $admin_check->execute();
    $admin_result = $admin_check->get_result();
    
    if ($admin_result->num_rows === 0) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR: Selected admin not found or inactive - ID: {$new_admin_id}");
        json_error("Selected admin not found or inactive", 404);
    }
    
    $new_admin = $admin_result->fetch_assoc();
    $admin_check->close();
    
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] New admin verified - Name: {$new_admin['firstname']} {$new_admin['lastname']}");

    // ==================== STEP 8: GET OLD ADMIN INFO ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 8: Getting old admin information");
    
    $old_admin_query = $conn->prepare("SELECT firstname, lastname FROM admin_tbl WHERE unique_id = ?");
    if ($old_admin_query) {
        $old_admin_query->bind_param("i", $request['assigned_admin_id']);
        $old_admin_query->execute();
        $old_admin_result = $old_admin_query->get_result();
        $old_admin = $old_admin_result->fetch_assoc();
        $old_admin_query->close();
        
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Old admin found - Name: " . ($old_admin['firstname'] ?? 'Unknown') . " " . ($old_admin['lastname'] ?? 'Unknown'));
    } else {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] WARNING: Could not fetch old admin info");
        $old_admin = ['firstname' => 'Unknown', 'lastname' => 'Admin'];
    }

    // ==================== STEP 9: START TRANSACTION ====================
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 9: Starting database transaction");
    $conn->begin_transaction();
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Transaction started");

    try {
        // ==================== STEP 10: UPDATE MAINTENANCE REQUESTS TABLE ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 10: Updating maintenance_requests table");
        
        $update_query = "
            UPDATE maintenance_requests 
            SET 
                assigned_admin_id = ?,
                status = 'pending',
                rejection_reason = NULL,
                rejected_at = NULL,
                rejected_by = NULL,
                assigned_at = NOW(),
                updated_at = NOW()
            WHERE request_id = ?
        ";

        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) {
            throw new Exception("Failed to prepare update query: " . $conn->error);
        }
        
        $update_stmt->bind_param("ii", $new_admin_id, $request_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to execute update: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();
        
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Update completed - Affected rows: {$affected_rows}");
        
        if ($affected_rows === 0) {
            throw new Exception("Failed to update request. No changes made.");
        }

        // ==================== STEP 11: INSERT INTO MAINTENANCE ASSIGNMENTS TABLE ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 11: Recording in maintenance_assignments table");
        
        $assignment_notes = "Reassigned from " . ($old_admin['firstname'] ?? 'Unknown') . " " . ($old_admin['lastname'] ?? 'Admin') . 
                            " to " . $new_admin['firstname'] . " " . $new_admin['lastname'] . 
                            ". Reason: " . ($reassignment_notes ?? 'No reason provided');
        
        $assignment_query = "
            INSERT INTO maintenance_assignments (request_id, assigned_to_type, assigned_to_id, assigned_by, is_auto, notes)
            VALUES (?, 'admin', ?, ?, 0, ?)
        ";
        
        $assignment_stmt = $conn->prepare($assignment_query);
        if (!$assignment_stmt) {
            throw new Exception("Failed to prepare assignment insert: " . $conn->error);
        }
        
        $assignment_stmt->bind_param("iiis", $request_id, $new_admin_id, $admin_id, $assignment_notes);
        
        if (!$assignment_stmt->execute()) {
            throw new Exception("Failed to insert assignment record: " . $assignment_stmt->error);
        }
        
        $assignment_stmt->close();
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Assignment record inserted");

        // ==================== STEP 12: LOG TO HISTORY TABLE ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 12: Logging to maintenance_history");
        
        $history_notes = "Reassigned from Admin ID {$request['assigned_admin_id']} to Admin ID {$new_admin_id}. Reason: {$reassignment_notes}";
        logMaintenanceHistory($conn, $request_id, 'reassigned', 'pending_reassignment', 'reassigned', $admin_id, 'super_admin', $history_notes);
        
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] History logged successfully");

        // ==================== STEP 13: CREATE NOTIFICATION FOR NEW ADMIN ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 13: Creating notification for new admin");
        
        $notification_title = "Maintenance Request Reassigned to You";
        $notification_message = "A maintenance request #{$request_id} has been reassigned to you by Super Admin. " .
                                "Reason: " . ($reassignment_notes ?? 'No reason provided') . 
                                ". Please review and take action.";
        
        createNotification($conn, (string)$new_admin_id, 'maintenance', $notification_title, $notification_message, 
            ['request_id' => $request_id, 'reassigned_by' => 'super_admin'], 'high');
        
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Notification sent to new admin ID: {$new_admin_id}");

        // ==================== STEP 14: CREATE NOTIFICATION FOR OLD ADMIN ====================
        if ($request['assigned_admin_id'] && $request['assigned_admin_id'] != $new_admin_id) {
            logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 14: Creating notification for old admin");
            
            $old_notification_title = "Maintenance Request Reassigned Away";
            $old_notification_message = "Maintenance request #{$request_id} has been reassigned to another admin by Super Admin. " .
                                        "Reason: " . ($reassignment_notes ?? 'No reason provided');
            
            createNotification($conn, (string)$request['assigned_admin_id'], 'maintenance', $old_notification_title, $old_notification_message, 
                ['request_id' => $request_id], 'medium');
            
            logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Notification sent to old admin ID: {$request['assigned_admin_id']}");
        } else {
            logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] No notification sent to old admin (same as new or none)");
        }

        // ==================== STEP 15: COMMIT TRANSACTION ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Step 15: Committing transaction");
        $conn->commit();
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 16: LOG COMPLETION ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Super Admin {$admin_id} reassigned maintenance request #{$request_id}");
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] From admin ID: {$request['assigned_admin_id']} To admin ID: {$new_admin_id}");
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Reason: " . ($reassignment_notes ?? 'No reason provided'));

        // ==================== STEP 17: RETURN SUCCESS RESPONSE ====================
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'request_id' => $request_id,
            'assigned_admin_id' => $new_admin_id,
            'assigned_to' => $new_admin_id,
            'old_admin_id' => $request['assigned_admin_id'],
            'new_admin_name' => $new_admin['firstname'] . ' ' . $new_admin['lastname'],
            'status' => 'pending'
        ], "Maintenance request reassigned successfully");

    } catch (Exception $e) {
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Rolling back transaction");
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[SUPER_ADMIN_REASSIGN] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>