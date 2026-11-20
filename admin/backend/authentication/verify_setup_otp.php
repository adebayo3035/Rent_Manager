
<?php
// admin/backend/auth/verify_setup_otp.php
// Admin account setup — Step 2: verify OTP + issue setup token

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/otp_verification.php';
require_once __DIR__ . '/../utilities/setup_token.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

logActivity("[SETUP] ===== VERIFY SETUP OTP PROCESS STARTED =====");
logActivity("[SETUP] Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
logActivity("[SETUP] Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
logActivity("[SETUP] Session Status: " . session_status());

rateLimiter();

$email = '';

try {

    // --------------------------------------------------
    // STEP 1: VALIDATE REQUEST METHOD
    // --------------------------------------------------

    logActivity("[SETUP] Step 1: Validating HTTP request method.");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        logActivity("[SETUP] Request rejected: Invalid HTTP method.", 'WARN');

        json_error("Method not allowed", 405);
    }

    logActivity("[SETUP] Request method validation successful.");

    // --------------------------------------------------
    // STEP 2: READ AND VALIDATE REQUEST PAYLOAD
    // --------------------------------------------------

    logActivity("[SETUP] Step 2: Reading incoming request payload.");

    $rawInput = file_get_contents('php://input');

    logActivity("[SETUP] Request payload received. Payload length: " .
        ($rawInput !== false ? strlen($rawInput) : 0) . " bytes.");

    $input = json_decode($rawInput ?: '', true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {

        logActivity("[SETUP] Invalid JSON payload. JSON Error: " .
            json_last_error_msg(), 'WARN');

        json_error("Invalid request body", 400);
    }

    logActivity("[SETUP] Request payload decoded successfully.");

    $email = strtolower(trim($input['email'] ?? ''));
    $code = trim((string)($input['code'] ?? ''));

    logActivity("[SETUP] Submitted email: " .
        (filter_var($email, FILTER_VALIDATE_EMAIL) ? maskEmail($email) : '[INVALID EMAIL]'));

    logActivity("[SETUP] OTP supplied: " .
        ($code !== '' ? 'YES' : 'NO'));

    // Never log the actual OTP.


    // --------------------------------------------------
    // STEP 3: VALIDATE EMAIL ADDRESS
    // --------------------------------------------------

    logActivity("[SETUP] Step 3: Validating submitted email address.");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        logActivity("[SETUP] Email validation failed: Invalid email address.", 'WARN');

        json_error("Please provide a valid email address", 400);
    }

    logActivity("[SETUP] Email format validation successful.");


    // --------------------------------------------------
    // STEP 4: VALIDATE OTP FORMAT
    // --------------------------------------------------

    logActivity("[SETUP] Step 4: Validating OTP format.");

    if (!preg_match('/^\d{6}$/', $code)) {

        logActivity("[SETUP] OTP format validation failed. " .
            "Expected exactly 6 numeric digits. Supplied length: " .
            strlen($code), 'WARN');

        json_error("Please enter the 6-digit code from your email", 400);
    }

    logActivity("[SETUP] OTP format validation successful. " .
        "OTP contains exactly 6 numeric digits.");


    // --------------------------------------------------
    // STEP 5: VALIDATE PENDING SETUP SESSION
    // --------------------------------------------------

    logActivity("[SETUP] Step 5: Checking pending setup session.");

    $pendingEmail = $_SESSION['setup_pending_email'] ?? null;

    if (!$pendingEmail) {

        logActivity("[SETUP] Session validation failed: " .
            "No pending setup email found in the current session.", 'WARN');

        json_error("Please restart the setup flow", 400);
    }

    logActivity("[SETUP] Pending setup email found in session: " .
        maskEmail($pendingEmail));

    if ($pendingEmail !== $email) {

        logActivity("[SETUP] Session email mismatch. " .
            "Submitted Email: " . maskEmail($email) .
            " | Pending Session Email: " . maskEmail($pendingEmail), 'WARN');

        json_error("Please restart the setup flow", 400);
    }

    logActivity("[SETUP] Session email matches submitted email. " .
        "Pending setup session validation successful.");


    // --------------------------------------------------
    // STEP 6: CHECK OTP VERIFICATION ATTEMPT LIMIT
    // --------------------------------------------------

    logActivity("[SETUP] Step 6: Checking OTP verification attempt limit.");

    $attemptKey = "setup_otp_attempts_" . md5($email);

    $attempts = (int)($_SESSION[$attemptKey] ?? 0);

    logActivity("[SETUP] Previous OTP verification attempts: " . $attempts);
    logActivity("[SETUP] Maximum permitted verification attempts: 5.");

    if ($attempts >= 5) {

        logActivity("[SETUP] OTP verification blocked. " .
            "Maximum verification attempts reached for " .
            maskEmail($email), 'WARN');

        json_error(
            "Too many attempts. Please wait a few minutes and try again.",
            429
        );
    }

    $_SESSION[$attemptKey] = $attempts + 1;

    logActivity("[SETUP] OTP verification attempt counter updated. " .
        "Current attempt: " . $_SESSION[$attemptKey]);


    // --------------------------------------------------
    // STEP 7: FIND ACTIVE TENANT ACCOUNT
    // --------------------------------------------------

    logActivity("[SETUP] Step 7: Looking up admin account.");

    logActivity("[SETUP] Admin lookup criteria: " .
        "Email match AND status = 1.");

    $stmt = $conn->prepare("
        SELECT unique_id, firstname, lastname
        FROM admin_tbl
        WHERE email = ? AND status = '1'
        LIMIT 1
    ");

    if (!$stmt) {

        logActivity("[SETUP] Admin lookup PREPARE failed. " .
            "Database error: " . $conn->error .
            " | Error code: " . $conn->errno, 'ERROR');

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] Admin lookup statement prepared successfully.");

    $stmt->bind_param("s", $email);

    logActivity("[SETUP] Admin email parameter bound successfully.");

    if (!$stmt->execute()) {

        logActivity("[SETUP] Admin lookup EXECUTION failed. " .
            "Database error: " . $stmt->error .
            " | Error code: " . $stmt->errno, 'ERROR');

        $stmt->close();

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] Admin lookup query executed successfully.");

    $queryResult = $stmt->get_result();

    if (!$queryResult) {

        logActivity("[SETUP] Failed to retrieve admin lookup result. " .
            "Error: " . $stmt->error, 'ERROR');

        $stmt->close();

        json_error("An unexpected error occurred", 500);
    }

    $admin = $queryResult->fetch_assoc();

    logActivity("[SETUP] Admin lookup completed. Matching active records: " .
        $queryResult->num_rows);

    $stmt->close();

    if (!$admin) {

        logActivity("[SETUP] Admin lookup failed. " .
            "No active admin account matched the submitted email: " .
            maskEmail($email), 'WARN');

        logActivity("[SETUP] Possible reasons: Email does not exist " .
            "or admin account is inactive.");

        // Don't leak whether the user exists.
        json_error("Invalid or expired code. Please try again.", 401);
    }

    logActivity("[SETUP] Active admin account found successfully.");
    logActivity("[SETUP] Admin Code: " . $admin['unique_id']);
    logActivity("[SETUP] Admin Email: " . maskEmail($email));


    // --------------------------------------------------
    // STEP 8: INITIALIZE OTP VERIFICATION SERVICE
    // --------------------------------------------------

    logActivity("[SETUP] Step 8: Initializing OTPVerification service.");

    $verifier = new OTPVerification($conn);

    logActivity("[SETUP] OTPVerification service initialized successfully.");

    // --------------------------------------------------
    // STEP 9: VERIFY OTP
    // --------------------------------------------------

    logActivity("[SETUP] Step 9: Calling OTPVerification::verifyOTP().");

    logActivity("[SETUP] OTP verification parameters: " .
        "Entity=admin, Email=" . maskEmail($email) .
        ", Purpose=account setup.");

    $verifyResult = $verifier->verifyOTP(
        'admin',
        $email,
        $code,
        'account setup'
    );

    logActivity("[SETUP] OTPVerification::verifyOTP() execution completed.");

    if (!is_array($verifyResult)) {

        logActivity("[SETUP] OTPVerification returned an invalid response type: " .
            gettype($verifyResult), 'ERROR');

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] OTP verification response received. Success: " .
        (!empty($verifyResult['success']) ? 'TRUE' : 'FALSE'));

    if (!$verifyResult['success']) {

        logActivity("[SETUP] OTP verification FAILED for admin " .
            $admin['unique_id'] . ". " .
            "Service message: " .
            ($verifyResult['message'] ?? 'No message returned') .
            " | Response code: " .
            ($verifyResult['code'] ?? 'Not provided'), 'WARN');

        json_error("Invalid or expired code. Please try again.", 401);
    }

    logActivity("[SETUP] OTP verification SUCCESSFUL for admin " .
        $admin['unique_id'] . ".");


    // --------------------------------------------------
    // STEP 10: CLEAR PENDING OTP SESSION DATA
    // --------------------------------------------------

    logActivity("[SETUP] Step 10: Clearing pending OTP session data.");

    unset($_SESSION[$attemptKey], $_SESSION['setup_pending_email']);

    logActivity("[SETUP] Pending OTP verification attempt counter cleared.");
    logActivity("[SETUP] Pending setup email removed from session.");

    
    // --------------------------------------------------
    // STEP 11: ISSUE SETUP TOKEN
    // --------------------------------------------------

    logActivity("[SETUP] Step 11: Issuing admin account setup token.");

    logActivity("[SETUP] Calling issueSetupToken(). " .
        "Admin Code: " . $admin['unique_id'] .
        " | Entity Type: admin.");

    $setupToken = issueSetupToken(
        $admin['unique_id'],
        'admin',
        $email
    );

    if (!is_string($setupToken) || $setupToken === '') {

        logActivity("[SETUP] Setup token issuance failed or returned an empty token. " .
            "Admin Code: " . $admin['unique_id'], 'ERROR');

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] Setup token issued successfully for admin " .
        $admin['unique_id'] . ".");

    // Never log the actual setup token.


    // --------------------------------------------------
    // STEP 12: STORE VERIFIED TENANT DETAILS IN SESSION
    // --------------------------------------------------

    logActivity("[SETUP] Step 12: Storing verified admin details in session.");

    $_SESSION['setup_verified_email'] = $email;
    $_SESSION['setup_verified_code'] = $admin['unique_id'];
    $_SESSION['setup_verified_type'] = 'admin';

    logActivity("[SETUP] Verified email stored in session.");
    logActivity("[SETUP] Verified admin code stored in session.");
    logActivity("[SETUP] Verified account type stored in session.");

    logActivity("[SETUP] Verified session details successfully established.");


    // --------------------------------------------------
    // STEP 13: COMPLETE REQUEST
    // --------------------------------------------------

    logActivity("[SETUP] OTP verification and setup token issuance completed successfully.");

    logActivity("[SETUP] ===== VERIFY SETUP OTP PROCESS COMPLETED SUCCESSFULLY =====");

    json_success([
        'setup_token' => $setupToken,
        'user_name' => trim(
            ($admin['firstname'] ?? '') . ' ' .
            ($admin['lastname'] ?? '')
        ),
    ], "Verified. Now set your password and security question.");

} catch (Throwable $e) {

    logActivity("[SETUP] UNHANDLED EXCEPTION OCCURRED.", 'ERROR');

    logActivity("[SETUP] Exception Type: " . get_class($e), 'ERROR');
    logActivity("[SETUP] Exception Message: " . $e->getMessage(), 'ERROR');
    logActivity("[SETUP] Exception File: " . $e->getFile(), 'ERROR');
    logActivity("[SETUP] Exception Line: " . $e->getLine(), 'ERROR');

    logActivity("[SETUP] ===== VERIFY SETUP OTP PROCESS TERMINATED WITH ERROR =====", 'ERROR');

    json_error("An unexpected error occurred", 500);
}


// --------------------------------------------------
// EMAIL MASKING HELPER
// --------------------------------------------------

function maskEmail(string $email): string {

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '[INVALID EMAIL]';
    }

    [$local, $domain] = explode('@', $email, 2);

    $show = max(1, min(3, strlen($local) - 2));

    return substr($local, 0, $show)
         . str_repeat('*', max(0, strlen($local) - $show - 1))
         . substr($local, -1) . '@' . $domain;
}
