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

    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $status = isset($_GET['status']) ? htmlspecialchars(trim($_GET['status'])) : null;
    $priority = isset($_GET['priority']) ? htmlspecialchars(trim($_GET['priority'])) : null;
    $date_from = isset($_GET['date_from']) ? htmlspecialchars(trim($_GET['date_from'])) : null;
    $date_to = isset($_GET['date_to']) ? htmlspecialchars(trim($_GET['date_to'])) : null;

    // Build WHERE clause - IMPORTANT: Use table alias 'mr' for maintenance_requests
    $where_clauses = ["mr.tenant_code = ?"];
    $params = [$tenant_code];
    $types = "s";

    if ($status && in_array($status, ['pending', 'in_progress', 'resolved', 'cancelled'])) {
        $where_clauses[] = "mr.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($priority && in_array($priority, ['low', 'medium', 'high', 'emergency'])) {
        $where_clauses[] = "mr.priority = ?";
        $params[] = $priority;
        $types .= "s";
    }

    if ($date_from) {
        $where_clauses[] = "DATE(mr.created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }

    if ($date_to) {
        $where_clauses[] = "DATE(mr.created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    $where_sql = "WHERE " . implode(" AND ", $where_clauses);

    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM maintenance_requests mr $where_sql";
    $count_stmt = $conn->prepare($count_query);
    
    if (!$count_stmt) {
        throw new Exception("Prepare failed for count: " . $conn->error);
    }
    
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    // Get maintenance requests with pagination
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
            DATEDIFF(NOW(), mr.created_at) as days_pending
        FROM maintenance_requests mr
        LEFT JOIN apartments a ON mr.apartment_code = a.apartment_code
        LEFT JOIN admin_tbl ad ON mr.assigned_to = ad.unique_id
        $where_sql
        ORDER BY 
            FIELD(mr.priority, 'emergency', 'high', 'medium', 'low'),
            FIELD(mr.status, 'pending', 'in_progress', 'resolved', 'cancelled'),
            mr.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed for main query: " . $conn->error);
    }
    
    // Build parameters for main query
    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $query_types = $types . "ii";
    
    $stmt->bind_param($query_types, ...$query_params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate estimated resolution time based on priority
        $estimated_days = 7; // default
        switch($row['priority']) {
            case 'emergency':
                $estimated_days = 1;
                break;
            case 'high':
                $estimated_days = 3;
                break;
            case 'medium':
                $estimated_days = 7;
                break;
            case 'low':
                $estimated_days = 14;
                break;
        }
        
        $requests[] = [
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
        ];
    }
    $stmt->close();

    // Get summary statistics - IMPORTANT: Use table alias 'mr'
    $summary_query = "
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
            SUM(CASE WHEN mr.status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
            SUM(CASE WHEN mr.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN mr.priority = 'emergency' THEN 1 ELSE 0 END) as emergency_count,
            SUM(CASE WHEN mr.priority = 'high' THEN 1 ELSE 0 END) as high_count,
            SUM(CASE WHEN mr.priority = 'medium' THEN 1 ELSE 0 END) as medium_count,
            SUM(CASE WHEN mr.priority = 'low' THEN 1 ELSE 0 END) as low_count,
            AVG(CASE WHEN mr.status = 'resolved' THEN DATEDIFF(mr.resolved_at, mr.created_at) ELSE NULL END) as avg_resolution_days
        FROM maintenance_requests mr
        $where_sql
    ";
    $summary_stmt = $conn->prepare($summary_query);
    
    if (!$summary_stmt) {
        throw new Exception("Prepare failed for summary: " . $conn->error);
    }
    
    if (!empty($params)) {
        $summary_stmt->bind_param($types, ...$params);
    }
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result->fetch_assoc();
    $summary_stmt->close();

    $conn->close();

    $response_data = [
        'requests' => $requests,
        'summary' => [
            'total_requests' => (int)($summary['total_requests'] ?? 0),
            'pending' => (int)($summary['pending_count'] ?? 0),
            'in_progress' => (int)($summary['in_progress_count'] ?? 0),
            'resolved' => (int)($summary['resolved_count'] ?? 0),
            'cancelled' => (int)($summary['cancelled_count'] ?? 0),
            'by_priority' => [
                'emergency' => (int)($summary['emergency_count'] ?? 0),
                'high' => (int)($summary['high_count'] ?? 0),
                'medium' => (int)($summary['medium_count'] ?? 0),
                'low' => (int)($summary['low_count'] ?? 0)
            ],
            'avg_resolution_days' => round($summary['avg_resolution_days'] ?? 0, 1)
        ],
        'filters' => [
            'available_statuses' => ['pending', 'in_progress', 'resolved', 'cancelled'],
            'available_priorities' => ['low', 'medium', 'high', 'emergency'],
            'current_status' => $status,
            'current_priority' => $priority
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $total,
            'total_pages' => ceil($total / $limit),
            'has_next_page' => $page < ceil($total / $limit),
            'has_previous_page' => $page > 1
        ]
    ];

    json_success($response_data, "Maintenance requests retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_maintenance_requests: " . $e->getMessage());
    json_error("Failed to fetch maintenance requests: " . $e->getMessage(), 500);
}
?>