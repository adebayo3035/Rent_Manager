<?php
// mark_all_read.php - Mark all notifications as read

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    
    $update_query = "UPDATE tenant_notifications SET is_read = 1, read_at = NOW() WHERE tenant_code = ? AND is_read = 0";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("s", $tenant_code);
    $update_stmt->execute();
    $affected = $update_stmt->affected_rows;
    $update_stmt->close();
    
    json_success(['marked_count' => $affected], "All notifications marked as read");

} catch (Exception $e) {
    logActivity("Error in mark_all_read: " . $e->getMessage());
    json_error("Failed to mark notifications as read", 500);
}
?>