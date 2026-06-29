<?php
// admin/backend/maintenance/fetch_maintenance_requests.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

// Generate unique request ID for tracking
$requestId = uniqid('fetch_requests_', true);
logActivity("[FETCH_REQUESTS] [ID:{$requestId}] ========== START ==========");
logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 1: Checking authentication");

    if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];
    $is_super_admin = ($admin_role === 'Super Admin');

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$admin_role}, Is Super Admin: " . ($is_super_admin ? 'Yes' : 'No'));

    // ==================== STEP 2: GET PAGINATION PARAMETERS ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 2: Getting pagination parameters");

    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Pagination - Page: {$page}, Limit: {$limit}, Offset: {$offset}");

    // ==================== STEP 3: GET FILTER PARAMETERS ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 3: Getting filter parameters");

    $status = isset($_GET['status']) ? htmlspecialchars(trim($_GET['status'])) : null;
    $priority = isset($_GET['priority']) ? htmlspecialchars(trim($_GET['priority'])) : null;
    $property = isset($_GET['property_code']) ? htmlspecialchars(trim($_GET['property_code'])) : null;
    $assigned_admin = isset($_GET['assigned_admin']) ? htmlspecialchars(trim($_GET['assigned_admin'])) : null; // NEW: Filter by assigned admin ID
    $date_from = isset($_GET['date_from']) ? htmlspecialchars(trim($_GET['date_from'])) : null;
    $date_to = isset($_GET['date_to']) ? htmlspecialchars(trim($_GET['date_to'])) : null;
    $assigned_to_me = isset($_GET['assigned_to_me']) ? filter_var($_GET['assigned_to_me'], FILTER_VALIDATE_BOOLEAN) : false;
    $search = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : null;

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Filters - Status: {$status}, Priority: {$priority}, Property: {$property}, Assigned Admin: {$assigned_admin}");
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Filters - Date From: {$date_from}, Date To: {$date_to}, Assigned To Me: " . ($assigned_to_me ? 'Yes' : 'No'));
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Search term: " . ($search ?? 'None'));

    // ==================== STEP 4: BUILD WHERE CLAUSE ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 4: Building WHERE clause");

    $where_clauses = [];
    $params = [];
    $types = "";

    // Role-based filtering
    if (!$is_super_admin) {
        $where_clauses[] = "(mr.assigned_admin_id = ? OR mr.assigned_agent_code IN (SELECT agent_code FROM properties WHERE agent_code IS NOT NULL))";
        $params[] = $admin_id;
        $types .= "i";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added role-based filter for regular admin");
    } else {
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Super Admin - No role-based filter applied");
    }

    // Status filter
    $allowed_statuses = ['pending', 'in_progress', 'resolved', 'cancelled', 'closed', 'pending_reassignment'];
    if ($status && in_array($status, $allowed_statuses)) {
        $where_clauses[] = "mr.status = ?";
        $params[] = $status;
        $types .= "s";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added status filter: {$status}");
    }

    // Priority filter
    $allowed_priorities = ['low', 'medium', 'high', 'emergency'];
    if ($priority && in_array($priority, $allowed_priorities)) {
        $where_clauses[] = "mr.priority = ?";
        $params[] = $priority;
        $types .= "s";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added priority filter: {$priority}");
    }

    // Search filter
    if ($search) {
        $searchTerm = "%{$search}%";
        $where_clauses[] = "(mr.issue_type LIKE ? OR mr.description LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "sss";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added search filter: {$search}");
    }

    // Property filter
    if ($property) {
        $where_clauses[] = "a.property_code = ?";
        $params[] = $property;
        $types .= "s";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added property filter: {$property}");
    }

    // ==================== NEW: Assigned Admin Filter ====================
    // Only Super Admin can filter by specific admin
    if ($assigned_admin && $is_super_admin) {
        $where_clauses[] = "mr.assigned_admin_id = ?";
        $params[] = $assigned_admin;
        $types .= "i";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added assigned admin filter: {$assigned_admin}");
    }

    // Date filters
    if ($date_from) {
        $where_clauses[] = "DATE(mr.created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added date_from filter: {$date_from}");
    }

    if ($date_to) {
        $where_clauses[] = "DATE(mr.created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added date_to filter: {$date_to}");
    }

    // Assigned to me filter (overrides other assignment filters)
    if ($assigned_to_me && !$is_super_admin) {
        $where_clauses[] = "mr.assigned_admin_id = ?";
        $params[] = $admin_id;
        $types .= "i";
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Added assigned_to_me filter");
    }

    $where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] WHERE clause built with " . count($where_clauses) . " conditions");

    // ==================== STEP 5: GET TOTAL COUNT ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 5: Getting total count");

    $count_query = "
        SELECT COUNT(*) as total 
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code
        $where_sql
    ";

    $count_stmt = $conn->prepare($count_query);
    if (!$count_stmt) {
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] ERROR: Prepare failed for count: " . $conn->error);
        throw new Exception("Prepare failed for count: " . $conn->error);
    }

    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }

    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Total records found: {$total}");

    // ==================== STEP 6: GET MAINTENANCE REQUESTS ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 6: Fetching maintenance requests with pagination");

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
            mr.auto_assigned,
            mr.assigned_at,
            mr.tenant_confirmed_at,
            mr.tenant_rating,
            mr.tenant_feedback,
            mr.escalated_at,
            mr.escalated_reason,
            mr.is_escalated,
            a.apartment_number,
            a.apartment_code,
            a.property_code,
            p.name as property_name,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            t.tenant_code,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
            ag.email as agent_email,
            ag.phone as agent_phone,
            CONCAT(ad.firstname, ' ', ad.lastname) as admin_name,
            ad.email as admin_email,
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
                WHEN mr.status = 'cancelled' THEN 'secondary'
                WHEN mr.status = 'closed' THEN 'success'
                ELSE 'secondary'
            END as status_color,
            DATEDIFF(NOW(), mr.created_at) as days_pending
        FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code
        LEFT JOIN agents ag ON mr.assigned_agent_code = ag.agent_code
        LEFT JOIN admin_tbl ad ON mr.assigned_admin_id = ad.unique_id
        $where_sql
        ORDER BY 
            FIELD(mr.priority, 'emergency', 'high', 'medium', 'low'),
            FIELD(mr.status, 'pending', 'in_progress', 'resolved', 'cancelled', 'closed'),
            mr.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] ERROR: Prepare failed for main query: " . $conn->error);
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

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Main query executed successfully");

    // ==================== STEP 7: PROCESS RESULTS ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 7: Processing results");

    $requests = [];
    $row_count = 0;

    while ($row = $result->fetch_assoc()) {
        $row_count++;

        // Calculate estimated resolution time based on priority
        $estimated_days = 7;
        switch ($row['priority']) {
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

        // Determine if current admin can take action
        $can_take_action = ($row['assigned_admin_id'] == $admin_id) || $is_super_admin;

        $requests[] = [
            'request_id' => (int) $row['request_id'],
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
            'days_pending' => (int) $row['days_pending'],
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
            'auto_assigned' => (bool) $row['auto_assigned'],
            'assigned_at' => $row['assigned_at'],
            'can_take_action' => $can_take_action,
            'tenant_confirmed' => !is_null($row['tenant_confirmed_at']),
            'tenant_confirmed_at' => $row['tenant_confirmed_at'],
            'tenant_rating' => $row['tenant_rating'],
            'tenant_feedback' => $row['tenant_feedback'],
            'is_escalated' => (bool) $row['is_escalated'],
            'escalated_reason' => $row['escalated_reason'],
            'tenant_info' => [
                'tenant_code' => $row['tenant_code'],
                'tenant_name' => $row['tenant_name'],
                'email' => $row['tenant_email'],
                'phone' => $row['tenant_phone']
            ],
            'property_info' => [
                'property_code' => $row['property_code'],
                'property_name' => $row['property_name'],
                'apartment_code' => $row['apartment_code'],
                'apartment_number' => $row['apartment_number']
            ]
        ];
    }
    $stmt->close();

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Retrieved {$row_count} requests for page {$page}");

    // ==================== STEP 8: GET PROPERTY LIST ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 8: Getting property list for filters");

    $propertyListQuery = "
        SELECT DISTINCT p.property_code, p.name 
        FROM properties p
        WHERE p.status = 1
        ORDER BY p.name ASC
    ";

    $propertyListStmt = $conn->prepare($propertyListQuery);
    $propertyListStmt->execute();
    $propertyListResult = $propertyListStmt->get_result();

    $propertyList = [];
    while ($row = $propertyListResult->fetch_assoc()) {
        $propertyList[] = $row;
    }
    $propertyListStmt->close();

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Retrieved " . count($propertyList) . " properties for filter dropdown");

    // ==================== STEP 8.5: GET ADMIN LIST FOR FILTER ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 8.5: Getting admin list for filter");

    // Only Super Admin needs admin filter list
    $adminList = [];
    if ($is_super_admin) {
        $adminListQuery = "
            SELECT unique_id, CONCAT(firstname, ' ', lastname) as name
            FROM admin_tbl
            WHERE status = '1'
            ORDER BY firstname ASC
        ";
        $adminListStmt = $conn->prepare($adminListQuery);
        $adminListStmt->execute();
        $adminListResult = $adminListStmt->get_result();

        while ($row = $adminListResult->fetch_assoc()) {
            $adminList[] = $row;
        }
        $adminListStmt->close();

        logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Retrieved " . count($adminList) . " admins for filter dropdown");
    }

    // ==================== STEP 9: GET SUMMARY STATISTICS ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 9: Calculating summary statistics");

    $summary_query = "
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
            SUM(CASE WHEN mr.status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
            SUM(CASE WHEN mr.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN mr.status = 'closed' THEN 1 ELSE 0 END) as closed_count,
            SUM(CASE WHEN mr.priority = 'emergency' THEN 1 ELSE 0 END) as emergency_count,
            SUM(CASE WHEN mr.priority = 'high' THEN 1 ELSE 0 END) as high_count,
            SUM(CASE WHEN mr.priority = 'medium' THEN 1 ELSE 0 END) as medium_count,
            SUM(CASE WHEN mr.priority = 'low' THEN 1 ELSE 0 END) as low_count,
            AVG(CASE WHEN mr.status IN ('resolved', 'closed') THEN DATEDIFF(mr.resolved_at, mr.created_at) ELSE NULL END) as avg_resolution_days
    ";

    // Add assigned_to_me count
    $summary_query .= " , SUM(CASE WHEN mr.assigned_admin_id = ? THEN 1 ELSE 0 END) as assigned_to_me_count";

    $summary_query .= " FROM maintenance_requests mr
        JOIN apartments a ON mr.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON mr.tenant_code = t.tenant_code";

    // Build WHERE clause for summary
    $summary_where_clauses = [];
    $summary_params = [];
    $summary_types = "";

    // Role-based filtering for summary
    if (!$is_super_admin) {
        $summary_where_clauses[] = "(mr.assigned_admin_id = ? OR mr.assigned_agent_code IN (SELECT agent_code FROM properties WHERE agent_code IS NOT NULL))";
        $summary_params[] = $admin_id;
        $summary_types .= "i";
    }

    // Apply assigned_to_me filter if active
    if ($assigned_to_me && !$is_super_admin) {
        $summary_where_clauses[] = "mr.assigned_admin_id = ?";
        $summary_params[] = $admin_id;
        $summary_types .= "i";
    }

    // Add other filters
    if ($status && in_array($status, $allowed_statuses)) {
        $summary_where_clauses[] = "mr.status = ?";
        $summary_params[] = $status;
        $summary_types .= "s";
    }

    if ($priority && in_array($priority, $allowed_priorities)) {
        $summary_where_clauses[] = "mr.priority = ?";
        $summary_params[] = $priority;
        $summary_types .= "s";
    }

    if ($property) {
        $summary_where_clauses[] = "a.property_code = ?";
        $summary_params[] = $property;
        $summary_types .= "s";
    }

    // NEW: Assigned admin filter for summary
    if ($assigned_admin && $is_super_admin) {
        $summary_where_clauses[] = "mr.assigned_admin_id = ?";
        $summary_params[] = $assigned_admin;
        $summary_types .= "i";
    }

    if ($search) {
        $searchTerm = "%{$search}%";
        $summary_where_clauses[] = "(mr.issue_type LIKE ? OR mr.description LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ?)";
        $summary_params[] = $searchTerm;
        $summary_params[] = $searchTerm;
        $summary_params[] = $searchTerm;
        $summary_types .= "sss";
    }

    if ($date_from) {
        $summary_where_clauses[] = "DATE(mr.created_at) >= ?";
        $summary_params[] = $date_from;
        $summary_types .= "s";
    }

    if ($date_to) {
        $summary_where_clauses[] = "DATE(mr.created_at) <= ?";
        $summary_params[] = $date_to;
        $summary_types .= "s";
    }

    if (!empty($summary_where_clauses)) {
        $summary_query .= " WHERE " . implode(" AND ", $summary_where_clauses);
    }

    $summary_stmt = $conn->prepare($summary_query);

    // Build parameters array for summary
    $summary_all_params = array_merge([$admin_id], $summary_params);
    $summary_all_types = "i" . $summary_types;

    $summary_stmt->bind_param($summary_all_types, ...$summary_all_params);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result->fetch_assoc();
    $summary_stmt->close();

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Summary - Total: {$summary['total_requests']}, Pending: {$summary['pending_count']}, Assigned to me: {$summary['assigned_to_me_count']}");

    // ==================== STEP 10: BUILD RESPONSE ====================
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Step 10: Building response");

    $total_pages = ceil($total / $limit);

    $response_data = [
        'requests' => $requests,
        'summary' => [
            'total_requests' => (int) ($summary['total_requests'] ?? 0),
            'pending' => (int) ($summary['pending_count'] ?? 0),
            'in_progress' => (int) ($summary['in_progress_count'] ?? 0),
            'resolved' => (int) ($summary['resolved_count'] ?? 0),
            'cancelled' => (int) ($summary['cancelled_count'] ?? 0),
            'closed' => (int) ($summary['closed_count'] ?? 0),
            'assigned_to_me' => (int) ($summary['assigned_to_me_count'] ?? 0),
            'by_priority' => [
                'emergency' => (int) ($summary['emergency_count'] ?? 0),
                'high' => (int) ($summary['high_count'] ?? 0),
                'medium' => (int) ($summary['medium_count'] ?? 0),
                'low' => (int) ($summary['low_count'] ?? 0)
            ],
            'avg_resolution_days' => round($summary['avg_resolution_days'] ?? 0, 1)
        ],
        'properties' => $propertyList,
        'admins' => $adminList, // NEW: List of admins for filter dropdown
        'user_role' => $admin_role,
        'is_super_admin' => $is_super_admin,
        'filters' => [
            'available_statuses' => ['pending', 'in_progress', 'resolved', 'cancelled', 'closed', 'pending_reassignment'],
            'available_priorities' => ['low', 'medium', 'high', 'emergency'],
            'current_status' => $status,
            'current_priority' => $priority,
            'current_property' => $property,
            'current_assigned_admin' => $assigned_admin // NEW: Current admin filter value
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $total,
            'total_pages' => $total_pages,
            'has_next_page' => $page < $total_pages,
            'has_previous_page' => $page > 1
        ]
    ];

    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] ========== SUCCESS ==========");
    json_success($response_data, "Maintenance requests retrieved successfully");

} catch (Exception $e) {
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[FETCH_REQUESTS] [ID:{$requestId}] Error Line: " . $e->getLine());

    json_error("Failed to fetch maintenance requests: " . $e->getMessage(), 500);
}
?>