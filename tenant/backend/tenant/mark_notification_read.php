<?php
// mark_notification_read.php - Mark notification as read

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
    $input = json_decode(file_get_contents('php://input'), true);
    $notification_id = isset($input['notification_id']) ? (int)$input['notification_id'] : 0;
    
    if ($notification_id <= 0) {
        json_error("Invalid notification ID", 400);
    }
    
    // Verify ownership
    $check_query = "SELECT tenant_code FROM tenant_notifications WHERE notification_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $notification_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $notification = $result->fetch_assoc();
    $check_stmt->close();
    
    if (!$notification || $notification['tenant_code'] !== $tenant_code) {
        json_error("Notification not found", 404);
    }
    
    // Mark as read
    $update_query = "UPDATE tenant_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("i", $notification_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    json_success(null, "Notification marked as read");

} catch (Exception $e) {
    logActivity("Error in mark_notification_read: " . $e->getMessage());
    json_error("Failed to mark notification as read", 500);
}
?>