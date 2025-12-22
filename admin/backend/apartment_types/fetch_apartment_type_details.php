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
logActivity("apartment Type details fetch process started");

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        $errorMsg = "Unauthenticated access attempt to Apartment Type details";
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
        $errorMsg = "Missing apartment_type_id in request data";
        logActivity($errorMsg);
        echo json_encode(['success' => false, 'message' => 'Apartment Type ID is required']);
        exit();
    }

    $apartment_type_id = filter_var($input['id'], FILTER_VALIDATE_INT);
    if ($apartment_type_id === false || $apartment_type_id < 1) {
        $errorMsg = "Invalid $apartment_type_id format: " . ($input['$apartment_type_id'] ?? 'null');
        logActivity($errorMsg);
        echo json_encode(['success' => false, 'message' => 'Invalid Apartment Type ID format']);
        exit();
    }

    logActivity("Processing Apartment Type details for ID: " . $apartment_type_id);

    try {
        // Start transaction for consistent data view
        $conn->begin_transaction();
        logActivity("Database transaction started");

        // Fetch Apartment Type details
        $query = "SELECT * FROM apartment_type WHERE type_id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed for Apartment Type details: " . $conn->error);
        }

        $stmt->bind_param("i", $apartment_type_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed for Apartment Type details: " . $stmt->error);
        }

        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            logActivity("No Apartment Type found with ID: " . $apartment_type_id);
            echo json_encode([
                'success' => false, 
                'message' => 'Apartment Type not found',
                '$apartment_type_id' => $apartment_type_id
            ]);
            exit();
        }

        $apartmentTypeDetails = $result->fetch_assoc();
        $conn->commit();

        logActivity("Successfully retrieved Apartment Type details for ID: " . $apartment_type_id);

        // Prepare response
        $response = [
            'success' => true,
            'apartment_type_details' => $apartmentTypeDetails,
            'logged_in_user_role' => $userRole,
            'requested_by' => $userId,
            'timestamp' => date('c')
        ];

        echo json_encode($response);
        logActivity("Apartment Type details fetch completed successfully");

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        throw $e;
    }
} catch (Exception $e) {
    $errorMsg = "Error fetching Apartment Type details: " . $e->getMessage();
    logActivity($errorMsg);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to fetch Apartment Type details',
        'error' => $e->getMessage(),
        'apartment_type_id' => $apartment_type_id ?? null
    ]);
} finally {
    // Clean up resources
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
    logActivity("Apartment Type details fetch process completed");
}