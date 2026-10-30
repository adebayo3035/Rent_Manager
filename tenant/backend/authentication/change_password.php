<?php
// change_password.php - Change tenant password with secret answer verification.
//
// Security:
//   - CSRF + rate limit + role + active tenancy via requireAuth
//   - Current password verified with lockout (3 fails → 60 min, shared with login)
//   - Secret answer verified with lockout (3 fails → 15 min, shared with wallet)
//   - Both attempt counters use wallet_security.php helpers — no local duplication
//   - Password update wrapped in transaction
//   - Successful change clears both counters and unlocks lock_history

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$requestId = uniqid('tenant_pass_change_', true);
logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] ========== START ==========");

// ============================================================
// Helpers (only the ones NOT provided by wallet_security.php)
// ============================================================

function logAndRespond($message, $response, $httpCode = 200, $exit = true)
{
    global $requestId;
    logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] " . $message);
    http_response_code($httpCode);
    echo json_encode($response);
    if ($exit) exit();
}

function validatePassword($password)
{
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

    $commonPasswords = ['password', '12345678', 'admin123', 'welcome123', 'tenant123'];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }

    return empty($errors) ? true : $errors;
}

/**
 * Update the password and clean up attempt/lock state.
 * Assumes the caller has already verified BOTH the current password
 * and the secret answer, and that any prior lockout writes were done
 * outside this transaction.
 */
function updatePasswordAndClearAttempts(mysqli $conn, string $tenant_code, string $hashedPassword): array
{
    global $requestId;

    try {
        $conn->begin_transaction();

        // Update password
        $stmt = $conn->prepare("
            UPDATE tenants SET password = ?, last_updated_at = NOW()
            WHERE tenant_code = ? AND status = 1
        ");
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

        // Mark any open locks as unlocked
        $stmt = $conn->prepare("
            UPDATE tenant_lock_history
            SET status = 'unlocked',
                unlocked_by = 0,
                unlock_method = 'Password change',
                unlock_reason = 'Password change successful',
                unlocked_at = NOW()
            WHERE tenant_code = ? AND status = 'locked' AND unlocked_at IS NULL
        ");
        if ($stmt) { $stmt->bind_param("s", $tenant_code); $stmt->execute(); $stmt->close(); }

        $conn->commit();

        logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Password updated + attempts cleared for {$tenant_code}");

        return [
            'success' => true,
            'message' => 'Password has been changed successfully. Please log in with your new password.',
            'data' => [
                'tenant_code'    => $tenant_code,
                'require_logout' => true,
                'redirect_url'   => '../pages/index.php',
            ],
        ];

    } catch (Throwable $e) {
        if ($conn instanceof mysqli) $conn->rollback();
        logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Update error: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'message' => 'Failed to update password. Please try again.'];
    }
}

// ============================================================
// Main
// ============================================================

try {
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

    $tenant_code = $_SESSION['tenant_code'];
    logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Tenant: {$tenant_code}");

    // ---- Parse input ----
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        logAndRespond("Empty request body", ['success' => false, 'message' => 'No input data received'], 400);
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logAndRespond("Invalid JSON", ['success' => false, 'message' => 'Invalid JSON data'], 400);
    }

    $currentPassword = isset($data['current_password']) ? trim($data['current_password']) : '';
    $newPassword     = isset($data['new_password'])     ? trim($data['new_password'])     : '';
    $secretAnswer    = isset($data['secret_answer'])    ? trim($data['secret_answer'])    : '';

    if ($currentPassword === '' || $newPassword === '' || $secretAnswer === '') {
        logAndRespond("Missing fields", [
            'success' => false,
            'message' => 'Current password, new password, and secret answer are required',
        ], 400);
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
        logAndRespond("User not found", ['success' => false, 'message' => 'No active account found'], 404);
    }

    $current_password_hash = $tenant['password'];

    // ---- Verify current password (with lockout) — OUTSIDE any transaction ----
    // verifyTenantPasswordWithLockout writes to tenant_login_attempts.
    // That write MUST persist even if the rest of the request fails,
    // otherwise the counter resets on every attempt and lockout never fires.
    $pwCheck = verifyTenantPasswordWithLockout(
        $conn,
        $tenant_code,
        $currentPassword,
        $current_password_hash
    );
    if (!$pwCheck['ok']) {
        logActivity(
            "[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Current password rejected: " . ($pwCheck['message'] ?? ''),
            'WARN'
        );
        logAndRespond("Invalid current password", [
            'success' => false,
            'message' => $pwCheck['message'] ?? 'Current password is incorrect',
            'locked'  => $pwCheck['locked'],
        ], $pwCheck['locked'] ? 423 : 401);
    }

    // ---- Secret question must exist ----
    if ((int) $tenant['has_secret_set'] !== 1 || empty($tenant['secret_question'])) {
        logAndRespond("Secret not set", [
            'success' => false,
            'message' => 'Security question not set. Please contact support.',
        ], 400);
    }

    // ---- Verify secret answer (with lockout) — OUTSIDE any transaction ----
    // Same reasoning: verifySecretAnswer writes to tenant_secret_attempts.
    // Must persist so the counter accumulates.
    $saCheck = verifySecretAnswer(
        $conn,
        'tenant',
        $tenant_code,
        $tenant['secret_answer'],
        $secretAnswer
    );
    if (!$saCheck['ok']) {
        logActivity(
            "[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Secret answer rejected: " . ($saCheck['message'] ?? ''),
            'WARN'
        );
        logAndRespond("Invalid secret answer", [
            'success' => false,
            'message' => $saCheck['message'] ?? 'Incorrect secret answer',
            'locked'  => $saCheck['locked'],
        ], $saCheck['locked'] ? 423 : 401);
    }

    // ---- Validate new password ----
    $passwordValidation = validatePassword($newPassword);
    if ($passwordValidation !== true) {
        logAndRespond("Weak password", [
            'success' => false,
            'message' => implode('. ', $passwordValidation),
        ], 400);
    }

    // ---- Reject same-as-current ----
    if (password_verify($newPassword, $current_password_hash)) {
        logAndRespond("Same password", [
            'success' => false,
            'message' => 'New password cannot be the same as current password',
        ], 400);
    }

    // ---- Apply change (transaction wraps ONLY the state-changing writes) ----
    $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
    $response = updatePasswordAndClearAttempts($conn, $tenant_code, $hashedPassword);

    // ---- Security notification (best-effort, non-blocking) ----
    if ($response['success']) {
        try {
            createSecurityNotification($conn, $tenant_code, 'password_changed');
        } catch (Throwable $notifErr) {
            logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
        }
    }

    logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] ========== " . ($response['success'] ? 'SUCCESS' : 'FAILED') . " ==========");
    echo json_encode($response);

} catch (Throwable $e) {
    logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] EXCEPTION: " . $e->getMessage(), 'ERROR');
    logActivity("[TENANT_PASSWORD_CHANGE] [ID:{$requestId}] Trace: " . $e->getTraceAsString(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again later.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}