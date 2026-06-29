<?php
// admin/backend/maintenance/fetch_maintenance_request_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

// Generate unique request ID for tracking
$requestId = uniqid('fetch_details_', true);
logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ========== START ==========");
logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    $is_super_admin = ($admin_role === 'Super Admin');
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}, Is Super Admin: " . ($is_super_admin ? 'Yes' : 'No'));

    // ==================== STEP 2: GET REQUEST ID ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 2: Getting request ID");
    
    $request_id = $_GET['request_id'] ?? null;
    
    if (!$request_id) {
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Request ID is required");
        json_error("Request ID is required", 400);
    }
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Request ID: {$request_id}");

    // ==================== STEP 3: VERIFY ADMIN PERMISSION ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 3: Verifying admin permission");
    
    $verifyQuery = "
        SELECT mr.request_id, mr.assigned_admin_id, mr.assigned_to
        FROM maintenance_requests mr
        WHERE mr.request_id = ?
        LIMIT 1
    ";
    
    $verifyStmt = $conn->prepare($verifyQuery);
    if (!$verifyStmt) {
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Failed to prepare permission query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $verifyStmt->bind_param("i", $request_id);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();
    
    if ($verifyResult->num_rows === 0) {
        $verifyStmt->close();
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Maintenance request not found - ID: {$request_id}");
        json_error("Maintenance request not found", 404);
    }
    
    $requestData = $verifyResult->fetch_assoc();
    $verifyStmt->close();
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Request found - Assigned Admin ID: {$requestData['assigned_admin_id']}");
    
    // Check permission: Super Admin can view all, regular admin can only view assigned to them
    if (!$is_super_admin && $requestData['assigned_admin_id'] != $admin_id) {
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Permission denied - Admin {$admin_id} not assigned to request {$request_id}");
        json_error("You do not have permission to view this request", 403);
    }
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Permission check passed");

    // ==================== STEP 4: FETCH MAINTENANCE REQUEST DETAILS ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 4: Fetching maintenance request details");
    
    $query = "
        SELECT 
            mr.request_id,
            mr.issue_type,
            mr.description,
            mr.priority,
            mr.status,
            mr.created_at,
            mr.updated_at,
            mr.resolved_at,
            mr.resolution_notes,
            mr.images,
            mr.assigned_agent_code,
            mr.assigned_admin_id,
            mr.assigned_to,
            mr.auto_assigned,
            mr.assigned_at,
            mr.rejection_reason,
            mr.rejected_at,
            mr.rejected_by,
            mr.tenant_confirmed_at,
            mr.tenant_rating,
            mr.tenant_feedback,
            mr.is_escalated,
            mr.escalated_at,
            mr.escalated_reason,
            mr.sla_breached,
            a.apartment_number,
            a.apartment_code,
            a.property_code,
            p.name as property_name,
            p.address as property_address,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            t.tenant_code,
            t.lease_start_date,
            t.lease_end_date,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
            ag.email as agent_email,
            ag.phone as agent_phone,
            CONCAT(ad.firstname, ' ', ad.lastname) as admin_name,
            ad.email as admin_email,
            CONCAT(assigned_ad.firstname, ' ', assigned_ad.lastname) as assigned_to_name,
            CASE 
                WHEN mr.priority = 'emergency' THEN 'danger'
                WHEN mr.priority = 'high' THEN 'warning'
                WHEN mr.priority = 'medium' THEN 'info'
                ELSE 'secondary'
            END as priority_color,
            CASE 
                WHEN mr.status = 'pending' THEN 'warning'
                WHEN mr.status = 'pending_reassignment' THEN 'info'
                WHEN mr.status = 'in_progress' THEN 'info'
                WHEN mr.status = 'resolved' THEN 'success'
                WHEN mr.status = 'cancelled' THEN 'secondary'
                WHEN mr.status = 'closed' THEN 'success'
                ELSE 'secondary'
            END as status_color,
            DATEDIFF(NOW(), mr.created_at) as days_pending,
            DATEDIFF(mr.resolved_at, mr.created_at) as resolution_days
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code
        LEFT JOIN agents ag ON mr.assigned_agent_code = ag.agent_code
        LEFT JOIN admin_tbl ad ON mr.assigned_admin_id = ad.unique_id
        LEFT JOIN admin_tbl assigned_ad ON mr.assigned_to = assigned_ad.unique_id
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Failed to prepare main query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ERROR: Request details not found after permission check");
        json_error("Maintenance request not found", 404);
    }

    $row = $result->fetch_assoc();
    $stmt->close();
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Request details retrieved - Status: {$row['status']}, Priority: {$row['priority']}");

    // ==================== STEP 5: CALCULATE ADDITIONAL DATA ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 5: Calculating additional data");
    
    // Calculate estimated resolution time based on priority
    $estimated_days = match($row['priority']) {
        'emergency' => 1,
        'high' => 3,
        'medium' => 7,
        'low' => 14,
        default => 7
    };
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Estimated resolution days: {$estimated_days}");

    // Determine if current admin can take action
    $can_take_action = ($row['assigned_admin_id'] == $admin_id) || $is_super_admin;
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Admin can take action: " . ($can_take_action ? 'Yes' : 'No'));

    // ==================== STEP 6: BUILD TIMELINE ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 6: Building timeline");
    
    $timeline = [];
    
    // Request created
    $timeline[] = [
        'date' => $row['created_at'],
        'formatted_date' => date('F j, Y g:i A', strtotime($row['created_at'])),
        'action' => 'Request Created',
        'description' => "Maintenance request submitted by {$row['tenant_name']} with priority: " . ucfirst($row['priority']),
        'user' => $row['tenant_name'],
        'user_role' => 'tenant'
    ];
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Request Created");
    
    // Auto-assigned
    if ($row['assigned_admin_id']) {
        $timeline[] = [
            'date' => $row['assigned_at'] ?? $row['created_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['assigned_at'] ?? $row['created_at'])),
            'action' => 'Auto-Assigned',
            'description' => "Request auto-assigned to: " . ($row['admin_name'] ?? 'Admin'),
            'user' => 'System',
            'user_role' => 'system'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Auto-Assigned to {$row['admin_name']}");
    }
    
    // Accepted by admin
    if ($row['assigned_to'] && $row['assigned_to'] == $row['assigned_admin_id']) {
        $timeline[] = [
            'date' => $row['assigned_at'] ?? $row['updated_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['assigned_at'] ?? $row['updated_at'])),
            'action' => 'Request Accepted',
            'description' => "Request accepted by: " . ($row['assigned_to_name'] ?? 'Admin'),
            'user' => $row['assigned_to_name'] ?? 'Admin',
            'user_role' => 'admin'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Request Accepted by {$row['assigned_to_name']}");
    }
    
    // Rejected
    if ($row['rejection_reason']) {
        $timeline[] = [
            'date' => $row['rejected_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['rejected_at'])),
            'action' => 'Request Rejected',
            'description' => "Rejection reason: {$row['rejection_reason']}",
            'user' => $row['admin_name'] ?? 'Admin',
            'user_role' => 'admin'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Request Rejected");
    }
    
    // Status changes - In Progress
    if ($row['status'] === 'in_progress') {
        $timeline[] = [
            'date' => $row['updated_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['updated_at'])),
            'action' => 'Work In Progress',
            'description' => "Maintenance work has started",
            'user' => $row['assigned_to_name'] ?? 'Admin',
            'user_role' => 'admin'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Work In Progress");
    }
    
    // Status changes - Resolved
    if ($row['status'] === 'resolved') {
        $timeline[] = [
            'date' => $row['resolved_at'],
            'formatted_date' => $row['resolved_at'] ? date('F j, Y g:i A', strtotime($row['resolved_at'])) : null,
            'action' => 'Request Resolved',
            'description' => $row['resolution_notes'] ?? 'Issue has been resolved',
            'user' => $row['assigned_to_name'] ?? 'Admin',
            'user_role' => 'admin'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Request Resolved");
    }
    
    // Tenant confirmed
    if ($row['tenant_confirmed_at']) {
        $timeline[] = [
            'date' => $row['tenant_confirmed_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['tenant_confirmed_at'])),
            'action' => 'Tenant Confirmed',
            'description' => "Tenant confirmed resolution with rating: {$row['tenant_rating']}/5",
            'user' => $row['tenant_name'],
            'user_role' => 'tenant'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Tenant Confirmed - Rating: {$row['tenant_rating']}/5");
    }
    
    // Escalation
    if ($row['is_escalated']) {
        $timeline[] = [
            'date' => $row['escalated_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['escalated_at'])),
            'action' => 'Request Escalated',
            'description' => $row['escalated_reason'] ?? 'Escalated for review',
            'user' => 'System',
            'user_role' => 'system'
        ];
        logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Added timeline event: Request Escalated");
    }
    // $sla_breached = "";
    // if($row['sla_breached'] == 1){
    //     $sla_breached = "Yes";
    // }
    // else{
    //     $sla_breached = "No";
    // }
    
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Timeline built with " . count($timeline) . " events");

    // ==================== STEP 7: BUILD RESPONSE ====================
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Step 7: Building response");
    
    $response_data = [
        'request' => [
            'request_id' => (int)$row['request_id'],
            'issue_type' => $row['issue_type'],
            'description' => $row['description'],
            'priority' => $row['priority'],
            'priority_color' => $row['priority_color'],
            'priority_display' => ucfirst($row['priority']),
            'status' => $row['status'],
            'status_color' => $row['status_color'],
            'status_display' => str_replace('_', ' ', ucfirst($row['status'])),
            'created_at' => $row['created_at'],
            'formatted_created_at' => date('F j, Y g:i A', strtotime($row['created_at'])),
            'updated_at' => $row['updated_at'],
            'resolved_at' => $row['resolved_at'],
            'days_pending' => (int)$row['days_pending'],
            'resolution_days' => $row['resolution_days'] ? (int)$row['resolution_days'] : null,
            'estimated_resolution_days' => $estimated_days,
            'resolution_notes' => $row['resolution_notes'],
            'images' => $row['images'] ? json_decode($row['images'], true) : [],
            'assigned_agent_code' => $row['assigned_agent_code'],
            'assigned_agent_name' => $row['agent_name'] ?? 'Not assigned',
            'assigned_agent_email' => $row['agent_email'],
            'assigned_agent_phone' => $row['agent_phone'],
            'assigned_admin_id' => $row['assigned_admin_id'],
            'assigned_admin_name' => $row['admin_name'] ?? 'Not assigned',
            'assigned_admin_email' => $row['admin_email'],
            'assigned_to' => $row['assigned_to'],
            'assigned_to_name' => $row['assigned_to_name'] ?? 'Not assigned yet',
            'auto_assigned' => (bool)$row['auto_assigned'],
            'assigned_at' => $row['assigned_at'],
            'can_take_action' => $can_take_action,
            'rejection_reason' => $row['rejection_reason'],
            'rejected_at' => $row['rejected_at'],
            'tenant_confirmed' => !is_null($row['tenant_confirmed_at']),
            'tenant_confirmed_at' => $row['tenant_confirmed_at'],
            'tenant_rating' => $row['tenant_rating'],
            'tenant_feedback' => $row['tenant_feedback'],
            'is_escalated' => (bool)$row['is_escalated'],
            'escalated_at' => $row['escalated_at'],
            'escalated_reason' => $row['escalated_reason'],
            'sla_breached' =>  $row['sla_breached'],
            'tenant_info' => [
                'tenant_code' => $row['tenant_code'],
                'tenant_name' => $row['tenant_name'],
                'email' => $row['tenant_email'],
                'phone' => $row['tenant_phone'],
                'lease_start_date' => $row['lease_start_date'],
                'lease_end_date' => $row['lease_end_date']
            ],
            'property_info' => [
                'property_code' => $row['property_code'],
                'property_name' => $row['property_name'],
                'property_address' => $row['property_address'],
                'apartment_code' => $row['apartment_code'],
                'apartment_number' => $row['apartment_number']
            ]
        ],
        'timeline' => $timeline
    ];

    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ========== SUCCESS ==========");
    json_success($response_data, "Maintenance request details retrieved successfully");

} catch (Exception $e) {
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[FETCH_REQUEST_DETAILS] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error("Failed to fetch maintenance request details: " . $e->getMessage(), 500);
}
?>