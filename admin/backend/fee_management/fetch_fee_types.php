<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Optional: rate limiting (same style as onboarding)
rateLimit("fetch_fee_types", 10, 60); 
// 10 requests per 60 seconds for safety



try {
    // Check authentication and admin role
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $userRole = $_SESSION['role'] ?? '';
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        json_error("Unauthorized access", 403);
    }
    
    // Get all fee types
    $query = "SELECT * FROM fee_types WHERE status = 1 ORDER BY display_order, fee_name";
    $result = $conn->query($query);
    
    $fee_types = [];
    while ($row = $result->fetch_assoc()) {
        $fee_types[] = $row;
    }
    
    json_success(['fee_types' => $fee_types], "Fee types retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_fee_types: " . $e->getMessage());
    json_error("Failed to fetch fee types", 500);
}
?>