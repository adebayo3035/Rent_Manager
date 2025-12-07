<?php
// create_property_type.php
// Endpoint to insert a new property type with full logging

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php'; // contains json_success, json_error, logActivity()
session_start();
// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("Invalid request method used on create_property_type");
    json_error("Invalid request method. Use POST.", 405);
}

 if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized attempt to add property");
        json_error("Not logged in", 401);
    }

    $userId  = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? null;

// Collect JSON input
$input = json_decode(file_get_contents("php://input"), true);

// $type_name   = trim($input['add_property_type_name'] ?? '');
// $description = trim($input['add_property_type_description'] ?? '');
// $status      = $input['status'] ?? 1; // default = Active

$type_name = trim($_POST['add_property_type_name'] ?? '');
$description = trim($_POST['add_property_type_description'] ?? '');
$status      = $_POST['status'] ?? 1; // default = Active
$created_by  = $userId;

// Basic validation
if ($type_name === '') {
    logActivity("Validation error: type_name missing");
    json_error("Property type name is required.", 400);
}

if (!in_array($status, [0, 1])) {
    logActivity("Validation error: invalid status '$status' provided");
    json_error("Status must be 0 (Inactive) or 1 (Active).", 400);
}

if (!$created_by) {
    logActivity("Validation error: created_by missing");
    json_error("Missing created_by.", 400);
}

// Prevent duplicate type names
$check = $conn->prepare("
    SELECT type_id 
    FROM property_type 
    WHERE type_name = ?
");
$check->bind_param("s", $type_name);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    logActivity("Duplicate property type attempted: '$type_name'");
    json_error("Property type already exists.", 409);
}

$check->close();

// Insert query
$stmt = $conn->prepare("
    INSERT INTO property_type 
        (type_name, description, status, created_by, created_at) 
    VALUES (?, ?, ?, ?, NOW())
");

if (!$stmt) {
    logActivity("Failed to prepare insert statement: " . $conn->error);
    json_error("Failed to prepare statement: " . $conn->error, 500);
}

$stmt->bind_param("ssis", $type_name, $description, $status, $created_by);

if ($stmt->execute()) {
    logActivity("Property type created successfully: $type_name by user $created_by");

    json_success([
        "message" => "Property type created successfully.",
        "type_id" => $stmt->insert_id
    ], 201);

} else {
    logActivity("Insert failed: " . $stmt->error);
    json_error("Failed to insert property type: " . $stmt->error, 500);
}

$stmt->close();
$conn->close();
