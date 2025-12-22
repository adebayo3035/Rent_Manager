<?php
// create_apartment_type.php (Optimized with Transaction, Try/Catch, Rate Limiting & Status Code Logging)

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';   // added for centralized auth using requireAuth function

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'add_apartment_type',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_apartment_type_form'
    ],
    'roles' => ['Super Admin', 'Admin']
]);

$userId = $auth['user_id'];
$userRole = $auth['role'];

logActivity("Authenticated user: {$userId} | Role: {$userRole}");

// Continue business logic...

// ------------------------------
// SESSION VALIDATION
// ------------------------------
// if (!isset($_SESSION['unique_id'])) {
//     $code = 401;
//     logActivity("Unauthorized access attempt ({$code}) — no active session");
//     json_error("Not logged in", $code);
// }

// $userId   = $_SESSION['unique_id'];
// $userRole = $_SESSION['role'] ?? 'UNKNOWN';

logActivity("Authenticated request. UserID={$userId} | Role={$userRole}");

// ------------------------------
// INPUT COLLECTION
// ------------------------------
$type_name   = trim($_POST['add_apartment_type_name'] ?? '');
$description = trim($_POST['add_apartment_type_description'] ?? '');
$status      = isset($_POST['status']) ? intval($_POST['status']) : 1;
$created_by  = $userId;

logActivity("Received inputs: " . json_encode([
    "type_name" => $type_name,
    "description" => $description,
    "status" => $status,
    "created_by" => $created_by
]));

// ------------------------------
// BASIC VALIDATION
// ------------------------------
if ($type_name === '') {
    $code = 400;
    logActivity("Validation failed ({$code}): Missing type_name");
    json_error("Apartment type name is required.", $code);
}

if (!in_array($status, [0, 1], true)) {
    $code = 400;
    logActivity("Validation failed ({$code}): Invalid status '{$status}'");
    json_error("Status must be 0 or 1.", $code);
}

logActivity("Basic validation passed.");

// =====================================================
// TRY–CATCH + TRANSACTION
// =====================================================
try {
    logActivity("Starting DB transaction.");
    $conn->begin_transaction();

    // ------------------------------
    // DUPLICATE CHECK
    // ------------------------------
    $duplicateSQL = "SELECT type_id FROM apartment_type WHERE type_name = ? LIMIT 1";
    $checkStmt = $conn->prepare($duplicateSQL);
    $checkStmt->bind_param("s", $type_name);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        $code = 409;
        logActivity("Duplicate detected ({$code}) for type_name '{$type_name}'");
        $conn->rollback();
        json_error("Apartment type already exists.", $code);
    }

    $checkStmt->close();
    logActivity("No duplicate apartment type found.");

    // ------------------------------
    // INSERT OPERATION
    // ------------------------------
    $insertSQL = "
        INSERT INTO apartment_type 
            (type_name, description, status, created_by, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ";

    $stmt = $conn->prepare($insertSQL);
    $stmt->bind_param("ssis", $type_name, $description, $status, $created_by);

    logActivity("Executing apartment type insert for '{$type_name}'");

    if (!$stmt->execute()) {
        throw new Exception("Insert failed: " . $stmt->error);
    }

    $newTypeID = $stmt->insert_id;
    $stmt->close();

    // ------------------------------
    // COMMIT
    // ------------------------------
    $conn->commit();
    logActivity("Transaction committed successfully. New type_id={$newTypeID}");

    // ------------------------------
    // SUCCESS RESPONSE
    // ------------------------------
    $code = 201;
    logActivity("Success response ({$code}) returned for new apartment type '{$type_name}'");

    json_success([
        "message" => "Apartment type created successfully.",
        "type_id" => $newTypeID
    ], $code);

} catch (Exception $e) {

    // ------------------------------
    // ROLLBACK & ERROR LOGGING
    // ------------------------------
    logActivity("Exception caught: " . $e->getMessage());
    logActivity("Rolling back transaction due to error.");

    $conn->rollback();

    $code = 500;
    logActivity("Error response ({$code}) returned: " . $e->getMessage());

    json_error("Failed to create apartment type. Error: " . $e->getMessage(), $code);
}

$conn->close();
logActivity("==== Create Apartment Type Request Finished ====");
