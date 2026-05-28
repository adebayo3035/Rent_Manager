<?php
// download_document.php - Download a client document

session_start();
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

define('UPLOAD_DIR', __DIR__ . '/../client_documents/');

// Generate unique request ID for tracking
$requestId = uniqid('client_download_', true);
logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ========== DOWNLOAD DOCUMENT - START ==========");
logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Script: " . __FILE__);
logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['client_code'])) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: No client code in session");
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Session data: " . json_encode(array_keys($_SESSION)));
        header('Location: ../login.php');
        exit();
    }
    
    $client_code = $_SESSION['client_code'];
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 1 - Authentication passed. Client Code: {$client_code}");

    // ==================== STEP 2: CHECK USER ROLE ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 2: Checking user role");
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Client') {
        $userRole = $_SESSION['role'] ?? 'none';
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: Unauthorized access - Role: {$userRole}");
        header('HTTP/1.0 403 Forbidden');
        exit('Unauthorized access');
    }
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 2 - Role validation passed: Client");

    // ==================== STEP 3: VALIDATE DOCUMENT ID ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 3: Validating document ID");
    
    $document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 3.1 - Document ID from request: {$document_id}");

    if ($document_id <= 0) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: Invalid document ID - Value: {$document_id}");
        header('HTTP/1.0 400 Bad Request');
        exit('Invalid document ID');
    }
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 3 - Document ID validated: {$document_id}");

    // ==================== STEP 4: FETCH DOCUMENT DETAILS ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 4: Fetching document details from database");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Client Code: {$client_code}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Document ID: {$document_id}");
    
    $query = "
        SELECT file_name, original_file_name, file_type, client_code, document_name
        FROM client_documents
        WHERE document_id = ? AND is_deleted = 0
    ";
    
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 4.1 - Query: " . preg_replace('/\s+/', ' ', trim($query)));
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: Prepare failed - " . $conn->error);
        header('HTTP/1.0 500 Internal Server Error');
        exit('Database error');
    }
    
    $stmt->bind_param("i", $document_id);
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 4.2 - Query executed, binding parameter: document_id = {$document_id}");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();
    
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 4.3 - Query result: " . ($document ? "Document found" : "Document not found"));

    if (!$document) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: Document not found or deleted - ID: {$document_id}");
        header('HTTP/1.0 404 Not Found');
        exit('Document not found');
    }
    
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 4.4 - Document details retrieved:");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - document_name: {$document['document_name']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - original_file_name: {$document['original_file_name']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - file_type: {$document['file_type']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - file_name (stored): {$document['file_name']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - document_owner: {$document['client_code']}");

    // ==================== STEP 5: VERIFY OWNERSHIP ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 5: Verifying document ownership");
    
    if ($document['client_code'] !== $client_code) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: Ownership mismatch");
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Document owner: {$document['client_code']}");
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Requesting client: {$client_code}");
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Document ID: {$document_id}");
        header('HTTP/1.0 403 Forbidden');
        exit('Unauthorized access');
    }
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 5 - Ownership verified successfully");

    // ==================== STEP 6: CHECK PHYSICAL FILE ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 6: Checking physical file existence");
    
    $file_path = UPLOAD_DIR . $document['file_name'];
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 6.1 - Expected file path: {$file_path}");
    
    // Check if directory exists
    if (!is_dir(UPLOAD_DIR)) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: Upload directory does not exist: {$UPLOAD_DIR}");
        header('HTTP/1.0 404 Not Found');
        exit('File not found');
    }
    
    if (!file_exists($file_path)) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: File not found on server");
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Path: {$file_path}");
        
        // Try to list directory contents for debugging
        $files = scandir(UPLOAD_DIR);
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Files in directory: " . implode(', ', array_slice($files, 2, 10)));
        
        header('HTTP/1.0 404 Not Found');
        exit('File not found');
    }
    
    $file_size = filesize($file_path);
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 6.2 - File found, size: {$file_size} bytes (" . round($file_size / 1024, 2) . " KB)");

    // ==================== STEP 7: SET DOWNLOAD HEADERS ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 7: Setting download headers");
    
    $encoded_filename = urlencode($document['original_file_name']);
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 7.1 - Encoded filename: {$encoded_filename}");
    
    header('Content-Type: ' . $document['file_type']);
    header('Content-Disposition: attachment; filename="' . $encoded_filename . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 7.2 - Headers set successfully");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Content-Type: {$document['file_type']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Content-Length: {$file_size}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Filename: {$document['original_file_name']}");

    // ==================== STEP 8: CLEAR OUTPUT BUFFER ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 8: Cleaning output buffer");
    
    $buffer_level = ob_get_level();
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 8.1 - Current buffer level: {$buffer_level}");
    
    if ($buffer_level) {
        for ($i = 0; $i < $buffer_level; $i++) {
            ob_end_clean();
            logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Cleared buffer level " . ($i + 1));
        }
    }
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 8.2 - Output buffer cleaned");

    // ==================== STEP 9: OUTPUT FILE ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 9: Outputting file content");
    
    $read_result = readfile($file_path);
    
    if ($read_result === false) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ERROR: readfile() failed");
        header('HTTP/1.0 500 Internal Server Error');
        exit('Failed to read file');
    }
    
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 9.1 - File output successful, bytes sent: {$read_result}");

    // ==================== STEP 10: LOG COMPLETION ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Step 10: Download completed successfully");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Document downloaded - ID: {$document_id}, Name: {$document['document_name']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Client: {$client_code}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - File size: {$file_size} bytes");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}]   - Original filename: {$document['original_file_name']}");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ========== DOWNLOAD DOCUMENT - SUCCESS ==========");

} catch (Exception $e) {
    // ==================== ERROR HANDLING ====================
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] ========== DOWNLOAD DOCUMENT - ERROR ==========");
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Error Type: " . get_class($e));
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Error Line: " . $e->getLine());
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Stack Trace: " . $e->getTraceAsString());
    
    // Check for specific error conditions
    if (strpos($e->getMessage(), "client_documents") !== false) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] HINT: The 'client_documents' table might not exist or have wrong structure");
    }
    
    if (strpos($e->getMessage(), "permission") !== false) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] HINT: File permission issue - check read permissions on the file");
    }
    
    header('HTTP/1.0 500 Internal Server Error');
    exit('An error occurred while processing your request');
} finally {
    // ==================== CLEANUP ====================
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Closing database statement");
        $stmt->close();
    }
    logActivity("[CLIENT_DOWNLOAD] [ID:{$requestId}] Script execution completed");
}
?>