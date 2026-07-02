<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notifications.php';

// ==================== CONSTANTS & CONFIGURATION ====================
const MAX_LOGIN_ATTEMPTS = 3;
const LOCKOUT_DURATION_MINUTES = 60; // 1 hour
const SESSION_DURATION = 1800; // 30 minutes

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function logWithCaller($message)
{
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
    $caller = null;
    $skipFunctions = ['log', 'logWithCaller', 'logActivity'];

    foreach ($backtrace as $trace) {
        if (!in_array($trace['function'], $skipFunctions)) {
            $caller = $trace;
            break;
        }
    }

    if ($caller) {
        $source = basename($caller['file']);
        $line = $caller['line'];
        $message = "[{$source}:{$line}] " . $message;
    }

    logActivity($message);
}

// ==================== HELPER CLASSES ====================
class LoginSecurity
{
    private $conn;
    private $requestId;

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->requestId = uniqid('login_', true);
    }

    private function log($message)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        logWithCaller("[LOGIN] [ID:{$this->requestId}] [IP:{$ip}] {$message}");
    }

    public function validateInput($data)
    {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            $this->log("Empty credentials provided");
            return [false, "Username and password are required", 400];
        }

        return [true, compact('username', 'password'), 200];
    }

    public function findUserByCredentials($username)
    {
        $this->log("Searching for user: {$username}");

        $stmt = $this->conn->prepare("SELECT * FROM clients WHERE email = ? OR phone = ? OR client_code = ?");
        if (!$stmt) {
            logActivity("Database prepare failed: " . $this->conn->error);
            return [false, "Database error", 500];
        }

        $stmt->bind_param("sss", $username, $username, $username);

        if (!$stmt->execute()) {
            logActivity("Database execute failed: " . $stmt->error);
            $stmt->close();
            return [false, "Database error", 500];
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $this->log("User not found: {$username}");
            $stmt->close();
            return [false, "Invalid credentials", 401];
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        $this->log("User found - ID: {$user['client_code']}, Status: {$user['status']}");
        return [true, $user, 200];
    }

    public function validateAccountStatus($user)
    {
        $userId = $user['client_code'];

        // Check if account is blocked
        if ($user['client_status'] !== '1') {
            $this->log("Account blocked - ID: {$userId}");
            return [false, "This account has been blocked!", 403];
        }

        // Check if account is deactivated
        if ($user['status'] == '0') {
            $this->log("Account deactivated - ID: {$userId}");
            return [false, "Account Deactivated, Raise a Reactivation Request", 403];
        }

        return [true, "Account is active", 200];
    }

    public function checkPasswordChangeRequired($user)
    {
        // Check if password_changed flag exists and is 0
        if (isset($user['password_changed']) && $user['password_changed'] == 0) {
            $this->log("Password change required for user: {$user['client_code']}");
            return true;
        }
        return false;
    }

    public function checkLockoutStatus($userId)
    {
        $this->log("Checking lockout status for user: {$userId}");

        $stmt = $this->conn->prepare("SELECT attempts, locked_until FROM client_login_attempts WHERE client_code = ?");
        if (!$stmt) {
            logActivity("Lockout check prepare failed");
            return [false, "Database error", 500, null];
        }

        $stmt->bind_param("s", $userId);

        if (!$stmt->execute()) {
            logActivity("Lockout check execute failed: " . $stmt->error);
            $stmt->close();
            return [false, "Database error", 500, null];
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return [true, "No lockout", 200, ['is_locked' => false, 'attempts' => 0]];
        }

        $data = $result->fetch_assoc();
        $stmt->close();

        $attempts = $data['attempts'];
        $lockedUntil = $data['locked_until'];
        $currentTime = new DateTime();

        if ($lockedUntil && $attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockTime = new DateTime($lockedUntil);

            if ($currentTime < $lockTime) {
                $remaining = $lockTime->diff($currentTime);
                $timeRemaining = $remaining->format('%i minutes %s seconds');

                $this->log("Account locked - ID: {$userId}, Time remaining: {$timeRemaining}");
                return [
                    false,
                    "Your account is locked. Please try again in {$timeRemaining}",
                    423,
                    [
                        'is_locked' => true,
                        'time_remaining' => $timeRemaining,
                        'attempts' => $attempts
                    ]
                ];
            } else {
                $this->resetLoginAttempts($userId);
                return [true, "Lock expired, attempts reset", 200, ['is_locked' => false, 'attempts' => 0]];
            }
        }

        return [true, "Not locked", 200, ['is_locked' => false, 'attempts' => $attempts]];
    }

    private function resetLoginAttempts($userId)
    {
        $this->log("Resetting login attempts for user: {$userId}");

        $this->conn->begin_transaction();

        try {
            $stmt = $this->conn->prepare("DELETE FROM client_login_attempts WHERE client_code = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed");
            }

            $stmt->bind_param("s", $userId);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $stmt->close();

            $stmt = $this->conn->prepare("
                UPDATE client_lock_history 
                SET status = 'unlocked', 
                    unlocked_by = 0, 
                    unlock_method = 'System auto-unlock', 
                    unlocked_at = NOW() 
                WHERE client_code = ? 
                AND status = 'locked'
                AND unlocked_at IS NULL
            ");

            if ($stmt) {
                $stmt->bind_param("s", $userId);
                $stmt->execute();
                $stmt->close();
            }

            $this->conn->commit();
            $this->log("Login attempts reset successfully for user: {$userId}");

        } catch (Exception $e) {
            $this->conn->rollback();
            logActivity("Failed to reset login attempts: " . $e->getMessage());
        }
    }

    public function handleFailedLogin($userId, $lockoutInfo = null)
    {
        $this->log("Processing failed login for user: {$userId}");

        $attempts = 0;
        if (is_array($lockoutInfo) && isset($lockoutInfo['attempts'])) {
            $attempts = (int) $lockoutInfo['attempts'];
        }

        $newAttempts = $attempts + 1;
        $attemptsRemaining = MAX_LOGIN_ATTEMPTS - $newAttempts;

        $this->conn->begin_transaction();

        try {
            if ($newAttempts === 1) {
                $stmt = $this->conn->prepare("INSERT INTO client_login_attempts (client_code, attempts, last_attempt) VALUES (?, ?, NOW())");
                $stmt->bind_param("si", $userId, $newAttempts);
            } else {
                $stmt = $this->conn->prepare("UPDATE client_login_attempts SET attempts = ?, last_attempt = NOW() WHERE client_code = ?");
                $stmt->bind_param("is", $newAttempts, $userId);
            }

            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $stmt->close();

            if ($newAttempts >= MAX_LOGIN_ATTEMPTS) {
                $lockUntil = (new DateTime())->modify("+" . LOCKOUT_DURATION_MINUTES . " minutes");
                $lockPeriod = $lockUntil->format('Y-m-d H:i:s');

                $stmt = $this->conn->prepare("UPDATE client_login_attempts SET locked_until = ? WHERE client_code = ?");
                $stmt->bind_param("ss", $lockPeriod, $userId);
                $stmt->execute();
                $stmt->close();

                $stmt = $this->conn->prepare("
                    INSERT INTO client_lock_history 
                    (client_code, status, locked_by, lock_reason, lock_method, locked_at) 
                    VALUES (?, 'locked', 0, ?, 'Automatic lock', NOW())
                ");
                $lockReason = "Account locked due to {$newAttempts} failed login attempts";
                $stmt->bind_param("ss", $userId, $lockReason);
                $stmt->execute();
                $stmt->close();

                $this->log("Account locked - ID: {$userId}, Until: {$lockPeriod}");
                $message = "Too many failed login attempts. Your account is locked for " . LOCKOUT_DURATION_MINUTES . " minutes.";
                
                // Create and notify Super Admin on account lock
                try {
                    createNotification($this->conn, [
                        'user_id' => $userId,
                        'title' => 'Account Lock Notification',
                        'message' => "User {$userId} account has been locked after too many failed login attempts.",
                        'type' => 'DANGER',
                        'category' => 'account_lock'
                    ]);
                    logActivity("Notification created for user ID: {$userId}");
                } catch (Exception $e) {
                    logActivity("[NOTIFICATION_ERROR] Failed to create user notification: " . $e->getMessage());
                }
            } else {
                $message = "Invalid credentials. Attempts remaining: {$attemptsRemaining}";
            }

            $this->conn->commit();
            return [false, $message, 401, null];

        } catch (Exception $e) {
            $this->conn->rollback();
            logActivity("Failed login processing error: " . $e->getMessage());
            return [false, "An error occurred during login", 500, null];
        }
    }

    public function processLogin($data)
    {
        $this->log("Login process started");

        // Step 1: Validate input
        list($valid, $result, $code) = $this->validateInput($data);
        if (!$valid) {
            return [$valid, $result, $code, null];
        }

        $username = $result['username'];
        $password = $result['password'];

        // Step 2: Find user
        list($found, $user, $code) = $this->findUserByCredentials($username);
        if (!$found) {
            return [$found, $user, $code, null];
        }

        $userId = $user['client_code'];

        // Step 3: Validate account status
        list($active, $message, $code) = $this->validateAccountStatus($user);
        if (!$active) {
            return [$active, $message, $code, null];
        }

        // Step 4: Check lockout status
        list($unlocked, $message, $code, $lockoutInfo) = $this->checkLockoutStatus($userId);
        if (!$unlocked) {
            return [$unlocked, $message, $code, $lockoutInfo ?? null];
        }

        // Step 5: Verify password
        if (!verifyAndRehashPassword($this->conn, $userId, $password, $user['password'])) {
            $this->log("Password verification failed for user: {$userId}");
            return $this->handleFailedLogin($userId, $lockoutInfo);
        }

        // Step 6: Check if password change is required (first login)
        $needsPasswordChange = $this->checkPasswordChangeRequired($user);

        // If password change is required, store token in database and return early
        if ($needsPasswordChange) {
            $this->log("Password change required for user: {$userId}");

            // Generate a temporary token
            $tempToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Store token in database
            $stmt = $this->conn->prepare("
                INSERT INTO temp_auth_tokens (user_id, user_type, token, expires_at) 
                VALUES (?, 'client', ?, ?)
            ");
            $stmt->bind_param("sss", $userId, $tempToken, $expiresAt);
            $stmt->execute();
            $stmt->close();

            // Also store in session as a fallback (but primary is database)
            $_SESSION['temp_auth_token'][$userId] = [
                'token' => $tempToken,
                'expires' => strtotime($expiresAt),
                'user_data' => [
                    'client_code' => $user['client_code'],
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname'],
                    'email' => $user['email']
                ]
            ];

            $this->log("Temp token stored in database for user: {$userId}, expires: {$expiresAt}");

            return [
                true,
                "Password change required",
                200,
                [
                    'user_id' => $userId,
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname'],
                    'needs_password_change' => true,
                    'requires_action' => true,
                    'temp_token' => $tempToken
                ]
            ];
        }

        // Step 7: Destroy existing session (if any) - only if no password change required
        if (!$this->destroyExistingSession($userId)) {
            $this->log("Warning: Failed to destroy existing session for user: {$userId}");
        }

        // Step 8: Create new session
        if (!$this->createNewSession($user)) {
            logActivity("Session creation failed for user: {$userId}");
            return [false, "Session creation failed", 500, null];
        }

        // Step 9: Update clients table (last login time)
        list($updated, , ) = $this->updateClientRecord($userId);
        if (!$updated) {
            $this->log("Warning: Failed to update last login time for user: {$userId}");
        }

        $this->log("Login successful for user: {$userId}");

        // Build response data
        $responseData = [
            'user_id' => $userId,
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'needs_password_change' => false,
            'requires_action' => false
        ];

        return [
            true,
            "Login successful",
            200,
            $responseData
        ];
    }

    public function destroyExistingSession($userId)
    {
        $this->log("Checking for existing sessions for user: {$userId}");

        $stmt = $this->conn->prepare("SELECT session_id FROM client_active_sessions WHERE client_code = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows === 0) {
            return true;
        }

        $sessionData = $result->fetch_assoc();
        $sessionId = $sessionData['session_id'];

        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            session_id($sessionId);
            session_start();
            session_destroy();

            $stmt = $this->conn->prepare("DELETE FROM client_active_sessions WHERE client_code = ?");
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $stmt->close();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function createNewSession($user)
    {
        $userId = $user['client_code'];
        $this->log("Creating new session for user: {$userId}");

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ini_set('session.gc_maxlifetime', SESSION_DURATION);
        session_set_cookie_params([
            'lifetime' => SESSION_DURATION,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        session_start();
        session_regenerate_id(true);

        $_SESSION = [
            'client_code' => $user['client_code'],
            'client_logged_in' => true,
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'role' => "Client",
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
            'last_activity' => time()
        ];

        $sessionId = session_id();
        $loginTime = date('Y-m-d H:i:s');

        $stmt = $this->conn->prepare("
            INSERT INTO client_active_sessions 
            (client_code, session_id, login_time, ip_address, user_agent, status) 
            VALUES (?, ?, ?, ?, ?, 'Active')
        ");

        if (!$stmt) {
            return false;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt->bind_param("sssss", $userId, $sessionId, $loginTime, $ipAddress, $userAgent);
        $stmt->execute();
        $stmt->close();

        $this->resetLoginAttempts($userId);
        $this->log("New session created successfully - Session ID: {$sessionId}");
        return true;
    }

    public function updateClientRecord($username)
    {
        $this->log("Updating Client Table for Username: {$username}");

        $stmt = $this->conn->prepare(
            "UPDATE clients SET last_login = ?, login_attempts = ? WHERE client_code = ?"
        );

        if (!$stmt) {
            return [false, "Database error", 500];
        }

        $currentTime = (new DateTime())->format('Y-m-d H:i:s');
        $loginAttempts = 0; // Reset attempts on successful login

        $stmt->bind_param("sis", $currentTime, $loginAttempts, $username);

        if (!$stmt->execute()) {
            $stmt->close();
            return [false, "Database error", 500];
        }

        $stmt->close();
        return [true, "Update successful", 200];
    }
}

// ==================== MAIN EXECUTION ====================
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Method not allowed"
        ]);
        exit;
    }

    $input = file_get_contents('php://input');
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Request body is required"]);
        exit;
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid JSON format"]);
        exit;
    }

    $security = new LoginSecurity($conn);

    // Process login
    list($success, $message, $code, $additionalData) = $security->processLogin($data);

    http_response_code($code);

    $response = [
        "success" => $success,
        "message" => $message
    ];

    if ($success && isset($additionalData)) {
        $response['data'] = $additionalData;
    }

    echo json_encode($response);

} catch (Exception $e) {
    logActivity("Login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An unexpected error occurred",
        "error" => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>