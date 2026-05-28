<?php
// client/backend/tenants/fetch_tenants.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('fetch_tenants_', true);
logActivity("[FETCH_TENANTS] [ID:{$requestId}] ========== START ==========");

try {
    // Check authentication
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Checking authentication");
    
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] ERROR: Unauthorized - No client session");
        json_error("Unauthorized", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Client authenticated: {$client_code}");
    
    // Get pagination and filter parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 20;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $property_filter = isset($_GET['property_code']) ? trim($_GET['property_code']) : '';
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'firstname';
    $sort_order = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Parameters - Page: {$page}, Limit: {$limit}, Property: {$property_filter}, Status: {$status_filter}, Search: {$search_term}, Sort: {$sort_by} {$sort_order}");
    
    // Validate sort_by - using actual column names from tenants table
    $allowed_sort = ['firstname', 'lastname', 'email', 'phone', 'status', 'lease_start_date', 'lease_end_date', 'created_at'];
    if (!in_array($sort_by, $allowed_sort)) {
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] Invalid sort column: {$sort_by}, defaulting to firstname");
        $sort_by = 'firstname';
    }
    
    // Map sort_by to actual column
    $sort_column_map = [
        'firstname' => 't.firstname',
        'lastname' => 't.lastname',
        'email' => 't.email',
        'phone' => 't.phone',
        'status' => 't.status',
        'lease_start_date' => 't.lease_start_date',
        'lease_end_date' => 't.lease_end_date',
        'created_at' => 't.created_at'
    ];
    $sort_column = $sort_column_map[$sort_by];
    
    // Build base query
    $baseQuery = "
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        LEFT JOIN (
            SELECT tenant_code, AVG(rating) as avg_rating, COUNT(*) as rating_count
            FROM tenant_ratings
            WHERE client_code = ?
            GROUP BY tenant_code
        ) tr ON t.tenant_code = tr.tenant_code
        WHERE p.client_code = ?
        AND t.deleted_at IS NULL
    ";
    
    $params = [$client_code, $client_code];
    $types = "ss";
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Building query with filters");
    
    // Property filter
    if (!empty($property_filter)) {
        $baseQuery .= " AND p.property_code = ?";
        $params[] = $property_filter;
        $types .= "s";
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] Applying property filter: {$property_filter}");
    }
    
    // Status filter (using tenant_status column which has values: '0' - Inactive, '1' - Active, '2' - Suspended, '3' - Evicted)
    if ($status_filter !== '') {
        $baseQuery .= " AND t.status = ?";
        $params[] = $status_filter;
        $types .= "s";
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] Applying status filter: {$status_filter}");
    }
    
    // Search filter
    if (!empty($search_term)) {
        $search_term = "%{$search_term}%";
        $baseQuery .= " AND (t.firstname LIKE ? OR t.lastname LIKE ? OR t.email LIKE ? OR t.phone LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ssss";
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] Applying search filter: {$search_term}");
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(DISTINCT t.tenant_code) as total " . $baseQuery;
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Executing count query");
    
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] ERROR: Failed to prepare count query: " . $conn->error);
        throw new Exception("Database prepare error");
    }
    
    $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) {
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] ERROR: Failed to execute count query: " . $countStmt->error);
        throw new Exception("Database execute error");
    }
    
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Total records found: {$totalRecords}");
    
    // Calculate pagination
    $totalPages = $totalRecords > 0 ? ceil($totalRecords / $limit) : 1;
    $from = $totalRecords > 0 ? $offset + 1 : 0;
    $to = min($offset + $limit, $totalRecords);
    
    // Main query
    $query = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email,
            t.phone,
            t.status as status,
            t.tenant_status as account_status,
            t.lease_start_date,
            t.lease_end_date,
            t.photo,
            t.apartment_code,
            t.created_at,
            t.evacuation_status,
            t.move_out_date,
            a.apartment_number,
            p.property_code,
            p.name as property_name,
            COALESCE(tr.avg_rating, 0) as avg_rating,
            COALESCE(tr.rating_count, 0) as rating_count,
            DATEDIFF(t.lease_end_date, CURDATE()) as days_remaining,
            t.payment_frequency,
            t.payment_amount_per_period
        " . $baseQuery . "
        ORDER BY {$sort_column} {$sort_order}
        LIMIT ? OFFSET ?
    ";
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Executing main query with limit: {$limit}, offset: {$offset}");
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] ERROR: Failed to prepare main query: " . $conn->error);
        throw new Exception("Database prepare error");
    }
    
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        logActivity("[FETCH_TENANTS] [ID:{$requestId}] ERROR: Failed to execute main query: " . $stmt->error);
        throw new Exception("Database execute error");
    }
    
    $result = $stmt->get_result();
    
    $tenants = [];
    while ($row = $result->fetch_assoc()) {
        // Format lease dates
        $row['lease_start_formatted'] = $row['lease_start_date'] ? date('M d, Y', strtotime($row['lease_start_date'])) : 'N/A';
        $row['lease_end_formatted'] = $row['lease_end_date'] ? date('M d, Y', strtotime($row['lease_end_date'])) : 'N/A';
        $row['created_at_formatted'] = $row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) : 'N/A';
        
        // Determine lease status
        if ($row['lease_end_date']) {
            $end_date = new DateTime($row['lease_end_date']);
            $today = new DateTime();
            if ($end_date < $today) {
                $row['lease_status'] = 'expired';
            } elseif ($row['days_remaining'] <= 30) {
                $row['lease_status'] = 'expiring_soon';
            } else {
                $row['lease_status'] = 'active';
            }
        } else {
            $row['lease_status'] = 'unknown';
        }
        
        // Map tenant_status to readable status
        $status_map = [
            '0' => 'Inactive',
            '1' => 'Active',
            '2' => 'Suspended',
            '3' => 'Evicted'
        ];
        $row['status_text'] = $status_map[$row['status']] ?? 'Unknown';
        $row['status_badge'] = $row['status'] == '1' ? 'active' : ($row['status'] == '2' ? 'suspended' : 'inactive');
        
        // Format rating stars
        $row['rating_stars'] = round($row['avg_rating'], 1);
        $row['has_rating'] = $row['rating_count'] > 0;
        
        $tenants[] = $row;
    }
    $stmt->close();
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Retrieved " . count($tenants) . " tenants");
    
    // Get properties for filter
    $propertyQuery = "
        SELECT DISTINCT p.property_code, p.name
        FROM properties p
        WHERE p.client_code = ? AND p.status = 1
        ORDER BY p.name
    ";
    $propStmt = $conn->prepare($propertyQuery);
    $propStmt->bind_param("s", $client_code);
    $propStmt->execute();
    $propResult = $propStmt->get_result();
    $properties = [];
    while ($row = $propResult->fetch_assoc()) {
        $properties[] = $row;
    }
    $propStmt->close();
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Retrieved " . count($properties) . " properties for filter");
    
    // Get summary statistics
    $summaryQuery = "
        SELECT 
            COUNT(DISTINCT t.tenant_code) as total_tenants,
            SUM(CASE WHEN t.status = '1' THEN 1 ELSE 0 END) as active_tenants,
            SUM(CASE WHEN t.status = '0' THEN 1 ELSE 0 END) as inactive_tenants,
            SUM(CASE WHEN t.status = '2' THEN 1 ELSE 0 END) as suspended_tenants,
            SUM(CASE WHEN t.status = '3' THEN 1 ELSE 0 END) as evicted_tenants,
            SUM(CASE WHEN t.lease_end_date < CURDATE() AND t.status = '1' THEN 1 ELSE 0 END) as expired_leases
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        WHERE p.client_code = ?
        AND t.deleted_at IS NULL
    ";
    $sumStmt = $conn->prepare($summaryQuery);
    $sumStmt->bind_param("s", $client_code);
    $sumStmt->execute();
    $summary = $sumStmt->get_result()->fetch_assoc();
    $sumStmt->close();
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Summary - Total: {$summary['total_tenants']}, Active: {$summary['active_tenants']}, Inactive: {$summary['inactive_tenants']}");
    
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] ========== SUCCESS ==========");
    
    json_success([
        'tenants' => $tenants,
        'summary' => $summary,
        'properties' => $properties,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages
        ],
        'filters' => [
            'current_property' => $property_filter,
            'current_status' => $status_filter,
            'current_search' => $search_term,
            'sort_by' => $sort_by,
            'sort_order' => strtolower($sort_order)
        ]
    ], "Tenants retrieved successfully");
    
} catch (Exception $e) {
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] ERROR EXCEPTION: " . $e->getMessage());
    logActivity("[FETCH_TENANTS] [ID:{$requestId}] Stack trace: " . $e->getTraceAsString());
    json_error("Failed to fetch tenants: " . $e->getMessage(), 500);
}