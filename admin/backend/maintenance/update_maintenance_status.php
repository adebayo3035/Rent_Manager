<?php
// admin/backend/maintenance/update_maintenance_status.php

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
$requestId = uniqid('update_status_', true);
logActivity("[UPDATE_STATUS] [ID:{$requestId}] ========== START ==========");
logActivity("[UPDATE_STATUS] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    $is_super_admin = ($admin_role === 'Super Admin');
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}, Is Super Admin: " . ($is_super_admin ? 'Yes' : 'No'));

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }

    $request_id = $input['request_id'] ?? null;
    $new_status = $input['status'] ?? null;
    $resolution_notes = $input['resolution_notes'] ?? null;
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Input - request_id: {$request_id}, new_status: {$new_status}");
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] resolution_notes length: " . strlen($resolution_notes ?? ''));

    // ==================== STEP 3: VALIDATE INPUT ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 3: Validating input");
    
    if (!$request_id) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Request ID is required");
        json_error("Request ID is required", 400);
    }
    
    if (!$new_status) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Status is required");
        json_error("Status is required", 400);
    }
    
    $allowed_statuses = ['resolved'];
    if (!in_array($new_status, $allowed_statuses)) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Invalid status '{$new_status}'. Allowed: " . implode(', ', $allowed_statuses));
        json_error("Invalid status. Allowed values: resolved", 400);
    }

    // ==================== STEP 4: FETCH MAINTENANCE REQUEST ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 4: Fetching maintenance request details");
    
    $query = "
        SELECT 
            mr.request_id,
            mr.status,
            mr.assigned_admin_id,
            mr.assigned_to,
            mr.assigned_agent_code,
            mr.tenant_code,
            mr.priority,
            mr.issue_type,
            mr.description,
            mr.assigned_at,
            a.apartment_number,
            p.name as property_name,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Maintenance request not found - ID: {$request_id}");
        json_error("Maintenance request not found", 404);
    }
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Request found - Current Status: {$request['status']}, Assigned Admin ID: {$request['assigned_admin_id']}");
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Priority: {$request['priority']}, Assigned At: {$request['assigned_at']}");

    // ==================== STEP 5: VALIDATE ADMIN PERMISSION ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 5: Validating admin permission");
    
    // Check if admin is assigned to this request
    $is_assigned = ($request['assigned_admin_id'] == $admin_id);
    $can_update = $is_assigned || $is_super_admin;
    
    if (!$can_update) {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Permission denied - Admin {$admin_id} not assigned to request {$request_id}");
        json_error("You are not authorized to update this request. Only the assigned admin can update it.", 403);
    }
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Permission validated - Admin can update: Yes");

    // ==================== STEP 6: VALIDATE CURRENT STATUS ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 6: Validating current status");
    
    $current_status = $request['status'];
    
    // Only 'in_progress' can be marked as 'resolved'
    if ($current_status !== 'in_progress') {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Invalid status transition - Current: {$current_status}, Target: {$new_status}");
        json_error("Cannot mark request as resolved. Current status is '{$current_status}'. Only 'in_progress' requests can be marked as resolved.", 400);
    }
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Status validation passed - Current: {$current_status} → Target: {$new_status}");

    // ==================== STEP 7: VALIDATE RESOLUTION NOTES ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 7: Validating resolution notes");
    
    if (empty($resolution_notes) || trim($resolution_notes) === '') {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Resolution notes are required");
        json_error("Resolution notes are required when marking a request as resolved", 400);
    }
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Resolution notes validated");

    // ==================== STEP 8: CALCULATE SLA ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 8: Calculating SLA metrics");
    
    // Expected resolution days based on priority
    $expected_days = match($request['priority']) {
        'emergency' => 1,
        'high' => 3,
        'medium' => 7,
        'low' => 14,
        default => 7
    };
    
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Expected resolution days for priority '{$request['priority']}': {$expected_days}");
    
    // Calculate actual resolution days (only if assigned_at exists)
    $actual_days = null;
    $sla_breached = 0;
    
    if ($request['assigned_at']) {
        // Calculate using MySQL DATEDIFF
        $actual_days_sql = "DATEDIFF(NOW(), '{$request['assigned_at']}')";
        $actual_days_calc = (new DateTime())->diff(new DateTime($request['assigned_at']))->days;
        $actual_days = $actual_days_calc;
        $sla_breached = ($actual_days > $expected_days) ? 1 : 0;
        
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Actual resolution days: {$actual_days}, SLA Breached: " . ($sla_breached ? 'Yes' : 'No'));
    } else {
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] WARNING: assigned_at is NULL, cannot calculate SLA accurately");
    }

    // ==================== STEP 9: UPDATE MAINTENANCE REQUEST ====================
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 9: Updating maintenance request");
    
    $conn->begin_transaction();
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Transaction started");
    
    try {
        $update_query = "
            UPDATE maintenance_requests 
            SET status = ?,
                resolved_at = NOW(),
                resolution_notes = CONCAT(IFNULL(resolution_notes, ''), '\n[', NOW(), '] ', ?),
                actual_resolution_days = ?,
                sla_breached = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE request_id = ?
        ";
        
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ssiiii", $new_status, $resolution_notes, $actual_days, $sla_breached, $admin_id, $request_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update request: " . $update_stmt->error);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();
        
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Request updated successfully. Affected rows: {$affected_rows}");
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] SLA Metrics - Actual Days: {$actual_days}, Expected Days: {$expected_days}, Breached: {$sla_breached}");
        
        if ($affected_rows === 0) {
            throw new Exception("No changes made. Request may already be in '{$new_status}' status.");
        }

        // ==================== STEP 10: LOG TO MAINTENANCE HISTORY ====================
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 10: Logging to maintenance history");
        
        $sla_message = $sla_breached ? "SLA BREACHED: Took {$actual_days} days (expected {$expected_days} days)" : "SLA MET: Took {$actual_days} days (expected {$expected_days} days)";
        $history_notes = "Request marked as resolved by Admin ID: {$admin_id}. Resolution notes: {$resolution_notes}. {$sla_message}";
        logMaintenanceHistory($conn, $request_id, 'status_changed', $current_status, $new_status, $admin_id, 'admin', $history_notes);
        
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] History logged successfully");

        // ==================== STEP 11: CREATE NOTIFICATION FOR TENANT ====================
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Step 11: Creating notification for tenant");
        
        $notification_title = "Maintenance Request Resolved";
        $notification_message = "Your maintenance request #{$request_id} for '{$request['issue_type']}' has been marked as resolved by admin. " .
                                "Resolution notes: {$resolution_notes}\n\n" .
                                "Resolution time: {$actual_days} days (expected: {$expected_days} days)\n\n" .
                                "Please confirm if the issue has been fully resolved. You can provide feedback or reopen if needed.";
        
        createNotification($conn, $request['tenant_code'], 'maintenance', $notification_title, $notification_message, [
            'request_id' => $request_id,
            'status' => $new_status,
            'resolution_notes' => $resolution_notes,
            'property_name' => $request['property_name'],
            'apartment_number' => $request['apartment_number'],
            'sla_breached' => $sla_breached,
            'actual_days' => $actual_days,
            'expected_days' => $expected_days
        ], $sla_breached ? 'urgent' : 'high');
        
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Notification created for tenant: {$request['tenant_code']}");

        // ==================== STEP 12: CREATE NOTIFICATION FOR ADMIN (Self) ====================
        $admin_notification_message = "You have marked maintenance request #{$request_id} as resolved.\n" .
                                      "Resolution notes: {$resolution_notes}\n" .
                                      "SLA: " . ($sla_breached ? "BREACHED - {$actual_days} days (expected {$expected_days} days)" : "MET - {$actual_days} days (expected {$expected_days} days)");
        
        createNotification($conn, (string)$admin_id, 'maintenance', "Request Resolved - #{$request_id}", $admin_notification_message, [
            'request_id' => $request_id,
            'status' => $new_status,
            'sla_breached' => $sla_breached,
            'actual_days' => $actual_days,
            'expected_days' => $expected_days
        ], 'medium');
        
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Notification created for admin: {$admin_id}");

        // ==================== STEP 13: COMMIT TRANSACTION ====================
        $conn->commit();
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 14: LOG ACTIVITY ====================
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Admin {$admin_id} marked maintenance request #{$request_id} as resolved");
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Old status: {$current_status}, New status: {$new_status}");
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] Resolution notes: {$resolution_notes}");
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] SLA - Expected: {$expected_days} days, Actual: {$actual_days} days, Breached: " . ($sla_breached ? 'Yes' : 'No'));

        // ==================== STEP 15: RETURN SUCCESS RESPONSE ====================
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'request_id' => $request_id,
            'old_status' => $current_status,
            'new_status' => $new_status,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolution_notes' => $resolution_notes,
            'tenant_notified' => true,
            'sla' => [
                'expected_days' => $expected_days,
                'actual_days' => $actual_days,
                'breached' => (bool)$sla_breached
            ]
        ], "Maintenance request marked as resolved successfully");

    } catch (Exception $e) {
        $conn->rollback();
        logActivity("[UPDATE_STATUS] [ID:{$requestId}] ERROR: Transaction rolled back - " . $e->getMessage());
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Error Line: " . $e->getLine());
    logActivity("[UPDATE_STATUS] [ID:{$requestId}] Stack Trace: " . $e->getTraceAsString());
    
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>