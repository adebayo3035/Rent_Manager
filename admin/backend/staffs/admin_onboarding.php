<?php
// onboard_admin.php
// Secure, refactored onboarding endpoint
// Requirements: config.php must provide $conn (mysqli) and auth_utils.php must provide logActivity().
define('CSRF_FORM_NAME', 'admin_onboarding_form');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();
logActivity('Onboard endpoint called');


// ---------- Authentication & Authorization ----------
if (!isset($_SESSION['unique_id'])) {
    logActivity('Access denied - not logged in');
    json_error('Not logged in', 401);
}

$loggedInUserRole = $_SESSION['role'] ?? null;
$logged_in_user = $_SESSION['unique_id'] ?? null;


if ($loggedInUserRole !== 'Super Admin') {
    logActivity("Access denied - insufficient role ({$loggedInUserRole}) for user {$logged_in_user}");
    json_error('Access Denied. Permission not granted!', 403);
}

// ---------- Accept & sanitize inputs ----------
$inputs = [
    'firstname' => $_POST['add_firstname'] ?? '',
    'lastname' => $_POST['add_lastname'] ?? '',
    'email' => $_POST['add_email'] ?? '',
    'phone' => $_POST['add_phone_number'] ?? '',
    'gender' => $_POST['add_gender'] ?? '',
    'address' => $_POST['add_address'] ?? '',
    'password' => $_POST['add_password'] ?? '',
    'secret_question' => $_POST['add_secret_question'] ?? '',
    'secret_answer' => $_POST['add_secret_answer'] ?? '',
    'role' => $_POST['add_role'] ?? ''
];

$inputs = sanitize_inputs($inputs);

// Log non-sensitive input summary
$logCopy = $inputs;
unset($logCopy['password'], $logCopy['secret_answer']);
logActivity('Inputs received: ' . json_encode($logCopy));

// ---------- Basic validations ----------
foreach ($logCopy as $k => $v) {
    if ($v === '') json_error('Please fill all input fields.');
}

if (!validate_phone($inputs['phone'])) json_error('Please input a valid Phone Number.');

if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) json_error('Invalid email address.');

$password = $inputs['password'];
$minLength = 8;
if (strlen($password) < $minLength
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[!@#$%^&*(),.?":{}|<>_]/', $password)
    || preg_match('/\s/', $password)
) {
    json_error('Password must be at least 8 characters with at least one uppercase letter, one number and one special character. No spaces allowed.');
}

// ---------- Validate file upload ----------
if (!isset($_FILES['photo'])) json_error('Please upload a profile photo.');

$photo = $_FILES['photo'];
if ($photo['error'] !== UPLOAD_ERR_OK) {
    logActivity('File upload error: ' . $photo['error']);
    json_error('Upload error.');
}

$allowed_ext = ['jpeg','jpg','png'];
$allowed_types = ['image/jpeg','image/jpg','image/png'];

$img_name = basename($photo['name']);
$tmp_name = $photo['tmp_name'];
$img_size = $photo['size'];
$img_type = $photo['type'];
$ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_ext) || !in_array($img_type, $allowed_types)) {
    json_error('Invalid image file. Allowed types: jpg, jpeg, png.');
}

if ($img_size > 500000) json_error('Sorry, your file is too large. Max 500KB allowed.');

// Use file content hash to detect duplicate images reliably
$file_hash = hash_file('sha256', $tmp_name);

$upload_dir = __DIR__ . '/admin_photos/';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
    logActivity('Failed to ensure upload dir exists: ' . $upload_dir);
    json_error('Server error preparing upload directory.', 500);
}

$file_name = $file_hash . '.' . $ext;
$upload_file = $upload_dir . $file_name;

// ---------- Check duplicates in DB (email/phone) and photo duplicates ----------
$check_sql = "SELECT unique_id, email, phone, photo FROM admin_tbl WHERE email = ? OR phone = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    logActivity('Prepare failed: ' . $conn->error);
    json_error('Server database error.', 500);
}
$check_stmt->bind_param('ss', $inputs['email'], $inputs['phone']);
$check_stmt->execute();
$res = $check_stmt->get_result();
if ($res && $row = $res->fetch_assoc()) {
    $err = [];
    if ($row['email'] === $inputs['email']) $err['email'] = 'Email already exists';
    if ($row['phone'] === $inputs['phone']) $err['phone'] = 'Phone already exists';
    if (!empty($err)) json_error($err, 409);
}
$check_stmt->close();

// Check if same photo was already uploaded by someone (by filename/hash recorded earlier)
$photo_check_sql = "SELECT 1 FROM admin_tbl WHERE photo = ? LIMIT 1";
$photo_check_stmt = $conn->prepare($photo_check_sql);
if (!$photo_check_stmt) json_error('Server database error.', 500);
$photo_check_stmt->bind_param('s', $file_name);
$photo_check_stmt->execute();
$photo_res = $photo_check_stmt->get_result();
if ($photo_res && $photo_res->num_rows > 0) {
    $photo_check_stmt->close();
    json_error('This image has already been uploaded by another user.');
}
$photo_check_stmt->close();

// If file already exists on disk, treat as duplicate (race safe)
if (is_file($upload_file)) {
    json_error('This image has already been uploaded by another user.');
}

// Move uploaded file (use move_uploaded_file)
if (!move_uploaded_file($tmp_name, $upload_file)) {
    logActivity('Failed to move uploaded file to ' . $upload_file);
    json_error('Failed to upload image.', 500);
}
logActivity('File uploaded to ' . $upload_file);

// ---------- Insert user in DB (transactional) ----------
$ran_id = random_unique_id();
$encrypt_pass = password_hash($inputs['password'], PASSWORD_ARGON2ID);
$encrypt_secret_answer = password_hash($inputs['secret_answer'], PASSWORD_ARGON2ID);
$status = 'Active now';

$insert_sql = "INSERT INTO admin_tbl
    (unique_id, firstname, lastname, email, phone, address, gender, password, secret_question, secret_answer, photo, role, onboarded_by, last_updated_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$conn->begin_transaction();
$insert_stmt = $conn->prepare($insert_sql);
if (!$insert_stmt) {
    logActivity('Prepare failed (insert): ' . $conn->error);
    // cleanup uploaded file
    @unlink($upload_file);
    json_error('Server database error.', 500);
}

// ensure onboarded_by is integer
$onboarded_by = is_numeric($logged_in_user) ? (int)$logged_in_user : $logged_in_user;

$insert_stmt->bind_param(
    'ssssssssssssss',
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
    $onboarded_by,
    $onboarded_by
);

$ok = $insert_stmt->execute();
if (!$ok) {
    logActivity('DB insert failed: ' . $insert_stmt->error);
    $conn->rollback();
    @unlink($upload_file);
    json_error('Failed to add new Staff. Database error.', 500);
}

$conn->commit();
$insert_stmt->close();

json_success('New Staff has been successfully Onboarded!');

// ----------------------------------
// end of script

