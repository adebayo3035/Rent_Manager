<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Add this to log the start of the request
$requestId = uniqid('secret_q_', true);
logActivity("[SECRET_QUESTION_START] [ID:{$requestId}] Request for secret question retrieval");

function logAndRespond($message, $response, $exit = true) {
    global $requestId;
    logActivity("[SECRET_QUESTION] [ID:{$requestId}] " . $message);
    echo json_encode($response);
    if ($exit) exit();
}

function getPostInput($key) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] JSON decode error: " . json_last_error_msg());
        return null;
    }
    return $input[$key] ?? null;
}

function getAdminByEmail($conn, $email) {
    $stmt = $conn->prepare("SELECT unique_id, secret_question, password FROM admin_tbl WHERE email = ? LIMIT 1");
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for admin query: " . $conn->error);
        return null;
    }
    $stmt->bind_param("s", $email);
    if (!$stmt->execute()) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for admin query: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    $stmt->close();
    return $admin;
}

function getLoginAttempts($conn, $unique_id) {
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ? LIMIT 1");
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for attempts query: " . $conn->error);
        return null;
    }
    $stmt->bind_param("s", $unique_id);
    if (!$stmt->execute()) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for attempts query: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc();
    $stmt->close();
    return $attempts;
}

function updateLoginAttempts($conn, $unique_id, $attempts, $locked_until = null) {
    $stmt = $conn->prepare("UPDATE admin_login_attempts SET attempts = ?, locked_until = ? WHERE unique_id = ?");
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for update attempts: " . $conn->error);
        return false;
    }
    $stmt->bind_param("iss", $attempts, $locked_until, $unique_id);
    $success = $stmt->execute();
    if (!$success) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for update attempts: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function insertLoginAttempt($conn, $unique_id) {
    $attempts = 1;
    $stmt = $conn->prepare("INSERT INTO admin_login_attempts (unique_id, attempts, locked_until) VALUES (?, ?, NULL)");
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for insert attempt: " . $conn->error);
        return false;
    }
    $stmt->bind_param("si", $unique_id, $attempts);
    $success = $stmt->execute();
    if (!$success) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for insert attempt: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function insertLockHistory($conn, $unique_id) {
    $status = "locked";
    $locked_by = "0";
    $lock_reason = "Account locked due to too many failed authentication attempts at get secret question";
    $lock_method = "Automatic lock";
    $stmt = $conn->prepare("INSERT INTO admin_lock_history (unique_id, status, locked_by, lock_reason, lock_method, locked_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for lock history: " . $conn->error);
        return false;
    }
    $stmt->bind_param("sssss", $unique_id, $status, $locked_by, $lock_reason, $lock_method);
    $success = $stmt->execute();
    if (!$success) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for lock history: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function resetLoginAttempts($conn, $unique_id) {
    $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for reset attempts: " . $conn->error);
        return false;
    }
    $stmt->bind_param("s", $unique_id);
    $success = $stmt->execute();
    if (!$success) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for reset attempts: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function updateLockHistory($conn, $unique_id) {
    $stmt = $conn->prepare("
        UPDATE admin_lock_history 
        SET status = 'unlocked', 
            unlocked_by = 0, 
            unlock_method = 'System auto-unlock', 
            unlocked_at = NOW() 
        WHERE unique_id = ? 
        AND status = 'locked'
        AND unlocked_at IS NULL
    ");
    
    if (!$stmt) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Prepare failed for unlock history: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $unique_id);
    $success = $stmt->execute();
    if (!$success) {
        global $requestId;
        logActivity("[SECRET_QUESTION_ERROR] [ID:{$requestId}] Execute failed for unlock history: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

// ========== MAIN EXECUTION ==========

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logAndRespond("Invalid request method: " . $_SERVER['REQUEST_METHOD'], 
        ['success' => false, 'message' => 'Invalid request method. Use POST.']
    );
}

// Get and validate input
$email = getPostInput('email');
$password = getPostInput('password');

logActivity("[SECRET_QUESTION_INPUT] [ID:{$requestId}] Email: " . ($email ? substr($email, 0, 10) . '...' : 'empty'));

if (!$email || !$password) {
    logAndRespond("Missing credentials: email or password", 
        ['success' => false, 'message' => 'Email and password are required']
    );
}

// Get admin data
$admin = getAdminByEmail($conn, $email);
if (!$admin) {
    // Generic error for security
    logAndRespond("Admin not found with email: " . substr($email, 0, 10) . '...', 
        ['success' => false, 'message' => 'Invalid credentials']
    );
}

$unique_id = $admin['unique_id'];
$hashedPassword = $admin['password'];
$secret_question = $admin['secret_question'];

logActivity("[SECRET_QUESTION_ADMIN] [ID:{$requestId}] Found admin ID: {$unique_id}");

// Check login attempts
$attemptData = getLoginAttempts($conn, $unique_id);
$current_time = new DateTime();
$max_attempts = 3;
$lockout_duration = 15; // minutes

if ($attemptData) {
    $attempts = $attemptData['attempts'] ?? 0;
    $locked_until = $attemptData['locked_until'] ? new DateTime($attemptData['locked_until']) : null;
    
    logActivity("[SECRET_QUESTION_ATTEMPTS] [ID:{$requestId}] Current attempts: {$attempts}, Locked until: " . ($locked_until ? $locked_until->format('Y-m-d H:i:s') : 'null'));

    if ($attempts >= $max_attempts && $locked_until && $current_time < $locked_until) {
        $remaining = $current_time->diff($locked_until);
        $remaining_minutes = $remaining->i;
        $remaining_seconds = $remaining->s;
        
        logAndRespond("Account locked for user: {$unique_id}", [
            'success' => false,
            'message' => "Your account is locked. Try again in {$remaining_minutes} minutes {$remaining_seconds} seconds."
        ]);
    }
} else {
    $attempts = 0;
    $locked_until = null;
    logActivity("[SECRET_QUESTION_ATTEMPTS] [ID:{$requestId}] No previous attempts found");
}

// Verify password
$passwordValid = verifyAndRehashPassword($conn, $unique_id, $password, $hashedPassword);
logActivity("[SECRET_QUESTION_PASSWORD] [ID:{$requestId}] Password validation: " . ($passwordValid ? 'valid' : 'invalid'));

if (!$passwordValid) {
    // Increment failed attempts
    $newAttempts = $attempts + 1;
    logActivity("[SECRET_QUESTION_ATTEMPTS] [ID:{$requestId}] New failed attempt count: {$newAttempts}");
    
    $lockedUntilTime = null;
    if ($newAttempts >= $max_attempts) {
        $lockedUntilTime = (new DateTime())->modify("+{$lockout_duration} minutes")->format('Y-m-d H:i:s');
        logActivity("[SECRET_QUESTION_LOCK] [ID:{$requestId}] Locking account until: {$lockedUntilTime}");
        
        // Record lock in history
        insertLockHistory($conn, $unique_id);
    }
    
    if ($attemptData) {
        updateLoginAttempts($conn, $unique_id, $newAttempts, $lockedUntilTime);
    } else {
        insertLoginAttempt($conn, $unique_id);
    }
    
    logAndRespond("Invalid password for user: {$unique_id}", [
        'success' => false,
        'message' => 'Invalid password. Please try again.'
    ]);
} else {
    // Password is valid - reset attempts and unlock if needed
    logActivity("[SECRET_QUESTION_SUCCESS] [ID:{$requestId}] Password validated successfully");
    
    resetLoginAttempts($conn, $unique_id);
    
    // Update lock history if account was locked
    if ($attempts >= $max_attempts) {
        updateLockHistory($conn, $unique_id);
    }
    
    logAndRespond("Secret question retrieved for user: {$unique_id}", [
        'success' => true,
        'secret_question' => $secret_question
    ], false);
}

// Close connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

logActivity("[SECRET_QUESTION_END] [ID:{$requestId}] Script execution completed");