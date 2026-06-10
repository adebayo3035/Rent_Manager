<?php
// admin/backend/maintenance/assign_maintenance_request.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('assign_request_', true);
logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ========== START ==========");
logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    $is_super_admin = ($admin_role === 'Super Admin');
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}, Is Super Admin: " . ($is_super_admin ? 'Yes' : 'No'));

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));

    $request_id = $input['request_id'] ?? null;
    $assign_to_self = $input['assign_to_self'] ?? true;
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Parameters - request_id: {$request_id}, assign_to_self: " . ($assign_to_self ? 'Yes' : 'No'));

    // ==================== STEP 3: VALIDATE REQUIRED FIELDS ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 3: Validating required fields");
    
    if (!$request_id) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Request ID is required");
        json_error("Request ID is required", 400);
    }
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Required fields validated");

    // ==================== STEP 4: FETCH MAINTENANCE REQUEST ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 4: Fetching maintenance request details");
    
    $query = "
        SELECT 
            mr.request_id,
            mr.status,
            mr.assigned_admin_id,
            mr.assigned_agent_code,
            mr.tenant_code,
            a.apartment_number,
            p.name as property_name,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Failed to prepare select query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Maintenance request not found - ID: {$request_id}");
        json_error("Maintenance request not found", 404);
    }
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Request found - Current Status: {$request['status']}, Current Assigned Admin ID: {$request['assigned_admin_id']}");

    // ==================== STEP 5: CHECK ASSIGNMENT PERMISSION ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 5: Checking assignment permission");
    
    // Check if already assigned to someone else
    if ($request['assigned_admin_id'] && $request['assigned_admin_id'] != $admin_id && !$is_super_admin) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Request already assigned to another admin: {$request['assigned_admin_id']}");
        json_error("This request is already assigned to another admin. Only Super Admin can reassign.", 403);
    }
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Assignment permission check passed");

    // ==================== STEP 6: CHECK REQUEST STATUS ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 6: Checking request status");
    
    $invalid_statuses = ['resolved', 'cancelled', 'closed', 'completed'];
    if (in_array($request['status'], $invalid_statuses)) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR: Request status '{$request['status']}' - Cannot assign");
        json_error("Cannot assign request that is already {$request['status']}", 400);
    }
    
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Status validation passed - Request is assignable");

    // ==================== STEP 7: START TRANSACTION ====================
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 7: Starting database transaction");
    $conn->begin_transaction();
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Transaction started");

    try {
        // ==================== STEP 8: UPDATE MAINTENANCE REQUEST ====================
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 8: Updating maintenance_requests table");
        
        $old_assigned_admin = $request['assigned_admin_id'];
        
        $update_query = "
            UPDATE maintenance_requests 
            SET assigned_admin_id = ?,
                assigned_at = NOW(),
                auto_assigned = FALSE,
                updated_at = NOW(),
                last_updated_by = ?
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

        $affected = $update_stmt->affected_rows;
        $update_stmt->close();
        
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Update completed - Affected rows: {$affected}, Old Admin: {$old_assigned_admin}, New Admin: {$admin_id}");

        // ==================== STEP 9: LOG ASSIGNMENT HISTORY ====================
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 9: Logging assignment history");
        
        $history_query = "
            INSERT INTO maintenance_assignments (request_id, assigned_to_type, assigned_to_id, assigned_by, is_auto, notes)
            VALUES (?, 'admin', ?, ?, FALSE, 'Manually assigned by admin')
        ";
        $history_stmt = $conn->prepare($history_query);
        if (!$history_stmt) {
            logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] WARNING: Failed to prepare history insert: " . $conn->error);
        } else {
            $history_stmt->bind_param("iii", $request_id, $admin_id, $admin_id);
            $history_stmt->execute();
            $history_stmt->close();
            logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Assignment history recorded");
        }

        // ==================== STEP 10: CREATE NOTIFICATION FOR TENANT ====================
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 10: Creating notification for tenant");
        
        $notification_title = "Maintenance Request Assigned";
        $notification_message = "Your maintenance request #{$request_id} for '{$request['issue_type']}' has been assigned to an admin. You will be updated on progress.";
        
        createNotification($conn, $request['tenant_code'], 'maintenance', $notification_title, $notification_message, [
            'request_id' => $request_id,
            'property_name' => $request['property_name'],
            'apartment_number' => $request['apartment_number']
        ], 'medium');
        
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Notification created for tenant: {$request['tenant_code']}");

        // ==================== STEP 11: COMMIT TRANSACTION ====================
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Step 11: Committing transaction");
        $conn->commit();
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 12: LOG COMPLETION ====================
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Admin {$admin_id} assigned maintenance request #{$request_id} to themselves");
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Request status: {$request['status']}, Assigned at: " . date('Y-m-d H:i:s'));

        // ==================== STEP 13: RETURN SUCCESS RESPONSE ====================
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'request_id' => $request_id,
            'assigned_admin_id' => $admin_id,
            'assigned_at' => date('Y-m-d H:i:s'),
            'status' => $request['status']
        ], "Maintenance request assigned to you successfully");

    } catch (Exception $e) {
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Rolling back transaction");
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[ASSIGN_REQUEST] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}
?>