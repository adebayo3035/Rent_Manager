<?php
// ../utilities/notifications.php

/**
 * Create a notification for a user
 * 
 * @param mysqli $conn Database connection
 * @param array $data Notification data
 * @return int Notification ID
 * @throws Exception If creation fails
 */
function createNotification(mysqli $conn, array $data)
{
    // Validate required fields
    $required = ['user_id', 'title', 'message'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Extract and sanitize values
    $user_id = $data['user_id'];
    $title = trim($data['title']);
    $message = trim($data['message']);
    $type = isset($data['type']) ? trim($data['type']) : 'INFO';
    $category = isset($data['category']) ? trim($data['category']) : 'GENERAL';

    $assigned_to = getRandomSuperAdmin($conn);



    // Validate type
    $validTypes = ['INFO', 'SUCCESS', 'WARNING', 'SYSTEM', 'DANGER'];
    if (!in_array(strtoupper($type), $validTypes)) {
        $type = 'INFO';
    }

    // Prepare SQL with the new assigned_to column
    $sql = "
        INSERT INTO notifications 
        (user_id, assigned_to, title, message, type, category, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare notification insert: " . $conn->error);
    }

    $stmt->bind_param(
        "iissss",
        $user_id,      // user_id (who created/triggered the notification)
        $assigned_to,  // assigned_to (who should see/act on it)
        $title,
        $message,
        $type,
        $category
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Failed to execute notification insert: " . $error);
    }

    $insertId = $conn->insert_id;
    $stmt->close();

    if ($insertId <= 0) {
        throw new Exception("Notification insert failed, no ID returned");
    }

    return $insertId;
}

/**
 * Get a random active Super Admin from admin_tbl
 * 
 * @param mysqli $conn Database connection
 * @return int|null Admin ID or null if none found
 */
function getRandomSuperAdmin(mysqli $conn)
{
    // First, try to get Super Admin
    $query = "SELECT unique_id FROM admin_tbl 
              WHERE role = 'Super Admin' AND status = '1' 
              ORDER BY RAND() LIMIT 1";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (int) $row['unique_id'];
    }

    // If no Super Admin found, try any active admin
    $query = "SELECT unique_id FROM admin_tbl 
              WHERE status = '1' 
              ORDER BY RAND() LIMIT 1";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (int) $row['unique_id'];
    }

    // If no admin found at all, return null
    return null;
}

function generateUniqueRequestId($prefix = 'REQ_')
{
    // Combine timestamp, random number, and microtime for uniqueness
    $uniqueId = $prefix . time() . '_' . mt_rand(1000, 9999) . '_' . substr(md5(microtime()), 0, 6);
    return $uniqueId;
}
