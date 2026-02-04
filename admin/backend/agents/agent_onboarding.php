<?php
// onboard_admin.php — Optimized with Transactions + Try/Catch

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notifications.php';

// Generate unique request ID for tracing
$requestId = uniqid('onboard_', true);
logActivity("[AGENT_ONBOARDING_START] [ID:{$requestId}] Agent onboarding request started");

// Enhanced logging function for this script
function logOnboarding($message) {
    global $requestId;
    logActivity("[AGENT_ONBOARDING] [ID:{$requestId}] " . $message);
}

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

logOnboarding("Authenticated user: {$userId} | Role: {$userRole}");

// Track transaction state
$transactionStarted = false;
$fileMoved = false;
$uploadPath = null;

// --------------------------- TRY / CATCH WRAPPER ---------------------------
try {

    // ------------------------- INPUT COLLECTION -------------------------
    logOnboarding("Collecting form inputs...");
    
    $inputs = sanitize_inputs([
        'firstname' => $_POST['agent_firstname'] ?? '',
        'lastname'  => $_POST['agent_lastname'] ?? '',
        'email'     => $_POST['agent_email'] ?? '',
        'phone'     => $_POST['agent_phone_number'] ?? '',
        'address'   => $_POST['agent_address'] ?? '',
        'gender'    => $_POST['agent_gender'] ?? '',
    ]);

    logOnboarding("Sanitized inputs: " . json_encode($inputs));

    // ------------------------- VALIDATIONS -------------------------
    logOnboarding("Starting input validation...");
    
    foreach ($inputs as $key => $value) {
        if ($value === '') {
            logOnboarding("Validation failed — missing field: {$key}");
            json_error("Missing required field: {$key}", 400);
        }
    }

    if (!validate_phone($inputs['phone'])) {
        logOnboarding("Invalid phone number entered: {$inputs['phone']}");
        json_error('Invalid phone number. Must be 11 digits.', 400);
    }

    if (!filter_var($inputs['email'], FILTER_VALIDATE_EMAIL)) {
        logOnboarding("Invalid email address: {$inputs['email']}");
        json_error('Invalid email address.', 400);
    }

    logOnboarding("Core input validation passed.");

    // ------------------------- FILE VALIDATION -------------------------
    logOnboarding("Starting file validation...");
    
    if (!isset($_FILES['agent_photo'])) {
        logOnboarding("Photo missing from request.");
        json_error('Please upload a profile photo.', 400);
    }

    $photo = $_FILES['agent_photo'];

    if ($photo['error'] !== UPLOAD_ERR_OK) {
        logOnboarding("Upload error code: " . $photo['error']);
        json_error('Photo upload failed.', 400);
    }

    $img_tmp  = $photo['tmp_name'];
    $img_name = $photo['name'];
    $img_size = $photo['size'];
    $img_type = mime_content_type($img_tmp);

    logOnboarding("Uploaded image: name={$img_name}, size={$img_size} bytes, type={$img_type}, tmp={$img_tmp}");

    $allowed_ext  = ['jpg', 'jpeg', 'png'];
    $allowed_mime = ['image/jpeg', 'image/png'];

    $ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext) || !in_array($img_type, $allowed_mime)) {
        logOnboarding("Rejected invalid image: EXT={$ext}, MIME={$img_type}");
        json_error('Only JPG, JPEG & PNG images allowed.', 400);
    }

    if ($img_size > 500000) {
        logOnboarding("Image too large: {$img_size} bytes (max 500KB)");
        json_error('Image too large. Max allowed size is 500KB.', 400);
    }

    $file_hash  = hash_file('sha256', $img_tmp);
    $file_name  = $file_hash . '.' . $ext;

    logOnboarding("Image hashed: {$file_name} (hash: {$file_hash})");

    $upload_dir  = __DIR__ . '/agent_photos/';
    $upload_path = $upload_dir . $file_name;

    logOnboarding("Upload path: {$upload_path}");
    logOnboarding("Directory exists: " . (is_dir($upload_dir) ? 'Yes' : 'No'));

    if (!is_dir($upload_dir)) {
        logOnboarding("Creating directory: {$upload_dir}");
        mkdir($upload_dir, 0755, true);
        logOnboarding("Directory created: " . (is_dir($upload_dir) ? 'Yes' : 'No'));
    }

    // ------------------------- DUPLICATE CHECK -------------------------
    logOnboarding("Checking for duplicates...");
    
    $dup_sql = "SELECT email, phone, photo FROM agents WHERE email = ? OR phone = ? OR photo = ? LIMIT 1";
    $stmt = $conn->prepare($dup_sql);
    if (!$stmt) {
        logOnboarding("Duplicate check prepare failed: " . $conn->error);
        throw new Exception("Database error during duplicate check.");
    }
    
    $stmt->bind_param("sss", $inputs['email'], $inputs['phone'], $file_name);
    if (!$stmt->execute()) {
        logOnboarding("Duplicate check execute failed: " . $stmt->error);
        $stmt->close();
        throw new Exception("Database error during duplicate check.");
    }
    
    $dup_res = $stmt->get_result();
    $stmt->close();

    if ($dup_res->num_rows > 0) {
        $existing = $dup_res->fetch_assoc();
        logOnboarding("Duplicate detected: " . json_encode($existing));
        json_error("Duplicate detected: Email, Phone or Photo already exists.", 409);
    }

    logOnboarding("No duplicates found.");

    // ------------------------- BEGIN TRANSACTION -------------------------
    logOnboarding("Starting database transaction...");
    $conn->begin_transaction();
    $transactionStarted = true;
    logOnboarding("Transaction started successfully.");

    // ------------------------- MOVE FILE -------------------------
    logOnboarding("Attempting to move uploaded file...");
    logOnboarding("Source: {$img_tmp}");
    logOnboarding("Destination: {$upload_path}");
    
    if (!move_uploaded_file($img_tmp, $upload_path)) {
        $lastError = error_get_last();
        logOnboarding("Failed to move file. Error: " . ($lastError['message'] ?? 'Unknown'));
        logOnboarding("File exists at source: " . (file_exists($img_tmp) ? 'Yes' : 'No'));
        logOnboarding("Directory writable: " . (is_writable($upload_dir) ? 'Yes' : 'No'));
        throw new Exception("Failed to save uploaded image.");
    }

    $fileMoved = true;
    logOnboarding("File moved successfully. File exists: " . (file_exists($upload_path) ? 'Yes' : 'No'));

    // ------------------------- DB INSERT -------------------------
    $agent_code = "AGENT" . random_unique_id();
    logOnboarding("Generated agent code: {$agent_code}");
    
    $insert_sql = "
        INSERT INTO agents
        (agent_code, firstname, lastname, email, phone, address, photo, gender, onboarded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    logOnboarding("Preparing insert statement...");
    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        logOnboarding("Insert prepare failed: " . $conn->error);
        throw new Exception("Database prepare error.");
    }

    logOnboarding("Binding parameters...");
    $bound = $stmt->bind_param(
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
    
    if (!$bound) {
        logOnboarding("Bind parameters failed");
        throw new Exception("Failed to bind parameters.");
    }

    logOnboarding("Executing insert...");
    if (!$stmt->execute()) {
        logOnboarding("Insert execute failed: " . $stmt->error);
        $stmt->close();
        throw new Exception("Failed to insert agent.");
    }

    $insertId = $conn->insert_id;
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    logOnboarding("Insert executed. Insert ID: {$insertId}, Affected rows: {$affectedRows}");

    // Verify the agent was actually inserted
    $verifyStmt = $conn->prepare("SELECT COUNT(*) as count FROM agents WHERE agent_code = ?");
    $verifyStmt->bind_param("s", $agent_code);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();
    $verifyRow = $verifyResult->fetch_assoc();
    $verifyStmt->close();
    
    logOnboarding("Verification query: Agent found in database: " . ($verifyRow['count'] ?? 0));

    // ------------------------- CREATE NOTIFICATION -------------------------
    logOnboarding("Creating notification...");
    try {
        createNotification($conn, [
            'user_id' => $userId,
            'title' => 'New Agent Onboarding',
            'message' => "User {$userId} has Onboarded a new agent into the Platform.",
            'type' => 'INFO',
            'category' => 'system_alert'
        ]);
        logOnboarding("Notification created successfully.");
    } catch (Exception $e) {
        logOnboarding("Notification creation failed (non-critical): " . $e->getMessage());
        // Don't throw, continue with onboarding
    }

    // ------------------------- COMMIT -------------------------
    logOnboarding("Committing transaction...");
    $conn->commit();
    $transactionStarted = false; // Reset since commit succeeded
    logOnboarding("Transaction committed successfully.");

    // Final verification
    $finalVerifyStmt = $conn->prepare("SELECT agent_code, firstname, lastname, email FROM agents WHERE agent_code = ?");
    $finalVerifyStmt->bind_param("s", $agent_code);
    $finalVerifyStmt->execute();
    $finalResult = $finalVerifyStmt->get_result();
    $agentData = $finalResult->fetch_assoc();
    $finalVerifyStmt->close();
    
    if (!$agentData) {
        logOnboarding("CRITICAL: Agent not found after commit! Agent code: {$agent_code}");
        throw new Exception("Agent creation failed - data not persisted.");
    }
    
    logOnboarding("Final verification successful: " . json_encode($agentData));

    // ------------------------- SUCCESS RESPONSE -------------------------
    logOnboarding("Onboarding completed successfully for agent: {$agent_code}");
    json_success("New Agent onboarded successfully!", [
        "agent_code" => $agent_code,
        "agent_name" => $agentData['firstname'] . ' ' . $agentData['lastname'],
        "email" => $agentData['email']
    ]);

} catch (Exception $e) {
    
    logOnboarding("=== EXCEPTION CAUGHT ===");
    logOnboarding("Error: " . $e->getMessage());
    logOnboarding("Transaction started: " . ($transactionStarted ? 'Yes' : 'No'));
    logOnboarding("File moved: " . ($fileMoved ? 'Yes' : 'No'));
    logOnboarding("Upload path: " . ($uploadPath ?? 'Not set'));

    // ------------------------- ROLLBACK ON FAILURE -------------------------
    if ($transactionStarted) {
        logOnboarding("Rolling back transaction...");
        if ($conn->rollback()) {
            logOnboarding("Transaction rolled back successfully.");
        } else {
            logOnboarding("Rollback failed: " . $conn->error);
        }
    } else {
        logOnboarding("No transaction to rollback.");
    }

    // ------------------------- CLEANUP -------------------------
    if ($fileMoved && isset($upload_path) && file_exists($upload_path)) {
        logOnboarding("Cleaning up uploaded file: {$upload_path}");
        if (unlink($upload_path)) {
            logOnboarding("File cleanup successful.");
        } else {
            logOnboarding("File cleanup failed.");
        }
    }

    // Log additional context
    $lastError = error_get_last();
    if ($lastError) {
        logOnboarding("Last PHP error: " . json_encode($lastError));
    }
    
    logOnboarding("MySQL error: " . $conn->error);
    logOnboarding("MySQL errno: " . $conn->errno);

    // ------------------------- ERROR RESPONSE -------------------------
    logOnboarding("Sending error response...");
    json_error("Onboarding failed: " . $e->getMessage(), 500);
    
} finally {
    // Always log the end of the request
    logOnboarding("Request processing completed. Request ID: {$requestId}");
    
    // Close connection if needed
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>