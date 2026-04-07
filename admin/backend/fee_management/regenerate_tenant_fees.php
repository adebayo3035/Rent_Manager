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
    
    $property_code = $_POST['property_code'] ?? $_GET['property_code'] ?? '';
    $apartment_type_id = $_POST['apartment_type_id'] ?? $_GET['apartment_type_id'] ?? null;
    
    if (empty($property_code)) {
        json_error("Property code is required", 400);
    }
    
    // Build the query to regenerate fees
    $query = "
        INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status, created_at)
        SELECT 
            t.tenant_code,
            t.apartment_code,
            pf.fee_type_id,
            pf.amount,
            CASE 
                WHEN ft.is_recurring = 0 THEN DATE_ADD(t.lease_start_date, INTERVAL 7 DAY)
                ELSE DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
            END as due_date,
            'pending',
            NOW()
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN property_apartment_type_fees pf ON a.property_code = pf.property_code AND a.apartment_type_id = pf.apartment_type_id
        INNER JOIN fee_types ft ON pf.fee_type_id = ft.fee_type_id
        WHERE a.property_code = ?
    ";
    
    $params = [$property_code];
    $types = "s";
    
    if ($apartment_type_id) {
        $query .= " AND a.apartment_type_id = ?";
        $params[] = $apartment_type_id;
        $types .= "i";
    }
    
    $query .= " AND pf.is_active = 1
                AND t.status = 1
                AND NOT EXISTS (
                    SELECT 1 FROM tenant_fees tf 
                    WHERE tf.tenant_code = t.tenant_code 
                    AND tf.fee_type_id = pf.fee_type_id
                )";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $fees_created = $stmt->affected_rows;
    $stmt->close();
    
    logActivity("Regenerated tenant fees for property: $property_code, apartment_type: $apartment_type_id, fees created: $fees_created");
    
    json_success([
        'property_code' => $property_code,
        'apartment_type_id' => $apartment_type_id,
        'fees_created' => $fees_created
    ], "Successfully generated $fees_created tenant fees");
    
} catch (Exception $e) {
    logActivity("Error in regenerate_tenant_fees: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>