<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check if user is logged in
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }

    $userId = $_SESSION['tenant_code'];
    $tenant_code = $_SESSION['tenant_code'] ?? null;

    if (!$tenant_code) {
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }

    // Fetch tenant details
    $query = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            t.gender,
            t.photo,
            t.apartment_code,
            t.property_code,
            t.lease_start_date,
            t.lease_end_date,
            t.payment_frequency,
            t.status,
            t.agreed_rent_amount,
            t.payment_amount_per_period,
            t.has_secret_set,
            a.apartment_number,
            a.rent_amount,
            a.security_deposit,
            p.name as property_name,
            p.address as property_address
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Tenant not found", 404, null, 'TENANT_NOT_FOUND');
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    // Return success with user data
    json_success($user, "User data retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_user_data: " . $e->getMessage());
    json_error("Failed to fetch user data", 500, null, 'SERVER_ERROR');
}
