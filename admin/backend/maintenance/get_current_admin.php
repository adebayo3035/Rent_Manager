<?php
// admin/backend/maintenance/get_current_admin.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();
try {
    if (!isset($_SESSION['unique_id'])) {
        json_error("Unauthorized", 401);
    }

    $admin_id = $_SESSION['unique_id'];
    $admin_role = $_SESSION['role'];

    $query = "SELECT unique_id, firstname, lastname, role FROM admin_tbl WHERE unique_id = ? AND status = '1' LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    $stmt->close();

    if (!$admin) {
        json_error("Admin not found", 404);
    }

    json_success([
        'unique_id' => $admin['unique_id'],
        'firstname' => $admin['firstname'],
        'lastname' => $admin['lastname'],
        'role' => $admin['role']
    ], "Admin retrieved");

} catch (Exception $e) {
    logActivity("Error in get_current_admin: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>