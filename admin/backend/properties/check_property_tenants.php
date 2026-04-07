<?php
header('Content-Type: application/json');
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
    
    $property_code = $_GET['property_code'] ?? '';
    
    if (empty($property_code)) {
        json_error("Property code required", 400);
    }
    
    // Check if property has any tenants
    $query = "
        SELECT COUNT(DISTINCT t.tenant_code) as tenant_count
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        WHERE a.property_code = ? AND t.status = 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $property_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $has_tenants = ($row['tenant_count'] > 0);
    
    json_success([
        'has_tenants' => $has_tenants,
        'tenant_count' => (int)$row['tenant_count'],
        'property_code' => $property_code
    ], "Property tenant check completed");
    
} catch (Exception $e) {
    logActivity("Error in check_property_tenants: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>