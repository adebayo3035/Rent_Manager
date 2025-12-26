<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

try {
    logActivity("Logout request received");

    /* ---------------------------
     * 1. Validate request method
     * --------------------------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid request method: {$_SERVER['REQUEST_METHOD']}");
        throw new Exception('Invalid request method', 405);
    }

    /* ---------------------------
     * 2. Validate active session
     * --------------------------- */
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Logout attempted without active session");
        throw new Exception('No active session', 401);
    }

    // $sessionUserId = $_SESSION['unique_id'];

    /* ---------------------------
     * 3. Read & validate payload
     * --------------------------- */
    $payload = json_decode(file_get_contents('php://input'), true);

    if (!isset($payload['logout_id'])) {
        logActivity("logout_id missing in payload");
        throw new Exception('Logout ID missing', 400);
    }

    $payloadLogoutId = (string) $payload['logout_id'];
    $sessionUserId = (string) $_SESSION['unique_id'];

    if ($payloadLogoutId !== $sessionUserId) {
        logActivity("Unauthorized logout attempt. Payload ID: {$payloadLogoutId}, Session ID: {$sessionUserId}");
        throw new Exception('Unauthorized logout attempt', 403);
    }

    logActivity("Processing logout for user {$sessionUserId}");

    /* ---------------------------
     * 4. Invalidate DB session
     * --------------------------- */
    $stmt = $conn->prepare("
        UPDATE admin_active_sessions 
        SET status = 'Inactive', logged_out_at = NOW() 
        WHERE unique_id = ? AND status = 'Active'
    ");

    if (!$stmt) {
        logActivity("DB prepare failed: {$conn->error}");
        throw new Exception('Database error', 500);
    }

    $stmt->bind_param("s", $sessionUserId);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        logActivity("No active DB session found for {$sessionUserId}");
    } else {
        logActivity("DB session invalidated for {$sessionUserId}");
    }

    $stmt->close();

    /* ---------------------------
     * 5. Destroy PHP session
     * --------------------------- */
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    // session_regenerate_id(true);

    logActivity("PHP session destroyed for {$sessionUserId}");

    /* ---------------------------
     * 6. Success response
     * --------------------------- */
    echo json_encode([
        'success' => true,
        'message' => 'Logout successful',
        'logout_time' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);

    logActivity("LOGOUT ERROR ({$e->getCode()}): {$e->getMessage()}");

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
