<?php
// admin/backend/maintenance/get_available_admins.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['unique_id']) || $_SESSION['role'] !== 'Super Admin') {
        json_error("Unauthorized", 401);
    }

    $query = "
        SELECT 
            unique_id,
            firstname,
            lastname,
            email,
            (SELECT COUNT(*) FROM maintenance_requests WHERE assigned_admin_id = admin_tbl.unique_id AND status IN ('pending', 'in_progress')) as active_requests
        FROM admin_tbl
        WHERE status = '1' AND role != 'Super Admin'
        ORDER BY active_requests ASC, firstname ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();

    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    $stmt->close();

    // Return data directly in the data field
    json_success($admins, "Available admins retrieved");

} catch (Exception $e) {
    logActivity("Error in get_available_admins: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>