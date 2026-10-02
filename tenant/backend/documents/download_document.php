<?php
// download_document.php - Download a tenant document
// Handles documents stored in BOTH tenant and admin directories based on document_type

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

// ==================== STORAGE PATHS ====================
// Default storage: tenant module (for tenant-uploaded documents)
define('TENANT_UPLOAD_DIR', __DIR__ . '/../tenant_documents/');

// Admin storage: for admin-generated documents like invoices
// From: tenant/backend/tenant/  →  To: admin/backend/tenant_documents/invoices/
define('ADMIN_INVOICE_DIR', __DIR__ . '/../../../admin/backend/tenant_documents/invoices/');

// ==================== DOCUMENT TYPE → STORAGE MAPPING ====================
/**
 * Map each document_type to its storage directory.
 * Defaults to tenant directory if not specified here.
 */
function getDocumentStorageDir($document_type) {
    $storage_map = [
        'INVOICE' => ADMIN_INVOICE_DIR,
        // Add more admin-stored types here as needed:
        // 'RECEIPT' => ADMIN_INVOICE_DIR,
        // 'STATEMENT' => ADMIN_INVOICE_DIR,
    ];
    
    return $storage_map[$document_type] ?? TENANT_UPLOAD_DIR;
}

logActivity("========== DOWNLOAD DOCUMENT - START ==========");

try {
    // ==================== AUTHENTICATION ====================
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("Not logged in");
        header('Location: ../login.php');
        exit();
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("Unauthorized access - role mismatch");
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

    // ==================== FETCH DOCUMENT RECORD ====================
    $query = "
        SELECT 
            document_id,
            file_name, 
            original_file_name, 
            file_type, 
            tenant_code, 
            document_name,
            document_type
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

    // ==================== VERIFY OWNERSHIP ====================
    if ($document['tenant_code'] !== $tenant_code) {
        logActivity("Unauthorized download attempt by tenant: {$tenant_code} for document: {$document_id}");
        header('HTTP/1.0 403 Forbidden');
        exit('Unauthorized access');
    }

    // ==================== RESOLVE FILE PATH BASED ON DOCUMENT TYPE ====================
    $document_type = $document['document_type'] ?? 'OTHER';
    $storage_dir = getDocumentStorageDir($document_type);
    $file_path = $storage_dir . $document['file_name'];

    logActivity("Document type: {$document_type} | Storage: " . basename($storage_dir) . " | File: {$document['file_name']}");

    // ==================== VERIFY FILE EXISTS ====================
    if (!file_exists($file_path)) {
        logActivity("File not found on server: {$file_path}");
        
        // Fallback: try the other directory as a safety net
        // (handles cases where document_type was not set correctly in the past)
        $fallback_dirs = [TENANT_UPLOAD_DIR, ADMIN_INVOICE_DIR];
        $found = false;
        
        foreach ($fallback_dirs as $dir) {
            if ($dir === $storage_dir) continue; // skip the one we already checked
            
            $fallback_path = $dir . $document['file_name'];
            if (file_exists($fallback_path)) {
                logActivity("Fallback found: {$fallback_path}");
                $file_path = $fallback_path;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            header('HTTP/1.0 404 Not Found');
            exit('File not found');
        }
    }

    // ==================== SECURITY: PATH TRAVERSAL CHECK ====================
    // Ensure the resolved path stays within allowed directories
    $real_file = realpath($file_path);
    $allowed_dirs = [realpath(TENANT_UPLOAD_DIR), realpath(ADMIN_INVOICE_DIR)];
    $is_safe = false;
    
    foreach ($allowed_dirs as $allowed) {
        if ($allowed && $real_file && strpos($real_file, $allowed) === 0) {
            $is_safe = true;
            break;
        }
    }
    
    if (!$is_safe) {
        logActivity("SECURITY: Path traversal attempt blocked: {$file_path}");
        header('HTTP/1.0 403 Forbidden');
        exit('Access denied');
    }

    // ==================== SERVE FILE ====================
    // Clear any output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $document['file_type']);
    header('Content-Disposition: attachment; filename="' . urlencode($document['original_file_name']) . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($file_path);
    
    logActivity("Document downloaded successfully - ID: {$document_id}, Type: {$document_type}");

} catch (Exception $e) {
    logActivity("Error in download_document: " . $e->getMessage());
    header('HTTP/1.0 500 Internal Server Error');
    exit('An error occurred while processing your request');
}