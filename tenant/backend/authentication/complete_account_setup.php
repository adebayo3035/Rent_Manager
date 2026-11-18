
<?php
// tenant/backend/auth/complete_account_setup.php
// Tenant account setup — Step 3: apply password + predefined secret question.

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

logActivity("[COMPLETE_SETUP] [ID:{$requestId}] ========== REQUEST STARTED ==========");
logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Request time: " . date('Y-m-d H:i:s'));
logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));

try {

    // ==================================================
    // STEP 1: VALIDATE REQUEST METHOD
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 1: Validating request method.");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Rejected request: POST required.", 'WARN');
        json_error("Method not allowed", 405);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Request method validated.");

    // ==================================================
    // STEP 2: READ REQUEST DATA
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 2: Reading request payload.");

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Invalid JSON request payload. JSON error: " .
            json_last_error_msg(),
            'WARN'
        );

        json_error("Invalid input data", 400);
    }

    logActivity(
        "[COMPLETE_SETUP] [ID:{$requestId}] Request payload decoded. Input keys: " .
        implode(', ', array_keys($input))
    );

    $setupToken = isset($input['setup_token']) && is_string($input['setup_token'])
        ? trim($input['setup_token'])
        : '';

    $password = isset($input['password']) && is_string($input['password'])
        ? $input['password']
        : '';

    $confirmPass = isset($input['password_confirm']) && is_string($input['password_confirm'])
        ? $input['password_confirm']
        : '';

    $secretQuestionKey = isset($input['secret_question']) && is_string($input['secret_question'])
        ? trim($input['secret_question'])
        : '';

    $secretAnswer = isset($input['secret_answer']) && is_string($input['secret_answer'])
        ? trim($input['secret_answer'])
        : '';

    $confirmAnswer = isset($input['confirm_answer']) && is_string($input['confirm_answer'])
        ? trim($input['confirm_answer'])
        : '';

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Setup token supplied: " . (!empty($setupToken) ? 'YES' : 'NO'));
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Password supplied: " . (!empty($password) ? 'YES' : 'NO'));
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Password confirmation supplied: " . (!empty($confirmPass) ? 'YES' : 'NO'));
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret question key supplied: " . (!empty($secretQuestionKey) ? 'YES' : 'NO'));
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer supplied: " . (!empty($secretAnswer) ? 'YES' : 'NO'));
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer confirmation supplied: " . (!empty($confirmAnswer) ? 'YES' : 'NO'));

    // ==================================================
    // STEP 3: VERIFY SETUP TOKEN
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 3: Verifying setup token.");

    if ($setupToken === '') {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Setup token is missing.", 'WARN');
        json_error("Your setup session has expired. Please restart the setup flow.", 401);
    }

    $tokenData = verifySetupToken($setupToken);

    if (!is_array($tokenData)) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Setup token verification failed.", 'WARN');
        json_error("Your setup session has expired. Please restart the setup flow.", 401);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Setup token signature and expiry verified.");

    // Validate required token claims before using them.
    if (
        empty($tokenData['code']) ||
        empty($tokenData['type']) ||
        empty($tokenData['email']) ||
        !is_string($tokenData['code']) ||
        !is_string($tokenData['type']) ||
        !is_string($tokenData['email'])
    ) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Setup token is missing required claims.", 'WARN');
        json_error("Invalid setup session. Please restart the setup flow.", 401);
    }

    if ($tokenData['type'] !== 'tenant') {
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Invalid token account type: {$tokenData['type']}",
            'WARN'
        );

        json_error("Invalid setup session.", 401);
    }

    $tenantCode = $tokenData['code'];
    $email = strtolower(trim($tokenData['email']));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Token contains an invalid email claim.", 'WARN');
        json_error("Invalid setup session.", 401);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Token belongs to a tenant.");
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant code from token: {$tenantCode}");
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant email from token: " . setupTokenMaskEmail($email));

    // ==================================================
    // STEP 4: VALIDATE PASSWORD
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 4: Validating password.");

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

    logActivity(
        "[COMPLETE_SETUP] [ID:{$requestId}] Password validation completed. " .
        "Errors: " . (
            isset($validationErrors['password']) || isset($validationErrors['password_confirm'])
                ? 'YES'
                : 'NO'
        )
    );

    // ==================================================
    // STEP 5: VALIDATE PREDEFINED SECRET QUESTION
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 5: Validating secret question.");

    // These keys and question texts must match the frontend options.
    $questionMap = [
        'mother_maiden_name' => "What is your mother's maiden name?",
        'first_pet' => "What was the name of your first pet?",
        'first_school' => "What was the name of your first school?",
        'birth_city' => "In which city were you born?",
        'favorite_teacher' => "What is the name of your favorite teacher?",
        'childhood_friend' => "What is the name of your childhood best friend?",
        'first_car' => "What was your first car?",
        'favorite_food' => "What is your favorite food?",
        'dream_job' => "What was your dream job as a child?",
        'favorite_place' => "What is your favorite place to visit?"
    ];

    if ($secretQuestionKey === '') {
        $validationErrors['secret_question'] = "Please select a secret question";

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] No secret question was selected.", 'WARN');

    } elseif (!array_key_exists($secretQuestionKey, $questionMap)) {
        $validationErrors['secret_question'] = "Invalid secret question selected";

        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Invalid secret question key submitted.",
            'WARN'
        );

    } else {
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Secret question key validated: {$secretQuestionKey}"
        );
    }

    // ==================================================
    // STEP 6: VALIDATE SECRET ANSWER
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 6: Validating secret answer.");

    if ($secretAnswer === '') {
        $validationErrors['secret_answer'] = "Secret answer is required";

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer is missing.", 'WARN');

    } else {

        if (strlen($secretAnswer) < 8) {
            $validationErrors['secret_answer'] = "Secret answer must be at least 8 characters long";

            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer is shorter than 8 characters.", 'WARN');
        }

        if (strlen($secretAnswer) > 255) {
            $validationErrors['secret_answer'] = "Secret answer cannot exceed 255 characters";

            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer exceeds 255 characters.", 'WARN');
        }

        if (trim($secretAnswer) === '') {
            $validationErrors['secret_answer'] = "Secret answer cannot be empty or contain only spaces";

            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer contains only whitespace.", 'WARN');
        }

        if (preg_match('/[<>"\'\\\\]/', $secretAnswer)) {
            $validationErrors['secret_answer'] = "Secret answer contains invalid characters";

            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer contains prohibited characters.", 'WARN');
        }
    }

    // ==================================================
    // STEP 7: VALIDATE SECRET ANSWER CONFIRMATION
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 7: Validating secret answer confirmation.");

    if ($confirmAnswer === '') {
        $validationErrors['confirm_answer'] = "Please confirm your secret answer";

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer confirmation is missing.", 'WARN');

    } elseif ($secretAnswer !== $confirmAnswer) {
        $validationErrors['confirm_answer'] = "Secret answers do not match";

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer confirmation does not match.", 'WARN');

    } else {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer confirmation matched.");
    }

    // Return all validation errors together.
    if (!empty($validationErrors)) {

        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Input validation failed. " .
            "Number of validation errors: " . count($validationErrors),
            'WARN'
        );

        foreach ($validationErrors as $field => $error) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Validation error on {$field}: {$error}",
                'WARN'
            );
        }

        json_validation_error($validationErrors, "Validation failed");
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] All input validations passed.");

    // Retrieve the approved question text.
    $secretQuestionText = $questionMap[$secretQuestionKey];

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Approved secret question retrieved.");

    // ==================================================
    // STEP 8: NORMALIZE AND HASH CREDENTIALS
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 8: Normalizing and hashing credentials.");

    $normalizedAnswer = strtolower(trim($secretAnswer));

    $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
    $secretHash = password_hash($normalizedAnswer, PASSWORD_ARGON2ID);

    if (!$passwordHash  || !$secretHash ) {
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Credential hashing failed.", 'ERROR');
        throw new RuntimeException("Unable to secure account credentials.", 500);
    }

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Password hash generated successfully.");
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Secret answer hash generated successfully.");

    // ==================================================
    // STEP 9: START DATABASE TRANSACTION
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 9: Starting database transaction.");

    if (!$conn->begin_transaction()) {
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Database transaction could not be started: " . $conn->error,
            'ERROR'
        );

        throw new RuntimeException("Unable to start account setup transaction.", 500);
    }

    $transactionStarted = true;

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Database transaction started.");

    try {

        // ==================================================
        // STEP 10: LOCK AND RETRIEVE TENANT RECORD
        // ==================================================

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 10: Locking tenant record.");

        $lockQuery = "
            SELECT
                tenant_code,
                firstname,
                lastname,
                email,
                password_changed,
                has_secret_set
            FROM tenants
            WHERE tenant_code = ?
              AND email = ?
              AND status = 1
            LIMIT 1
            FOR UPDATE
        ";

        $lockStmt = $conn->prepare($lockQuery);

        if (!$lockStmt) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Tenant lock query prepare failed: " . $conn->error,
                'ERROR'
            );

            throw new RuntimeException("Unable to retrieve tenant account.", 500);
        }

        $lockStmt->bind_param("ss", $tenantCode, $email);

        if (!$lockStmt->execute()) {
            $dbError = $lockStmt->error;
            $lockStmt->close();

            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Tenant lock query execution failed: {$dbError}",
                'ERROR'
            );

            throw new RuntimeException("Unable to retrieve tenant account.", 500);
        }

        $lockResult = $lockStmt->get_result();

        if (!$lockResult) {
            $dbError = $lockStmt->error;
            $lockStmt->close();

            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Tenant query result retrieval failed: {$dbError}",
                'ERROR'
            );

            throw new RuntimeException("Unable to retrieve tenant account.", 500);
        }

        $tenant = $lockResult->fetch_assoc();
        $lockStmt->close();

        if (!$tenant) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] No active tenant matched the token claims.",
                'WARN'
            );

            throw new RuntimeException("Account not found or inactive.", 404);
        }

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Active tenant record found.");
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant code matched token claim: {$tenantCode}");
        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant email matched token claim: " . setupTokenMaskEmail($email));
        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Current setup flags - password_changed: " .
            $tenant['password_changed'] . ", has_secret_set: " . $tenant['has_secret_set']
        );

        // ==================================================
        // STEP 11: CHECK WHETHER ACCOUNT IS ALREADY SET UP
        // ==================================================

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 11: Checking existing account setup status.");

        if (
            (int)$tenant['password_changed'] === 1 &&
            (int)$tenant['has_secret_set'] === 1
        ) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Account has already completed setup.",
                'WARN'
            );

            throw new RuntimeException(
                "This account has already been set up. Please log in.",
                409
            );
        }

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant is eligible for initial account setup.");

        // ==================================================
        // STEP 12: UPDATE TENANT ACCOUNT
        // ==================================================

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 12: Updating tenant security details.");

        $updateQuery = "
            UPDATE tenants
            SET
                password = ?,
                password_changed = 1,
                secret_question = ?,
                secret_answer = ?,
                has_secret_set = 1,
                last_updated_at = NOW()
            WHERE tenant_code = ?
              AND email = ?
        ";

        $updateStmt = $conn->prepare($updateQuery);

        if (!$updateStmt) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Tenant update prepare failed: " . $conn->error,
                'ERROR'
            );

            throw new RuntimeException("Unable to update account security details.", 500);
        }

        $updateStmt->bind_param(
            "sssss",
            $passwordHash,
            $secretQuestionText,
            $secretHash,
            $tenantCode,
            $email
        );

        if (!$updateStmt->execute()) {
            $dbError = $updateStmt->error;
            $updateStmt->close();

            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Tenant update execution failed: {$dbError}",
                'ERROR'
            );

            throw new RuntimeException("Unable to update account security details.", 500);
        }

        $affectedRows = $updateStmt->affected_rows;
        $updateStmt->close();

        if ($affectedRows !== 1) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Unexpected number of affected tenant rows: {$affectedRows}",
                'ERROR'
            );

            throw new RuntimeException("Account security details could not be saved.", 500);
        }

        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Tenant security details updated successfully. " .
            "Affected rows: {$affectedRows}"
        );

        // ==================================================
        // STEP 13: COMMIT TRANSACTION
        // ==================================================

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 13: Committing database transaction.");

        if (!$conn->commit()) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Database transaction commit failed: " . $conn->error,
                'ERROR'
            );

            throw new RuntimeException("Unable to complete account setup.", 500);
        }

        $transactionStarted = false;

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Database transaction committed successfully.");

    } catch (Throwable $transactionError) {

        if ($transactionStarted) {

            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Transaction failed. Initiating rollback.",
                'ERROR'
            );

            if (!$conn->rollback()) {
                logActivity(
                    "[COMPLETE_SETUP] [ID:{$requestId}] Database rollback failed: " . $conn->error,
                    'ERROR'
                );
            } else {
                logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Database transaction rolled back.");
            }

            $transactionStarted = false;
        }

        throw $transactionError;
    }

    // ==================================================
    // STEP 14: SEND ACCOUNT READY EMAIL
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Step 14: Sending account-ready email.");

    try {

        $html = renderAccountReadyEmail([
            'name' => trim(($tenant['firstname'] ?? '') . ' ' . ($tenant['lastname'] ?? '')),
            'role' => 'Tenant',
            'login_url' => 'http://localhost/rent_manager/tenant/pages/login.php',
            'support_email' => 'support@rentflowpro.com',
        ]);

        sendEmailWithGmailSMTP(
            $email,
            $html,
            'Your RentFlow Pro account is ready'
        );

        logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Account-ready email sent successfully.");

    } catch (Throwable $mailError) {

        logActivity(
            "[COMPLETE_SETUP] [ID:{$requestId}] Account setup succeeded, but confirmation email failed: " .
            $mailError->getMessage(),
            'WARN'
        );
    }

    // ==================================================
    // STEP 15: FINAL SUCCESS
    // ==================================================

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant account setup completed.");
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant code: {$tenantCode}");
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Tenant email: " . setupTokenMaskEmail($email));
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] ========== REQUEST COMPLETED SUCCESSFULLY ==========");

    json_success([
        'redirect_url' => '/rent_manager/tenant/pages/index.php',
    ], "Your account is ready. Please log in with your new password.");

} catch (Throwable $e) {

    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] ========== REQUEST FAILED ==========", 'ERROR');
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Error message: " . $e->getMessage(), 'ERROR');
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Error code: " . $e->getCode(), 'ERROR');
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Error file: " . $e->getFile(), 'ERROR');
    logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Error line: " . $e->getLine(), 'ERROR');

    if ($transactionStarted) {
        if (!$conn->rollback()) {
            logActivity(
                "[COMPLETE_SETUP] [ID:{$requestId}] Final rollback failed: " . $conn->error,
                'ERROR'
            );
        } else {
            logActivity("[COMPLETE_SETUP] [ID:{$requestId}] Final rollback completed.");
        }
    }

    $errorCode = (int)$e->getCode();

    if ($errorCode === 404) {
        json_error("Account not found or inactive.", 404);
    }

    if ($errorCode === 409) {
        json_error("This account has already been set up. Please log in.", 409);
    }

    json_error("We could not complete your account setup. Please try again or contact support.", 500);
}
