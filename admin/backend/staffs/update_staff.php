<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session
session_start();

// Generate request ID for tracking
$requestId = uniqid('staff_update_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$currentUserId = $_SESSION['unique_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

// Status mapping
const STATUS_MAP = [
    '0' => 'Inactive',
    '1' => 'Active', 
    '2' => 'Suspended'
];

const ALLOWED_ACTIONS = ['update_all', 'restore', 'delete'];
const ALLOWED_ROLES = ['Admin', 'Super Admin'];
const SUPER_ADMIN_ONLY = ['restore', 'delete'];

try {
    logActivity("[STAFF_UPDATE_START] [ID:{$requestId}] [IP:{$ipAddress}] Initiated by User:{$currentUserId}, Role:{$userRole}");

    // ==================== AUTHENTICATION & AUTHORIZATION ====================
    if (!$currentUserId || !$userRole) {
        throw new Exception("Unauthorized. Please login.", 401);
    }
    
    if (!in_array($userRole, ALLOWED_ROLES, true)) {
        throw new Exception("Access denied. Admin privileges required.", 403);
    }

    // ==================== REQUEST VALIDATION ====================
    $input = file_get_contents("php://input");
    if (empty($input)) {
        throw new Exception("No data received.", 400);
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data: " . json_last_error_msg(), 400);
    }

    // Log received data (excluding sensitive info)
    $logData = $data;
    if (isset($logData['secret_answer'])) $logData['secret_answer'] = '[REDACTED]';
    logActivity("[REQUEST_DATA] [ID:{$requestId}] Data received: " . json_encode($logData));

    if (!isset($data['unique_id']) || !is_numeric($data['unique_id'])) {
        throw new Exception("Valid Staff ID is required.", 400);
    }

    $staffId = (int)$data['unique_id'];
    $actionType = $data['action_type'] ?? 'update_all';
    
    if (!in_array($actionType, ALLOWED_ACTIONS, true)) {
        throw new Exception("Invalid action type: {$actionType}", 400);
    }
    
    // Super Admin check for sensitive actions
    if (in_array($actionType, SUPER_ADMIN_ONLY, true) && $userRole !== 'Super Admin') {
        throw new Exception("Access Denied! Unauthorized Access to {$actionType} User.", 403);
    }

    logActivity("[PROCESSING] [ID:{$requestId}] Processing {$actionType} for Staff ID:{$staffId}");

    // ==================== HELPER FUNCTIONS ====================
    function sanitizeInput($input) {
        if (is_string($input)) {
            $input = trim($input);
            $input = stripslashes($input);
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        }
        return $input;
    }

    function verifySecretAnswer($conn, $staffId, $secretAnswer, $requestId) {
        $storedHash = '';
        $query = "SELECT secret_answer FROM admin_tbl WHERE unique_id = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database error during secret verification");
        }
        
        $stmt->bind_param("i", $staffId);
        $stmt->execute();
        $stmt->bind_result($storedHash);
        $stmt->fetch();
        $stmt->close();
        
        if (empty($storedHash)) {
            logActivity("[SECRET_MISSING] [ID:{$requestId}] No secret answer found for staff {$staffId}");
            throw new Exception("No secret answer found for this staff");
        }
        
        if (!password_verify($secretAnswer, $storedHash)) {
            logActivity("[SECRET_INCORRECT] [ID:{$requestId}] Incorrect secret answer for staff {$staffId}");
            throw new Exception("Incorrect secret answer");
        }
        
        logActivity("[SECRET_VERIFIED] [ID:{$requestId}] Secret answer verified for staff {$staffId}");
        return true;
    }

    function processPendingReactivationRequest($conn, $staffId, $currentUserId, $requestId, $actionType) {
        logActivity("[REACTIVATION_CHECK] [ID:{$requestId}] Checking pending reactivation requests for staff:{$staffId}");
        
        $query = "SELECT id, request_reason FROM account_reactivation_requests 
                  WHERE user_id = ? AND user_type = 'admin' AND status = 'pending' 
                  ORDER BY created_at DESC LIMIT 1";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logActivity("[REACTIVATION_QUERY_ERROR] [ID:{$requestId}] Query prep failed: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("i", $staffId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            logActivity("[NO_PENDING_REACTIVATION] [ID:{$requestId}] No pending requests for staff {$staffId}");
            return false;
        }
        
        $row = $result->fetch_assoc();
        $requestIdInTable = $row['id'];
        $requestReason = $row['request_reason'] ?? 'No reason provided';
        $stmt->close();
        
        logActivity("[PENDING_REACTIVATION_FOUND] [ID:{$requestId}] Request ID:{$requestIdInTable} for staff {$staffId}");
        
        // Update the reactivation request
        $updateQuery = "UPDATE account_reactivation_requests SET 
            status = 'approved', 
            reviewed_by = ?, 
            review_notes = 'Auto Restore via {$actionType}', 
            review_timestamp = NOW(),
            updated_at = NOW() 
            WHERE id = ?";
        
        $updateStmt = $conn->prepare($updateQuery);
        if (!$updateStmt) {
            logActivity("[REACTIVATION_UPDATE_PREPARE_FAILED] [ID:{$requestId}] " . $conn->error);
            return false;
        }
        
        $updateStmt->bind_param("ii", $currentUserId, $requestIdInTable);
        
        if (!$updateStmt->execute()) {
            logActivity("[REACTIVATION_UPDATE_FAILED] [ID:{$requestId}] " . $updateStmt->error);
            $updateStmt->close();
            return false;
        }
        
        $affectedRows = $updateStmt->affected_rows;
        $updateStmt->close();
        
        logActivity("[REACTIVATION_UPDATED] [ID:{$requestId}] Updated request ID:{$requestIdInTable}, Affected:{$affectedRows}");
        logActivity("[REACTIVATION_DETAILS] [ID:{$requestId}] Reason:'{$requestReason}', Reviewer:{$currentUserId}");
        
        return $requestIdInTable;
    }

    function validateUpdateData($data, $currentStatus, $staffId, $requestId) {
        $validatedData = [];
        $errors = [];
        
        $fieldValidations = [
            'firstname' => function($value) {
                $value = sanitizeInput($value);
                if (empty($value)) return ['error' => 'First name is required'];
                if (strlen($value) > 100) return ['error' => 'First name is too long (max 100 characters)'];
                if (!preg_match('/^[a-zA-Z\s\-\'\.]+$/', $value)) return ['error' => 'First name contains invalid characters'];
                return ['value' => $value];
            },
            'lastname' => function($value) {
                $value = sanitizeInput($value);
                if (empty($value)) return ['error' => 'Last name is required'];
                if (strlen($value) > 100) return ['error' => 'Last name is too long (max 100 characters)'];
                if (!preg_match('/^[a-zA-Z\s\-\'\.]+$/', $value)) return ['error' => 'Last name contains invalid characters'];
                return ['value' => $value];
            },
            'email' => function($value) {
                $value = sanitizeInput($value);
                if (empty($value)) return ['error' => 'Email is required'];
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return ['error' => 'Invalid email format'];
                if (strlen($value) > 255) return ['error' => 'Email is too long'];
                return ['value' => strtolower($value)];
            },
            'phone_number' => function($value) {
                $value = sanitizeInput($value);
                if (empty($value)) return ['error' => 'Phone number is required'];
                $cleanPhone = preg_replace('/[^0-9\+]/', '', $value);
                if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
                    return ['error' => 'Phone number must be 10-15 digits'];
                }
                if (!preg_match('/^[0-9\+]{10,15}$/', $cleanPhone)) {
                    return ['error' => 'Invalid phone number format'];
                }
                return ['value' => $cleanPhone];
            },
            'address' => function($value) {
                $value = sanitizeInput($value);
                if (empty($value)) return ['error' => 'Address is required'];
                if (strlen($value) > 500) return ['error' => 'Address is too long (max 500 characters)'];
                return ['value' => $value];
            },
            'gender' => function($value) {
                $value = sanitizeInput($value);
                $validGenders = ['Male', 'Female', 'Other'];
                if (!in_array($value, $validGenders)) return ['error' => 'Invalid gender selection'];
                return ['value' => $value];
            },
            'status' => function($value) use ($currentStatus, $staffId, $requestId) {
                $value = sanitizeInput($value);
                $validStatuses = ['0', '1', '2'];
                if (!in_array($value, $validStatuses)) return ['error' => 'Invalid status'];
                
                if ($value === $currentStatus) {
                    logActivity("[STATUS_NO_CHANGE] [ID:{$requestId}] Status unchanged for staff {$staffId}: {$currentStatus}");
                    return ['value' => null, 'skip' => true];
                }
                
                return ['value' => (int)$value];
            },
            'secret_answer' => function($value) use ($staffId, $requestId) {
                if (empty($value)) return ['error' => 'Secret answer is required for verification'];
                $value = sanitizeInput($value);
                return ['value' => $value, 'needs_verification' => true];
            }
        ];

        foreach ($fieldValidations as $field => $validator) {
            if (isset($data[$field])) {
                $result = $validator($data[$field]);
                if (isset($result['error'])) {
                    $errors[$field] = $result['error'];
                } elseif (!isset($result['skip'])) {
                    $validatedData[$field] = $result;
                }
            } elseif ($field === 'secret_answer' && ($actionType === 'update_all' || isset($data['status']))) {
                $errors[$field] = 'Secret answer is required for verification when updating sensitive data';
            }
        }

        if (!empty($errors)) {
            logActivity("[VALIDATION_ERRORS] [ID:{$requestId}] " . json_encode($errors));
            throw new Exception(json_encode(['errors' => $errors]), 400);
        }

        return $validatedData;
    }

    function checkForDuplicates($conn, $staffId, $validatedData, $requestId) {
        if (!isset($validatedData['email']) && !isset($validatedData['phone_number'])) {
            return [];
        }
        
        $checkEmail = $validatedData['email']['value'] ?? '';
        $checkPhone = $validatedData['phone_number']['value'] ?? '';
        
        $query = "SELECT unique_id, email, phone FROM admin_tbl 
                  WHERE (email = ? OR phone = ?) AND unique_id != ? AND status != '2' 
                  LIMIT 2";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database query preparation failed");
        }
        
        $stmt->bind_param("ssi", $checkEmail, $checkPhone, $staffId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $errors = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['email'] === $checkEmail) {
                $errors['email'] = 'Email already exists';
                logActivity("[DUPLICATE_EMAIL] [ID:{$requestId}] Email {$checkEmail} used by user {$row['unique_id']}");
            }
            if ($row['phone'] === $checkPhone) {
                $errors['phone_number'] = 'Phone number already exists';
                logActivity("[DUPLICATE_PHONE] [ID:{$requestId}] Phone {$checkPhone} used by user {$row['unique_id']}");
            }
        }
        
        $stmt->close();
        return $errors;
    }

    // ==================== GET CURRENT STAFF STATUS ====================
    logActivity("[FETCH_CURRENT_STATUS] [ID:{$requestId}] Fetching current status for staff:{$staffId}");
    $currentQuery = "SELECT status, firstname, lastname, email FROM admin_tbl WHERE unique_id = ?";
    $currentStmt = $conn->prepare($currentQuery);
    
    if (!$currentStmt) {
        throw new Exception("Failed to prepare current status query");
    }
    
    $currentStmt->bind_param("i", $staffId);
    $currentStmt->execute();
    $currentStmt->bind_result($currentStatus, $currentFirstName, $currentLastName, $currentEmail);
    $currentStmt->fetch();
    $currentStmt->close();
    
    if (!$currentStatus && $currentStatus !== '0') {
        throw new Exception("Staff not found.", 404);
    }
    
    $currentStatusText = STATUS_MAP[$currentStatus] ?? 'Unknown';
    logActivity("[CURRENT_STATUS] [ID:{$requestId}] Staff {$staffId} status: {$currentStatus} ({$currentStatusText})");

    // ==================== HANDLE RESTORE ACTION ====================
    if ($actionType === 'restore') {
        if ($currentStatus === '1') {
            throw new Exception("Staff is already active.", 400);
        }
        $actionTypes = "Account Restore";
        // Process any pending reactivation requests
        $reactivationRequestId = processPendingReactivationRequest($conn, $staffId, $currentUserId, $requestId, $actionTypes);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $updateQuery = "UPDATE admin_tbl SET status = '1', updated_at = NOW(), last_updated_by = ? WHERE unique_id = ?";
            $updateStmt = $conn->prepare($updateQuery);
            
            if (!$updateStmt) {
                throw new Exception("Failed to prepare restore query: " . $conn->error);
            }
            
            $updateStmt->bind_param("ii", $currentUserId, $staffId);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to execute restore query: " . $updateStmt->error);
            }
            
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            logActivity("[RESTORE_SUCCESS] [ID:{$requestId}] Staff {$staffId} restored by {$currentUserId}. Affected:{$affectedRows}");
            logActivity("[AUDIT_RESTORE] [ID:{$requestId}] {$currentUserId} restored {$currentFirstName} {$currentLastName} from {$currentStatusText} to Active");
            
            $response = [
                "success" => true,
                "message" => "Staff has been successfully restored to active status.",
                "affected_rows" => $affectedRows,
                "previous_status" => $currentStatusText,
                "new_status" => "Active",
                "request_id" => $requestId
            ];
            
            if ($reactivationRequestId) {
                $response['pending_reactivation_processed'] = true;
                $response['reactivation_request_id'] = $reactivationRequestId;
                logActivity("[RESTORE_WITH_REACTIVATION] [ID:{$requestId}] Auto-approved request ID:{$reactivationRequestId}");
            }
            
            echo json_encode($response);
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    // ==================== HANDLE DELETE ACTION ====================
    if ($actionType === 'delete') {
        if ($currentStatus === '0') {
            throw new Exception("Staff is already inactive.", 400);
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $updateQuery = "UPDATE admin_tbl SET status = '0', updated_at = NOW(), last_updated_by = ? WHERE unique_id = ?";
            $updateStmt = $conn->prepare($updateQuery);
            
            if (!$updateStmt) {
                throw new Exception("Failed to prepare delete query: " . $conn->error);
            }
            
            $updateStmt->bind_param("ii", $currentUserId, $staffId);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to execute delete query: " . $updateStmt->error);
            }
            
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            logActivity("[DELETE_SUCCESS] [ID:{$requestId}] Staff {$staffId} marked inactive by {$currentUserId}. Affected:{$affectedRows}");
            logActivity("[AUDIT_DELETE] [ID:{$requestId}] {$currentUserId} deleted {$currentFirstName} {$currentLastName} from {$currentStatusText} to Inactive");
            
            echo json_encode([
                "success" => true,
                "message" => "Staff has been successfully marked as inactive.",
                "affected_rows" => $affectedRows,
                "previous_status" => $currentStatusText,
                "new_status" => "Inactive",
                "request_id" => $requestId
            ]);
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    // ==================== HANDLE UPDATE_ALL ACTION ====================
    // Validate and prepare update data
    $validatedData = validateUpdateData($data, $currentStatus, $staffId, $requestId);
    
    // Check for duplicates
    $duplicateErrors = checkForDuplicates($conn, $staffId, $validatedData, $requestId);
    if (!empty($duplicateErrors)) {
        throw new Exception(json_encode(['errors' => $duplicateErrors]), 409);
    }
    
    // Verify secret answer if required
    if (isset($validatedData['secret_answer'])) {
        verifySecretAnswer($conn, $staffId, $validatedData['secret_answer']['value'], $requestId);
        unset($validatedData['secret_answer']); // Remove from update data
    }
    
    // Check if status is changing from Inactive (0) to Active (1)
    $isReactivating = false;
    $reactivationRequestId = null;
    $actionTypes = "Account Restore on Update";
    
    if (isset($validatedData['status']) && $validatedData['status']['value'] == 1 && $currentStatus == 0) {
        $isReactivating = true;
        logActivity("[STATUS_REACTIVATION] [ID:{$requestId}] Staff {$staffId} is being reactivated via update_all");
        
        // Process pending reactivation requests
        $reactivationRequestId = processPendingReactivationRequest($conn, $staffId, $currentUserId, $requestId, $actionTypes);
    }
    
    // Prepare update query
    $updateFields = [];
    $updateValues = [];
    $types = '';
    
    // Add audit fields
    $updateFields[] = 'updated_at = NOW()';
    $updateFields[] = 'last_updated_by = ?';
    $updateValues[] = $currentUserId;
    $types .= 'i';
    
    // Add updatable fields
    $fieldMappings = [
        'firstname' => 'firstname',
        'lastname' => 'lastname', 
        'email' => 'email',
        'phone_number' => 'phone',
        'address' => 'address',
        'gender' => 'gender',
        'status' => 'status'
    ];
    
    $changedFields = [];
    foreach ($fieldMappings as $inputField => $dbField) {
        if (isset($validatedData[$inputField]) && $validatedData[$inputField]['value'] !== null) {
            $updateFields[] = "{$dbField} = ?";
            $updateValues[] = $validatedData[$inputField]['value'];
            $types .= 's';
            $changedFields[] = $inputField;
        }
    }
    
    // Check if there are actual changes
    if (count($updateFields) <= 2) {
        logActivity("[UPDATE_NO_CHANGE] [ID:{$requestId}] No data changes for staff {$staffId}");
        echo json_encode([
            "success" => true,
            "message" => "No changes were made (data identical).",
            "affected_rows" => 0,
            "request_id" => $requestId
        ]);
        exit();
    }
    
    // Add WHERE clause parameter
    $updateValues[] = $staffId;
    $types .= 'i';
    
    // Build and execute query
    $sql = "UPDATE admin_tbl SET " . implode(', ', $updateFields) . " WHERE unique_id = ?";
    logActivity("[UPDATE_QUERY] [ID:{$requestId}] Query: {$sql}");
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare update query: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$updateValues);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute update: " . $stmt->error);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    // Prepare response
    if ($affectedRows > 0) {
        logActivity("[UPDATE_SUCCESS] [ID:{$requestId}] Staff {$staffId} updated. Affected:{$affectedRows}");
        logActivity("[CHANGED_FIELDS] [ID:{$requestId}] Fields: " . implode(', ', $changedFields));
        
        // Log status change if applicable
        if (isset($validatedData['status'])) {
            $newStatus = $validatedData['status']['value'];
            $newStatusText = STATUS_MAP[$newStatus] ?? 'Unknown';
            logActivity("[STATUS_CHANGE] [ID:{$requestId}] Staff {$staffId}: {$currentStatusText} → {$newStatusText}");
        }
        
        $response = [
            "success" => true,
            "message" => "Staff record updated successfully.",
            "affected_rows" => $affectedRows,
            "updated_fields" => $changedFields,
            "request_id" => $requestId
        ];
        
        if ($isReactivating) {
            $response['is_reactivation'] = true;
            $response['reactivation_processed'] = (bool)$reactivationRequestId;
            if ($reactivationRequestId) {
                $response['reactivation_request_id'] = $reactivationRequestId;
            }
        }
        
        echo json_encode($response);
    } else {
        logActivity("[UPDATE_NO_CHANGE] [ID:{$requestId}] No changes made for staff {$staffId}");
        echo json_encode([
            "success" => true,
            "message" => "No changes were made (data identical).",
            "affected_rows" => 0,
            "request_id" => $requestId
        ]);
    }

} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    $message = $e->getMessage();
    
    // Check if message contains JSON errors
    $decodedMessage = json_decode($message, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decodedMessage['errors'])) {
        $errorData = $decodedMessage;
        $message = "Validation errors";
    } else {
        $errorData = ['error' => $message];
    }
    
    logActivity("[EXCEPTION] [ID:{$requestId}] Code:{$code}, Message:{$message}, Trace:" . $e->getTraceAsString());
    
    http_response_code($code);
    echo json_encode([
        "success" => false,
        "message" => $message,
        "request_id" => $requestId,
        ...$errorData
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("[STAFF_UPDATE_END] [ID:{$requestId}] Process completed");
}