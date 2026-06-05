<?php
// client/backend/maintenance/fetch_maintenance_request_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication - Client role
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    $client_code = $_SESSION['client_code'];
    $request_id = $_GET['request_id'] ?? null;

    if (!$request_id) {
        json_error("Request ID is required", 400);
    }

    // First, verify that this maintenance request belongs to a property owned by the client
    $verifyQuery = "
        SELECT mr.request_id
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE mr.request_id = ? AND p.client_code = ?
        LIMIT 1
    ";
    
    $verifyStmt = $conn->prepare($verifyQuery);
    $verifyStmt->bind_param("is", $request_id, $client_code);
    $verifyStmt->execute();
    
    if ($verifyStmt->get_result()->num_rows === 0) {
        $verifyStmt->close();
        json_error("Maintenance request not found or you don't have permission to view it", 404);
    }
    $verifyStmt->close();

    // Get maintenance request details with tenant and property information
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
            a.apartment_number,
            a.apartment_code,
            a.property_code,
            p.name as property_name,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            t.tenant_code,
            t.lease_start_date,
            t.lease_end_date,
            CONCAT(ad.firstname, ' ', ad.lastname) as assigned_to_name,
            mr.assigned_to,
            CASE 
                WHEN mr.priority = 'emergency' THEN 'danger'
                WHEN mr.priority = 'high' THEN 'warning'
                WHEN mr.priority = 'medium' THEN 'info'
                ELSE 'secondary'
            END as priority_color,
            CASE 
                WHEN mr.status = 'pending' THEN 'warning'
                WHEN mr.status = 'in_progress' THEN 'info'
                WHEN mr.status = 'resolved' THEN 'success'
                ELSE 'secondary'
            END as status_color,
            DATEDIFF(NOW(), mr.created_at) as days_pending,
            DATEDIFF(mr.resolved_at, mr.created_at) as resolution_days
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code
        LEFT JOIN admin_tbl ad ON mr.assigned_to = ad.unique_id
        WHERE mr.request_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        json_error("Maintenance request not found", 404);
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    // Calculate estimated resolution time based on priority
    $estimated_days = match($row['priority']) {
        'emergency' => 1,
        'high' => 3,
        'medium' => 7,
        'low' => 14,
        default => 7
    };

    // Build timeline/activity log
    $timeline = [];
    $timeline[] = [
        'date' => $row['created_at'],
        'formatted_date' => date('F j, Y g:i A', strtotime($row['created_at'])),
        'action' => 'Request created',
        'description' => "Maintenance request submitted by {$row['tenant_name']} with priority: " . ucfirst($row['priority']),
        'user' => 'Tenant',
        'user_role' => 'tenant'
    ];
    
    if ($row['assigned_to']) {
        $timeline[] = [
            'date' => $row['updated_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['updated_at'])),
            'action' => 'Request assigned',
            'description' => "Assigned to: " . ($row['assigned_to_name'] ?? 'Staff member'),
            'user' => 'System',
            'user_role' => 'system'
        ];
    }
    
    if ($row['status'] === 'in_progress') {
        $timeline[] = [
            'date' => $row['updated_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['updated_at'])),
            'action' => 'Work in progress',
            'description' => "Maintenance work has started",
            'user' => 'Staff',
            'user_role' => 'staff'
        ];
    }
    
    if ($row['status'] === 'resolved') {
        $timeline[] = [
            'date' => $row['resolved_at'],
            'formatted_date' => $row['resolved_at'] ? date('F j, Y g:i A', strtotime($row['resolved_at'])) : null,
            'action' => 'Request resolved',
            'description' => $row['resolution_notes'] ?? 'Issue has been resolved',
            'user' => 'Staff',
            'user_role' => 'staff'
        ];
    }

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
            'assigned_to' => $row['assigned_to'],
            'assigned_to_name' => $row['assigned_to_name'] ?? 'Not assigned yet',
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
                'apartment_code' => $row['apartment_code'],
                'apartment_number' => $row['apartment_number']
            ]
        ],
        'timeline' => $timeline
    ];

    json_success($response_data, "Maintenance request details retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_maintenance_request_details (client): " . $e->getMessage());
    json_error("Failed to fetch maintenance request details: " . $e->getMessage(), 500);
}
?>