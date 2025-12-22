<?php
// add_apartment.php — Production-grade implementation with capacity validation

header('Content-Type: application/json; charset=utf-8');

// Define constants
define('CSRF_FORM_NAME', 'add_apartment_form');
define('MAX_APARTMENT_UNIT', 1000); // Maximum apartment unit number
define('MIN_APARTMENT_UNIT', 1);    // Minimum apartment unit number

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

// Authentication and authorization
$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'add_apartment',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_apartment_form'
    ],
    'roles' => ['Super Admin', 'Admin']
]);

$userId = $auth['user_id'];
$userRole = $auth['role'];

logActivity("==== Apartment Onboarding Started ====");
logActivity("User: {$userId} | Role: {$userRole}");

try {
    // ------------------------- INPUT COLLECTION & SANITIZATION -------------------------
    $rawInputs = [
        'property_code' => $_POST['apartment_property_code'] ?? '',
        'agent_code' => $_POST['apartment_agent_code'] ?? '',
        'apartment_type_id' => $_POST['apartment_type'] ?? '',
        'apartment_type_unit' => $_POST['apartment_type_unit'] ?? '',
        'rent_amount' => $_POST['apartment_rent_amount'] ?? '',
        'security_deposit' => $_POST['apartment_security_deposit'] ?? '',
        // 'description'           => $_POST['description'] ?? '',
    ];

    // Log non-sensitive inputs
    $logInputs = $rawInputs;
    // unset($logInputs['rent_amount'], $logInputs['security_deposit']);
    logActivity("Raw inputs received: " . json_encode($logInputs));

    // Sanitize inputs
    $inputs = sanitize_inputs($rawInputs);

    // Extract variables
    $propertyCode = trim($inputs['property_code']);
    $agentCode = trim($inputs['agent_code']);
    $typeId = (int) $inputs['apartment_type_id'];
    $typeUnit = (int) $inputs['apartment_type_unit'];
    // $floorNumber    = !empty($inputs['floor_number']) ? (int) $inputs['floor_number'] : null;
    // $apartmentNumber= trim($inputs['apartment_number'] ?? '');
    $rentAmount =  (float) $inputs['rent_amount'];
    $securityDeposit = (float) $inputs['security_deposit'];
    // $description    = trim($inputs['description'] ?? '');

    // ------------------------- COMPREHENSIVE VALIDATION -------------------------
    $errors = [];

    // Required fields validation
    $required = [
        'property_code' => 'Property Code',
        'agent_code' => 'Agent Code',
        'apartment_type_id' => 'Apartment Type',
        'apartment_type_unit' => 'Apartment Unit'
    ];

    foreach ($required as $field => $label) {
        if (empty($inputs[$field])) {
            $errors[] = "{$label} is required.";
        }
    }

    // Property code format validation
    if (!empty($propertyCode) && !preg_match('/^[A-Za-z0-9_\-]{4,50}$/', $propertyCode)) {
        $errors[] = "Invalid property code format.";
    }

    // Agent code format validation
    if (!empty($agentCode) && !preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $agentCode)) {
        $errors[] = "Invalid agent code format.";
    }

    // Apartment type unit validation
    if ($typeUnit < MIN_APARTMENT_UNIT || $typeUnit > MAX_APARTMENT_UNIT) {
        $errors[] = "Apartment unit must be between " . MIN_APARTMENT_UNIT . " and " . MAX_APARTMENT_UNIT . ".";
    }

    // Apartment type validation
    if ($typeId <= 0) {
        $errors[] = "Invalid apartment type selected.";
    }

    // Floor number validation (if provided)
    // if ($floorNumber !== null && ($floorNumber < 0 || $floorNumber > 100)) {
    //     $errors[] = "Floor number must be between 0 and 100.";
    // }

    // Rent amount validation (if provided)
    if ($rentAmount !== null && $rentAmount < 0) {
        $errors[] = "Rent amount cannot be negative.";
    }

    // Security deposit validation (if provided)
    if ($securityDeposit !== null && $securityDeposit < 0) {
        $errors[] = "Security deposit cannot be negative.";
    }

    // If any validation errors, return them
    if (!empty($errors)) {
        logActivity("Validation errors: " . implode(" | ", $errors));
        json_error(implode(" ", $errors), 400);
    }

    logActivity("Input validation passed.");

    // ------------------------- DATABASE TRANSACTION -------------------------
    $conn->begin_transaction();
    logActivity("Database transaction started.");

    try {
        // ------------------------- FOREIGN KEY VALIDATION -------------------------
        // 1. Validate property exists and is active
        $propertyStmt = $conn->prepare("
            SELECT p.property_code, p.property_type_unit, pt.type_name 
            FROM properties p
            LEFT JOIN property_type pt ON p.property_type_id = pt.type_id
            WHERE p.property_code = ? AND p.status = 1
            LIMIT 1
        ");
        $propertyStmt->bind_param("s", $propertyCode);
        $propertyStmt->execute();
        $propertyResult = $propertyStmt->get_result();

        if ($propertyResult->num_rows === 0) {
            $propertyStmt->close();
            throw new Exception("Property not found or inactive.", 404);
        }

        $propertyData = $propertyResult->fetch_assoc();
        $maxApartments = (int) $propertyData['property_type_unit'];
        $propertyTypeName = $propertyData['type_name'] ?? 'Unknown';
        $propertyStmt->close();

        logActivity("Property validation passed: {$propertyCode} | Max apartments: {$maxApartments}");

        // 2. Validate agent exists and is active
        if (!empty($agentCode)) {
            $agentStmt = $conn->prepare("SELECT agent_code FROM agents WHERE agent_code = ? AND status = 1 LIMIT 1");
            $agentStmt->bind_param("s", $agentCode);
            $agentStmt->execute();
            $agentStmt->store_result();

            if ($agentStmt->num_rows === 0) {
                $agentStmt->close();
                throw new Exception("Agent not found or inactive.", 400);
            }
            $agentStmt->close();
            logActivity("Agent validation passed: {$agentCode}");
        }

        // 3. Validate apartment type exists and is active
        $typeStmt = $conn->prepare("SELECT type_name FROM apartment_type WHERE type_id = ? AND status = 1 LIMIT 1");
        $typeStmt->bind_param("i", $typeId);
        $typeStmt->execute();
        $typeStmt->bind_result($apartmentTypeName);

        if (!$typeStmt->fetch()) {
            $typeStmt->close();
            throw new Exception("Apartment type not found or inactive.", 400);
        }
        $typeStmt->close();
        logActivity("Apartment type validation passed: ID {$typeId}");

        // ------------------------- APARTMENT CAPACITY VALIDATION -------------------------
        // Count existing apartments under this property
        $countStmt = $conn->prepare("
            SELECT COUNT(*) as total_apartments 
            FROM apartments 
            WHERE property_code = ? AND status = 1
        ");
        $countStmt->bind_param("s", $propertyCode);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countData = $countResult->fetch_assoc();
        $existingApartments = (int) $countData['total_apartments'];
        $countStmt->close();

        logActivity("Capacity check - Existing: {$existingApartments} | Allowed: {$maxApartments}");

        // Check if property has capacity for new apartment
        if ($existingApartments >= $maxApartments) {
            throw new Exception(
                "Property '{$propertyCode}' has reached its maximum capacity of {$maxApartments} apartments. " .
                "No more apartments can be added to this property.",
                400
            );
        }

        // Calculate remaining capacity
        $remainingCapacity = $maxApartments - $existingApartments;
        logActivity("Property has {$remainingCapacity} apartment(s) remaining capacity");

        $apartmentNumber = $existingApartments + 1; // Auto-assign apartment number

        // ------------------------- DUPLICATE APARTMENT CHECK -------------------------
        // Check for duplicate apartment number within the same property
        if (!empty($apartmentNumber)) {
            $dupNumberStmt = $conn->prepare("
                SELECT apartment_code 
                FROM apartments 
                WHERE property_code = ? AND apartment_number = ? AND status = 1
                LIMIT 1
            ");
            $dupNumberStmt->bind_param("ss", $propertyCode, $apartmentNumber);
            $dupNumberStmt->execute();
            $dupNumberStmt->store_result();

            if ($dupNumberStmt->num_rows > 0) {
                $dupNumberStmt->close();
                throw new Exception("Apartment number '{$apartmentNumber}' already exists in this property.", 409);
            }
            $dupNumberStmt->close();
        }

        // Check for duplicate unit combination (property + type + unit)
        // $dupUnitStmt = $conn->prepare("
        //     SELECT apartment_code 
        //     FROM apartments 
        //     WHERE property_code = ? 
        //       AND apartment_type_id = ? 
        //       AND apartment_type_unit = ?
        //       AND status = 1
        //     LIMIT 1
        // ");
        // $dupUnitStmt->bind_param("sii", $propertyCode, $typeId, $typeUnit);
        // $dupUnitStmt->execute();
        // $dupUnitStmt->store_result();

        // if ($dupUnitStmt->num_rows > 0) {
        //     $dupUnitStmt->close();
        //     throw new Exception(
        //         "This apartment unit (Type: {$apartmentTypeName}, Unit: {$typeUnit}) " .
        //         "already exists under property '{$propertyCode}'.",
        //         409
        //     );
        // }
        // $dupUnitStmt->close();

        // logActivity("Duplicate checks passed.");

        // ------------------------- GENERATE APARTMENT CODE -------------------------
        // Enhanced apartment code generation
        $apartmentCode = generateApartmentCode($propertyCode, $typeUnit, $apartmentNumber);
        logActivity("Generated apartment code: {$apartmentCode}");

        // ------------------------- DATABASE INSERT -------------------------
        $insertSql = "
    INSERT INTO apartments (
        apartment_code,
        property_code,
        agent_code,
        apartment_type_id,
        apartment_type_unit,
        apartment_number,
        rent_amount,
        security_deposit,
        occupancy_status,
        created_by,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())
";

        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            throw new Exception("Database prepare failed: " . $conn->error, 500);
        }

        // Bind parameters
        $stmt->bind_param(
            "sssiiidds",
            $apartmentCode,
            $propertyCode,
            $agentCode,
            $typeId,
            $typeUnit,
            $apartmentNumber,
            $rentAmount,
            $securityDeposit,
            $userId
        );


        if (!$stmt->execute()) {
            // Check for specific MySQL errors
            if (strpos($stmt->error, 'foreign key constraint') !== false) {
                throw new Exception("Referenced entity (property, agent, or type) not found.", 400);
            }
            throw new Exception("Database insert failed: " . $stmt->error, 500);
        }

        $newApartmentId = $stmt->insert_id;
        $stmt->close();

        logActivity("Apartment inserted successfully. ID: {$newApartmentId}, Code: {$apartmentCode}");

        // ------------------------- UPDATE PROPERTY STATISTICS -------------------------
        // Optionally update property statistics or trigger any post-creation logic
        // $updateStatsStmt = $conn->prepare("
        //     UPDATE properties 
        //     SET total_apartments = (
        //         SELECT COUNT(*) FROM apartments 
        //         WHERE property_code = ? AND status = 1
        //     ),
        //     last_updated_by = ?,
        //     updated_at = NOW()
        //     WHERE property_code = ?
        // ");

        // if ($updateStatsStmt) {
        //     $updateStatsStmt->bind_param("sis", $propertyCode, $userId, $propertyCode);
        //     $updateStatsStmt->execute();
        //     $updateStatsStmt->close();
        //     logActivity("Property statistics updated for {$propertyCode}");
        // }

        // // ------------------------- CREATE AUDIT TRAIL -------------------------
        // $auditSql = "INSERT INTO apartment_audit_log 
        //     (apartment_code, property_code, action, performed_by, details, ip_address, user_agent)
        //     VALUES (?, ?, 'CREATE', ?, ?, ?, ?)";

        // $auditStmt = $conn->prepare($auditSql);
        // if ($auditStmt) {
        //     $details = json_encode([
        //         'apartment_type' => $apartmentTypeName,
        //         'apartment_unit' => $typeUnit,
        //         'floor_number' => $floorNumber,
        //         'apartment_number' => $apartmentNumber,
        //         'rent_amount' => $rentAmount,
        //         'remaining_capacity' => $remainingCapacity - 1 // After this insertion
        //     ]);
        //     $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        //     $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

        //     $auditStmt->bind_param(
        //         'ssssss',
        //         $apartmentCode,
        //         $propertyCode,
        //         $userId,
        //         $details,
        //         $ipAddress,
        //         $userAgent
        //     );
        //     $auditStmt->execute();
        //     $auditStmt->close();
        //     logActivity("Audit log created for apartment {$apartmentCode}");
        // }

        // ------------------------- COMMIT TRANSACTION -------------------------
        $conn->commit();
        logActivity("Transaction committed successfully.");

        // ------------------------- SUCCESS RESPONSE -------------------------
        $response = [
            'success' => true,
            'message' => 'Apartment added successfully!',
            'apartment_code' => $apartmentCode,
            'apartment_id' => $newApartmentId,
            'property_code' => $propertyCode,
            'remaining_capacity' => $remainingCapacity - 1,
            'total_capacity' => $maxApartments,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        logActivity("==== Apartment Onboarding Completed Successfully ====");

    } catch (Throwable $e) {
        // Rollback transaction
        $conn->rollback();
        logActivity("Transaction rolled back: " . $e->getMessage());
        throw $e; // Re-throw for outer catch block
    }

} catch (Throwable $e) {
    // ------------------------- ERROR HANDLING -------------------------
    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;

    logActivity("FATAL ERROR: " . $e->getMessage() .
        " | File: " . $e->getFile() .
        " | Line: " . $e->getLine() .
        " | Code: " . $errorCode);

    // Don't expose internal errors in production
    $errorMessage = ($errorCode === 500 && strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false)
        ? "An internal error occurred while processing your request."
        : $e->getMessage();

    json_error($errorMessage, $errorCode);
}

/**
 * Generate unique apartment code
 * Format: PROPERTYCODE-APT-UNIT[X]-F[Floor]-N[Number]
 */
function generateApartmentCode($propertyCode, $unit, $apartmentNumber = '')
{
    $code = $propertyCode . "-APT" . sprintf('%03d', $unit);

    if (!empty($apartmentNumber)) {
        $code .= "-" . strtoupper(preg_replace('/[^A-Z0-9]/', '', $apartmentNumber));
    }

    // Add timestamp component to ensure uniqueness
    $timestamp = date('His');
    $code .= '-' . $timestamp;

    return $code;
}