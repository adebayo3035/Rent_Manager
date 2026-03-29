<?php
// heartbeat.php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

try {
    if (!isset($_SESSION['unique_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit();
    }
    
    // Update last activity timestamp in session
    $_SESSION['last_activity'] = time();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}