<?php
require_once __DIR__ . '/utilities/config.php';

$client_code = 'CLIENTD8E1'; // replace with the actual client/tenant code

// Hash the password (using client_code as base as per your logic)
$newPassword = 'CLIENTD8E1';
$encrypt_pass = password_hash($newPassword, PASSWORD_ARGON2ID);

// Prepare update query
$stmt = $conn->prepare("
    UPDATE clients 
    SET password = ? 
    WHERE client_code = ?
");

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

// Bind and execute
$stmt->bind_param("ss", $encrypt_pass, $client_code);

if ($stmt->execute()) {
    echo "Password updated successfully for Client: " . $client_code;
} else {
    echo "Error updating password: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>