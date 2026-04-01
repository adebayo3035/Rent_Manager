<?php
session_start();
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
// === Start Processing ===
logActivity("Fetching admin notifications started.");

try {
    $adminId = $_SESSION['unique_id'] ?? null;

    if (!$adminId) {
        logActivity("Unauthorized access attempt. No admin ID found in session.");
        echo json_encode(['success' => false, 'message' => 'User Not Logged In.']);
        exit;
    }

    // Validate and set pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 5; // Max 100 to prevent abuse
    $offset = ($page - 1) * $limit;

    // Fetch the role of the admin
    $stmtRole = $conn->prepare("SELECT role FROM admin_tbl WHERE unique_id = ?");
    if (!$stmtRole) {
        throw new Exception("Prepare failed for role fetch: " . $conn->error);
    }
    $stmtRole->bind_param("i", $adminId);
    $stmtRole->execute();
    $resultRole = $stmtRole->get_result();
    $userRole = $resultRole->fetch_assoc()['role'] ?? null;
    $stmtRole->close();

    if (!$userRole) {
        logActivity("Role not found for admin ID: $adminId");
        throw new Exception("User role not found.");
    }
    logActivity("User role detected: $userRole");

    // Prepare notification query
    if ($userRole === 'Super Admin') {
        $sql = "SELECT * FROM admin_notifications WHERE super_admin_read IS NULL AND is_read = 'No' ORDER BY created_at DESC LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $offset, $limit);
    } else {
        $sql = "SELECT * FROM admin_notifications WHERE is_read = 'No' AND user_id = ? ORDER BY created_at DESC LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $adminId, $offset, $limit);
    }
    
    if (!$stmt) {
        throw new Exception("Prepare failed for notification fetch: " . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    logActivity("Fetched " . count($notifications) . " notifications for page $page.");

    // Prepare notification count query
    if ($userRole === 'Super Admin') {
        $sqlCount = "SELECT COUNT(*) AS total_notifications FROM admin_notifications WHERE is_read = 'No' AND super_admin_read IS NULL";
        $stmtCount = $conn->prepare($sqlCount);
    } else {
        $sqlCount = "SELECT COUNT(*) AS total_notifications FROM admin_notifications WHERE is_read = 'No' AND user_id = ?";
        $stmtCount = $conn->prepare($sqlCount);
        $stmtCount->bind_param("i", $adminId);
    }

    if (!$stmtCount) {
        throw new Exception("Prepare failed for notification count: " . $conn->error);
    }

    $stmtCount->execute();
    $resultCount = $stmtCount->get_result();
    $totalNotifications = $resultCount->fetch_assoc()['total_notifications'] ?? 0;
    $stmtCount->close();

    $totalPages = ceil($totalNotifications / $limit);

    logActivity("Total unread notifications: $totalNotifications. Total pages: $totalPages.");

    // Respond with notifications and pagination info
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'totalNotifications' => (int)$totalNotifications,
        'totalPages' => (int)$totalPages,
        'currentPage' => (int)$page,
        'role' => $userRole
    ]);

} catch (Exception $e) {
    logActivity("Error occurred: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred fetching notifications.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("Fetching admin notifications process ended.");
}
