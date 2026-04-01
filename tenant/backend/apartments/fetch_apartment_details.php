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
    logActivity("Validated apartment_code: {$apartment_code}");


    // ------------------------ START TRANSACTION ------------------------
    if (!$conn->begin_transaction()) {
        throw new Exception("Failed to begin transaction: " . $conn->error);
    }
    logActivity("Transaction started.");


    // ------------------------ SQL PREPARATION - APARTMENT DETAILS WITH JOIN ------------------------
    // Updated query to join apartments with properties and agents in a single query
    $query = "
        SELECT 
            a.*,
            p.name as property_name,
            p.address as property_address,
            p.agent_code as property_agent_code,
            ag.firstname as agent_firstname,
            ag.lastname as agent_lastname,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name
        FROM apartments a
        LEFT JOIN properties p ON a.property_code = p.property_code
        LEFT JOIN agents ag ON p.agent_code = ag.agent_code
        WHERE a.apartment_code = ?
    ";
    
    logActivity("Preparing SQL Query with joins: {$query}");

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    logActivity("SQL Statement prepared successfully.");


    // ------------------------ SQL BIND ------------------------
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

    $apartmentData = $result->fetch_assoc();
    logActivity("Apartment data fetched with joins: " . json_encode($apartmentData));


    // ------------------------ STRUCTURE THE APARTMENT DETAILS WITH NESTED AGENT INFO ------------------------
    $apartmentDetails = [
        // Basic apartment information
        'apartment_code' => $apartmentData['apartment_code'],
        'apartment_number' => $apartmentData['apartment_number'],
        'property_code' => $apartmentData['property_code'],
        'status' => $apartmentData['status'] ?? null,
        'created_at' => $apartmentData['created_at'] ?? null,
        'updated_at' => $apartmentData['updated_at'] ?? null,
        
        // Property information
        'property' => [
            'name' => $apartmentData['property_name'],
            'address' => $apartmentData['property_address'],
            'agent_code' => $apartmentData['property_agent_code']
        ],
        
        // Agent information (nested inside apartment_details)
        'agent' => [
            'agent_code' => $apartmentData['property_agent_code'],
            'firstname' => $apartmentData['agent_firstname'],
            'lastname' => $apartmentData['agent_lastname'],
            'agent_name' => $apartmentData['agent_name'] ?? 
                           trim(($apartmentData['agent_firstname'] ?? '') . ' ' . ($apartmentData['agent_lastname'] ?? ''))
        ]
    ];

    // Add any additional fields from apartments table that might exist
    // This ensures we don't miss any apartment-specific fields
    foreach ($apartmentData as $key => $value) {
        // Skip keys we've already explicitly set or that are from joined tables
        if (!in_array($key, [
            'apartment_code', 'apartment_number', 'property_code', 'status', 
            'created_at', 'updated_at', 'property_name', 'property_address', 
            'property_agent_code', 'agent_firstname', 'agent_lastname', 'agent_name'
        ]) && !str_starts_with($key, 'property_') && !str_starts_with($key, 'agent_')) {
            $apartmentDetails[$key] = $value;
        }
    }

    // Clean up agent info if no agent exists
    if (empty($apartmentDetails['agent']['agent_code'])) {
        $apartmentDetails['agent'] = [
            'agent_code' => null,
            'firstname' => null,
            'lastname' => null,
            'agent_name' => 'No agent assigned'
        ];
    }

    logActivity("Structured apartment details with nested agent info: " . json_encode($apartmentDetails));


    // ------------------------ COMMIT TRANSACTION ------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");


    // ------------------------ SUCCESS RESPONSE ------------------------
    $response = [
        'success' => true,
        'apartment_details' => $apartmentDetails,  // All data nested here
        'logged_in_user_role' => $loggedInUserRole,
        'requested_by' => $adminId,
        'timestamp' => date('c')
    ];

    echo json_encode($response);
    logActivity("SUCCESS: Apartment details with agent info returned successfully.");

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