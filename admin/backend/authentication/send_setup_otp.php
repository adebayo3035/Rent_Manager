
<?php
// admin/backend/auth/send_setup_otp.php
// Admin account setup — Step 1: send OTP

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/otp_service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

logActivity("[SETUP] ===== SEND SETUP OTP PROCESS STARTED =====");
logActivity("[SETUP] Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
logActivity("[SETUP] Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
logActivity("[SETUP] Session Status: " . session_status());

rateLimiter();

try {

    // STEP 1: Validate request method

    logActivity("[SETUP] Step 1: Validating request method.");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("[SETUP] Request rejected: Invalid HTTP method.", 'WARN');
        json_error("Method not allowed", 405);
    }

    logActivity("[SETUP] Request method validation successful.");

    // STEP 2: Read and validate request data

    logActivity("[SETUP] Step 2: Reading incoming request payload.");

    $rawInput = file_get_contents('php://input');

    logActivity("[SETUP] Request payload received. Payload length: " .
        ($rawInput !== false ? strlen($rawInput) : 0) . " bytes.");

    $input = json_decode($rawInput ?: '', true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        logActivity("[SETUP] Invalid JSON request payload. JSON Error: " .
            json_last_error_msg(), 'WARN');

        json_error("Invalid request body", 400);
    }

    logActivity("[SETUP] Request payload successfully decoded.");

    $email = strtolower(trim($input['email'] ?? ''));

    logActivity("[SETUP] Email extracted from request: " .
        (filter_var($email, FILTER_VALIDATE_EMAIL) ? maskEmail($email) : '[INVALID EMAIL]'));

    // STEP 3: Validate email format

    logActivity("[SETUP] Step 3: Validating supplied email address.");

    if (empty($email)) {
        logActivity("[SETUP] Email validation failed: No email supplied.", 'WARN');
        json_error("Please provide a valid email address", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("[SETUP] Email validation failed: Invalid email format.", 'WARN');
        json_error("Please provide a valid email address", 400);
    }

    logActivity("[SETUP] Email format validation successful.");

    // STEP 4: Check per-email session rate limit

    logActivity("[SETUP] Step 4: Checking per-email OTP request limit.");

    $key = "setup_otp_sends_" . md5($email);
    $now = time();

    $sends = $_SESSION[$key] ?? [];

    logActivity("[SETUP] Existing OTP attempts recorded in session: " . count($sends));

    $sends = array_values(array_filter($sends, fn($t) => $t > $now - 3600));

    logActivity("[SETUP] OTP attempts within the last 60 minutes: " . count($sends));

    if (count($sends) >= 5) {
        logActivity("[SETUP] OTP request blocked: Maximum of 5 attempts reached for " .
            maskEmail($email), 'WARN');

        json_error("Too many setup attempts. Please try again later.", 429);
    }

    logActivity("[SETUP] Per-email OTP rate limit check passed.");

    // STEP 5: Find admin by email

    logActivity("[SETUP] Step 5: Checking whether supplied email belongs to a admin.");

    logActivity("[SETUP] Executing active admin lookup. Search criteria: " .
        "email match AND status = 1.");

    $stmt = $conn->prepare("
        SELECT unique_id, firstname, lastname, password_changed, has_secret_set
        FROM admin_tbl
        WHERE email = ? AND status = '1'
        LIMIT 1
    ");

    if (!$stmt) {
        logActivity("[SETUP] Admin lookup PREPARE failed. Database error: " .
            $conn->error . " | Error code: " . $conn->errno, 'ERROR');

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] Admin lookup statement prepared successfully.");

    $stmt->bind_param("s", $email);

    logActivity("[SETUP] Admin email parameter bound successfully.");

    if (!$stmt->execute()) {
        logActivity("[SETUP] Admin lookup EXECUTION failed. Database error: " .
            $stmt->error . " | Error code: " . $stmt->errno, 'ERROR');

        $stmt->close();

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] Admin lookup query executed successfully.");

    $queryResult = $stmt->get_result();

    if (!$queryResult) {
        logActivity("[SETUP] Failed to retrieve admin lookup result. Error: " .
            $stmt->error, 'ERROR');

        $stmt->close();

        json_error("An unexpected error occurred", 500);
    }

    $admin = $queryResult->fetch_assoc();

    logActivity("[SETUP] Admin lookup completed. Matching active records: " .
        $queryResult->num_rows);

    $stmt->close();

    // STEP 6: Determine admin eligibility

    if (!$admin) {

        logActivity("[SETUP] Admin eligibility check FAILED. " .
            "No admin record matched the supplied email with status = 1.", 'WARN');

        logActivity("[SETUP] Possible reasons: Email is not registered, " .
            "email does not match an existing admin, or admin account is inactive.");

        // Don't reveal whether email exists
        json_success(
            ['email_masked' => maskEmail($email)],
            "If this email is registered, you'll receive a setup code shortly."
        );

        exit;
    }

    logActivity("[SETUP] Admin record found successfully.");

    logActivity("[SETUP] Admin Code: " . $admin['unique_id']);
    logActivity("[SETUP] Admin Firstname: " . $admin['firstname']);
    logActivity("[SETUP] Admin Lastname: " . $admin['lastname']);
    logActivity("[SETUP] Admin Email: " . maskEmail($email));

    logActivity("[SETUP] Evaluating admin account setup eligibility.");

    logActivity("[SETUP] Password Changed Status: " .
        $admin['password_changed']);

    logActivity("[SETUP] Secret Configuration Status: " .
        $admin['has_secret_set']);

    $passwordChanged = ((int)$admin['password_changed'] === 1);
    $hasSecretSet = ((int)$admin['has_secret_set'] === 1);

    $alreadySetUp = $passwordChanged && $hasSecretSet;

    logActivity("[SETUP] Admin setup evaluation: " .
        "Password Changed=" . ($passwordChanged ? 'YES' : 'NO') .
        ", Secret Set=" . ($hasSecretSet ? 'YES' : 'NO'));

    if ($alreadySetUp) {

        logActivity("[SETUP] Admin eligibility check FAILED. " .
            "Account has already completed setup. Admin Code: " .
            $admin['unique_id'], 'WARN');

        json_error(
            "This account has already been set up. Please log in instead.",
            409
        );
    }

    logActivity("[SETUP] Admin eligibility check PASSED. " .
        "Account is eligible for setup OTP.");

    // STEP 7: Generate OTP via OTPService

    logActivity("[SETUP] Step 7: Initializing OTP generation process.");

    $requestId = uniqid('setup_otp_', true);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    logActivity("[SETUP] OTP Request ID generated: " . $requestId);
    logActivity("[SETUP] OTP recipient: " . maskEmail($email));
    logActivity("[SETUP] OTP request purpose: RentFlow Pro Account Setup.");

    logActivity("[SETUP] Initializing OTPService.");

    $otpService = new OTPService(
        $conn,
        'admin',
        'admin_tbl',
        $requestId,
        $ipAddress
    );

    logActivity("[SETUP] OTPService initialized successfully.");

    logActivity("[SETUP] Calling OTPService::generateOTP().");

    $result = $otpService->generateOTP($email, 'RentFlow Pro Account Setup');

    logActivity("[SETUP] OTPService::generateOTP() execution completed.");

    if (!is_array($result)) {
        logActivity("[SETUP] OTPService returned an invalid response type: " .
            gettype($result), 'ERROR');

        json_error("An unexpected error occurred", 500);
    }

    logActivity("[SETUP] OTPService response success status: " .
        (!empty($result['success']) ? 'TRUE' : 'FALSE'));

    if (!$result['success']) {

        logActivity("[SETUP] OTP generation failed for admin " .
            $admin['unique_id'] . ". Request ID: " . $requestId .
            ". Service message: " . ($result['message'] ?? 'No message returned') .
            ". Response code: " . ($result['code'] ?? 'Not provided'), 'WARN');

        json_error($result['message'] ?? 'Unable to generate OTP',
            $result['code'] ?? 400);
    }

    logActivity("[SETUP] OTPService reported successful OTP processing for admin " .
        $admin['unique_id'] . ". Request ID: " . $requestId);

    // STEP 8: Update session

    logActivity("[SETUP] Step 8: Updating session after successful OTP processing.");

    $sends[] = $now;

    $_SESSION[$key] = $sends;

    logActivity("[SETUP] OTP attempt counter updated successfully. " .
        "Total attempts in current 60-minute window: " . count($sends));

    $_SESSION['setup_pending_email'] = $email;

    logActivity("[SETUP] Pending setup email stored in session successfully.");

    // STEP 9: Complete request

    logActivity("[SETUP] All processing steps completed successfully.");
    logActivity("[SETUP] OTP sent for admin " . $admin['unique_id'] .
        " <" . maskEmail($email) . ">.");

    logActivity("[SETUP] ===== SEND SETUP OTP PROCESS COMPLETED SUCCESSFULLY =====");

    json_success(
        ['email_masked' => maskEmail($email)],
        "We've sent a 6-digit code to your email."
    );

} catch (Throwable $e) {

    logActivity("[SETUP] UNHANDLED EXCEPTION OCCURRED.", 'ERROR');

    logActivity("[SETUP] Exception Type: " . get_class($e), 'ERROR');
    logActivity("[SETUP] Exception Message: " . $e->getMessage(), 'ERROR');
    logActivity("[SETUP] Exception File: " . $e->getFile(), 'ERROR');
    logActivity("[SETUP] Exception Line: " . $e->getLine(), 'ERROR');

    logActivity("[SETUP] ===== SEND SETUP OTP PROCESS TERMINATED WITH ERROR =====", 'ERROR');

    json_error("An unexpected error occurred", 500);
}

function maskEmail(string $email): string {

    [$local, $domain] = explode('@', $email, 2);

    $show = max(1, min(3, strlen($local) - 2));

    return substr($local, 0, $show)
         . str_repeat('*', max(0, strlen($local) - $show - 1))
         . substr($local, -1) . '@' . $domain;
}
