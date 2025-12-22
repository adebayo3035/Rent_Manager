<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

try {
    // Log entry point
    logActivity("Super Admins endpoint accessed by session ID: " . ($_SESSION['unique_id'] ?? 'Unknown'));

    if (!isset($_SESSION['unique_id'])) {
        $logMessage = "Unauthorized access attempt to Super Admins endpoint: No session found. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        logActivity($logMessage);
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? 'Unknown';
    
    // Log authenticated access
    logActivity("Super Admins request initiated by Admin ID: {$adminId}, Role: {$adminRole}");

    if (!$conn) {
        $logMessage = "Database connection failed for Super Admins endpoint. Admin ID: {$adminId}";
        logActivity($logMessage);
        echo json_encode(["success" => false, "message" => "Database connection error."]);
        exit();
    }

    // Log the start of Super Admins query
    logActivity("Starting Super Admins database query for Admin ID: {$adminId}");

    // Fetch only active Super Admins with firstname + lastname
    $superAdminsQuery = "SELECT unique_id, firstname, lastname FROM admin_tbl 
                        WHERE role = 'Super Admin'  
                        ORDER BY firstname, lastname";
    
    $superAdminsStmt = $conn->prepare($superAdminsQuery);
    $superAdmins = [];
    
    if (!$superAdminsStmt) {
        $errorMsg = $conn->error;
        $logMessage = "Failed to prepare Super Admins query. Admin ID: {$adminId}, Error: {$errorMsg}";
        logActivity($logMessage);
        echo json_encode(["success" => false, "message" => "Database query preparation failed."]);
        exit();
    }

    $superAdminsStmt->execute();
    $superAdminsResult = $superAdminsStmt->get_result();
    
    $adminCount = 0;
    while ($admin = $superAdminsResult->fetch_assoc()) {
        $superAdmins[] = $admin;
        $adminCount++;
    }
    
    $superAdminsStmt->close();

    // Log query results
    logActivity("Super Admins query completed. Found {$adminCount} active Super Admins for Admin ID: {$adminId}");

    // Log detailed information using firstname + lastname
    if ($adminCount > 0) {
        $adminNames = array_map(function($admin) {
            $fullName = trim($admin['firstname'] . ' ' . $admin['lastname']);
            return $fullName . " (" . $admin['unique_id'] . ")";
        }, $superAdmins);
        
        $namesList = implode(", ", $adminNames);
        logActivity("Retrieved Super Admins for dropdown: {$namesList}. Requested by Admin ID: {$adminId}");
    } else {
        logActivity("No active Super Admins found in database. Requested by Admin ID: {$adminId}");
    }

    $conn->close();

    // Log successful response
    logActivity("Super Admins endpoint successfully returning {$adminCount} admins to Admin ID: {$adminId}");

    echo json_encode([
        "success" => true,
        "super_admins" => $superAdmins,
        "count" => $adminCount
    ]);

} catch (Exception $e) {
    $adminId = $_SESSION['unique_id'] ?? 'Unknown';
    $errorMessage = $e->getMessage();
    $stackTrace = $e->getTraceAsString();
    
    // Log detailed exception information
    $logMessage = "EXCEPTION in Super Admins endpoint - Admin ID: {$adminId}, Error: {$errorMessage}, Stack Trace: {$stackTrace}";
    logActivity($logMessage);

    if (isset($conn) && $conn->connect_errno == 0) {
        $conn->close();
    }

    echo json_encode([
        "success" => false, 
        "message" => "An unexpected error occurred while fetching Super Admins."
    ]);
    exit();
}