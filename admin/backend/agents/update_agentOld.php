<?php
// update_agent.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    logActivity("Update Agent API called | IP: " . getClientIP());

    // -------------------------
    // AUTH CHECK
    // -------------------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized update attempt | IP: " . getClientIP());
        json_error("Unauthorized", 401);
    }
    $adminId = $_SESSION['unique_id'];
    logActivity("Authenticated Admin: {$adminId}");

    // -------------------------
    // RATE LIMIT
    // -------------------------
    rateLimit("update_agent", 20, 60); // 20 requests per minute per IP
    logActivity("Rate limit check passed for admin {$adminId}");

    // -------------------------
    // ENSURE POST (multipart/form-data)
    // -------------------------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logActivity("Invalid HTTP method for update_agent: {$_SERVER['REQUEST_METHOD']}");
        json_error("Invalid HTTP method. POST required.", 405);
    }

    // -------------------------
    // COLLECT + SANITIZE INPUTS
    // -------------------------
    // Use sanitize_inputs from utils.php if available
    $raw = $_POST;
    $inputs = sanitize_inputs($raw);

    logActivity("Raw POST inputs: " . json_encode($raw));

    // Required field: agent_code
    if (empty($inputs['agent_code'])) {
        logActivity("Validation failed: agent_code missing");
        json_error("agent_code is required", 400);
    }
    $agent_code = $inputs['agent_code'];

    // Optional fields (but we'll require some)
    $firstname = $inputs['firstname'] ?? '';
    $lastname  = $inputs['lastname']  ?? '';
    $email     = $inputs['email']     ?? '';
    $phone     = $inputs['phone']     ?? '';
    $address   = $inputs['address']   ?? '';
    $gender    = $inputs['gender']    ?? '';
    $status    = isset($inputs['status']) ? intval($inputs['status']) : null;

    logActivity("Sanitized inputs for agent_code {$agent_code}: firstname={$firstname}, lastname={$lastname}, email={$email}, phone={$phone}, gender={$gender}, status={$status}");

    // -------------------------
    // BASIC VALIDATION
    // -------------------------
    // require at least firstname, lastname, email, phone, gender, status in update
    $required = ['firstname', 'lastname', 'email', 'phone', 'gender', 'status'];
    foreach ($required as $f) {
        if (!isset($inputs[$f]) || trim($inputs[$f]) === '') {
            logActivity("Validation failed: missing field {$f} for agent {$agent_code}");
            json_error("{$f} is required", 400);
        }
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logActivity("Validation failed: invalid email ({$email}) for agent {$agent_code}");
        json_error("Invalid email address", 400);
    }

    // Validate phone (use your validate_phone if available)
    
        if (!validate_phone($phone)) {
            logActivity("Validation failed: invalid phone ({$phone}) for agent {$agent_code}");
            json_error("Invalid phone number", 400);
        }

    // Normalize gender & status
    $gender = ucfirst(strtolower($gender));
    if (!in_array($gender, ['Male', 'Female'], true)) {
        logActivity("Validation failed: invalid gender ({$gender}) for agent {$agent_code}");
        json_error("Invalid gender", 400);
    }
    if (!in_array($status, [0, 1], true)) {
        logActivity("Validation failed: invalid status ({$status}) for agent {$agent_code}");
        json_error("Invalid status", 400);
    }

    // -------------------------
    // FETCH EXISTING AGENT + START TRANSACTION
    // -------------------------
    $conn->begin_transaction();
    logActivity("DB transaction started for updating agent {$agent_code} by admin {$adminId}");

    $stmt = $conn->prepare("SELECT email, phone, photo FROM agents WHERE agent_code = ? LIMIT 1");
    if (!$stmt) {
        logActivity("DB prepare failed (select agent): " . $conn->error);
        $conn->rollback();
        json_error("Database error", 500);
    }
    $stmt->bind_param("s", $agent_code);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        logActivity("Agent not found during update: {$agent_code}");
        $stmt->close();
        $conn->rollback();
        json_error("Agent not found", 404);
    }

    $existing = $res->fetch_assoc();
    $oldEmail = $existing['email'];
    $oldPhone = $existing['phone'];
    $oldPhoto = $existing['photo'];
    $stmt->close();

    logActivity("Existing agent data loaded for {$agent_code} | email={$oldEmail} phone={$oldPhone} photo={$oldPhoto}");

    // -------------------------
    // SINGLE DUPLICATE CHECK (email OR phone)
    // -------------------------
    $dupSql = "SELECT agent_code, email, phone FROM agents WHERE (email = ? OR phone = ?) AND agent_code != ? LIMIT 1";
    $dupStmt = $conn->prepare($dupSql);
    if (!$dupStmt) {
        logActivity("DB prepare failed (dup check): " . $conn->error);
        $conn->rollback();
        json_error("Database error", 500);
    }
    $dupStmt->bind_param("sss", $email, $phone, $agent_code);
    $dupStmt->execute();
    $dupRes = $dupStmt->get_result();

    if ($dupRes && $dupRes->num_rows > 0) {
        $dupRow = $dupRes->fetch_assoc();
        logActivity("Duplicate found for update of {$agent_code}: " . json_encode($dupRow));
        $dupStmt->close();
        $conn->rollback();

        if ($dupRow['email'] === $email) {
            json_error("Email already exists for another agent", 409);
        }
        if ($dupRow['phone'] === $phone) {
            json_error("Phone already exists for another agent", 409);
        }

        // generic fallback
        json_error("Duplicate contact found", 409);
    }
    $dupStmt->close();
    logActivity("Duplicate check passed for agent {$agent_code}");

    // -------------------------
    // HANDLE PHOTO UPLOAD (if provided)
    // -------------------------
    $newPhotoName = $oldPhoto; // default keep old if not uploading new

    if (!empty($_FILES['photo']['name'])) {
        logActivity("Photo upload detected for agent {$agent_code}");
        $photo = $_FILES['photo'];

        // Validate file upload success
        if ($photo['error'] !== UPLOAD_ERR_OK) {
            logActivity("Photo upload error ({$photo['error']}) for agent {$agent_code}");
            $conn->rollback();
            json_error("Photo upload error", 400);
        }

        // Validate size (2MB max)
        $maxSize = 2 * 1024 * 1024;
        if ($photo['size'] > $maxSize) {
            logActivity("Photo too large ({$photo['size']}) for agent {$agent_code}");
            $conn->rollback();
            json_error("Photo too large. Max 2MB allowed", 400);
        }

        // Validate MIME type / extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $photo['tmp_name']);
        finfo_close($finfo);

        $allowed = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png'];
        if (!array_key_exists($mime, $allowed)) {
            logActivity("Unsupported photo mime '{$mime}' for agent {$agent_code}");
            $conn->rollback();
            json_error("Unsupported photo type. Use JPG or PNG", 400);
        }

        // Generate unique filename
        $ext = $allowed[$mime];
        // $newPhotoName = sprintf("agent_%s_%s.%s", time(), bin2hex(random_bytes(6)), $ext);
        $file_hash = hash_file('sha256', $photo['tmp_name']);
        $newPhotoName = $file_hash . '.' . $ext;
        logActivity("Generated new photo name '{$newPhotoName}' for agent {$agent_code}");
        $uploadDir = __DIR__ . "/agent_photos/";
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            logActivity("Failed to ensure upload directory exists: {$uploadDir}");
            $conn->rollback();
            json_error("Server error preparing upload directory", 500);
        }

        $dest = $uploadDir . $newPhotoName;
        if (!move_uploaded_file($photo['tmp_name'], $dest)) {
            logActivity("Failed to move uploaded photo to {$dest} for agent {$agent_code}");
            $conn->rollback();
            json_error("Failed to save uploaded photo", 500);
        }

        logActivity("Photo uploaded to {$dest} for agent {$agent_code}");

        // remove old photo file if exists and different
        if (!empty($oldPhoto) && $oldPhoto !== $newPhotoName) {
            $oldPath = $uploadDir . $oldPhoto;
            if (file_exists($oldPath)) {
                if (@unlink($oldPath)) {
                    logActivity("Removed old photo {$oldPath} for agent {$agent_code}");
                } else {
                    logActivity("Failed to remove old photo {$oldPath} for agent {$agent_code}");
                }
            }
        }
    }

    // -------------------------
    // EXECUTE UPDATE
    // -------------------------
    $updateSql = "
        UPDATE agents SET
            firstname = ?,
            lastname  = ?,
            email     = ?,
            phone     = ?,
            address   = ?,
            gender    = ?,
            status    = ?,
            photo     = ?
        WHERE agent_code = ?
        LIMIT 1
    ";

    $updateStmt = $conn->prepare($updateSql);
    if (!$updateStmt) {
        logActivity("DB prepare failed (update): " . $conn->error);
        $conn->rollback();
        json_error("Database error", 500);
    }

    $updateStmt->bind_param(
        "ssssssiss",
        $firstname,
        $lastname,
        $email,
        $phone,
        $address,
        $gender,
        $status,
        $newPhotoName,
        $agent_code
    );

    logActivity("Executing update for agent {$agent_code}");
    if (!$updateStmt->execute()) {
        logActivity("DB update failed for {$agent_code}: " . $updateStmt->error);
        $updateStmt->close();
        $conn->rollback();
        json_error("Failed to update agent", 500);
    }

    $affected = $updateStmt->affected_rows;
    $updateStmt->close();

    $conn->commit();
    logActivity("Agent {$agent_code} updated successfully by admin {$adminId} | affected_rows={$affected}");

    $responseData = [
        'success' => true,
        'message' => "Agent updated successfully",
        'agent_code' => $agent_code,
        'affected_rows' => $affected,
        'photo' => $newPhotoName
    ];

    echo json_encode($responseData);
    exit();

} catch (Exception $e) {
    $err = $e->getMessage();
    logActivity("EXCEPTION updating agent: " . $err);
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    json_error("Server error: " . $err, 500);
}
