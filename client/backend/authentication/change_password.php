<?php
// client/backend/authentication/change_password.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Generate unique request ID for tracing
$requestId = uniqid('client_pass_change_', true);
logActivity("[CLIENT_PASSWORD_CHANGE_START] [ID:{$requestId}] Password change request started");

// Helper functions
function logAndRespond($message, $response, $httpCode = 200, $exit = true) {
    global $requestId;
    logActivity("[CLIENT_PASSWORD_CHANGE] [ID:{$requestId}] " . $message);
    http_response_code($httpCode);
    echo json_encode($response);
    if ($exit) exit();
}

function validateClientPassword($password) {
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
    
    if (preg_match('/\s/', $password)) {
        $errors[] = "Password cannot contain spaces";
    }
    
    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123', 'client123'];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }
    
    return empty($errors) ? true : $errors;
}

// Normalize and verify secret answer (case-insensitive)
function verifySecretAnswer($inputAnswer, $storedHash) {
    if (empty($storedHash)) return false;
    $normalizedAnswer = strtolower(trim($inputAnswer));
    return password_verify($normalizedAnswer, $storedHash);
}

// Get secret answer attempts
function getSecretAnswerAttempts($conn, $client_code) {
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM client_secret_attempts WHERE client_code = ? LIMIT 1");
    if (!$stmt) return null;
    
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc();
    $stmt->close();
    return $attempts;
}

// Update secret answer attempts
function updateSecretAnswerAttempts($conn, $client_code, $attempts, $locked_until = null) {
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM client_secret_attempts WHERE client_code = ?");
    if (!$checkStmt) return false;
    
    $checkStmt->bind_param("s", $client_code);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    if ($row && $row['count'] > 0) {
        $query = "UPDATE client_secret_attempts SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE client_code = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) return false;
        $stmt->bind_param("iss", $attempts, $locked_until, $client_code);
    } else {
        $query = "INSERT INTO client_secret_attempts (client_code, attempts, locked_until, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
        $stmt = $conn->prepare($query);
        if (!$stmt) return false;
        $stmt->bind_param("sis", $client_code, $attempts, $locked_until);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Insert lock history
function insertSecretAnswerLockHistory($conn, $client_code, $attempts) {
    $stmt = $conn->prepare("
        INSERT INTO client_lock_history 
        (client_code, status, locked_by, lock_reason, lock_method, locked_at) 
        VALUES (?, 'locked', 0, ?, 'Automatic lock (secret answer)', NOW())
    ");
    if (!$stmt) return false;
    
    $lock_reason = "Account locked due to {$attempts} failed secret answer attempts during password change";
    $stmt->bind_param("ss", $client_code, $lock_reason);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Check if secret answer is locked
function checkSecretAnswerLock($conn, $client_code) {
    $attemptData = getSecretAnswerAttempts($conn, $client_code);
    
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
            updateSecretAnswerAttempts($conn, $client_code, 0, null);
            return ['success' => true];
        }
    }
    
    return ['success' => true];
}

// Update password only (no logout in backend - frontend handles logout)
function updatePasswordOnly($conn, $client_code, $hashedPassword, $email) {
    global $requestId;
    
    try {
        $conn->begin_transaction();
        
        // Update password
        $stmt = $conn->prepare("UPDATE clients SET password = ?, date_updated = NOW() WHERE client_code = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare password update: " . $conn->error);
        }
        
        $stmt->bind_param("ss", $hashedPassword, $client_code);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update password: " . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if ($affectedRows === 0) {
            throw new Exception("User not found");
        }
        
        // Clear secret answer attempts
        $stmtClearSecret = $conn->prepare("DELETE FROM client_secret_attempts WHERE client_code = ?");
        if ($stmtClearSecret) {
            $stmtClearSecret->bind_param("s", $client_code);
            $stmtClearSecret->execute();
            $stmtClearSecret->close();
        }
        
        // Clear login attempts
        $stmtClearLogin = $conn->prepare("DELETE FROM client_login_attempts WHERE client_code = ?");
        if ($stmtClearLogin) {
            $stmtClearLogin->bind_param("s", $client_code);
            $stmtClearLogin->execute();
            $stmtClearLogin->close();
        }
        
        // Update lock history to unlocked
        $stmtUnlock = $conn->prepare("
            UPDATE client_lock_history 
            SET status = 'unlocked', 
                unlocked_by = 0, 
                unlock_method = 'Password change', 
                unlock_reason = 'Password change successful',
                unlocked_at = NOW() 
            WHERE client_code = ? AND status = 'locked' AND unlocked_at IS NULL
        ");
        if ($stmtUnlock) {
            $stmtUnlock->bind_param("s", $client_code);
            $stmtUnlock->execute();
            $stmtUnlock->close();
        }
        
        $conn->commit();
        
        logActivity("[CLIENT_PASSWORD_CHANGE_SUCCESS] [ID:{$requestId}] Password changed successfully for client: {$client_code}");
        
        return [
            'success' => true, 
            'message' => 'Password has been changed successfully. Please log in with your new password.',
            'data' => [
                'client_code' => $client_code,
                'require_logout' => true,
                'redirect_url' => '../pages/login.php'
            ]
        ];
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        logActivity("[PASSWORD_CHANGE_ERROR] [ID:{$requestId}] " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update password. Please try again.'];
    }
}

// === Main Execution ===

try {
    // Start session
    session_start();
    
    // Decode input
    $input = file_get_contents("php://input");
    if (empty($input)) {
        logAndRespond("Empty request body", ['success' => false, 'message' => 'No input data received'], 400);
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logAndRespond("Invalid JSON", ['success' => false, 'message' => 'Invalid JSON data'], 400);
    }
    
    logActivity("[CLIENT_PASSWORD_CHANGE] [ID:{$requestId}] Payload received: " . json_encode(array_keys($data)));
    
    // Validate required fields
    $currentPassword = isset($data['current_password']) ? trim($data['current_password']) : null;
    $newPassword = isset($data['new_password']) ? trim($data['new_password']) : null;
    $confirmPassword = isset($data['confirm_password']) ? trim($data['confirm_password']) : null;
    $secretAnswer = isset($data['secret_answer']) ? trim($data['secret_answer']) : null;
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword) || empty($secretAnswer)) {
        logAndRespond("Missing fields", [
            'success' => false, 
            'message' => 'Current password, new password, confirm password, and secret answer are required'
        ], 400);
    }
    
    // Check if new password matches confirm password
    if ($newPassword !== $confirmPassword) {
        logAndRespond("Password mismatch", [
            'success' => false, 
            'message' => 'New password and confirm password do not match'
        ], 400);
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        logAndRespond("Not logged in", ['success' => false, 'message' => 'You must be logged in to change password'], 401);
    }
    
    $client_code = $_SESSION['client_code'];
    logActivity("[CLIENT_PASSWORD_CHANGE] [ID:{$requestId}] Client code from session: {$client_code}");
    
    // Get client details
    $stmt = $conn->prepare("
        SELECT client_code, email, password, secret_question, secret_answer, has_secret_set, status 
        FROM clients 
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        logAndRespond("User not found", ['success' => false, 'message' => 'No active account found'], 404);
    }
    
    $client = $result->fetch_assoc();
    $stmt->close();
    
    $email = $client['email'];
    $current_password_hash = $client['password'];
    
    // Verify current password
    if (!password_verify($currentPassword, $current_password_hash)) {
        logAndRespond("Invalid current password", ['success' => false, 'message' => 'Current password is incorrect'], 401);
    }
    
    // Check if secret question is set
    if (!$client['has_secret_set'] || empty($client['secret_question'])) {
        logAndRespond("Secret not set", ['success' => false, 'message' => 'Security question not set. Please set it first.'], 400);
    }
    
    // Check secret answer lock
    $secretLockCheck = checkSecretAnswerLock($conn, $client_code);
    if (!$secretLockCheck['success']) {
        logAndRespond("Account locked", ['success' => false, 'message' => $secretLockCheck['message']], 423);
    }
    
    // Validate new password
    $passwordValidation = validateClientPassword($newPassword);
    if ($passwordValidation !== true) {
        logAndRespond("Weak password", ['success' => false, 'message' => implode('. ', $passwordValidation)], 400);
    }
    
    // Check if new password is same as current
    if (password_verify($newPassword, $current_password_hash)) {
        logAndRespond("Same password", ['success' => false, 'message' => 'New password cannot be the same as current password'], 400);
    }
    
    // Verify secret answer
    $secretAnswerValid = verifySecretAnswer($secretAnswer, $client['secret_answer']);
    
    $secretAttemptData = getSecretAnswerAttempts($conn, $client_code);
    $currentSecretAttempts = $secretAttemptData['attempts'] ?? 0;
    $maxSecretAttempts = 3;
    $secretLockoutDuration = 15;
    
    if (!$secretAnswerValid) {
        $newSecretAttempts = $currentSecretAttempts + 1;
        $lockedUntilTime = null;
        
        if ($newSecretAttempts >= $maxSecretAttempts) {
            $lockedUntilTime = (new DateTime())->modify("+{$secretLockoutDuration} minutes")->format('Y-m-d H:i:s');
            insertSecretAnswerLockHistory($conn, $client_code, $newSecretAttempts);
        }
        
        updateSecretAnswerAttempts($conn, $client_code, $newSecretAttempts, $lockedUntilTime);
        
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
    updateSecretAnswerAttempts($conn, $client_code, 0, null);
    
    // Proceed with password change
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $response = updatePasswordOnly($conn, $client_code, $hashedPassword, $email);
    
    logActivity("[CLIENT_PASSWORD_CHANGE_COMPLETE] [ID:{$requestId}] Password change completed for client: {$client_code}");
    
    // Send notification
    createSecurityNotification($conn, $client_code, 'password_changed');
    
    // Send the response
    echo json_encode($response);
    
} catch (Exception $e) {
    logActivity("[CLIENT_PASSWORD_CHANGE_EXCEPTION] [ID:{$requestId}] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again later.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}
?>