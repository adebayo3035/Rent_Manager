<?php
// onboard_admin.php
// Secure admin onboarding endpoint.
//
// SECURITY:
//   - requireAuth(): CSRF, per-route rate limit, role gate (Super Admin)
//   - Password strength enforced (incl. lowercase — matches client/tenant policy)
//   - Secret question validated against allowed whitelist
//   - Role validated against allowed enum
//   - Real MIME check via finfo_file (does not trust $_FILES['type'])
//   - Photo stored by content hash — dedup across admins
//   - Duplicate email/phone pre-check + errno 1062 race handling
//   - Transaction wraps insert; file cleaned up on any failure
//   - Old-photo-safe: an upload that succeeds but whose INSERT fails leaves no
//     orphan file on disk

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

// ---- Auth: centralises CSRF, rate limit, role gate ----
$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'admin_onboard',
    'rate_limit' => [10, 60],           // 10 onboardings per minute
    'csrf'       => ['enabled' => true, 'form_name' => 'add_staff_form'],
    'roles'      => ['Super Admin'],
]);

$loggedInUserId    = (int) $auth['user_id'];
$loggedInUserRole  = $auth['role'];
$loggedInUserEmail = $_SESSION['email'] ?? 'unknown';

$requestTraceId = uniqid('onboard_admin_', true);
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ========== START ==========");
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Onboarded by: ID={$loggedInUserId}, Role={$loggedInUserRole}");

// ---------- STEP 1: SANITIZE INPUTS ----------
$inputs = [
    'firstname'       => $_POST['staff_firstname']       ?? '',
    'lastname'        => $_POST['staff_lastname']        ?? '',
    'email'           => $_POST['staff_email']           ?? '',
    'phone'           => $_POST['staff_phone_number']    ?? '',
    'gender'          => $_POST['staff_gender']          ?? '',
    'address'         => $_POST['staff_address']         ?? '',
    'password'        => $_POST['staff_password']        ?? '',
    'secret_question' => $_POST['staff_secret_question'] ?? '',
    'secret_answer'   => $_POST['staff_secret_answer']   ?? '',
    'role'            => $_POST['staff_role']            ?? '',
];

// Trim + strip tags on non-sensitive text fields (do NOT strip_tags password)
foreach (['firstname', 'lastname', 'email', 'phone', 'gender', 'address', 'secret_question', 'secret_answer', 'role'] as $k) {
    $inputs[$k] = trim(strip_tags($inputs[$k]));
}
$inputs['password'] = trim($inputs['password']);

$logCopy = $inputs;
unset($logCopy['password'], $logCopy['secret_answer']);
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Inputs: " . json_encode($logCopy));

// ---------- STEP 2: REQUIRED FIELDS ----------
$required = ['firstname', 'lastname', 'email', 'phone', 'gender', 'address', 'password', 'secret_question', 'secret_answer', 'role'];
$missing  = array_values(array_filter($required, fn($f) => empty($inputs[$f])));

if (!empty($missing)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Missing fields: " . implode(', ', $missing));
    json_error("Please fill all input fields. Missing: " . implode(', ', $missing), 400);
}

// ---------- STEP 3: PHONE ----------
if (!validate_phone($inputs['phone'])) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid phone");
    json_error('Please input a valid Phone Number.', 400);
}

// ---------- STEP 4: EMAIL ----------
if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid email");
    json_error('Invalid email address.', 400);
}

// ---------- STEP 5: GENDER ----------
if (!in_array($inputs['gender'], ['Male', 'Female', 'Other'], true)) {
    json_error('Invalid gender value.', 400);
}

// ---------- STEP 6: ROLE (whitelist) ----------
$allowedRoles = ['Super Admin', 'Admin'];
if (!in_array($inputs['role'], $allowedRoles, true)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid role '{$inputs['role']}'");
    json_error('Invalid role.', 400);
}

// ---------- STEP 7: SECRET QUESTION (whitelist) ----------
$allowedQuestions = [
    "What was your first pet's name?",
    "What is your mother's maiden name?",
    "What city were you born in?",
    "What is your favorite book?",
    "What was your first car?",
];
if (!in_array($inputs['secret_question'], $allowedQuestions, true)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid secret question");
    json_error('Invalid secret question selected.', 400);
}

// ---------- STEP 8: PASSWORD STRENGTH ----------
$password     = $inputs['password'];
$pwdErrors    = [];

if (strlen($password) < 8)                            $pwdErrors[] = 'Minimum 8 characters';
if (!preg_match('/[A-Z]/', $password))                 $pwdErrors[] = 'At least one uppercase letter';
if (!preg_match('/[a-z]/', $password))                 $pwdErrors[] = 'At least one lowercase letter';
if (!preg_match('/\d/', $password))                    $pwdErrors[] = 'At least one number';
if (!preg_match('/[!@#$%^&*(),.?":{}|<>_]/', $password)) $pwdErrors[] = 'At least one special character';
if (preg_match('/\s/', $password))                     $pwdErrors[] = 'No spaces allowed';

if (!empty($pwdErrors)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Weak password: " . implode(', ', $pwdErrors));
    json_error('Password must be at least 8 characters with at least one uppercase letter, one lowercase letter, one number, and one special character. No spaces allowed.', 400);
}

// ---------- STEP 9: PHOTO UPLOAD PRESENCE + ERROR ----------
if (!isset($_FILES['staff_photo'])) {
    json_error('Please upload a profile photo.', 400);
}
$photo = $_FILES['staff_photo'];
if ($photo['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension',
    ];
    $msg = $errorMessages[$photo['error']] ?? 'Unknown upload error';
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: {$msg} (code {$photo['error']})");
    json_error('Upload error: ' . $msg, 400);
}

// ---------- STEP 10: PHOTO EXTENSION ----------
$img_name = basename($photo['name']);
$tmp_name = $photo['tmp_name'];
$img_size = $photo['size'];
$ext      = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

$allowedExt = ['jpeg', 'jpg', 'png'];
if (!in_array($ext, $allowedExt, true)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Bad ext '{$ext}'");
    json_error('Invalid image file. Allowed types: jpg, jpeg, png.', 400);
}

// ---------- STEP 11: REAL MIME CHECK ----------
if (!function_exists('finfo_open')) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: fileinfo unavailable", 'ERROR');
    json_error('Server misconfiguration — please contact support', 500);
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: finfo_open failed", 'ERROR');
    json_error('Server misconfiguration — please contact support', 500);
}
$actualMime = finfo_file($finfo, $tmp_name);

$extMimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
if ($actualMime !== $extMimeMap[$ext]) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: MIME mismatch ext={$ext}, actual={$actualMime}");
    json_error('File content does not match its extension.', 400);
}

// ---------- STEP 12: PHOTO SIZE (500 KB) ----------
if ($img_size > 500000) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Photo too large ({$img_size} bytes)");
    json_error('Sorry, your file is too large. Max 500KB allowed.', 400);
}

// ---------- STEP 13: DUPLICATE EMAIL / PHONE ----------
$check_stmt = $conn->prepare("SELECT unique_id, email, phone FROM admin_tbl WHERE email = ? OR phone = ? LIMIT 1");
if (!$check_stmt) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Prepare dup-check failed: " . $conn->error, 'ERROR');
    json_error('Server database error.', 500);
}
$check_stmt->bind_param('ss', $inputs['email'], $inputs['phone']);
$check_stmt->execute();
$res = $check_stmt->get_result();

if ($res && $row = $res->fetch_assoc()) {
    $err = [];
    if ($row['email'] === $inputs['email']) $err['email'] = 'Email already exists';
    if ($row['phone'] === $inputs['phone']) $err['phone'] = 'Phone already exists';
    $check_stmt->close();
    if (!empty($err)) {
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate detected: " . json_encode($err));
        json_error($err, 409);
    }
}
$check_stmt->close();

// ---------- STEP 14: UPLOAD DIRECTORY ----------
$upload_dir = __DIR__ . '/admin_photos/';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Cannot create upload dir", 'ERROR');
    json_error('Server error preparing upload directory.', 500);
}
if (!is_writable($upload_dir)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Upload dir not writable", 'ERROR');
    json_error('Server error preparing upload directory.', 500);
}

// ---------- STEP 15: HASH-BASED FILENAME ----------
$file_hash   = hash_file('sha256', $tmp_name);
$file_name   = $file_hash . '.' . $ext;
$upload_file = $upload_dir . $file_name;

// ---------- STEP 16: DUPLICATE PHOTO CHECK ----------
$photo_check_stmt = $conn->prepare("SELECT 1 FROM admin_tbl WHERE photo = ? LIMIT 1");
if ($photo_check_stmt) {
    $photo_check_stmt->bind_param('s', $file_name);
    $photo_check_stmt->execute();
    $photo_res = $photo_check_stmt->get_result();
    if ($photo_res && $photo_res->num_rows > 0) {
        $photo_check_stmt->close();
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Duplicate photo (DB) {$file_name}");
        json_error('This image has already been uploaded by another user.', 409);
    }
    $photo_check_stmt->close();
}

// If the file already exists on disk with the same hash, another admin
// uploaded it — reject rather than silently reuse (matches onboarding rule).
if (is_file($upload_file)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Duplicate photo (disk) {$file_name}");
    json_error('This image has already been uploaded by another user.', 409);
}

// ---------- STEP 17: MOVE UPLOADED FILE ----------
if (!move_uploaded_file($tmp_name, $upload_file)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: move_uploaded_file failed", 'ERROR');
    json_error('Failed to upload image.', 500);
}
$fileWasMoved = true;
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] File uploaded: {$file_name}");

// ---------- STEP 18: GENERATE ADMIN ID ----------
$ran_id = generateUniqueAdminId($conn);
if ($ran_id === null) {
    @unlink($upload_file);
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: ID generation failed", 'ERROR');
    json_error('Failed to generate unique ID. Please try again.', 500);
}

// ---------- STEP 19: HASH SENSITIVE DATA ----------
$encrypt_pass          = password_hash($inputs['password'], PASSWORD_ARGON2ID);
$encrypt_secret_answer = password_hash($inputs['secret_answer'], PASSWORD_ARGON2ID);

// ---------- STEP 20: INSERT (with transaction + errno 1062) ----------
$conn->begin_transaction();
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Transaction started");

try {
    $insert_sql = "INSERT INTO admin_tbl
        (unique_id, firstname, lastname, email, phone, address, gender,
         password, secret_question, secret_answer, photo, role, status,
         onboarded_by, last_updated_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $insert_stmt = $conn->prepare($insert_sql);
    if (!$insert_stmt) throw new Exception("Prepare failed: " . $conn->error);

    $status = '1';
    $insert_stmt->bind_param(
        'ssssssssssssssi',
        $ran_id,
        $inputs['firstname'],
        $inputs['lastname'],
        $inputs['email'],
        $inputs['phone'],
        $inputs['address'],
        $inputs['gender'],
        $encrypt_pass,
        $inputs['secret_question'],
        $encrypt_secret_answer,
        $file_name,
        $inputs['role'],
        $status,
        $loggedInUserId,
        $loggedInUserId
    );

    if (!$insert_stmt->execute()) {
        if ($insert_stmt->errno === 1062) {
            // UNIQUE race — someone claimed the email/phone between our check and insert
            $field = stripos($insert_stmt->error, 'email') !== false ? 'Email' : 'Phone';
            $insert_stmt->close();
            throw new Exception("{$field} was just claimed by another user. Please try again.", 409);
        }
        throw new Exception("Insert failed: " . $insert_stmt->error);
    }

    $insert_stmt->close();
    $conn->commit();
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    if (!empty($fileWasMoved) && is_file($upload_file)) {
        @unlink($upload_file);
    }
    throw $e;
}

// ---------- STEP 21: RESPONSE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ========== SUCCESS ==========");
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] New admin: ID={$ran_id}, Email={$inputs['email']}, Onboarded by {$loggedInUserEmail}");

json_success([
    'admin_id' => $ran_id,
    'name'     => $inputs['firstname'] . ' ' . $inputs['lastname'],
    'email'    => $inputs['email'],
    'role'     => $inputs['role'],
], 'New Staff has been successfully Onboarded!');