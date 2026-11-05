<?php
// tenant/backend/authentication/change_password.php
//
// Security:
//   - CSRF + rate limit + role + active tenancy via requireAuth
//   - Current password verified with lockout (3 fails → 60 min, shared with login)
//   - Secret answer verified with lockout (3 fails → 15 min, shared with wallet)
//   - Both verification calls happen OUTSIDE the transaction so counters persist
//   - Password update + attempt clearing wrapped in transaction
//   - Argon2id hash
//   - 423 responses include data.tenant_code, locked_until, and source
//     so the client can render an accurate modal + forced logout

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

// ---- Auth ----
$auth = requireAuth([
    'method'                 => 'POST',
    'rate_key'               => 'tenant_change_password',
    'rate_limit'             => [3, 600],
    'csrf'                   => ['enabled' => true, 'form_name' => 'tenant_change_password'],
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => true,
]);

$tenant_code = $_SESSION['tenant_code'] ?? null;
if (!$tenant_code) json_error("Session expired", 401);

logActivity("[TENANT_PASSWORD_CHANGE] ========== START ==========");
logActivity("[TENANT_PASSWORD_CHANGE] Tenant: {$tenant_code}");

// ---- Helper ----
function validateTenantPassword(string $password)
{
    $minLength = 8;
    $errors = [];

    if (strlen($password) < $minLength)              $errors[] = "Password must be at least $minLength characters long";
    if (!preg_match('/[A-Z]/', $password))           $errors[] = "Password must contain at least one uppercase letter";
    if (!preg_match('/[a-z]/', $password))           $errors[] = "Password must contain at least one lowercase letter";
    if (!preg_match('/\d/', $password))              $errors[] = "Password must contain at least one number";
    if (preg_match('/\s/', $password))               $errors[] = "Password cannot contain spaces";

    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123', 'tenant123'];
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
function updatePasswordAndClearAttempts(mysqli $conn, string $tenant_code, string $hashedPassword): array
{
    try {
        $conn->begin_transaction();

        // Update password
        $stmt = $conn->prepare("UPDATE tenants SET password = ?, last_updated_at = NOW() WHERE tenant_code = ? AND status = 1");
        if (!$stmt) throw new Exception("Prepare failed (password update): " . $conn->error);
        $stmt->bind_param("ss", $hashedPassword, $tenant_code);
        if (!$stmt->execute()) throw new Exception("Execute failed (password update): " . $stmt->error);
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) throw new Exception("Tenant not found or password unchanged");

        // Clear secret-answer attempts
        $stmt = $conn->prepare("DELETE FROM tenant_secret_attempts WHERE tenant_code = ?");
        if ($stmt) { $stmt->bind_param("s", $tenant_code); $stmt->execute(); $stmt->close(); }

        // Clear login attempts
        $stmt = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
        if ($stmt) { $stmt->bind_param("s", $tenant_code); $stmt->execute(); $stmt->close(); }

        // Unlock any open locks
        $stmt = $conn->prepare("
            UPDATE tenant_lock_history
            SET status = 'unlocked', unlocked_by = 0,
                unlock_method = 'Password change',
                unlock_reason = 'Password change successful',
                unlocked_at = NOW()
            WHERE tenant_code = ? AND status = 'locked' AND unlocked_at IS NULL
        ");
        if ($stmt) { $stmt->bind_param("s", $tenant_code); $stmt->execute(); $stmt->close(); }

        $conn->commit();

        return [
            'success' => true,
            'message' => 'Password has been changed successfully. Please log in with your new password.',
            'data'    => [
                'tenant_code'    => $tenant_code,
                'require_logout' => true,
                'redirect_url'   => '../pages/index.php',
            ],
        ];

    } catch (Throwable $e) {
        if ($conn instanceof mysqli) $conn->rollback();
        logActivity("[TENANT_PASSWORD_CHANGE] Update error: " . $e->getMessage(), 'ERROR');
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
    $newPassword     = isset($data['new_password'])     ? trim($data['new_password'])     : '';
    $confirmPassword = isset($data['confirm_password']) ? trim($data['confirm_password']) : '';
    $secretAnswer    = isset($data['secret_answer'])    ? trim($data['secret_answer'])    : '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '' || $secretAnswer === '') {
        json_error("Current password, new password, confirm password, and secret answer are required", 400);
    }
    if ($newPassword !== $confirmPassword) {
        json_error("New password and confirm password do not match", 400);
    }

    // ---- Load tenant ----
    $stmt = $conn->prepare("
        SELECT tenant_code, email, password, secret_question, secret_answer, has_secret_set, status
        FROM tenants
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed (load tenant): " . $conn->error);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        json_error("No active account found", 404);
    }

    $current_password_hash = $tenant['password'];

    // ---- Verify current password with lockout — OUTSIDE any transaction ----
    $pwCheck = verifyTenantPasswordWithLockout(
        $conn, $tenant_code, $currentPassword, $current_password_hash, session_id()
    );

    if (!$pwCheck['ok']) {
        $isLocked = !empty($pwCheck['locked']);
        logActivity(
            "[TENANT_PASSWORD_CHANGE] Current password rejected: " . ($pwCheck['message'] ?? ''),
            'WARN'
        );

        // Password lockouts are always 60 minutes; compute the exact expiry
        // so the frontend countdown is accurate.
        $lockoutData = $isLocked
            ? [
                'tenant_code'  => $tenant_code,
                'locked_until' => date('Y-m-d H:i:s', time() + 3600),
                'source'       => 'password',
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
    logActivity("[TENANT_PASSWORD_CHANGE] Current password verified");

    // ---- Secret question must exist ----
    if ((int) $tenant['has_secret_set'] !== 1 || empty($tenant['secret_question'])) {
        json_error("Security question not set. Please set it first.", 400);
    }

    // ---- Verify secret answer with lockout — OUTSIDE any transaction ----
    $saCheck = verifySecretAnswer(
        $conn, 'tenant', $tenant_code, $tenant['secret_answer'], $secretAnswer
    );

    if (!$saCheck['ok']) {
        $isLocked = !empty($saCheck['locked']);
        logActivity(
            "[TENANT_PASSWORD_CHANGE] Secret answer rejected: " . ($saCheck['message'] ?? ''),
            'WARN'
        );

        // Pull the real lockout expiry so the countdown matches the
        // 15-minute secret-answer lock instead of the 60-minute password default.
        $lockoutData = null;
        if ($isLocked) {
            $attemptState = getSecretAttempts($conn, 'tenant', $tenant_code);
            $lockoutData = [
                'tenant_code'  => $tenant_code,
                'locked_until' => $attemptState['locked_until'] ?? date('Y-m-d H:i:s', time() + 900),
                'source'       => 'secret_answer',
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
    logActivity("[TENANT_PASSWORD_CHANGE] Secret answer verified");

    // ---- Validate new password strength ----
    $passwordValidation = validateTenantPassword($newPassword);
    if ($passwordValidation !== true) {
        json_error(implode('. ', $passwordValidation), 400);
    }

    // ---- Reject same-as-current ----
    if (password_verify($newPassword, $current_password_hash)) {
        json_error("New password cannot be the same as current password", 400);
    }

    // ---- Apply password change ----
    $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
    $response = updatePasswordAndClearAttempts($conn, $tenant_code, $hashedPassword);

    // ---- Security notification (best-effort) ----
    if ($response['success']) {
        try {
            if (function_exists('createSecurityNotification')) {
                createSecurityNotification($conn, $tenant_code, 'password_changed');
            }
        } catch (Throwable $notifErr) {
            logActivity("[TENANT_PASSWORD_CHANGE] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
        }
    }

    logActivity("[TENANT_PASSWORD_CHANGE] ========== " . ($response['success'] ? 'SUCCESS' : 'FAILED') . " ==========");
    echo json_encode($response);

} catch (Throwable $e) {
    logActivity("[TENANT_PASSWORD_CHANGE] EXCEPTION: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_PASSWORD_CHANGE] Trace: " . $e->getTraceAsString(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again later.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}