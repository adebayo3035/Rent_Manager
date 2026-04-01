<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

function sendErrorResponse($code, $message, $logMessage = '') {
    http_response_code($code);
    if ($logMessage) {
        logActivity($logMessage);
    }
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Check for user ID from headers (for internal API calls) or session (for direct calls)
$user_id = $_SERVER['HTTP_X_USER_ID'] ?? $_SESSION['unique_id'] ?? null;

if (!$user_id) {
    sendErrorResponse(401, 'User not authenticated.', "Restriction Check Failed: User not authenticated.");
}

// Get user role from headers or session
$user_role = $_SERVER['HTTP_X_USER_ROLE'] ?? $_SESSION['role'] ?? 'Unknown';

logActivity("🔐 Restriction check for User ID: $user_id, Role: $user_role");

// Retrieve the restriction_id for the user
$query = "SELECT restriction_id FROM admin_tbl WHERE unique_id = ?";
$stmt = $conn->prepare($query);

if (!$stmt) {
    sendErrorResponse(500, 'Database error.', "Restriction Check DB Error: " . $conn->error);
}

$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    sendErrorResponse(500, 'Database query failed.', "Restriction Check DB Execute Error: " . $stmt->error);
}

$stmt->bind_result($restriction_id);
$stmt->fetch();
$stmt->close();

if ($restriction_id == 1) {
    sendErrorResponse(403, 'There is a restriction on this staff account.', "Restriction Check: Blocked access for user ID $user_id due to restriction.");
}

logActivity("✅ Restriction Check Passed: User ID $user_id allowed access.");
// Continue with your application logic...