<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php'; // contains json_success, json_error, logActivity()
session_start();

// Initialize variables for cleanup
$stmt = null;
// $conn = null;

// Initialize logging
logActivity("property Type details fetch process started");

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        $errorMsg = "Unauthenticated access attempt to Property Type details";
        logActivity($errorMsg);
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $userId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? null;
    logActivity("Request initiated by user ID: $userId (Role: $userRole)");

    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }

    if (!isset($input['id'])) {
        $errorMsg = "Missing property_type_id in request data";
        logActivity($errorMsg);
        echo json_encode(['success' => false, 'message' => 'Property Type ID is required']);
        exit();
    }

    $property_type_id = filter_var($input['id'], FILTER_VALIDATE_INT);
    if ($property_type_id === false || $property_type_id < 1) {
        $errorMsg = "Invalid $property_type_id format: " . ($input['$property_type_id'] ?? 'null');
        logActivity($errorMsg);
        echo json_encode(['success' => false, 'message' => 'Invalid Property Type ID format']);
        exit();
    }

    logActivity("Processing Property Type details for ID: " . $property_type_id);

    try {
        // Start transaction for consistent data view
        $conn->begin_transaction();
        logActivity("Database transaction started");

        // Fetch Property Type details
        $query = "SELECT * FROM property_type WHERE type_id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed for Property Type details: " . $conn->error);
        }

        $stmt->bind_param("i", $property_type_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed for Property Type details: " . $stmt->error);
        }

        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            logActivity("No Property Type found with ID: " . $property_type_id);
            echo json_encode([
                'success' => false, 
                'message' => 'Property Type not found',
                '$property_type_id' => $property_type_id
            ]);
            exit();
        }

        $propertyTypeDetails = $result->fetch_assoc();
        $conn->commit();

        logActivity("Successfully retrieved Property Type details for ID: " . $property_type_id);

        // Prepare response
        $response = [
            'success' => true,
            'property_type_details' => $propertyTypeDetails,
            'logged_in_user_role' => $userRole,
            'requested_by' => $userId,
            'timestamp' => date('c')
        ];

        echo json_encode($response);
        logActivity("Property Type details fetch completed successfully");

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        throw $e;
    }
} catch (Exception $e) {
    $errorMsg = "Error fetching Property Type details: " . $e->getMessage();
    logActivity($errorMsg);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to fetch Property Type details',
        'error' => $e->getMessage(),
        'property_type_id' => $property_type_id ?? null
    ]);
} finally {
    // Clean up resources
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
    logActivity("Property Type details fetch process completed");
}