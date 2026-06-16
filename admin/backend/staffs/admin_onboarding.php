<?php
// onboard_admin.php
// Secure, refactored onboarding endpoint with detailed logging

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestTraceId = uniqid('onboard_admin_', true);
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ========== START ==========");
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Request Method: " . $_SERVER['REQUEST_METHOD']);

// ---------- STEP 1: AUTHENTICATION ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 1: Checking authentication");

if (!isset($_SESSION['unique_id'])) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Access denied - Not logged in");
    json_error('Not logged in', 401);
}

$loggedInUserRole = $_SESSION['role'] ?? null;
$loggedInUserId = $_SESSION['unique_id'] ?? null;
$loggedInUserEmail = $_SESSION['email'] ?? 'unknown';

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Logged in user: ID={$loggedInUserId}, Role={$loggedInUserRole}, Email={$loggedInUserEmail}");

// ---------- STEP 2: AUTHORIZATION ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 2: Checking authorization");

if ($loggedInUserRole !== 'Super Admin') {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Access denied - Insufficient role ({$loggedInUserRole})");
    json_error('Access Denied. Permission not granted!', 403);
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Authorization passed - User is Super Admin");

// ---------- STEP 3: VALIDATE REQUEST METHOD ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 3: Validating request method");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid method: " . $_SERVER['REQUEST_METHOD']);
    json_error('Method not allowed. Use POST.', 405);
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Request method validated: POST");

// ---------- STEP 4: ACCEPT AND SANITIZE INPUTS ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 4: Accepting and sanitizing inputs");

$inputs = [
    'firstname' => $_POST['staff_firstname'] ?? '',
    'lastname' => $_POST['staff_lastname'] ?? '',
    'email' => $_POST['staff_email'] ?? '',
    'phone' => $_POST['staff_phone_number'] ?? '',
    'gender' => $_POST['staff_gender'] ?? '',
    'address' => $_POST['staff_address'] ?? '',
    'password' => $_POST['staff_password'] ?? '',
    'secret_question' => $_POST['staff_secret_question'] ?? '',
    'secret_answer' => $_POST['staff_secret_answer'] ?? '',
    'role' => $_POST['staff_role'] ?? ''
];

$inputs = sanitize_inputs($inputs);
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Inputs sanitized");

// Log non-sensitive input summary
$logCopy = $inputs;
unset($logCopy['password'], $logCopy['secret_answer']);
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Inputs received: " . json_encode($logCopy));

// ---------- STEP 5: VALIDATE REQUIRED FIELDS ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 5: Validating required fields");

$required_fields = ['firstname', 'lastname', 'email', 'phone', 'gender', 'address', 'password', 'secret_question', 'secret_answer', 'role'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (empty($inputs[$field])) {
        $missing_fields[] = $field;
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Missing field: {$field}");
    }
}

if (!empty($missing_fields)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Missing required fields: " . implode(', ', $missing_fields));
    json_error("Please fill all input fields. Missing: " . implode(', ', $missing_fields));
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] All required fields present");

// ---------- STEP 6: VALIDATE PHONE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 6: Validating phone number");

if (!validate_phone($inputs['phone'])) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid phone number: " . $inputs['phone']);
    json_error('Please input a valid Phone Number.');
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Phone number validated: " . $inputs['phone']);

// ---------- STEP 7: VALIDATE EMAIL ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 7: Validating email");

if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid email: " . $inputs['email']);
    json_error('Invalid email address.');
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Email validated: " . $inputs['email']);

// ---------- STEP 8: VALIDATE PASSWORD STRENGTH ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 8: Validating password strength");

$password = $inputs['password'];
$minLength = 8;
$passwordErrors = [];

if (strlen($password) < $minLength) {
    $passwordErrors[] = "Minimum length {$minLength} characters";
}
if (!preg_match('/[A-Z]/', $password)) {
    $passwordErrors[] = "At least one uppercase letter";
}
if (!preg_match('/\d/', $password)) {
    $passwordErrors[] = "At least one number";
}
if (!preg_match('/[!@#$%^&*(),.?":{}|<>_]/', $password)) {
    $passwordErrors[] = "At least one special character";
}
if (preg_match('/\s/', $password)) {
    $passwordErrors[] = "No spaces allowed";
}

if (!empty($passwordErrors)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Password validation failed: " . implode(', ', $passwordErrors));
    json_error('Password must be at least 8 characters with at least one uppercase letter, one number and one special character. No spaces allowed.');
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Password strength validation passed");

// ---------- STEP 9: VALIDATE FILE UPLOAD ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 9: Validating file upload");

if (!isset($_FILES['staff_photo'])) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: No photo uploaded");
    json_error('Please upload a profile photo.');
}

$photo = $_FILES['staff_photo'];
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Photo upload - Name: {$photo['name']}, Size: {$photo['size']}, Error: {$photo['error']}");

if ($photo['error'] !== UPLOAD_ERR_OK) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: File upload error: " . $photo['error']);
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];
    json_error('Upload error: ' . ($errorMessages[$photo['error']] ?? 'Unknown error'));
}

// ---------- STEP 10: VALIDATE IMAGE TYPE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 10: Validating image type");

$allowed_ext = ['jpeg', 'jpg', 'png'];
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];

$img_name = basename($photo['name']);
$tmp_name = $photo['tmp_name'];
$img_size = $photo['size'];
$img_type = $photo['type'];
$ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Image details - Name: {$img_name}, Type: {$img_type}, Ext: {$ext}, Size: {$img_size}");

if (!in_array($ext, $allowed_ext) || !in_array($img_type, $allowed_types)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Invalid image type - Ext: {$ext}, Type: {$img_type}");
    json_error('Invalid image file. Allowed types: jpg, jpeg, png.');
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Image type validation passed");

// ---------- STEP 11: VALIDATE IMAGE SIZE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 11: Validating image size");

$maxSize = 500000; // 500KB
if ($img_size > $maxSize) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Image too large - {$img_size} bytes (max: {$maxSize})");
    json_error("Sorry, your file is too large. Max 500KB allowed.");
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Image size validation passed");

// ---------- STEP 12: CHECK DUPLICATES (EMAIL/PHONE) ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 12: Checking for duplicate email/phone");

$check_sql = "SELECT unique_id, email, phone, photo FROM admin_tbl WHERE email = ? OR phone = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Prepare failed: " . $conn->error);
    json_error('Server database error.', 500);
}

$check_stmt->bind_param('ss', $inputs['email'], $inputs['phone']);
$check_stmt->execute();
$res = $check_stmt->get_result();

if ($res && $row = $res->fetch_assoc()) {
    $err = [];
    if ($row['email'] === $inputs['email']) {
        $err['email'] = 'Email already exists';
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate email: {$inputs['email']}");
    }
    if ($row['phone'] === $inputs['phone']) {
        $err['phone'] = 'Phone already exists';
        logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Duplicate phone: {$inputs['phone']}");
    }
    if (!empty($err)) {
        $check_stmt->close();
        json_error($err, 409);
    }
}
$check_stmt->close();

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] No duplicate email/phone found");

// ---------- STEP 13: SETUP UPLOAD DIRECTORY ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 13: Setting up upload directory");

$upload_dir = __DIR__ . '/admin_photos/';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Failed to create upload directory: {$upload_dir}");
    json_error('Server error preparing upload directory.', 500);
}
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Upload directory ready: {$upload_dir}");

// ---------- STEP 14: GENERATE UNIQUE FILE NAME ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 14: Generating unique file name");

$file_hash = hash_file('sha256', $tmp_name);
$file_name = $file_hash . '.' . $ext;
$upload_file = $upload_dir . $file_name;

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] File hash: {$file_hash}, File name: {$file_name}");

// ---------- STEP 15: CHECK DUPLICATE PHOTO IN DB ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 15: Checking for duplicate photo");

$photo_check_sql = "SELECT 1 FROM admin_tbl WHERE photo = ? LIMIT 1";
$photo_check_stmt = $conn->prepare($photo_check_sql);
if (!$photo_check_stmt) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Prepare failed for photo check: " . $conn->error);
    json_error('Server database error.', 500);
}

$photo_check_stmt->bind_param('s', $file_name);
$photo_check_stmt->execute();
$photo_res = $photo_check_stmt->get_result();

if ($photo_res && $photo_res->num_rows > 0) {
    $photo_check_stmt->close();
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Duplicate photo found: {$file_name}");
    json_error('This image has already been uploaded by another user.');
}
$photo_check_stmt->close();

if (is_file($upload_file)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Duplicate file on disk: {$upload_file}");
    json_error('This image has already been uploaded by another user.');
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] No duplicate photo found");

// ---------- STEP 16: MOVE UPLOADED FILE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 16: Moving uploaded file");

if (!move_uploaded_file($tmp_name, $upload_file)) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Failed to move uploaded file to {$upload_file}");
    json_error('Failed to upload image.', 500);
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] File uploaded successfully to: {$upload_file}");

// ---------- STEP 17: GENERATE UNIQUE ADMIN ID ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 17: Generating unique admin ID");

$ran_id = generateUniqueAdminId($conn);
if ($ran_id === null) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Failed to generate unique admin ID");
    @unlink($upload_file);
    json_error('Failed to generate unique ID. Please try again.', 500);
}

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Unique admin ID generated: {$ran_id}");

// ---------- STEP 18: HASH PASSWORD AND SECRET ANSWER ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 18: Hashing sensitive data");

$encrypt_pass = password_hash($inputs['password'], PASSWORD_ARGON2ID);
$encrypt_secret_answer = password_hash($inputs['secret_answer'], PASSWORD_ARGON2ID);

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Password and secret answer hashed");

// ---------- STEP 19: INSERT USER INTO DATABASE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Step 19: Inserting admin into database");

$onboarded_by = (int)$loggedInUserId;
$status = '1'; // Active

$insert_sql = "INSERT INTO admin_tbl
    (unique_id, firstname, lastname, email, phone, address, gender, password, secret_question, secret_answer, photo, role, status, onboarded_by, last_updated_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
";

$conn->begin_transaction();
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Transaction started");

$insert_stmt = $conn->prepare($insert_sql);
if (!$insert_stmt) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Prepare failed for insert: " . $conn->error);
    @unlink($upload_file);
    json_error('Server database error.', 500);
}

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
    $onboarded_by,
    $onboarded_by
);

if (!$insert_stmt->execute()) {
    logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ERROR: Insert failed: " . $insert_stmt->error);
    $conn->rollback();
    @unlink($upload_file);
    json_error('Failed to add new Staff. Database error.', 500);
}

$affected_rows = $insert_stmt->affected_rows;
$insert_stmt->close();

logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Insert successful. Affected rows: {$affected_rows}");

// ---------- STEP 20: COMMIT TRANSACTION ----------
$conn->commit();
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Transaction committed");

// ---------- STEP 21: LOG ACTIVITY ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Admin onboarded successfully");
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] New Admin - ID: {$ran_id}, Name: {$inputs['firstname']} {$inputs['lastname']}, Email: {$inputs['email']}");
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] Onboarded by: {$loggedInUserEmail} (ID: {$loggedInUserId})");

// ---------- STEP 22: RETURN SUCCESS RESPONSE ----------
logActivity("[ONBOARD_ADMIN] [ID:{$requestTraceId}] ========== SUCCESS ==========");

json_success([
    'admin_id' => $ran_id,
    'name' => $inputs['firstname'] . ' ' . $inputs['lastname'],
    'email' => $inputs['email'],
    'role' => $inputs['role']
], 'New Staff has been successfully Onboarded!');

// ----------------------------------
// end of script
?>