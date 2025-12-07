<?php
// onboard_admin.php (Refined + Detailed Logs)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// ------------------------- SESSION -------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    logActivity("Session started for onboarding request.");
}
rateLimit("agent_onboarding", 10, 60);

logActivity("New onboarding request received | IP: " . getClientIP());
logActivity("Onboard endpoint hit. Session user: " . json_encode($_SESSION));

// ------------------------- METHOD CHECK -------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("Invalid request method: {$_SERVER['REQUEST_METHOD']}");
    json_error('Invalid request method. POST required.', 405);
}

// ------------------------- AUTH CHECK -------------------------
if (!isset($_SESSION['unique_id'])) {
    logActivity("Authorization failed — no session user.");
    json_error('Not logged in', 401);
}

$logged_in_user = $_SESSION['unique_id'];
$logged_in_role = $_SESSION['role'] ?? 'UNKNOWN';

logActivity("Authenticated user: {$logged_in_user} | Role: {$logged_in_role}");


// ------------------------- INPUT COLLECTION -------------------------
$inputs = sanitize_inputs([
    'firstname' => $_POST['agent_firstname'] ?? '',
    'lastname'  => $_POST['agent_lastname'] ?? '',
    'email'     => $_POST['agent_email'] ?? '',
    'phone'     => $_POST['agent_phone_number'] ?? '',
    'address'   => $_POST['agent_address'] ?? '',
    'gender'    => $_POST['agent_gender'] ?? '',
]);

logActivity("Raw sanitized inputs received: " . json_encode($inputs));


// ------------------------- VALIDATIONS -------------------------
foreach ($inputs as $key => $value) {
    if ($value === '') {
        logActivity("Validation failed — missing field: {$key}");
        json_error("Missing required field: {$key}", 400);
    }
}

if (!validate_phone($inputs['phone'])) {
    logActivity("Invalid phone number: {$inputs['phone']}");
    json_error('Invalid phone number. Must be 11 digits.', 400);
}

if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
    logActivity("Invalid email format: {$inputs['email']}");
    json_error('Invalid email address.', 400);
}

logActivity("Basic validation passed.");


// ------------------------- FILE UPLOAD VALIDATION -------------------------
if (!isset($_FILES['agent_photo'])) {
    logActivity("File upload missing.");
    json_error('Please upload a profile photo.', 400);
}

$photo = $_FILES['agent_photo'];

if ($photo['error'] !== UPLOAD_ERR_OK) {
    logActivity("File upload error. Code: {$photo['error']}");
    json_error('File upload error.', 400);
}

$img_tmp  = $photo['tmp_name'];
$img_name = $photo['name'];
$img_size = $photo['size'];
$img_type = mime_content_type($img_tmp);

logActivity("Uploaded file details: name={$img_name}, type={$img_type}, size={$img_size}");

$allowed_ext = ['jpg','jpeg','png'];
$allowed_mime = ['image/jpeg','image/png'];

$ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_ext) || !in_array($img_type, $allowed_mime)) {
    logActivity("Invalid image type upload attempt: {$img_type} ({$ext})");
    json_error('Only JPG, JPEG & PNG images are allowed.', 400);
}

if ($img_size > 500000) {
    logActivity("File too large: {$img_size}");
    json_error('Image too large. Max allowed 500KB.', 400);
}

$file_hash = hash_file('sha256', $img_tmp);
$file_name = $file_hash . '.' . $ext;

logActivity("Image hash generated: {$file_hash}");

$upload_dir = __DIR__ . '/agent_photos/';
$upload_path = $upload_dir . $file_name;

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
    logActivity("Upload directory created: {$upload_dir}");
}


// ------------------------- DUPLICATE CHECKS -------------------------
logActivity("Checking for duplicate email/phone/photo in DB.");

$duplicate_sql = "SELECT email, phone, photo FROM agents WHERE email = ? OR phone = ? OR photo = ? LIMIT 1";
$stmt = $conn->prepare($duplicate_sql);
$stmt->bind_param('sss', $inputs['email'], $inputs['phone'], $file_name);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    $existing = $res->fetch_assoc();
    logActivity("Duplicate found: " . json_encode($existing));
    json_error('Duplicate detected: Email, Phone or Image already exists.', 409);
}

$stmt->close();
logActivity("No duplicates found.");


// ------------------------- MOVE FILE -------------------------
if (!move_uploaded_file($img_tmp, $upload_path)) {
    logActivity("Failed to move uploaded image to {$upload_path}");
    json_error('Failed to save uploaded image.', 500);
}

logActivity("Image successfully moved to {$upload_path}");


// ------------------------- INSERT INTO DB -------------------------
$agent_code = random_unique_id();

logActivity("Generated agent code: {$agent_code}");

$sql = "INSERT INTO agents
        (agent_code, firstname, lastname, email, phone, address, photo, gender, onboarded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    logActivity("DB prepare failed: " . $conn->error);
    json_error('Database error (prepare).', 500);
}

logActivity("Prepared statement created successfully.");

$stmt->bind_param(
    'ssssssssi',
    $agent_code,
    $inputs['firstname'],
    $inputs['lastname'],
    $inputs['email'],
    $inputs['phone'],
    $inputs['address'],
    $file_name,
    $inputs['gender'],
    $logged_in_user
);

logActivity("Executing DB insert for agent: {$agent_code}");

if (!$stmt->execute()) {
    logActivity("DB insert failed: " . $stmt->error);
    json_error('Failed to onboard new Agent.', 500);
}

$stmt->close();

logActivity("Agent successfully inserted into DB: {$agent_code}");


// ------------------------- RESPONSE -------------------------
json_success('New Agent successfully onboarded!', [
    'agent_code' => $agent_code
]);
