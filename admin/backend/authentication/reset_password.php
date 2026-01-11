<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

// Decode input with error handling
$input = file_get_contents("php://input");
if (empty($input)) {
    logActivity("[PASSWORD_RESET] Empty request body received");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No input data received']);
    exit();
}

$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    logActivity("[PASSWORD_RESET] Invalid JSON payload: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

logActivity("[PASSWORD_RESET] Payload decoded successfully");
// Helper function to return and log error
function respondWithError($message, $logMsg = '', $httpCode = 400)
{
    http_response_code($httpCode);
    if ($logMsg) {
        logActivity($logMsg);
    }
    echo json_encode([
        'success' => false, 
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// Password strength validation with detailed messages
function validatePassword($password)
{
    $minLength = 8;
    $errors = [];
    
    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least $minLength characters long";
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
    
    if (preg_match('/\s/', $password)) {
        $errors[] = "Password cannot contain spaces";
    }
    
    // Check for common weak passwords
    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123'];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }
    
    return empty($errors) ? true : $errors;
}

// Check if user exceeded daily reset attempts
function checkResetAttempts($conn, $email)
{
    try {
        $stmt = $conn->prepare("SELECT reset_attempts, last_attempt_date FROM admin_password_reset_attempts WHERE email = ? AND DATE(last_attempt_date) = CURDATE()");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $stmt->bind_param("s", $email);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($row = $result->fetch_assoc()) {
            if ($row['reset_attempts'] >= 3) {
                $lastAttempt = date('H:i', strtotime($row['last_attempt_date']));
                logActivity("Reset attempt limit reached for $email. Last attempt at $lastAttempt");
                return [
                    'success' => false, 
                    'message' => 'You have exceeded the maximum password reset attempts for today. Please try again tomorrow.',
                    'attempts' => $row['reset_attempts'],
                    'last_attempt' => $lastAttempt
                ];
            }
        }
        logActivity("Reset attempt limit pass for $email. $email");
        
        return ['success' => true];
        
    } catch (Exception $e) {
        logActivity("Database error in checkResetAttempts: " . $e->getMessage());
        return ['success' => true]; // Allow to continue on database error
    }
}

// Check if user account is temporarily locked
function checkLockStatus($conn, $unique_id)
{
    try {
        $stmt = $conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $unique_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($row = $result->fetch_assoc()) {
            $attempts = (int)$row['attempts'];
            $locked_until = new DateTime($row['locked_until']);
            $now = new DateTime();
            
            if ($attempts >= 3 && $now < $locked_until) {
                $remaining = $now->diff($locked_until);
                $minutes = $remaining->i;
                $seconds = $remaining->s;
                
                logActivity("Account locked for ID $unique_id. Try again in $minutes minutes, $seconds seconds");
                
                return [
                    'success' => false, 
                    'message' => "Your account is temporarily locked due to too many failed attempts. Please try again in $minutes minutes and $seconds seconds.",
                    'locked_until' => $row['locked_until']
                ];
            }
        }
        logActivity("Lock attempt limit Passed for User:  $unique_id.");
        return ['success' => true];
        
    } catch (Exception $e) {
        logActivity("Database error in checkLockStatus: " . $e->getMessage());
        return ['success' => true]; // Allow to continue on database error
    }
}

// Update/reset daily reset attempt tracker
function updateResetAttempts($conn, $email)
{
    try {
        // Start transaction
        $conn->begin_transaction();
        
        $stmt = $conn->prepare("SELECT reset_attempts FROM admin_password_reset_attempts WHERE email = ? AND DATE(last_attempt_date) = CURDATE()");
        if (!$stmt) {
            throw new Exception("Failed to prepare SELECT statement: " . $conn->error);
        }
        
        $stmt->bind_param("s", $email);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute SELECT: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($row = $result->fetch_assoc()) {
            $attempts = $row['reset_attempts'] + 1;
            $stmt = $conn->prepare("UPDATE admin_password_reset_attempts SET reset_attempts = ?, last_attempt_date = NOW() WHERE email = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare UPDATE statement: " . $conn->error);
            }
            $stmt->bind_param("is", $attempts, $email);
        } else {
            $attempts = 1;
            $stmt = $conn->prepare("INSERT INTO admin_password_reset_attempts (email, reset_attempts, last_attempt_date) VALUES (?, ?, NOW())");
            if (!$stmt) {
                throw new Exception("Failed to prepare INSERT statement: " . $conn->error);
            }
            $stmt->bind_param("si", $email, $attempts);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute update/insert: " . $stmt->error);
        }
        
        $stmt->close();
        $conn->commit();
        
        logActivity("Reset attempts updated to $attempts for $email");
        return $attempts;
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        logActivity("Database error in updateResetAttempts for $email: " . $e->getMessage());
        return false;
    }
}

// Perform password update
function updatePassword($conn, $email, $hashedPassword, $unique_id)
{
    try {
        $conn->begin_transaction();
        
        // Update password
        $stmt = $conn->prepare("UPDATE admin_tbl SET password = ?, last_updated_by = ?, updated_at = NOW() WHERE email = ? AND unique_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare password update statement: " . $conn->error);
        }
        
        $stmt->bind_param("sisi", $hashedPassword, $unique_id, $email, $unique_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update password: " . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if ($affectedRows === 0) {
            throw new Exception("No rows affected - user not found or already updated");
        }
        
        // Reset attempt counter
        $stmtReset = $conn->prepare("UPDATE admin_password_reset_attempts SET reset_attempts = 0, last_attempt_date = NOW() WHERE email = ?");
        if (!$stmtReset) {
            throw new Exception("Failed to prepare reset attempts statement: " . $conn->error);
        }
        
        $stmtReset->bind_param("s", $email);
        if (!$stmtReset->execute()) {
            throw new Exception("Failed to reset attempt counter: " . $stmtReset->error);
        }
        
        $stmtReset->close();
        
        // Clear login attempts
        $stmtClearLogin = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
        if ($stmtClearLogin) {
            $stmtClearLogin->bind_param("i", $unique_id);
            $stmtClearLogin->execute();
            $stmtClearLogin->close();
        }
        
        $conn->commit();
        
        logActivity("Password reset successful for $email (User ID: $unique_id)");
        return [
            'success' => true, 
            'message' => 'Password has been reset successfully. You can now log in with your new password.',
            'reset_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        logActivity("Database error in updatePassword for $email: " . $e->getMessage());
        return [
            'success' => false, 
            'message' => 'Failed to update password due to a system error. Please try again.'
        ];
    }
}

// === Begin Process ===

try {
    // Validate required fields
    $requiredFields = ['email', 'password', 'confirmPassword', 'secret_answer'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        respondWithError(
            'Missing required fields: ' . implode(', ', $missingFields), 
            'Password reset failed: Missing input fields',
            400
        );
    }
    
    // Sanitize inputs
    $email = trim($data['email']);
    $password = $data['password'];
    $confirmPassword = $data['confirmPassword'];
    $secret_answer = trim($data['secret_answer']);
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respondWithError('Invalid email address format.', "Invalid email format: $email", 400);
    }
    
    // Validate password
    $passwordValidation = validatePassword($password);
    if ($passwordValidation !== true) {
        respondWithError(
            'Password requirements not met: ' . implode('. ', $passwordValidation),
            "Weak password attempt for $email",
            400
        );
    }
    
    // Check password confirmation
    if ($password !== $confirmPassword) {
        respondWithError(
            'Password and Confirm Password do not match.', 
            "Password mismatch for $email",
            400
        );
    }
    
    // Check reset attempts
    $resetCheck = checkResetAttempts($conn, $email);
    if (!$resetCheck['success']) {
        respondWithError($resetCheck['message'], "Reset attempt limit reached for $email", 429);
    }
    
    // Check if user exists using prepared statement
    $stmt = $conn->prepare("SELECT unique_id, secret_answer, password FROM admin_tbl WHERE email = ?");
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $email);
    if (!$stmt->execute()) {
        throw new Exception("Database query execution failed: " . $stmt->error);
    }
    
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        $stmt->close();
        respondWithError(
            'No active account found with this email address.', 
            "Email not found or account inactive: $email",
            404
        );
    }
    
    $stmt->bind_result($unique_id, $db_secret_answer, $current_password_hash);
    $stmt->fetch();
    $stmt->close();
    
    // Check if account is locked
    $lockCheck = checkLockStatus($conn, $unique_id);
    if (!$lockCheck['success']) {
        respondWithError($lockCheck['message'], "Account locked for ID $unique_id", 423);
    }
    
    // Prevent using same password
    if (password_verify($password, $current_password_hash)) {
        updateResetAttempts($conn, $email);
        respondWithError(
            'New password cannot be the same as your current password.', 
            "Attempt to reuse current password for $email",
            400
        );
    }
    
    // Validate secret answer - check if function exists
    if (!function_exists('verifyAndRehashSecretAnswer')) {
        // Fallback validation
        if (trim($secret_answer) !== trim($db_secret_answer)) {
            updateResetAttempts($conn, $email);
            respondWithError(
                'Security answer is incorrect.', 
                "Invalid secret answer for $email",
                401
            );
        }
    } else {
        if (!verifyAndRehashSecretAnswer($conn, $unique_id, $secret_answer, $db_secret_answer)) {
            updateResetAttempts($conn, $email);
            respondWithError(
                'Security answer is incorrect.', 
                "Invalid secret answer for $email",
                401
            );
        }
    }
    
    // All checks passed, proceed to reset
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        throw new Exception("Password hashing failed");
    }
    
    $response = updatePassword($conn, $email, $hashedPassword, $unique_id);
    
    // Log successful reset for audit
    logActivity("Password reset completed successfully for $email (User ID: $unique_id)");
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    logActivity("Unexpected error in password reset: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again later.',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} finally {
    // Ensure connection is closed properly
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
