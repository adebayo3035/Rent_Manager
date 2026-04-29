<?php
// download_document.php - Download a tenant document

session_start();
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

define('UPLOAD_DIR', __DIR__ . '/../tenant_documents/');

logActivity("========== DOWNLOAD DOCUMENT - START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("Not logged in");
        header('Location: ../login.php');
        exit();
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("Unauthorized access");
        header('HTTP/1.0 403 Forbidden');
        exit('Unauthorized access');
    }

    $tenant_code = $_SESSION['tenant_code'];
    $document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;

    if ($document_id <= 0) {
        logActivity("Invalid document ID: {$document_id}");
        header('HTTP/1.0 400 Bad Request');
        exit('Invalid document ID');
    }

    logActivity("Tenant Code: {$tenant_code}, Document ID: {$document_id}");

    // Get document details
    $query = "
        SELECT file_name, original_file_name, file_type, tenant_code, document_name
        FROM tenant_documents
        WHERE document_id = ? AND is_deleted = 0
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();

    if (!$document) {
        logActivity("Document not found: {$document_id}");
        header('HTTP/1.0 404 Not Found');
        exit('Document not found');
    }

    // Verify ownership
    if ($document['tenant_code'] !== $tenant_code) {
        logActivity("Unauthorized download attempt by tenant: {$tenant_code} for document: {$document_id}");
        header('HTTP/1.0 403 Forbidden');
        exit('Unauthorized access');
    }

    $file_path = UPLOAD_DIR . $document['file_name'];

    if (!file_exists($file_path)) {
        logActivity("File not found on server: {$file_path}");
        header('HTTP/1.0 404 Not Found');
        exit('File not found');
    }

    // Set headers for download
    header('Content-Type: ' . $document['file_type']);
    header('Content-Disposition: attachment; filename="' . urlencode($document['original_file_name']) . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Clear output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Read file and output
    readfile($file_path);
    
    logActivity("Document downloaded successfully - ID: {$document_id}");

} catch (Exception $e) {
    logActivity("Error in download_document: " . $e->getMessage());
    header('HTTP/1.0 500 Internal Server Error');
    exit('An error occurred while processing your request');
}
?>