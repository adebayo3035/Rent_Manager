<?php
// onboard_admin.php — Optimized with Transactions + Try/Catch
// Purpose: Onboard a new property (upload photo, insert DB record) with full logging and validation

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// ------------------------- SESSION -------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    logActivity("Session started for new property onboarding.");
}

rateLimit("add_property", 10, 60);
logActivity("Onboarding request initiated | IP: " . getClientIP());

// ------------------------- METHOD CHECK -------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logActivity("Invalid request method attempted: " . $_SERVER['REQUEST_METHOD']);
    json_error('Invalid request method. POST required.', 405);
}

// ------------------------- AUTH CHECK -------------------------
if (!isset($_SESSION['unique_id'])) {
    logActivity("Onboarding failed — no active user session.");
    json_error('Not logged in', 401);
}

$logged_in_user = $_SESSION['unique_id'];
$logged_in_role = $_SESSION['role'] ?? 'UNKNOWN';

logActivity("Authenticated user: {$logged_in_user} | Role: {$logged_in_role}");

// --------------------------- TRY / CATCH WRAPPER ---------------------------
try {

    // ------------------------- INPUT COLLECTION -------------------------
    $inputs = sanitize_inputs([
        'agent_code' => $_POST['property_agent_code'] ?? '',
        'property_type'  => $_POST['property_type'] ?? '',
        'property_name'     => $_POST['property_name'] ?? '',
        'property_address'     => $_POST['property_address'] ?? '',
        'property_city'   => $_POST['property_city'] ?? '',
        'property_state'    => $_POST['property_state'] ?? '',
        'property_country'    => $_POST['property_country'] ?? '',
        'property_contact_name'    => $_POST['property_contact_name'] ?? '',
        'property_contact_phone'    => $_POST['property_contact_phone'] ?? '',
        'property_note'    => $_POST['property_note'] ?? '',
    ]);

    logActivity("Sanitized inputs: " . json_encode($inputs));

    // ------------------------- VALIDATIONS -------------------------
    // Required fields
    $required = [
        'agent_code',
        'property_type',
        'property_name',
        'property_address',
        'property_city',
        'property_state',
        'property_country',
        'property_contact_name',
        'property_contact_phone'
    ];

    foreach ($required as $key) {
        if (!isset($inputs[$key]) || trim($inputs[$key]) === '') {
            logActivity("Validation failed — missing field: {$key}");
            json_error("Missing required field: {$key}", 400);
        }
    }

    // Validate phone
    if (!validate_phone($inputs['property_contact_phone'])) {
        logActivity("Invalid phone number entered: {$inputs['property_contact_phone']}");
        json_error('Invalid phone number. Must be 11 digits.', 400);
    }

    logActivity("Core input validation passed.");

    // ------------------------- FILE VALIDATION -------------------------
    if (!isset($_FILES['property_photo'])) {
        logActivity("Photo missing from request.");
        json_error('Please upload a Photo of the Property.', 400);
    }

    $photo = $_FILES['property_photo'];

    if (!is_array($photo) || $photo['error'] !== UPLOAD_ERR_OK) {
        logActivity("Upload error: " . ($photo['error'] ?? 'no-file'));
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

    if (!in_array($ext, $allowed_ext, true) || !in_array($img_type, $allowed_mime, true)) {
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

    $upload_dir  = __DIR__ . '/property_photos/';
    $upload_path = $upload_dir . $file_name;

    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            logActivity("Failed to create upload directory: {$upload_dir}");
            json_error("Server error preparing upload directory", 500);
        }
        logActivity("Created missing upload directory: {$upload_dir}");
    }

    // ------------------------- BEGIN TRANSACTION -------------------------
    if (!isset($conn) || $conn->connect_errno) {
        logActivity("Database connection error: " . ($conn->connect_error ?? 'unknown'));
        json_error("Database connection error", 500);
    }

    $conn->begin_transaction();
    logActivity("Database transaction started.");

    // ------------------------- MOVE FILE -------------------------
    if (!move_uploaded_file($img_tmp, $upload_path)) {
        logActivity("Failed to move file to {$upload_path}");
        throw new Exception("Failed to save uploaded image.");
    }

    logActivity("Image moved successfully: {$upload_path}");

    // ------------------------- DB INSERT -------------------------
    // property_code generation
    $property_code = "PROPERTY" . random_unique_id();
    logActivity("Generated unique property code: {$property_code}");

    // Ensure property_type id is integer
    $property_type_id = (int) $inputs['property_type'];

    $insert_sql = "
        INSERT INTO properties
        (agent_code, property_code, property_type_id, name, address, city, state, country, contact_name, contact_phone, photo, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        logActivity("DB prepare failed: " . $conn->error);
        throw new Exception("Database prepare error.");
    }

    // bind_param types:
    // agent_code (s), property_code (s), property_type_id (i),
    // name (s), address (s), city (s), state (s), country (s),
    // contact_name (s), contact_phone (s), photo (s), notes (s), created_by (i)
    $bindTypes = "ssisssssssssi";

    $bindAgentCode   = $inputs['agent_code'];
    $bindPropertyCode = $property_code;
    $bindTypeId      = $property_type_id;
    $bindName        = $inputs['property_name'];
    $bindAddress     = $inputs['property_address'];
    $bindCity        = $inputs['property_city'];
    $bindState       = $inputs['property_state'];
    $bindCountry     = $inputs['property_country'];
    $bindContactName = $inputs['property_contact_name'];
    $bindContactPhone= $inputs['property_contact_phone'];
    $bindPhoto       = $file_name;
    $bindNotes       = $inputs['property_note'] ?? null;
    $bindCreatedBy   = is_numeric($logged_in_user) ? (int)$logged_in_user : $logged_in_user;

    $ok = $stmt->bind_param(
        $bindTypes,
        $bindAgentCode,
        $bindPropertyCode,
        $bindTypeId,
        $bindName,
        $bindAddress,
        $bindCity,
        $bindState,
        $bindCountry,
        $bindContactName,
        $bindContactPhone,
        $bindPhoto,
        $bindNotes,
        $bindCreatedBy
    );

    if (!$ok) {
        logActivity("bind_param failed: " . $stmt->error);
        throw new Exception("Statement binding failed.");
    }

    if (!$stmt->execute()) {
        logActivity("DB execute failed: " . $stmt->error);
        throw new Exception("Failed to insert property.");
    }

    $stmt->close();
    logActivity("Property inserted successfully: {$property_code}");

    // ------------------------- COMMIT -------------------------
    $conn->commit();
    logActivity("Transaction committed successfully.");

    // ------------------------- SUCCESS RESPONSE -------------------------
    json_success("New property onboarded successfully!", [
        "property_code" => $property_code
    ], 201);

} catch (Throwable $e) {

    // ------------------------- ROLLBACK ON FAILURE -------------------------
    if (isset($conn) && $conn->connect_errno == 0) {
        // attempt rollback if a transaction is active
        try {
            $conn->rollback();
            logActivity("Transaction rolled back due to error.");
        } catch (Throwable $rbEx) {
            logActivity("Rollback failed: " . $rbEx->getMessage());
        }
    }

    // Delete uploaded file if it was moved before the error
    if (isset($upload_path) && file_exists($upload_path)) {
        @unlink($upload_path);
        logActivity("Rollback cleanup: Removed uploaded image {$upload_path}");
    }

    logActivity("Exception caught: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    json_error("Onboarding failed: " . $e->getMessage(), 500);
}
