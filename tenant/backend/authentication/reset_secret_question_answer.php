<?php
// tenant/backend/authentication/reset_secret_question_answer.php
//
// Security:
//   - CSRF + rate limit + role + active tenancy via requireAuth
//   - Password reauthentication required (via verifyTenantPasswordWithLockout)
//     → 3 failed attempts locks account for 60 min (shared with login)
//   - Password verification happens OUTSIDE the transaction so the lockout
//     counter persists even on failure
//   - 24-hour cooldown between successful resets
//   - Argon2id hash for the new secret answer
//   - 423 responses include data.tenant_code for client-side forced logout

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'                 => 'POST',
    'rate_key'               => 'tenant_reset_secret',
    'rate_limit'             => [3, 3600],
    'csrf'                   => ['enabled' => true, 'form_name' => 'reset_secret_form'],
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => true,
]);

$tenant_code = $_SESSION['tenant_code'] ?? null;
if (!$tenant_code) json_error("Session expired", 401);

logActivity("[TENANT_RESET_SECRET] ========== START ==========");
logActivity("[TENANT_RESET_SECRET] Tenant: {$tenant_code}");

// ---- Question whitelist ----
$question_map = [
    'mother_maiden_name' => "What is your mother's maiden name?",
    'first_pet'          => "What was the name of your first pet?",
    'first_school'       => "What was the name of your first school?",
    'birth_city'         => "In which city were you born?",
    'favorite_teacher'   => "What is the name of your favorite teacher?",
    'childhood_friend'   => "What is the name of your childhood best friend?",
    'first_car'          => "What was your first car?",
    'favorite_food'      => "What is your favorite food?",
    'dream_job'          => "What was your dream job as a child?",
    'favorite_place'     => "What is your favorite place to visit?",
];

// ---- Parse input ----
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);
if (!is_array($input)) json_error("Invalid input data", 400);

$secret_question_key = trim($input['secret_question'] ?? '');
$secret_answer       = trim($input['secret_answer'] ?? '');
$confirm_answer      = trim($input['confirm_answer'] ?? '');
$password            = $input['password'] ?? '';

// ---- Field validation ----
$validation_errors = [];

if ($password === '') {
    $validation_errors['password'] = "Password is required to reset secret question";
}
if ($secret_question_key === '') {
    $validation_errors['secret_question'] = "Please select a secret question";
} elseif (!array_key_exists($secret_question_key, $question_map)) {
    $validation_errors['secret_question'] = "Invalid secret question selected";
}
if ($secret_answer === '') {
    $validation_errors['secret_answer'] = "Secret answer is required";
} elseif (strlen($secret_answer) < 8) {
    $validation_errors['secret_answer'] = "Secret answer must be at least 8 characters long";
} elseif (preg_match('/[<>"\'\\\\]/', $secret_answer)) {
    $validation_errors['secret_answer'] = "Secret answer contains invalid characters";
}
if ($confirm_answer === '') {
    $validation_errors['confirm_answer'] = "Please confirm your secret answer";
} elseif ($secret_answer !== $confirm_answer) {
    $validation_errors['confirm_answer'] = "Secret answers do not match";
}

if (!empty($validation_errors)) {
    json_validation_error($validation_errors, "Validation failed");
}

try {
    // ---- Load current tenant row (password hash + cooldown field) ----
    $stmt = $conn->prepare("
        SELECT password, secret_question, has_secret_set, last_secret_reset
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
        json_error("Tenant not found", 404);
    }

    // ---- Password verification with lockout — OUTSIDE any transaction ----
    $pwCheck = verifyTenantPasswordWithLockout(
        $conn,
        $tenant_code,
        $password,
        $tenant['password'],
        session_id()
    );

    if (!$pwCheck['ok']) {
        $isLocked = !empty($pwCheck['locked']);
        logActivity(
            "[TENANT_RESET_SECRET] Password failed for {$tenant_code}: " . ($pwCheck['message'] ?? ''),
            'WARN'
        );

        json_error(
            $pwCheck['message'] ?? 'Incorrect password',
            $isLocked ? 423 : 401,
            null,
            $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
            $isLocked ? ['tenant_code' => $tenant_code] : null
        );
    }
    logActivity("[TENANT_RESET_SECRET] Password verified");

    // ---- Must already have a secret question to reset ----
    if ((int) $tenant['has_secret_set'] !== 1 || empty($tenant['secret_question'])) {
        json_error("No secret question set to reset. Please set one first.", 400);
    }

    // ---- 24-hour cooldown ----
    if (!empty($tenant['last_secret_reset'])) {
        $last = strtotime($tenant['last_secret_reset']);
        if ($last > time() - 86400) {
            $nextAllowed = date('Y-m-d H:i', $last + 86400);
            logActivity("[TENANT_RESET_SECRET] Cooldown active until {$nextAllowed} for {$tenant_code}", 'WARN');
            json_error(
                "You can only reset your secret question once every 24 hours. Next allowed: {$nextAllowed}",
                429,
                null,
                'RESET_COOLDOWN_ACTIVE',
                ['next_allowed' => $nextAllowed]
            );
        }
    }

    // ---- Transaction for the update ----
    $conn->begin_transaction();
    logActivity("[TENANT_RESET_SECRET] Transaction started");

    try {
        $secret_question_text = $question_map[$secret_question_key];
        $normalized = strtolower(trim($secret_answer));
        $encrypted  = password_hash($normalized, PASSWORD_ARGON2ID);
        $oldQuestion = $tenant['secret_question'];

        $upd = $conn->prepare("
            UPDATE tenants
            SET secret_question = ?, secret_answer = ?, has_secret_set = 1,
                last_secret_reset = NOW(), last_updated_at = NOW()
            WHERE tenant_code = ? AND status = 1
        ");
        if (!$upd) throw new Exception("Prepare failed (update): " . $conn->error);
        $upd->bind_param("sss", $secret_question_text, $encrypted, $tenant_code);
        if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
        if ($upd->affected_rows === 0) throw new Exception("No changes made. Tenant not found.");
        $upd->close();

        logActivity("[TENANT_RESET_SECRET] Question changed: "
            . substr($oldQuestion, 0, 60) . " → {$secret_question_text}");

        // ---- Clear login attempts (successful reauth) ----
        $clr = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
        if ($clr) { $clr->bind_param("s", $tenant_code); $clr->execute(); $clr->close(); }

        // ---- Notification (best-effort) ----
        try {
            if (function_exists('createSecurityNotification')) {
                createSecurityNotification($conn, $tenant_code, 'secret_question_reset');
            }
        } catch (Throwable $notifErr) {
            logActivity("[TENANT_RESET_SECRET] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
        }

        $conn->commit();
        logActivity("[TENANT_RESET_SECRET] Transaction committed");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("[TENANT_RESET_SECRET] ROLLBACK: " . $e->getMessage(), 'ERROR');
        throw $e;
    }

    logActivity("[TENANT_RESET_SECRET] ========== SUCCESS ==========");
    logActivity("[TENANT_RESET_SECRET] tenant={$tenant_code}, new_question_key={$secret_question_key}");

    json_success([
        'has_secret_set'  => true,
        'secret_question' => $secret_question_text,
        'reset_date'      => date('Y-m-d H:i:s'),
    ], "Secret question and answer reset successfully");

} catch (Throwable $e) {
    logActivity("[TENANT_RESET_SECRET] ERROR: " . $e->getMessage(), 'ERROR');
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}