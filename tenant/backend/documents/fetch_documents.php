<?php
// fetch_documents.php - Fetch all documents for the logged-in tenant

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

logActivity("========== FETCH DOCUMENTS - START ==========");

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

    // Fetch documents
    $query = "
        SELECT 
            document_id,
            document_name,
            document_type,
            original_file_name,
            file_size,
            file_type,
            file_hash,
            uploaded_at,
            uploaded_by
        FROM tenant_documents
        WHERE tenant_code = ? AND is_deleted = 0
        ORDER BY uploaded_at DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = [
            'document_id' => (int)$row['document_id'],
            'document_name' => $row['document_name'],
            'document_type' => $row['document_type'],
            'original_file_name' => $row['original_file_name'],
            'file_size' => (int)$row['file_size'],
            'file_type' => $row['file_type'],
            'uploaded_at' => $row['uploaded_at']
        ];
    }
    $stmt->close();

    logActivity("Found " . count($documents) . " documents");

    json_success($documents, "Documents retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_documents: " . $e->getMessage());
    json_error("Failed to fetch documents", 500);
}
?>