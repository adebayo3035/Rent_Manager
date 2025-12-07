<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
function destroySession($uniqueId, $conn)
{
    // Validate input
    if (!is_numeric($uniqueId)) {
        logActivity("Invalid unique_id provided for session destruction: " . $uniqueId);
        return false; // Invalid input is a real error
    }

    logActivity("Checking for existing sessions for user: " . $uniqueId);

    $stmt = $conn->prepare("SELECT session_id FROM admin_active_sessions WHERE unique_id = ?");
    if (!$stmt) {
        logActivity("Prepare failed: " . $conn->error);
        return false; // Database error is a real error
    }

    $stmt->bind_param("i", $uniqueId);

    if (!$stmt->execute()) {
        logActivity("Database error when fetching session: " . $stmt->error);
        $stmt->close();
        return false; // Database error is a real error
    }

    $result = $stmt->get_result();

    // No existing session is NOT an error condition
    if ($result->num_rows === 0) {
        logActivity("No active session found - safe to proceed");
        $stmt->close();
        return true; // Specifically allow login to continue
    }

    $sessionData = $result->fetch_assoc();
    $sessionId = $sessionData['session_id'];
    $stmt->close();

    logActivity("Found active session (ID: $sessionId) - attempting destruction");

    try {
        // Destroy PHP session
        session_id($sessionId);
        session_start();
        $_SESSION = array();

        if (!session_destroy()) {
            logActivity("Failed to destroy PHP session");
            return false;
        }

        // Remove from database
        $deleteStmt = $conn->prepare("DELETE FROM admin_active_sessions WHERE unique_id = ?");
        if (!$deleteStmt || !$deleteStmt->bind_param("i", $uniqueId) || !$deleteStmt->execute()) {
            logActivity("Failed to remove session record: " . ($deleteStmt->error ?? 'Unknown'));
            return false;
        }

        logActivity("Session destroyed successfully");
        return true;

    } catch (Exception $e) {
        logActivity("Exception during session destruction: " . $e->getMessage());
        return false;
    } finally {
        if (isset($deleteStmt) && $deleteStmt instanceof mysqli_stmt) {
            $deleteStmt->close();
        }
    }
}

$data = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Retrieve username and password
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        //$encrypted_password = md5($password);

        // Validate inputs
        if (empty($username) || empty($password)) {
            logActivity("Login attempt with empty credentials from IP: " . $_SERVER['REMOTE_ADDR']);
            echo json_encode(["success" => false, "message" => "Username and password are required"]);
            exit;
        }

        logActivity("Login attempt initiated for username: " . $username . " from IP: " . $_SERVER['REMOTE_ADDR']);

        // Step 1: Fetch user data
        $stmt = $conn->prepare("SELECT * FROM admin_tbl WHERE email = ? OR phone = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            logActivity("Login failed - User not found: " . $username . " from IP: " . $_SERVER['REMOTE_ADDR']);
            echo json_encode(["success" => false, "message" => "Invalid credentials"]);
            exit;
        }

        $row = $result->fetch_assoc();
        $unique_id = $row['unique_id'];
        $block_id = $row['block_id'];
        $delete_status = $row['delete_status'];
        $admin_password = $row['password'];

        logActivity("User found - ID: $unique_id, Block Status: $block_id, Delete Status: $delete_status");

        // Check account status
        if ($block_id !== 0) {
            logActivity("Login blocked - Account $unique_id is blocked. Attempt from IP: " . $_SERVER['REMOTE_ADDR']);
            echo json_encode(["success" => false, "message" => "This account has been blocked!"]);
            exit;
        }

        if ($delete_status == 'Yes') {
            logActivity("Login blocked - Account $unique_id is deactivated. Attempt from IP: " . $_SERVER['REMOTE_ADDR']);
            echo json_encode(["success" => false, "message" => "This account has been deactivated. Please contact support"]);
            exit;
        }

        // Check lockout status
        $max_attempts = 3;
        $lockout_duration = 60; // minutes
        $lockout_status = checkLockoutStatus($conn, $unique_id, $max_attempts, $lockout_duration);

        if ($lockout_status['is_locked']) {
            logActivity("Login blocked - Account $unique_id is temporarily locked. Time remaining: " . $lockout_status['time_remaining'] . ". Attempt from IP: " . $_SERVER['REMOTE_ADDR']);
            echo json_encode(["success" => false, "message" => "Your account is locked. Please try again in " . $lockout_status['time_remaining']]);
            exit;
        }
        // Verify password
        if (!verifyAndRehashPassword($conn, $unique_id, $password, $admin_password)) {
            logActivity("Login failed - Invalid password for user $unique_id from IP: " . $_SERVER['REMOTE_ADDR']);
            handleFailedLogin($conn, $unique_id, $max_attempts, $lockout_duration);
            echo json_encode(["success" => false, "message" => "Invalid credentials"]);
            exit;
        }

        // Process for Successful login
        destroySession($unique_id, $conn);

        // Start secure session
        session_start();
        session_regenerate_id(true);

        // Set session parameters
        $_SESSION = [
            'unique_id' => $row['unique_id'],
            'firstname' => $row['firstname'],
            'lastname' => $row['lastname'],
            'role' => $row['role'],
            'restriction_id' => $row['restriction_id'],
            'secret_answer' => md5($row['secret_answer']),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'created_at' => time()
        ];

        // Record session
        $newSessionId = session_id();
        $loginTime = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO admin_active_sessions (unique_id, session_id, login_time, ip_address, status) VALUES (?, ?, ?, ?, 'Active')");
        $stmt->bind_param("isss", $unique_id, $newSessionId, $loginTime, $_SERVER['REMOTE_ADDR']);
        $stmt->execute();

        // Reset login attempts
        $stmtResetAttempts = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
        $stmtResetAttempts->bind_param("i", $unique_id);
        $stmtResetAttempts->execute();

        logActivity("Login successful for user $unique_id from IP: " . $_SERVER['REMOTE_ADDR']);
        echo json_encode(["success" => true, "message" => "Login successful"]);

    } catch (Exception $e) {
        logActivity("Login error: " . $e->getMessage() . " from IP: " . $_SERVER['REMOTE_ADDR']);
        echo json_encode(["success" => false, "message" => "An error occurred during login"]);
    }
} else {
    logActivity("Invalid request method " . $_SERVER['REQUEST_METHOD'] . " from IP: " . $_SERVER['REMOTE_ADDR']);
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

// Helper function for lockout check
function checkLockoutStatus($conn, $unique_id, $max_attempts, $lockout_duration)
{
    try {
        logActivity("Checking lockout status for user: $unique_id (Lockout Duration: $lockout_duration minutes)");

        $stmt = $conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ?");
        if (!$stmt) {
            logActivity("Database prepare error: " . $conn->error);
            return ['is_locked' => false];
        }

        $stmt->bind_param("i", $unique_id);
        if (!$stmt->execute()) {
            logActivity("Database execute error: " . $stmt->error);
            return ['is_locked' => false];
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            logActivity("No lockout record found for user: " . $unique_id);
            return ['is_locked' => false];
        }

        $data = $result->fetch_assoc();
        $attempts = $data['attempts'];
        $locked_until = $data['locked_until'];
        $current_time = new DateTime();

        if ($locked_until) {
            $locked_until_time = new DateTime($locked_until);

            // Check if account is currently locked
            if ($attempts >= $max_attempts && $current_time < $locked_until_time) {
                $time_remaining = $locked_until_time->diff($current_time)->format('%i minutes %s seconds');
                logActivity("Account " . $unique_id . " is locked. Time remaining: " . $time_remaining);
                return ['is_locked' => true, 'time_remaining' => $time_remaining];
            }

            // Check if lockout period has expired and needs to be cleared
            if ($attempts >= $max_attempts && $current_time >= $locked_until_time) {
                logActivity("Lockout period expired for user: " . $unique_id . " - auto-unlocking account");

                // Start transaction for atomic operations
                $conn->begin_transaction();

                try {
                    // Clear login attempts
                    $stmtReset = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
                    $stmtReset->bind_param("i", $unique_id);

                    if (!$stmtReset->execute()) {
                        throw new Exception("Failed to reset login attempts: " . $stmtReset->error);
                    }

                    // Update lock history
                    $status = 'unlocked';
                    $unlock_method = 'System auto-unlock';
                    $adminID = 0; // System ID

                    $stmtUnlockHistory = $conn->prepare("
                        UPDATE admin_lock_history 
                        SET status = ?, 
                            unlocked_by = ?, 
                            unlock_method = ?, 
                            unlocked_at = NOW() 
                        WHERE unique_id = ? 
                        AND status = 'locked'
                        AND unlocked_at IS NULL
                    ");
                    $stmtUnlockHistory->bind_param("sisi", $status, $adminID, $unlock_method, $unique_id);

                    if (!$stmtUnlockHistory->execute()) {
                        throw new Exception("Failed to update lock history: " . $stmtUnlockHistory->error);
                    }

                    $conn->commit();
                    logActivity("Successfully unlocked account: " . $unique_id);

                } catch (Exception $e) {
                    $conn->rollback();
                    logActivity("Error during auto-unlock for " . $unique_id . ": " . $e->getMessage());
                    throw $e;
                }
            }
        }

        return ['is_locked' => false];

    } catch (Exception $e) {
        logActivity("Error in checkLockoutStatus for " . $unique_id . ": " . $e->getMessage());
        return ['is_locked' => false]; // Fail open to prevent locking out legitimate users
    } finally {
        if (isset($stmt))
            $stmt->close();
        if (isset($stmtReset))
            $stmtReset->close();
        if (isset($stmtUnlockHistory))
            $stmtUnlockHistory->close();
    }
}

// Function to handle failed login attempts
function handleFailedLogin($conn, $unique_id, $max_attempts, $lockout_duration)
{
    try {
        // Validate inputs
        if (!is_numeric($unique_id)) {
            logActivity("Invalid unique_id provided to handleFailedLogin: " . $unique_id);
            throw new Exception("Invalid user identifier");
        }

        logActivity("Processing failed login for user: " . $unique_id . " from IP: " . $_SERVER['REMOTE_ADDR']);

        // Check if an entry exists for this unique_id
        $stmtCheckAttempts = $conn->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE unique_id = ?");
        if (!$stmtCheckAttempts) {
            logActivity("Database prepare error: " . $conn->error);
            throw new Exception("Database error");
        }

        $stmtCheckAttempts->bind_param("i", $unique_id);
        if (!$stmtCheckAttempts->execute()) {
            logActivity("Database execute error: " . $stmtCheckAttempts->error);
            throw new Exception("Database error");
        }

        $resultCheckAttempts = $stmtCheckAttempts->get_result();
        $current_attempts = 0;
        $is_locked = false;

        if ($resultCheckAttempts->num_rows > 0) {
            $row = $resultCheckAttempts->fetch_assoc();
            $current_attempts = $row['attempts'];
            $locked_until = $row['locked_until'];

            // Check if account is already locked
            if ($locked_until && new DateTime() < new DateTime($locked_until)) {
                $is_locked = true;
            }
        }

        // Calculate new attempt count
        $new_attempts = $current_attempts + 1;
        $attempts_remaining = $max_attempts - $new_attempts;

        logActivity("Login attempt for user: " . $unique_id .
            " - Current attempts: " . $new_attempts .
            " of " . $max_attempts .
            " - Locked: " . ($is_locked ? "Yes" : "No"));

        if ($is_locked) {
            // Account is already locked
            $lock_time = new DateTime($locked_until);
            $current_time = new DateTime();
            $remaining_time = $lock_time->diff($current_time);

            $message = "Account is locked. Please try again in " .
                $remaining_time->format('%i minutes %s seconds');

            logActivity("Login blocked - Account " . $unique_id . " is already locked until " . $locked_until);
            echo json_encode(["success" => false, "message" => $message]);
            return;
        }

        if ($new_attempts >= $max_attempts) {
            // Lock the account
            $locked_until = new DateTime();
            $locked_until->modify("+$lockout_duration minutes");
            $lock_period = $locked_until->format('Y-m-d H:i:s');

            // Update login attempts table
            $stmtLock = $conn->prepare("UPDATE admin_login_attempts SET attempts = ?, locked_until = ?, last_attempt = NOW() WHERE unique_id = ?");
            $stmtLock->bind_param("isi", $new_attempts, $lock_period, $unique_id);

            if (!$stmtLock->execute()) {
                logActivity("Failed to lock account " . $unique_id . ": " . $stmtLock->error);
                throw new Exception("Database error");
            }

            // Record in lock history
            $locked_by = 0; // System ID
            $lock_reason = "Account locked due to too many failed login attempts";
            $lock_method = "Automatic lock";

            $stmtInsertLock = $conn->prepare("
                INSERT INTO admin_lock_history 
                (unique_id, status, locked_by, lock_reason, lock_method, locked_at) 
                VALUES (?, 'locked', ?, ?, ?, NOW())
            ");
            $stmtInsertLock->bind_param("iiss", $unique_id, $locked_by, $lock_reason, $lock_method);

            if (!$stmtInsertLock->execute()) {
                logActivity("Failed to record lock history for " . $unique_id . ": " . $stmtInsertLock->error);
            }

            logActivity("Account " . $unique_id . " locked until " . $lock_period .
                " due to " . $new_attempts . " failed attempts");

            $message = "Too many failed login attempts. Your account is locked for " .
                $lockout_duration . " minutes.";

            echo json_encode(["success" => false, "message" => $message]);
            return;
        }

        // Update attempt count (not locked yet)
        if ($resultCheckAttempts->num_rows > 0) {
            $stmtUpdate = $conn->prepare("UPDATE admin_login_attempts SET attempts = ?, last_attempt = NOW() WHERE unique_id = ?");
            $stmtUpdate->bind_param("ii", $new_attempts, $unique_id);
        } else {
            $stmtUpdate = $conn->prepare("INSERT INTO admin_login_attempts (unique_id, attempts, last_attempt) VALUES (?, ?, NOW())");
            $stmtUpdate->bind_param("ii", $unique_id, $new_attempts);
        }

        if (!$stmtUpdate->execute()) {
            logActivity("Failed to update login attempts for " . $unique_id . ": " . $stmtUpdate->error);
            throw new Exception("Database error");
        }

        logActivity("Failed login recorded for " . $unique_id .
            " - Attempt " . $new_attempts . " of " . $max_attempts);

        $message = "Invalid credentials. Attempts remaining: " . $attempts_remaining;
        echo json_encode(["success" => false, "message" => $message]);

    } catch (Exception $e) {
        logActivity("Error in handleFailedLogin for " . $unique_id . ": " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "An error occurred during login"]);
    } finally {
        // Clean up database connections
        if (isset($stmtCheckAttempts))
            $stmtCheckAttempts->close();
        if (isset($stmtLock))
            $stmtLock->close();
        if (isset($stmtInsertLock))
            $stmtInsertLock->close();
        if (isset($stmtUpdate))
            $stmtUpdate->close();
    }
}

