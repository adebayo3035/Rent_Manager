<?php
// delete_document.php - Soft delete a document for the tenant and remove physical file

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

logActivity("========== DELETE DOCUMENT - START ==========");

define('UPLOAD_DIR', __DIR__ . '/../tenant_documents/');

try {
    // Step 1: Check authentication
    logActivity("Step 1: Checking authentication");
    
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("ERROR: No tenant code in session");
        json_error("Not logged in", 401);
    }

    // Step 2: Check user role
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    logActivity("Step 2 - Tenant authenticated: {$tenant_code}");

    // Step 3: Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    $document_id = isset($input['document_id']) ? (int)$input['document_id'] : 0;

    if ($document_id <= 0) {
        logActivity("ERROR: Invalid document ID: {$document_id}");
        json_error("Invalid document ID", 400);
    }
    logActivity("Step 3 - Document ID validated: {$document_id}");

    // Step 4: Get document details to verify ownership and get file path
    logActivity("Step 4: Fetching document details from database");
    
    $query = "
        SELECT document_id, file_name, tenant_code, document_name, original_file_name
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
        logActivity("ERROR: Document not found - ID: {$document_id}");
        json_error("Document not found", 404);
    }
    logActivity("Step 4 - Document found: {$document['document_name']} ({$document['original_file_name']})");

    // Step 5: Verify ownership
    logActivity("Step 5: Verifying document ownership");
    
    if ($document['tenant_code'] !== $tenant_code) {
        logActivity("ERROR: Unauthorized delete attempt - Tenant: {$tenant_code}, Document Owner: {$document['tenant_code']}");
        json_error("Unauthorized access", 403);
    }
    logActivity("Step 5 - Ownership verified");

    // Step 6: Get the physical file path
    $file_path = UPLOAD_DIR . $document['file_name'];
    logActivity("Step 6 - Physical file path: {$file_path}");

    // Step 7: Delete the physical file from disk
    $file_deleted = false;
    if (file_exists($file_path)) {
        logActivity("Step 7: Attempting to delete physical file");
        
        if (unlink($file_path)) {
            $file_deleted = true;
            logActivity("Step 7 - Physical file deleted successfully: {$document['file_name']}");
        } else {
            logActivity("WARNING: Failed to delete physical file: {$file_path}");
        }
    } else {
        logActivity("Step 7 - Physical file not found (already deleted or never existed): {$file_path}");
    }

    // Step 8: Soft delete the document record in database
    logActivity("Step 8: Updating database record (soft delete)");
    
    $delete_query = "
        UPDATE tenant_documents 
        SET is_deleted = 1, 
            deleted_at = NOW(), 
            deleted_by = ?,
            file_deleted = ?,
            file_deleted_at = NOW()
        WHERE document_id = ?
    ";

    $stmt = $conn->prepare($delete_query);
    $file_deleted_int = $file_deleted ? 1 : 0;
    $stmt->bind_param("sii", $tenant_code, $file_deleted_int, $document_id);

    if (!$stmt->execute()) {
        throw new Exception("Failed to delete document record: " . $stmt->error);
    }

    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    logActivity("Step 8 - Database record updated. Affected rows: {$affected_rows}");

    // Step 9: Log completion
    logActivity("Document deleted successfully - ID: {$document_id}
    , File deleted: " . ($file_deleted ? 'Yes' : 'No'));

    // send notification after successful document deletion
    createDocumentNotification($conn, $tenant_code, $document['document_name'], 'deleted');
    logActivity("========== DELETE DOCUMENT - END ==========");

    // Step 10: Return success response
    json_success([
        'document_id' => $document_id,
        'file_deleted' => $file_deleted
    ], "Document deleted successfully");

} catch (Exception $e) {
    logActivity("ERROR in delete_document: " . $e->getMessage());
    logActivity("Stack trace: " . $e->getTraceAsString());
    json_error("Failed to delete document: " . $e->getMessage(), 500);
}
?>