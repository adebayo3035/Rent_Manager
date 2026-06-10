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
            mr.assigned_agent_code,
            mr.assigned_admin_id,
            mr.assigned_to,
            a.apartment_number,
            a.apartment_code,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
            CONCAT(ad.firstname, ' ', ad.lastname) as admin_name,
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
        LEFT JOIN apartments a ON mr.apartment_code = a.apartment_code
        LEFT JOIN agents ag ON mr.assigned_agent_code = ag.agent_code
        LEFT JOIN admin_tbl ad ON mr.assigned_admin_id = ad.unique_id
        LEFT JOIN admin_tbl assigned_ad ON mr.assigned_to = assigned_ad.unique_id
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

    // Calculate estimated resolution time based on priority
    $estimated_days = match($row['priority']) {
        'emergency' => 1,
        'high' => 3,
        'medium' => 7,
        'low' => 14,
        default => 7
    };

    // Get timeline from maintenance_history table
    $history_query = "
        SELECT 
            id,
            action,
            old_value,
            new_value,
            changed_by,
            changed_by_type,
            notes,
            created_at,
            DATE_FORMAT(created_at, '%b %d, %Y %h:%i %p') as formatted_date,
            ip_address
        FROM maintenance_history
        WHERE request_id = ?
        ORDER BY created_at ASC
    ";

    $history_stmt = $conn->prepare($history_query);
    $history_stmt->bind_param("i", $request_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();

    $timeline = [];
    while ($history_row = $history_result->fetch_assoc()) {
        // Format action for display
        $action_display = ucfirst(str_replace('_', ' ', $history_row['action']));
        
        // Build description based on action type
        $description = $history_row['notes'];
        if (empty($description)) {
            switch ($history_row['action']) {
                case 'created':
                    $description = "Maintenance request created";
                    break;
                case 'auto_assigned':
                    $description = "Request auto-assigned to admin";
                    break;
                case 'accepted':
                    $description = "Admin accepted the request";
                    break;
                case 'rejected':
                    $description = "Admin rejected the request. Reason: " . ($history_row['notes'] ?? 'No reason provided');
                    break;
                case 'reassigned':
                    $description = "Request reassigned from Admin {$history_row['old_value']} to Admin {$history_row['new_value']}";
                    break;
                case 'status_changed':
                    $description = "Status changed from " . ucfirst($history_row['old_value']) . " to " . ucfirst($history_row['new_value']);
                    break;
                case 'resolved':
                    $description = "Request marked as resolved. Notes: " . ($history_row['notes'] ?? 'No resolution notes');
                    break;
                case 'cancelled':
                    $description = "Request cancelled. Reason: " . ($history_row['notes'] ?? 'No reason provided');
                    break;
                case 'tenant_confirmed':
                    $description = "Tenant confirmed completion with rating: " . ($history_row['new_value'] ?? 'Not rated');
                    break;
                default:
                    $description = $history_row['notes'] ?? 'Action recorded';
            }
        }
        
        $timeline[] = [
            'id' => $history_row['id'],
            'date' => $history_row['created_at'],
            'formatted_date' => $history_row['formatted_date'],
            'action' => $action_display,
            'description' => $description,
            'user' => $history_row['changed_by_type'],
            'user_role' => $history_row['changed_by_type'],
            'old_value' => $history_row['old_value'],
            'new_value' => $history_row['new_value'],
            'notes' => $history_row['notes'],
            'ip_address' => $history_row['ip_address']
        ];
    }
    $history_stmt->close();

    // If no history records exist, create a basic one from the request data (fallback)
    if (empty($timeline)) {
        $timeline[] = [
            'date' => $row['created_at'],
            'formatted_date' => date('F j, Y g:i A', strtotime($row['created_at'])),
            'action' => 'Request Created',
            'description' => "Maintenance request submitted with priority: " . ucfirst($row['priority']),
            'user' => 'Tenant',
            'user_role' => 'tenant',
            'old_value' => null,
            'new_value' => null,
            'notes' => null,
            'ip_address' => null
        ];
        
        if ($row['assigned_to']) {
            $timeline[] = [
                'date' => $row['updated_at'],
                'formatted_date' => date('F j, Y g:i A', strtotime($row['updated_at'])),
                'action' => 'Assigned',
                'description' => "Assigned to: " . ($row['assigned_to_name'] ?? 'Staff member'),
                'user' => 'System',
                'user_role' => 'system',
                'old_value' => null,
                'new_value' => null,
                'notes' => null,
                'ip_address' => null
            ];
        }
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
            'assigned_agent_code' => $row['assigned_agent_code'],
            'assigned_agent_name' => $row['agent_name'] ?? 'Not assigned',
            'assigned_admin_id' => $row['assigned_admin_id'],
            'assigned_admin_name' => $row['admin_name'] ?? 'Not assigned',
            'assigned_to' => $row['assigned_to'],
            'assigned_to_name' => $row['assigned_to_name'] ?? 'Not assigned yet',
            'apartment_info' => [
                'apartment_code' => $row['apartment_code'],
                'apartment_number' => $row['apartment_number']
            ],
            'can_cancel' => in_array($row['status'], ['pending', 'pending_reassignment']),
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