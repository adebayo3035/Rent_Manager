<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

// Initialize logging
logActivity("Staff details fetch process started");


try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        $errorMsg = "Unauthenticated access attempt to staff details";
        logActivity($errorMsg);
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $loggedInUserRole = $_SESSION['role'] ?? null;
    logActivity("Staff details request initiated by admin ID: $adminId (Role: $loggedInUserRole)");

    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }

    if (!isset($input['staff_id'])) {
        $errorMsg = "Missing staff_id in request data";
        logActivity($errorMsg);
        echo json_encode(['success' => false, 'message' => 'Staff ID is required']);
        exit();
    }

    $staff_id = $input['staff_id'];
    if (!is_numeric($staff_id)) {
        $errorMsg = "Invalid staff_id format: " . $staff_id;
        logActivity($errorMsg);
        echo json_encode(['success' => false, 'message' => 'Invalid Staff ID format']);
        exit();
    }

    logActivity("Processing staff details for ID: " . $staff_id);

    try {
        // Start transaction for consistent data view
        $conn->begin_transaction();
        logActivity("Transaction started for staff details fetch");
    
        // Fetch staff details
        $query = "SELECT * FROM admin_tbl WHERE unique_id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed for staff details: " . $conn->error);
        }
    
        $stmt->bind_param("i", $staff_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed for staff details: " . $stmt->error);
        }
    
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("No record found for staff ID: " . $staff_id);
        }
    
        $staffDetails = $result->fetch_assoc();
        // DO NOT CLOSE $stmt here
        $conn->commit();
    
        logActivity("Successfully retrieved staff details for ID: " . $staff_id);
    
        // Prepare response
        $response = [
            'success' => true,
            'staff_details' => $staffDetails,
            'logged_in_user_role' => $loggedInUserRole,
            'requested_by' => $adminId,
            'timestamp' => date('c')
        ];
    
        echo json_encode($response);
        logActivity("Staff details fetch completed successfully");
    
    } catch (Exception $e) {
        $conn->rollback();
        throw $e; // Re-throw to outer catch block
    }
} catch (Exception $e) {
    $errorMsg = "Error fetching staff details: " . $e->getMessage();
    logActivity($errorMsg);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to fetch staff details',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    logActivity("Staff details fetch process completed");
}