<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';   // verifyTenantPasswordWithLockout

if (!isset($_SESSION)) session_start();
rateLimiter();

$auth = requireAuth([
    'method'                 => 'POST',
    'rate_key'               => 'tenant_reset_secret',
    'rate_limit'             => [3, 3600],
    'csrf'                   => ['enabled' => true, 'form_name' => 'reset_secret_form'],
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => true,
]);

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

try {
    logActivity("RESET_SECRET: Script started");

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) json_error("Tenant code not found", 400);

    logActivity("RESET_SECRET: Processing request for tenant: " . $tenant_code);

    // ---- Parse input ----
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);
    if (!$input) json_error("Invalid input data", 400);

    $secret_question_key = isset($input['secret_question']) ? trim($input['secret_question']) : '';
    $secret_answer       = isset($input['secret_answer'])   ? trim($input['secret_answer'])   : '';
    $confirm_answer      = isset($input['confirm_answer'])  ? trim($input['confirm_answer'])  : '';
    $password            = isset($input['password'])        ? $input['password']              : '';

    // ---- Validation (unrelated to DB, no transaction needed) ----
    $validation_errors = [];

    if (empty($password)) {
        $validation_errors['password'] = "Password is required to reset secret question";
    }
    if (empty($secret_question_key)) {
        $validation_errors['secret_question'] = "Please select a secret question";
    } elseif (!array_key_exists($secret_question_key, $question_map)) {
        $validation_errors['secret_question'] = "Invalid secret question selected";
    }
    if (empty($secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer is required";
    } elseif (strlen($secret_answer) < 8) {
        $validation_errors['secret_answer'] = "Secret answer must be at least 8 characters long";
    } elseif (preg_match('/[<>"\'\\\\]/', $secret_answer)) {
        $validation_errors['secret_answer'] = "Secret answer contains invalid characters";
    }
    if (empty($confirm_answer)) {
        $validation_errors['confirm_answer'] = "Please confirm your secret answer";
    } elseif ($secret_answer !== $confirm_answer) {
        $validation_errors['confirm_answer'] = "Secret answers do not match";
    }

    if (!empty($validation_errors)) {
        json_validation_error($validation_errors, "Validation failed");
    }

    logActivity("RESET_SECRET: Verifying password (outside transaction)");

    $pwStmt = $conn->prepare("SELECT password FROM tenants WHERE tenant_code = ? LIMIT 1");
    if (!$pwStmt) throw new Exception("Database prepare failed: " . $conn->error);
    $pwStmt->bind_param("s", $tenant_code);
    $pwStmt->execute();
    $pwRow = $pwStmt->get_result()->fetch_assoc();
    $pwStmt->close();

    if (!$pwRow) {
        json_error("Tenant not found", 404);
    }

    $pwCheck = verifyTenantPasswordWithLockout($conn, $tenant_code, $password, $pwRow['password']);
    if (!$pwCheck['ok']) {
        // The attempt counter has ALREADY been written and committed
        // by verifyTenantPasswordWithLockout — it's not in any transaction.
        logActivity("RESET_SECRET: Password failed - " . ($pwCheck['message'] ?? ''), 'WARN');
        json_error($pwCheck['message'] ?? 'Incorrect password', $pwCheck['locked'] ? 423 : 401);
    }

    logActivity("RESET_SECRET: Password verification successful");

    // ============================================================
    // NOW start the transaction for the actual update
    // ============================================================
    $conn->begin_transaction();
    logActivity("RESET_SECRET: Transaction started");

    try {
        // ---- Lock tenant row and fetch reset-related state ----
        $stmt = $conn->prepare("
            SELECT secret_question, has_secret_set, last_secret_reset
            FROM tenants
            WHERE tenant_code = ?
            FOR UPDATE
        ");
        if (!$stmt) throw new Exception("Database prepare failed: " . $conn->error);
        $stmt->bind_param("s", $tenant_code);
        $stmt->execute();
        $tenant = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$tenant) throw new Exception("Tenant not found", 404);

        // ---- Must already have a secret question ----
        if (!$tenant['has_secret_set'] || empty($tenant['secret_question'])) {
            throw new Exception("No secret question set to reset. Please set one first.", 400);
        }

        // ---- 24-hour cooldown ----
        if (!empty($tenant['last_secret_reset'])) {
            $last = strtotime($tenant['last_secret_reset']);
            if ($last > time() - 86400) {
                $nextAllowed = date('Y-m-d H:i', $last + 86400);
                throw new Exception(
                    "You can only reset your secret question once every 24 hours. Next allowed: {$nextAllowed}",
                    429
                );
            }
        }

        // ---- Apply update ----
        $secret_question_text = $question_map[$secret_question_key];
        $normalized = strtolower(trim($secret_answer));
        $encrypted  = password_hash($normalized, PASSWORD_ARGON2ID);

        $upd = $conn->prepare("
            UPDATE tenants
            SET secret_question = ?, secret_answer = ?, has_secret_set = 1,
                last_secret_reset = NOW(), last_updated_at = NOW()
            WHERE tenant_code = ?
        ");
        if (!$upd) throw new Exception("Database prepare failed: " . $conn->error);
        $upd->bind_param("sss", $secret_question_text, $encrypted, $tenant_code);
        if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
        $upd->close();

        logActivity("RESET_SECRET: Question changed: " . substr($tenant['secret_question'], 0, 60)
            . " → {$secret_question_text}");

        // ---- Clear login attempts (successful reauth) ----
        $reset = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
        if ($reset) { $reset->bind_param("s", $tenant_code); $reset->execute(); $reset->close(); }

        // ---- Notification ----
        createSecurityNotification($conn, $tenant_code, 'secret_question_reset');

        $conn->commit();
        logActivity("RESET_SECRET: Transaction committed");
        logActivity("RESET_SECRET: ========== SUCCESS ==========");

        json_success([
            'has_secret_set'  => true,
            'secret_question' => $secret_question_text,
            'reset_date'      => date('Y-m-d H:i:s'),
        ], "Secret question and answer reset successfully");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("RESET_SECRET: ROLLBACK - " . $e->getMessage(), 'ERROR');
        throw $e;
    }

} catch (Throwable $e) {
    logActivity("RESET_SECRET_ERROR: " . $e->getMessage());
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}