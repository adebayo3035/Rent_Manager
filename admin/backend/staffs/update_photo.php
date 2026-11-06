<?php
// admin/backend/staffs/update_photo.php
// Admin avatar photo upload — separate endpoint, own rate limit.
//
// Mirrors the photo handling in onboard_admin.php:
//   - Content-hash filename (<sha256>.<ext>) — same image is stored once
//   - Duplicate check against DB and disk — same image can't be reused
//   - 500 KB size limit — matches onboarding
//
// Additional security (not in onboarding):
//   - finfo MIME cross-check (do not trust $_FILES['photo']['type'])
//   - Extension whitelist enforced
//   - Old photo deleted after new one is safely committed
//   - Path traversal guarded with realpath() containment

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'admin_update_photo',
    'rate_limit' => [5, 300],
    'csrf'       => ['enabled' => true, 'form_name' => 'admin_profile_form'],
    'roles'      => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];

$requestTraceId = uniqid('update_photo_', true);
logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ========== START ==========");
logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Admin: {$adminId}");

try {
    // ---- 1) Upload presence ----
    if (!isset($_FILES['photo'])) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: No file field", 'WARN');
        json_error("No photo uploaded", 400);
    }

    $photo = $_FILES['photo'];

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
        $msg = $errorMessages[$photo['error']] ?? 'Upload failed';
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: {$msg}", 'WARN');
        json_error($msg, 400);
    }

    // ---- 2) Extension check (match onboarding) ----
    $img_name = basename($photo['name']);
    $tmp_name = $photo['tmp_name'];
    $img_size = $photo['size'];
    $ext      = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

    $allowed_ext = ['jpeg', 'jpg', 'png'];
    if (!in_array($ext, $allowed_ext, true)) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Bad extension '{$ext}'", 'WARN');
        json_error('Invalid image file. Allowed types: jpg, jpeg, png.', 400);
    }

    // ---- 3) Real MIME check (improvement over onboarding) ----
    if (!function_exists('finfo_open')) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: fileinfo unavailable", 'ERROR');
        json_error("Server misconfiguration — please contact support", 500);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: finfo_open failed", 'ERROR');
        json_error("Server misconfiguration — please contact support", 500);
    }
    $actualMime = finfo_file($finfo, $tmp_name);

    $extMimeMap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    if ($actualMime !== $extMimeMap[$ext]) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: MIME mismatch ext={$ext}, actual={$actualMime}", 'WARN');
        json_error("File content does not match its extension", 400);
    }

    // ---- 4) Size (1 MB — match onboarding) ----
    $maxSize = 500000 * 2;
    if ($img_size > $maxSize) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Too large {$img_size} bytes", 'WARN');
        json_error("Sorry, your file is too large. Max 500KB allowed.", 400);
    }

    // ---- 5) Upload directory ----
    $upload_dir = __DIR__ . '/admin_photos/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Cannot create directory", 'ERROR');
        json_error('Server error preparing upload directory.', 500);
    }
    if (!is_writable($upload_dir)) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Directory not writable", 'ERROR');
        json_error('Server error preparing upload directory.', 500);
    }

    // ---- 6) Content-hash filename (match onboarding) ----
    $file_hash = hash_file('sha256', $tmp_name);
    $file_name = $file_hash . '.' . $ext;
    $upload_file = $upload_dir . $file_name;

    logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Hash: {$file_hash}, Filename: {$file_name}");

    // ---- 7) Duplicate check — same image already used by *another* admin ----
    $photo_check_stmt = $conn->prepare("
        SELECT 1 FROM admin_tbl WHERE photo = ? AND unique_id != ? LIMIT 1
    ");
    if (!$photo_check_stmt) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Prepare photo check failed", 'ERROR');
        json_error('Server database error.', 500);
    }
    $photo_check_stmt->bind_param('si', $file_name, $adminId);
    $photo_check_stmt->execute();
    $photo_res = $photo_check_stmt->get_result();
    if ($photo_res && $photo_res->num_rows > 0) {
        $photo_check_stmt->close();
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Photo belongs to another admin", 'WARN');
        json_error('This image is already being used by another user.', 409);
    }
    $photo_check_stmt->close();

    // ---- 8) Read old photo BEFORE moving (for cleanup after commit) ----
    $old_stmt = $conn->prepare("SELECT photo FROM admin_tbl WHERE unique_id = ? LIMIT 1");
    if (!$old_stmt) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Prepare old-photo read failed", 'ERROR');
        json_error('Server database error.', 500);
    }
    $old_stmt->bind_param('i', $adminId);
    $old_stmt->execute();
    $row = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    if (!$row) {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: Admin not found", 'WARN');
        json_error('Admin not found', 404);
    }
    $oldPhoto = $row['photo'] ?? null;

    // ---- 9) If the file already exists on disk with the same hash,
    //         the admin is just re-uploading the same image — no-op.
    //         Otherwise, move it into place. ----
    $fileWasMoved = false;
    if (!is_file($upload_file)) {
        if (!move_uploaded_file($tmp_name, $upload_file)) {
            logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: move_uploaded_file failed", 'ERROR');
            json_error('Failed to upload image.', 500);
        }
        $fileWasMoved = true;
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] File moved to {$upload_file}");
    } else {
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] File already exists on disk (dedup) — reusing {$file_name}");
    }

    // ---- 10) Update DB ----
    $conn->begin_transaction();
    logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Transaction started");

    try {
        $upd = $conn->prepare("
            UPDATE admin_tbl
            SET photo = ?, updated_at = NOW(), last_updated_by = ?
            WHERE unique_id = ? AND status = '1'
        ");
        if (!$upd) throw new Exception("Prepare failed (update photo): " . $conn->error);
        $upd->bind_param("sii", $file_name, $adminId, $adminId);
        if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
        $upd->close();

        $conn->commit();
        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Transaction committed");
    } catch (Throwable $e) {
        $conn->rollback();
        // Only delete the new file if we just moved it AND the DB update failed
        if ($fileWasMoved && is_file($upload_file)) {
            @unlink($upload_file);
        }
        throw $e;
    }

    // ---- 11) Delete old photo (containment-checked) ----
    // Only delete if:
    //   - it differs from the new filename
    //   - it's not referenced by any other admin (paranoid, since onboarding dedups)
    //   - path is contained within admin_photos/
    if ($oldPhoto && $oldPhoto !== $file_name) {
        // Ensure no other admin row still references the old photo
        $ref_stmt = $conn->prepare("SELECT COUNT(*) AS n FROM admin_tbl WHERE photo = ?");
        if ($ref_stmt) {
            $ref_stmt->bind_param("s", $oldPhoto);
            $ref_stmt->execute();
            $refCount = (int) ($ref_stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $ref_stmt->close();

            if ($refCount === 0) {
                $oldPath = $upload_dir . $oldPhoto;
                $realDir = realpath($upload_dir);
                $realOld = realpath($oldPath);

                if ($realOld && $realDir && strpos($realOld, $realDir) === 0 && is_file($realOld)) {
                    if (@unlink($realOld)) {
                        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Old photo deleted: {$oldPhoto}");
                    } else {
                        logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Old photo delete failed: {$oldPhoto}", 'WARN');
                    }
                }
            } else {
                logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] Old photo still referenced ({$refCount} rows), skipped delete: {$oldPhoto}");
            }
        }
    }

    // ---- 12) Build public URL (same convention as admin profile getPhotoUrl) ----
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appBase = '/rent_manager'; // adjust if app is at root
    $photoUrl = "{$scheme}://{$host}{$appBase}/admin/backend/staffs/admin_photos/{$file_name}";

    logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ========== SUCCESS ==========");

    json_success("Photo updated successfully", [
        'photo'     => $file_name,
        'photo_url' => $photoUrl,
    ]);

} catch (Throwable $e) {
    logActivity("[UPDATE_PHOTO] [ID:{$requestTraceId}] ERROR: " . $e->getMessage(), 'ERROR');
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}