<?php
header('Content-Type: application/json');
require_once __DIR__ . '../utilities/config.php';
require_once __DIR__ . '../utilities/auth_utils.php';
require_once __DIR__ . '../utilities/utils.php';
session_start();

// Log start
logActivity("Add Property process started");

try {
    // ------------------------------
    // AUTH CHECK
    // ------------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized attempt to add property");
        json_error("Not logged in", 401);
    }

    $userId  = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? null;

    logActivity("Add Property request by User ID: $userId (Role: $userRole)");

    // ------------------------------
    // READ JSON INPUT
    // ------------------------------
    $input = json_decode(file_get_contents('php://input'), true);

    $requiredFields = [
        'agent_id', 'name', 'property_type_id'
    ];

    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            json_error("Missing required field: $field", 400);
        }
    }

    // Assign variables
    $agent_id         = $input['agent_id'];
    $property_code    = trim($input['property_code']);
    $name             = trim($input['name']);
    $address          = $input['address'] ?? null;
    $city             = $input['city'] ?? null;
    $state            = $input['state'] ?? null;
    $country          = $input['country'] ?? "Nigeria";
    $contact_name     = $input['contact_name'] ?? null;
    $contact_phone    = $input['contact_phone'] ?? null;
    $notes            = $input['notes'] ?? null;
    $property_type_id = $input['property_type_id'];
    $status           = "Active";

    // ------------------------------
    // START TRANSACTION
    // ------------------------------
    $conn->begin_transaction();

    // ------------------------------
    // VALIDATE property_type_id EXISTS
    // ------------------------------
    $stmt = $conn->prepare("SELECT type_id FROM property_type WHERE type_id = ?");
    $stmt->bind_param("i", $property_type_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->rollback();
        json_error("Invalid property_type_id supplied", 400);
    }
    $stmt->close();

    // ------------------------------
    // CHECK FOR DUPLICATES
    // property_code must be unique
    // ------------------------------
    $stmt = $conn->prepare("SELECT id FROM properties WHERE property_code = ?");
    $stmt->bind_param("s", $property_code);
    $stmt->execute();
    $duplicateCheck = $stmt->get_result();

    if ($duplicateCheck->num_rows > 0) {
        $stmt->close();
        $conn->rollback();
        json_error("A property with this property_code already exists", 409);
    }
    $stmt->close();

    // ------------------------------
    // INSERT NEW PROPERTY
    // ------------------------------
    $insertQuery = "
        INSERT INTO properties (
            agent_id, property_code, name, address, city, state, country, 
            contact_name, contact_phone, notes, property_type_id, status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $insert = $conn->prepare($insertQuery);
    if (!$insert) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $insert->bind_param(
        "isssssssssis",
        $agent_id,
        $property_code,
        $name,
        $address,
        $city,
        $state,
        $country,
        $contact_name,
        $contact_phone,
        $notes,
        $property_type_id,
        $status
    );

    if (!$insert->execute()) {
        throw new Exception("Insert failed: " . $insert->error);
    }

    $newPropertyId = $conn->insert_id;
    $insert->close();

    // ------------------------------
    // COMMIT TRANSACTION
    // ------------------------------
    $conn->commit();

    logActivity("Property added successfully. ID: $newPropertyId | Code: $property_code");

    // SUCCESS RESPONSE
    json_success(
        "Property added successfully",
        [
            "property_id" => $newPropertyId,
            "property_code" => $property_code,
            "name" => $name
        ]
    );

} catch (Exception $e) {
    // Rollback if needed
    if ($conn->errno) {
        $conn->rollback();
    }

    logActivity("Error adding property: " . $e->getMessage());
    json_error($e->getMessage(), 500);
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}

