<?php
// Make sure NO white space before this line
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

$requestId = uniqid('tenant_secret_', true);
logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Request started");

// Get tenant by email or phone
function getTenantByIdentifier($conn, $identifier) {
    $stmt = $conn->prepare("
        SELECT 
            tenant_code, 
            firstname, 
            lastname, 
            email, 
            secret_question, 
            has_secret_set,
            status,
            password
        FROM tenants 
        WHERE email = ? OR phone = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenant = $result->fetch_assoc();
    $stmt->close();
    return $tenant;
}

// Get login attempts
function getLoginAttempts($conn, $identifier) {
    $stmt = $conn->prepare("SELECT attempts, locked_until FROM tenant_login_attempts WHERE tenant_code = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempts = $result->fetch_assoc();
    $stmt->close();
    return $attempts;
}

// Update login attempts
function updateLoginAttempts($conn, $identifier, $attempts, $locked_until = null) {
    // Check if record exists
    $checkStmt = $conn->prepare("SELECT id FROM tenant_login_attempts WHERE tenant_code = ?");
    $checkStmt->bind_param("s", $identifier);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();
    
    if ($exists) {
        $stmt = $conn->prepare("UPDATE tenant_login_attempts SET attempts = ?, locked_until = ?, last_attempt = NOW() WHERE tenant_code = ?");
        if (!$stmt) return false;
        $stmt->bind_param("iss", $attempts, $locked_until, $identifier);
    } else {
        $stmt = $conn->prepare("INSERT INTO tenant_login_attempts (tenant_code, attempts, locked_until, last_attempt) VALUES (?, ?, ?, NOW())");
        if (!$stmt) return false;
        $stmt->bind_param("sis", $identifier, $attempts, $locked_until);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Reset login attempts
function resetLoginAttempts($conn, $identifier) {
    $stmt = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
    if (!$stmt) return false;
    $stmt->bind_param("s", $identifier);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Insert lock history - matches your table structure
function insertLockHistory($conn, $tenant_code, $attempts) {
    $stmt = $conn->prepare("
        INSERT INTO tenant_lock_history 
        (tenant_code, status, locked_by, lock_reason, lock_method, locked_at) 
        VALUES (?, 'locked', 0, ?, 'Automatic lock', NOW())
    ");
    if (!$stmt) return false;
    $lock_reason = "Account locked due to {$attempts} failed secret question attempts";
    $stmt->bind_param("ss", $tenant_code, $lock_reason);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Update lock history when unlocked
function updateLockHistory($conn, $tenant_code) {
    $stmt = $conn->prepare("
        UPDATE tenant_lock_history 
        SET status = 'unlocked', 
            unlocked_by = 0, 
            unlock_method = 'Auto after successful verification',
            unlocked_at = NOW() 
        WHERE tenant_code = ? AND status = 'locked' AND unlocked_at IS NULL
    ");
    if (!$stmt) return false;
    $stmt->bind_param("s", $tenant_code);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// ========== MAIN EXECUTION ==========

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method. Use POST.']);
        exit;
    }

    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }

    $identifier = isset($input['identifier']) ? trim($input['identifier']) : '';
    $password = isset($input['password']) ? $input['password'] : '';

    if (empty($identifier) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email/Phone and password are required']);
        exit;
    }

    logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Looking for tenant: " . substr($identifier, 0, 10) . '...');

    // Get tenant
    $tenant = getTenantByIdentifier($conn, $identifier);
    
    if (!$tenant) {
        logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Tenant not found");
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }

    $tenant_code = $tenant['tenant_code'];

    // Check status
    if ($tenant['status'] != 1) {
        echo json_encode(['success' => false, 'message' => 'Account is inactive. Please contact support.']);
        exit;
    }

    // Check if secret question exists
    if (!$tenant['has_secret_set'] || empty($tenant['secret_question'])) {
        echo json_encode(['success' => false, 'message' => 'Secret question has not been set for this account.']);
        exit;
    }

    // Check login attempts
    $attemptData = getLoginAttempts($conn, $tenant_code);
    $current_time = new DateTime();
    $max_attempts = 3;
    $lockout_duration = 15; // minutes

    if ($attemptData) {
        $attempts = $attemptData['attempts'] ?? 0;
        $locked_until = $attemptData['locked_until'] ? new DateTime($attemptData['locked_until']) : null;
        
        logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Current attempts: {$attempts}");

        if ($attempts >= $max_attempts && $locked_until && $current_time < $locked_until) {
            $remaining = $current_time->diff($locked_until);
            $remaining_minutes = $remaining->i;
            $remaining_seconds = $remaining->s;
            
            echo json_encode([
                'success' => false, 
                'message' => "Too many failed attempts. Please try again in {$remaining_minutes} minutes and {$remaining_seconds} seconds."
            ]);
            exit;
        }
    } else {
        $attempts = 0;
    }

    // Verify password
    $passwordValid = password_verify($password, $tenant['password']);
    logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Password validation: " . ($passwordValid ? 'valid' : 'invalid'));

    if (!$passwordValid) {
        $newAttempts = $attempts + 1;
        logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Failed attempt count: {$newAttempts}");
        
        $lockedUntilTime = null;
        if ($newAttempts >= $max_attempts) {
            $lockedUntilTime = (new DateTime())->modify("+{$lockout_duration} minutes")->format('Y-m-d H:i:s');
            logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Locking account until: {$lockedUntilTime}");
            insertLockHistory($conn, $tenant_code, $newAttempts);
        }
        
        updateLoginAttempts($conn, $tenant_code, $newAttempts, $lockedUntilTime);
        
        $remainingAttempts = $max_attempts - $newAttempts;
        $message = $remainingAttempts > 0 
            ? "Invalid password. You have {$remainingAttempts} attempt(s) remaining."
            : "Account locked for {$lockout_duration} minutes due to too many failed attempts.";
        
        echo json_encode([
            'success' => false, 
            'message' => $message,
            'remaining_attempts' => $remainingAttempts > 0 ? $remainingAttempts : 0
        ]);
        exit;
    }

    // Success - password is valid
    logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Success for tenant: {$tenant_code}");

    // Reset attempts on successful verification
    resetLoginAttempts($conn, $tenant_code);
    if ($attempts >= $max_attempts) {
        updateLockHistory($conn, $tenant_code);
    }

    echo json_encode([
        'success' => true,
        'secret_question' => $tenant['secret_question'],
        'tenant_name' => $tenant['firstname'] . ' ' . $tenant['lastname']
    ]);

} catch (Exception $e) {
    logActivity("[TENANT_SECRET_QUESTION] [ID:{$requestId}] Exception: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred. Please try again later.']);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
exit;
?>