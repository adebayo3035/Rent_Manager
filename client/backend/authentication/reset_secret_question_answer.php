<?php

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
    'method'     => 'POST',
    'rate_key'   => 'client_reset_secret',
    'rate_limit' => [3, 3600],
    'csrf'       => ['enabled' => true, 'form_name' => 'client_reset_secret'],
    'roles'      => ['Client'],
]);

$client_code = $_SESSION['client_code'] ?? null;
if (!$client_code) json_error("Session expired", 401);

logActivity("[CLIENT_RESET_SECRET] ========== START ==========");
logActivity("[CLIENT_RESET_SECRET] Client: {$client_code}");

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
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
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
} elseif (trim($secret_answer) === '') {
    $validation_errors['secret_answer'] = "Secret answer cannot be empty or contain only spaces";
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
    // ---- Load current client row (password hash + cooldown field) ----
    $stmt = $conn->prepare("
        SELECT password, secret_question, has_secret_set, last_secret_reset
        FROM clients
        WHERE client_code = ? AND status = 1
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed (load client): " . $conn->error);
    $stmt->bind_param("s", $client_code);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) {
        json_error("Client not found", 404);
    }

    // ---- Password verification (with lockout) — OUTSIDE the transaction ----
    $pwCheck = verifyClientPasswordWithLockout(
        $conn,
        $client_code,
        $password,
        $client['password'],
        session_id()
    );

    if (!$pwCheck['ok']) {
        $isLocked = !empty($pwCheck['locked']);
        logActivity(
            "[CLIENT_RESET_SECRET] Password failed for {$client_code}: " . ($pwCheck['message'] ?? ''),
            'WARN'
        );

        json_error(
            $pwCheck['message'] ?? 'Incorrect password',
            $isLocked ? 423 : 401,
            null,
            $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
            $isLocked ? ['client_code' => $client_code] : null
        );
    }
    logActivity("[CLIENT_RESET_SECRET] Password verified");

    // ---- Must already have a secret question to reset ----
    if ((int) $client['has_secret_set'] !== 1 || empty($client['secret_question'])) {
        json_error("No secret question set to reset. Please set one first.", 400);
    }

    // ---- 24-hour cooldown ----
    if (!empty($client['last_secret_reset'])) {
        $last = strtotime($client['last_secret_reset']);
        if ($last > time() - 86400) {
            $nextAllowed = date('Y-m-d H:i', $last + 86400);
            logActivity("[CLIENT_RESET_SECRET] Cooldown active until {$nextAllowed} for {$client_code}", 'WARN');
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
    logActivity("[CLIENT_RESET_SECRET] Transaction started");

    try {
        $secret_question_text = $question_map[$secret_question_key];
        $normalized = strtolower(trim($secret_answer));
        $encrypted  = password_hash($normalized, PASSWORD_ARGON2ID);
        $oldQuestion = $client['secret_question'];

        $upd = $conn->prepare("
            UPDATE clients
            SET secret_question = ?, secret_answer = ?, has_secret_set = 1,
                last_secret_reset = NOW(), date_updated = NOW()
            WHERE client_code = ? AND status = 1
        ");
        if (!$upd) throw new Exception("Prepare failed (update): " . $conn->error);
        $upd->bind_param("sss", $secret_question_text, $encrypted, $client_code);
        if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
        if ($upd->affected_rows === 0) throw new Exception("No changes made. Client not found.");
        $upd->close();

        logActivity("[CLIENT_RESET_SECRET] Question changed: "
            . substr($oldQuestion, 0, 60) . " → {$secret_question_text}");

        // ---- Clear secret-answer attempts ----
        $clr = $conn->prepare("DELETE FROM client_secret_attempts WHERE client_code = ?");
        if ($clr) { $clr->bind_param("s", $client_code); $clr->execute(); $clr->close(); }

        // ---- Clear login attempts ----
        $clr = $conn->prepare("DELETE FROM client_login_attempts WHERE client_code = ?");
        if ($clr) { $clr->bind_param("s", $client_code); $clr->execute(); $clr->close(); }

        // ---- Notification (best-effort) ----
        try {
            if (function_exists('createSecurityNotification')) {
                createSecurityNotification($conn, $client_code, 'secret_question_reset');
            }
        } catch (Throwable $notifErr) {
            logActivity("[CLIENT_RESET_SECRET] Notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
        }

        $conn->commit();
        logActivity("[CLIENT_RESET_SECRET] Transaction committed");

    } catch (Throwable $e) {
        $conn->rollback();
        logActivity("[CLIENT_RESET_SECRET] ROLLBACK: " . $e->getMessage(), 'ERROR');
        throw $e;
    }

    logActivity("[CLIENT_RESET_SECRET] ========== SUCCESS ==========");
    logActivity("[CLIENT_RESET_SECRET] client={$client_code}, new_question_key={$secret_question_key}");

    json_success([
        'has_secret_set'  => true,
        'secret_question' => $secret_question_text,
        'reset_date'      => date('Y-m-d H:i:s'),
    ], "Secret question and answer reset successfully");

} catch (Throwable $e) {
    logActivity("[CLIENT_RESET_SECRET] ERROR: " . $e->getMessage(), 'ERROR');
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}