<?php
// update_apartment.php
header('Content-Type: application/json; charset=utf-8');

// Define constants
define('CSRF_FORM_NAME', 'update_apartment');
define('MIN_RENT_AMOUNT', 0);
define('MAX_RENT_AMOUNT', 999999999.99);
define('MIN_SECURITY_DEPOSIT', 0);
define('MAX_SECURITY_DEPOSIT', 999999999.99);

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

logActivity("==== Apartment Update Request Started ====");

try {
    // ================= RATE LIMITING =================
    rateLimit("update_apartment", 10, 60);
    logActivity("Rate limit check passed for apartment update");

    // ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to update_apartment");
        json_error("Not logged in.", 401);
    }

    $adminId = (int) $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? 'User';

    // ================= METHOD VALIDATION =================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        json_error("Invalid request method. Use POST.", 405);
    }

    // ================= VALIDATE JSON INPUT =================
    $rawInput = file_get_contents("php://input");
    if (empty($rawInput)) {
        logActivity("Empty request body from user {$adminId}");
        json_error("Request body is empty.", 400);
    }

    $input = json_decode($rawInput, true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON input from user {$adminId}: " . json_last_error_msg());
        json_error("Invalid JSON input: " . json_last_error_msg(), 400);
    }

    // Log non-sensitive input
    $logInput = $input;
    logActivity("REQUEST: Apartment update | User={$adminId} | Role={$adminRole} | Input: " . json_encode($logInput));

    // ================= NORMALIZE & VALIDATE INPUTS =================
    $apartment_code = isset($input['apartment_id']) ? trim($input['apartment_id']) : '';
    $property_code = isset($input['property_code']) ? trim($input['property_code']) : '';
    $apartment_type_id = isset($input['apartment_type_id']) ? (int) $input['apartment_type_id'] : 0;
    $apartment_type_unit = isset($input['apartment_type_unit']) ? (int) $input['apartment_type_unit'] : 0;
    $status = isset($input['status']) ? (int) $input['status'] : null;
    $action_type = isset($input['action_type']) ? trim($input['action_type']) : 'update_all';
    
    $rent_amount = 0.00;
    $security_deposit = 0.00;

    // ================= SANITIZE NUMBER FIELDS WITH COMMAS =================
    if ($action_type === 'update_all') {
        try {
            $rent_amount = sanitizeNumberWithCommas(
                $input['rent_amount'] ?? null,
                false, // required
                MIN_RENT_AMOUNT,
                MAX_RENT_AMOUNT
            );
            
            $security_deposit = sanitizeNumberWithCommas(
                $input['security_deposit'] ?? null,
                true, // allow null/empty
                MIN_SECURITY_DEPOSIT,
                MAX_SECURITY_DEPOSIT
            );
            
            // If security deposit is null (not provided), set to 0
            if ($security_deposit === null) {
                $security_deposit = 0.00;
            }
            
            logActivity("Sanitized amounts - Rent: {$rent_amount}, Deposit: {$security_deposit}");
            
        } catch (Exception $e) {
            logActivity("Number validation error: " . $e->getMessage());
            json_error($e->getMessage(), 400);
        }
    }

    // ================= BASIC VALIDATION =================
    $errors = [];

    if (empty($apartment_code)) {
        $errors[] = "apartment_id is required.";
    }

    if (!preg_match('/^[A-Za-z0-9_\-]{4,50}$/', $apartment_code)) {
        $errors[] = "Invalid apartment code format.";
    }

    // Validate action_type
    $allowedActions = ['update_all', 'delete', 'restore'];
    if (!in_array($action_type, $allowedActions, true)) {
        $errors[] = "Invalid action type. Allowed: " . implode(', ', $allowedActions);
    }

    // For full updates, validate required fields
    if ($action_type === 'update_all') {
        if ($status === null || !in_array($status, [0, 1], true)) {
            $errors[] = "Invalid status. Must be 0 (Inactive) or 1 (Active).";
        }
        
        if (empty($property_code)) {
            $errors[] = "property_code is required.";
        }
        
        // Number validation already done by sanitizeNumberWithCommas
        // Additional business rule validation
        if ($rent_amount < MIN_RENT_AMOUNT) {
            $errors[] = "Rent amount cannot be negative.";
        }
        
        if ($security_deposit < MIN_SECURITY_DEPOSIT) {
            $errors[] = "Security deposit cannot be negative.";
        }
    }

    if (!empty($errors)) {
        logActivity("Validation errors: " . implode(" | ", $errors));
        json_error(implode(" ", $errors), 400);
    }

    // ================= START TRANSACTION =================
    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        logActivity("TRANSACTION STARTED for apartment: {$apartment_code}");
    } catch (Exception $e) {
        logActivity("TRANSACTION FAILED to start: " . $e->getMessage());
        throw new Exception("Failed to start database transaction.", 500);
    }

    try {
        // ================= VERIFY APARTMENT EXISTS =================
        $check = $conn->prepare("
            SELECT a.apartment_code, a.status, a.property_code, a.occupancy_status,
                   p.property_code as prop_code, p.status as prop_status
            FROM apartments a
            LEFT JOIN properties p ON a.property_code = p.property_code
            WHERE a.apartment_code = ?
            FOR UPDATE
        ");
        
        if (!$check) {
            throw new Exception("Database prepare failed: " . $conn->error);
        }
        
        $check->bind_param("s", $apartment_code);
        $check->execute();
        $check->bind_result($existing_code, $existing_status, $existing_property_code, 
                           $occupancy_status, $prop_code, $prop_status);
        $apartmentExists = $check->fetch();
        $check->close();

        if (!$apartmentExists) {
            throw new Exception("Apartment not found.", 404);
        }

        logActivity("Apartment found: {$apartment_code} | Property: {$existing_property_code} | Status: {$existing_status}");

        // ================= PERMISSION CHECKS =================
        // For delete/restore actions, require Super Admin
        if (in_array($action_type, ['delete', 'restore']) && $adminRole !== "Super Admin") {
            throw new Exception("You do not have permission to {$action_type} apartments.", 403);
        }

        // Check if property exists and is active (for context)
        if (!empty($prop_code) && $prop_status != 1) {
            logActivity("WARNING: Parent property is inactive: {$prop_code}");
            // Continue anyway - apartment might need update even if property is inactive
        }

        // ================= OCCUPANCY CHECK =================
        // Prevent updates on occupied apartments unless specifically allowed
        if ($action_type === 'update_all' && $occupancy_status == 1) {
            logActivity("WARNING: Apartment {$apartment_code} is currently occupied");
            // You might want to restrict certain updates for occupied apartments
        }

        // ================= ROUTE ACTION =================
        $resultMessage = '';
        $auditDetails = [];

        switch ($action_type) {
            case 'update_all':
                $resultMessage = handleFullUpdate(
                    $conn,
                    $apartment_code,
                    $property_code,
                    $apartment_type_id,
                    $apartment_type_unit,
                    $rent_amount,      // Already sanitized
                    $security_deposit, // Already sanitized
                    $adminId,
                    $status,
                    $adminRole
                );
                $auditDetails = [
                    'action' => 'update',
                    'apartment_code' => $apartment_code,
                    'updated_fields' => array_keys(array_filter($input, function($v) {
                        return $v !== null && $v !== '';
                    }))
                ];
                break;

            case 'delete':
                // Check if apartment is occupied before deletion
                if ($occupancy_status == 1) {
                    throw new Exception("Cannot delete an occupied apartment. Please vacate the apartment first.", 400);
                }
                $resultMessage = handleStatusUpdate($conn, $apartment_code, 0, $adminId, $adminRole, "delete");
                $auditDetails = ['action' => 'delete', 'apartment_code' => $apartment_code];
                break;

            case 'restore':
                $resultMessage = handleStatusUpdate($conn, $apartment_code, 1, $adminId, $adminRole, "restore");
                $auditDetails = ['action' => 'restore', 'apartment_code' => $apartment_code];
                break;
        }

        // ================= COMMIT TRANSACTION =================
        $conn->commit();
        $transactionStarted = false;
        
        logActivity("TRANSACTION COMMITTED: Apartment {$apartment_code} updated by user {$adminId}");

        // ================= SUCCESS RESPONSE =================
        $response = [
            'success' => true,
            'message' => $resultMessage,
            'apartment_code' => $apartment_code,
            'action' => $action_type,
            'rent_amount' => number_format($rent_amount, 2), // Format for response
            'security_deposit' => number_format($security_deposit, 2),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        
        logActivity("==== Apartment Update Request Completed Successfully ====");
        exit;

    } catch (Throwable $e) {
        // Rollback transaction
        $conn->rollback();
        $transactionStarted = false;
        logActivity("Transaction rolled back: " . $e->getMessage());
        throw $e; // Re-throw for outer catch block
    }

} catch (Throwable $e) {
    // ================= ERROR HANDLING =================
    if (isset($transactionStarted) && $transactionStarted === true) {
        try {
            $conn->rollback();
            logActivity("TRANSACTION ROLLED BACK due to error");
        } catch (Throwable $rollbackError) {
            logActivity("ROLLBACK ERROR: " . $rollbackError->getMessage());
        }
    }

    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    
    logActivity("EXCEPTION: " . $e->getMessage() . 
                " | File: " . $e->getFile() . 
                " | Line: " . $e->getLine());

    // Don't expose internal errors in production
    $errorMessage = ($errorCode === 500 && strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false)
        ? "An internal error occurred while processing the request."
        : $e->getMessage();

    json_error($errorMessage, $errorCode);
}

/**
 * Full update handler with comprehensive validation
 */
function handleFullUpdate($conn, $apartment_code, $property_code, 
                         $apartment_type_id, $apartment_type_unit, $rent_amount, 
                         $security_deposit, $adminId, $status, $adminRole) {
    
    $errors = [];

    // Property code validation
    if (empty($property_code)) {
        $errors[] = "Property Code is required.";
    } elseif (!preg_match('/^[A-Za-z0-9_\-]{4,50}$/', $property_code)) {
        $errors[] = "Invalid property code format.";
    } else {
        // Verify property exists and is active
        $propStmt = $conn->prepare("SELECT property_code FROM properties WHERE property_code = ? AND status = 1 LIMIT 1");
        if ($propStmt) {
            $propStmt->bind_param("s", $property_code);
            $propStmt->execute();
            $propStmt->store_result();
            if ($propStmt->num_rows === 0) {
                $errors[] = "Property not found or inactive.";
            }
            $propStmt->close();
        }
    }

    // Apartment type validation
    if ($apartment_type_id <= 0) {
        $errors[] = "Invalid apartment type selected.";
    } else {
        $typeStmt = $conn->prepare("SELECT type_name FROM apartment_type WHERE type_id = ? AND status = 1 LIMIT 1");
        if ($typeStmt) {
            $typeStmt->bind_param("i", $apartment_type_id);
            $typeStmt->execute();
            $typeStmt->store_result();
            if ($typeStmt->num_rows === 0) {
                $errors[] = "Apartment type not found or inactive.";
            }
            $typeStmt->close();
        }
    }

    // Apartment type unit validation
    if ($apartment_type_unit < 0) {
        $errors[] = "Apartment unit cannot be negative.";
    }

    // Rent validation (already sanitized, but double-check)
    if ($rent_amount < MIN_RENT_AMOUNT) {
        $errors[] = "Rent amount cannot be negative.";
    }
    if ($rent_amount > MAX_RENT_AMOUNT) {
        $errors[] = "Rent amount exceeds maximum allowed value.";
    }

    // Security deposit validation (already sanitized, but double-check)
    if ($security_deposit < MIN_SECURITY_DEPOSIT) {
        $errors[] = "Security deposit cannot be negative.";
    }
    if ($security_deposit > MAX_SECURITY_DEPOSIT) {
        $errors[] = "Security deposit exceeds maximum allowed value.";
    }

    // Status validation
    if (!in_array($status, [0, 1], true)) {
        $errors[] = "Invalid status. Must be 0 or 1.";
    }

    // Permission check for deactivation
    if ($status == 0 && $adminRole !== "Super Admin") {
        $errors[] = "You do not have permission to deactivate apartments.";
    }

    // If any validation errors, throw exception
    if (!empty($errors)) {
        logActivity("VALIDATION ERRORS for apartment {$apartment_code}: " . implode(", ", $errors));
        throw new Exception(implode(" ", $errors), 400);
    }

    // ================= PERFORM UPDATE =================
    $stmt = $conn->prepare("
        UPDATE apartments 
        SET 
            apartment_type_id = ?,
            apartment_type_unit = ?,
            rent_amount = ?,
            security_deposit = ?,
            updated_at = NOW(),
            last_updated_by = ?,
            status = ?
        WHERE apartment_code = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error, 500);
    }

    $types = "siddiis";
    $stmt->bind_param(
        $types,
        $apartment_type_id,
        $apartment_type_unit,
        $rent_amount,
        $security_deposit,
        $adminId,
        $status,
        $apartment_code
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        
        // Handle specific MySQL errors
        if (strpos($error, 'foreign key constraint') !== false) {
            throw new Exception("Referenced entity (property or type) not found.", 400);
        }
        
        throw new Exception("Database update failed: " . $error, 500);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        logActivity("WARNING: No rows affected for apartment {$apartment_code} update - data may be unchanged");
        // This might be okay if data didn't change
    }

    logActivity("SUCCESS: Full update for apartment {$apartment_code} | Rent: {$rent_amount} | Deposit: {$security_deposit} | Rows affected: {$affected}");

    return "Apartment updated successfully!";
}

/**
 * Status update handler (delete/restore)
 */
function handleStatusUpdate($conn, $apartment_code, $new_status, $adminId, $adminRole, $action) {
    $occupancy_status = '';
    // Additional permission check
    if ($adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized {$action} attempt by user {$adminId}");
        throw new Exception("You do not have permission to {$action} apartments.", 403);
    }

    // Check occupancy status for delete action
    if ($action === "delete") {
        $checkOccupancy = $conn->prepare("
            SELECT occupancy_status FROM apartments 
            WHERE apartment_code = ?
            LIMIT 1
        ");
        if ($checkOccupancy) {
            $checkOccupancy->bind_param("s", $apartment_code);
            $checkOccupancy->execute();
            $checkOccupancy->bind_result($occupancy_status);
            $checkOccupancy->fetch();
            $checkOccupancy->close();
            
            if ($occupancy_status == 1) {
                throw new Exception("Cannot delete an occupied apartment.", 400);
            }
        }
    }

    // Unified query for both delete and restore
    $query = "
        UPDATE apartments 
        SET status = ?, 
            last_updated_by = ?, 
            updated_at = NOW()
        WHERE apartment_code = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error, 500);
    }

    $stmt->bind_param("iis", $new_status, $adminId, $apartment_code);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Database update failed: " . $error, 500);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Apartment {$apartment_code} {$action}d by user {$adminId} | Rows affected: {$affected}");

    return "Apartment " . ($action === "delete" ? "deactivated" : "restored") . " successfully!";
}
