<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// ==================== CONSTANTS & CONFIGURATION ====================
const MAX_LOGIN_ATTEMPTS = 3;
const LOCKOUT_DURATION_MINUTES = 60; // 1 hour
const SESSION_DURATION = 1800; // 30 minutes

function logWithCaller($message)
{
    // Get deeper caller information
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

    // Skip through the trace until we find a non-logging function
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

        $stmt = $this->conn->prepare("SELECT * FROM admin_tbl WHERE email = ? OR phone = ?");
        if (!$stmt) {
            logActivity("Database prepare failed: " . $this->conn->error);
            return [false, "Database error", 500];
        }

        $stmt->bind_param("ss", $username, $username);

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

        $this->log("User found - ID: {$user['unique_id']}, Status: {$user['status']}");
        return [true, $user, 200];
    }

    public function validateAccountStatus($user)
    {
        $userId = $user['unique_id'];

        // Check if account is blocked
        if ($user['block_id'] !== 0) {
            $this->log("Account blocked - ID: {$userId}");
            return [false, "This account has been blocked!", 403];
        }

        // Check if account is deactivated
        if ($user['status'] == '0') {
            $this->log("Account deactivated - ID: {$userId}");
            return [false, "This account has been deactivated. Please contact support", 403];
        }

        return [true, "Account is active", 200];
    }

    public function checkLockoutStatus($userId)
    {
        $this->log("Checking lockout status for user: {$userId}");

        $stmt = $this->conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ?");
        if (!$stmt) {
            logActivity("Lockout check prepare failed");
            return [false, "Database error", 500, null];
        }

        $stmt->bind_param("i", $userId);

        if (!$stmt->execute()) {
            logActivity("Lockout check execute failed: " . $stmt->error);
            $stmt->close();
            return [false, "Database error", 500, null];
        }

        $result = $stmt->get_result();

        // No previous attempts
        if ($result->num_rows === 0) {
            $stmt->close();
            return [true, "No lockout", 200, ['is_locked' => false, 'attempts' => 0]];
        }

        $data = $result->fetch_assoc();
        $stmt->close();

        $attempts = $data['attempts'];
        $lockedUntil = $data['locked_until'];
        $currentTime = new DateTime();

        // Check if account is locked
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
                // Lockout expired, reset attempts
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
            // Clear login attempts
            $stmt = $this->conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
            if (!$stmt) {
                logActivity("Failed to prepare delete statement");
                throw new Exception("Prepare failed");
            }

            $stmt->bind_param("i", $userId);
            if (!$stmt->execute()) {
                logActivity("Failed to execute delete statement: " . $stmt->error);
                throw new Exception("Execute failed");
            }
            $stmt->close();

            // Update lock history
            $stmt = $this->conn->prepare("
                UPDATE admin_lock_history 
                SET status = 'unlocked', 
                    unlocked_by = 0, 
                    unlock_method = 'System auto-unlock', 
                    unlocked_at = NOW() 
                WHERE unique_id = ? 
                AND status = 'locked'
                AND unlocked_at IS NULL
            ");

            if ($stmt) {
                $stmt->bind_param("i", $userId);
                if (!$stmt->execute()) {
                    logActivity("Failed to update lock history: " . $stmt->error);
                }
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

        // FIX: Properly handle null/empty lockoutInfo
        $attempts = 0;
        if (is_array($lockoutInfo) && isset($lockoutInfo['attempts'])) {
            $attempts = (int) $lockoutInfo['attempts'];
        } elseif (is_numeric($lockoutInfo)) {
            // If it somehow comes as a number directly
            $attempts = (int) $lockoutInfo;
        }

        $newAttempts = $attempts + 1;
        $attemptsRemaining = MAX_LOGIN_ATTEMPTS - $newAttempts;

        $this->conn->begin_transaction();

        try {
            // Update or insert login attempts
            if ($newAttempts === 1) {
                $stmt = $this->conn->prepare("INSERT INTO admin_login_attempts (unique_id, attempts, last_attempt) VALUES (?, ?, NOW())");
            } else {
                $stmt = $this->conn->prepare("UPDATE admin_login_attempts SET attempts = ?, last_attempt = NOW() WHERE unique_id = ?");
            }

            if (!$stmt) {
                logActivity("Failed to prepare login attempts statement");
                throw new Exception("Prepare failed");
            }

            // FIX: Bind parameters correctly based on query type
            if ($newAttempts === 1) {
                $stmt->bind_param("ii", $userId, $newAttempts);
            } else {
                $stmt->bind_param("ii", $newAttempts, $userId);
            }

            if (!$stmt->execute()) {
                logActivity("Failed to execute login attempts statement: " . $stmt->error);
                throw new Exception("Execute failed");
            }
            $stmt->close();

            // Lock account if max attempts reached
            if ($newAttempts >= MAX_LOGIN_ATTEMPTS) {
                $lockUntil = (new DateTime())->modify("+" . LOCKOUT_DURATION_MINUTES . " minutes");
                $lockPeriod = $lockUntil->format('Y-m-d H:i:s');

                // Update locked_until
                $stmt = $this->conn->prepare("UPDATE admin_login_attempts SET locked_until = ? WHERE unique_id = ?");
                if (!$stmt) {
                    logActivity("Failed to prepare lock statement");
                    throw new Exception("Prepare lock failed");
                }

                $stmt->bind_param("si", $lockPeriod, $userId);
                if (!$stmt->execute()) {
                    logActivity("Failed to execute lock statement: " . $stmt->error);
                    throw new Exception("Execute lock failed");
                }
                $stmt->close();

                // Record lock history
                $stmt = $this->conn->prepare("
                INSERT INTO admin_lock_history 
                (unique_id, status, locked_by, lock_reason, lock_method, locked_at) 
                VALUES (?, 'locked', 0, ?, 'Automatic lock', NOW())
            ");

                if ($stmt) {
                    $lockReason = "Account locked due to {$newAttempts} failed login attempts";
                    $stmt->bind_param("is", $userId, $lockReason);
                    if (!$stmt->execute()) {
                        logActivity("Failed to record lock history: " . $stmt->error);
                    }
                    $stmt->close();
                }

                $this->log("Account locked - ID: {$userId}, Until: {$lockPeriod}");
                $message = "Too many failed login attempts. Your account is locked for " . LOCKOUT_DURATION_MINUTES . " minutes.";
            } else {
                $message = "Invalid credentials. Attempts remaining: {$attemptsRemaining}";
            }

            $this->conn->commit();
            return [false, $message, 401, null];  // FIXED: Added 4th element

        } catch (Exception $e) {
            $this->conn->rollback();
            logActivity("Failed login processing error: " . $e->getMessage());
            return [false, "An error occurred during login", 500, null];  // FIXED: Added 4th element
        }
    }
    public function destroyExistingSession($userId)
    {
        $this->log("Checking for existing sessions for user: {$userId}");

        $stmt = $this->conn->prepare("SELECT session_id FROM admin_active_sessions WHERE unique_id = ?");
        if (!$stmt) {
            logActivity("Session check prepare failed");
            return false;
        }

        $stmt->bind_param("i", $userId);

        if (!$stmt->execute()) {
            logActivity("Session check execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows === 0) {
            $this->log("No active session found for user: {$userId}");
            return true; // No session to destroy is not an error
        }

        $sessionData = $result->fetch_assoc();
        $sessionId = $sessionData['session_id'];

        $this->log("Found active session - ID: {$sessionId} for user: {$userId}");

        try {
            // Check current session status
            $currentSessionId = session_id();

            // If we're already in a session, close it first
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Destroy the old session
            session_id($sessionId);
            session_start();
            session_destroy();

            // Remove from database
            $stmt = $this->conn->prepare("DELETE FROM admin_active_sessions WHERE unique_id = ?");
            if (!$stmt) {
                logActivity("Failed to prepare session delete statement");
                throw new Exception("Prepare delete failed");
            }

            $stmt->bind_param("i", $userId);
            if (!$stmt->execute()) {
                logActivity("Failed to execute session delete statement: " . $stmt->error);
                throw new Exception("Execute delete failed");
            }
            $stmt->close();

            $this->log("Session destroyed successfully for user: {$userId}");
            return true;

        } catch (Exception $e) {
            logActivity("Session destruction failed: " . $e->getMessage());
            return false;
        }
    }
    public function createNewSession($user)
    {
        $userId = $user['unique_id'];
        $this->log("Creating new session for user: {$userId}");

        // Close any existing session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Set session settings BEFORE starting
        ini_set('session.gc_maxlifetime', SESSION_DURATION);
        session_set_cookie_params([
            'lifetime' => SESSION_DURATION,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        // Start fresh session
        session_start();
        session_regenerate_id(true);

        // Set session data
        $_SESSION = [
            'unique_id' => $user['unique_id'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'role' => $user['role'],
            'restriction_id' => $user['restriction_id'],
            'secret_answer_hash' => md5($user['secret_answer']),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
            'last_activity' => time()
        ];

        $sessionId = session_id();
        $loginTime = date('Y-m-d H:i:s');

        // Record session in database
        $stmt = $this->conn->prepare("
        INSERT INTO admin_active_sessions 
        (unique_id, session_id, login_time, ip_address, user_agent, status) 
        VALUES (?, ?, ?, ?, ?, 'Active')
    ");

        if (!$stmt) {
            logActivity("Session recording prepare failed");
            return false;
        }

        // Extract variables to avoid reference issues
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt->bind_param(
            "issss",
            $userId,
            $sessionId,
            $loginTime,
            $ipAddress,
            $userAgent
        );

        if (!$stmt->execute()) {
            logActivity("Session recording failed: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $stmt->close();

        // Reset login attempts on successful login
        $this->resetLoginAttempts($userId);

        $this->log("New session created successfully - Session ID: {$sessionId}");
        return true;
    }

    public function processLogin($data)
    {
        $this->log("Login process started");

        // Step 1: Validate input
        list($valid, $result, $code) = $this->validateInput($data);
        if (!$valid) {
            return [$valid, $result, $code];
        }

        $username = $result['username'];
        $password = $result['password'];

        // Step 2: Find user
        list($found, $user, $code) = $this->findUserByCredentials($username);
        if (!$found) {
            return [$found, $user, $code];
        }

        $userId = $user['unique_id'];

        // Step 3: Validate account status
        list($active, $message, $code) = $this->validateAccountStatus($user);
        if (!$active) {
            return [$active, $message, $code];
        }

        // Step 4: Check lockout status
        list($unlocked, $message, $code, $lockoutInfo) = $this->checkLockoutStatus($userId);
        if (!$unlocked) {
            return [$unlocked, $message, $code];
        }

        // Step 5: Verify password
        if (!verifyAndRehashPassword($this->conn, $userId, $password, $user['password'])) {
            $this->log("Password verification failed for user: {$userId}");
            return $this->handleFailedLogin($userId, $lockoutInfo);
        }

        // Step 6: Destroy existing session (if any)
        if (!$this->destroyExistingSession($userId)) {
            $this->log("Warning: Failed to destroy existing session for user: {$userId}");
            // Continue anyway - don't block login due to session cleanup issues
        }

        // Step 7: Create new session
        if (!$this->createNewSession($user)) {
            logActivity("Session creation failed for user: {$userId}");
            return [false, "Session creation failed", 500];
        }

        $this->log("Login successful for user: {$userId}");
        return [
            true,
            "Login successful",
            200,
            [
                'user_id' => $userId,
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'role' => $user['role']
            ]
        ];
    }
}

// ==================== MAIN EXECUTION ====================
// At the very beginning of the file, after headers
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid request method: " . $_SERVER['REQUEST_METHOD'] . " from IP: " . $_SERVER['REMOTE_ADDR']);
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Method not allowed",
            "allowed_methods" => ["POST"]
        ]);
        exit;
    }

    // Get request data
    $input = file_get_contents('php://input');
    if (empty($input)) {
        logActivity("Empty request body from IP: " . $_SERVER['REMOTE_ADDR']);
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Request body is required"]);
        exit;
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Invalid JSON: " . json_last_error_msg() . " from IP: " . $_SERVER['REMOTE_ADDR']);
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid JSON format"]);
        exit;
    }

    // Create security instance
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
    logActivity("Login error: " . $e->getMessage() . " from IP: " . $_SERVER['REMOTE_ADDR']);
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