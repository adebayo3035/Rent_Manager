<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

try {
    logActivity("Staff update process initiated");

    // Get the JSON data from the request body
    $data = json_decode(file_get_contents("php://input"), true);
    logActivity("Received request data: " . json_encode($data));

    if (!isset($data['admin_id'])) {
        logActivity("Validation failed - Admin ID missing in request");
        echo json_encode(["success" => false, "message" => "Admin ID is missing."]);
        exit();
    }

    $adminId = $data['admin_id'];
    $email = trim($data['email']);
    $phone_number = trim($data['phone_number']);
    $secret_answer = trim($data['secret_answer']);
    $encrypted_answer = password_hash($secret_answer, PASSWORD_DEFAULT);

    logActivity("Processing update for Admin ID: $adminId");

    // Validate required fields
    $requiredFields = [
        'email' => $email,
        'phone_number' => $phone_number,
        'secret_answer' => $secret_answer
    ];

    foreach ($requiredFields as $field => $value) {
        if (empty($value)) {
            logActivity("Validation failed - Missing required field: $field");
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
            exit;
        }
    }

    // Validate session identity
    if ($adminId !== $_SESSION['unique_id']) {
        logActivity("Security violation - Admin ID mismatch (Session: {$_SESSION['unique_id']}, Request: $adminId)");
        echo json_encode(['success' => false, 'message' => 'Error Validating Staff Identity.']);
        exit();
    }

    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("Validation failed - Invalid email format: $email");
        echo json_encode(['success' => false, 'message' => 'Invalid E-mail address.']);
        exit();
    }

    // Phone number validation
    if (!preg_match('/^\d{11}$/', $phone_number)) {
        logActivity("Validation failed - Invalid phone format: $phone_number");
        echo json_encode(['success' => false, 'message' => 'Please input a valid Phone Number.']);
        exit();
    }

    // Check for duplicates
    logActivity("Checking for duplicate phone/email for Admin ID: $adminId");
    $checkQuery = "SELECT unique_id, phone, email FROM admin_tbl WHERE (phone = ? OR email = ?) AND unique_id != ?";
    $stmt = $conn->prepare($checkQuery);

    if (!$stmt) {
        logActivity("Database error - Failed to prepare duplicate check query");
        echo json_encode(["success" => false, "message" => "Database error occurred."]);
        exit();
    }

    $stmt->bind_param("ssi", $phone_number, $email, $adminId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($existingId, $existingPhone, $existingEmail);
        $stmt->fetch();

        if ($existingPhone === $phone_number) {
            logActivity("Duplicate detected - Phone number exists for Admin ID: $existingId");
            echo json_encode(['success' => false, 'message' => 'Phone number already exists.']);
            $stmt->close();
            exit();
        }

        if ($existingEmail === $email) {
            logActivity("Duplicate detected - Email exists for Admin ID: $existingId");
            echo json_encode(['success' => false, 'message' => 'Email address already exists.']);
            $stmt->close();
            exit();
        }
    }
    $stmt->close();

    // Validate secret answer
    logActivity("Validating secret answer for Admin ID: $adminId");
    $secretAnswerQuery = "SELECT secret_answer FROM admin_tbl WHERE unique_id = ?";
    $stmt = $conn->prepare($secretAnswerQuery);

    if (!$stmt) {
        logActivity("Database error - Failed to prepare secret answer validation query");
        echo json_encode(["success" => false, "message" => "Database error occurred."]);
        exit();
    }

    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($stored_secret_answer);

    if ($stmt->num_rows === 0) {
        logActivity("Validation failed - No admin record found for ID: $adminId");
        echo json_encode(['success' => false, 'message' => 'No secret answer found for the provided ID.']);
        $stmt->close();
        exit();
    }

    $stmt->fetch();
    // if ($stored_secret_answer !== $encrypted_answer) {
    //     logActivity("Validation failed - Incorrect secret answer for Admin ID: $adminId");
    //     echo json_encode(['success' => false, 'message' => 'Error Validating Secret Answer.']);
    //     $stmt->close();
    //     exit();
    // }
    if (!verifyAndUpgradeSecretAnswer($conn, $adminId, $secret_answer, $stored_secret_answer)) {
    logActivity("Validation failed - Incorrect secret answer for Admin ID: $adminId");
        echo json_encode(['success' => false, 'message' => 'Error Validating Secret Answer.']);
        $stmt->close();
        exit();
    }
    $stmt->close();

    // Prepare update
    logActivity("Preparing update for Admin ID: $adminId");
    $sql = "UPDATE admin_tbl SET email = ?, phone = ?, updated_at = NOW(), last_updated_by = ? WHERE unique_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        logActivity("Database error - Failed to prepare update query");
        echo json_encode(["success" => false, "message" => "Database error occurred."]);
        exit();
    }

    $stmt->bind_param("ssii", $email, $phone_number, $adminId, $adminId);
    logActivity("Executing update for Admin ID: $adminId");

    if ($stmt->execute()) {
        $affectedRows = $stmt->affected_rows;
        logActivity("Success - Updated Admin ID: $adminId. Affected rows: $affectedRows");
        echo json_encode([
            "success" => true,
            "message" => "Your Record has been Successfully Updated.",
            "affected_rows" => $affectedRows
        ]);
    } else {
        logActivity("Update failed - Error: " . $stmt->error);
        echo json_encode([
            "success" => false,
            "message" => "Failed to update Staff record.",
            "error" => $stmt->error
        ]);
    }

} catch (Exception $e) {
    logActivity("Exception occurred: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An unexpected error occurred."
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    $conn->close();
    logActivity("Staff update process completed");
}