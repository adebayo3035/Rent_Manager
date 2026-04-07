<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $tenant_code = $_SESSION['tenant_code'] ?? null;
    
    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }
    
    $status = $_GET['status'] ?? null;
    
    $query = "
        SELECT 
            tf.tenant_fee_id,
            tf.amount,
            tf.due_date,
            tf.status,
            tf.notes,
            ft.fee_name,
            ft.fee_code,
            ft.is_mandatory,
            ft.calculation_type,
            ft.is_recurring,
            ft.recurrence_period
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        WHERE tf.tenant_code = ?
    ";
    
    $params = [$tenant_code];
    $types = "s";
    
    if ($status && in_array($status, ['pending', 'paid', 'overdue', 'waived'])) {
        $query .= " AND tf.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    $query .= " ORDER BY tf.due_date ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $fees = [];
    while ($row = $result->fetch_assoc()) {
        $fees[] = $row;
    }
    $stmt->close();
    
    json_success(['fees' => $fees], "Tenant fees retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_tenant_fees: " . $e->getMessage());
    json_error("Failed to fetch tenant fees", 500);
}
?>