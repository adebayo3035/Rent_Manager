<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

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
    
    $action = $input['action'] ?? 'create';
    $fee_type_id = $input['fee_type_id'] ?? null;
    $fee_code = $input['fee_code'] ?? '';
    $fee_name = $input['fee_name'] ?? '';
    $description = $input['description'] ?? '';
    $is_mandatory = $input['is_mandatory'] ?? 1;
    $calculation_type = $input['calculation_type'] ?? 'fixed';
    $is_recurring = $input['is_recurring'] ?? 0;
    $recurrence_period = $input['recurrence_period'] ?? 'one-time';
    $display_order = $input['display_order'] ?? 0;
    
    if ($action === 'create') {
        // Insert new fee type
        $query = "INSERT INTO fee_types (fee_code, fee_name, description, is_mandatory, calculation_type, is_recurring, recurrence_period, display_order) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssisssi", $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create fee type: " . $stmt->error);
        }
        
        $fee_type_id = $stmt->insert_id;
        $stmt->close();
        
        logActivity("Fee type created: $fee_name by user {$_SESSION['unique_id']}");
        json_success(['fee_type_id' => $fee_type_id], "Fee type created successfully", 201);
        
    } elseif ($action === 'update') {
        // Update existing fee type
        if (!$fee_type_id) {
            json_error("Fee type ID required for update", 400);
        }
        
        $query = "UPDATE fee_types SET fee_code = ?, fee_name = ?, description = ?, is_mandatory = ?, 
                  calculation_type = ?, is_recurring = ?, recurrence_period = ?, display_order = ? 
                  WHERE fee_type_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssisssii", $fee_code, $fee_name, $description, $is_mandatory, $calculation_type, $is_recurring, $recurrence_period, $display_order, $fee_type_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update fee type: " . $stmt->error);
        }
        
        $stmt->close();
        
        logActivity("Fee type updated: $fee_name by user {$_SESSION['unique_id']}");
        json_success(null, "Fee type updated successfully");
        
    } else {
        json_error("Invalid action", 400);
    }
    
} catch (Exception $e) {
    logActivity("Error in manage_fee_type: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>