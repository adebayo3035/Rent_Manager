<?php
// update_property_type.php
header('Content-Type: application/json; charset=utf-8');

// Define constants
define('MAX_NAME_LENGTH', 255);
define('MAX_ADDRESS_LENGTH', 500);
define('MAX_PHONE_LENGTH', 20);
define('CSRF_FORM_NAME', 'update_property');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

logActivity("==== Property Update Request Started ====");

try {
    // ================= RATE LIMITING =================
    rateLimit("update_property", 10, 60);
    logActivity("Rate limit check passed for property update");

    // ================= AUTH CHECK =================
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY: Unauthorized access attempt to update_property_type");
        json_error("Not logged in.", 401);
    }

    $adminId = (int) $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? 'User';

    // ================= METHOD VALIDATION =================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        json_error("Invalid request method. Use POST.", 405);
    }

    // // ================= CSRF PROTECTION =================
    // $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    // if (!validateCsrfToken($csrfToken, CSRF_FORM_NAME)) {
    //     logActivity("CSRF token validation failed for user {$adminId}");
    //     json_error("Security token invalid or expired.", 403);
    // }
    // logActivity("CSRF token validation passed");

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

    // ================= SANITIZE & VALIDATE INPUTS =================
    $property_code     = isset($input['property_id']) ? trim($input['property_id']) : '';
    $property_name     = isset($input['property_name']) ? trim($input['property_name']) : '';
    $agent_code        = isset($input['agent_code']) ? trim($input['agent_code']) : '';
    $client_code       = isset($input['client_code']) ? trim($input['client_code']) : '';
    $property_type_id  = isset($input['property_type_id']) ? (int)$input['property_type_id'] : 0;
    $property_type_unit= isset($input['property_type_unit']) ? (int)$input['property_type_unit'] : 0;
    $country           = isset($input['country']) ? trim($input['country']) : '';
    $state             = isset($input['state']) ? trim($input['state']) : '';
    $city              = isset($input['city']) ? trim($input['city']) : '';
    $address           = isset($input['address']) ? trim($input['address']) : '';
    $contact_name      = isset($input['contact_name']) ? trim($input['contact_name']) : '';
    $contact_phone     = isset($input['contact_phone']) ? trim($input['contact_phone']) : '';
    $status            = isset($input['status']) ? (int)$input['status'] : null;
    $action_type       = isset($input['action_type']) ? trim($input['action_type']) : 'update_all';

    // Log non-sensitive input summary
    $logInput = $input;
    unset($logInput['contact_phone']);
    logActivity("REQUEST: Property update | User={$adminId} | Role={$adminRole} | Input: " . json_encode($logInput));

    // ================= BASIC VALIDATION =================
    if (empty($property_code)) {
        logActivity("ERROR: Missing property_id from user {$adminId}");
        json_error("property_id is required.", 400);
    }

    // Validate property code format
    if (!preg_match('/^[A-Za-z0-9_\-]{4,50}$/', $property_code)) {
        logActivity("ERROR: Invalid property_code format from user {$adminId}");
        json_error("Invalid property code format.", 400);
    }

    // Validate action_type
    $allowedActions = ['update_all', 'delete', 'restore'];
    if (!in_array($action_type, $allowedActions, true)) {
        logActivity("ERROR: Invalid action type '{$action_type}' by user {$adminId}");
        json_error("Invalid action type. Allowed: " . implode(', ', $allowedActions), 400);
    }

    // ================= START TRANSACTION =================
    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        logActivity("TRANSACTION STARTED for property: {$property_code}");
    } catch (Exception $e) {
        logActivity("TRANSACTION FAILED to start: " . $e->getMessage());
        throw new Exception("Failed to start database transaction.", 500);
    }

    // ================= VERIFY PROPERTY EXISTS =================
    $check = $conn->prepare("SELECT property_code, status FROM properties WHERE property_code = ? FOR UPDATE");
    if (!$check) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $check->bind_param("s", $property_code);
    $check->execute();
    $check->bind_result($existing_code, $existing_status);
    $propertyExists = $check->fetch();
    $check->close();

    if (!$propertyExists) {
        $conn->rollback();
        $transactionStarted = false;
        logActivity("ERROR: Property {$property_code} not found (User {$adminId})");
        json_error("Property not found.", 404);
    }

    // ================= PERMISSION CHECKS =================
    // For delete/restore actions, require Super Admin
    if (in_array($action_type, ['delete', 'restore']) && $adminRole !== "Super Admin") {
        $conn->rollback();
        $transactionStarted = false;
        logActivity("SECURITY: Unauthorized {$action_type} attempt by user {$adminId} (Role: {$adminRole})");
        json_error("You do not have permission to {$action_type} properties.", 403);
    }

    // For status changes (deactivation), require Super Admin
    if ($action_type === 'update_all' && $status === 0 && $adminRole !== "Super Admin") {
        $conn->rollback();
        $transactionStarted = false;
        logActivity("SECURITY: Unauthorized deactivate attempt by user {$adminId}");
        json_error("You do not have permission to deactivate properties.", 403);
    }

    // ================= ROUTE ACTION =================
    $resultMessage = '';
    $auditDetails = [];

    switch ($action_type) {
        case 'update_all':
            $resultMessage = handleFullUpdate(
                $conn,
                $property_code,
                $property_name,
                $agent_code,
                $client_code,
                $property_type_id,
                $property_type_unit,
                $address,
                $city,
                $state,
                $country,
                $contact_name,
                $contact_phone,
                $adminId,
                $status,
                $adminRole
            );
            // $auditDetails = [
            //     'action' => 'update',
            //     'property_code' => $property_code,
            //     'updated_fields' => array_keys(array_filter($input))
            // ];
            break;

        case 'delete':
            $resultMessage = handleStatusUpdate($conn, $property_code, 0, $adminId, $adminRole, "delete");
            // $auditDetails = ['action' => 'delete', 'property_code' => $property_code];
            break;

        case 'restore':
            $resultMessage = handleStatusUpdate($conn, $property_code, 1, $adminId, $adminRole, "restore");
            // $auditDetails = ['action' => 'restore', 'property_code' => $property_code];
            break;
    }

    // ================= CREATE AUDIT LOG =================
    // try {
    //     $auditSql = "INSERT INTO property_audit_log 
    //         (property_code, action, performed_by, details, ip_address, user_agent)
    //         VALUES (?, ?, ?, ?, ?, ?)";
        
    //     $auditStmt = $conn->prepare($auditSql);
    //     if ($auditStmt) {
    //         $detailsJson = json_encode($auditDetails);
    //         $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    //         $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
            
    //         $auditStmt->bind_param(
    //             'ssisss',
    //             $property_code,
    //             $action_type,
    //             $adminId,
    //             $detailsJson,
    //             $ipAddress,
    //             $userAgent
    //         );
    //         $auditStmt->execute();
    //         $auditStmt->close();
    //         logActivity("Audit log created for property {$property_code}");
    //     }
    // } catch (Exception $e) {
    //     logActivity("WARNING: Failed to create audit log: " . $e->getMessage());
    //     // Don't fail the whole request if audit fails
    // }

    // ================= COMMIT TRANSACTION =================
    $conn->commit();
    $transactionStarted = false;
    
    logActivity("TRANSACTION COMMITTED: Property {$property_code} updated by user {$adminId}");

    // ================= SUCCESS RESPONSE =================
    $response = [
        'success' => true,
        'message' => $resultMessage,
        'property_code' => $property_code,
        'action' => $action_type,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    
    logActivity("==== Property Update Request Completed Successfully ====");
    exit;

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
                " | Line: " . $e->getLine() . 
                " | Trace: " . $e->getTraceAsString());

    // Don't expose internal errors in production
    $errorMessage = ($errorCode === 500 && strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false)
        ? "An internal error occurred while processing the request."
        : $e->getMessage();

    json_error($errorMessage, $errorCode);
}

/**
 * Full update handler with comprehensive validation
 */
function handleFullUpdate($conn, $property_code, $property_name, $agent_code, $client_code, 
                         $property_type_id, $property_type_unit, $address, $city, $state, 
                         $country, $contact_name, $contact_phone, $adminId, $status, $adminRole) {
    
    // ================= FIELD VALIDATION =================
    $errors = [];

    // Property name validation
    if (empty($property_name)) {
        $errors[] = "Property Name is required.";
    } elseif (strlen($property_name) > MAX_NAME_LENGTH) {
        $errors[] = "Property Name cannot exceed " . MAX_NAME_LENGTH . " characters.";
    }

    // Status validation
    if (!in_array($status, [0, 1], true)) {
        $errors[] = "Invalid status. Must be 0 or 1.";
    }

    // Agent code validation
    if (!empty($agent_code)) {
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $agent_code)) {
            $errors[] = "Invalid agent code format.";
        } else {
            // Verify agent exists
            $stmt = $conn->prepare("SELECT 1 FROM agents WHERE agent_code = ? AND status = 1 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $agent_code);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 0) {
                    $errors[] = "Agent not found or inactive.";
                }
                $stmt->close();
            }
        }
    }

    // Client code validation
    if (!empty($client_code)) {
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $client_code)) {
            $errors[] = "Invalid client code format.";
        } else {
            // Verify client exists
            $stmt = $conn->prepare("SELECT 1 FROM clients WHERE client_code = ? AND status = 1 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $client_code);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 0) {
                    $errors[] = "Client not found or inactive.";
                }
                $stmt->close();
            }
        }
    }

    // Property type validation
    if ($property_type_id <= 0) {
        $errors[] = "Invalid property type selected.";
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM property_type WHERE type_id = ? AND status = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $property_type_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $errors[] = "Property type not found or inactive.";
            }
            $stmt->close();
        }
    }

    // Property type unit validation
    if ($property_type_unit < 0) {
        $errors[] = "Property type unit cannot be negative.";
    }

    // Address validation
    if (!empty($address) && strlen($address) > MAX_ADDRESS_LENGTH) {
        $errors[] = "Address cannot exceed " . MAX_ADDRESS_LENGTH . " characters.";
    }

    // Phone validation
    if (!empty($contact_phone)) {
        $normalizedPhone = preg_replace('/\D+/', '', $contact_phone);
        if (strlen($normalizedPhone) < 7 || strlen($normalizedPhone) > MAX_PHONE_LENGTH) {
            $errors[] = "Contact phone must be between 7 and " . MAX_PHONE_LENGTH . " digits.";
        } else {
            $contact_phone = $normalizedPhone;
        }
    }

    // Check for duplicate property name (excluding current property and soft-deleted)
    $dup = $conn->prepare("
        SELECT property_code FROM properties 
        WHERE name = ? AND property_code != ? AND status = 1
        LIMIT 1
    ");
    if ($dup) {
        $dup->bind_param("ss", $property_name, $property_code);
        $dup->execute();
        $dup->store_result();
        if ($dup->num_rows > 0) {
            $errors[] = "Another active property already uses this name.";
        }
        $dup->close();
    }

    // If any validation errors, throw exception
    if (!empty($errors)) {
        logActivity("VALIDATION ERRORS for property {$property_code}: " . implode(", ", $errors));
        throw new Exception(implode(" ", $errors), 400);
    }

    // ================= PERFORM UPDATE =================
    $stmt = $conn->prepare("
        UPDATE properties 
        SET agent_code = ?, 
            client_code = ?, 
            property_type_id = ?, 
            property_type_unit = ?, 
            name = ?, 
            address = ?, 
            city = ?, 
            state = ?, 
            country = ?, 
            contact_name = ?, 
            contact_phone = ?, 
            updated_at = NOW(), 
            last_updated_by = ?, 
            status = ?
        WHERE property_code = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }

    // FIXED: Correct parameter binding order
    $types = "ssiisssssssiis";
    $stmt->bind_param(
        $types,
        $agent_code,
        $client_code,
        $property_type_id,
        $property_type_unit,
        $property_name,
        $address,
        $city,
        $state,
        $country,
        $contact_name,
        $contact_phone,
        $adminId,
        $status,
        $property_code
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Database update failed: " . $error, 500);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        logActivity("WARNING: No rows affected for property {$property_code} update");
        // This might be okay if data didn't change, but log it
    }

    logActivity("SUCCESS: Full update for property {$property_code} | Rows affected: {$affected}");

    return "Property updated successfully!";
}

/**
 * Status update handler (delete/restore)
 */
function handleStatusUpdate($conn, $property_code, $new_status, $adminId, $adminRole, $action) {
    
    // Additional permission check (belt and suspenders)
    if ($adminRole !== "Super Admin") {
        logActivity("SECURITY: Unauthorized {$action} attempt by user {$adminId}");
        throw new Exception("You do not have permission to {$action} properties.", 403);
    }

    // Check if property has active dependencies (e.g., apartments, tenants)
    if ($action === "delete") {
        $checkDeps = $conn->prepare("
            SELECT 1 FROM apartments 
            WHERE property_code = ? AND occupancy_status = 1 
            LIMIT 1
        ");
        if ($checkDeps) {
            $checkDeps->bind_param("s", $property_code);
            $checkDeps->execute();
            $checkDeps->store_result();
            if ($checkDeps->num_rows > 0) {
                $checkDeps->close();
                throw new Exception("Cannot delete property with occupied apartments.", 400);
            }
            $checkDeps->close();
        }
    }

    $query = "
        UPDATE properties 
        SET status = ?, 
            last_updated_by = ?, 
            updated_at = NOW()
        WHERE property_code = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }

    $stmt->bind_param("iis", $new_status, $adminId, $property_code);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Database update failed: " . $error, 500);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("SUCCESS: Property {$property_code} {$action}d by user {$adminId} | Rows affected: {$affected}");

    return "Property " . ($action === "delete" ? "deactivated" : "restored") . " successfully!";
}