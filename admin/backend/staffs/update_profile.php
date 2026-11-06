<?php
// admin/backend/staffs/update_profile.php
// Admin self-service profile update. Three actions: basic, password, secret.
//
// Photo upload is handled by a separate endpoint: update_photo.php.
//
// SECURITY:
//   - All verifications run OUTSIDE any transaction — counters persist on failure.
//   - Password lockout: 3 fails → 60 min. Secret-answer: 3 fails → 15 min.
//   - 423 responses carry { locked_until, source } for the frontend modal.

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
    'rate_key'   => 'admin_update_profile',
    'rate_limit' => [10, 300],
    'csrf'       => ['enabled' => true, 'form_name' => 'admin_profile_form'],
    'roles'      => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];

logActivity("[ADMIN_PROFILE_UPDATE] ========== START ==========");
logActivity("[ADMIN_PROFILE_UPDATE] Admin: {$adminId}");

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = $_POST;

    $action = trim($input['action'] ?? '');

    if (!in_array($action, ['basic', 'password', 'secret'], true)) {
        json_error("Invalid or missing action", 400);
    }

    // Load current admin row
    $stmt = $conn->prepare("
        SELECT unique_id, firstname, lastname, email, phone, gender, address,
               password, secret_question, secret_answer
        FROM admin_tbl
        WHERE unique_id = ? AND status = '1'
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed (load admin): " . $conn->error);
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) json_error("Admin not found", 404);

    // Pre-check both locks
    $secretLock = checkSecretLockout($conn, 'admin', (string)$adminId);
    if ($secretLock['locked']) {
        json_error(
            $secretLock['message'], 423, null, 'ACCOUNT_LOCKED',
            ['locked_until' => $secretLock['locked_until'], 'source' => 'secret_answer']
        );
    }

    $pwdLock = checkPasswordLockout($conn, 'admin', (string)$adminId);
    if ($pwdLock['locked']) {
        json_error(
            $pwdLock['message'], 423, null, 'ACCOUNT_LOCKED',
            ['locked_until' => $pwdLock['locked_until'], 'source' => 'password']
        );
    }

    // Dispatch
    switch ($action) {
        case 'basic':    handleBasicUpdate($conn, $adminId, $current, $input);    break;
        case 'password': handlePasswordChange($conn, $adminId, $current, $input); break;
        case 'secret':   handleSecretChange($conn, $adminId, $current, $input);   break;
    }

} catch (Throwable $e) {
    logActivity("[ADMIN_PROFILE_UPDATE] ERROR: " . $e->getMessage(), 'ERROR');
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}

// ============================================================
// HANDLER: basic
// ============================================================
function handleBasicUpdate(mysqli $conn, int $adminId, array $current, array $input): void
{
    $firstname = trim($input['firstname'] ?? '');
    $lastname  = trim($input['lastname'] ?? '');
    $email     = trim($input['email'] ?? '');
    $phone     = trim($input['phone'] ?? '');
    $gender    = trim($input['gender'] ?? '');
    $address   = trim($input['address'] ?? '');
    $password  = $input['current_password'] ?? '';

    if ($firstname === '' || $lastname === '' || $email === '' || $phone === '') {
        json_error("First name, last name, email, and phone are required", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error("Invalid email address", 400);
    }
    if (function_exists('validate_phone') && !validate_phone($phone)) {
        json_error("Invalid phone number format", 400);
    }
    if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
        json_error("Invalid gender value", 400);
    }

    $emailChanged = strcasecmp($email, $current['email']) !== 0;
    $phoneChanged = $phone !== $current['phone'];
    $needsReauth  = $emailChanged || $phoneChanged;

    // Reauth required only if email/phone changes
    if ($needsReauth) {
        if ($password === '') {
            json_error("Enter your password to change email or phone", 400);
        }

        $verify = verifyAdminPasswordWithLockout($conn, $adminId, $password, $current['password']);
        if (!$verify['ok']) {
            $isLocked = !empty($verify['locked']);
            $lockoutData = $isLocked
                ? ['locked_until' => date('Y-m-d H:i:s', time() + 3600), 'source' => 'password']
                : null;
            json_error(
                $verify['message'] ?? 'Incorrect password',
                $isLocked ? 423 : 401,
                null,
                $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
                $lockoutData
            );
        }
    }

    // Transaction
    $conn->begin_transaction();
    try {
        if ($emailChanged) {
            $chk = $conn->prepare("
                SELECT unique_id FROM admin_tbl
                WHERE email = ? AND unique_id != ? AND status = '1' LIMIT 1
            ");
            if (!$chk) throw new Exception("Prepare failed (email check): " . $conn->error);
            $chk->bind_param("si", $email, $adminId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception("Email is already used by another admin", 409);
            }
            $chk->close();
        }
        if ($phoneChanged) {
            $chk = $conn->prepare("
                SELECT unique_id FROM admin_tbl
                WHERE phone = ? AND unique_id != ? AND status = '1' LIMIT 1
            ");
            if (!$chk) throw new Exception("Prepare failed (phone check): " . $conn->error);
            $chk->bind_param("si", $phone, $adminId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception("Phone is already used by another admin", 409);
            }
            $chk->close();
        }

        $upd = $conn->prepare("
            UPDATE admin_tbl
            SET firstname = ?, lastname = ?, email = ?, phone = ?, gender = ?, address = ?,
                updated_at = NOW(), last_updated_by = ?
            WHERE unique_id = ? AND status = '1'
        ");
        if (!$upd) throw new Exception("Prepare failed (update): " . $conn->error);
        $upd->bind_param("ssssssii", $firstname, $lastname, $email, $phone, $gender, $address, $adminId, $adminId);
        if (!$upd->execute()) {
            if ($upd->errno === 1062) {
                $field = stripos($upd->error, 'email') !== false ? 'Email' : 'Phone';
                $upd->close();
                throw new Exception("{$field} was just claimed by another admin", 409);
            }
            throw new Exception("Update failed: " . $upd->error);
        }
        $upd->close();

        // Reset password counter if reauth happened
        if ($needsReauth) {
            $reset = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
            if ($reset) { $reset->bind_param("i", $adminId); $reset->execute(); $reset->close(); }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    logActivity("[ADMIN_PROFILE_UPDATE] basic update succeeded for {$adminId}");
    respondWithProfile($conn, $adminId, "Personal information updated successfully");
}

// ============================================================
// HANDLER: password
// ============================================================
function handlePasswordChange(mysqli $conn, int $adminId, array $current, array $input): void
{
    $currentPw   = $input['current_password'] ?? '';
    $newPw       = $input['new_password'] ?? '';
    $confirmPw   = $input['confirm_password'] ?? '';
    $secretAns   = $input['secret_answer'] ?? '';

    if ($currentPw === '' || $newPw === '' || $confirmPw === '' || $secretAns === '') {
        json_error("Current password, new password, confirm password, and secret answer are all required", 400);
    }
    if ($newPw !== $confirmPw) {
        json_error("New password and confirm password do not match", 400);
    }

    // Verify current password (with lockout)
    $pwCheck = verifyAdminPasswordWithLockout($conn, $adminId, $currentPw, $current['password']);
    if (!$pwCheck['ok']) {
        $isLocked = !empty($pwCheck['locked']);
        $lockoutData = $isLocked
            ? ['locked_until' => date('Y-m-d H:i:s', time() + 3600), 'source' => 'password']
            : null;
        json_error(
            $pwCheck['message'] ?? 'Incorrect password',
            $isLocked ? 423 : 401,
            null,
            $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
            $lockoutData
        );
    }

    // Verify secret answer (with lockout)
    if (empty($current['secret_answer'])) {
        json_error("Please set up your secret question first", 400);
    }
    $saCheck = verifySecretAnswer($conn, 'admin', (string)$adminId, $current['secret_answer'], $secretAns);
    if (!$saCheck['ok']) {
        $isLocked = !empty($saCheck['locked']);
        $lockoutData = null;
        if ($isLocked) {
            $state = getSecretAttempts($conn, 'admin', (string)$adminId);
            $lockoutData = [
                'locked_until' => $state['locked_until'] ?? date('Y-m-d H:i:s', time() + 900),
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

    // Validate new password strength
    $pwdErrors = validateAdminPasswordStrength($newPw);
    if (!empty($pwdErrors)) {
        json_error(implode('. ', $pwdErrors), 400);
    }
    if (password_verify($newPw, $current['password'])) {
        json_error("New password cannot be the same as current password", 400);
    }

    // Apply
    $hashed = password_hash($newPw, PASSWORD_ARGON2ID);
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("
            UPDATE admin_tbl SET password = ?, updated_at = NOW()
            WHERE unique_id = ? AND status = '1'
        ");
        if (!$upd) throw new Exception("Prepare failed (password update): " . $conn->error);
        $upd->bind_param("si", $hashed, $adminId);
        if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
        $upd->close();

        // Reset both counters (both verifications succeeded)
        $reset = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
        if ($reset) { $reset->bind_param("i", $adminId); $reset->execute(); $reset->close(); }
        $reset = $conn->prepare("DELETE FROM admin_secret_attempts WHERE unique_id = ?");
        if ($reset) { $reset->bind_param("i", $adminId); $reset->execute(); $reset->close(); }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    logActivity("[ADMIN_PROFILE_UPDATE] password changed for {$adminId}");
    respondWithProfile($conn, $adminId, "Password updated successfully");
}

// ============================================================
// HANDLER: secret
// ============================================================
function handleSecretChange(mysqli $conn, int $adminId, array $current, array $input): void
{
    $newQuestion = trim($input['secret_question'] ?? '');
    $newAnswer   = trim($input['secret_answer'] ?? '');
    $confirm     = trim($input['confirm_secret_answer'] ?? '');
    $currentPw   = $input['current_password'] ?? '';

    $allowedQuestions = [
        "What was your first pet's name?",
        "What is your mother's maiden name?",
        "What city were you born in?",
        "What is your favorite book?",
        "What was your first car?",
    ];

    if ($newQuestion === '' || !in_array($newQuestion, $allowedQuestions, true)) {
        json_error("Please select a valid secret question", 400);
    }
    if ($newAnswer === '') {
        json_error("Secret answer is required", 400);
    }
    if (strlen($newAnswer) < 8) {
        json_error("Secret answer must be at least 8 characters", 400);
    }
    if (preg_match('/[<>"\'\\\\]/', $newAnswer)) {
        json_error("Secret answer contains invalid characters", 400);
    }
    if ($newAnswer !== $confirm) {
        json_error("Secret answers do not match", 400);
    }
    if ($currentPw === '') {
        json_error("Current password is required to change your secret details", 400);
    }

    // Verify current password (with lockout)
    $pwCheck = verifyAdminPasswordWithLockout($conn, $adminId, $currentPw, $current['password']);
    if (!$pwCheck['ok']) {
        $isLocked = !empty($pwCheck['locked']);
        $lockoutData = $isLocked
            ? ['locked_until' => date('Y-m-d H:i:s', time() + 3600), 'source' => 'password']
            : null;
        json_error(
            $pwCheck['message'] ?? 'Incorrect password',
            $isLocked ? 423 : 401,
            null,
            $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_PASSWORD',
            $lockoutData
        );
    }

    // Apply
    $hashed = password_hash(strtolower($newAnswer), PASSWORD_ARGON2ID);
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("
            UPDATE admin_tbl
            SET secret_question = ?, secret_answer = ?, updated_at = NOW()
            WHERE unique_id = ? AND status = '1'
        ");
        if (!$upd) throw new Exception("Prepare failed (secret update): " . $conn->error);
        $upd->bind_param("ssi", $newQuestion, $hashed, $adminId);
        if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
        $upd->close();

        // Reset password counter (verification succeeded)
        $reset = $conn->prepare("DELETE FROM admin_login_attempts WHERE unique_id = ?");
        if ($reset) { $reset->bind_param("i", $adminId); $reset->execute(); $reset->close(); }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    logActivity("[ADMIN_PROFILE_UPDATE] secret details changed for {$adminId}");
    respondWithProfile($conn, $adminId, "Secret details updated successfully");
}

// ============================================================
// SHARED HELPERS
// ============================================================

function respondWithProfile(mysqli $conn, int $adminId, string $message): void
{
    $stmt = $conn->prepare("
        SELECT unique_id, firstname, lastname, email, phone, gender, address,
               secret_question, photo, role, status, created_at, updated_at
        FROM admin_tbl WHERE unique_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_success($message, ['profile' => $profile]);
}

function validateAdminPasswordStrength(string $password): array
{
    $errors = [];
    if (strlen($password) < 8)                                 $errors[] = "Password must be at least 8 characters";
    if (!preg_match('/[A-Z]/', $password))                     $errors[] = "Must contain an uppercase letter";
    if (!preg_match('/[a-z]/', $password))                     $errors[] = "Must contain a lowercase letter";
    if (!preg_match('/\d/', $password))                        $errors[] = "Must contain a number";
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>_\-]/', $password)) $errors[] = "Must contain a special character";
    return $errors;
}