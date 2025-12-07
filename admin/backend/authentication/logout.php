<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

try {
    logActivity("Logout process initiated");

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid request method attempted: " . $_SERVER['REQUEST_METHOD']);
        throw new Exception('Invalid request method.', 405);
    }

    // Get and validate input
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['logout_id'])) {
        logActivity("Missing logout_id in request payload");
        throw new Exception('Logout ID missing.', 400);
    }

    $logout_id = mysqli_real_escape_string($conn, $data['logout_id']);
    logActivity("Processing logout for user: $logout_id");

    // Step 1: Retrieve session data
    $stmt = $conn->prepare("SELECT session_id, status FROM admin_active_sessions WHERE unique_id = ?");
    if (!$stmt) {
        logActivity("DB Prepare failed: " . $conn->error);
        throw new Exception('Database error', 500);
    }
    
    $stmt->bind_param("s", $logout_id);
    if (!$stmt->execute()) {
        logActivity("DB Query failed: " . $stmt->error);
        throw new Exception('Database error', 500);
    }

    $result = $stmt->get_result();
    $userSession = $result->fetch_assoc();
    $stmt->close();

    if (!$userSession) {
        logActivity("No active session found for user: $logout_id");
        throw new Exception('No active session found for this user.', 404);
    }

    $session_id = $userSession['session_id'];
    logActivity("Found active session: $session_id (Status: {$userSession['status']})");

    // Step 2: Invalidate session in DB
    $stmt = $conn->prepare("UPDATE admin_active_sessions SET status = 'Inactive' WHERE session_id = ?");
    if (!$stmt) {
        logActivity("DB Prepare failed: " . $conn->error);
        throw new Exception('Database error', 500);
    }
    
    $stmt->bind_param("s", $session_id);
    if (!$stmt->execute()) {
        logActivity("DB Update failed: " . $stmt->error);
        throw new Exception('Database error', 500);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    logActivity("Session invalidated in DB. Affected rows: $affectedRows");

    // Step 3: Destroy session
    session_write_close();
    session_id($session_id);
    session_start();
    
    $sessionDataBefore = $_SESSION ?? [];
    session_destroy();
    logActivity("Session destroyed. Previous session data: " . json_encode($sessionDataBefore));

    // Success response
    logActivity("Logout successful for user: $logout_id");
    echo json_encode([
        'success' => true,
        'message' => "User successfully logged out",
        'logout_time' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    logActivity("ERROR {$e->getCode()}: {$e->getMessage()}");
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
} finally {
    if (isset($conn)) $conn->close();
}