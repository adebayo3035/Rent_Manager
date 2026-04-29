<?php
// fetch_notifications.php - Fetch tenant notifications

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

logActivity("========== FETCH NOTIFICATIONS - START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    
    // Get pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $offset = ($page - 1) * $limit;
    
    // Get filter parameters
    $type = isset($_GET['type']) ? $_GET['type'] : null;
    $is_read = isset($_GET['is_read']) ? ($_GET['is_read'] === 'true' ? 1 : 0) : null;
    
    // Build query
    $where_clauses = ["tenant_code = ?"];
    $params = [$tenant_code];
    $types = "s";
    
    if ($type) {
        $where_clauses[] = "notification_type = ?";
        $params[] = $type;
        $types .= "s";
    }
    
    if ($is_read !== null) {
        $where_clauses[] = "is_read = ?";
        $params[] = $is_read;
        $types .= "i";
    }
    
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM tenant_notifications $where_sql";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
    
    // Get notifications
    $query = "
        SELECT 
            notification_id,
            notification_type,
            title,
            message,
            details,
            is_read,
            priority,
            created_at,
            read_at,
            action_url,
            action_text
        FROM tenant_notifications
        $where_sql
        ORDER BY 
            CASE WHEN is_read = 0 THEN 0 ELSE 1 END,
            created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($query);
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $row['details'] = $row['details'] ? json_decode($row['details'], true) : null;
        $row['created_at_formatted'] = date('M d, Y g:i A', strtotime($row['created_at']));
        $row['time_ago'] = timeAgo($row['created_at']);
        $notifications[] = $row;
    }
    $stmt->close();
    
    // Get unread count
    $unread_query = "SELECT COUNT(*) as unread FROM tenant_notifications WHERE tenant_code = ? AND is_read = 0";
    $unread_stmt = $conn->prepare($unread_query);
    $unread_stmt->bind_param("s", $tenant_code);
    $unread_stmt->execute();
    $unread_count = $unread_stmt->get_result()->fetch_assoc()['unread'];
    $unread_stmt->close();
    
    json_success([
        'notifications' => $notifications,
        'unread_count' => $unread_count,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ], "Notifications retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_notifications: " . $e->getMessage());
    json_error("Failed to fetch notifications", 500);
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    if ($diff < 31536000) return floor($diff / 2592000) . ' months ago';
    return floor($diff / 31536000) . ' years ago';
}
?>