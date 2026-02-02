<?php
// backend/notifications_api.php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

// Start logging
logActivity("[NOTIFICATIONS_API_START] Request method: " . $_SERVER['REQUEST_METHOD']);

// Check authentication
if (!isset($_SESSION['unique_id'])) {
    logActivity("[NOTIFICATIONS_API_ERROR] No session found - User not authenticated");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login first']);
    exit;
}

$user_id = $_SESSION['unique_id'];
$method = $_SERVER['REQUEST_METHOD'];

logActivity("[NOTIFICATIONS_API] User ID: {$user_id}, Method: {$method}");

// FIRST: Let's debug by checking what's in the database directly
logActivity("[NOTIFICATIONS_API_DEBUG] === CHECKING DATABASE ===");
$debugQuery = "SELECT 
                id, 
                user_id, 
                assigned_to, 
                title, 
                type, 
                category, 
                is_read, 
                is_archived,
                created_at
              FROM notifications 
              ORDER BY id DESC 
              LIMIT 5";
$debugResult = $conn->query($debugQuery);
if ($debugResult) {
    $allRows = [];
    while ($row = $debugResult->fetch_assoc()) {
        $allRows[] = $row;
    }
    logActivity("[NOTIFICATIONS_API_DEBUG] First 5 notifications: " . json_encode($allRows));
}

// Check for user ID 3 specifically
$checkUserQuery = "SELECT COUNT(*) as count FROM notifications WHERE assigned_to = ? OR user_id = ?";
$checkStmt = $conn->prepare($checkUserQuery);
$checkStmt->bind_param("ii", $user_id, $user_id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$checkRow = $checkResult->fetch_assoc();
logActivity("[NOTIFICATIONS_API_DEBUG] Notifications for user {$user_id} (assigned_to OR user_id): " . ($checkRow['count'] ?? 0));

// Check specifically for assigned_to = 3
$checkAssignedQuery = "SELECT COUNT(*) as count FROM notifications WHERE assigned_to = ?";
$checkAssignedStmt = $conn->prepare($checkAssignedQuery);
$checkAssignedStmt->bind_param("i", $user_id);
$checkAssignedStmt->execute();
$checkAssignedResult = $checkAssignedStmt->get_result();
$checkAssignedRow = $checkAssignedResult->fetch_assoc();
logActivity("[NOTIFICATIONS_API_DEBUG] Notifications assigned_to {$user_id}: " . ($checkAssignedRow['count'] ?? 0));

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user_id);
            break;
        case 'POST':
            handlePost($conn, $user_id);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    logActivity("[NOTIFICATIONS_API_EXCEPTION] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

function handleGet($conn, $user_id) {
    logActivity("[NOTIFICATIONS_API_GET] Starting for user: {$user_id}");
    
    // Get query parameters
    $type = $_GET['type'] ?? 'all';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 10;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    
    logActivity("[NOTIFICATIONS_API_GET] Parameters - type: {$type}, limit: {$limit}, offset: {$offset}");
    
    // IMPORTANT: is_archived is ENUM('0', '1') - we need to compare with strings!
    // Build query
    $query = "SELECT 
                id,
                user_id,
                assigned_to,
                title,
                message,
                type,
                category,
                is_read,
                is_archived,
                created_at,
                read_at
              FROM notifications
              WHERE (assigned_to = ? OR user_id = ?)";
    
    $params = [$user_id, $user_id];
    $paramTypes = "ii";
    
    // Apply filters - NOTE: is_archived is ENUM so compare with strings '0' or '1'
    if ($type === 'unread') {
        $query .= " AND is_read = 0 AND is_archived = '0'";
        logActivity("[NOTIFICATIONS_API_GET] Filter: unread (not read, not archived)");
    } elseif ($type === 'archived') {
        $query .= " AND is_archived = '1'";
        logActivity("[NOTIFICATIONS_API_GET] Filter: archived");
    } elseif ($type !== 'all') {
        // Category filter
        $query .= " AND category = ? AND is_archived = '0'";
        $params[] = $type;
        $paramTypes .= "s";
        logActivity("[NOTIFICATIONS_API_GET] Filter by category: {$type}");
    } else {
        // 'all' means show all non-archived notifications
        $query .= " AND is_archived = '0'";
        logActivity("[NOTIFICATIONS_API_GET] Filter: all (non-archived)");
    }
    
    logActivity("[NOTIFICATIONS_API_GET] Query before pagination: " . $query);
    logActivity("[NOTIFICATIONS_API_GET] Params: " . json_encode($params));
    
    // Get total count
    $totalCount = getTotalCount($conn, $user_id, $type);
    logActivity("[NOTIFICATIONS_API_GET] Total count: {$totalCount}");
    
    // Add ordering and pagination
    $query .= " ORDER BY 
                CASE WHEN is_read = 0 THEN 0 ELSE 1 END,
                created_at DESC 
              LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= "ii";
    
    logActivity("[NOTIFICATIONS_API_GET] Final query: " . $query);
    logActivity("[NOTIFICATIONS_API_GET] Final params: " . json_encode($params));
    logActivity("[NOTIFICATIONS_API_GET] Param types: {$paramTypes}");
    
    // Execute the query
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $error = $conn->error;
        logActivity("[NOTIFICATIONS_API_GET_ERROR] Failed to prepare statement: " . $error);
        throw new Exception("Database error: " . $error);
    }
    
    // Debug: Check if binding works
    logActivity("[NOTIFICATIONS_API_GET] Attempting to bind " . count($params) . " parameters");
    
    $bound = $stmt->bind_param($paramTypes, ...$params);
    if (!$bound) {
        logActivity("[NOTIFICATIONS_API_GET_ERROR] Failed to bind parameters");
        throw new Exception("Failed to bind parameters");
    }
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        logActivity("[NOTIFICATIONS_API_GET_ERROR] Failed to execute: " . $error);
        throw new Exception("Database error: " . $error);
    }
    
    $result = $stmt->get_result();
    $notificationCount = $result->num_rows;
    logActivity("[NOTIFICATIONS_API_GET] Retrieved {$notificationCount} notifications");
    
    // Process results
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        logActivity("[NOTIFICATIONS_API_GET] Processing row: " . json_encode($row));
        // Add formatted fields
        $row['time_ago'] = getTimeAgo($row['created_at']);
        $row['icon'] = getNotificationIcon($row['type'], $row['category']);
        $row['link'] = getNotificationLink($row['category'], $row['id']);
        
        $notifications[] = $row;
    }
    
    $stmt->close();
    
    // Get counts for the UI
    $counts = getNotificationCounts($conn, $user_id);
    
    // Prepare and send response
    $response = [
        'success' => true,
        'notifications' => $notifications,
        'total' => $totalCount,
        'counts' => $counts,
        'debug' => [ // Temporary debug info
            'user_id' => $user_id,
            'query_type' => $type,
            'rows_found' => $notificationCount
        ]
    ];
    
    logActivity("[NOTIFICATIONS_API_GET] Sending response with " . count($notifications) . " notifications");
    echo json_encode($response);
}

function getTotalCount($conn, $user_id, $type) {
    $query = "SELECT COUNT(*) as total 
              FROM notifications 
              WHERE (assigned_to = ? OR user_id = ?)";
    
    if ($type === 'unread') {
        $query .= " AND is_read = 0 AND is_archived = '0'";
    } elseif ($type === 'archived') {
        $query .= " AND is_archived = '1'";
    } elseif ($type !== 'all') {
        $query .= " AND category = ? AND is_archived = '0'";
    } else {
        $query .= " AND is_archived = '0'";
    }
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[NOTIFICATIONS_API_COUNT_ERROR] Failed to prepare count statement: " . $conn->error);
        return 0;
    }
    
    if ($type !== 'all' && $type !== 'unread' && $type !== 'archived') {
        logActivity("[NOTIFICATIONS_API_COUNT] Binding: user_id={$user_id}, user_id={$user_id}, category={$type}");
        $stmt->bind_param("iis", $user_id, $user_id, $type);
    } else {
        logActivity("[NOTIFICATIONS_API_COUNT] Binding: user_id={$user_id}, user_id={$user_id}");
        $stmt->bind_param("ii", $user_id, $user_id);
    }
    
    if (!$stmt->execute()) {
        logActivity("[NOTIFICATIONS_API_COUNT_ERROR] Failed to execute count query: " . $stmt->error);
        $stmt->close();
        return 0;
    }
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['total'] ?? 0;
}

function getNotificationCounts($conn, $user_id) {
    $query = "SELECT 
                COUNT(CASE WHEN is_read = 0 AND is_archived = '0' THEN 1 END) as unread,
                COUNT(CASE WHEN category = 'account_reactivation' AND is_read = 0 AND is_archived = '0' THEN 1 END) as reactivation_pending,
                COUNT(CASE WHEN category = 'payment' AND is_read = 0 AND is_archived = '0' THEN 1 END) as payment_pending,
                COUNT(CASE WHEN category = 'account_lock' AND is_read = 0 AND is_archived = '0' THEN 1 END) as lock_alerts,
                COUNT(CASE WHEN is_archived = '0' THEN 1 END) as total_unarchived
              FROM notifications 
              WHERE (assigned_to = ? OR user_id = ?)";
    
    logActivity("[NOTIFICATIONS_API_COUNTS] Query: " . $query);
    logActivity("[NOTIFICATIONS_API_COUNTS] User ID: {$user_id}");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[NOTIFICATIONS_API_COUNTS_ERROR] Failed to prepare counts statement: " . $conn->error);
        return getDefaultCounts();
    }
    
    $stmt->bind_param("ii", $user_id, $user_id);
    
    if (!$stmt->execute()) {
        logActivity("[NOTIFICATIONS_API_COUNTS_ERROR] Failed to execute counts query: " . $stmt->error);
        $stmt->close();
        return getDefaultCounts();
    }
    
    $result = $stmt->get_result();
    $counts = $result->fetch_assoc() ?: [];
    $stmt->close();
    
    logActivity("[NOTIFICATIONS_API_COUNTS] Raw counts from DB: " . json_encode($counts));
    
    return array_merge(getDefaultCounts(), $counts);
}

function getDefaultCounts() {
    return [
        'unread' => 0,
        'reactivation_pending' => 0,
        'payment_pending' => 0,
        'lock_alerts' => 0,
        'total_unarchived' => 0
    ];
}

function handlePost($conn, $user_id) {
    logActivity("[NOTIFICATIONS_API_POST] Starting for user: {$user_id}");
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity("[NOTIFICATIONS_API_POST_ERROR] JSON decode error");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        return;
    }
    
    if (!isset($data['action'])) {
        logActivity("[NOTIFICATIONS_API_POST_ERROR] No action specified");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action is required']);
        return;
    }
    
    $action = $data['action'];
    logActivity("[NOTIFICATIONS_API_POST] Action: {$action}");
    
    switch ($action) {
        case 'mark_as_read':
            if (!isset($data['notification_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Notification ID is required']);
                return;
            }
            markAsRead($conn, $user_id, $data['notification_id']);
            break;
            
        case 'mark_all_read':
            markAllAsRead($conn, $user_id);
            break;
            
        case 'archive':
            if (!isset($data['notification_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Notification ID is required']);
                return;
            }
            archiveNotification($conn, $user_id, $data['notification_id']);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function markAsRead($conn, $user_id, $notification_id) {
    logActivity("[NOTIFICATIONS_API_MARK_READ] User {$user_id}, Notification {$notification_id}");
    
    // Note: is_read is tinyint(1) so we can use integer
    $query = "UPDATE notifications 
              SET is_read = 1, read_at = NOW() 
              WHERE id = ? AND (assigned_to = ? OR user_id = ?)";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[NOTIFICATIONS_API_MARK_READ_ERROR] Prepare failed");
        echo json_encode(['success' => false, 'message' => 'Database error']);
        return;
    }
    
    $stmt->bind_param("iii", $notification_id, $user_id, $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    $response = [
        'success' => $affected > 0,
        'message' => $affected > 0 ? 'Notification marked as read' : 'Notification not found'
    ];
    
    logActivity("[NOTIFICATIONS_API_MARK_READ] Result: " . json_encode($response));
    echo json_encode($response);
}

function markAllAsRead($conn, $user_id) {
    logActivity("[NOTIFICATIONS_API_MARK_ALL_READ] User {$user_id}");
    
    $query = "UPDATE notifications 
              SET is_read = 1, read_at = NOW() 
              WHERE (assigned_to = ? OR user_id = ?) 
              AND is_read = 0 AND is_archived = '0'";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[NOTIFICATIONS_API_MARK_ALL_READ_ERROR] Prepare failed");
        echo json_encode(['success' => false, 'message' => 'Database error']);
        return;
    }
    
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    $response = [
        'success' => true,
        'message' => "Marked {$affected} notifications as read"
    ];
    
    logActivity("[NOTIFICATIONS_API_MARK_ALL_READ] Result: {$affected} notifications marked");
    echo json_encode($response);
}

function archiveNotification($conn, $user_id, $notification_id) {
    logActivity("[NOTIFICATIONS_API_ARCHIVE] User {$user_id}, Notification {$notification_id}");
    
    // Note: is_archived is ENUM('0', '1') so we need to set string '1'
    $query = "UPDATE notifications 
              SET is_archived = '1' 
              WHERE id = ? AND (assigned_to = ? OR user_id = ?)";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[NOTIFICATIONS_API_ARCHIVE_ERROR] Prepare failed");
        echo json_encode(['success' => false, 'message' => 'Database error']);
        return;
    }
    
    $stmt->bind_param("iii", $notification_id, $user_id, $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    $response = [
        'success' => $affected > 0,
        'message' => $affected > 0 ? 'Notification archived' : 'Notification not found'
    ];
    
    logActivity("[NOTIFICATIONS_API_ARCHIVE] Result: " . json_encode($response));
    echo json_encode($response);
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " min ago";
    if ($diff < 86400) return floor($diff / 3600) . " hour ago";
    if ($diff < 604800) return floor($diff / 86400) . " day ago";
    return floor($diff / 604800) . " week ago";
}

function getNotificationIcon($type, $category) {
    // Note: type is lowercase in DB (e.g., 'info' not 'INFO')
    $type = strtolower($type);
    
    $icons = [
        'info' => 'ℹ️',
        'success' => '✅',
        'warning' => '⚠️',
        'danger' => '🚨',
        'system' => '⚙️'
    ];
    
    $categoryIcons = [
        'account_reactivation' => '🔓',
        'account_lock' => '🔒',
        'payment' => '💰',
        'system_alert' => '🚨',
        'other' => '📌'
    ];
    
    return $categoryIcons[$category] ?? $icons[$type] ?? '📌';
}

function getNotificationLink($category, $notification_id) {
    $links = [
        'account_reactivation' => 'account_management.php',
        'account_lock' => 'settings.php',
        'payment' => 'payments.php',
        'system_alert' => 'dashboard.php',
        'other' => 'notifications.php'
    ];
    
    return $links[$category] ?? '#';
}