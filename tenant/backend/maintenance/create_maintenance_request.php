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

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    // $userId = $_SESSION['unique_id'];

    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400);
    }

    // Validate required fields
    $required_fields = ['issue_type', 'priority', 'description'];
    $validation_errors = [];
    
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            $validation_errors[$field] = "$field is required";
        }
    }
    
    if (!empty($validation_errors)) {
        json_validation_error($validation_errors, "Validation failed");
    }

    $issue_type = htmlspecialchars(trim($input['issue_type']));
    $priority = htmlspecialchars(trim($input['priority']));
    $description = htmlspecialchars(trim($input['description']));

    // Validate priority
    $allowed_priorities = ['low', 'medium', 'high', 'emergency'];
    if (!in_array($priority, $allowed_priorities)) {
        json_error("Invalid priority value", 400, null, 'INVALID_PRIORITY');
    }

    // Get tenant's apartment code
    $apartmentQuery = "
        SELECT apartment_code 
        FROM tenants 
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ";
    $stmt = $conn->prepare($apartmentQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenantData = $result->fetch_assoc();
    $stmt->close();

    if (!$tenantData || !$tenantData['apartment_code']) {
        json_error("No apartment assigned to this tenant", 400, null, 'NO_APARTMENT');
    }

    $apartment_code = $tenantData['apartment_code'];

    // Insert maintenance request (without image handling for now)
    $insertQuery = "
        INSERT INTO maintenance_requests (
            tenant_code,
            apartment_code,
            issue_type,
            description,
            priority,
            status,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
    ";
    
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("ssssss", $tenant_code, $apartment_code, $issue_type, $description, $priority, $tenant_code);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create maintenance request: " . $stmt->error);
    }

    $request_id = $stmt->insert_id;
    $stmt->close();

    // Log activity
    logActivity("Maintenance request created | Tenant: $tenant_code | Request ID: $request_id | Priority: $priority");

    $responseData = [
        'request_id' => $request_id,
        'tenant_code' => $tenant_code,
        'apartment_code' => $apartment_code,
        'issue_type' => $issue_type,
        'priority' => $priority,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s')
    ];

    json_created($responseData, "Maintenance request submitted successfully");

} catch (Exception $e) {
    logActivity("Error in create_maintenance_request: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>