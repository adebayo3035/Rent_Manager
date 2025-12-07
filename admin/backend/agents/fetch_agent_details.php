<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
logActivity("==== Agent Details Fetch Request Started ====");

try {

    // ------------------------ AUTH CHECK ------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("AUTH FAILURE: Attempt to fetch agent details without login.");
        echo json_encode(["success" => false, "message" => "Not logged in"]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? "UNKNOWN";

    logActivity("Authenticated Request | Admin ID: {$adminId}, Role: {$loggedInUserRole}");
    rateLimit("fetch_agent_details", 10, 60);

    logActivity("New fetch_agent_details request received | IP: " . getClientIP());


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
        logActivity("Validation Error: agent_code missing in payload.");
        echo json_encode(['success' => false, 'message' => 'agent_code is required']);
        exit();
    }

    $agent_code = trim($input['id']);

    // Valid agent_code format? (hex or alphanumeric, length 8–64)
    if (!preg_match('/^[a-zA-Z0-9]+$/', $agent_code)) {
        logActivity("Validation Error: Invalid agent_code format: {$agent_code}");
        echo json_encode(['success' => false, 'message' => 'Invalid agent_code format']);
        exit();
    }

    logActivity("Validated agent_code: {$agent_code}");


    // ------------------------ START TRANSACTION ------------------------
    if (!$conn->begin_transaction()) {
        throw new Exception("Failed to begin transaction: " . $conn->error);
    }
    logActivity("Transaction started.");


    // ------------------------ SQL PREPARATION ------------------------
    $query = "SELECT * FROM agents WHERE agent_code = ?";
    logActivity("Preparing SQL Query: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    logActivity("SQL Statement prepared successfully.");


    // ------------------------ SQL BIND ------------------------
    // agent_code is a string, so bind as 's'
    if (!$stmt->bind_param("s", $agent_code)) {
        throw new Exception("SQL Bind Failed: " . $stmt->error);
    }

    logActivity("SQL Bind successful: [agent_code => {$agent_code}]");


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
        logActivity("NO RECORD FOUND for agent_code: {$agent_code}");
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Agent not found']);
        exit();
    }

    $agentDetails = $result->fetch_assoc();
    logActivity("Agent details fetched: " . json_encode($agentDetails));


    // ------------------------ COMMIT TRANSACTION ------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");


    // ------------------------ SUCCESS RESPONSE ------------------------
    $response = [
        'success' => true,
        'agent_details' => $agentDetails,
        'logged_in_user_role' => $loggedInUserRole,
        'requested_by' => $adminId,
        'timestamp' => date('c')
    ];

    echo json_encode($response);
    logActivity("SUCCESS: Agent details returned successfully.");

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
        'message' => 'Failed to fetch agent details',
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

    logActivity("==== Agent Details Fetch Request Completed ====");
}
