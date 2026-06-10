<?php
// tenant/backend/maintenance/confirm_resolution.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/logMaintenanceHistory.php';  // ADD THIS LINE

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('confirm_resolution_', true);
logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] ========== START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] ERROR: Unauthorized");
        json_error("Unauthorized", 401);
    }

    $tenant_code = $_SESSION['tenant_code'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] ERROR: Invalid input");
        json_error("Invalid input data", 400);
    }
    
    $request_id = $input['request_id'] ?? null;
    $satisfied = $input['satisfied'] ?? true;
    $rating = $input['rating'] ?? null;
    $feedback = $input['feedback'] ?? null;
    $escalation_reason = $input['escalation_reason'] ?? null;
    
    logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] Request ID: {$request_id}, Satisfied: " . ($satisfied ? 'Yes' : 'No'));
    
    if (!$request_id) {
        json_error("Request ID is required", 400);
    }
    
    // Verify request belongs to tenant and is resolved
    $query = "
        SELECT request_id, status, assigned_to, issue_type
        FROM maintenance_requests
        WHERE request_id = ? AND tenant_code = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $request_id, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();
    
    if (!$request) {
        json_error("Maintenance request not found", 404);
    }
    
    if ($request['status'] !== 'resolved') {
        json_error("Only resolved requests can be confirmed. Current status: {$request['status']}", 400);
    }
    
    $conn->begin_transaction();
    
    try {
        if ($satisfied) {
            // Tenant is satisfied - close the request
            $update_query = "
                UPDATE maintenance_requests 
                SET status = 'closed',
                    tenant_confirmed_at = NOW(),
                    tenant_rating = ?,
                    tenant_feedback = ?,
                    updated_at = NOW()
                WHERE request_id = ?
            ";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("isi", $rating, $feedback, $request_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Log to maintenance history
            logMaintenanceHistory($conn, $request_id, 'tenant_confirmed', 'resolved', 'closed', $tenant_code, 'tenant', "Rating: {$rating}/5. Feedback: {$feedback}");
            
            logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] Request #{$request_id} closed by tenant with rating: {$rating}");
            
            // Create notification for admin
            createNotification($conn, (string)$request['assigned_to'], 'maintenance', 'Maintenance Request Closed', 
                "Tenant has confirmed resolution for request #{$request_id} with rating {$rating}/5.", 
                ['request_id' => $request_id], 'medium');
            
        } else {
            // Tenant is NOT satisfied - re-open with escalation
            $update_query = "
                UPDATE maintenance_requests 
                SET status = 'in_progress',
                    is_escalated = 1,
                    escalated_at = NOW(),
                    escalated_reason = ?,
                    tenant_feedback = ?,
                    updated_at = NOW()
                WHERE request_id = ?
            ";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssi", $escalation_reason, $feedback, $request_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Log to maintenance history
            logMaintenanceHistory($conn, $request_id, 'escalated', 'resolved', 'in_progress', $tenant_code, 'tenant', "Escalation reason: {$escalation_reason}");
            
            logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] Request #{$request_id} re-opened with escalation. Reason: {$escalation_reason}");
            
            // Create notification for admin about escalation
            createNotification($conn, (string)$request['assigned_to'], 'maintenance', 'Maintenance Request Escalated', 
                "Tenant is not satisfied with resolution. Request #{$request_id} has been re-opened.\nReason: {$escalation_reason}", 
                ['request_id' => $request_id], 'high');
        }
        
        $conn->commit();
        
        logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] ========== SUCCESS ==========");
        
        json_success([
            'request_id' => $request_id,
            'status' => $satisfied ? 'closed' : 'in_progress',
            'satisfied' => $satisfied
        ], $satisfied ? "Request closed successfully" : "Escalation submitted. Admin will review.");
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    logActivity("[CONFIRM_RESOLUTION] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>