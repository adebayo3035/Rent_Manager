<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication and admin role
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $userRole = $_SESSION['role'] ?? '';
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        json_error("Unauthorized access", 403);
    }
    
    // Get all active properties
    $query = "SELECT property_code, name, address, status FROM properties WHERE status = 1 ORDER BY name DESC";
    $result = $conn->query($query);
    
    $properties = [];
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
    
    // Return using json_success with data in 'data' key
    json_success(['properties' => $properties], "Properties retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_properties: " . $e->getMessage());
    json_error("Failed to fetch Properties", 500);
}
?>