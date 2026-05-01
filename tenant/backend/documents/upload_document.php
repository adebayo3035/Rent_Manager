<?php
// upload_document.php - Upload a document for the tenant

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

logActivity("========== UPLOAD DOCUMENT - START ==========");

// Define constants
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('UPLOAD_DIR', __DIR__ . '/../tenant_documents/');

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
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

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    logActivity("Tenant Code: {$tenant_code}");

    // Validate input
    $document_type = isset($_POST['document_type']) ? trim($_POST['document_type']) : '';
    $document_name = isset($_POST['document_name']) ? trim($_POST['document_name']) : '';

    // Get uploaded file
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        json_error("Please select a file to upload", 400);
    }

    $file = $_FILES['document_file'];
    $file_tmp = $file['tmp_name'];
    $original_file_name = $file['name'];
    $file_size = $file['size'];
    $file_mime = mime_content_type($file_tmp);

    // Validate file size
    if ($file_size > MAX_FILE_SIZE) {
        json_error("File size must be less than 5MB", 400);
    }

    // Validate file type
    if (!isset($allowed_types[$file_mime])) {
        $allowed_mime = implode(', ', array_keys($allowed_types));
        json_error("Invalid file type. Allowed: " . $allowed_mime, 400);
    }

    $file_extension = $allowed_types[$file_mime];
    $allowed_doc_types = ['LEASE_AGREEMENT', 'IDENTIFICATION', 'PAYMENT_RECEIPT', 'MAINTENANCE_REQUEST', 'OTHER'];
    
    if (!in_array($document_type, $allowed_doc_types)) {
        json_error("Invalid document type", 400);
    }

    if (empty($document_name)) {
        json_error("Document name is required", 400);
    }

    // Generate unique file name using hash
    $file_content = file_get_contents($file_tmp);
    $file_hash = hash('sha256', $file_content);
    
    // Check if file with same hash already exists for this tenant
    $check_query = "SELECT document_id FROM tenant_documents WHERE tenant_code = ? AND file_hash = ? AND is_deleted = 0";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $tenant_code, $file_hash);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($existing) {
        json_error("This file has already been uploaded", 400);
    }
    
    // Generate stored file name
    $stored_file_name = $file_hash . '_' . time() . '.' . $file_extension;
    $upload_path = UPLOAD_DIR . $stored_file_name;

    // Move file to upload directory
    if (!move_uploaded_file($file_tmp, $upload_path)) {
        logActivity("Failed to move uploaded file");
        json_error("Failed to save file", 500);
    }

    // Insert document record into database
    $query = "
        INSERT INTO tenant_documents (
            tenant_code, 
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
        $tenant_code,
        $document_name,
        $document_type,
        $stored_file_name,
        $original_file_name,
        $file_size,
        $file_mime,
        $file_hash,
        $tenant_code
    );

    if (!$stmt->execute()) {
        // Remove uploaded file if database insert fails
        unlink($upload_path);
        throw new Exception("Failed to save document record: " . $stmt->error);
    }

    $document_id = $stmt->insert_id;
    $stmt->close();

    logActivity("Document uploaded successfully - ID: {$document_id}, Hash: {$file_hash}");

    //Create notification after successful document upload
    createDocumentNotification($conn, $tenant_code, $document_name, 'uploaded');
    json_success([
        'document_id' => $document_id,
        'document_name' => $document_name,
        'file_size' => $file_size,
        'uploaded_at' => date('Y-m-d H:i:s')
    ], "Document uploaded successfully");

} catch (Exception $e) {
    logActivity("Error in upload_document: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>