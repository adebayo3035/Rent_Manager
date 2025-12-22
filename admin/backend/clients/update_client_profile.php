<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

// Utility to send response with code
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

// Validate session and permissions
if (!isset($_SESSION['unique_id'])) {
    logActivity("Unauthorized access attempt - No active session found");
    respond(["success" => false, "message" => "Not logged in."], 401);
}

$loggedInUserRole = $_SESSION['role'];
$logged_in_user = $_SESSION['unique_id'];
logActivity("Staff update initiated by User ID: $logged_in_user (Role: $loggedInUserRole)");

if ($loggedInUserRole !== "Super Admin") {
    logActivity("Access denied - User ID: $logged_in_user attempted update without Super Admin rights");
    respond(["success" => false, "message" => "Access Denied."], 403);
}

// Process request data
$data = json_decode(file_get_contents("php://input"), true);
logActivity("Incoming update request payload: " . json_encode($data));

if (!isset($data['staff_id'])) {
    logActivity("Missing field - Staff ID not provided");
    respond(["success" => false, "message" => "Staff ID is missing."], 400);
}

// Extract and validate input data
$adminId = $data['staff_id'];
$firstname = trim($data['firstname']);
$lastname = trim($data['lastname']);
$email = trim($data['email']);
$phone_number = trim($data['phone_number']);
$role = $data['role'];
$gender = $data['gender'];
$address = trim($data['address']);

logActivity("Validating fields for Staff ID: $adminId");

// Required field check
$requiredFields = compact('email', 'phone_number', 'firstname', 'lastname', 'role', 'gender', 'address');

foreach ($requiredFields as $field => $value) {
    if (empty($value)) {
        logActivity("Validation error - Missing $field for Staff ID: $adminId");
        respond(['success' => false, 'message' => "Missing required field: $field"], 422);
    }
}

// Prevent self-update
if ($adminId == $logged_in_user) {
    logActivity("Unauthorized modification attempt - User tried updating own account (ID: $logged_in_user)");
    respond(['success' => false, 'message' => 'You cannot update your own record.'], 403);
}

// Email format validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    logActivity("Validation error - Invalid email format: $email");
    respond(['success' => false, 'message' => 'Invalid email address.'], 400);
}

// Phone number validation
if (!preg_match('/^\d{11}$/', $phone_number)) {
    logActivity("Validation error - Invalid phone number: $phone_number");
    respond(['success' => false, 'message' => 'Invalid phone number format.'], 400);
}

// Check account restrictions
logActivity("Checking restrictions for Staff ID: $adminId");
$stmt = $conn->prepare("SELECT restriction_id, block_id, role, delete_status FROM admin_tbl WHERE unique_id = ?");
if (!$stmt) {
    logActivity("DB error - Restriction check failed for Staff ID: $adminId");
    respond(['success' => false, 'message' => 'Internal server error.'], 500);
}

$stmt->bind_param("i", $adminId);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($restriction, $block, $staff_role, $delete_status);
    $stmt->fetch();

    if ($restriction !== 0 || $block !== 0) {
        logActivity("Account restricted - Staff ID: $adminId");
        $stmt->close();
        respond(['success' => false, 'message' => 'Account is restricted. Remove restriction before update.'], 403);
    }

    if ($delete_status === 'Yes') {
        logActivity("Account deactivated - Staff ID: $adminId");
        $stmt->close();
        respond(['success' => false, 'message' => 'Account is deactivated. Reactivate to proceed.'], 403);
    }

    if ($staff_role === 'Super Admin' && $role !== 'Super Admin') {
        logActivity("Privilege violation - Attempted downgrade of Super Admin (ID: $adminId)");
        $stmt->close();
        respond(['success' => false, 'message' => 'Cannot downgrade a Super Admin account.'], 403);
    }
}
$stmt->close();

// Check duplicates
logActivity("Checking duplicates for phone/email for Staff ID: $adminId");
$stmt = $conn->prepare("SELECT phone, email FROM admin_tbl WHERE (phone = ? OR email = ?) AND unique_id != ?");
if (!$stmt) {
    logActivity("DB error - Duplicate check query failed for Staff ID: $adminId");
    respond(["success" => false, "message" => "Internal server error."], 500);
}

$stmt->bind_param("ssi", $phone_number, $email, $adminId);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($existingPhone, $existingEmail);
    $stmt->fetch();

    if ($existingPhone === $phone_number) {
        logActivity("Duplicate phone - $phone_number already exists (Staff ID: $adminId)");
        $stmt->close();
        respond(['success' => false, 'message' => 'Phone number already exists.'], 409);
    }

    if ($existingEmail === $email) {
        logActivity("Duplicate email - $email already exists (Staff ID: $adminId)");
        $stmt->close();
        respond(['success' => false, 'message' => 'Email already exists.'], 409);
    }
}
$stmt->close();

// Perform update
logActivity("Executing update for Staff ID: $adminId");
$stmt = $conn->prepare("UPDATE admin_tbl SET 
    firstname = ?, lastname = ?, email = ?, phone = ?, address = ?, gender = ?, role = ?, 
    updated_at = NOW(), last_updated_by = ? WHERE unique_id = ?");

if (!$stmt) {
    logActivity("DB error - Failed to prepare update for Staff ID: $adminId");
    respond(["success" => false, "message" => "Internal server error."], 500);
}

$stmt->bind_param("sssssssii", $firstname, $lastname, $email, $phone_number, $address, $gender, $role, $logged_in_user, $adminId);

if ($stmt->execute()) {
    $rows = $conn->affected_rows;
    logActivity("Update success - Staff ID: $adminId updated by User ID: $logged_in_user");
    respond([
        "success" => true,
        "message" => "Record successfully updated.",
        "affected_rows" => $rows
    ], 200);
} else {
    logActivity("Update failed - DB error: " . $stmt->error);
    respond([
        "success" => false,
        "message" => "Update failed.",
        "error" => $stmt->error
    ], 500);
}

$stmt->close();
$conn->close();
