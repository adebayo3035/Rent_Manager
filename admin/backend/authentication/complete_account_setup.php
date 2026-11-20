<?php
// admin/backend/auth/complete_account_setup.php
// Admin account setup — Step 3: apply password + predefined secret question.
//
// Rewritten to surface EVERY error with a precise step tag, so any failure
// in the log tells you exactly which line and which SQL statement failed.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/setup_token.php';
require_once __DIR__ . '/../utilities/email_templates.php';
require_once __DIR__ . '/../../../mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

rateLimiter();

$requestId = uniqid('complete_setup_', true);
$transactionStarted = false;
$currentStep = 'INIT';

/**
 * Central helper — every failure path must pass through here so the log
 * always ends with one unambiguous "[COMPLETE_SETUP] FATAL" entry.
 */
function failSetup(string $requestId, string $step, string $userMessage, Throwable $e, int $httpCode = 500): void
{
    logActivity(
        "[COMPLETE_SETUP] [ID:{$requestId}] FATAL | Step={$step} | "
        . "Class=" . get_class($e) . " | "
        . "Message=" . $e->getMessage() . " | "
        . "Code=" . $e->getCode() . " | "
        . "File=" . $e->getFile() . ":" . $e->getLine(),
        'ERROR'
    );

    // Include the previous exception if there's one (mysqli wraps errors)
    $prev = $e->getPrevious();
    while ($prev) {
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] FATAL | Step={$step} | PREVIOUS: "
            . get_class($prev) . " | " . $prev->getMessage()
            . " | " . $prev->getFile() . ":" . $prev->getLine(),
            'ERROR'
        );
        $prev = $prev->getPrevious();
    }

    json_error($userMessage, $httpCode);
}

logActivity("[COMPLETE_SETUP] [ID:{$requestId}] ========== REQUEST STARTED ==========");
logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Method=" . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN')
    . " | IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));

try {
    // ============================================================
    // STEP 1 — METHOD
    // ============================================================
    $currentStep = 'STEP_1_METHOD';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException("Method not allowed", 405);
    }

    // ============================================================
    // STEP 2 — READ PAYLOAD
    // ============================================================
    $currentStep = 'STEP_2_PAYLOAD';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Invalid JSON: " . json_last_error_msg(), 'WARN');
        throw new RuntimeException("Invalid input data", 400);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Payload keys: " . implode(',', array_keys($input)));

    $setupToken      = isset($input['setup_token'])      && is_string($input['setup_token'])      ? trim($input['setup_token'])      : '';
    $password        = isset($input['password'])         && is_string($input['password'])         ? $input['password']               : '';
    $confirmPass     = isset($input['password_confirm']) && is_string($input['password_confirm']) ? $input['password_confirm']       : '';
    $secretQuestionKey = isset($input['secret_question']) && is_string($input['secret_question']) ? trim($input['secret_question']) : '';
    $secretAnswer    = isset($input['secret_answer'])    && is_string($input['secret_answer'])    ? trim($input['secret_answer'])    : '';
    $confirmAnswer   = isset($input['confirm_answer'])   && is_string($input['confirm_answer'])   ? trim($input['confirm_answer'])   : '';

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Fields present — "
        . "token=" . (int)!empty($setupToken)
        . " pwd=" . (int)!empty($password)
        . " pwd2=" . (int)!empty($confirmPass)
        . " qkey=" . (int)!empty($secretQuestionKey)
        . " ans=" . (int)!empty($secretAnswer)
        . " ans2=" . (int)!empty($confirmAnswer));

    // ============================================================
    // STEP 3 — VERIFY TOKEN
    // ============================================================
    $currentStep = 'STEP_3_TOKEN';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if ($setupToken === '') {
        throw new RuntimeException("Your setup session has expired. Please restart the setup flow.", 401);
    }

    $tokenData = verifySetupToken($setupToken);

    if (!is_array($tokenData)) {
        throw new RuntimeException("Your setup session has expired. Please restart the setup flow.", 401);
    }

    if (empty($tokenData['code']) || empty($tokenData['type']) || empty($tokenData['email'])
        || !is_string($tokenData['code']) || !is_string($tokenData['type']) || !is_string($tokenData['email'])) {
        throw new RuntimeException("Invalid setup session. Please restart the setup flow.", 401);
    }

    if ($tokenData['type'] !== 'admin') {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Token type mismatch: {$tokenData['type']}", 'WARN');
        throw new RuntimeException("Invalid setup session.", 401);
    }

    $adminCode = $tokenData['code'];
    $email = strtolower(trim($tokenData['email']));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Invalid setup session.", 401);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Token OK | admin_code={$adminCode} | email="
        . setupTokenMaskEmail($email));

    // ============================================================
    // STEP 4 — PASSWORD VALIDATION
    // ============================================================
    $currentStep = 'STEP_4_PASSWORD';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    $validationErrors = [];

    if ($password === '') {
        $validationErrors['password'] = "Password is required";
    } else {
        if (strlen($password) < 8) {
            $validationErrors['password'] = "Password must be at least 8 characters";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $validationErrors['password'] = "Password must contain at least one letter and one number";
        }
    }

    if ($confirmPass === '') {
        $validationErrors['password_confirm'] = "Please confirm your password";
    } elseif ($password !== $confirmPass) {
        $validationErrors['password_confirm'] = "Passwords do not match";
    }

    // ============================================================
    // STEP 5 — SECRET QUESTION VALIDATION
    // ============================================================
    $currentStep = 'STEP_5_QUESTION';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    $questionMap = [
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

    if ($secretQuestionKey === '') {
        $validationErrors['secret_question'] = "Please select a secret question";
    } elseif (!array_key_exists($secretQuestionKey, $questionMap)) {
        $validationErrors['secret_question'] = "Invalid secret question selected";
    }

    // ============================================================
    // STEP 6 — SECRET ANSWER VALIDATION
    // ============================================================
    $currentStep = 'STEP_6_ANSWER';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if ($secretAnswer === '') {
        $validationErrors['secret_answer'] = "Secret answer is required";
    } else {
        if (strlen($secretAnswer) < 8) {
            $validationErrors['secret_answer'] = "Secret answer must be at least 8 characters long";
        }
        if (strlen($secretAnswer) > 255) {
            $validationErrors['secret_answer'] = "Secret answer cannot exceed 255 characters";
        }
        if (trim($secretAnswer) === '') {
            $validationErrors['secret_answer'] = "Secret answer cannot be empty";
        }
        if (preg_match('/[<>"\'\\\\]/', $secretAnswer)) {
            $validationErrors['secret_answer'] = "Secret answer contains invalid characters";
        }
    }

    // ============================================================
    // STEP 7 — CONFIRM ANSWER
    // ============================================================
    $currentStep = 'STEP_7_CONFIRM';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if ($confirmAnswer === '') {
        $validationErrors['confirm_answer'] = "Please confirm your secret answer";
    } elseif ($secretAnswer !== $confirmAnswer) {
        $validationErrors['confirm_answer'] = "Secret answers do not match";
    }

    if (!empty($validationErrors)) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Validation failed: "
            . json_encode(array_keys($validationErrors)), 'WARN');
        json_validation_error($validationErrors, "Validation failed");
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] All validations passed.");

    $secretQuestionText = $questionMap[$secretQuestionKey];

    // ============================================================
    // STEP 8 — HASH CREDENTIALS
    // ============================================================
    $currentStep = 'STEP_8_HASH';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    $normalizedAnswer = strtolower(trim($secretAnswer));

    $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
    $secretHash   = password_hash($normalizedAnswer, PASSWORD_ARGON2ID);

    if (!$passwordHash || !$secretHash) {
        throw new RuntimeException("Unable to hash credentials.", 500);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Hashes generated (pwd_len=" . strlen($passwordHash)
        . ", ans_len=" . strlen($secretHash) . ")");

    // ============================================================
    // STEP 9 — TRANSACTION
    // ============================================================
    $currentStep = 'STEP_9_TRANSACTION';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if (!$conn->begin_transaction()) {
        throw new RuntimeException("Unable to start transaction: " . $conn->error, 500);
    }
    $transactionStarted = true;

    // ============================================================
    // STEP 10 — LOCK ADMIN ROW
    // ============================================================
    $currentStep = 'STEP_10_LOCK';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} | binding admin_code={$adminCode}, email=" . setupTokenMaskEmail($email));

    $lockQuery = "
        SELECT unique_id, firstname, lastname, email, password_changed, has_secret_set
        FROM admin_tbl
        WHERE unique_id = ?
          AND email = ?
          AND status = '1'
        LIMIT 1
        FOR UPDATE
    ";

    try {
        $lockStmt = $conn->prepare($lockQuery);
        if (!$lockStmt) {
            throw new RuntimeException("Prepare failed: " . $conn->error);
        }
        $lockStmt->bind_param("ss", $adminCode, $email);
        $lockStmt->execute();
        $lockResult = $lockStmt->get_result();
        $admin = $lockResult->fetch_assoc();
        $lockStmt->close();
    } catch (Throwable $sqlEx) {
        failSetup($requestId, $currentStep, "Unable to retrieve admin account.", $sqlEx, 500);
        exit;
    }

    if (!$admin) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] No matching admin found for code={$adminCode}, email=" . setupTokenMaskEmail($email), 'WARN');

        if ($transactionStarted) {
            $conn->rollback();
            $transactionStarted = false;
        }

        json_error("Account not found or inactive.", 404);
        exit;
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Admin locked | "
        . "password_changed={$admin['password_changed']} | has_secret_set={$admin['has_secret_set']}");

    // ============================================================
    // STEP 11 — ALREADY SET UP CHECK
    // ============================================================
    $currentStep = 'STEP_11_ELIGIBILITY';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if ((int)$admin['password_changed'] === 1 && (int)$admin['has_secret_set'] === 1) {
        if ($transactionStarted) {
            $conn->rollback();
            $transactionStarted = false;
        }
        json_error("This account has already been set up. Please log in.", 409);
        exit;
    }

    // ============================================================
    // STEP 12 — UPDATE ADMIN (THE STEP THAT'S FAILING)
    // ============================================================
    $currentStep = 'STEP_12_UPDATE';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} | executing UPDATE on admin_tbl");

    $updateQuery = "
        UPDATE admin_tbl
        SET password = ?,
            password_changed = 1,
            secret_question = ?,
            secret_answer = ?,
            has_secret_set = 1,
            updated_at = NOW()
        WHERE unique_id = ?
          AND email = ?
    ";

    // Log exactly what columns will be written (values redacted)
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] UPDATE columns: "
        . "password(len=" . strlen($passwordHash) . "), "
        . "password_changed=1, "
        . "secret_question(len=" . strlen($secretQuestionText) . "), "
        . "secret_answer(len=" . strlen($secretHash) . "), "
        . "has_secret_set=1, "
        . "last_updated_at=NOW() "
        . "WHERE unique_id={$adminCode} AND email=" . setupTokenMaskEmail($email));

    try {
        $updateStmt = $conn->prepare($updateQuery);
        if (!$updateStmt) {
            throw new RuntimeException("Prepare failed: " . $conn->error);
        }

        $updateStmt->bind_param("sssss", $passwordHash, $secretQuestionText, $secretHash, $adminCode, $email);

        if (!$updateStmt->execute()) {
            throw new RuntimeException("Execute returned false: " . $updateStmt->error);
        }

        $affectedRows = $updateStmt->affected_rows;
        $updateStmt->close();

    } catch (Throwable $sqlEx) {
        // THIS is where the real error comes from — capture everything
        $mysqliErrno = ($sqlEx instanceof mysqli_sql_exception) ? $sqlEx->getCode() : null;
        $mysqliSqlState = ($sqlEx instanceof mysqli_sql_exception) ? $sqlEx->getSqlState() : null;

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} UPDATE FAILED | "
            . "class=" . get_class($sqlEx)
            . " | errno={$mysqliErrno}"
            . " | sqlstate={$mysqliSqlState}"
            . " | message=" . $sqlEx->getMessage(),
            'ERROR');

        failSetup($requestId, $currentStep, "Unable to update account security details.", $sqlEx, 500);
        exit;
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} UPDATE executed. affected_rows={$affectedRows}");

    if ($affectedRows !== 1) {
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} UNEXPECTED affected_rows={$affectedRows}. "
            . "Expected 1. This usually means: (a) the WHERE clause matched 0 rows, or "
            . "(b) the row already had identical values (password hash should differ, so (b) is unlikely).",
            'ERROR'
        );

        if ($transactionStarted) {
            $conn->rollback();
            $transactionStarted = false;
        }

        json_error("Account security details could not be saved. Please retry.", 500);
        exit;
    }

    // ============================================================
    // STEP 13 — COMMIT
    // ============================================================
    $currentStep = 'STEP_13_COMMIT';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    if (!$conn->commit()) {
        throw new RuntimeException("Commit failed: " . $conn->error, 500);
    }
    $transactionStarted = false;
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} COMMIT OK");

    // ============================================================
    // STEP 14 — CONFIRMATION EMAIL (best-effort)
    // ============================================================
    $currentStep = 'STEP_14_EMAIL';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep}");

    try {
        $html = renderAccountReadyEmail([
            'name'          => trim(($admin['firstname'] ?? '') . ' ' . ($admin['lastname'] ?? '')),
            'role'          => 'Admin',
            'login_url'     => 'http://localhost/rent_manager/admin/pages/index.php',
            'support_email' => 'support@rentflowpro.com',
        ]);

        // Correct argument order: (to, body, subject, attachments, toName)
        sendEmailWithGmailSMTP(
            $email,
            $html,
            'Your RentFlow Pro account is ready',
            [],
            trim(($admin['firstname'] ?? '') . ' ' . ($admin['lastname'] ?? ''))
        );

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} Email sent.");

    } catch (Throwable $mailErr) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} Email failed (non-fatal): "
            . $mailErr->getMessage(), 'WARN');
    }

    // ============================================================
    // STEP 15 — SUCCESS
    // ============================================================
    $currentStep = 'STEP_15_SUCCESS';
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] {$currentStep} SUCCESS | admin_code={$adminCode}");

    json_success(
        ['redirect_url' => '/rent_manager/admin/pages/index.php'],
        "Your account is ready. Please log in with your new password."
    );

} catch (Throwable $e) {

    // ============================================================
    // GLOBAL CATCH — every unhandled error lands here
    // ============================================================
    if ($transactionStarted) {
        try {
            $conn->rollback();
            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Rollback performed (step={$currentStep}).");
        } catch (Throwable $rbEx) {
            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Rollback itself failed: " . $rbEx->getMessage(), 'ERROR');
        }
    }

    // Route through the fail helper so the log entry is consistent
    failSetup(
        $requestId,
        $currentStep,
        "We could not complete your account setup. Please try again or contact support.",
        $e,
        (int)$e->getCode() >= 400 && (int)$e->getCode() < 600 ? (int)$e->getCode() : 500
    );
}