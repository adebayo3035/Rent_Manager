<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Generate unique request ID for tracing
$requestId = uniqid('pass_reset_', true);
logActivity("[PASSWORD_RESET_START] [ID:{$requestId}] Password reset request started");

// Helper functions
function logAndRespond($message, $response, $httpCode = 200, $exit = true) {
    global $requestId;
    logActivity("[PASSWORD_RESET] [ID:{$requestId}] " . $message);
    http_response_code($httpCode);
    echo json_encode($response);
    if ($exit) exit();
}

function validatePassword($password) {
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
    
    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123'];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }
    
    return empty($errors) ? true : $errors;
}

function getSecretAnswerAttempts($conn, $unique_id) {
    global $requestId;
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM admin_secret_attempts WHERE unique_id = ? LIMIT 1");
    if (!$stmt) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed for secret attempts: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $unique_id);
    if (!$stmt->execute()) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Execute failed for secret attempts: " . $stmt->error);
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc();
    $stmt->close();
    
    return $attempts;
}

function updateSecretAnswerAttempts($conn, $unique_id, $attempts, $locked_until = null) {
    global $requestId;
    
    // Check if record exists
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM admin_secret_attempts WHERE unique_id = ?");
    if (!$checkStmt) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed for check: " . $conn->error);
        return false;
    }
    
    $checkStmt->bind_param("i", $unique_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    if ($row && $row['count'] > 0) {
        // Update existing record
        $query = "UPDATE admin_secret_attempts SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE unique_id = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed for update: " . $conn->error);
            return false;
        }
        $stmt->bind_param("isi", $attempts, $locked_until, $unique_id);
    } else {
        // Insert new record - FIXED: Added locked_until parameter
        $query = "INSERT INTO admin_secret_attempts (unique_id, attempts, locked_until, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed for insert: " . $conn->error);
            return false;
        }
        $stmt->bind_param("iis", $unique_id, $attempts, $locked_until);
    }
    
    $success = $stmt->execute();
    
    if (!$success) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Execute failed: " . $stmt->error);
    } else {
        logActivity("[PASSWORD_RESET_DEBUG] [ID:{$requestId}] Updated secret attempts for user {$unique_id}: attempts={$attempts}, locked_until=" . ($locked_until ?? 'NULL'));
    }
    
    $stmt->close();
    return $success;
}

function insertSecretAnswerLockHistory($conn, $unique_id) {
    global $requestId;
    
    $status = "locked";
    $locked_by = "0";
    $lock_reason = "Account locked due to too many failed secret answer attempts during password reset";
    $lock_method = "Automatic lock";
    
    $stmt = $conn->prepare("INSERT INTO admin_lock_history (unique_id, status, locked_by, lock_reason, lock_method, locked_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed for secret lock history: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("issss", $unique_id, $status, $locked_by, $lock_reason, $lock_method);
    $success = $stmt->execute();
    
    if (!$success) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Execute failed for secret lock history: " . $stmt->error);
    }
    
    $stmt->close();
    return $success;
}

function checkSecretAnswerLock($conn, $unique_id) {
    global $requestId;
    
    $attemptData = getSecretAnswerAttempts($conn, $unique_id);
    
    if (!$attemptData) {
        logActivity("[PASSWORD_RESET_DEBUG] [ID:{$requestId}] No previous secret answer attempts for user: {$unique_id}");
        return ['success' => true];
    }
    
    $attempts = $attemptData['attempts'] ?? 0;
    $locked_until = $attemptData['locked_until'] ?? null;
    
    logActivity("[PASSWORD_RESET_DEBUG] [ID:{$requestId}] Secret attempts for user {$unique_id}: attempts={$attempts}, locked_until={$locked_until}");
    
    // Check if account is locked
    if ($attempts >= 3 && $locked_until) {
        $lockedUntilTime = new DateTime($locked_until);
        $currentTime = new DateTime();
        
        if ($currentTime < $lockedUntilTime) {
            $remaining = $currentTime->diff($lockedUntilTime);
            $minutes = $remaining->i;
            $seconds = $remaining->s;
            
            logActivity("[PASSWORD_RESET_LOCK] [ID:{$requestId}] Secret answer lock active for user {$unique_id}, remaining: {$minutes}m {$seconds}s");
            
            return [
                'success' => false,
                'message' => "Your account is temporarily locked due to too many failed secret answer attempts. Please try again in {$minutes} minutes and {$seconds} seconds.",
                'locked_until' => $locked_until
            ];
        } else {
            // Lock expired, reset attempts
            updateSecretAnswerAttempts($conn, $unique_id, 0, null);
            logActivity("[PASSWORD_RESET_LOCK] [ID:{$requestId}] Secret answer lock expired for user {$unique_id}, resetting attempts");
            return ['success' => true];
        }
    }
    
    return ['success' => true];
}

function checkActiveSession($conn, $unique_id) {
    global $requestId;
    
    // Check if user has an active session (status != 'logged_out' and logged_out_at is NULL)
    $stmt = $conn->prepare("SELECT COUNT(*) as active_sessions FROM admin_active_sessions WHERE unique_id = ? AND (status != 'logged_out' OR logged_out_at IS NULL)");
    if (!$stmt) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed for session check: " . $conn->error);
        return false; // Return false on error to allow reset for safety
    }
    
    $stmt->bind_param("i", $unique_id);
    if (!$stmt->execute()) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Execute failed for session check: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $hasActiveSession = ($row && $row['active_sessions'] > 0);
    
    if ($hasActiveSession) {
        logActivity("[PASSWORD_RESET_SESSION] [ID:{$requestId}] User {$unique_id} has active session(s): " . $row['active_sessions']);
    }
    
    return $hasActiveSession;
}

function checkResetAttempts($conn, $email) {
    global $requestId;
    
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
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            if ($row['reset_attempts'] >= 3) {
                $lastAttempt = date('H:i', strtotime($row['last_attempt_date']));
                logActivity("[PASSWORD_RESET_ATTEMPTS] [ID:{$requestId}] Reset attempt limit reached for {$email}. Last attempt at {$lastAttempt}");
                return [
                    'success' => false, 
                    'message' => 'You have exceeded the maximum password reset attempts for today. Please try again tomorrow.',
                    'attempts' => $row['reset_attempts'],
                    'last_attempt' => $lastAttempt
                ];
            }
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Database error in checkResetAttempts: " . $e->getMessage());
        return ['success' => true]; // Allow to continue on database error
    }
}

function updateResetAttempts($conn, $email) {
    global $requestId;
    
    try {
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
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            $attempts = $row['reset_attempts'] + 1;
            $stmt = $conn->prepare("UPDATE admin_password_reset_attempts SET reset_attempts = ?, last_attempt_date = NOW(), last_attempt_date_time = NOW() WHERE email = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare UPDATE statement: " . $conn->error);
            }
            $stmt->bind_param("is", $attempts, $email);
        } else {
            $attempts = 1;
            $stmt = $conn->prepare("INSERT INTO admin_password_reset_attempts (email, reset_attempts, last_attempt_date, last_attempt_date_time) VALUES (?, ?, NOW(), NOW())");
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
        
        logActivity("[PASSWORD_RESET_ATTEMPTS] [ID:{$requestId}] Reset attempts updated to {$attempts} for {$email}");
        return $attempts;
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Database error in updateResetAttempts for {$email}: " . $e->getMessage());
        return false;
    }
}

function updatePassword($conn, $email, $hashedPassword, $unique_id) {
    global $requestId;
    
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
        
        // Reset reset attempts counter
        $stmtReset = $conn->prepare("UPDATE admin_password_reset_attempts SET reset_attempts = 0, last_attempt_date = NOW(), last_attempt_date_time = NOW() WHERE email = ?");
        if ($stmtReset) {
            $stmtReset->bind_param("s", $email);
            $stmtReset->execute();
            $stmtReset->close();
        }
        
        // Clear secret answer attempts
        $stmtClearSecret = $conn->prepare("DELETE FROM admin_secret_attempts WHERE unique_id = ?");
        if ($stmtClearSecret) {
            $stmtClearSecret->bind_param("i", $unique_id);
            $stmtClearSecret->execute();
            $stmtClearSecret->close();
        }
        
        // Clear login attempts
        $stmtClearLogin = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
        if ($stmtClearLogin) {
            $stmtClearLogin->bind_param("i", $unique_id);
            $stmtClearLogin->execute();
            $stmtClearLogin->close();
        }
        
        // Logout all active sessions for this user
        $stmtLogoutSessions = $conn->prepare("UPDATE admin_active_sessions SET status = 'Inactive', logged_out_at = NOW() WHERE unique_id = ? AND (status != 'Inactive' OR logged_out_at IS NULL)");
        if ($stmtLogoutSessions) {
            $stmtLogoutSessions->bind_param("i", $unique_id);
            $stmtLogoutSessions->execute();
            $affectedSessions = $stmtLogoutSessions->affected_rows;
            $stmtLogoutSessions->close();
            logActivity("[PASSWORD_RESET_SESSION] [ID:{$requestId}] Logged out {$affectedSessions} active session(s) for user {$unique_id}");
        }
        
        $conn->commit();
        
        logActivity("[PASSWORD_RESET_SUCCESS] [ID:{$requestId}] Password reset successful for {$email} (User ID: {$unique_id})");
        return [
            'success' => true, 
            'message' => 'Password has been reset successfully. All active sessions have been logged out.',
            'reset_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        logActivity("[PASSWORD_RESET_ERROR] [ID:{$requestId}] Database error in updatePassword for {$email}: " . $e->getMessage());
        return [
            'success' => false, 
            'message' => 'Failed to update password due to a system error. Please try again.'
        ];
    }
}

// === Main Execution ===

try {
    // Decode input
    $input = file_get_contents("php://input");
    if (empty($input)) {
        logAndRespond("Empty request body received", 
            ['success' => false, 'message' => 'No input data received'], 
            400
        );
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logAndRespond("Invalid JSON payload: " . json_last_error_msg(),
            ['success' => false, 'message' => 'Invalid JSON data'],
            400
        );
    }
    
    logActivity("[PASSWORD_RESET] [ID:{$requestId}] Payload decoded successfully");
    
    // Validate required fields
    $requiredFields = ['email', 'password', 'confirmPassword', 'secret_answer'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        logAndRespond("Missing required fields: " . implode(', ', $missingFields),
            ['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missingFields)],
            400
        );
    }
    
    // Sanitize inputs
    $email = trim($data['email']);
    $password = $data['password'];
    $confirmPassword = $data['confirmPassword'];
    $secret_answer = trim($data['secret_answer']);
    
    logActivity("[PASSWORD_RESET] [ID:{$requestId}] Processing reset for email: " . substr($email, 0, 10) . '...');
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logAndRespond("Invalid email format: {$email}",
            ['success' => false, 'message' => 'Invalid email address format.'],
            400
        );
    }
    
    // Validate password
    $passwordValidation = validatePassword($password);
    if ($passwordValidation !== true) {
        logAndRespond("Weak password attempt for {$email}",
            ['success' => false, 'message' => 'Password requirements not met: ' . implode('. ', $passwordValidation)],
            400
        );
    }
    
    // Check password confirmation
    if ($password !== $confirmPassword) {
        logAndRespond("Password mismatch for {$email}",
            ['success' => false, 'message' => 'Password and Confirm Password do not match.'],
            400
        );
    }
    
    // Check reset attempts
    $resetCheck = checkResetAttempts($conn, $email);
    if (!$resetCheck['success']) {
        logAndRespond("Reset attempt limit reached for {$email}",
            ['success' => false, 'message' => $resetCheck['message']],
            429
        );
    }
    
    // Check if user exists
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
        logAndRespond("Email not found or account inactive: {$email}",
            ['success' => false, 'message' => 'No active account found with this email address.'],
            404
        );
    }
    
    $stmt->bind_result($unique_id, $db_secret_answer, $current_password_hash);
    $stmt->fetch();
    $stmt->close();
    
    logActivity("[PASSWORD_RESET] [ID:{$requestId}] Found user ID: {$unique_id}");
    
    // // NEW: Check if user has active session
    // $hasActiveSession = checkActiveSession($conn, $unique_id);
    // if ($hasActiveSession) {
    //     logAndRespond("User has active session: {$unique_id}",
    //         ['success' => false, 'message' => 'Cannot reset password while you have an active session. Please log out first or use the "Forgot Password" feature from the login page.'],
    //         403
    //     );
    // }
    
    // Check secret answer lock BEFORE validating secret answer
    $secretLockCheck = checkSecretAnswerLock($conn, $unique_id);
    if (!$secretLockCheck['success']) {
        logAndRespond("Secret answer lock active for user {$unique_id}",
            ['success' => false, 'message' => $secretLockCheck['message']],
            423
        );
    }
    
    // Prevent using same password
    if (password_verify($password, $current_password_hash)) {
        updateResetAttempts($conn, $email);
        logAndRespond("Attempt to reuse current password for {$email}",
            ['success' => false, 'message' => 'New password cannot be the same as your current password.'],
            400
        );
    }
    
    // Validate secret answer with lockout mechanism
    $secretAnswerValid = false;
    $secretAttemptData = getSecretAnswerAttempts($conn, $unique_id);
    $currentSecretAttempts = $secretAttemptData['attempts'] ?? 0;
    $maxSecretAttempts = 3;
    $secretLockoutDuration = 15; // minutes
    
    logActivity("[PASSWORD_RESET_DEBUG] [ID:{$requestId}] Current secret answer attempts: {$currentSecretAttempts}");
    
    // Validate secret answer
    if (function_exists('verifyAndRehashSecretAnswer')) {
        $secretAnswerValid = verifyAndRehashSecretAnswer($conn, $unique_id, $secret_answer, $db_secret_answer);
    } else {
        // Fallback validation
        $secretAnswerValid = (trim($secret_answer) === trim($db_secret_answer));
    }
    
    if (!$secretAnswerValid) {
        // Increment failed secret answer attempts
        $newSecretAttempts = $currentSecretAttempts + 1;
        
        // Set lock time only on 3rd attempt
        $lockedUntilTime = null;
        if ($newSecretAttempts >= $maxSecretAttempts) {
            $lockedUntilTime = (new DateTime())->modify("+{$secretLockoutDuration} minutes")->format('Y-m-d H:i:s');
            logActivity("[PASSWORD_RESET_LOCK] [ID:{$requestId}] Locking account for secret answer failures until: {$lockedUntilTime}");
            
            // Record lock in history
            insertSecretAnswerLockHistory($conn, $unique_id);
        }
        
        // Update secret answer attempts
        updateSecretAnswerAttempts($conn, $unique_id, $newSecretAttempts, $lockedUntilTime);
        
        // Also increment reset attempts
        updateResetAttempts($conn, $email);
        
        // Prepare error message
        $remainingAttempts = $maxSecretAttempts - $newSecretAttempts;
        if ($newSecretAttempts >= $maxSecretAttempts) {
            $message = "Your account has been locked due to too many failed secret answer attempts. Please try again in {$secretLockoutDuration} minutes.";
        } else {
            $message = "Security answer is incorrect. You have {$remainingAttempts} attempt(s) remaining.";
        }
        
        logAndRespond("Invalid secret answer for user {$unique_id} (attempt {$newSecretAttempts})",
            [
                'success' => false, 
                'message' => $message,
                'attempts_remaining' => ($newSecretAttempts < $maxSecretAttempts) ? $remainingAttempts : 0,
                'locked' => $newSecretAttempts >= $maxSecretAttempts
            ],
            401
        );
    } else {
        // Secret answer is valid - reset secret answer attempts
        updateSecretAnswerAttempts($conn, $unique_id, 0, null);
        logActivity("[PASSWORD_RESET_DEBUG] [ID:{$requestId}] Secret answer validated successfully for user {$unique_id}");
    }
    
    // All checks passed, proceed to reset
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        throw new Exception("Password hashing failed");
    }
    
    $response = updatePassword($conn, $email, $hashedPassword, $unique_id);
    
    // Log successful reset for audit
    logActivity("[PASSWORD_RESET_COMPLETE] [ID:{$requestId}] Password reset completed successfully for {$email} (User ID: {$unique_id})");
    
    echo json_encode($response);
    
} catch (Exception $e) {
    logActivity("[PASSWORD_RESET_EXCEPTION] [ID:{$requestId}] Unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again later.',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("[PASSWORD_RESET_END] [ID:{$requestId}] Script execution completed");
}
