<?php
// client/backend/authentication/get_secret_question.php
// Get secret question for client after password verification

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Generate unique request ID for tracking
$requestId = uniqid('client_secret_', true);
logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] ========== GET SECRET QUESTION - START ==========");
logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Request Method: " . $_SERVER['REQUEST_METHOD']);

// ==================== HELPER FUNCTIONS ====================

// Get client by email or phone
function getClientByIdentifier($conn, $identifier) {
    logActivity("[CLIENT_SECRET_QUESTION] Getting client by identifier: " . substr($identifier, 0, 10) . '...');
    
    $stmt = $conn->prepare("
        SELECT 
            client_code, 
            firstname, 
            lastname, 
            email, 
            secret_question, 
            has_secret_set,
            status,
            password
        FROM clients 
        WHERE email = ? OR phone = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Prepare failed - " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();
    $stmt->close();
    
    if ($client) {
        logActivity("[CLIENT_SECRET_QUESTION] Client found: {$client['client_code']}");
    } else {
        logActivity("[CLIENT_SECRET_QUESTION] Client not found for identifier");
    }
    
    return $client;
}

// Get login attempts
function getLoginAttempts($conn, $client_code) {
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM client_login_attempts WHERE client_code = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc();
    $stmt->close();
    return $attempts;
}

// Update login attempts
function updateLoginAttempts($conn, $client_code, $attempts, $locked_until = null) {
    logActivity("[CLIENT_SECRET_QUESTION] Updating login attempts for: {$client_code} - Attempts: {$attempts}");
    
    // Check if record exists
    $checkStmt = $conn->prepare("SELECT id FROM client_login_attempts WHERE client_code = ?");
    $checkStmt->bind_param("s", $client_code);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();
    
    if ($exists) {
        $stmt = $conn->prepare("UPDATE client_login_attempts SET attempts = ?, locked_until = ?, last_attempt = NOW() WHERE client_code = ?");
        if (!$stmt) return false;
        $stmt->bind_param("iss", $attempts, $locked_until, $client_code);
    } else {
        $stmt = $conn->prepare("INSERT INTO client_login_attempts (client_code, attempts, locked_until, last_attempt) VALUES (?, ?, ?, NOW())");
        if (!$stmt) return false;
        $stmt->bind_param("sis", $client_code, $attempts, $locked_until);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Reset login attempts
function resetLoginAttempts($conn, $client_code) {
    logActivity("[CLIENT_SECRET_QUESTION] Resetting login attempts for: {$client_code}");
    
    $stmt = $conn->prepare("DELETE FROM client_login_attempts WHERE client_code = ?");
    if (!$stmt) return false;
    $stmt->bind_param("s", $client_code);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Insert lock history
function insertLockHistory($conn, $client_code, $attempts) {
    logActivity("[CLIENT_SECRET_QUESTION] Inserting lock history for: {$client_code} - Attempts: {$attempts}");
    
    $stmt = $conn->prepare("
        INSERT INTO client_lock_history 
        (client_code, status, locked_by, lock_reason, lock_method, locked_at) 
        VALUES (?, 'locked', 0, ?, 'Automatic lock', NOW())
    ");
    
    if (!$stmt) return false;
    
    $lock_reason = "Account locked due to {$attempts} failed secret question attempts";
    $stmt->bind_param("ss", $client_code, $lock_reason);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Update lock history when unlocked
function updateLockHistory($conn, $client_code) {
    logActivity("[CLIENT_SECRET_QUESTION] Updating lock history (unlock) for: {$client_code}");
    
    $stmt = $conn->prepare("
        UPDATE client_lock_history 
        SET status = 'unlocked', 
            unlocked_by = 0, 
            unlock_method = 'Auto after successful verification',
            unlocked_at = NOW() 
        WHERE client_code = ? AND status = 'locked' AND unlocked_at IS NULL
    ");
    
    if (!$stmt) return false;
    $stmt->bind_param("s", $client_code);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// ==================== MAIN EXECUTION ====================

try {
    // ==================== STEP 1: CHECK REQUEST METHOD ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 1: Checking request method");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Invalid request method - " . $_SERVER['REQUEST_METHOD']);
        echo json_encode(['success' => false, 'message' => 'Invalid request method. Use POST.']);
        exit;
    }
    logActivity("[CLIENT_SECRET_QUESTION] Step 1 - Request method validated: POST");

    // ==================== STEP 2: GET AND PARSE INPUT ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 2: Parsing input data");
    
    $raw_input = file_get_contents('php://input');
    logActivity("[CLIENT_SECRET_QUESTION] Step 2.1 - Raw input length: " . strlen($raw_input) . " bytes");
    
    $input = json_decode($raw_input, true);
    
    if (!$input) {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Invalid JSON input");
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    logActivity("[CLIENT_SECRET_QUESTION] Step 2.2 - Input parsed successfully");

    // ==================== STEP 3: EXTRACT INPUT FIELDS ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 3: Extracting input fields");
    
    $identifier = isset($input['identifier']) ? trim($input['identifier']) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    
    logActivity("[CLIENT_SECRET_QUESTION] Step 3.1 - Identifier provided: " . ($identifier ? substr($identifier, 0, 10) . '...' : 'missing'));
    logActivity("[CLIENT_SECRET_QUESTION] Step 3.2 - Password provided: " . ($password ? '***provided***' : 'missing'));

    // ==================== STEP 4: VALIDATE INPUT FIELDS ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 4: Validating input fields");
    
    if (empty($identifier) || empty($password)) {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Missing required fields");
        echo json_encode(['success' => false, 'message' => 'Email/Phone and password are required']);
        exit;
    }
    logActivity("[CLIENT_SECRET_QUESTION] Step 4 - Input validation passed");

    // ==================== STEP 5: FIND CLIENT ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 5: Finding client by identifier");
    
    $client = getClientByIdentifier($conn, $identifier);
    
    if (!$client) {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Client not found");
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }
    
    $client_code = $client['client_code'];
    logActivity("[CLIENT_SECRET_QUESTION] Step 5 - Client found: {$client_code}");

    // ==================== STEP 6: CHECK ACCOUNT STATUS ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 6: Checking account status");
    
    if ($client['status'] != 1) {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Account inactive - Status: {$client['status']}");
        echo json_encode(['success' => false, 'message' => 'Account is inactive. Please contact support.']);
        exit;
    }
    logActivity("[CLIENT_SECRET_QUESTION] Step 6 - Account status: Active");

    // ==================== STEP 7: CHECK SECRET QUESTION EXISTS ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 7: Checking if secret question exists");
    
    if (!$client['has_secret_set'] || empty($client['secret_question'])) {
        logActivity("[CLIENT_SECRET_QUESTION] ERROR: Secret question not set - has_secret_set: {$client['has_secret_set']}");
        echo json_encode(['success' => false, 'message' => 'Secret question has not been set for this account.']);
        exit;
    }
    logActivity("[CLIENT_SECRET_QUESTION] Step 7 - Secret question exists");

    // ==================== STEP 8: CHECK LOGIN ATTEMPTS ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 8: Checking login attempts");
    
    $attemptData = getLoginAttempts($conn, $client_code);
    $current_time = new DateTime();
    $max_attempts = 3;
    $lockout_duration = 15; // minutes

    if ($attemptData) {
        $attempts = $attemptData['attempts'] ?? 0;
        $locked_until = $attemptData['locked_until'] ? new DateTime($attemptData['locked_until']) : null;
        
        logActivity("[CLIENT_SECRET_QUESTION] Step 8.1 - Current attempts: {$attempts}");
        logActivity("[CLIENT_SECRET_QUESTION] Step 8.2 - Locked until: " . ($locked_until ? $locked_until->format('Y-m-d H:i:s') : 'not locked'));

        if ($attempts >= $max_attempts && $locked_until && $current_time < $locked_until) {
            $remaining = $current_time->diff($locked_until);
            $remaining_minutes = $remaining->i;
            $remaining_seconds = $remaining->s;
            
            logActivity("[CLIENT_SECRET_QUESTION] ERROR: Account locked - Time remaining: {$remaining_minutes} minutes, {$remaining_seconds} seconds");
            
            echo json_encode([
                'success' => false, 
                'message' => "Too many failed attempts. Please try again in {$remaining_minutes} minutes and {$remaining_seconds} seconds."
            ]);
            exit;
        }
    } else {
        $attempts = 0;
        logActivity("[CLIENT_SECRET_QUESTION] Step 8.1 - No previous attempts found");
    }

    // ==================== STEP 9: VERIFY PASSWORD ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 9: Verifying password");
    
    $passwordValid = password_verify($password, $client['password']);
    logActivity("[CLIENT_SECRET_QUESTION] Step 9.1 - Password validation: " . ($passwordValid ? 'VALID' : 'INVALID'));

    if (!$passwordValid) {
        $newAttempts = $attempts + 1;
        logActivity("[CLIENT_SECRET_QUESTION] Step 9.2 - Failed attempt - New count: {$newAttempts}");
        
        $lockedUntilTime = null;
        if ($newAttempts >= $max_attempts) {
            $lockedUntilTime = (new DateTime())->modify("+{$lockout_duration} minutes")->format('Y-m-d H:i:s');
            logActivity("[CLIENT_SECRET_QUESTION] Step 9.3 - Locking account until: {$lockedUntilTime}");
            insertLockHistory($conn, $client_code, $newAttempts);
        }
        
        updateLoginAttempts($conn, $client_code, $newAttempts, $lockedUntilTime);
        
        $remainingAttempts = $max_attempts - $newAttempts;
        $message = $remainingAttempts > 0 
            ? "Invalid password. You have {$remainingAttempts} attempt(s) remaining."
            : "Account locked for {$lockout_duration} minutes due to too many failed attempts.";
        
        logActivity("[CLIENT_SECRET_QUESTION] Step 9.4 - Response: {$message}");
        
        echo json_encode([
            'success' => false, 
            'message' => $message,
            'remaining_attempts' => $remainingAttempts > 0 ? $remainingAttempts : 0
        ]);
        exit;
    }
    
    logActivity("[CLIENT_SECRET_QUESTION] Step 9 - Password verified successfully");

    // ==================== STEP 10: SUCCESS - RESET ATTEMPTS ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 10: Success - Resetting attempts and preparing response");
    
    // Reset attempts on successful verification
    resetLoginAttempts($conn, $client_code);
    if ($attempts >= $max_attempts) {
        logActivity("[CLIENT_SECRET_QUESTION] Step 10.1 - Updating lock history (unlock)");
        updateLockHistory($conn, $client_code);
    }
    
    logActivity("[CLIENT_SECRET_QUESTION] Step 10.2 - Attempts reset for client: {$client_code}");
    logActivity("[CLIENT_SECRET_QUESTION] Step 10.3 - Secret question: {$client['secret_question']}");

    // ==================== STEP 11: RETURN SUCCESS RESPONSE ====================
    logActivity("[CLIENT_SECRET_QUESTION] Step 11: Returning success response");
    logActivity("[CLIENT_SECRET_QUESTION] Client: {$client_code}, Name: {$client['firstname']} {$client['lastname']}");
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] ========== GET SECRET QUESTION - SUCCESS ==========");
    
    echo json_encode([
        'success' => true,
        'secret_question' => $client['secret_question'],
        'client_name' => $client['firstname'] . ' ' . $client['lastname']
    ]);

} catch (Exception $e) {
    // ==================== ERROR HANDLING ====================
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] ========== GET SECRET QUESTION - ERROR ==========");
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Error Type: " . get_class($e));
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Error Line: " . $e->getLine());
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Stack Trace: " . $e->getTraceAsString());
    
    echo json_encode(['success' => false, 'message' => 'An internal error occurred. Please try again later.']);
}

// ==================== CLEANUP ====================
if (isset($conn) && $conn instanceof mysqli) {
    logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Closing database connection");
    $conn->close();
}

logActivity("[CLIENT_SECRET_QUESTION] [ID:{$requestId}] Script execution completed");
exit;
?>