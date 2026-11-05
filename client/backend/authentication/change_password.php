<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
rateLimiter();

// ---- Auth ----
$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'client_change_password',
    'rate_limit' => [30, 600],
    'csrf' => ['enabled' => true, 'form_name' => 'client_change_password'],
    'roles' => ['Client'],
]);

$client_code = $_SESSION['client_code'] ?? null;
if (!$client_code)
    json_error("Session expired", 401);

logActivity("[CLIENT_PASSWORD_CHANGE] ========== START ==========");
logActivity("[CLIENT_PASSWORD_CHANGE] Client: {$client_code}");

// ---- Helper ----
function validateClientPassword(string $password)
{
    $minLength = 8;
    $errors = [];

    if (strlen($password) < $minLength)
        $errors[] = "Password must be at least $minLength characters long";
    if (!preg_match('/[A-Z]/', $password))
        $errors[] = "Password must contain at least one uppercase letter";
    if (!preg_match('/[a-z]/', $password))
        $errors[] = "Password must contain at least one lowercase letter";
    if (!preg_match('/\d/', $password))
        $errors[] = "Password must contain at least one number";
    if (preg_match('/\s/', $password))
        $errors[] = "Password cannot contain spaces";

    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123', 'client123'];
    if (in_array(strtolower($password), $commonPasswords, true)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }

    return empty($errors) ? true : $errors;
}

/**
 * Apply the password change and clean up all attempt/lock state.
 * Assumes both current password and secret answer have already been
 * verified (with lockout) and their attempt counters already reset.
 */
function applyPasswordChangeAndCleanup(mysqli $conn, string $client_code, string $hashedPassword): array
{
    try {
        $conn->begin_transaction();

        // Update password
        $stmt = $conn->prepare("UPDATE clients SET password = ?, date_updated = NOW() WHERE client_code = ? AND status = 1");
        if (!$stmt)
            throw new Exception("Prepare failed (password update): " . $conn->error);
        $stmt->bind_param("ss", $hashedPassword, $client_code);
        if (!$stmt->execute())
            throw new Exception("Execute failed (password update): " . $stmt->error);
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0)
            throw new Exception("Client not found or password unchanged");

        // Clear secret-answer attempts
        $stmt = $conn->prepare("DELETE FROM client_secret_attempts WHERE client_code = ?");
        if ($stmt) {
            $stmt->bind_param("s", $client_code);
            $stmt->execute();
            $stmt->close();
        }

        // Clear login attempts
        $stmt = $conn->prepare("DELETE FROM client_login_attempts WHERE client_code = ?");
        if ($stmt) {
            $stmt->bind_param("s", $client_code);
            $stmt->execute();
            $stmt->close();
        }

        // Unlock any open locks
        $stmt = $conn->prepare("
            UPDATE client_lock_history
            SET status = 'unlocked', unlocked_by = 0,
                unlock_method = 'Password change',
                unlock_reason = 'Password change successful',
                unlocked_at = NOW()
            WHERE client_code = ? AND status = 'locked' AND unlocked_at IS NULL
        ");
        if ($stmt) {
            $stmt->bind_param("s", $client_code);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        return [
            'success' => true,
            'message' => 'Password has been changed successfully. Please log in with your new password.',
            'data' => [
                'client_code' => $client_code,
                'require_logout' => true,
                'redirect_url' => '../pages/login.php',
            ],
        ];

    } catch (Throwable $e) {
        if ($conn instanceof mysqli)
            $conn->rollback();
        logActivity("[CLIENT_PASSWORD_CHANGE] Update error: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'message' => 'Failed to update password. Please try again.'];
    }
}

// === Main ===

try {
    // ---- Parse input ----
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        json_error("No input data received", 400);
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error("Invalid JSON data", 400);
    }

    $currentPassword = isset($data['current_password']) ? trim($data['current_password']) : '';
    $newPassword = isset($data['new_password']) ? trim($data['new_password']) : '';
    $confirmPassword = isset($data['confirm_password']) ? trim($data['confirm_password']) : '';
    $secretAnswer = isset($data['secret_answer']) ? trim($data['secret_answer']) : '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '' || $secretAnswer === '') {
        json_error("Current password, new password, confirm password, and secret answer are required", 400);
    }
    if ($newPassword !== $confirmPassword) {
        json_error("New password and confirm password do not match", 400);
    }

    // ---- Load client ----
    $stmt = $conn->prepare("
        SELECT client_code, email, password, secret_question, secret_answer, has_secret_set, status
        FROM clients
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ");
    if (!$stmt)
        throw new Exception("Prepare failed (load client): " . $conn->error);
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) {
        json_error("No active account found", 404);
    }

    $current_password_hash = $client['password'];

    // ---- Verify current password with lockout — OUTSIDE any transaction ----
    $pwCheck = verifyClientPasswordWithLockout(
        $conn,
        $client_code,
        $currentPassword,
        $current_password_hash,
        session_id()
    );

    if (!$pwCheck['ok']) {
        $isLocked = !empty($pwCheck['locked']);
        logActivity(
            "[CLIENT_PASSWORD_CHANGE] Current password rejected: " . ($pwCheck['message'] ?? ''),
            'WARN'
        );

        // Password lockouts are always 60 minutes; compute the exact expiry so the
        // frontend can render an accurate countdown.
        $lockoutData = $isLocked
            ? [
                'client_code' => $client_code,
                'locked_until' => date('Y-m-d H:i:s', time() + 3600),
                'source' => 'password',
            ]
            : null;

        json_error(
            $pwCheck['message'] ?? 'Current password is incorrect',
            $isLocked ? 423 : 401,
            null,
            $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
            $lockoutData
        );
    }
    logActivity("[CLIENT_PASSWORD_CHANGE] Current password verified");

    // ---- Secret question must exist ----
    if ((int) $client['has_secret_set'] !== 1 || empty($client['secret_question'])) {
        json_error("Security question not set. Please set it first.", 400);
    }

    // ---- Verify secret answer with lockout — OUTSIDE any transaction ----
    $saCheck = verifySecretAnswer(
        $conn,
        'client',
        $client_code,
        $client['secret_answer'],
        $secretAnswer
    );

    if (!$saCheck['ok']) {
        $isLocked = !empty($saCheck['locked']);
        logActivity(
            "[CLIENT_PASSWORD_CHANGE] Secret answer rejected: " . ($saCheck['message'] ?? ''),
            'WARN'
        );

        // Pull the real lockout expiry from client_secret_attempts so the
        // countdown matches the 15-min lock instead of the 60-min default.
        $lockoutData = null;
        if ($isLocked) {
            $attemptState = getSecretAttempts($conn, 'client', $client_code);
            $lockoutData = [
                'client_code' => $client_code,
                'locked_until' => $attemptState['locked_until']
                    ?? date('Y-m-d H:i:s', time() + 900),
                'source' => 'secret_answer',
            ];
        }

        json_error(
            $saCheck['message'] ?? 'Incorrect secret answer',
            $isLocked ? 423 : 401,
            null,
            $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_SECRET_ANSWER',
            $lockoutData
        );
    }
    logActivity("[CLIENT_PASSWORD_CHANGE] Secret answer verified");

    // ---- Validate new password strength ----
    $passwordValidation = validateClientPassword($newPassword);
    if ($passwordValidation !== true) {
        json_error(implode('. ', $passwordValidation), 400);
    }

    // ---- Reject same-as-current ----
    if (password_verify($newPassword, $current_password_hash)) {
        json_error("New password cannot be the same as current password", 400);
    }

    // ---- Apply password change ----
    $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
    $response = applyPasswordChangeAndCleanup($conn, $client_code, $hashedPassword);

    // ---- Security notification (best-effort) ----
    if ($response['success']) {
        try {
            if (function_exists('createSecurityNotification')) {
                createSecurityNotification($conn, $client_code, 'password_changed');
            }
        } catch (Throwable $notifErr) {
            logActivity("[CLIENT_PASSWORD_CHANGE] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
        }
    }

    logActivity("[CLIENT_PASSWORD_CHANGE] ========== " . ($response['success'] ? 'SUCCESS' : 'FAILED') . " ==========");

    echo json_encode($response);

} catch (Throwable $e) {
    logActivity("[CLIENT_PASSWORD_CHANGE] EXCEPTION: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_PASSWORD_CHANGE] Trace: " . $e->getTraceAsString(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again later.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}