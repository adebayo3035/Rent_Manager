<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Initialize variables to avoid undefined errors
$countStmt = null;
$stmt = null;
$statsStmt = null;

try {
    // -----------------------------------------------------
    //  RATE LIMIT CHECK
    // -----------------------------------------------------
    rateLimit("fetch_account_reactivation_requests", 60, 60); // 60 requests per IP per minute

    // -----------------------------------------------------
    //  AUTHENTICATION CHECK
    // -----------------------------------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized access attempt | No session | IP: " . getClientIP());
        http_response_code(401);
        echo json_encode([
            "success" => false, 
            "message" => "Not logged in. Please login again.",
            "code" => 401
        ]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? 'Unknown';

    logActivity("Fetch Account Reactivation Requests | AdminID: {$adminId} | Role: {$loggedInUserRole} | IP: " . getClientIP());

    // -----------------------------------------------------
    //  AUTHORIZATION CHECK
    // -----------------------------------------------------
    $allowedRoles = ['super admin']; // Adjust based on your system
    if (!in_array(strtolower($loggedInUserRole), $allowedRoles)) {
        logActivity("Unauthorized role access attempt | Role: {$loggedInUserRole} | AdminID: {$adminId}");
        http_response_code(403);
        echo json_encode([
            "success" => false, 
            "message" => "You don't have permission to access this resource.",
            "code" => 403
        ]);
        exit();
    }

    // -----------------------------------------------------
    //  DATABASE CONNECTION CHECK
    // -----------------------------------------------------
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        logActivity("DB Connection Failure | IP: " . getClientIP());
        http_response_code(503);
        echo json_encode([
            "success" => false, 
            "message" => "Database connection error. Please try again later.",
            "code" => 503
        ]);
        exit();
    }

    // -----------------------------------------------------
    //  VALIDATE PAGINATION INPUTS
    // -----------------------------------------------------
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    
    // Validate ranges
    $page = max(1, $page); // Minimum page is 1
    $limit = min(max(1, $limit), 100); // Limit between 1 and 100
    
    $offset = ($page - 1) * $limit;

    logActivity("Pagination Parsed | Page: {$page} | Limit: {$limit} | Offset: {$offset}");

    // -----------------------------------------------------
    //  FILTER INPUTS
    // -----------------------------------------------------
    $user_type = isset($_GET['user_type']) ? trim($_GET['user_type']) : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;

    $allowedUserTypes = ['admin', 'agent', 'client', 'tenant'];
    $allowedStatuses = ['pending', 'approved', 'rejected', 'expired'];

    logActivity("Filter Inputs | User Type: {$user_type} | Status: {$status} | Search: {$search} | Date From: {$date_from} | Date To: {$date_to}");

    // -----------------------------------------------------
    //  BUILD WHERE CLAUSE (with table alias 'ar')
    // -----------------------------------------------------
    $whereClauses = [];
    $params = [];
    $types = '';

    // User Type filter - use table alias 'ar'
    if ($user_type !== null && $user_type !== '' && in_array(strtolower($user_type), $allowedUserTypes)) {
        $whereClauses[] = "ar.user_type = ?";
        $params[] = strtolower($user_type);
        $types .= 's';
    }

    // Status filter - use table alias 'ar'
    if ($status !== null && $status !== '' && in_array(strtolower($status), $allowedStatuses)) {
        $whereClauses[] = "ar.status = ?";
        $params[] = strtolower($status);
        $types .= 's';
    }

    // Search filter - use table alias 'ar'
    if ($search !== null && $search !== '') {
        $whereClauses[] = "(ar.email LIKE ? OR ar.user_id LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $types .= 'ss';
    }

    // Date range filter - use table alias 'ar'
    if ($date_from !== null && $date_from !== '') {
        if (DateTime::createFromFormat('Y-m-d', $date_from) !== false) {
            $whereClauses[] = "DATE(ar.created_at) >= ?";
            $params[] = $date_from;
            $types .= 's';
        }
    }

    if ($date_to !== null && $date_to !== '') {
        if (DateTime::createFromFormat('Y-m-d', $date_to) !== false) {
            $whereClauses[] = "DATE(ar.created_at) <= ?";
            $params[] = $date_to;
            $types .= 's';
        }
    }

    $whereSQL = count($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    logActivity("Generated WHERE SQL: {$whereSQL}");

    // -----------------------------------------------------
    //  TOTAL COUNT QUERY (use same alias 'ar')
    // -----------------------------------------------------
    $totalQuery = "SELECT COUNT(*) AS total FROM account_reactivation_requests ar {$whereSQL}";
    logActivity("Preparing Total Count Query: {$totalQuery}");

    $countStmt = $conn->prepare($totalQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare total count query: " . $conn->error);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    if (!$countStmt->execute()) {
        throw new Exception("Failed to execute total count query: " . $countStmt->error);
    }

    $totalResult = $countStmt->get_result();
    $totalRow = $totalResult->fetch_assoc();
    $totalRequests = $totalRow['total'] ?? 0;
    
    // Close count statement
    if ($countStmt) {
        $countStmt->close();
        $countStmt = null;
    }

    logActivity("Total Account Reactivation Requests Found: {$totalRequests}");

    // -----------------------------------------------------
    //  MAIN QUERY WITH USER DETAILS
    // -----------------------------------------------------
    $query = "SELECT 
                ar.*,
                CASE ar.user_type
                    WHEN 'admin' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM admin_tbl WHERE unique_id = ar.user_id)
                    WHEN 'agent' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM agents WHERE agent_code = ar.user_id)
                    WHEN 'client' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM clients WHERE client_code = ar.user_id)
                    WHEN 'tenant' THEN (SELECT CONCAT_WS(' ', firstname, lastname) FROM tenants WHERE tenant_code = ar.user_id)
                END as user_full_name,
                CASE ar.user_type
                    WHEN 'admin' THEN (SELECT phone FROM admin_tbl WHERE unique_id = ar.user_id)
                    WHEN 'agent' THEN (SELECT phone FROM agents WHERE agent_code = ar.user_id)
                    WHEN 'client' THEN (SELECT phone FROM clients WHERE client_code = ar.user_id)
                    WHEN 'tenant' THEN (SELECT phone FROM tenants WHERE tenant_code = ar.user_id)
                END as user_phone,
                CASE ar.user_type
                    WHEN 'admin' THEN (SELECT status FROM admin_tbl WHERE unique_id = ar.user_id)
                    WHEN 'agent' THEN (SELECT status FROM agents WHERE agent_code = ar.user_id)
                    WHEN 'client' THEN (SELECT status FROM clients WHERE client_code = ar.user_id)
                    WHEN 'tenant' THEN (SELECT status FROM tenants WHERE tenant_code = ar.user_id)
                END as user_current_status,
                CONCAT_WS(' ', a.firstname, a.lastname) as reviewed_by_name
              FROM 
                account_reactivation_requests ar
              LEFT JOIN admin_tbl a ON ar.reviewed_by = a.unique_id
              {$whereSQL}
              ORDER BY 
                CASE ar.status
                    WHEN 'pending' THEN 1
                    WHEN 'approved' THEN 2
                    WHEN 'rejected' THEN 3
                    WHEN 'expired' THEN 4
                END,
                ar.created_at DESC
              LIMIT ? OFFSET ?";

    logActivity("Preparing Account Reactivation Requests Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare Account Reactivation Requests fetch query: " . $conn->error);
    }

    // Add pagination params
    $paramsWithPagination = $params;
    $typesWithPagination = $types;
    
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;
    $typesWithPagination .= 'ii';

    if (!empty($paramsWithPagination)) {
        $stmt->bind_param($typesWithPagination, ...$paramsWithPagination);
    }

    logActivity("Executing Query with params: " . json_encode($paramsWithPagination));

    if (!$stmt->execute()) {
        throw new Exception("Failed to execute query: " . $stmt->error);
    }

    $result = $stmt->get_result();

    $account_reactivation_requests = [];
    while ($row = $result->fetch_assoc()) {
        // Format dates for better display
        if (!empty($row['created_at'])) {
            $row['created_at_formatted'] = date('Y-m-d H:i', strtotime($row['created_at']));
        }
        if (!empty($row['review_timestamp'])) {
            $row['review_timestamp_formatted'] = date('Y-m-d H:i', strtotime($row['review_timestamp']));
        }
        if (!empty($row['expires_at'])) {
            $row['expires_at_formatted'] = date('Y-m-d H:i', strtotime($row['expires_at']));
        }
        
        // Clean up NULL names
        if (empty($row['user_full_name'])) {
            $row['user_full_name'] = 'Unknown';
        }
        if (empty($row['reviewed_by_name'])) {
            $row['reviewed_by_name'] = 'Not reviewed yet';
        }
        
        // Status badge styling
        $statusColors = [
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'expired' => 'secondary'
        ];
        
        $row['status_badge'] = $statusColors[$row['status']] ?? 'secondary';
        
        // Add user type display name
        $userTypeDisplay = [
            'admin' => 'Admin',
            'agent' => 'Agent',
            'client' => 'Client',
            'tenant' => 'Tenant'
        ];
        
        $row['user_type_display'] = $userTypeDisplay[$row['user_type']] ?? ucfirst($row['user_type']);
        
        $account_reactivation_requests[] = $row;
    }

    // Close main statement
    if ($stmt) {
        $stmt->close();
        $stmt = null;
    }
    
    // -----------------------------------------------------
    //  ADDITIONAL STATISTICS
    // -----------------------------------------------------
    $statsQuery = "SELECT 
                    status,
                    COUNT(*) as count
                   FROM account_reactivation_requests
                   GROUP BY status";
    
    $statsStmt = $conn->prepare($statsQuery);
    $stats = [];
    if ($statsStmt) {
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result();
        while ($statRow = $statsResult->fetch_assoc()) {
            $stats[$statRow['status']] = $statRow['count'];
        }
        
        // Close stats statement
        $statsStmt->close();
        $statsStmt = null;
    }

    // Close connection
    $conn->close();
    $conn = null;

    logActivity("Account Reactivation Requests Fetch Success | Returned: " . count($account_reactivation_requests) . " rows");

    // -----------------------------------------------------
    //  RESPONSE
    // -----------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "account_reactivation_requests" => $account_reactivation_requests,
        "pagination" => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$totalRequests,
            'total_pages' => $limit > 0 ? ceil($totalRequests / $limit) : 0,
            'has_next_page' => ($page * $limit) < $totalRequests,
            'has_prev_page' => $page > 1
        ],
        "filters" => [
            'user_type' => $user_type,
            'status' => $status,
            'search' => $search,
            'date_from' => $date_from,
            'date_to' => $date_to
        ],
        "statistics" => $stats,
        "logged_in_user_role" => $loggedInUserRole,
        "timestamp" => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    // Log the full error with trace
    logActivity("EXCEPTION | " . $e->getMessage() . " | Line: " . $e->getLine() . " | File: " . $e->getFile() . " | IP: " . getClientIP());
    
    // Safely close statements only if they exist and are not already closed
    try {
        if ($countStmt && $countStmt instanceof mysqli_stmt) {
            $countStmt->close();
        }
    } catch (Exception $stmtError) {
        // Ignore errors when closing count statement
    }
    
    try {
        if ($stmt && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    } catch (Exception $stmtError) {
        // Ignore errors when closing main statement
    }
    
    try {
        if ($statsStmt && $statsStmt instanceof mysqli_stmt) {
            $statsStmt->close();
        }
    } catch (Exception $stmtError) {
        // Ignore errors when closing stats statement
    }
    
    // Close connection if exists and is open
    try {
        if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno == 0) {
            $conn->close();
        }
    } catch (Exception $connError) {
        // Ignore errors when closing connection
    }

    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "An unexpected error occurred. Please try again.",
        "error" => $e->getMessage(),
        "code" => 500
    ]);
    exit();
}