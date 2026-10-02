<?php
// tenant/backend/tenant/update_profile_photo.php
// Handles profile photo upload/update for tenants
// Photos are stored in the ADMIN's tenant_photos directory (matches onboard_tenant.php)

header('Content-Type: application/json; charset=utf-8');

// ==================== CONFIGURATION ====================
// Matches onboard_tenant.php settings
define('MAX_FILE_SIZE', 500000); // 500KB
define('ALLOWED_EXT', ['jpg', 'jpeg', 'png']);
define('ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/jpg']);
define('MIN_IMAGE_WIDTH', 100);
define('MIN_IMAGE_HEIGHT', 100);
define('MAX_IMAGE_WIDTH', 4000);
define('MAX_IMAGE_HEIGHT', 4000);

// Path to the ADMIN's tenant_photos directory (where onboard_tenant.php stores them)
// From: /tenant/backend/tenant/update_profile_photo.php
// To:   /admin/backend/tenants/tenant_photos/
define('PHOTO_UPLOAD_DIR', __DIR__ . '/../../../admin/backend/tenants/tenant_photos/');
define('PHOTO_URL_PATH', '/admin/backend/tenants/tenant_photos/');

require_once __DIR__ . '/../utilities/config.php';
// require_once __DIR__ . '/../utilities/auth_utils.php';
// require_once __DIR__ . '/../utilities/utils.php';
// require_once __DIR__ . '/../utilities/auth_guard.php';
// require_once __DIR__ . '/../utilities/rate_limit.php';


require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

logActivity("========== STARTING PROFILE PHOTO UPDATE ==========");

// ==================== AUTHENTICATION ====================
// $auth = requireAuth([
//     'method' => 'POST',
//     'rate_key' => 'update_profile_photo',
//     'rate_limit' => [5, 60], // 5 uploads per minute
//     'roles' => ['Tenant']
// ]);

// $userId = $auth['user_id'];
$userRole = $_SESSION['role'];
$tenant_code = $_SESSION['tenant_code'] ?? null;

if (!$tenant_code) {
    logActivity("ERROR: Tenant code not found in session");
    json_error("Session expired. Please login again.", 401);
}

logActivity("Authenticated tenant: {$tenant_code} | User ID: {$tenant_code}");

try {
    // ==================== VALIDATE UPLOAD ====================
    logActivity("Step 1: Validating uploaded file");

    if (!isset($_FILES['photo'])) {
        logActivity("ERROR: No photo in request");
        json_error('Please select a photo to upload.', 400);
    }

    $photo = $_FILES['photo'];

    if (!is_array($photo) || $photo['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $photo['error'] ?? 'unknown';
        logActivity("ERROR: Upload error code: {$errorCode}");
        json_error('Photo upload failed. Please try again.', 400);
    }

    $img_tmp = $photo['tmp_name'];
    $img_name = basename($photo['name']);
    $img_size = $photo['size'];
    $img_type = mime_content_type($img_tmp);

    logActivity("Uploaded file - Name: {$img_name}, Size: {$img_size}, Type: {$img_type}");

    // ==================== VALIDATE EXTENSION & MIME ====================
    logActivity("Step 2: Validating file type");

    $ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXT, true) || !in_array($img_type, ALLOWED_MIME, true)) {
        logActivity("Rejected invalid file: EXT={$ext}, MIME={$img_type}");
        json_error('Only JPG, JPEG & PNG images are allowed.', 400);
    }

    // ==================== VALIDATE SIZE ====================
    logActivity("Step 3: Validating file size");

    if ($img_size > MAX_FILE_SIZE) {
        logActivity("File too large: {$img_size} bytes");
        json_error('Image too large. Maximum size is ' . (MAX_FILE_SIZE / 1024) . 'KB.', 400);
    }

    if ($img_size < 100) {
        logActivity("File too small: {$img_size} bytes");
        json_error('Image file is too small. Please select a valid image.', 400);
    }

    // ==================== VALIDATE IMAGE CONTENT ====================
    logActivity("Step 4: Validating image content");

    $image_info = @getimagesize($img_tmp);
    if (!$image_info) {
        logActivity("Invalid image content");
        json_error('Invalid image file.', 400);
    }

    list($width, $height, $imageType) = $image_info;

    logActivity("Image dimensions: {$width}x{$height}, Type: {$imageType}");

    $allowedImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
    if (!in_array($imageType, $allowedImageTypes, true)) {
        logActivity("Unsupported image type: {$imageType}");
        json_error('Unsupported image format.', 400);
    }

    if ($width < MIN_IMAGE_WIDTH || $height < MIN_IMAGE_HEIGHT) {
        logActivity("Image too small: {$width}x{$height}");
        json_error("Image too small. Minimum is " . MIN_IMAGE_WIDTH . "x" . MIN_IMAGE_HEIGHT . " pixels.", 400);
    }

    if ($width > MAX_IMAGE_WIDTH || $height > MAX_IMAGE_HEIGHT) {
        logActivity("Image too large: {$width}x{$height}");
        json_error("Image too large. Maximum is " . MAX_IMAGE_WIDTH . "x" . MAX_IMAGE_HEIGHT . " pixels.", 400);
    }

    // ==================== GET CURRENT TENANT INFO ====================
    logActivity("Step 5: Fetching current tenant data");

    $userQuery = "SELECT id, photo FROM tenants WHERE tenant_code = ? LIMIT 1";
    $userStmt = $conn->prepare($userQuery);

    if (!$userStmt) {
        logActivity("ERROR: Failed to prepare user query: " . $conn->error);
        json_error("Database error occurred", 500);
    }

    $userStmt->bind_param("s", $tenant_code);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $user = $userResult->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        logActivity("ERROR: Tenant not found: {$tenant_code}");
        json_error("Tenant not found", 404);
    }

    $old_photo = $user['photo'];
    logActivity("Current photo: " . ($old_photo ?: 'none'));

    // ==================== GENERATE NEW FILENAME ====================
    logActivity("Step 6: Generating new filename");

    $file_hash = hash_file('sha256', $img_tmp);
    $file_name = $file_hash . '.' . $ext;

    logActivity("New filename (hash-based): {$file_name}");

    // ==================== PREPARE UPLOAD DIRECTORY ====================
    // Points to ADMIN's tenant_photos directory (same as onboard_tenant.php)
    $upload_path = PHOTO_UPLOAD_DIR . $file_name;

    logActivity("Target upload directory: " . PHOTO_UPLOAD_DIR);
    logActivity("Target upload path: {$upload_path}");

    if (!is_dir(PHOTO_UPLOAD_DIR)) {
        logActivity("WARNING: Upload directory does not exist: " . PHOTO_UPLOAD_DIR);
        logActivity("Attempting to create directory...");
        
        if (!mkdir(PHOTO_UPLOAD_DIR, 0750, true)) {
            logActivity("ERROR: Failed to create upload directory");
            json_error("Server error: Upload directory not available", 500);
        }
        logActivity("Created upload directory");
    }

    if (!is_writable(PHOTO_UPLOAD_DIR)) {
        logActivity("ERROR: Upload directory is not writable: " . PHOTO_UPLOAD_DIR);
        json_error("Server error: Upload directory not writable", 500);
    }

    // ==================== CHECK FOR DUPLICATE ====================
    logActivity("Step 7: Checking for existing file");

    // If the exact same file exists, check its usage
    if (is_file($upload_path)) {
        logActivity("File with same hash already exists: {$file_name}");
        
        // Check if it belongs to another tenant
        $photoCheckStmt = $conn->prepare("SELECT tenant_code FROM tenants WHERE photo = ? AND tenant_code != ? LIMIT 1");
        if ($photoCheckStmt) {
            $photoCheckStmt->bind_param("ss", $file_name, $tenant_code);
            $photoCheckStmt->execute();
            $photoCheckResult = $photoCheckStmt->get_result();
            
            if ($photoCheckResult->num_rows > 0) {
                $photoCheckStmt->close();
                logActivity("File already used by another tenant");
                json_error('This image has already been uploaded for another tenant.', 409);
            }
            $photoCheckStmt->close();
        }
        
        // If it's the same photo the tenant already has, no action needed
        if ($old_photo === $file_name) {
            logActivity("Same photo as current - no change needed");
            json_success([
                'message' => 'No change detected',
                'photo_filename' => $file_name,
                'photo_url' => PHOTO_URL_PATH . $file_name
            ], 'Profile photo is already up to date');
            exit;
        }
    }

    // ==================== MOVE UPLOADED FILE ====================
    logActivity("Step 8: Moving uploaded file");

    if (!move_uploaded_file($img_tmp, $upload_path)) {
        logActivity("ERROR: Failed to move uploaded file to: {$upload_path}");
        json_error('Failed to save uploaded file.', 500);
    }

    @chmod($upload_path, 0640);
    logActivity("File moved successfully to: {$upload_path}");

    // ==================== UPDATE DATABASE ====================
    logActivity("Step 9: Updating database");

    $conn->begin_transaction();

    try {
        $updateQuery = "UPDATE tenants SET photo = ?, last_updated_at = NOW() WHERE tenant_code = ?";
        $updateStmt = $conn->prepare($updateQuery);

        if (!$updateStmt) {
            throw new Exception("Failed to prepare update: " . $conn->error);
        }

        $updateStmt->bind_param("ss", $file_name, $tenant_code);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update database: " . $updateStmt->error);
        }

        $updateStmt->close();
        logActivity("Database updated with new photo: {$file_name}");

        $conn->commit();
        logActivity("Transaction committed");

    } catch (Exception $dbError) {
        $conn->rollback();
        // Clean up the newly uploaded file
        if (file_exists($upload_path)) {
            @unlink($upload_path);
            logActivity("Rolled back - cleaned up new file");
        }
        throw $dbError;
    }

    // ==================== DELETE OLD PHOTO ====================
    logActivity("Step 10: Cleaning up old photo");

    if (!empty($old_photo) && $old_photo !== $file_name) {
        $old_photo_path = PHOTO_UPLOAD_DIR . $old_photo;

        // Security: ensure old photo is within our upload directory
        $real_old_path = realpath($old_photo_path);
        $real_upload_dir = realpath(PHOTO_UPLOAD_DIR);

        if ($real_old_path && $real_upload_dir && strpos($real_old_path, $real_upload_dir) === 0) {
            // Check if old photo is used by any other tenant before deleting
            $checkUsageStmt = $conn->prepare("SELECT COUNT(*) as count FROM tenants WHERE photo = ?");
            if ($checkUsageStmt) {
                $checkUsageStmt->bind_param("s", $old_photo);
                $checkUsageStmt->execute();
                $usageResult = $checkUsageStmt->get_result()->fetch_assoc();
                $checkUsageStmt->close();

                if ((int)$usageResult['count'] === 0) {
                    if (@unlink($old_photo_path)) {
                        logActivity("Old photo deleted: {$old_photo}");
                    } else {
                        logActivity("WARNING: Could not delete old photo: {$old_photo}");
                    }
                } else {
                    logActivity("Old photo still in use by other tenants - not deleted");
                }
            }
        } else {
            logActivity("SECURITY: Skipped deleting old photo (outside upload dir): {$old_photo}");
        }
    }

    // ==================== SUCCESS RESPONSE ====================
    logActivity("========== PROFILE PHOTO UPDATE COMPLETED ==========");

    json_success([
        'message' => 'Profile photo updated successfully',
        'photo_filename' => $file_name,
        'photo_url' => PHOTO_URL_PATH . $file_name,
        'dimensions' => "{$width}x{$height}",
        'size' => $img_size
    ], 'Profile photo updated successfully');

} catch (Exception $e) {
    logActivity("ERROR: " . $e->getMessage());
    logActivity("Exception trace: " . $e->getTraceAsString());
    logActivity("========== PROFILE PHOTO UPDATE FAILED ==========");
    json_error("An error occurred while updating your photo. Please try again.", 500);
}