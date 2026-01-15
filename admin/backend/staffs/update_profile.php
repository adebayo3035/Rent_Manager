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
$requestId = uniqid('edit_staff_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userId = $_SESSION['unique_id'] ?? null;
$userType = $_SESSION['role'] ?? null;

// Log request start
logActivity("[STAFF_EDIT_START] [ID:{$requestId}] [IP:{$ipAddress}] [UserID:{$userId}] Staff profile edit request started");

// Check authentication
if (!$userId) {
    logActivity("[AUTH_FAILED_STAFF] [ID:{$requestId}] Unauthorized Staff edit attempt - UserID:{$userId}, Type:{$userType}");
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please login.',
        'code' => 401,
        'request_id' => $requestId
    ]);
    exit();
}

$allowedUserTypes = ['Admin', 'Super Admin'];
if (!in_array($userType, $allowedUserTypes, true)) {
    logActivity("[AUTH_FAILED_STAFF] [ID:{$requestId}] Unauthorized Staff edit attempt - UserID:{$userId}, Type:{$userType}");
    
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Admin privileges required.',
        'code' => 403,
        'request_id' => $requestId
    ]);
    exit();
}

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    logActivity("[DB_CONNECTION_FAILED] [ID:{$requestId}] Database connection object not available");
    
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Service temporarily unavailable.',
        'code' => 503,
        'request_id' => $requestId
    ]);
    exit();
}

if ($conn->connect_error) {
    logActivity("[DB_CONNECT_ERROR] [ID:{$requestId}] Database connection failed: " . $conn->connect_error);
    
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
    $method = $_SERVER['REQUEST_METHOD'];
    logActivity("[INVALID_METHOD_STAFF] [ID:{$requestId}] Invalid request method: {$method}");
    
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Please use POST.',
        'code' => 405,
        'request_id' => $requestId
    ]);
    exit();
}

// Initialize data from appropriate source
$data = [];
$files = $_FILES;

if (!empty($_POST)) {
    // FormData request (with file upload)
    $data = $_POST;
    logActivity("[FORM_DATA_RECEIVED] [ID:{$requestId}] Received FormData with " . count($data) . " fields");
    
    // Log files if any
    if (!empty($files)) {
        logActivity("[FILES_RECEIVED] [ID:{$requestId}] Received " . count($files) . " file(s)");
    }
} else {
    // Try JSON request
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    
    if (empty($input)) {
        logActivity("[EMPTY_REQUEST_STAFF] [ID:{$requestId}] Empty request body received");
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No data received.',
            'code' => 400,
            'request_id' => $requestId
        ]);
        exit();
    }
    else{
        logActivity("[REQUEST_STAFF] [ID:{$requestId}] Received Inpus are". $data );
    }
    
    
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        logActivity("[JSON_ERROR_STAFF] [ID:{$requestId}] JSON decode error: {$jsonError}");
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON data: ' . $jsonError,
            'code' => 400,
            'request_id' => $requestId
        ]);
        exit();
    }
    
    logActivity("[JSON_DATA_RECEIVED] [ID:{$requestId}] Received JSON data with " . count($data) . " fields");
}

// Validate we have data
if (empty($data) && empty($files)) {
    logActivity("[NO_DATA_RECEIVED] [ID:{$requestId}] No data received");
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No data received.',
        'code' => 400,
        'request_id' => $requestId
    ]);
    exit();
}

// Log received data (safely - hide sensitive data)
$logData = $data;
if (isset($logData['current_password'])) $logData['current_password'] = '[REDACTED]';
if (isset($logData['password'])) $logData['password'] = '[REDACTED]';
if (isset($logData['secret_answer'])) $logData['secret_answer'] = '[REDACTED]';
logActivity("[DATA_RECEIVED_STAFF] [ID:{$requestId}] Data fields: " . json_encode(array_keys($logData)));

// Define functions
function verifyCurrentPassword($conn, $userId, $currentPassword) {
    $query = "SELECT password FROM admin_tbl WHERE unique_id = ? AND status = '1'";
    logActivity("[PASSWORD_QUERY] Preparing query: {$query} for user ID: {$userId}");
    
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
        logActivity("[USER_NOT_FOUND_PASSWORD] No active Staff found with ID: {$userId}");
        $stmt->close();
        return false;
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $passwordHash = $row['password'];
    $isValid = password_verify($currentPassword, $passwordHash);
    
    logActivity("[PASSWORD_CHECK_RESULT] Password verification result: " . ($isValid ? 'VALID' : 'INVALID'));
    
    return $isValid;
}

function checkEmailExists($conn, $email, $excludeUserId) {
    $query = "SELECT unique_id, email FROM admin_tbl WHERE email = ? AND unique_id != ? AND status = '1' LIMIT 1";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare email check query: " . $conn->error);
    }
    
    $stmt->bind_param("si", $email, $excludeUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    
    if ($exists) {
        $row = $result->fetch_assoc();
        logActivity("[EMAIL_EXISTS_CHECK] Email '{$email}' already in use by user ID: {$row['unique_id']}");
    }
    
    $stmt->close();
    return $exists;
}

function checkPhoneExists($conn, $phone, $excludeUserId) {
    if (empty($phone)) return false;
    
    $query = "SELECT unique_id, phone FROM admin_tbl WHERE phone = ? AND unique_id != ? AND status = '1' LIMIT 1";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare phone check query: " . $conn->error);
    }
    
    $stmt->bind_param("si", $phone, $excludeUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    
    if ($exists) {
        $row = $result->fetch_assoc();
        logActivity("[PHONE_EXISTS_CHECK] Phone '{$phone}' already in use by user ID: {$row['unique_id']}");
    }
    
    $stmt->close();
    return $exists;
}

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
    
    // Check for common weak passwords
    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123', 'password123'];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

function shouldLogoutAfterUpdate($data) {
    // Logout if password, secret question, or secret answer is changed
    $sensitiveFields = ['password', 'secret_question', 'secret_answer'];
    
    foreach ($sensitiveFields as $field) {
        if (isset($data[$field]) && !empty(trim($data[$field]))) {
            logActivity("[SENSITIVE_FIELD_CHANGED] Field '{$field}' will trigger logout");
            return true;
        }
    }
    
    return false;
}

function getCurrentPhoto($conn, $userId) {
    $query = "SELECT photo FROM admin_tbl WHERE unique_id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare photo query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['photo'];
}

function handlePhotoUpload($photoFile, $userId, $conn) {
    global $requestId;
    
    $allowed_ext = ['jpeg', 'jpg', 'png', 'gif'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    
    $img_name = basename($photoFile['name']);
    $tmp_name = $photoFile['tmp_name'];
    $img_size = $photoFile['size'];
    $img_type = $photoFile['type'];
    $ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));
    
    logActivity("[PHOTO_UPLOAD_START] [ID:{$requestId}] Processing photo: {$img_name}, Size: {$img_size}, Type: {$img_type}");
    
    // Validate file type
    if (!in_array($ext, $allowed_ext) || !in_array($img_type, $allowed_types)) {
        throw new Exception('Invalid image file. Allowed types: jpg, jpeg, png, gif.');
    }
    
    // Validate file size (2MB max)
    if ($img_size > 2 * 1024 * 1024) {
        throw new Exception('File is too large. Maximum size is 2MB.');
    }
    
    // Generate unique filename with user ID and timestamp
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $file_hash = hash_file('sha256', $tmp_name);
    $file_name = "staff_{$userId}_{$timestamp}_{$random}.{$ext}";
    
    // Create upload directory if it doesn't exist
    $upload_dir = __DIR__ . '/admin_photos/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new Exception('Server error: Could not create upload directory.');
    }
    
    $upload_file = $upload_dir . $file_name;
    
    // Check if photo already exists in database (by content hash)
    $checkQuery = "SELECT COUNT(*) as count FROM admin_tbl WHERE photo LIKE ? AND unique_id != ?";
    $checkStmt = $conn->prepare($checkQuery);
    if ($checkStmt) {
        $searchPattern = "%{$file_hash}%";
        $checkStmt->bind_param("si", $searchPattern, $userId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkRow = $checkResult->fetch_assoc();
        $checkStmt->close();
        
        if ($checkRow['count'] > 0) {
            throw new Exception('This photo is already being used by another user.');
        }
    }
    
    // Get current photo to delete later
    $currentPhoto = getCurrentPhoto($conn, $userId);
    
    // Move uploaded file
    if (!move_uploaded_file($tmp_name, $upload_file)) {
        throw new Exception('Failed to upload image. Please try again.');
    }
    
    logActivity("[PHOTO_UPLOAD_SUCCESS] [ID:{$requestId}] Photo uploaded successfully: {$file_name}");
    
    return [
        'filename' => $file_name,
        'path' => $upload_file,
        'hash' => $file_hash,
        'old_photo' => $currentPhoto
    ];
}

function deleteOldPhoto($oldPhotoFilename) {
    if (empty($oldPhotoFilename)) {
        return false;
    }
    
    $upload_dir = __DIR__ . '/admin_photos/';
    $oldPhotoPath = $upload_dir . $oldPhotoFilename;
    
    // Check if file exists and is in the correct directory (security check)
    if (file_exists($oldPhotoPath) && strpos(realpath($oldPhotoPath), realpath($upload_dir)) === 0) {
        if (unlink($oldPhotoPath)) {
            logActivity("[OLD_PHOTO_DELETED] Successfully deleted old photo: {$oldPhotoFilename}");
            return true;
        } else {
            logActivity("[OLD_PHOTO_DELETE_FAILED] Failed to delete old photo: {$oldPhotoFilename}");
            return false;
        }
    }
    
    return false;
}

function prepareUpdateData($conn, $data, $files, $userId) {
    global $requestId;
    $updateData = [];
    
    // Personal Information Fields
    $personalFields = ['firstname', 'lastname', 'phone', 'email', 'address', 'gender'];
    
    foreach ($personalFields as $field) {
        if (isset($data[$field]) && trim($data[$field]) !== '') {
            $value = trim($data[$field]);
            
            // Field-specific validation
            switch ($field) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('Invalid email address format');
                    }
                    // Check if email already exists (excluding current user)
                    if (checkEmailExists($conn, $value, $userId)) {
                        throw new Exception('Email address is already in use by another account');
                    }
                    $updateData[$field] = $value;
                    logActivity("[EMAIL_VALIDATION] [ID:{$requestId}] Email validated for update: {$value}");
                    break;
                    
                case 'phone':
                    // Basic phone validation
                    $cleanPhone = preg_replace('/[^0-9]/', '', $value);
                    if (strlen($cleanPhone) < 10) {
                        throw new Exception('Phone number must be at least 10 digits');
                    }
                    // Check if phone already exists (excluding current user)
                    if (checkPhoneExists($conn, $value, $userId)) {
                        throw new Exception('Phone number is already in use by another account');
                    }
                    $updateData[$field] = $value;
                    logActivity("[PHONE_VALIDATION] [ID:{$requestId}] Phone validated for update: {$value}");
                    break;
                    
                case 'gender':
                    $validGenders = ['Male', 'Female'];
                    if (!in_array($value, $validGenders)) {
                        throw new Exception('Invalid gender selection');
                    }
                    $updateData[$field] = $value;
                    break;
                    
                default:
                    // Basic length validation for text fields
                    if (strlen($value) > 255) {
                        throw new Exception("{$field} is too long (max 255 characters)");
                    }
                    $updateData[$field] = $value;
            }
        }
    }
    
    // Security Fields (Password)
    if (isset($data['password']) && trim($data['password']) !== '') {
        $newPassword = trim($data['password']);
        
        // Validate password strength
        $passwordValidation = validatePasswordStrength($newPassword);
        if (!$passwordValidation['valid']) {
            throw new Exception('Password requirements not met: ' . implode(', ', $passwordValidation['errors']));
        }
        
        // Hash the new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hashedPassword === false) {
            throw new Exception('Failed to hash password');
        }
        
        $updateData['password'] = $hashedPassword;
        logActivity("[PASSWORD_UPDATE] [ID:{$requestId}] New password prepared for hashing");
    }
    
    // Secret Question & Answer
    if (isset($data['secret_question']) && trim($data['secret_question']) !== '') {
        $secretQuestion = trim($data['secret_question']);
        
        // Validate secret question (should be from allowed list)
        $allowedQuestions = [
            "What was your first pet's name?",
            "What is your mother's maiden name?",
            "What city were you born in?",
            "What is your favorite book?",
            "What was your first car?"
        ];
        
        if (!in_array($secretQuestion, $allowedQuestions)) {
            throw new Exception('Invalid secret question selected');
        }
        
        $updateData['secret_question'] = $secretQuestion;
        logActivity("[SECRET_QUESTION_UPDATE] [ID:{$requestId}] Secret question updated");
    }
    
    if (isset($data['secret_answer']) && trim($data['secret_answer']) !== '') {
        $secretAnswer = trim($data['secret_answer']);
        
        if (strlen($secretAnswer) < 3) {
            throw new Exception('Secret answer must be at least 3 characters');
        }
        
        // Hash the secret answer using bcrypt
        $hashedAnswer = password_hash($secretAnswer, PASSWORD_DEFAULT);
        $updateData['secret_answer'] = $hashedAnswer;
        logActivity("[SECRET_ANSWER_UPDATE] [ID:{$requestId}] Secret answer hashed and prepared for update");
    }
    
    // Handle profile photo upload
    $photoData = null;
    if (isset($files['photo']) && $files['photo']['error'] === UPLOAD_ERR_OK) {
        logActivity("[PHOTO_PROCESSING_START] [ID:{$requestId}] Processing uploaded photo");
        $photoData = handlePhotoUpload($files['photo'], $userId, $conn);
        $updateData['photo'] = $photoData['filename'];
    } elseif (isset($data['photo']) && trim($data['photo']) !== '') {
        // Handle photo URL/string if sent via JSON
        $updateData['photo'] = trim($data['photo']);
        logActivity("[PHOTO_UPDATE] [ID:{$requestId}] Photo path updated via JSON");
    }
    
    // Add timestamp fields
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    $updateData['last_updated_by'] = $userId;
    
    if (empty($updateData)) {
        throw new Exception('No valid fields to update');
    }
    
    logActivity("[UPDATE_DATA_PREPARED] [ID:{$requestId}] Fields prepared: " . implode(', ', array_keys($updateData)));
    
    return [
        'update_data' => $updateData,
        'photo_data' => $photoData
    ];
}

function updateStaffProfile($conn, $userId, $updateData, $photoData = null) {
    global $requestId;
    
    // Build SET clause
    $setClause = [];
    $types = '';
    $values = [];
    
    foreach ($updateData as $field => $value) {
        // Skip non-database fields
        if ($field === 'confirm_password') continue;
        
        $setClause[] = "{$field} = ?";
        $types .= 's'; // All fields are strings
        $values[] = $value;
    }
    
    if (empty($setClause)) {
        return [
            'success' => false, 
            'message' => 'No fields to update',
            'photo_data' => null
        ];
    }
    
    $query = "UPDATE admin_tbl SET " . implode(', ', $setClause) . " WHERE unique_id = ?";
    $types .= 'i'; // For user_id parameter
    $values[] = $userId;
    
    logActivity("[UPDATE_QUERY_STAFF] [ID:{$requestId}] Query: " . $query);
    
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
    
    logActivity("[UPDATE_EXECUTED] [ID:{$requestId}] Query executed. Affected rows: {$affectedRows}");
    
    if ($affectedRows === 0) {
        return [
            'success' => false, 
            'message' => 'No changes made or Staff not found',
            'photo_data' => $photoData
        ];
    }
    
    return [
        'success' => true, 
        'affected_rows' => $affectedRows,
        'photo_data' => $photoData
    ];
}

// Main execution
try {
    // Validate required fields
    if (!isset($data['current_password']) || trim($data['current_password']) === '') {
        logActivity("[MISSING_CURRENT_PASSWORD] [ID:{$requestId}] Current password not provided");
        throw new Exception('Current password is required for verification');
    }
    
    $currentPassword = trim($data['current_password']);
    
    // Verify current password first
    logActivity("[PASSWORD_VERIFICATION_START] [ID:{$requestId}] Verifying current password for Staff ID: {$userId}");
    $passwordValid = verifyCurrentPassword($conn, $userId, $currentPassword);
    
    if (!$passwordValid) {
        logActivity("[PASSWORD_VERIFICATION_FAILED_STAFF] [ID:{$requestId}] Invalid current password for Staff ID: {$userId}");
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Current password is incorrect.',
            'code' => 401,
            'request_id' => $requestId
        ]);
        exit();
    }
    
    logActivity("[PASSWORD_VERIFIED_STAFF] [ID:{$requestId}] Current password verified for Staff ID: {$userId}");
    
    // Prepare update data with validation
    logActivity("[DATA_PREPARATION_START] [ID:{$requestId}] Preparing update data");
    $preparedData = prepareUpdateData($conn, $data, $files, $userId);
    $updateData = $preparedData['update_data'];
    $photoData = $preparedData['photo_data'];
    
    // Check if logout is required (password or secret Q/A changed)
    $shouldLogout = shouldLogoutAfterUpdate($data);
    
    // Start transaction
    $conn->begin_transaction();
    logActivity("[TRANSACTION_START_STAFF] [ID:{$requestId}] Starting database transaction for Staff ID: {$userId}");
    
    // Update Staff profile
    logActivity("[PROFILE_UPDATE_START] [ID:{$requestId}] Updating Staff profile for ID: {$userId}");
    $updateResult = updateStaffProfile($conn, $userId, $updateData, $photoData);
    
    if (!$updateResult['success']) {
        throw new Exception('Profile update failed: ' . $updateResult['message']);
    }
    
    // Delete old photo if new photo was uploaded successfully
    if ($photoData && isset($photoData['old_photo']) && !empty($photoData['old_photo'])) {
        logActivity("[OLD_PHOTO_CLEANUP_START] [ID:{$requestId}] Attempting to delete old photo: {$photoData['old_photo']}");
        deleteOldPhoto($photoData['old_photo']);
    }
    
    // Commit transaction
    $conn->commit();
    logActivity("[TRANSACTION_COMMITTED_STAFF] [ID:{$requestId}] Profile update committed for Staff ID: {$userId}. Affected rows: " . $updateResult['affected_rows']);
    
    // Prepare success response
    $response = [
        'success' => true,
        'message' => 'Profile updated successfully.',
        'code' => 200,
        'request_id' => $requestId,
        'should_logout' => $shouldLogout,
        'updated_fields' => array_keys($updateData)
    ];
    
    if ($photoData) {
        $response['photo_uploaded'] = true;
        $response['photo_filename'] = $photoData['filename'];
        logActivity("[PHOTO_UPDATE_SUCCESS] [ID:{$requestId}] Profile photo updated to: {$photoData['filename']}");
    }
    
    // In the success response section:
if ($shouldLogout) {
    $logoutId = $userId; // Same as unique_id
    $response['logout_reason'] = 'Security settings were updated. Please login again.';
    $response['logout_id'] = $logoutId; // Send logout_id in response
    
    logActivity("[LOGOUT_REQUIRED_STAFF] [ID:{$requestId}] Logout required for Staff ID: {$userId} due to security changes");
    
    // Destroy session immediately if logout is required
    // asession_destroy();
    logActivity("[SESSION_DESTROYED] [ID:{$requestId}] Session destroyed for Staff ID: {$userId}");
}
    
    logActivity("[PROFILE_UPDATE_SUCCESS_STAFF] [ID:{$requestId}] Profile updated successfully for Staff ID: {$userId}");
    
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        logActivity("[TRANSACTION_ROLLBACK_STAFF] [ID:{$requestId}] Transaction rolled back due to error: " . $e->getMessage());
    }
    
    // Clean up uploaded file if transaction failed
    if (isset($photoData) && isset($photoData['path']) && file_exists($photoData['path'])) {
        unlink($photoData['path']);
        logActivity("[UPLOAD_CLEANUP] [ID:{$requestId}] Cleared uploaded file after error: {$photoData['path']}");
    }
    
    logActivity("[PROFILE_UPDATE_ERROR_STAFF] [ID:{$requestId}] Error for Staff ID: {$userId}: " . $e->getMessage());
    
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
        logActivity("[DB_CONNECTION_CLOSED] [ID:{$requestId}] Database connection closed");
    }
    logActivity("[STAFF_EDIT_END] [ID:{$requestId}] Request processing completed");
}
