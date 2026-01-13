<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session
session_start();
$requestId = uniqid('edit_profile_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userId = $_SESSION['user_id'] ?? null;
$userType = $_SESSION['user_type'] ?? null;

// Log request start
logActivity("[EDIT_PROFILE_START] [ID:{$requestId}] [IP:{$ipAddress}] [User:{$userId}] Edit profile request started");

// Check authentication
if (!$userId || $userType !== 'admin') {
    logActivity("[AUTH_FAILED] [ID:{$requestId}] Unauthorized edit profile attempt");
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please login.',
        'code' => 401,
        'request_id' => $requestId
    ]);
    exit();
}

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    logActivity("[DB_CONNECTION_FAILED] [ID:{$requestId}] Database connection error");
    
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Service temporarily unavailable.',
        'code' => 503,
        'request_id' => $requestId
    ]);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("[INVALID_METHOD] [ID:{$requestId}] Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
        'code' => 405,
        'request_id' => $requestId
    ]);
    exit();
}

// Get and validate input
$input = file_get_contents("php://input");
if (empty($input)) {
    logActivity("[EMPTY_REQUEST] [ID:{$requestId}] Empty request body");
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No data received.',
        'code' => 400,
        'request_id' => $requestId
    ]);
    exit();
}

$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logActivity("[JSON_ERROR] [ID:{$requestId}] JSON decode error: " . json_last_error_msg());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data.',
        'code' => 400,
        'request_id' => $requestId
    ]);
    exit();
}

// Log received data (safely)
$logData = $data;
if (isset($logData['password'])) $logData['password'] = '[REDACTED]';
if (isset($logData['secret_answer'])) $logData['secret_answer'] = '[REDACTED]';
if (isset($logData['current_password'])) $logData['current_password'] = '[REDACTED]';
logActivity("[EDIT_DATA_RECEIVED] [ID:{$requestId}] Data: " . json_encode($logData));

try {
    // Validate required fields
    if (!isset($data['current_password'])) {
        throw new Exception('Current password is required for verification');
    }
    
    // Verify current password first
    $currentPassword = trim($data['current_password']);
    if (!$this->verifyCurrentPassword($conn, $userId, $currentPassword)) {
        logActivity("[PASSWORD_VERIFICATION_FAILED] [ID:{$requestId}] Invalid current password for user: {$userId}");
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Current password is incorrect.',
            'code' => 401,
            'request_id' => $requestId
        ]);
        exit();
    }
    
    logActivity("[PASSWORD_VERIFIED] [ID:{$requestId}] Current password verified for user: {$userId}");
    
    // Prepare update data
    $updateData = $this->prepareUpdateData($data);
    $shouldLogout = $this->shouldLogoutAfterUpdate($data);
    
    // Start transaction
    $conn->begin_transaction();
    logActivity("[TRANSACTION_START] [ID:{$requestId}] Starting database transaction");
    
    // Update profile
    $updateResult = $this->updateProfile($conn, $userId, $updateData);
    
    if (!$updateResult['success']) {
        throw new Exception($updateResult['message']);
    }
    
    // Log the profile update
    $this->logProfileUpdate($conn, $userId, $updateData);
    
    // Commit transaction
    $conn->commit();
    logActivity("[TRANSACTION_COMMITTED] [ID:{$requestId}] Profile update committed");
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'Profile updated successfully.',
        'code' => 200,
        'request_id' => $requestId,
        'should_logout' => $shouldLogout,
        'logout_reason' => $shouldLogout ? 'Security settings were updated. Please login again.' : null
    ];
    
    logActivity("[PROFILE_UPDATE_SUCCESS] [ID:{$requestId}] Profile updated for user: {$userId}");
    
    // If logout is required, include logout URL
    if ($shouldLogout) {
        $response['logout_url'] = '../backend/auth/logout.php';
        logActivity("[LOGOUT_REQUIRED] [ID:{$requestId}] Logout required due to security changes");
        
        // Destroy session immediately if logout is required
        session_destroy();
    }
    
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        logActivity("[TRANSACTION_ROLLBACK] [ID:{$requestId}] Transaction rolled back due to error");
    }
    
    logActivity("[PROFILE_UPDATE_ERROR] [ID:{$requestId}] Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update profile: ' . $e->getMessage(),
        'code' => 500,
        'request_id' => $requestId
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("[EDIT_PROFILE_END] [ID:{$requestId}] Request completed");
}

/**
 * Verify current password
 */
function verifyCurrentPassword($conn, $userId, $currentPassword) {
    $query = "SELECT password FROM admin_tbl WHERE unique_id = ? AND status = '1'";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare password verification query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $userId);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute password verification: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return password_verify($currentPassword, $row['password']);
}

/**
 * Prepare update data from request
 */
function prepareUpdateData($data) {
    $allowedFields = [
        'firstname', 'lastname', 'phone', 'address', 'gender',
        'password', 'secret_question', 'secret_answer', 'photo'
    ];
    
    $updateData = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && trim($data[$field]) !== '') {
            $updateData[$field] = trim($data[$field]);
            
            // Special handling for sensitive fields
            if ($field === 'password') {
                // Validate password strength
                $passwordValidation = validatePasswordStrength($data[$field]);
                if (!$passwordValidation['valid']) {
                    throw new Exception('Password does not meet requirements: ' . implode(', ', $passwordValidation['errors']));
                }
                // Hash the password
                $updateData[$field] = password_hash($data[$field], PASSWORD_DEFAULT);
            }
            
            if ($field === 'secret_answer') {
                // You might want to hash secret answers too
                $updateData[$field] = hash('sha256', $data[$field]);
            }
        }
    }
    
    // Validate phone number if provided
    if (isset($updateData['phone'])) {
        if (!preg_match('/^[0-9\-\+\(\)\s]{10,20}$/', $updateData['phone'])) {
            throw new Exception('Invalid phone number format');
        }
    }
    
    // Validate email if trying to change (usually email shouldn't be changed easily)
    if (isset($data['email'])) {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        // Email change might require additional verification
        $updateData['email'] = trim($data['email']);
    }
    
    // Add timestamp
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    $updateData['last_updated_by'] = $GLOBALS['userId'];
    
    if (empty($updateData)) {
        throw new Exception('No valid fields to update');
    }
    
    return $updateData;
}

/**
 * Check if logout is required after update
 */
function shouldLogoutAfterUpdate($data) {
    // Logout if password, secret question, or secret answer is changed
    $sensitiveFields = ['password', 'secret_question', 'secret_answer'];
    
    foreach ($sensitiveFields as $field) {
        if (isset($data[$field]) && trim($data[$field]) !== '') {
            return true;
        }
    }
    
    return false;
}

/**
 * Update profile in database
 */
function updateProfile($conn, $userId, $updateData) {
    // Build SET clause
    $setClause = [];
    $types = '';
    $values = [];
    
    foreach ($updateData as $field => $value) {
        $setClause[] = "{$field} = ?";
        $types .= 's'; // All fields are strings
        $values[] = $value;
    }
    
    if (empty($setClause)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }
    
    $query = "UPDATE admin_tbl SET " . implode(', ', $setClause) . " WHERE unique_id = ?";
    $types .= 'i'; // For user_id parameter
    $values[] = $userId;
    
    logActivity("[UPDATE_QUERY] Preparing query: " . $query);
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare update query: " . $conn->error);
    }
    
    // Bind parameters dynamically
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute update: " . $stmt->error);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    if ($affectedRows === 0) {
        return ['success' => false, 'message' => 'No changes made or user not found'];
    }
    
    return ['success' => true, 'affected_rows' => $affectedRows];
}

/**
 * Log profile update for audit
 */
function logProfileUpdate($conn, $userId, $updateData) {
    // Create audit log entry if audit table exists
    if ($conn->query("SHOW TABLES LIKE 'profile_update_logs'")->num_rows > 0) {
        try {
            $fieldsChanged = array_keys($updateData);
            // Remove sensitive fields from log
            $fieldsChanged = array_filter($fieldsChanged, function($field) {
                return !in_array($field, ['password', 'secret_answer']);
            });
            
            $logQuery = "INSERT INTO profile_update_logs (user_id, user_type, fields_changed, ip_address, updated_at) 
                         VALUES (?, 'admin', ?, ?, NOW())";
            
            $logStmt = $conn->prepare($logQuery);
            if ($logStmt) {
                $ip = $GLOBALS['ipAddress'];
                $fieldsJson = json_encode(array_values($fieldsChanged));
                $logStmt->bind_param("iss", $userId, $fieldsJson, $ip);
                $logStmt->execute();
                $logStmt->close();
            }
        } catch (Exception $e) {
            // Don't throw - audit logging is optional
            logActivity("[AUDIT_LOG_ERROR] Failed to log profile update: " . $e->getMessage());
        }
    }
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/\d/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>_\-]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
?>