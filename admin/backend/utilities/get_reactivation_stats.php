<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_utils.php';
require_once __DIR__ . '/utils.php';

session_start();

try {
    // -----------------------------------------------------
    //  AUTHENTICATION CHECK
    // -----------------------------------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized access attempt to stats | No session | IP: " . getClientIP());
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

    logActivity("Fetch Reactivation Stats | AdminID: {$adminId} | Role: {$loggedInUserRole} | IP: " . getClientIP());

    // -----------------------------------------------------
    //  AUTHORIZATION CHECK
    // -----------------------------------------------------
    $allowedRoles = ['super admin'];
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
    //  GET STATISTICS BY STATUS
    // -----------------------------------------------------
    $statsQuery = "SELECT 
                    status,
                    COUNT(*) as count
                   FROM account_reactivation_requests 
                   GROUP BY status";
    
    logActivity("Preparing stats query: {$statsQuery}");
    
    $statsStmt = $conn->prepare($statsQuery);
    if (!$statsStmt) {
        throw new Exception("Failed to prepare stats query: " . $conn->error);
    }

    if (!$statsStmt->execute()) {
        throw new Exception("Failed to execute stats query: " . $statsStmt->error);
    }

    $statsResult = $statsStmt->get_result();
    $stats = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'expired' => 0
    ];

    while ($statRow = $statsResult->fetch_assoc()) {
        $status = strtolower($statRow['status']);
        $stats[$status] = (int)$statRow['count'];
    }
    $statsStmt->close();

    // -----------------------------------------------------
    //  GET TODAY'S REQUESTS COUNT
    // -----------------------------------------------------
    $todayQuery = "SELECT COUNT(*) as today_count 
                   FROM account_reactivation_requests 
                   WHERE DATE(created_at) = CURDATE()";
    
    $todayResult = $conn->query($todayQuery);
    $todayRow = $todayResult->fetch_assoc();
    $stats['today'] = (int)$todayRow['today_count'];
    $todayResult->close();

    // -----------------------------------------------------
    //  GET WEEKLY STATS
    // -----------------------------------------------------
    $weeklyQuery = "SELECT 
                    DATE(created_at) as request_date,
                    COUNT(*) as daily_count
                   FROM account_reactivation_requests 
                   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   GROUP BY DATE(created_at)
                   ORDER BY request_date DESC";
    
    $weeklyResult = $conn->query($weeklyQuery);
    $weeklyStats = [];
    while ($weekRow = $weeklyResult->fetch_assoc()) {
        $weeklyStats[] = $weekRow;
    }
    $weeklyResult->close();

    // -----------------------------------------------------
    //  GET USER TYPE DISTRIBUTION
    // -----------------------------------------------------
    $userTypeQuery = "SELECT 
                      user_type,
                      COUNT(*) as count
                     FROM account_reactivation_requests 
                     GROUP BY user_type";
    
    $userTypeResult = $conn->query($userTypeQuery);
    $userTypeStats = [];
    while ($typeRow = $userTypeResult->fetch_assoc()) {
        $userTypeStats[$typeRow['user_type']] = (int)$typeRow['count'];
    }
    $userTypeResult->close();

    // -----------------------------------------------------
    //  GET PENDING REQUESTS BY DAYS
    // -----------------------------------------------------
    $pendingAgeQuery = "SELECT 
                        CASE 
                            WHEN DATEDIFF(NOW(), created_at) = 0 THEN 'Today'
                            WHEN DATEDIFF(NOW(), created_at) = 1 THEN 'Yesterday'
                            WHEN DATEDIFF(NOW(), created_at) BETWEEN 2 AND 7 THEN 'This Week'
                            WHEN DATEDIFF(NOW(), created_at) BETWEEN 8 AND 30 THEN 'This Month'
                            ELSE 'Older'
                        END as age_group,
                        COUNT(*) as count
                       FROM account_reactivation_requests 
                       WHERE status = 'pending'
                       GROUP BY age_group";
    
    $pendingAgeResult = $conn->query($pendingAgeQuery);
    $pendingAgeStats = [];
    while ($ageRow = $pendingAgeResult->fetch_assoc()) {
        $pendingAgeStats[$ageRow['age_group']] = (int)$ageRow['count'];
    }
    $pendingAgeResult->close();

    // Calculate total requests
    $totalRequests = array_sum($stats);
    
    // Close connection
    $conn->close();

    logActivity("Reactivation Stats Fetch Success | Total: {$totalRequests}");

    // -----------------------------------------------------
    //  RESPONSE
    // -----------------------------------------------------
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "statistics" => $stats,
        "total_requests" => $totalRequests,
        "weekly_stats" => $weeklyStats,
        "user_type_stats" => $userTypeStats,
        "pending_age_stats" => $pendingAgeStats,
        "timestamp" => date('Y-m-d H:i:s'),
        "message" => "Statistics retrieved successfully"
    ]);

} catch (Exception $e) {
    // Log the full error with trace
    logActivity("STATS EXCEPTION | " . $e->getMessage() . " | Line: " . $e->getLine() . " | File: " . $e->getFile() . " | IP: " . getClientIP());
    
    // Close connections if open
    if (isset($statsStmt) && $statsStmt) {
        $statsStmt->close();
    }
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno == 0) {
        $conn->close();
    }

    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "An unexpected error occurred while fetching statistics.",
        "error" => $e->getMessage(), // Only include in development
        "code" => 500
    ]);
    exit();
}
?>