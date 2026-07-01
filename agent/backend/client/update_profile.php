<?php
// client/backend/client/update_profile.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['client_logged_in'], $_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error("Method not allowed", 405);
    }

    $client_code = $_SESSION['client_code'];
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        json_error("Invalid input data", 400);
    }

    $firstname = trim($input['firstname'] ?? '');
    $lastname = trim($input['lastname'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $gender = trim($input['gender'] ?? '');
    $address = trim($input['address'] ?? '');

    if ($firstname === '' || $lastname === '' || $email === '' || $phone === '') {
        json_error("First name, last name, email, and phone are required", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error("Invalid email format", 400);
    }

    if (function_exists('validate_phone') && !validate_phone($phone)) {
        json_error("Invalid phone number format", 400);
    }

    if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
        json_error("Invalid gender value", 400);
    }

    $checkEmailStmt = $conn->prepare("
        SELECT client_code FROM clients
        WHERE email = ? AND client_code != ? AND status = 1
        LIMIT 1
    ");
    $checkEmailStmt->bind_param("ss", $email, $client_code);
    $checkEmailStmt->execute();
    $emailResult = $checkEmailStmt->get_result();

    if ($emailResult->num_rows > 0) {
        $checkEmailStmt->close();
        json_error("Email address is already used by another client", 400);
    }
    $checkEmailStmt->close();

    $checkPhoneStmt = $conn->prepare("
        SELECT client_code FROM clients
        WHERE phone = ? AND client_code != ? AND status = 1
        LIMIT 1
    ");
    $checkPhoneStmt->bind_param("ss", $phone, $client_code);
    $checkPhoneStmt->execute();
    $phoneResult = $checkPhoneStmt->get_result();

    if ($phoneResult->num_rows > 0) {
        $checkPhoneStmt->close();
        json_error("Phone number is already used by another client", 400);
    }
    $checkPhoneStmt->close();

    $updateStmt = $conn->prepare("
        UPDATE clients
        SET firstname = ?,
            lastname = ?,
            email = ?,
            phone = ?,
            gender = ?,
            address = ?,
            date_updated = NOW()
        WHERE client_code = ? AND status = 1
    ");
    $updateStmt->bind_param("sssssss", $firstname, $lastname, $email, $phone, $gender, $address, $client_code);

    if (!$updateStmt->execute()) {
        throw new Exception("Failed to update profile: " . $updateStmt->error);
    }
    $updateStmt->close();

    $fetchStmt = $conn->prepare("
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
        FROM clients
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ");
    $fetchStmt->bind_param("s", $client_code);
    $fetchStmt->execute();
    $userData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    json_success($userData, "Profile updated successfully");

} catch (Exception $e) {
    logActivity("Error updating client profile: " . $e->getMessage());
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
