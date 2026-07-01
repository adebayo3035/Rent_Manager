<?php
// fetch_documents.php - Fetch all documents for the logged-in client

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('client_docs_', true);
logActivity("[CLIENT_DOCS] [ID:{$requestId}] ========== FETCH DOCUMENTS - START ==========");
logActivity("[CLIENT_DOCS] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[CLIENT_DOCS] [ID:{$requestId}] Script: " . __FILE__);

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['client_code'])) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] ERROR: No client code in session");
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] Session data: " . json_encode(array_keys($_SESSION)));
        json_error("Not logged in", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 1 - Authentication passed. Client Code: {$client_code}");

    // ==================== STEP 2: CHECK USER ROLE ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 2: Checking user role");
    
    if (!isset($_SESSION['role'])) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] ERROR: Role not set in session");
        json_error("Unauthorized access - Role not set", 403);
    }
    
    $userRole = $_SESSION['role'];
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 2 - User role found: {$userRole}");
    
    if ($userRole !== 'Client') {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] ERROR: Unauthorized access - Expected 'Client', got '{$userRole}'");
        json_error("Unauthorized access - Client role required", 403);
    }
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 2 - Role validation passed");

    // ==================== STEP 3: DATABASE CONNECTION CHECK ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 3: Checking database connection");
    
    if (!$conn || $conn->connect_errno) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] ERROR: Database connection failed");
        if ($conn) {
            logActivity("[CLIENT_DOCS] [ID:{$requestId}] Connection error: " . $conn->connect_error);
        }
        json_error("Database connection error", 500);
    }
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 3 - Database connection OK");

    // ==================== STEP 4: PREPARE QUERY ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 4: Preparing query to fetch documents");
    
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
        FROM client_documents
        WHERE client_code = ? AND is_deleted = 0
        ORDER BY uploaded_at DESC
    ";
    
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 4.1 - Query: " . preg_replace('/\s+/', ' ', trim($query)));

    // ==================== STEP 5: EXECUTE QUERY ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 5: Executing query for client_code: {$client_code}");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] ERROR: Prepare failed - " . $conn->error);
        json_error("Database prepare error: " . $conn->error, 500);
    }
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 5.1 - Statement prepared successfully");
    
    $stmt->bind_param("s", $client_code);
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 5.2 - Parameters bound: client_code = {$client_code}");
    
    if (!$stmt->execute()) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] ERROR: Execute failed - " . $stmt->error);
        json_error("Database execute error: " . $stmt->error, 500);
    }
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 5.3 - Query executed successfully");

    // ==================== STEP 6: PROCESS RESULTS ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 6: Processing query results");
    
    $result = $stmt->get_result();
    $rowCount = $result->num_rows;
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 6.1 - Number of rows found: {$rowCount}");

    $documents = [];
    $index = 0;
    
    while ($row = $result->fetch_assoc()) {
        $index++;
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 6.2.{$index} - Processing document ID: {$row['document_id']}");
        logActivity("[CLIENT_DOCS] [ID:{$requestId}]   - document_name: {$row['document_name']}");
        logActivity("[CLIENT_DOCS] [ID:{$requestId}]   - document_type: {$row['document_type']}");
        logActivity("[CLIENT_DOCS] [ID:{$requestId}]   - file_size: {$row['file_size']} bytes");
        logActivity("[CLIENT_DOCS] [ID:{$requestId}]   - uploaded_at: {$row['uploaded_at']}");
        
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
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 6.3 - Finished processing. Total documents: " . count($documents));

    // ==================== STEP 7: VERIFY TABLE EXISTS (DEBUG) ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 7: Verifying client_documents table structure");
    
    $tableCheckQuery = "SHOW TABLES LIKE 'client_documents'";
    $tableCheck = $conn->query($tableCheckQuery);
    
    if ($tableCheck->num_rows > 0) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 7.1 - client_documents table exists");
        
        // Get column count for debugging
        $columnQuery = "SHOW COLUMNS FROM client_documents";
        $columnResult = $conn->query($columnQuery);
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 7.2 - Table has {$columnResult->num_rows} columns");
    } else {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] WARNING: client_documents table does not exist!");
    }

    // ==================== STEP 8: LOG COMPLETION ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Step 8: Preparing success response");
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Found " . count($documents) . " documents for client: {$client_code}");
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] ========== FETCH DOCUMENTS - SUCCESS ==========");
    
    json_success($documents, "Documents retrieved successfully");

} catch (Exception $e) {
    // ==================== ERROR HANDLING ====================
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] ========== FETCH DOCUMENTS - ERROR ==========");
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Error Type: " . get_class($e));
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Error Line: " . $e->getLine());
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Stack Trace: " . $e->getTraceAsString());
    
    // Check for specific error conditions
    if (strpos($e->getMessage(), "client_documents") !== false) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] HINT: The 'client_documents' table might not exist or have wrong structure");
    }
    
    if (strpos($e->getMessage(), "prepare") !== false) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] HINT: SQL prepare failed - check table structure and column names");
    }
    
    json_error("Failed to fetch documents: " . $e->getMessage(), 500);
} finally {
    // ==================== CLEANUP ====================
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        logActivity("[CLIENT_DOCS] [ID:{$requestId}] Cleaning up database connection");
        // Note: Don't close connection here if it might be needed elsewhere
        // $conn->close();
    }
    logActivity("[CLIENT_DOCS] [ID:{$requestId}] Script execution completed");
}
?>