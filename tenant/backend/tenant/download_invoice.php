<?php
// tenant/backend/tenant/download_invoice.php
// Proxy: lets tenant download their own invoice stored in admin module

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }
    $tenant_code = $_SESSION['tenant_code'];
    $document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
    
    if (!$document_id) {
        $_SESSION['error'] = "Invalid invoice";
        json_error("Invalid Invoice Number", 404);
        // header('Location: ../documents.php');
        exit();
    }
    
    // Verify document belongs to this tenant
    $query = "
        SELECT * FROM tenant_documents 
        WHERE document_id = ? 
        AND tenant_code = ? 
        AND document_type = 'INVOICE'
        AND is_deleted = 0
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $document_id, $tenant_code);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$document) {
        $_SESSION['error'] = "Invoice not found";
        header('Location: ../documents.php');
        exit();
    }
    
    // Path to admin's invoice folder
    $file_path = __DIR__ . '/../../../admin/backend/tenant_documents/invoices/' . $document['file_name'];
    
    if (!file_exists($file_path)) {
        $_SESSION['error'] = "Invoice file not available";
        header('Location: ../documents.php');
        exit();
    }
    
    logActivity("Tenant {$tenant_code} downloading invoice {$document_id}");
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $document['original_file_name'] . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit();
    
} catch (Exception $e) {
    logActivity("ERROR in tenant download_invoice: " . $e->getMessage());
    header('Location: ../documents.php');
    exit();
}