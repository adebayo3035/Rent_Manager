<?php
// admin/backend/documents/download_document.php
// Serve a document file with role-based access

// Suppress PHP warnings from reaching the response body.
// Errors still get logged to Apache/PHP error log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/document_helper.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

logActivity("========== DOWNLOAD DOCUMENT (ADMIN) - START ==========");

/**
 * Send a plain-text error response and exit.
 * File-download endpoints cannot return JSON — the browser expects binary.
 */
function sendDownloadError($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

try {
    // ==================== AUTHENTICATION ====================
    // Explicitly check each key — do NOT assume they exist.
    if (empty($_SESSION['unique_id']) || empty($_SESSION['role'])) {
        logActivity("UNAUTHORIZED: No valid session for document download");
        sendDownloadError(401, "Your session has expired. Please log in again.");
    }

    $adminId  = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'];

    if (!in_array($userRole, ['Super Admin', 'Admin'], true)) {
        logActivity("FORBIDDEN: Role {$userRole} attempted document download");
        sendDownloadError(403, "You do not have permission to download documents.");
    }

    // ==================== VALIDATE INPUT ====================
    $documentId = (int)($_GET['document_id'] ?? 0);
    if ($documentId <= 0) {
        sendDownloadError(400, "Invalid document ID.");
    }

    // ==================== FETCH DOCUMENT (with role-based visibility) ====================
    [$visSQL, $visParams, $visTypes] = buildDocumentVisibilityFilter($adminId, $userRole);

    $stmt = $conn->prepare("
        SELECT d.file_name, d.original_file_name, d.file_type, d.storage_location
        FROM tenant_documents d
        WHERE d.document_id = ? AND d.is_deleted = 0 AND {$visSQL}
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $bindParams = array_merge([$documentId], $visParams);
    $bindTypes  = 'i' . $visTypes;
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        logActivity("NOT FOUND: Document #{$documentId} not visible to {$adminId} ({$userRole})");
        sendDownloadError(404, "Document not found or you do not have access to it.");
    }

    // ==================== RESOLVE PHYSICAL PATH ====================
    $dir  = resolveStorageDir($doc['storage_location']);
    $path = $dir . $doc['file_name'];

    if (!file_exists($path)) {
        logActivity("FILE MISSING: Path {$path} does not exist for document #{$documentId}");
        sendDownloadError(404, "The file for this document is not available on the server.");
    }

    // ==================== PATH TRAVERSAL PROTECTION ====================
    $real     = realpath($path);
    $realBase = realpath($dir);

    if (!$real || !$realBase || strpos($real, $realBase) !== 0) {
        logActivity("SECURITY: Path traversal attempt blocked for doc #{$documentId}");
        sendDownloadError(403, "Access denied.");
    }

    // ==================== SERVE THE FILE ====================
    logActivity("Admin {$adminId} ({$userRole}) downloading document #{$documentId}");

    // Clear output buffers so nothing corrupts the file stream
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Inline for PDFs and images; attachment for everything else
    $disposition = 'attachment';
    $isInline    = isset($_GET['inline']) && $_GET['inline'] === '1';
    $isInlineable = ($doc['file_type'] === 'application/pdf' 
                     || strpos($doc['file_type'], 'image/') === 0);

    if ($isInline && $isInlineable) {
        $disposition = 'inline';
    }

    header('Content-Type: ' . $doc['file_type']);
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($doc['original_file_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($path);
    exit;

} catch (Exception $e) {
    logActivity("ERROR in download_document: " . $e->getMessage());
    sendDownloadError(500, "An error occurred while processing your download. Please try again.");
}