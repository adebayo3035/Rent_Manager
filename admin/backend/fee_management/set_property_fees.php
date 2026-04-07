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
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400);
    }
    
    $property_code = $input['property_code'] ?? '';
    $fees = $input['fees'] ?? []; // Array of [apartment_type_id => [fee_type_id => amount]]
    $effective_from = $input['effective_from'] ?? date('Y-m-d');
    
    if (empty($property_code)) {
        json_error("Property code required", 400);
    }
    
    if (empty($fees)) {
        json_error("Fees data required", 400);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, deactivate old fees for this property
        $deactivate_query = "UPDATE property_apartment_type_fees 
                             SET is_active = 0, updated_at = NOW() 
                             WHERE property_code = ? AND effective_to IS NULL";
        $deactivate_stmt = $conn->prepare($deactivate_query);
        $deactivate_stmt->bind_param("s", $property_code);
        $deactivate_stmt->execute();
        $deactivate_stmt->close();
        
        // Insert new fees
        $insert_query = "INSERT INTO property_apartment_type_fees 
                        (property_code, apartment_type_id, fee_type_id, amount, effective_from, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        
        foreach ($fees as $apartment_type_id => $fee_types) {
            foreach ($fee_types as $fee_type_id => $amount) {
                $insert_stmt->bind_param("siidsi", $property_code, $apartment_type_id, $fee_type_id, $amount, $effective_from, $_SESSION['unique_id']);
                $insert_stmt->execute();
            }
        }
        
        $insert_stmt->close();
        
        $conn->commit();
        
        logActivity("Property fees set for property: $property_code by user {$_SESSION['unique_id']}");
        json_success(null, "Property fees set successfully");
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    logActivity("Error in set_property_fees: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>