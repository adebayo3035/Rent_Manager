<?php
// client/backend/client/update_profile_photo.php
// Handles profile photo upload/update for clients
// Photos are stored in the ADMIN's client_photos directory (matches onboard_client.php)

header('Content-Type: application/json; charset=utf-8');

// ==================== CONFIGURATION ====================
define('MAX_FILE_SIZE', 500000); // 500KB
define('ALLOWED_EXT', ['jpg', 'jpeg', 'png']);
define('ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/jpg']);
define('MIN_IMAGE_WIDTH', 100);
define('MIN_IMAGE_HEIGHT', 100);
define('MAX_IMAGE_WIDTH', 4000);
define('MAX_IMAGE_HEIGHT', 4000);

// Path to the ADMIN's client_photos directory
// From: /client/backend/client/update_profile_photo.php
// To:   /admin/backend/clients/client_photos/
define('PHOTO_UPLOAD_DIR', __DIR__ . '/../../../admin/backend/clients/client_photos/');
define('PHOTO_URL_PATH', 'admin/backend/clients/client_photos/'); // no leading slash
// ==================== BOOTSTRAP ====================
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

// FIX: use session_status() instead of isset($_SESSION)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

rateLimiter();

// FIX: Guard against missing DB connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    logActivity("ERROR: DB connection not available in update_profile_photo.php");
    json_error("Server error: database unavailable", 500);
}

logActivity("========== STARTING PROFILE PHOTO UPDATE ==========");

// FIX: Guard against post_max_size overflow (silent empty $_FILES/$_POST)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_FILES)
    && empty($_POST)
) {
    logActivity("ERROR: POST body exceeded post_max_size (empty \$_FILES and \$_POST)");
    json_error("Upload too large. Please select a smaller image.", 413);
}

// ==================== AUTH & ROLE CHECK ====================
$userRole = $_SESSION['role'] ?? null;
$client_code = $_SESSION['client_code'] ?? null;

if (!$client_code) {
    // FIX: corrected log message (was "Tenant code")
    logActivity("ERROR: Client code not found in session");
    json_error("Session expired. Please login again.", 401);
}

// FIX: enforce role
if ($userRole !== 'Client') {
    logActivity("ERROR: Unauthorized role '{$userRole}' tried to update client photo for {$client_code}");
    json_error("Unauthorized access", 403);
}

logActivity("Authenticated client: {$client_code} | Role: {$userRole}");

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

    // FIX: normalize jpeg -> jpg to avoid duplicate filenames for identical content
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

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

    // ==================== FETCH CURRENT CLIENT ====================
    logActivity("Step 5: Fetching current client data");

    $userQuery = "SELECT client_id, photo FROM clients WHERE client_code = ? LIMIT 1";
    $userStmt = $conn->prepare($userQuery);

    if (!$userStmt) {
        logActivity("ERROR: Failed to prepare user query: " . $conn->error);
        json_error("Database error occurred", 500);
    }

    $userStmt->bind_param("s", $client_code);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $user = $userResult->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        logActivity("ERROR: Client not found: {$client_code}");
        json_error("Client not found", 404);
    }

    $old_photo = $user['photo'];
    logActivity("Current photo: " . ($old_photo ?: 'none'));

    // ==================== GENERATE NEW FILENAME ====================
    logActivity("Step 6: Generating new filename");

    $file_hash = hash_file('sha256', $img_tmp);
    $file_name = $file_hash . '.' . $ext;

    logActivity("New filename (hash-based): {$file_name}");

    // ==================== PREPARE UPLOAD DIRECTORY ====================
    $upload_path = PHOTO_UPLOAD_DIR . $file_name;

    logActivity("Target upload directory: " . PHOTO_UPLOAD_DIR);
    logActivity("Target upload path: {$upload_path}");

    if (!is_dir(PHOTO_UPLOAD_DIR)) {
        logActivity("WARNING: Upload directory does not exist. Attempting to create...");
        if (!mkdir(PHOTO_UPLOAD_DIR, 0755, true) && !is_dir(PHOTO_UPLOAD_DIR)) {
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

    if (is_file($upload_path)) {
        logActivity("File with same hash already exists: {$file_name}");

        // Same photo, same client → no-op
        if ($old_photo === $file_name) {
            logActivity("Same photo as current - no change needed");
            json_success([
                'message' => 'No change detected',
                'photo_filename' => $file_name,
                'photo_url' => buildPhotoUrl($file_name),
            ], 'Profile photo is already up to date');
        }

        // Same content, different client → conflict
        $photoCheckStmt = $conn->prepare(
            "SELECT client_code FROM clients WHERE photo = ? AND client_code != ? LIMIT 1"
        );
        if ($photoCheckStmt) {
            $photoCheckStmt->bind_param("ss", $file_name, $client_code);
            $photoCheckStmt->execute();
            $photoCheckResult = $photoCheckStmt->get_result();

            if ($photoCheckResult->num_rows > 0) {
                $photoCheckStmt->close();
                logActivity("File already used by another client");
                json_error('This image has already been uploaded for another client.', 409);
            }
            $photoCheckStmt->close();
        }
    }

    // ==================== MOVE UPLOADED FILE ====================
    logActivity("Step 8: Moving uploaded file");

    if (!move_uploaded_file($img_tmp, $upload_path)) {
        logActivity("ERROR: Failed to move uploaded file to: {$upload_path}");
        json_error('Failed to save uploaded file.', 500);
    }

    // FIX: 0644 (world-readable) so the web server can serve the image later.
    // 0640 would break access if PHP and the web server run as different users.
    @chmod($upload_path, 0644);
    logActivity("File moved successfully to: {$upload_path}");

    // ==================== UPDATE DATABASE ====================
    logActivity("Step 9: Updating database");

    $conn->begin_transaction();

    try {
        $updateQuery = "UPDATE clients SET photo = ?, date_updated = NOW() WHERE client_code = ?";
        $updateStmt = $conn->prepare($updateQuery);

        if (!$updateStmt) {
            throw new Exception("Failed to prepare update: " . $conn->error);
        }

        $updateStmt->bind_param("ss", $file_name, $client_code);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update database: " . $updateStmt->error);
        }

        $updateStmt->close();
        logActivity("Database updated with new photo: {$file_name}");

        $conn->commit();
        logActivity("Transaction committed");

    } catch (Exception $dbError) {
        $conn->rollback();
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

        $real_old_path = realpath($old_photo_path);
        $real_upload_dir = realpath(PHOTO_UPLOAD_DIR);

        // FIX: append trailing separator to prevent sibling-dir prefix attack
        // e.g. ".../client_photos_backup/evil.jpg" would otherwise pass strpos() === 0
        $real_upload_dir = $real_upload_dir
            ? rtrim($real_upload_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            : null;

        if (
            $real_old_path
            && $real_upload_dir
            && strpos($real_old_path, $real_upload_dir) === 0
        ) {
            // Only delete if no other client references this file
            $checkUsageStmt = $conn->prepare("SELECT COUNT(*) AS count FROM clients WHERE photo = ?");
            if ($checkUsageStmt) {
                $checkUsageStmt->bind_param("s", $old_photo);
                $checkUsageStmt->execute();
                $usageResult = $checkUsageStmt->get_result()->fetch_assoc();
                $checkUsageStmt->close();

                if ((int) $usageResult['count'] === 0) {
                    if (@unlink($old_photo_path)) {
                        logActivity("Old photo deleted: {$old_photo}");
                    } else {
                        logActivity("WARNING: Could not delete old photo: {$old_photo}");
                    }
                } else {
                    logActivity("Old photo still in use by other clients - not deleted");
                }
            }
        } else {
            logActivity("SECURITY: Skipped deleting old photo (outside upload dir): {$old_photo}");
        }
    }

    // ==================== SUCCESS RESPONSE ====================
    logActivity("========== PROFILE PHOTO UPDATE COMPLETED ==========");

    // FIX: arguments were reversed — message first, data second
    json_success([
        'message' => 'Profile photo updated successfully',
        'photo_filename' => $file_name,
        'photo_url' => buildPhotoUrl($file_name),
        'dimensions' => "{$width}x{$height}",
        'size' => $img_size,
    ], 'Profile photo updated successfully');

} catch (Exception $e) {
    logActivity("ERROR: " . $e->getMessage());
    logActivity("Exception trace: " . $e->getTraceAsString());
    logActivity("========== PROFILE PHOTO UPDATE FAILED ==========");
    json_error("An error occurred while updating your photo. Please try again.", 500);
}

/**
 * Compute the URL path to a file in the admin's client_photos directory,
 * including the app's base path (e.g. "/Rent_Manager/"), so the URL is
 * usable verbatim from any frontend (browser, mobile, etc.).
 */
function buildPhotoUrl(string $filename): string
{
    // DOCUMENT_ROOT = C:/xampp/htdocs
    // __DIR__ of this file = C:/xampp/htdocs/Rent_Manager/client/backend/client
    // The photo lives in  C:/xampp/htdocs/Rent_Manager/admin/backend/clients/client_photos/
    //
    // We need "/Rent_Manager/admin/backend/clients/client_photos/{$filename}"

    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    $photoDirFs = str_replace('\\', '/', realpath(PHOTO_UPLOAD_DIR) ?: PHOTO_UPLOAD_DIR);
    $photoDirFs = rtrim($photoDirFs, '/');

    // Strip the docroot prefix → gives "/Rent_Manager/admin/backend/clients/client_photos"
    if ($documentRoot && strpos($photoDirFs, $documentRoot) === 0) {
        $urlPath = substr($photoDirFs, strlen($documentRoot));
    } else {
        // Fallback: assume app is at /Rent_Manager
        $urlPath = '/Rent_Manager/admin/backend/clients/client_photos';
    }

    return $urlPath . '/' . rawurlencode($filename);
}