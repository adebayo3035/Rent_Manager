<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $property_code = $_GET['property_code'] ?? '';
    $apartment_type_id = $_GET['apartment_type_id'] ?? null;
    
    if (empty($property_code)) {
        json_error("Property code required", 400);
    }
    
    $query = "
        SELECT 
            pf.fee_id,
            pf.property_code,
            pf.apartment_type_id,
            pf.fee_type_id,
            pf.amount,
            pf.is_active,
            pf.effective_from,
            ft.fee_name,
            ft.fee_code,
            ft.calculation_type,
            ft.is_mandatory,
            ft.is_recurring,
            ft.recurrence_period,
            at.type_name as apartment_type_name
        FROM property_apartment_type_fees pf
        JOIN fee_types ft ON pf.fee_type_id = ft.fee_type_id
        JOIN apartment_type at ON pf.apartment_type_id = at.type_id
        WHERE pf.property_code = ? 
        AND pf.is_active = 1
    ";
    
    $params = [$property_code];
    $types = "s";
    
    if ($apartment_type_id) {
        $query .= " AND pf.apartment_type_id = ?";
        $params[] = $apartment_type_id;
        $types .= "i";
    }
    
    $query .= " ORDER BY at.type_name, ft.display_order";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $fees = [];
    while ($row = $result->fetch_assoc()) {
        $fees[] = $row;
    }
    $stmt->close();
    
    // Group by apartment type
    $grouped_fees = [];
    foreach ($fees as $fee) {
        $type_name = $fee['apartment_type_name'];
        if (!isset($grouped_fees[$type_name])) {
            $grouped_fees[$type_name] = [
                'apartment_type_id' => $fee['apartment_type_id'],
                'fees' => []
            ];
        }
        $grouped_fees[$type_name]['fees'][] = [
            'fee_id' => $fee['fee_id'],
            'fee_type_id' => $fee['fee_type_id'],
            'fee_name' => $fee['fee_name'],
            'fee_code' => $fee['fee_code'],
            'amount' => (float)$fee['amount'],
            'calculation_type' => $fee['calculation_type'],
            'is_mandatory' => (bool)$fee['is_mandatory'],
            'is_recurring' => (bool)$fee['is_recurring'],
            'recurrence_period' => $fee['recurrence_period'],
            'effective_from' => $fee['effective_from']
        ];
    }
    
    json_success(['property_fees' => $grouped_fees], "Property fees retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_property_fees: " . $e->getMessage());
    json_error("Failed to fetch property fees", 500);
}
?>