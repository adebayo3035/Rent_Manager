<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();
if (!isset($_SESSION['unique_id'])) {
    logActivity("Unauthorized access attempt to fetch admin data");
    header("Location: index.php"); // Redirect to login page if not logged in
    exit();
}

if (isset($_SESSION['unique_id'])) {
    $userId = $_SESSION['unique_id'];
    $sql = mysqli_query($conn, "SELECT * FROM admin_tbl WHERE unique_id = $userId");
    if (mysqli_num_rows($sql) > 0) {
        $row = mysqli_fetch_assoc($sql);
        logActivity("Admin data fetched successfully for user ID: $userId");
        echo json_encode($row); // Return user info as JSON
    } else {
        logActivity("No admin data found for user ID: $userId");
        echo json_encode(null); // Return null if no user found
    }

    
} else {
    echo json_encode(null); // Return null if no user id set
}
