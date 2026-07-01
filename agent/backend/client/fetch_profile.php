<?php
// client/backend/client/fetch_profile.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    $client_code = $_SESSION['client_code'];
    $passwordFlagColumn = null;
    $columnCheck = $conn->query("SHOW COLUMNS FROM clients LIKE 'password_changed'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        $passwordFlagColumn = 'password_changed';
    } else {
        $fallbackCheck = $conn->query("SHOW COLUMNS FROM clients LIKE 'password_change'");
        if ($fallbackCheck && $fallbackCheck->num_rows > 0) {
            $passwordFlagColumn = 'password_change';
        }
    }

    $passwordFlagSelect = $passwordFlagColumn ? ", {$passwordFlagColumn} AS password_changed" : ", 1 AS password_changed";

    $query = "
        SELECT 
            client_code,
            firstname,
            lastname,
            email,
            phone,
            address,
            photo,
            gender,
            status,
            bank_name,
            bank_account_name,
            bank_account_number,
            date_created
            {$passwordFlagSelect}
        FROM clients 
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Client not found", 404);
    }

    $client = $result->fetch_assoc();
    $stmt->close();

    json_success($client, "Profile retrieved successfully");

} catch (Exception $e) {
    logActivity("Error fetching client profile: " . $e->getMessage());
    json_error("Failed to fetch profile", 500);
}
?>
