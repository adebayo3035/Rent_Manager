<?php
// admin/backend/tenants/download_invoice.php
// Allows admins to download tenant invoices

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION))
    session_start();
rateLimiter();

logActivity("========== ADMIN DOWNLOAD INVOICE - START ==========");

try {
    // Check authentication — admin only
    if (!isset($_SESSION['unique_id'])) {
        logActivity("ERROR: No admin session");
        header('Location: ../../login.php');
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $document_id = isset($_GET['document_id']) ? (int) $_GET['document_id'] : 0;

    if (!$document_id) {
        $_SESSION['error'] = "Invalid invoice ID";
        header('Location: ../../pages/tenants.php');
        exit();
    }

    // Fetch the document record
    $query = "
        SELECT 
            d.*,
            t.firstname, t.lastname
        FROM tenant_documents d
        JOIN tenants t ON d.tenant_code = t.tenant_code
        WHERE d.document_id = ?
        AND d.document_type = 'INVOICE'
        AND d.is_deleted = 0
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$document) {
        logActivity("ERROR: Invoice not found - ID: {$document_id}");
        $_SESSION['error'] = "Invoice not found";
        header('Location: ../../pages/tenants.php');
        exit();
    }

    // Build full path
    $file_path = __DIR__ . '/../tenant_documents/invoices/' . $document['file_name'];

    if (!file_exists($file_path)) {
        logActivity("ERROR: Invoice file missing: {$file_path}");
        $_SESSION['error'] = "Invoice file not found on server";
        header('Location: ../../pages/tenants.php');
        exit();
    }

    // Log download
    logActivity("Admin {$adminId} downloading invoice {$document['document_id']} for tenant {$document['tenant_code']}");

    // Serve the file
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $document['original_file_name'] . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($file_path);
    exit();

} catch (Exception $e) {
    logActivity("ERROR in admin download_invoice: " . $e->getMessage());
    $_SESSION['error'] = "Failed to download invoice";
    header('Location: ../../pages/tenants.php');
    exit();
}