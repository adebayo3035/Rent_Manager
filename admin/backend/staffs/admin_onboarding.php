<?php
// onboard_admin.php
// Secure administrator onboarding endpoint.
//
// FLOW:
// 1. Authenticate the requesting Super Admin.
// 2. Validate administrator details and profile photo.
// 3. Check duplicate email, phone number and photo.
// 4. Generate a unique administrator ID.
// 5. Create the administrator record using a random placeholder password.
// 6. Commit the database transaction.
// 7. Send the administrator welcome email with the OTP account setup link.
//
// IMPORTANT:
// The administrator must complete the OTP-based account setup process
// before being allowed to use the account normally.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/sendOTPGmail.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

rateLimiter();

// --------------------------------------------------
// STEP 1: AUTHENTICATION AND AUTHORIZATION
// --------------------------------------------------

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'admin_onboard',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_staff_form'
    ],
    'roles' => ['Super Admin']
]);

$loggedInUserId = (int) $auth['user_id'];
$loggedInUserRole = $auth['role'];
$loggedInUserEmail = $_SESSION['email'] ?? 'unknown';

$requestTraceId = uniqid('onboard_admin_', true);

// Track resources so failures can be cleaned up.
$upload_file = null;
$fileWasMoved = false;
$transactionStarted = false;

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ========== START ==========");
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Requesting User ID={$loggedInUserId}, Role={$loggedInUserRole}");

// --------------------------------------------------
// MAIN ONBOARDING PROCESS
// --------------------------------------------------

try {

    // --------------------------------------------------
    // STEP 2: COLLECT AND SANITIZE INPUTS
    // --------------------------------------------------

    $inputs = [
        'firstname' => $_POST['staff_firstname'] ?? '',
        'lastname' => $_POST['staff_lastname'] ?? '',
        'email' => $_POST['staff_email'] ?? '',
        'phone' => $_POST['staff_phone_number'] ?? '',
        'gender' => $_POST['staff_gender'] ?? '',
        'address' => $_POST['staff_address'] ?? '',
        'role' => $_POST['staff_role'] ?? ''
    ];

    foreach ($inputs as $key => $value) {
        if (!is_string($value)) {
            json_error("Invalid value supplied for {$key}.", 400);
        }

        $inputs[$key] = trim(strip_tags($value));
    }

    // Do not log the full input array because it contains personal information.
    logActivity(
        "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Input fields received: " .
        implode(', ', array_keys($inputs))
    );

    // --------------------------------------------------
    // STEP 3: REQUIRED FIELD VALIDATION
    // --------------------------------------------------

    // Password and security question are intentionally excluded.
    // They will be configured through the OTP-based account setup flow.

    $required = [
        'firstname',
        'lastname',
        'email',
        'phone',
        'gender',
        'address',
        'role'
    ];

    $missing = [];

    foreach ($required as $field) {
        if ($inputs[$field] === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Missing fields: " .
            implode(', ', $missing)
        );

        json_error(
            "Please fill all required fields. Missing: " . implode(', ', $missing),
            400
        );
    }

    // --------------------------------------------------
    // STEP 4: VALIDATE PHONE NUMBER
    // --------------------------------------------------

    if (!validate_phone($inputs['phone'])) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid phone number.");

        json_error('Please input a valid Phone Number.', 400);
    }

    // --------------------------------------------------
    // STEP 5: VALIDATE EMAIL
    // --------------------------------------------------

    if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid email address.");

        json_error('Invalid email address.', 400);
    }

    // --------------------------------------------------
    // STEP 6: VALIDATE GENDER
    // --------------------------------------------------

    $allowedGenders = ['Male', 'Female', 'Other'];

    if (!in_array($inputs['gender'], $allowedGenders, true)) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid gender.");

        json_error('Invalid gender value.', 400);
    }

    // --------------------------------------------------
    // STEP 7: VALIDATE ADMIN ROLE
    // --------------------------------------------------

    $allowedRoles = ['Super Admin', 'Admin'];

    if (!in_array($inputs['role'], $allowedRoles, true)) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid administrator role.");

        json_error('Invalid role.', 400);
    }

    // --------------------------------------------------
    // STEP 8: VALIDATE PHOTO UPLOAD
    // --------------------------------------------------

    if (!isset($_FILES['staff_photo'])) {
        json_error('Please upload a profile photo.', 400);
    }

    $photo = $_FILES['staff_photo'];

    if (!isset($photo['error']) || $photo['error'] !== UPLOAD_ERR_OK) {

        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the permitted form size.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by an extension.'
        ];

        $uploadError = $photo['error'] ?? UPLOAD_ERR_NO_FILE;

        $errorMessage = $errorMessages[$uploadError] ?? 'Unknown upload error.';

        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Photo upload error: {$uploadError}"
        );

        json_error($errorMessage, 400);
    }

    $img_name = basename($photo['name']);
    $tmp_name = $photo['tmp_name'];
    $img_size = (int) $photo['size'];

    if (
        empty($tmp_name) ||
        !is_uploaded_file($tmp_name)
    ) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid uploaded file.");

        json_error('Invalid uploaded file.', 400);
    }

    // --------------------------------------------------
    // STEP 9: VALIDATE PHOTO EXTENSION
    // --------------------------------------------------

    $ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

    $allowedExt = ['jpg', 'jpeg', 'png'];

    if (!in_array($ext, $allowedExt, true)) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid photo extension.");

        json_error('Invalid image file. Allowed types: JPG, JPEG and PNG.', 400);
    }

    // --------------------------------------------------
    // STEP 10: VALIDATE ACTUAL PHOTO MIME TYPE
    // --------------------------------------------------

    if (!function_exists('finfo_open')) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Fileinfo extension unavailable.", 'ERROR');

        json_error('Server configuration error. Please contact support.', 500);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Unable to initialize fileinfo.", 'ERROR');

        json_error('Server configuration error. Please contact support.', 500);
    }

    $actualMime = finfo_file($finfo, $tmp_name);

    if ($actualMime === false) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Unable to determine uploaded file MIME type.", 'ERROR');

        json_error('Unable to validate uploaded image.', 400);
    }

    $extMimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png'
    ];

    if (
        $actualMime === false ||
        $actualMime !== $extMimeMap[$ext]
    ) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Photo MIME validation failed.");

        json_error('File content does not match its extension.', 400);
    }

    // --------------------------------------------------
    // STEP 11: VALIDATE PHOTO SIZE
    // --------------------------------------------------

    if ($img_size <= 0 || $img_size > 500000) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Invalid photo size: {$img_size} bytes.");

        json_error('Sorry, your file is too large or invalid. Maximum allowed size is 500KB.', 400);
    }

    // --------------------------------------------------
    // STEP 12: CHECK DUPLICATE EMAIL AND PHONE
    // --------------------------------------------------

    $check_stmt = $conn->prepare(
        "SELECT unique_id, email, phone
         FROM admin_tbl
         WHERE email = ? OR phone = ?
         LIMIT 1"
    );

    if (!$check_stmt) {
        throw new RuntimeException("Unable to prepare administrator duplicate check.");
    }

    $check_stmt->bind_param(
        'ss',
        $inputs['email'],
        $inputs['phone']
    );

    if (!$check_stmt->execute()) {
        $check_stmt->close();

        throw new RuntimeException("Unable to execute administrator duplicate check.");
    }

    $res = $check_stmt->get_result();

    if ($res === false) {
        $check_stmt->close();

        throw new RuntimeException("Unable to retrieve administrator duplicate check result.");
    }

    if ($row = $res->fetch_assoc()) {

        $duplicates = [];

        if ($row['email'] === $inputs['email']) {
            $duplicates['email'] = 'Email already exists.';
        }

        if ($row['phone'] === $inputs['phone']) {
            $duplicates['phone'] = 'Phone number already exists.';
        }

        $check_stmt->close();

        if (!empty($duplicates)) {
            logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate email or phone detected.");

            json_error($duplicates, 409);
        }

    } else {
        $check_stmt->close();
    }

    // --------------------------------------------------
    // STEP 13: PREPARE PHOTO UPLOAD DIRECTORY
    // --------------------------------------------------

    $upload_dir = __DIR__ . '/admin_photos/';

    if (!is_dir($upload_dir)) {

        if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            throw new RuntimeException("Unable to create administrator photo directory.");
        }
    }

    if (!is_writable($upload_dir)) {
        throw new RuntimeException("Administrator photo directory is not writable.");
    }

    // --------------------------------------------------
    // STEP 14: GENERATE HASH-BASED PHOTO FILENAME
    // --------------------------------------------------

    $file_hash = hash_file('sha256', $tmp_name);

    if ($file_hash === false) {
        throw new RuntimeException("Unable to generate uploaded photo hash.");
    }

    $file_name = $file_hash . '.' . $ext;
    $upload_file = $upload_dir . $file_name;

    // --------------------------------------------------
    // STEP 15: CHECK DUPLICATE PHOTO IN DATABASE
    // --------------------------------------------------

    $photo_check_stmt = $conn->prepare(
        "SELECT 1 FROM admin_tbl WHERE photo = ? LIMIT 1"
    );

    if (!$photo_check_stmt) {
        throw new RuntimeException("Unable to prepare photo duplicate check.");
    }

    $photo_check_stmt->bind_param('s', $file_name);

    if (!$photo_check_stmt->execute()) {
        $photo_check_stmt->close();

        throw new RuntimeException("Unable to execute photo duplicate check.");
    }

    $photo_res = $photo_check_stmt->get_result();

    if ($photo_res === false) {
        $photo_check_stmt->close();

        throw new RuntimeException("Unable to retrieve photo duplicate check result.");
    }

    if ($photo_res->num_rows > 0) {
        $photo_check_stmt->close();

        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate profile photo detected.");

        json_error('This image has already been uploaded by another user.', 409);
    }

    $photo_check_stmt->close();

    // Also reject if the same hashed file already exists on disk.
    if (is_file($upload_file)) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate photo detected on disk.");

        json_error('This image has already been uploaded by another user.', 409);
    }

    // --------------------------------------------------
    // STEP 16: GENERATE UNIQUE ADMINISTRATOR ID
    // --------------------------------------------------

    $ran_id = generateUniqueAdminId($conn);

    if (empty($ran_id)) {
        throw new RuntimeException("Unable to generate unique administrator ID.");
    }

    // --------------------------------------------------
    // STEP 17: GENERATE RANDOM PLACEHOLDER PASSWORD
    // --------------------------------------------------

    // The placeholder is never sent to the administrator.
    // The administrator must establish a real password through account setup.

    $temporaryPassword = bin2hex(random_bytes(32));

    $encrypt_pass = password_hash(
        $temporaryPassword,
        PASSWORD_ARGON2ID
    );

    // Clear the plaintext placeholder from the variable.
    unset($temporaryPassword);

    if (!$encrypt_pass) {
        throw new RuntimeException("Unable to generate administrator password hash.");
    }

    // --------------------------------------------------
    // STEP 18: MOVE PROFILE PHOTO
    // --------------------------------------------------

    if (!move_uploaded_file($tmp_name, $upload_file)) {
        throw new RuntimeException("Failed to save administrator profile photo.");
    }

    $fileWasMoved = true;

    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Profile photo uploaded successfully.");

    // --------------------------------------------------
    // STEP 19: BEGIN DATABASE TRANSACTION
    // --------------------------------------------------

    if (!$conn->begin_transaction()) {
        throw new RuntimeException("Unable to start administrator onboarding transaction.");
    }

    $transactionStarted = true;

    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Database transaction started.");

    // --------------------------------------------------
    // STEP 20: INSERT ADMINISTRATOR RECORD
    // --------------------------------------------------

    $insert_sql = "
        INSERT INTO admin_tbl
        (
            unique_id,
            firstname,
            lastname,
            email,
            phone,
            address,
            gender,
            password,
            photo,
            role,
            status,
            onboarded_by,
            last_updated_by,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $insert_stmt = $conn->prepare($insert_sql);

    if (!$insert_stmt) {
        throw new RuntimeException("Unable to prepare administrator onboarding query.");
    }

    // Retain the existing status representation from your implementation.
    $status = '1';

    $insert_stmt->bind_param(
        'sssssssssssii',
        $ran_id,
        $inputs['firstname'],
        $inputs['lastname'],
        $inputs['email'],
        $inputs['phone'],
        $inputs['address'],
        $inputs['gender'],
        $encrypt_pass,
        $file_name,
        $inputs['role'],
        $status,
        $loggedInUserId,
        $loggedInUserId
    );

    if (!$insert_stmt->execute()) {

        $insertErrorNumber = $insert_stmt->errno;
        $insertErrorMessage = $insert_stmt->error;

        $insert_stmt->close();

        if ($insertErrorNumber === 1062) {
            logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate-key database constraint encountered.");

            throw new RuntimeException(
                'An administrator with the supplied email or phone number already exists.',
                409
            );
        }

        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Database insert failed: {$insertErrorMessage}",
            'ERROR'
        );

        throw new RuntimeException("Unable to create administrator record.");
    }

    $insert_stmt->close();

    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Administrator record inserted.");

    // --------------------------------------------------
    // STEP 21: COMMIT TRANSACTION
    // --------------------------------------------------

    if (!$conn->commit()) {
        throw new RuntimeException("Unable to commit administrator onboarding transaction.");
    }

    $transactionStarted = false;

    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Database transaction committed.");

    // Consume the onboarding CSRF token after successful creation.
    consumeCsrfToken('add_staff_form');

    // --------------------------------------------------
    // STEP 22: SEND ADMIN WELCOME EMAIL
    // --------------------------------------------------

    // Email delivery is best-effort.
    // A mail failure must not reverse a successfully created administrator.

    try {

        $setupBase = 'http://localhost/rent_manager';

        $setupLink = $setupBase . '/admin/pages/account_setup.php';

        $firstName = htmlspecialchars(
            $inputs['firstname'],
            ENT_QUOTES,
            'UTF-8'
        );

        $lastName = htmlspecialchars(
            $inputs['lastname'],
            ENT_QUOTES,
            'UTF-8'
        );

        $adminEmail = $inputs['email'];

        $emailEscaped = htmlspecialchars(
            $adminEmail,
            ENT_QUOTES,
            'UTF-8'
        );

        $setupLinkEscaped = htmlspecialchars(
            $setupLink,
            ENT_QUOTES,
            'UTF-8'
        );

        $htmlBody = "
        <div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;'>

            <div style='background:#1e3c72;color:#fff;padding:24px;border-radius:10px 10px 0 0;text-align:center;'>
                <h1 style='margin:0;font-size:22px;'>Welcome to RentFlow Pro</h1>

                <p style='margin:6px 0 0;opacity:0.8;font-size:13px;'>
                    Your administrator account is ready to activate
                </p>
            </div>

            <div style='background:#fff;padding:28px;border:1px solid #e2e8f0;border-radius:0 0 10px 10px;'>

                <p style='margin:0 0 16px;color:#0f172a;font-size:15px;'>
                    Hi {$firstName} {$lastName},
                </p>

                <p style='margin:0 0 16px;color:#334155;line-height:1.7;'>
                    Your administrator account has been successfully created on RentFlow Pro.
                    To activate your account, kindly complete the password and security setup process.
                </p>

                <p style='text-align:center;margin:24px 0;'>
                    <a href='{$setupLinkEscaped}'
                       style='background:#1e3c72;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                        Set up my account
                    </a>
                </p>

                <div style='background:#eff6ff;border-radius:6px;padding:14px;margin:0 0 16px;'>
                    <p style='margin:0 0 8px;color:#1e40af;font-size:13px;font-weight:600;'>
                        How to activate your account:
                    </p>

                    <ol style='margin:0;padding-left:20px;color:#475569;font-size:13px;line-height:1.8;'>
                        <li>Click the button above to open the account setup page.</li>
                        <li>Enter your registered email address: <strong>{$emailEscaped}</strong></li>
                        <li>Click <strong>Send code</strong> to receive a verification code.</li>
                        <li>Enter the 6-digit verification code sent to your email.</li>
                        <li>After successful verification, create your new password.</li>
                        <li>Select a security question and provide your security answer.</li>
                        <li>Complete the setup process to activate your account.</li>
                    </ol>
                </div>

                <p style='margin:0 0 8px;color:#64748b;font-size:12px;line-height:1.7;'>
                    Please ensure you complete this process to secure your account.
                    You can access the account setup page using the link below:
                    <br>
                    <a href='{$setupLinkEscaped}'
                       style='color:#1e3c72;font-weight:600;'>
                        {$setupLinkEscaped}
                    </a>
                </p>

                <p style='margin:16px 0 0;color:#94a3b8;font-size:11px;line-height:1.6;'>
                    If you did not expect this email or were not informed about the creation
                    of this administrator account, you can safely ignore this message
                    and contact the system administrator.
                </p>

            </div>

            <div style='text-align:center;padding:16px 8px;color:#94a3b8;font-size:11px;'>
                © RentFlow Pro — sent to you because an administrator account was created in your name.
            </div>

        </div>
        ";

        $sent = sendEmailWithGmailSMTP(
            $adminEmail,
            $htmlBody,
            'Welcome to RentFlow Pro — Set up your admin account',
            [],
            "{$inputs['firstname']} {$inputs['lastname']}"
        );

        if ($sent) {
            logActivity(
                "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Welcome email sent successfully."
            );
        } else {
            logActivity(
                "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Welcome email delivery failed.",
                'WARN'
            );
        }

    } catch (Throwable $mailErr) {

        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Welcome email exception: " .
            $mailErr->getMessage(),
            'WARN'
        );

        // Do not throw. The administrator record has already been committed.
    }

    // --------------------------------------------------
    // STEP 23: SUCCESS RESPONSE
    // --------------------------------------------------

    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ========== SUCCESS ==========");

    logActivity(
        "[ONBOARD_ADMIN] [ID:{$requestTraceId}] New administrator created. " .
        "Admin ID={$ran_id}, Onboarded by ID={$loggedInUserId}"
    );

    json_success([
        'admin_id' => $ran_id,
        'name' => $inputs['firstname'] . ' ' . $inputs['lastname'],
        'email' => $inputs['email'],
        'role' => $inputs['role']
    ], 'New Staff has been successfully Onboarded!');


} catch (Throwable $e) {

    // --------------------------------------------------
    // STEP 24: ROLLBACK AND CLEANUP ON FAILURE
    // --------------------------------------------------

    if ($transactionStarted) {

        $conn->rollback();

        $transactionStarted = false;

        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Transaction rolled back.",
            'ERROR'
        );
    }

    // Remove the uploaded photo if the database operation failed.
    if (
        $fileWasMoved &&
        $upload_file !== null &&
        is_file($upload_file)
    ) {
        @unlink($upload_file);

        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Uploaded photo removed after failure."
        );
    }

    $errorCode = (int) $e->getCode();

    // Only expose expected conflict messages.
    if ($errorCode === 409) {

        logActivity(
            "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Onboarding conflict: " . $e->getMessage(),
            'WARN'
        );

        json_error($e->getMessage(), 409);
    }

    // Keep internal database/server details out of the API response.
    logActivity(
        "[ONBOARD_ADMIN] [ID:{$requestTraceId}] Onboarding exception: " .
        $e->getMessage(),
        'ERROR'
    );

    json_error(
        'Unable to complete administrator onboarding at this time. Please try again or contact support.',
        500
    );
}
