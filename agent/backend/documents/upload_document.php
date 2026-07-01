<?php
// upload_document.php - Upload a document for the client

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('client_upload_', true);
logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ========== UPLOAD DOCUMENT - START ==========");
logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Script: " . __FILE__);
logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Request Method: " . $_SERVER['REQUEST_METHOD']);

// Define constants
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('UPLOAD_DIR', __DIR__ . '/../client_documents/');

// Ensure upload directory exists
logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Checking upload directory: " . UPLOAD_DIR);
if (!is_dir(UPLOAD_DIR)) {
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Upload directory does not exist. Creating...");
    if (mkdir(UPLOAD_DIR, 0755, true)) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Upload directory created successfully");
    } else {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Failed to create upload directory");
    }
} else {
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Upload directory exists");
}

// Allowed file types
$allowed_types = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'text/plain' => 'txt'
];
logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Allowed file types: " . implode(', ', array_keys($allowed_types)));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['client_code'])) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: No client code in session");
        json_error("Not logged in", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 1 - Authentication passed. Client Code: {$client_code}");

    // ==================== STEP 2: CHECK USER ROLE ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 2: Checking user role");
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Client') {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 2 - Role validation passed: Client");

    // ==================== STEP 3: VALIDATE INPUT FIELDS ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 3: Validating input fields");
    
    $document_type = isset($_POST['document_type']) ? trim($_POST['document_type']) : '';
    $document_name = isset($_POST['document_name']) ? trim($_POST['document_name']) : '';
    
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 3.1 - document_type: {$document_type}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 3.2 - document_name: {$document_name}");

    // ==================== STEP 4: CHECK UPLOADED FILE ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 4: Checking uploaded file");
    
    if (!isset($_FILES['document_file'])) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: No file uploaded (document_file not set)");
        json_error("Please select a file to upload", 400);
    }
    
    $file_error = $_FILES['document_file']['error'];
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 4.1 - File upload error code: {$file_error}");
    
    if ($file_error !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => "File exceeds upload_max_filesize",
            UPLOAD_ERR_FORM_SIZE => "File exceeds MAX_FILE_SIZE",
            UPLOAD_ERR_PARTIAL => "File only partially uploaded",
            UPLOAD_ERR_NO_FILE => "No file uploaded",
            UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
            UPLOAD_ERR_EXTENSION => "File upload stopped by extension"
        ];
        $error_msg = $error_messages[$file_error] ?? "Unknown upload error";
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Upload failed - {$error_msg}");
        json_error("File upload failed: {$error_msg}", 400);
    }
    
    $file = $_FILES['document_file'];
    $file_tmp = $file['tmp_name'];
    $original_file_name = $file['name'];
    $file_size = $file['size'];
    
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 4.2 - File details:");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - original_file_name: {$original_file_name}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - file_size: {$file_size} bytes (" . round($file_size / 1024, 2) . " KB)");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - tmp_name: {$file_tmp}");
    
    // Get MIME type
    $file_mime = mime_content_type($file_tmp);
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 4.3 - Detected MIME type: {$file_mime}");

    // ==================== STEP 5: VALIDATE FILE SIZE ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 5: Validating file size");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - Max allowed: " . MAX_FILE_SIZE . " bytes (" . (MAX_FILE_SIZE / 1024 / 1024) . " MB)");
    
    if ($file_size > MAX_FILE_SIZE) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: File too large - {$file_size} > " . MAX_FILE_SIZE);
        json_error("File size must be less than 5MB", 400);
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 5 - File size validation passed");

    // ==================== STEP 6: VALIDATE FILE TYPE ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 6: Validating file type");
    
    if (!isset($allowed_types[$file_mime])) {
        $allowed_mime = implode(', ', array_keys($allowed_types));
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Invalid MIME type - {$file_mime}");
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - Allowed types: {$allowed_mime}");
        json_error("Invalid file type. Allowed: " . $allowed_mime, 400);
    }
    
    $file_extension = $allowed_types[$file_mime];
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 6.1 - File extension determined: {$file_extension}");

    // ==================== STEP 7: VALIDATE DOCUMENT TYPE ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 7: Validating document type");
    
    $allowed_doc_types = ['LEASE_AGREEMENT', 'IDENTIFICATION', 'PAYMENT_RECEIPT', 'MAINTENANCE_REQUEST', 'OTHER'];
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 7.1 - Allowed document types: " . implode(', ', $allowed_doc_types));
    
    if (!in_array($document_type, $allowed_doc_types)) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Invalid document type - {$document_type}");
        json_error("Invalid document type", 400);
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 7 - Document type validation passed");

    // ==================== STEP 8: VALIDATE DOCUMENT NAME ====================
    if (empty($document_name)) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Document name is empty");
        json_error("Document name is required", 400);
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 8 - Document name validation passed: {$document_name}");

    // ==================== STEP 9: GENERATE FILE HASH ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 9: Generating file hash");
    
    $file_content = file_get_contents($file_tmp);
    if ($file_content === false) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Failed to read file contents");
        json_error("Failed to read file", 500);
    }
    
    $file_hash = hash('sha256', $file_content);
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 9.1 - File hash generated: {$file_hash}");

    // ==================== STEP 10: CHECK FOR DUPLICATE FILE ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 10: Checking for duplicate file");
    
    $check_query = "SELECT document_id FROM client_documents WHERE client_code = ? AND file_hash = ? AND is_deleted = 0";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $client_code, $file_hash);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($existing) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Duplicate file found - Document ID: " . $existing['document_id']);
        json_error("This file has already been uploaded", 400);
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 10 - No duplicate found");

    // ==================== STEP 11: GENERATE STORED FILE NAME ====================
    $stored_file_name = $file_hash . '_' . time() . '.' . $file_extension;
    $upload_path = UPLOAD_DIR . $stored_file_name;
    
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 11 - Generated stored file name: {$stored_file_name}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 11.1 - Upload path: {$upload_path}");

    // ==================== STEP 12: MOVE FILE TO UPLOAD DIRECTORY ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 12: Moving file to upload directory");
    
    if (!move_uploaded_file($file_tmp, $upload_path)) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Failed to move uploaded file");
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - Source: {$file_tmp}");
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - Destination: {$upload_path}");
        json_error("Failed to save file", 500);
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 12 - File moved successfully");

    // ==================== STEP 13: INSERT DATABASE RECORD ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 13: Inserting document record into database");
    
    $query = "
        INSERT INTO client_documents (
            client_code, 
            document_name, 
            document_type, 
            file_name, 
            original_file_name, 
            file_size, 
            file_type, 
            file_hash,
            uploaded_by,
            uploaded_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        "sssssisss",
        $client_code,
        $document_name,
        $document_type,
        $stored_file_name,
        $original_file_name,
        $file_size,
        $file_mime,
        $file_hash,
        $client_code
    );
    
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 13.1 - Parameters bound:");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - client_code: {$client_code}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - document_name: {$document_name}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - document_type: {$document_type}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - file_name: {$stored_file_name}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - original_file_name: {$original_file_name}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - file_size: {$file_size}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - file_type: {$file_mime}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - file_hash: {$file_hash}");
    
    if (!$stmt->execute()) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ERROR: Database insert failed - " . $stmt->error);
        // Remove uploaded file if database insert fails
        if (file_exists($upload_path)) {
            unlink($upload_path);
            logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - Removed uploaded file due to DB error");
        }
        throw new Exception("Failed to save document record: " . $stmt->error);
    }
    
    $document_id = $stmt->insert_id;
    $stmt->close();
    
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 13.2 - Database record inserted successfully");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - Document ID: {$document_id}");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}]   - File Hash: {$file_hash}");

    // ==================== STEP 14: CREATE NOTIFICATION ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 14: Creating notification");
    
    try {
        createDocumentNotification($conn, $client_code, $document_name, 'uploaded');
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 14.1 - Notification created successfully");
    } catch (Exception $e) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] WARNING: Failed to create notification - " . $e->getMessage());
        // Don't fail the upload if notification fails
    }

    // ==================== STEP 15: LOG COMPLETION ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Step 15: Upload completed successfully");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Document uploaded - ID: {$document_id}, Name: {$document_name}, Size: {$file_size} bytes");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ========== UPLOAD DOCUMENT - SUCCESS ==========");
    
    json_success([
        'document_id' => $document_id,
        'document_name' => $document_name,
        'file_size' => $file_size,
        'uploaded_at' => date('Y-m-d H:i:s')
    ], "Document uploaded successfully");

} catch (Exception $e) {
    // ==================== ERROR HANDLING ====================
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] ========== UPLOAD DOCUMENT - ERROR ==========");
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Error Type: " . get_class($e));
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Error Line: " . $e->getLine());
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Stack Trace: " . $e->getTraceAsString());
    
    // Check for specific error conditions
    if (strpos($e->getMessage(), "client_documents") !== false) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] HINT: The 'client_documents' table might not exist or have wrong structure");
    }
    
    if (strpos($e->getMessage(), "Duplicate entry") !== false) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] HINT: Duplicate entry - possible unique constraint violation");
    }
    
    json_error($e->getMessage(), 500);
} finally {
    // ==================== CLEANUP ====================
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Closing database statement");
        $stmt->close();
    }
    logActivity("[CLIENT_UPLOAD] [ID:{$requestId}] Script execution completed");
}
?>