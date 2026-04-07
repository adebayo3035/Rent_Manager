<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    
    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    $request_id = $_GET['request_id'] ?? null;

    if (!$request_id) {
        json_error("Request ID is required", 400);
    }

    // Get maintenance request details
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
        LEFT JOIN apartments a ON mr.apartment_code = a.apartment_code
        LEFT JOIN admin_tbl ad ON mr.assigned_to = ad.unique_id
        WHERE mr.request_id = ? AND mr.tenant_code = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $request_id, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Maintenance request not found", 404);
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    // Calculate estimated resolution time
    $estimated_days = match($row['priority']) {
        'emergency' => 1,
        'high' => 3,
        'medium' => 7,
        'low' => 14,
        default => 7
    };

    // Get timeline/activity log (optional - if you have a separate table for logs)
    $timeline = [];
    $timeline[] = [
        'date' => $row['created_at'],
        'action' => 'Request created',
        'description' => "Maintenance request submitted with priority: " . ucfirst($row['priority']),
        'user' => 'Tenant'
    ];
    
    if ($row['assigned_to']) {
        $timeline[] = [
            'date' => $row['updated_at'],
            'action' => 'Request assigned',
            'description' => "Assigned to: " . ($row['assigned_to_name'] ?? 'Staff member'),
            'user' => 'System'
        ];
    }
    
    if ($row['status'] === 'in_progress') {
        $timeline[] = [
            'date' => $row['updated_at'],
            'action' => 'Work in progress',
            'description' => "Maintenance work has started",
            'user' => 'Staff'
        ];
    }
    
    if ($row['status'] === 'resolved') {
        $timeline[] = [
            'date' => $row['resolved_at'],
            'action' => 'Request resolved',
            'description' => $row['resolution_notes'] ?? 'Issue has been resolved',
            'user' => 'Staff'
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
            'apartment_info' => [
                'apartment_code' => $row['apartment_code'],
                'apartment_number' => $row['apartment_number']
            ],
            'can_cancel' => in_array($row['status'], ['pending']),
            'can_escalate' => in_array($row['status'], ['pending', 'in_progress']) && $row['days_pending'] > $estimated_days
        ],
        'timeline' => $timeline
    ];

    json_success($response_data, "Maintenance request details retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_maintenance_request_details: " . $e->getMessage());
    json_error("Failed to fetch maintenance request details", 500);
}
?>