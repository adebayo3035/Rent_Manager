<?php
// onboard_admin.php — Optimized with Transactions + Try/Catch

header('Content-Type: application/json; charset=utf-8');

define('CSRF_FORM_NAME', 'add_client_form');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/sendOTPGmail.php';
// require_once __DIR__ . '/../../../mailer.php';   // ← added for sendEmailWithGmailSMTP

if (!isset($_SESSION)) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_onboarding',
    'rate_limit' => [10, 60],
    'csrf'       => [
        'enabled'   => true,
        'form_name' => 'add_client_form',
    ],
    'roles' => ['Super Admin', 'Admin'],
]);

$userId   = $auth['user_id'];
$userRole = $auth['role'];

logActivity("Authenticated user: {$userId} | Role: {$userRole}");

// --------------------------- TRY / CATCH WRAPPER ---------------------------
try {

    // ------------------------- INPUT COLLECTION -------------------------
    $inputs = sanitize_inputs([
        'firstname' => $_POST['client_firstname']    ?? '',
        'lastname'  => $_POST['client_lastname']     ?? '',
        'email'     => $_POST['client_email']        ?? '',
        'phone'     => $_POST['client_phone_number'] ?? '',
        'address'   => $_POST['client_address']      ?? '',
        'gender'    => $_POST['client_gender']       ?? '',
    ]);

    logActivity("Sanitized inputs: " . json_encode($inputs));

    // ------------------------- VALIDATIONS -------------------------
    foreach ($inputs as $key => $value) {
        if ($value === '') {
            logActivity("Validation failed — missing field: {$key}");
            json_error("Missing required field: {$key}", 400);
        }
    }

    if (!validate_phone($inputs['phone'])) {
        logActivity("Invalid phone number entered: {$inputs['phone']}");
        json_error('Invalid phone number. Must be 11 digits.', 400);
    }

    if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
        logActivity("Invalid email address: {$inputs['email']}");
        json_error('Invalid email address.', 400);
    }

    logActivity("Core input validation passed.");

    // ------------------------- FILE VALIDATION -------------------------
    if (!isset($_FILES['client_photo'])) {
        logActivity("Photo missing from request.");
        json_error('Please upload a profile photo.', 400);
    }

    $photo = $_FILES['client_photo'];

    if ($photo['error'] !== UPLOAD_ERR_OK) {
        logActivity("Upload error: " . $photo['error']);
        json_error('Photo upload failed.', 400);
    }

    $img_tmp  = $photo['tmp_name'];
    $img_name = $photo['name'];
    $img_size = $photo['size'];
    $img_type = mime_content_type($img_tmp);

    logActivity("Uploaded image detected: {$img_name}, size={$img_size}, type={$img_type}");

    $allowed_ext  = ['jpg', 'jpeg', 'png'];
    $allowed_mime = ['image/jpeg', 'image/png'];

    $ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext) || !in_array($img_type, $allowed_mime)) {
        logActivity("Rejected invalid image upload: EXT={$ext}, MIME={$img_type}");
        json_error('Only JPG, JPEG & PNG images allowed.', 400);
    }

    if ($img_size > 500000) {
        logActivity("Image too large: {$img_size}");
        json_error('Image too large. Max allowed size is 500KB.', 400);
    }

    $file_hash = hash_file('sha256', $img_tmp);
    $file_name = $file_hash . '.' . $ext;

    logActivity("Image hashed to unique name: {$file_name}");

    $upload_dir  = __DIR__ . '/client_photos/';
    $upload_path = $upload_dir . $file_name;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
        logActivity("Created missing upload directory: {$upload_dir}");
    }

    // ------------------------- DUPLICATE CHECK -------------------------
    logActivity("Checking duplicates: email={$inputs['email']}, phone={$inputs['phone']}");

    $dup_sql = "SELECT email, phone, photo FROM clients WHERE email = ? OR phone = ? OR photo = ? LIMIT 1";
    $stmt    = $conn->prepare($dup_sql);
    $stmt->bind_param("sss", $inputs['email'], $inputs['phone'], $file_name);
    $stmt->execute();
    $dup_res = $stmt->get_result();

    if ($dup_res->num_rows > 0) {
        $existing = $dup_res->fetch_assoc();
        logActivity("Duplicate detected: " . json_encode($existing));
        json_error("Duplicate detected: Email, Phone or Photo already exists.", 409);
    }

    $stmt->close();
    logActivity("No duplicates found. Proceeding.");

    // ------------------------- BEGIN TRANSACTION -------------------------
    $conn->begin_transaction();
    logActivity("Database transaction started.");

    // ------------------------- MOVE FILE -------------------------
    if (!move_uploaded_file($img_tmp, $upload_path)) {
        logActivity("Failed to move file to {$upload_path}");
        throw new Exception("Failed to save uploaded image.");
    }

    logActivity("Image moved successfully: {$upload_path}");

    // ------------------------- DB INSERT -------------------------
    $client_code = "CLIENT" . random_unique_id();
    logActivity("Generated unique client code: {$client_code}");

    // Default password: the client code itself.
    // Nobody knows this password — the client must set their own via the setup flow.
    $encrypt_pass = password_hash($client_code, PASSWORD_ARGON2ID);

    $insert_sql = "
        INSERT INTO clients
        (client_code, firstname, lastname, email, password, phone, address, photo, gender, onboarded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        logActivity("DB prepare failed: " . $conn->error);
        throw new Exception("Database prepare error.");
    }

    $stmt->bind_param(
        "sssssssssi",
        $client_code,
        $inputs['firstname'],
        $inputs['lastname'],
        $inputs['email'],
        $encrypt_pass,
        $inputs['phone'],
        $inputs['address'],
        $file_name,
        $inputs['gender'],
        $userId
    );

    if (!$stmt->execute()) {
        logActivity("DB execute failed: " . $stmt->error);
        throw new Exception("Failed to insert client.");
    }

    $stmt->close();
    logActivity("Client inserted successfully: {$client_code}");

    // ------------------------- COMMIT -------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");
    consumeCsrfToken(CSRF_FORM_NAME);

    // ▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼
    // WELCOME EMAIL — best-effort, after commit, before response
    // ▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼▼
    try {
        // Everything (first login, password and security setup) happens on the login page.
        // Adapt the hostname once you deploy; hard-coded for localhost now.
        $setupBase = 'http://localhost/rent_manager';
        $loginLink = $setupBase . '/client/pages/index.php';

        $firstName         = htmlspecialchars($inputs['firstname'], ENT_QUOTES, 'UTF-8');
        $lastName          = htmlspecialchars($inputs['lastname'],  ENT_QUOTES, 'UTF-8');
        $clientEmail       = $inputs['email'];
        $clientCodeEscaped = htmlspecialchars($client_code, ENT_QUOTES, 'UTF-8');

        $htmlBody = "
        <div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;'>
            <div style='background:#1e3c72;color:#fff;padding:24px;border-radius:10px 10px 0 0;text-align:center;'>
                <h1 style='margin:0;font-size:22px;'>Welcome to RentFlow Pro</h1>
                <p style='margin:6px 0 0;opacity:0.8;font-size:13px;'>Your client account is ready to activate</p>
            </div>
            <div style='background:#fff;padding:28px;border:1px solid #e2e8f0;border-radius:0 0 10px 10px;'>
                <p style='margin:0 0 16px;color:#0f172a;font-size:15px;'>Hi {$firstName} {$lastName},</p>
                <p style='margin:0 0 16px;color:#334155;line-height:1.7;'>
                    Your client account has been created on RentFlow Pro. For your first login, use your
                    Client ID as both your <strong>username</strong> and your <strong>password</strong>.
                    After you log in, you will be asked to set your own password and security details.
                </p>
                <p style='text-align:center;margin:24px 0;'>
                    <a href='{$loginLink}' style='background:#1e3c72;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                        Log in to my account
                    </a>
                </p>
                <div style='background:#eff6ff;border-radius:6px;padding:12px 14px;margin:0 0 16px;'>
                    <p style='margin:0;color:#1e40af;font-size:13px;line-height:1.8;'>
                        <strong>Username:</strong> <code style='font-family:monospace;background:#dbeafe;padding:2px 6px;border-radius:4px;'>{$clientCodeEscaped}</code><br>
                        <strong>Password (first login only):</strong> <code style='font-family:monospace;background:#dbeafe;padding:2px 6px;border-radius:4px;'>{$clientCodeEscaped}</code>
                    </p>
                </div>
                <div style='background:#f8fafc;border-left:3px solid #1e3c72;padding:14px 16px;border-radius:6px;margin:0 0 16px;'>
                    <p style='margin:0 0 8px;color:#334155;font-size:13px;font-weight:600;'>How to get set up:</p>
                    <ol style='margin:0;padding-left:20px;color:#64748b;font-size:13px;line-height:1.7;'>
                        <li>Click the button above, or open this link: <a href='{$loginLink}' style='color:#1e3c72;'>{$loginLink}</a></li>
                        <li>Enter your Client ID <strong>{$clientCodeEscaped}</strong> as both your username and password</li>
                        <li>When prompted, create your new password</li>
                        <li>Set up your security details</li>
                    </ol>
                </div>
                <p style='margin:0 0 8px;color:#64748b;font-size:12px;line-height:1.6;'>
                    Your Client ID works as a password only for this first login. For your security, please complete the setup straight away.
                    After that, log in anytime at:<br>
                    <a href='{$loginLink}' style='color:#1e3c72;font-weight:600;'>{$loginLink}</a>
                </p>
                <p style='margin:16px 0 0;color:#94a3b8;font-size:11px;'>
                    If you didn't expect this email, you can safely ignore it.
                </p>
            </div>
            <div style='text-align:center;padding:16px 8px;color:#94a3b8;font-size:11px;'>
                © RentFlow Pro — sent to you because a client account was created in your name.
            </div>
        </div>
        ";

        $sent = sendEmailWithGmailSMTP(
            // $clientEmail,
            "adebayoabdulrahmon@gmail.com",
            $htmlBody,
            'Welcome to RentFlow Pro — Log in to set up your client account',
            [],
            "{$inputs['firstname']} {$inputs['lastname']}"
        );

        if ($sent) {
            logActivity("[ONBOARD_CLIENT] Welcome email sent to {$clientEmail} for {$client_code}");
        } else {
            logActivity("[ONBOARD_CLIENT] Welcome email FAILED for {$clientEmail} (see email log)", 'WARN');
        }

    } catch (Throwable $mailErr) {
        logActivity("[ONBOARD_CLIENT] Welcome email threw for {$client_code}: " . $mailErr->getMessage(), 'WARN');
        // Do NOT rethrow — email failure must not fail onboarding
    }
    // ▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲

    // ------------------------- SUCCESS RESPONSE -------------------------
    json_success("New Client onboarded successfully!", [
        "client_code" => $client_code,
    ]);

} catch (Exception $e) {

    // ------------------------- ROLLBACK ON FAILURE -------------------------
    if ($conn->errno) {
        $conn->rollback();
        logActivity("Transaction rolled back due to error.");
    }

    // Delete uploaded file if it was moved before an error
    if (isset($upload_path) && file_exists($upload_path)) {
        unlink($upload_path);
        logActivity("Rollback cleanup: Removed uploaded image {$upload_path}");
    }

    logActivity("Exception caught: " . $e->getMessage());
    json_error("Onboarding failed: " . $e->getMessage(), 500);
}