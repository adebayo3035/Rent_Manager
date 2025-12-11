<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
logActivity("==== Property Details Fetch Request Started ====");

try {

    // ------------------------ AUTH CHECK ------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("AUTH FAILURE: Attempt to fetch property details without login.");
        echo json_encode(["success" => false, "message" => "Not logged in"]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? "UNKNOWN";

    logActivity("Authenticated Request | Admin ID: {$adminId}, Role: {$loggedInUserRole}");
    rateLimit("fetch_property_details", 10, 60);

    logActivity("New fetch_property_details request received | IP: " . getClientIP());


    // ------------------------ INPUT VALIDATION ------------------------
    $rawInput = file_get_contents('php://input');
    logActivity("Raw Input: " . $rawInput);

    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $msg = "Invalid JSON payload: " . json_last_error_msg();
        logActivity($msg);
        throw new Exception($msg);
    }

    if (!isset($input['id'])) {
        logActivity("Validation Error: property_code missing in payload.");
        echo json_encode(['success' => false, 'message' => 'property_code is required']);
        exit();
    }

    $property_code = trim($input['id']);

    // Valid property_code format? (hex or alphanumeric, length 8–64)
    if (!preg_match('/^[a-zA-Z0-9]+$/', $property_code)) {
        logActivity("Validation Error: Invalid property_code format: {$property_code}");
        echo json_encode(['success' => false, 'message' => 'Invalid property_code format']);
        exit();
    }

    logActivity("Validated property_code: {$property_code}");


    // ------------------------ START TRANSACTION ------------------------
    if (!$conn->begin_transaction()) {
        throw new Exception("Failed to begin transaction: " . $conn->error);
    }
    logActivity("Transaction started.");


    // ------------------------ SQL PREPARATION ------------------------
    $query = "SELECT * FROM properties WHERE property_code = ?";
    logActivity("Preparing SQL Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    logActivity("SQL Statement prepared successfully.");


    // ------------------------ SQL BIND ------------------------
    // property_code is a string, so bind as 's'
    if (!$stmt->bind_param("s", $property_code)) {
        throw new Exception("SQL Bind Failed: " . $stmt->error);
    }

    logActivity("SQL Bind successful: [property_code => {$property_code}]");


    // ------------------------ SQL EXECUTION ------------------------
    if (!$stmt->execute()) {
        throw new Exception("SQL Execute Failed: " . $stmt->error);
    }

    logActivity("SQL Execute completed successfully.");


    // ------------------------ FETCH RESULT ------------------------
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Failed to retrieve SQL results: " . $stmt->error);
    }

    logActivity("SQL result retrieved. Row count: " . $result->num_rows);

    if ($result->num_rows === 0) {
        logActivity("NO RECORD FOUND for property_code: {$property_code}");
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Property not found']);
        exit();
    }

    $propertyDetails = $result->fetch_assoc();
    logActivity("Property details fetched: " . json_encode($propertyDetails));


    // ------------------------ COMMIT TRANSACTION ------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");


    // ------------------------ SUCCESS RESPONSE ------------------------
    $response = [
        'success' => true,
        'property_details' => $propertyDetails,
        'logged_in_user_role' => $loggedInUserRole,
        'requested_by' => $adminId,
        'timestamp' => date('c')
    ];

    echo json_encode($response);
    logActivity("SUCCESS: Property details returned successfully.");

} catch (Exception $e) {

    // ------------------------ ERROR HANDLING ------------------------
    $err = "ERROR: " . $e->getMessage();
    logActivity($err);

    if ($conn->errno) {
        $conn->rollback();
        logActivity("Transaction rolled back due to error.");
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch property details',
        'error' => $e->getMessage()
    ]);

} finally {

    // ------------------------ CLEAN UP ------------------------
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
        logActivity("SQL Statement closed.");
    }

    if ($conn instanceof mysqli) {
        $conn->close();
        logActivity("Database connection closed.");
    }

    logActivity("==== Property Details Fetch Request Completed ====");
}
