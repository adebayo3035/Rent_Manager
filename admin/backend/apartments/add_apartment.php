<?php
// add_apartment.php — Multi-apartment creation with automatic property tracking

header('Content-Type: application/json; charset=utf-8');

// Define constants
define('CSRF_FORM_NAME', 'add_apartment_form');
define('MAX_APARTMENT_UNIT', 1000);
define('MIN_APARTMENT_UNIT', 1);
define('MIN_RENT_AMOUNT', 0);
define('MAX_RENT_AMOUNT', 999999999.99);
define('MIN_SECURITY_DEPOSIT', 0);
define('MAX_SECURITY_DEPOSIT', 999999999.99);
define('MAX_BULK_CREATE', 100);

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

logActivity("==== Multi-Apartment Onboarding Started ====");
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

    logActivity("Raw inputs received: " . json_encode($rawInputs));

    // Sanitize inputs
    $inputs = sanitize_inputs($rawInputs);

    // Extract variables
    $propertyCode = trim($inputs['property_code']);
    $typeId = (int) $inputs['apartment_type_id'];
    $unitsToCreate = (int) $inputs['apartment_type_unit'];
    
    // ------------------------- SANITIZE NUMBER FIELDS -------------------------
    try {
        $rentAmount = sanitizeNumberWithCommas(
            $inputs['rent_amount'], 
            false,
            MIN_RENT_AMOUNT, 
            MAX_RENT_AMOUNT
        );
        
        $securityDeposit = sanitizeNumberWithCommas(
            $inputs['security_deposit'], 
            true,
            MIN_SECURITY_DEPOSIT, 
            MAX_SECURITY_DEPOSIT
        );
        
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
        'apartment_type_unit' => 'Number of Apartments'
    ];

    foreach ($required as $field => $label) {
        if (empty($inputs[$field])) {
            $errors[] = "{$label} is required.";
        }
    }

    // Validate units to create
    if ($unitsToCreate < MIN_APARTMENT_UNIT) {
        $errors[] = "Number of apartments must be at least " . MIN_APARTMENT_UNIT . ".";
    }
    
    if ($unitsToCreate > MAX_BULK_CREATE) {
        $errors[] = "Cannot create more than " . MAX_BULK_CREATE . " apartments at once.";
    }

    // Property code format validation
    if (!empty($propertyCode) && !preg_match('/^[A-Za-z0-9_\-]{4,50}$/', $propertyCode)) {
        $errors[] = "Invalid property code format.";
    }

    // Apartment type validation
    if ($typeId <= 0) {
        $errors[] = "Invalid apartment type selected.";
    }

    // Rent amount validation
    if ($rentAmount < MIN_RENT_AMOUNT) {
        $errors[] = "Rent amount cannot be negative.";
    }

    // Security deposit validation
    if ($securityDeposit < MIN_SECURITY_DEPOSIT) {
        $errors[] = "Security deposit cannot be negative.";
    }

    if (!empty($errors)) {
        logActivity("Validation errors: " . implode(" | ", $errors));
        json_error(implode(" ", $errors), 400);
    }

    logActivity("Input validation passed.");

    // ------------------------- DATABASE TRANSACTION -------------------------
    $conn->begin_transaction();
    logActivity("Database transaction started.");

    try {
        // ------------------------- PROPERTY VALIDATION WITH TRACKING -------------------------
        $propertyStmt = $conn->prepare("
            SELECT 
                p.property_code,
                p.name,
                p.property_type_unit as max_capacity,
                p.apartments_created,
                p.occupied_apartments,
                p.remaining_apartments,
                (p.property_type_unit - p.apartments_created) as available_slots
            FROM properties p
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
        $propertyStmt->close();

        $maxCapacity = (int) $propertyData['max_capacity'];
        $apartmentsCreated = (int) $propertyData['apartments_created'];
        $occupiedApartments = (int) $propertyData['occupied_apartments'];
        $availableSlots = (int) $propertyData['available_slots'];
        $remainingApartments = (int) $propertyData['remaining_apartments'];

        logActivity("Property: {$propertyData['name']} ({$propertyCode})");
        logActivity("Current Property Stats:");
        logActivity("  - Max Capacity: {$maxCapacity}");
        logActivity("  - Apartments Created: {$apartmentsCreated}");
        logActivity("  - Occupied Apartments: {$occupiedApartments}");
        logActivity("  - Remaining Apartments (can create): {$remainingApartments}");
        logActivity("  - Available Slots: {$availableSlots}");

        // Check if property has capacity for new apartments
        if ($apartmentsCreated >= $maxCapacity) {
            throw new Exception(
                "Property '{$propertyData['name']}' has reached its maximum capacity of {$maxCapacity} apartments. " .
                "No more apartments can be added.",
                400
            );
        }

        // Check if requested units fit
        if ($unitsToCreate > $availableSlots) {
            throw new Exception(
                "Cannot create {$unitsToCreate} apartments. Property '{$propertyData['name']}' only has {$availableSlots} available slots. " .
                "Current: {$apartmentsCreated}/{$maxCapacity} apartments.",
                400
            );
        }

        // 2. Validate apartment type exists
        $typeStmt = $conn->prepare("SELECT type_name FROM apartment_type WHERE type_id = ? AND status = 1 LIMIT 1");
        $typeStmt->bind_param("i", $typeId);
        $typeStmt->execute();
        $typeStmt->bind_result($apartmentTypeName);

        if (!$typeStmt->fetch()) {
            $typeStmt->close();
            throw new Exception("Apartment type not found or inactive.", 400);
        }
        $typeStmt->close();
        logActivity("Apartment type validation passed: {$apartmentTypeName} (ID: {$typeId})");

        // ------------------------- BULK INSERT APARTMENTS -------------------------
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

        $createdApartments = [];
        $startNumber = $apartmentsCreated + 1;
        $endNumber = $apartmentsCreated + $unitsToCreate;
        
        logActivity("Creating apartments #{$startNumber} through #{$endNumber}");

        for ($i = 0; $i < $unitsToCreate; $i++) {
            $apartmentNumber = $apartmentsCreated + $i + 1;
            
            // Generate unique apartment code
            $apartmentCode = generateApartmentCode($propertyCode, $unitsToCreate, $apartmentNumber);
            
            // Ensure code is unique
            $originalCode = $apartmentCode;
            $counter = 1;
            while (isApartmentCodeExists($conn, $apartmentCode)) {
                $apartmentCode = $originalCode . '-' . str_pad($counter, 2, '0', STR_PAD_LEFT);
                $counter++;
                logActivity("Apartment code conflict, regenerated: {$apartmentCode}");
            }
            
            $occupancy_status = 'NOT OCCUPIED';
            
            $stmt->bind_param(
                "ssiiiddss",
                $apartmentCode,
                $propertyCode,
                $typeId,
                $unitsToCreate,
                $apartmentNumber,
                $rentAmount,
                $securityDeposit,
                $occupancy_status,
                $userId
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create apartment #{$apartmentNumber}: " . $stmt->error, 500);
            }
            
            $newApartmentId = $stmt->insert_id;
            
            $createdApartments[] = [
                'id' => $newApartmentId,
                'code' => $apartmentCode,
                'number' => $apartmentNumber
            ];
            
            logActivity("Apartment #{$apartmentNumber} created - ID: {$newApartmentId}, Code: {$apartmentCode}");
        }
        
        $stmt->close();

        // ------------------------- UPDATE PROPERTY APARTMENT COUNTS -------------------------
        $newApartmentsCreated = $apartmentsCreated + $unitsToCreate;
        
        $updatePropertyStmt = $conn->prepare("
            UPDATE properties 
            SET apartments_created = ?,
                updated_at = NOW(),
                last_updated_by = ?
            WHERE property_code = ?
        ");
        $updatePropertyStmt->bind_param("iss", $newApartmentsCreated, $userId, $propertyCode);
        $updatePropertyStmt->execute();
        $updatePropertyStmt->close();

        logActivity("Property apartments_created updated: {$apartmentsCreated} → {$newApartmentsCreated}");
        logActivity("Remaining apartments (can create): {$maxCapacity} - {$newApartmentsCreated} = " . ($maxCapacity - $newApartmentsCreated));

        // ------------------------- COMMIT TRANSACTION -------------------------
        $conn->commit();
        logActivity("Transaction committed successfully. Created " . count($createdApartments) . " apartments.");

        // Consume CSRF token
        consumeCsrfToken(CSRF_FORM_NAME);

        // ------------------------- SUCCESS RESPONSE -------------------------
        $response = [
            'success' => true,
            'message' => $unitsToCreate . ' apartment(s) added successfully!',
            'apartments_created' => count($createdApartments),
            'apartment_type' => $apartmentTypeName,
            'property_code' => $propertyCode,
            'property_name' => $propertyData['name'],
            'rent_amount' => number_format($rentAmount, 2),
            'security_deposit' => number_format($securityDeposit, 2),
            'apartments' => $createdApartments,
            'property_stats' => [
                'total_capacity' => $maxCapacity,
                'apartments_created_before' => $apartmentsCreated,
                'apartments_created_now' => $newApartmentsCreated,
                'remaining_apartments' => $maxCapacity - $newApartmentsCreated,
                'occupied_apartments' => $occupiedApartments,
                'vacant_apartments' => $newApartmentsCreated - $occupiedApartments,
                'occupancy_rate' => $maxCapacity > 0 ? round(($occupiedApartments / $maxCapacity) * 100, 2) : 0
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        logActivity("==== Multi-Apartment Onboarding Completed Successfully ====");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("Transaction rolled back: " . $e->getMessage());
        throw $e;
    }

} catch (Throwable $e) {
    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;

    logActivity("FATAL ERROR: " . $e->getMessage() .
        " | File: " . $e->getFile() .
        " | Line: " . $e->getLine() .
        " | Code: " . $errorCode);

    $errorMessage = ($errorCode === 500 && strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false)
        ? "An internal error occurred while processing your request."
        : $e->getMessage();

    json_error($errorMessage, $errorCode);
}

/**
 * Generate unique apartment code
 */
function generateApartmentCode($propertyCode, $unit, $apartmentNumber)
{
    $code = $propertyCode . "-APT" . sprintf('%03d', $unit);
    
    if (!empty($apartmentNumber)) {
        $code .= "-" . strtoupper(preg_replace('/[^A-Z0-9]/', '', $apartmentNumber));
    }
    
    $timestamp = date('His');
    $code .= '-' . $timestamp;
    
    return $code;
}

/**
 * Check if apartment code already exists
 */
function isApartmentCodeExists($conn, $apartmentCode)
{
    $checkStmt = $conn->prepare("SELECT apartment_code FROM apartments WHERE apartment_code = ? LIMIT 1");
    $checkStmt->bind_param("s", $apartmentCode);
    $checkStmt->execute();
    $checkStmt->store_result();
    $exists = $checkStmt->num_rows > 0;
    $checkStmt->close();
    return $exists;
}