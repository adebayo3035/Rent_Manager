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

    // Fetch all active fee types
    $query = "SELECT fee_type_id, fee_code, fee_name, description, is_mandatory, calculation_type, is_recurring, recurrence_period 
              FROM fee_types 
              WHERE status = 1 
              ORDER BY display_order, fee_name";
    
    $result = $conn->query($query);
    
    $fee_types = [];
    while ($row = $result->fetch_assoc()) {
        $fee_types[] = [
            'fee_type_id' => (int)$row['fee_type_id'],
            'fee_code' => $row['fee_code'],
            'fee_name' => $row['fee_name'],
            'description' => $row['description'],
            'is_mandatory' => (bool)$row['is_mandatory'],
            'calculation_type' => $row['calculation_type'],
            'is_recurring' => (bool)$row['is_recurring'],
            'recurrence_period' => $row['recurrence_period']
        ];
    }
    
    json_success(['fee_types' => $fee_types], "Fee types retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_fee_types: " . $e->getMessage());
    json_error("Failed to fetch fee types", 500);
}
?>