<?php
// report.php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate request ID for tracking
$requestId = uniqid('sql_query_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Check if user is authenticated and authorized
if (!isset($_SESSION['unique_id']) || !isset($_SESSION['role'])) {
    logActivity("[SQL_QUERY_UNAUTH] [ID:{$requestId}] [IP:{$ipAddress}] Unauthenticated access attempt");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['unique_id'];
$firstname = $_SESSION['firstname'] ?? '';
$lastname = $_SESSION['lastname'] ?? '';
$username = trim($firstname . ' ' . $lastname);
$userRole = $_SESSION['role'];

// Only allow specific roles
$allowedRoles = ['Super Admin', 'Admin'];
if (!in_array($userRole, $allowedRoles)) {
    logActivity("[SQL_QUERY_UNAUTHORIZED] [ID:{$requestId}] [IP:{$ipAddress}] [User:{$userId}] [Role:{$userRole}] Unauthorized role attempted SQL query");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

logActivity("[SQL_QUERY_START] [ID:{$requestId}] [IP:{$ipAddress}] [User:{$userId}] [Role:{$userRole}] SQL query runner accessed");

class SecureQueryRunner {
    private $conn;
    private $allowedUsers = ['Super Admin', 'Admin'];
    private $maxExecutionTime = 30; // Increased to 30 seconds
    private $maxRows = 10000;
    private $requestId;
    private $userId;
    
    public function __construct($conn, $requestId, $userId) {
        $this->conn = $conn;
        $this->requestId = $requestId;
        $this->userId = $userId;
    }
    
    public function executeQuery($userRole, $sql) {
        $startTime = microtime(true);
        
        // 1. Authentication & Authorization
        if (!in_array($userRole, $this->allowedUsers)) {
            logActivity("[SQL_QUERY_UNAUTHORIZED] [ID:{$this->requestId}] [User:{$this->userId}] Unauthorized role: {$userRole}");
            throw new Exception("Unauthorized access");
        }
        
        logActivity("[SQL_QUERY_RECEIVED] [ID:{$this->requestId}] [User:{$this->userId}] Query length: " . strlen($sql) . " chars");
        
        // 2. Log the attempt (before validation)
        $this->logQueryAttempt($sql, 'attempted');
        
        // 3. Validate SQL
        if (!$this->validateSQL($sql)) {
            logActivity("[SQL_QUERY_REJECTED] [ID:{$this->requestId}] [User:{$this->userId}] Query failed validation: " . substr($sql, 0, 200));
            $this->logQueryAttempt($sql, 'rejected');
            throw new Exception("Invalid SQL query: Contains forbidden keywords or is not a SELECT statement");
        }
        
        logActivity("[SQL_QUERY_VALIDATED] [ID:{$this->requestId}] [User:{$this->userId}] Query passed validation");
        
        // 4. Set limits
        $this->setQueryLimits();
        
        // 5. Execute query
        try {
            logActivity("[SQL_QUERY_EXECUTING] [ID:{$this->requestId}] [User:{$this->userId}] Executing query");
            
            $result = $this->conn->query($sql);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2); // in milliseconds
            
            if ($result === false) {
                $error = $this->conn->error;
                logActivity("[SQL_QUERY_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] [Time:{$executionTime}ms] MySQL Error: {$error}");
                $this->logQueryAttempt($sql, 'failed', 0, $executionTime, $error);
                throw new Exception("Query failed: " . $error);
            }
            
            // Get row count before fetching
            $rowCount = $result->num_rows;
            
            logActivity("[SQL_QUERY_SUCCESS] [ID:{$this->requestId}] [User:{$this->userId}] [Time:{$executionTime}ms] [Rows:{$rowCount}] Query executed successfully");
            $this->logQueryAttempt($sql, 'success', $rowCount, $executionTime);
            
            $formattedResults = $this->formatResults($result);
            
            // Additional log for large result sets
            if ($rowCount > 1000) {
                logActivity("[SQL_QUERY_LARGE_RESULT] [ID:{$this->requestId}] [User:{$this->userId}] Large result set: {$rowCount} rows");
            }
            
            return $formattedResults;
            
        } catch (Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            logActivity("[SQL_QUERY_EXCEPTION] [ID:{$this->requestId}] [User:{$this->userId}] [Time:{$executionTime}ms] Exception: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function validateSQL($sql) {
        $originalSql = $sql;
        $sql = strtolower(trim($sql));
        
        // Check query length
        if (strlen($sql) > 5000) {
            logActivity("[SQL_QUERY_TOO_LONG] [ID:{$this->requestId}] [User:{$this->userId}] Query too long: " . strlen($sql) . " chars");
            return false;
        }
        
        // BLOCK LIST - Dangerous operations
        $dangerousKeywords = [
            'drop ', 'delete ', 'truncate ', 'alter ', 'create ', 'insert ',
            'update ', 'grant ', 'revoke ', 'exec ', 'execute ', 'xp_', 'sp_',
            'shutdown', 'kill', 'union select', 'information_schema',
            'into outfile', 'into dumpfile', 'load_file', 'benchmark(',
            'sleep(', 'waitfor delay', '--', '/*', '*/', '#'
        ];
        
        foreach ($dangerousKeywords as $keyword) {
            if (strpos($sql, $keyword) !== false) {
                logActivity("[SQL_QUERY_DANGEROUS] [ID:{$this->requestId}] [User:{$this->userId}] Found dangerous keyword: {$keyword}");
                return false;
            }
        }
        
        // ALLOW LIST - Only SELECT queries (case insensitive)
        if (substr($sql, 0, 6) !== 'select') {
            // Check if it's SELECT with parentheses or comments before
            $cleanSql = preg_replace('/^(\s|\(|--|#|\/\*)+/', '', $sql);
            if (substr($cleanSql, 0, 6) !== 'select') {
                logActivity("[SQL_QUERY_NOT_SELECT] [ID:{$this->requestId}] [User:{$this->userId}] Not a SELECT query");
                return false;
            }
        }
        
        return true;
    }
    
    private function setQueryLimits() {
        // Set PHP execution time limit
        set_time_limit($this->maxExecutionTime);
        logActivity("[SQL_QUERY_SET_LIMITS] [ID:{$this->requestId}] [User:{$this->userId}] Set PHP time limit: {$this->maxExecutionTime}s");
        
        // Try to set MySQL execution time limit
        $mysqlVariables = [
            'max_execution_time',  // MySQL 5.7+
            'max_statement_time',  // Older MySQL
            'max_query_time'       // Some versions
        ];
        
        $timeLimitMs = $this->maxExecutionTime * 1000;
        $setSuccess = false;
        
        foreach ($mysqlVariables as $variable) {
            try {
                $query = "SET SESSION {$variable} = {$timeLimitMs}";
                if ($this->conn->query($query)) {
                    logActivity("[SQL_QUERY_MYSQL_LIMIT] [ID:{$this->requestId}] [User:{$this->userId}] Set MySQL {$variable} = {$timeLimitMs}ms");
                    $setSuccess = true;
                    break;
                }
            } catch (Exception $e) {
                // Try next variable
                continue;
            }
        }
        
        if (!$setSuccess) {
            logActivity("[SQL_QUERY_MYSQL_LIMIT_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] Could not set MySQL execution time limit");
        }
        
        // Set row limit
        try {
            $this->conn->query("SET SESSION sql_select_limit = " . $this->maxRows);
            logActivity("[SQL_QUERY_ROW_LIMIT] [ID:{$this->requestId}] [User:{$this->userId}] Set row limit: {$this->maxRows}");
        } catch (Exception $e) {
            logActivity("[SQL_QUERY_ROW_LIMIT_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] Could not set row limit: " . $e->getMessage());
        }
    }
    
    private function logQueryAttempt($sql, $status, $rowsReturned = 0, $executionTime = 0, $error = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sqlTruncated = strlen($sql) > 1000 ? substr($sql, 0, 1000) . '...' : $sql;
        
        try {
            // Create table if it doesn't exist
            $this->conn->query("
                CREATE TABLE IF NOT EXISTS query_audit_log (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    query TEXT NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    rows_returned INT DEFAULT 0,
                    execution_time_ms INT DEFAULT 0,
                    status VARCHAR(20) NOT NULL,
                    error_message TEXT,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_status (status),
                    INDEX idx_timestamp (timestamp)
                )
            ");
            
            $logStmt = $this->conn->prepare("
                INSERT INTO query_audit_log 
                (user_id, query, ip_address, user_agent, rows_returned, execution_time_ms, status, error_message) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $logStmt->bind_param(
                "isssiiss", 
                $this->userId, 
                $sqlTruncated, 
                $ip, 
                $userAgent,
                $rowsReturned,
                $executionTime,
                $status,
                $error
            );
            
            if ($logStmt->execute()) {
                logActivity("[SQL_QUERY_AUDIT_LOGGED] [ID:{$this->requestId}] [User:{$this->userId}] [Status:{$status}] Query logged to audit table");
            } else {
                logActivity("[SQL_QUERY_AUDIT_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] Failed to log to audit table: " . $logStmt->error);
            }
            
            $logStmt->close();
            
        } catch (Exception $e) {
            logActivity("[SQL_QUERY_AUDIT_ERROR] [ID:{$this->requestId}] [User:{$this->userId}] Audit logging error: " . $e->getMessage());
        }
    }
    
    private function formatResults($result) {
        $data = [];
        $columns = [];
        
        // Get column names
        $fields = $result->fetch_fields();
        foreach ($fields as $field) {
            $columns[] = $field->name;
        }
        
        logActivity("[SQL_QUERY_COLUMNS] [ID:{$this->requestId}] [User:{$this->userId}] Columns returned: " . implode(', ', $columns));
        
        // Get data
        $rowCount = 0;
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            $rowCount++;
            
            // Log progress for large datasets
            if ($rowCount % 1000 === 0) {
                logActivity("[SQL_QUERY_FETCH_PROGRESS] [ID:{$this->requestId}] [User:{$this->userId}] Fetched {$rowCount} rows...");
            }
            
            // Safety limit - don't fetch more than maxRows
            if ($rowCount >= $this->maxRows) {
                logActivity("[SQL_QUERY_MAX_ROWS] [ID:{$this->requestId}] [User:{$this->userId}] Reached maximum row limit: {$this->maxRows}");
                break;
            }
        }
        
        logActivity("[SQL_QUERY_FORMATTED] [ID:{$this->requestId}] [User:{$this->userId}] Formatting complete: {$rowCount} rows");
        
        return [
            'columns' => $columns,
            'data' => $data,
            'row_count' => $rowCount
        ];
    }
}

// Main execution
try {
    // Get POST data
    $input = file_get_contents('php://input');
    if (empty($input)) {
        logActivity("[SQL_QUERY_NO_DATA] [ID:{$requestId}] [User:{$userId}] No POST data received");
        throw new Exception("No data received");
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity("[SQL_QUERY_INVALID_JSON] [ID:{$requestId}] [User:{$userId}] Invalid JSON: " . json_last_error_msg());
        throw new Exception("Invalid JSON format");
    }
    
    if (!isset($data['query']) || empty(trim($data['query']))) {
        logActivity("[SQL_QUERY_EMPTY] [ID:{$requestId}] [User:{$userId}] Empty query received");
        throw new Exception("SQL query is required");
    }
    
    $sql = trim($data['query']);
    
    logActivity("[SQL_QUERY_PROCESSING] [ID:{$requestId}] [User:{$userId}] Processing query");
    
    // Execute query
    $queryRunner = new SecureQueryRunner($conn, $requestId, $userId);
    $results = $queryRunner->executeQuery($userRole, $sql);
    
    logActivity("[SQL_QUERY_COMPLETED] [ID:{$requestId}] [User:{$userId}] Query completed successfully, returning " . $results['row_count'] . " rows");
    
    // Ensure proper response format
    $response = [
        'success' => true,
        'data' => $results,
        'message' => 'Query executed successfully',
        'request_id' => $requestId,
        'row_count' => $results['row_count'],
        'generated_by' => $username
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    logActivity("[SQL_QUERY_ERROR] [ID:{$requestId}] [User:{$userId}] Final error: " . $e->getMessage());
    http_response_code(400);
    
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'request_id' => $requestId
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    logActivity("[SQL_QUERY_END] [ID:{$requestId}] [User:{$userId}] Request processing completed");
}
