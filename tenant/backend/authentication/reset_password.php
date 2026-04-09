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
$requestId = uniqid('tenant_pass_reset_', true);
logActivity("[TENANT_PASSWORD_RESET_START] [ID:{$requestId}] Password reset request started");

// Helper functions
function logAndRespond($message, $response, $httpCode = 200, $exit = true) {
    global $requestId;
    logActivity("[TENANT_PASSWORD_RESET] [ID:{$requestId}] " . $message);
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
    
    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123', 'tenant123'];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }
    
    return empty($errors) ? true : $errors;
}

// Verify secret answer (case-insensitive, using password_verify)
function verifySecretAnswer($inputAnswer, $storedHash) {
    if (empty($storedHash)) return false;
    // Normalize: trim whitespace and convert to lowercase for case-insensitive comparison
    $normalizedAnswer = strtolower(trim($inputAnswer));
    return password_verify($normalizedAnswer, $storedHash);
}

// Get secret answer attempts
function getSecretAnswerAttempts($conn, $tenant_code) {
    global $requestId;
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM tenant_secret_attempts WHERE tenant_code = ? LIMIT 1");
    if (!$stmt) {
        logActivity("[TENANT_PASSWORD_RESET_ERROR] [ID:{$requestId}] Prepare failed: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("s", $tenant_code);
    if (!$stmt->execute()) {
        logActivity("[TENANT_PASSWORD_RESET_ERROR] [ID:{$requestId}] Execute failed: " . $stmt->error);
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc();
    $stmt->close();
    return $attempts;
}

// Update secret answer attempts
function updateSecretAnswerAttempts($conn, $tenant_code, $attempts, $locked_until = null) {
    global $requestId;
    
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM tenant_secret_attempts WHERE tenant_code = ?");
    if (!$checkStmt) return false;
    
    $checkStmt->bind_param("s", $tenant_code);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    if ($row && $row['count'] > 0) {
        $query = "UPDATE tenant_secret_attempts SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE tenant_code = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) return false;
        $stmt->bind_param("iss", $attempts, $locked_until, $tenant_code);
    } else {
        $query = "INSERT INTO tenant_secret_attempts (tenant_code, attempts, locked_until, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
        $stmt = $conn->prepare($query);
        if (!$stmt) return false;
        $stmt->bind_param("sis", $tenant_code, $attempts, $locked_until);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Insert lock history
function insertSecretAnswerLockHistory($conn, $tenant_code, $attempts) {
    global $requestId;
    
    $stmt = $conn->prepare("
        INSERT INTO tenant_lock_history 
        (tenant_code, status, locked_by, lock_reason, lock_method, locked_at) 
        VALUES (?, 'locked', 0, ?, 'Automatic lock', NOW())
    ");
    if (!$stmt) return false;
    
    $lock_reason = "Account locked due to {$attempts} failed secret answer attempts during password reset";
    $stmt->bind_param("ss", $tenant_code, $lock_reason);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Check if secret answer is locked
function checkSecretAnswerLock($conn, $tenant_code) {
    $attemptData = getSecretAnswerAttempts($conn, $tenant_code);
    
    if (!$attemptData) {
        return ['success' => true];
    }
    
    $attempts = $attemptData['attempts'] ?? 0;
    $locked_until = $attemptData['locked_until'] ?? null;
    
    if ($attempts >= 3 && $locked_until) {
        $lockedUntilTime = new DateTime($locked_until);
        $currentTime = new DateTime();
        
        if ($currentTime < $lockedUntilTime) {
            $remaining = $currentTime->diff($lockedUntilTime);
            $minutes = $remaining->i;
            $seconds = $remaining->s;
            
            return [
                'success' => false,
                'message' => "Too many failed secret answer attempts. Please try again in {$minutes} minutes and {$seconds} seconds.",
                'locked_until' => $locked_until
            ];
        } else {
            updateSecretAnswerAttempts($conn, $tenant_code, 0, null);
            return ['success' => true];
        }
    }
    
    return ['success' => true];
}

// Check reset attempts for rate limiting
function checkResetAttempts($conn, $email) {
    try {
        $stmt = $conn->prepare("SELECT reset_attempts, last_attempt_date FROM tenant_password_reset_attempts WHERE email = ? AND DATE(last_attempt_date) = CURDATE()");
        if (!$stmt) return ['success' => true];
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row && $row['reset_attempts'] >= 5) {
            return [
                'success' => false, 
                'message' => 'Too many password reset attempts. Please try again tomorrow.'
            ];
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => true];
    }
}

// Update reset attempts counter
function updateResetAttempts($conn, $email) {
    try {
        $stmt = $conn->prepare("SELECT reset_attempts FROM tenant_password_reset_attempts WHERE email = ? AND DATE(last_attempt_date) = CURDATE()");
        if (!$stmt) return false;
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            $attempts = $row['reset_attempts'] + 1;
            $stmt = $conn->prepare("UPDATE tenant_password_reset_attempts SET reset_attempts = ?, last_attempt_date = NOW() WHERE email = ?");
            if (!$stmt) return false;
            $stmt->bind_param("is", $attempts, $email);
        } else {
            $attempts = 1;
            $stmt = $conn->prepare("INSERT INTO tenant_password_reset_attempts (email, reset_attempts, last_attempt_date) VALUES (?, ?, NOW())");
            if (!$stmt) return false;
            $stmt->bind_param("si", $email, $attempts);
        }
        
        $stmt->execute();
        $stmt->close();
        return $attempts;
        
    } catch (Exception $e) {
        return false;
    }
}

// Update password and clear all attempts
function updatePassword($conn, $email, $hashedPassword, $tenant_code) {
    global $requestId;
    
    try {
        $conn->begin_transaction();
        
        // Update password
        $stmt = $conn->prepare("UPDATE tenants SET password = ?, last_updated_at = NOW() WHERE email = ? AND tenant_code = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare password update: " . $conn->error);
        }
        
        $stmt->bind_param("sss", $hashedPassword, $email, $tenant_code);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update password: " . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if ($affectedRows === 0) {
            throw new Exception("User not found");
        }
        
        // Reset reset attempts counter
        $stmtReset = $conn->prepare("DELETE FROM tenant_password_reset_attempts WHERE email = ?");
        if ($stmtReset) {
            $stmtReset->bind_param("s", $email);
            $stmtReset->execute();
            $stmtReset->close();
        }
        
        // Clear secret answer attempts
        $stmtClearSecret = $conn->prepare("DELETE FROM tenant_secret_attempts WHERE tenant_code = ?");
        if ($stmtClearSecret) {
            $stmtClearSecret->bind_param("s", $tenant_code);
            $stmtClearSecret->execute();
            $stmtClearSecret->close();
        }
        
        // Clear login attempts
        $stmtClearLogin = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
        if ($stmtClearLogin) {
            $stmtClearLogin->bind_param("s", $email);
            $stmtClearLogin->execute();
            $stmtClearLogin->close();
        }
        
        // Update lock history to unlocked
        $stmtUnlock = $conn->prepare("
            UPDATE tenant_lock_history 
            SET status = 'unlocked', 
                unlocked_by = 0, 
                unlock_method = 'Password reset', 
                unlock_reason = 'Password reset successful',
                unlocked_at = NOW() 
            WHERE tenant_code = ? AND status = 'locked' AND unlocked_at IS NULL
        ");
        if ($stmtUnlock) {
            $stmtUnlock->bind_param("s", $tenant_code);
            $stmtUnlock->execute();
            $stmtUnlock->close();
        }
        
        $conn->commit();
        
        logActivity("[TENANT_PASSWORD_RESET_SUCCESS] [ID:{$requestId}] Password reset successful for {$email}");
        return ['success' => true, 'message' => 'Password has been reset successfully.'];
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        logActivity("[TENANT_PASSWORD_RESET_ERROR] [ID:{$requestId}] " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update password. Please try again.'];
    }
}

// === Main Execution ===

try {
    // Decode input
    $input = file_get_contents("php://input");
    if (empty($input)) {
        logAndRespond("Empty request body", ['success' => false, 'message' => 'No input data received'], 400);
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logAndRespond("Invalid JSON", ['success' => false, 'message' => 'Invalid JSON data'], 400);
    }
    
    logActivity("[TENANT_PASSWORD_RESET] [ID:{$requestId}] Payload decoded");
    
    // Validate required fields
    $requiredFields = ['email', 'password', 'confirmPassword', 'secret_answer'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        logAndRespond("Missing fields", ['success' => false, 'message' => 'All fields are required'], 400);
    }
    
    // Sanitize inputs
    $email = trim($data['email']);
    $newPassword = $data['password'];
    $confirmPassword = $data['confirmPassword'];
    $secret_answer = trim($data['secret_answer']);
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logAndRespond("Invalid email", ['success' => false, 'message' => 'Invalid email address'], 400);
    }
    
    // Validate password
    $passwordValidation = validatePassword($newPassword);
    if ($passwordValidation !== true) {
        logAndRespond("Weak password", ['success' => false, 'message' => implode('. ', $passwordValidation)], 400);
    }
    
    // Check password confirmation
    if ($newPassword !== $confirmPassword) {
        logAndRespond("Password mismatch", ['success' => false, 'message' => 'Passwords do not match'], 400);
    }
    
    // Check reset rate limit
    $resetCheck = checkResetAttempts($conn, $email);
    if (!$resetCheck['success']) {
        logAndRespond("Reset limit reached", ['success' => false, 'message' => $resetCheck['message']], 429);
    }
    
    // Find tenant
    $stmt = $conn->prepare("
        SELECT tenant_code, firstname, lastname, secret_question, secret_answer, has_secret_set, password, status 
        FROM tenants 
        WHERE email = ? AND status = 1
        LIMIT 1
    ");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        logAndRespond("User not found", ['success' => false, 'message' => 'No active account found with this email'], 404);
    }
    
    $tenant = $result->fetch_assoc();
    $stmt->close();
    
    $tenant_code = $tenant['tenant_code'];
    $current_password_hash = $tenant['password'];
    
    // Check if secret question is set
    if (!$tenant['has_secret_set'] || empty($tenant['secret_question'])) {
        logAndRespond("Secret not set", ['success' => false, 'message' => 'Security question not set. Please contact support.'], 400);
    }
    
    // Check secret answer lock
    $secretLockCheck = checkSecretAnswerLock($conn, $tenant_code);
    if (!$secretLockCheck['success']) {
        logAndRespond("Account locked", ['success' => false, 'message' => $secretLockCheck['message']], 423);
    }
    
    // Prevent using same password
    if (password_verify($newPassword, $current_password_hash)) {
        updateResetAttempts($conn, $email);
        logAndRespond("Same password", ['success' => false, 'message' => 'New password cannot be the same as current password'], 400);
    }
    
    // Verify secret answer using password_verify
    $secretAnswerValid = verifySecretAnswer($secret_answer, $tenant['secret_answer']);
    
    $secretAttemptData = getSecretAnswerAttempts($conn, $tenant_code);
    $currentSecretAttempts = $secretAttemptData['attempts'] ?? 0;
    $maxSecretAttempts = 3;
    $secretLockoutDuration = 15;
    
    if (!$secretAnswerValid) {
        $newSecretAttempts = $currentSecretAttempts + 1;
        $lockedUntilTime = null;
        
        if ($newSecretAttempts >= $maxSecretAttempts) {
            $lockedUntilTime = (new DateTime())->modify("+{$secretLockoutDuration} minutes")->format('Y-m-d H:i:s');
            insertSecretAnswerLockHistory($conn, $tenant_code, $newSecretAttempts);
        }
        
        updateSecretAnswerAttempts($conn, $tenant_code, $newSecretAttempts, $lockedUntilTime);
        updateResetAttempts($conn, $email);
        
        $remainingAttempts = $maxSecretAttempts - $newSecretAttempts;
        $message = $remainingAttempts > 0 
            ? "Security answer is incorrect. You have {$remainingAttempts} attempt(s) remaining."
            : "Too many failed attempts. Please try again in {$secretLockoutDuration} minutes.";
        
        logAndRespond("Invalid secret answer", [
            'success' => false, 
            'message' => $message,
            'remaining_attempts' => $remainingAttempts
        ], 401);
    }
    
    // Secret answer valid - reset attempts
    updateSecretAnswerAttempts($conn, $tenant_code, 0, null);
    
    // Proceed with password reset
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $response = updatePassword($conn, $email, $hashedPassword, $tenant_code);
    
    logActivity("[TENANT_PASSWORD_RESET_COMPLETE] [ID:{$requestId}] Password reset completed for {$email}");
    echo json_encode($response);
    
} catch (Exception $e) {
    logActivity("[TENANT_PASSWORD_RESET_EXCEPTION] [ID:{$requestId}] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again later.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>