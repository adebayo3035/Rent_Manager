<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();
try {
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $query = "
        SELECT 
            tf.tenant_fee_id,
            tf.amount,
            tf.due_date,
            tf.status,
            tf.notes,
            ft.fee_name,
            ft.fee_code,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.tenant_code,
            a.apartment_number
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        JOIN tenants t ON tf.tenant_code = t.tenant_code
        LEFT JOIN apartments a ON tf.apartment_code = a.apartment_code
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";
    
    if ($status) {
        $query .= " AND tf.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($search) {
        $query .= " AND (t.firstname LIKE ? OR t.lastname LIKE ? OR t.tenant_code LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "sss";
    }
    
    $query .= " ORDER BY tf.due_date ASC LIMIT 100";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
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