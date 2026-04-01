<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check if user is logged in
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }
    
    $tenant_code = $_SESSION['tenant_code'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $new_password = $input['new_password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';
    
    // Validate
    if (empty($new_password) || empty($confirm_password)) {
        json_error("Password fields are required", 400);
    }
    
    if ($new_password !== $confirm_password) {
        json_error("Passwords do not match", 400);
    }
    
    if (strlen($new_password) < 8) {
        json_error("Password must be at least 8 characters", 400);
    }
    
    if (!preg_match('/[A-Z]/', $new_password)) {
        json_error("Password must contain at least one uppercase letter", 400);
    }
    
    if (!preg_match('/[a-z]/', $new_password)) {
        json_error("Password must contain at least one lowercase letter", 400);
    }
    
    if (!preg_match('/[0-9]/', $new_password)) {
        json_error("Password must contain at least one number", 400);
    }
    
    // Hash new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update password and set password_changed flag
    $query = "UPDATE tenants SET password = ?, password_changed = 1, last_updated_at = NOW() WHERE tenant_code = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $hashed_password, $tenant_code);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update password: " . $stmt->error);
    }
    
    $stmt->close();
    
    logActivity("First-time password changed for tenant: $tenant_code");
    
    // Clear session and force re-login
    session_destroy();
    
    json_success(null, "Password changed successfully. Please log in again.");
    
} catch (Exception $e) {
    logActivity("Error changing default password: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>