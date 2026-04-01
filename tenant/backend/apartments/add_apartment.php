<?php
// add_apartment.php — Production-grade implementation with capacity validation

header('Content-Type: application/json; charset=utf-8');

// Define constants
define('CSRF_FORM_NAME', 'add_apartment_form');
define('MAX_APARTMENT_UNIT', 1000); // Maximum apartment unit number
define('MIN_APARTMENT_UNIT', 1);    // Minimum apartment unit number
define('MIN_RENT_AMOUNT', 0);       // Minimum rent amount
define('MAX_RENT_AMOUNT', 999999999.99); // Maximum rent amount
define('MIN_SECURITY_DEPOSIT', 0);   // Minimum security deposit
define('MAX_SECURITY_DEPOSIT', 999999999.99); // Maximum security deposit

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
        'apartment_type_id' => $_POST['apartment_type'] ?? '',
        'apartment_type_unit' => $_POST['apartment_type_unit'] ?? '',
        'rent_amount' => $_POST['apartment_rent_amount'] ?? '',
        'security_deposit' => $_POST['apartment_security_deposit'] ?? '',
    ];

    // Log non-sensitive inputs
    $logInputs = $rawInputs;
    logActivity("Raw inputs received: " . json_encode($logInputs));

    // Sanitize inputs
    $inputs = sanitize_inputs($rawInputs);

    // Extract variables
    $propertyCode = trim($inputs['property_code']);
    $typeId = (int) $inputs['apartment_type_id'];
    $typeUnit = (int) $inputs['apartment_type_unit'];
    
    // ------------------------- SANITIZE NUMBER FIELDS WITH COMMAS -------------------------
    // Method 1: Using the sanitizeNumberWithCommas function (strict)
    try {
        $rentAmount = sanitizeNumberWithCommas(
            $inputs['rent_amount'], 
            false, // required
            MIN_RENT_AMOUNT, 
            MAX_RENT_AMOUNT
        );
        
        $securityDeposit = sanitizeNumberWithCommas(
            $inputs['security_deposit'], 
            true, // allow null/empty
            MIN_SECURITY_DEPOSIT, 
            MAX_SECURITY_DEPOSIT
        );
        
        // If security deposit is empty or null, set to 0
        if ($securityDeposit === null) {
            $securityDeposit = 0.00;
        }
        
    } catch (Exception $e) {
        logActivity("Number validation error: " . $e->getMessage());
        json_error($e->getMessage(), 400);
    }
    

    logActivity("Sanitized amounts - Rent: {$rentAmount}, Deposit: {$securityDeposit}");

    // ------------------------- COMPREHENSIVE VALIDATION -------------------------
    $errors = [];

    // Required fields validation
    $required = [
        'property_code' => 'Property Code',
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

    // Apartment type unit validation
    if ($typeUnit < MIN_APARTMENT_UNIT || $typeUnit > MAX_APARTMENT_UNIT) {
        $errors[] = "Apartment unit must be between " . MIN_APARTMENT_UNIT . " and " . MAX_APARTMENT_UNIT . ".";
    }

    // Apartment type validation
    if ($typeId <= 0) {
        $errors[] = "Invalid apartment type selected.";
    }

    // Rent amount validation (already done in sanitizeNumberWithCommas, but double-check)
    if ($rentAmount < MIN_RENT_AMOUNT) {
        $errors[] = "Rent amount cannot be negative.";
    }

    // Security deposit validation (already done in sanitizeNumberWithCommas)
    if ($securityDeposit < MIN_SECURITY_DEPOSIT) {
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

        // 2. Validate apartment type exists and is active
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
        
        // ------------------------- GENERATE APARTMENT CODE -------------------------
        // Enhanced apartment code generation
        $apartmentCode = generateApartmentCode($propertyCode, $typeUnit, $apartmentNumber);
        logActivity("Generated apartment code: {$apartmentCode}");
        $occupancy_status = 'NOT OCCUPIED';

        // ------------------------- DATABASE INSERT -------------------------
        $insertSql = "
            INSERT INTO apartments (
                apartment_code,
                property_code,
                apartment_type_id,
                apartment_type_unit,
                apartment_number,
                rent_amount,
                security_deposit,
                occupancy_status,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";

        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            throw new Exception("Database prepare failed: " . $conn->error, 500);
        }

        // Bind parameters
        $stmt->bind_param(
            "ssiiiddss",
            $apartmentCode,
            $propertyCode,
            $typeId,
            $typeUnit,
            $apartmentNumber,
            $rentAmount,
            $securityDeposit,
            $occupancy_status,
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

        // ------------------------- COMMIT TRANSACTION -------------------------
        $conn->commit();
        logActivity("Transaction committed successfully.");

        // Consume CSRF token after successful operation
        consumeCsrfToken(CSRF_FORM_NAME);

        // ------------------------- SUCCESS RESPONSE -------------------------
        $response = [
            'success' => true,
            'message' => 'Apartment added successfully!',
            'apartment_code' => $apartmentCode,
            'apartment_id' => $newApartmentId,
            'property_code' => $propertyCode,
            'rent_amount' => number_format($rentAmount, 2), // Format for response
            'security_deposit' => number_format($securityDeposit, 2),
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