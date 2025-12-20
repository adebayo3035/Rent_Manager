<?php
// onboard_admin.php — Optimized with Transactions + Try/Catch

header('Content-Type: application/json; charset=utf-8');

// define('CSRF_FORM_NAME', 'add_agent_form');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';   // added for centralized auth using requireAuth function

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'agent_onboarding',
    'rate_limit' => [10, 60],
    'csrf' => [
        'enabled' => true,
        'form_name' => 'add_agent_form'
    ],
    'roles' => ['Super Admin', 'Admin']
]);

$userId = $auth['user_id'];
$userRole = $auth['role'];

logActivity("Authenticated user: {$userId} | Role: {$userRole}");


// --------------------------- TRY / CATCH WRAPPER ---------------------------
try {

    // ------------------------- INPUT COLLECTION -------------------------
    $inputs = sanitize_inputs([
        'firstname' => $_POST['agent_firstname'] ?? '',
        'lastname'  => $_POST['agent_lastname'] ?? '',
        'email'     => $_POST['agent_email'] ?? '',
        'phone'     => $_POST['agent_phone_number'] ?? '',
        'address'   => $_POST['agent_address'] ?? '',
        'gender'    => $_POST['agent_gender'] ?? '',
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
    if (!isset($_FILES['agent_photo'])) {
        logActivity("Photo missing from request.");
        json_error('Please upload a profile photo.', 400);
    }

    $photo = $_FILES['agent_photo'];

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

    $file_hash  = hash_file('sha256', $img_tmp);
    $file_name  = $file_hash . '.' . $ext;

    logActivity("Image hashed to unique name: {$file_name}");

    $upload_dir  = __DIR__ . '/agent_photos/';
    $upload_path = $upload_dir . $file_name;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
        logActivity("Created missing upload directory: {$upload_dir}");
    }

    // ------------------------- DUPLICATE CHECK -------------------------
    logActivity("Checking duplicates: email={$inputs['email']}, phone={$inputs['phone']}");

    $dup_sql = "SELECT email, phone, photo FROM agents WHERE email = ? OR phone = ? OR photo = ? LIMIT 1";
    $stmt = $conn->prepare($dup_sql);
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
    $agent_code = "AGENT" . random_unique_id();
    logActivity("Generated unique agent code: {$agent_code}");

    $insert_sql = "
        INSERT INTO agents
        (agent_code, firstname, lastname, email, phone, address, photo, gender, onboarded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        logActivity("DB prepare failed: " . $conn->error);
        throw new Exception("Database prepare error.");
    }

    $stmt->bind_param(
        "ssssssssi",
        $agent_code,
        $inputs['firstname'],
        $inputs['lastname'],
        $inputs['email'],
        $inputs['phone'],
        $inputs['address'],
        $file_name,
        $inputs['gender'],
        $userId
    );

    if (!$stmt->execute()) {
        logActivity("DB execute failed: " . $stmt->error);
        throw new Exception("Failed to insert agent.");
    }

    $stmt->close();
    logActivity("Agent inserted successfully: {$agent_code}");

    // ------------------------- COMMIT -------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");

    // ------------------------- SUCCESS RESPONSE -------------------------
    json_success("New Agent onboarded successfully!", [
        "agent_code" => $agent_code
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

