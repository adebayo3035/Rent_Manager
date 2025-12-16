<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
logActivity("==== Apartment Details Fetch Request Started ====");

try {

    // ------------------------ AUTH CHECK ------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("AUTH FAILURE: Attempt to fetch apartment details without login.");
        echo json_encode(["success" => false, "message" => "Not logged in"]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? "UNKNOWN";

    logActivity("Authenticated Request | Admin ID: {$adminId}, Role: {$loggedInUserRole}");
    rateLimit("fetch_apartment_details", 10, 60);

    logActivity("New fetch_apartment_details request received | IP: " . getClientIP());


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
        logActivity("Validation Error: apartment_code missing in payload.");
        echo json_encode(['success' => false, 'message' => 'apartment_code is required']);
        exit();
    }

    $apartment_code = trim($input['id']);

    // Valid apartment_code format? (hex or alphanumeric, length 8–64)
    // if (!preg_match('/^[a-zA-Z0-9]+$/', $apartment_code)) {
    //     logActivity("Validation Error: Invalid apartment_code format: {$apartment_code}");
    //     echo json_encode(['success' => false, 'message' => 'Invalid apartment_code format']);
    //     exit();
    // }

    logActivity("Validated apartment_code: {$apartment_code}");


    // ------------------------ START TRANSACTION ------------------------
    if (!$conn->begin_transaction()) {
        throw new Exception("Failed to begin transaction: " . $conn->error);
    }
    logActivity("Transaction started.");


    // ------------------------ SQL PREPARATION ------------------------
    $query = "SELECT * FROM apartments WHERE apartment_code = ?";
    logActivity("Preparing SQL Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    logActivity("SQL Statement prepared successfully.");


    // ------------------------ SQL BIND ------------------------
    // apartment_code is a string, so bind as 's'
    if (!$stmt->bind_param("s", $apartment_code)) {
        throw new Exception("SQL Bind Failed: " . $stmt->error);
    }

    logActivity("SQL Bind successful: [apartment_code => {$apartment_code}]");


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
        logActivity("NO RECORD FOUND for apartment_code: {$apartment_code}");
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Apartment not found']);
        exit();
    }

    $apartmentDetails = $result->fetch_assoc();
    logActivity("Apartment details fetched: " . json_encode($apartmentDetails));


    // ------------------------ COMMIT TRANSACTION ------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");


    // ------------------------ SUCCESS RESPONSE ------------------------
    $response = [
        'success' => true,
        'apartment_details' => $apartmentDetails,
        'logged_in_user_role' => $loggedInUserRole,
        'requested_by' => $adminId,
        'timestamp' => date('c')
    ];

    echo json_encode($response);
    logActivity("SUCCESS: Apartment details returned successfully.");

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
        'message' => 'Failed to fetch apartment details',
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

    logActivity("==== Apartment Details Fetch Request Completed ====");
}
