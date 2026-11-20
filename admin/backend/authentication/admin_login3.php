<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notifications.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION))
    session_start();
rateLimiter();

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

        $stmt = $this->conn->prepare("SELECT *,
            (password_changed = 0 AND has_secret_set = 0) AS is_first_login
         FROM admin_tbl
         WHERE email = ? OR phone = ? OR unique_id = ?");
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

    public function checkPasswordChangeRequired($user)
    {
        // Check if password_changed flag exists and is 0
        if (isset($user['password_changed']) && $user['password_changed'] == 0) {
            $this->log("Password change required for user: {$user['unique_id']}");
            return true;
        }
        return false;
    }

    public function checkSecretChangeRequired($user)
    {
        // Check if has_secret_set flag exists and is 0
        if (isset($user['has_secret_set']) && $user['has_secret_set'] == 0) {
            $this->log("Secret Question and Answer change required for user: {$user['unique_id']}");
            return true;
        }
        return false;
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

    public function checkSecretLockout($userId)
    {
        $this->log("Checking secret-answer lockout status for user: {$userId}");

        $stmt = $this->conn->prepare("
        SELECT attempts, locked_until
        FROM admin_secret_attempts
        WHERE unique_id = ?
        LIMIT 1
    ");
        if (!$stmt) {
            logActivity("Secret lockout check prepare failed: " . $this->conn->error);
            return [true, "No secret lockout", 200, null];  // fail-open on DB error (login should still work)
        }

        $stmt->bind_param("s", $userId);
        if (!$stmt->execute()) {
            logActivity("Secret lockout check execute failed: " . $stmt->error);
            $stmt->close();
            return [true, "No secret lockout", 200, null];
        }

        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $stmt->close();
            return [true, "No secret lockout", 200, null];
        }

        $data = $result->fetch_assoc();
        $stmt->close();

        $attempts = (int) $data['attempts'];
        $lockedUntil = $data['locked_until'];

        if ($attempts >= 3 && $lockedUntil) {
            $lockTime = new DateTime($lockedUntil);
            $currentTime = new DateTime();

            if ($currentTime < $lockTime) {
                $remaining = $currentTime->diff($lockTime);
                $mins = $remaining->i + ($remaining->h * 60);
                $secs = $remaining->s;
                $timeRemaining = "{$mins}m {$secs}s";

                $this->log("Secret-answer lockout active - ID: {$userId}, Time remaining: {$timeRemaining}");

                return [
                    false,
                    "Your account is temporarily locked due to failed security-answer attempts. Try again in {$timeRemaining}.",
                    423,
                    [
                        'is_locked' => true,
                        'lock_source' => 'secret_answer',
                        'locked_until' => $lockedUntil,
                        'time_remaining' => $timeRemaining,
                        'attempts' => $attempts,
                    ],
                ];
            }

            // Lock expired — clean up so the next attempt starts fresh
            $this->resetSecretAttempts($userId);
            return [true, "Secret lockout expired, attempts reset", 200, null];
        }

        return [true, "Not locked", 200, null];
    }

    private function resetSecretAttempts($userId)
    {
        $this->log("Resetting secret-answer attempts for user: {$userId}");

        $stmt = $this->conn->prepare("DELETE FROM admin_secret_attempts WHERE unique_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $stmt->close();
        }
    }


    public function handleFailedLogin($userId, $lockoutInfo = null, $context = 'login')
    {
        $this->log("Processing failed login for user: {$userId} (context: {$context})");

        // ─── Determine current attempt count ───
        $attempts = 0;
        if (is_array($lockoutInfo) && isset($lockoutInfo['attempts'])) {
            $attempts = (int) $lockoutInfo['attempts'];
        }

        // Don't double-count if already locked out — caller should have
        // caught this earlier via checkLockoutStatus(), but guard here anyway
        if (is_array($lockoutInfo) && !empty($lockoutInfo['is_locked'])) {
            $this->log("Attempt to increment while already locked - user: {$userId}");
            return [
                false,
                "Your account is locked. Please try again later.",
                423,
                $lockoutInfo,
            ];
        }

        $newAttempts = $attempts + 1;
        $attemptsRemaining = max(0, MAX_LOGIN_ATTEMPTS - $newAttempts);

        $this->conn->begin_transaction();

        try {
            // ─── Upsert the attempt row ───
            if ($newAttempts === 1) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO admin_login_attempts (unique_id, attempts, last_attempt)
                 VALUES (?, ?, NOW())"
                );
                if (!$stmt) {
                    throw new Exception("Prepare failed (insert): " . $this->conn->error);
                }
                $stmt->bind_param("si", $userId, $newAttempts);
            } else {
                $stmt = $this->conn->prepare(
                    "UPDATE admin_login_attempts
                 SET attempts = ?, last_attempt = NOW()
                 WHERE unique_id = ?"
                );
                if (!$stmt) {
                    throw new Exception("Prepare failed (update): " . $this->conn->error);
                }
                $stmt->bind_param("is", $newAttempts, $userId);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception("Execute failed: " . $err);
            }
            $stmt->close();

            // ─── Lockout logic ───
            $lockoutData = null;

            if ($newAttempts >= MAX_LOGIN_ATTEMPTS) {
                $lockUntil = (new DateTime())->modify("+" . LOCKOUT_DURATION_MINUTES . " minutes");
                $lockPeriod = $lockUntil->format('Y-m-d H:i:s');

                $stmt = $this->conn->prepare(
                    "UPDATE admin_login_attempts SET locked_until = ? WHERE unique_id = ?"
                );
                if (!$stmt) {
                    throw new Exception("Prepare failed (lockout): " . $this->conn->error);
                }
                $stmt->bind_param("ss", $lockPeriod, $userId);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new Exception("Lockout update failed: " . $err);
                }
                $stmt->close();

                $stmt = $this->conn->prepare("
                INSERT INTO admin_lock_history
                    (unique_id, status, locked_by, lock_reason, lock_method, locked_at)
                VALUES (?, 'locked', 0, ?, 'Automatic lock', NOW())
            ");
                if (!$stmt) {
                    throw new Exception("Prepare failed (lock history): " . $this->conn->error);
                }
                $lockReason = "Account locked due to {$newAttempts} failed login attempts (context: {$context})";
                $stmt->bind_param("ss", $userId, $lockReason);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new Exception("Lock history insert failed: " . $err);
                }
                $stmt->close();

                $this->log("Account locked - ID: {$userId}, Until: {$lockPeriod}");

                $message = "Too many failed login attempts. Your account is locked for "
                    . LOCKOUT_DURATION_MINUTES . " minutes.";

                $lockoutData = [
                    'is_locked' => true,
                    'locked_until' => $lockPeriod,
                    'attempts' => $newAttempts,
                    'source' => 'password',
                ];

                // ─── Notify super admins (best-effort) ───
                try {
                    createNotification($this->conn, [
                        'user_id' => $userId,
                        'title' => 'Account Lock Notification',
                        'message' => "User {$userId} account has been locked after {$newAttempts} failed login attempts.",
                        'type' => 'DANGER',
                        'category' => 'account_lock',
                    ]);
                    logActivity("[NOTIFICATION_SUCCESS] Lock notification created for user ID: {$userId}");
                } catch (Exception $e) {
                    logActivity("[NOTIFICATION_ERROR] Failed to create lock notification: " . $e->getMessage());
                }

            } else {
                // ─── Not yet locked — build context-aware message ───
                if ($context === 'first_login') {
                    $message = "Invalid credentials. If this is your first login, please check your onboarding email for setup instructions."
                        . "Attempts remaining: {$attemptsRemaining}";
                } else {
                    $message = "Invalid credentials. Attempts remaining: {$attemptsRemaining}";
                }
            }

            $this->conn->commit();

            return [
                false,
                $message,
                $newAttempts >= MAX_LOGIN_ATTEMPTS ? 423 : 401,
                $lockoutData,
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            logActivity("Failed login processing error: " . $e->getMessage());
            return [false, "An error occurred during login", 500, null];
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

        list($valid, $result, $code) = $this->validateInput($data);
        if (!$valid) {
            return [$valid, $result, $code, null];
        }

        $username = $result['username'];
        $password = $result['password'];

        list($found, $user, $code) = $this->findUserByCredentials($username);
        if (!$found) {
            return [$found, $user, $code, null];
        }

        $userId = $user['unique_id'];

        // ─── 1. LOCKOUT CHECK ───
        list($unlocked, $message, $code, $lockoutInfo) = $this->checkLockoutStatus($userId);
        if (!$unlocked) {
            return [$unlocked, $message, $code, $lockoutInfo ?? null];
        }

        // ─── 2. ACCOUNT STATUS CHECK ───
        list($active, $message, $code) = $this->validateAccountStatus($user);
        if (!$active) {
            return [$active, $message, $code, null];
        }

        // ─── 3. FIRST-LOGIN CHECK (before any password verification) ───
        // A fresh account has password_changed = 0 AND has_secret_set = 0.
        // They cannot log in until they complete the setup flow.
        // Send them to the setup page — no session, no attempt counter.
        $needsPasswordChange = $this->checkPasswordChangeRequired($user);
        $needsSecretChange = $this->checkSecretChangeRequired($user);

        if ($needsPasswordChange || $needsSecretChange) {
            $this->log("First-login redirect for {$userId} "
                . "(password_changed=" . (int) $needsPasswordChange
                . ", has_secret_set=" . (int) $needsSecretChange . ")");

            return [
                false,
                "Your account hasn't been set up yet. Please complete your account setup first.",
                403,
                [
                    'requires_setup' => true,
                    'setup_url' => '/rent_manager/admin/pages/account_setup.php',
                    'email_hint' => $user['email'] ?? null,
                ],
            ];
        }

        // ─── 4. NORMAL PASSWORD VERIFY ───
        if (!verifyAndRehashPassword($this->conn, $userId, $password, $user['password'])) {
            $this->log("Password verification failed for user: {$userId}");
            return $this->handleFailedLogin($userId, $lockoutInfo);
        }

        // ─── 5. SECRET LOCKOUT CHECK ───
        list($secretUnlocked, $secretMessage, $secretCode, $secretInfo) = $this->checkSecretLockout($userId);
        if (!$secretUnlocked) {
            $this->log("Login blocked by secret-answer lockout for user: {$userId}");
            return [$secretUnlocked, $secretMessage, $secretCode, $secretInfo];
        }

        // ─── 6. FULL LOGIN ───
        if (!$this->destroyExistingSession($userId)) {
            $this->log("Warning: Failed to destroy existing session for {$userId}");
        }

        if (!$this->createNewSession($user)) {
            logActivity("Session creation failed for user: {$userId}");
            return [false, "Session creation failed", 500, null];
        }

        list($updated, , ) = $this->updateAdminRecord($userId);
        if (!$updated) {
            $this->log("Warning: Failed to update last login time for {$userId}");
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

    public function updateAdminRecord($username)
    {
        $this->log("Updating Admin Table for Username: {$username}");

        $stmt = $this->conn->prepare(
            "UPDATE admin_tbl SET last_login_at = ? WHERE unique_id = ?"
        );

        if (!$stmt) {
            $this->log("ERROR: updateAdminRecord prepare failed: " . $this->conn->error);
            return [false, "Database error", 500];
        }

        $currentTime = (new DateTime())->format('Y-m-d H:i:s');
        $loginAttempts = 0;

        $stmt->bind_param("ss", $currentTime, $username);

        if (!$stmt->execute()) {
            $errMsg = $stmt->error;
            $stmt->close();
            $this->log("ERROR: updateAdminRecord execute failed for {$username}: " . $errMsg);
            return [false, "Database error", 500];
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        // affected_rows === 0 means the row exists but nothing changed
        // (last_login_at was already the current second, or login_attempts was already 0).
        // The row's existence was validated upstream in findUserByCredentials, so this
        // is informational, not an error.
        if ($affected === 0) {
            $this->log("INFO: updateAdminRecord made no change for {$username} (already current)");
        }

        return [true, "Update successful", 200];
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

    // if ($success && isset($additionalData)) {
    //     $response['data'] = $additionalData;
    // }
    if (isset($additionalData) && !empty($additionalData)) {
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
