<?php
header('Content-Type: application/json');
require_once __DIR__ . '../utilities/config.php';
require_once __DIR__ . '../utilities/auth_utils.php';
require_once __DIR__ . '../utilities/utils.php';
session_start();

logActivity("Properties listing fetch process started");

try {

    // -----------------------------
    // AUTHENTICATION CHECK
    // -----------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Access denied - not logged in");
        json_error('Not logged in', 401);
    }

    $userId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'Unknown';

    logActivity("Fetch initiated by user ID: $userId (Role: $userRole)");

    // -----------------------------
    // FETCH PROPERTIES
    // -----------------------------
    $query = "SELECT id, property_code, name 
              FROM properties 
              WHERE status = 'Active'
              ORDER BY id ASC";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        logActivity("Prepare failed: " . $conn->error);
        json_error("Failed to prepare database query", 500);
    }

    if (!$stmt->execute()) {
        logActivity("Execute failed: " . $stmt->error);
        json_error("Failed to fetch properties", 500);
    }

    $result = $stmt->get_result();
    $properties = [];
    
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }

    logActivity("Fetched " . count($properties) . " properties successfully");

    // -----------------------------
    // SUCCESS RESPONSE
    // -----------------------------
    json_success("Properties retrieved successfully", [
        "properties" => $properties,
        "count" => count($properties),
        "requested_by" => $userId,
        "user_role" => $userRole,
        "timestamp" => date('c')
    ]);

} catch (Exception $e) {
    logActivity("Fatal error: " . $e->getMessage());
    json_error("Unexpected server error occurred", 500);
}

