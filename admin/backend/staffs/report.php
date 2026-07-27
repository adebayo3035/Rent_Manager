<?php
// report.php - Secure SQL Query Runner (Complete Version)
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

// Set JSON header
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON input'
    ]);
    exit;
}

// ==================== CSRF VALIDATION ====================
$csrfToken = $input['csrf_token'] ?? null;
$tokenId = $input['token_id'] ?? null;
$formName = 'generic_reporting_form';

logActivity("[CSRF_TOKEN_VALIDATION] Request received for CSRF Token Validation");

// Validate CSRF
if (!$csrfToken || !$tokenId) {
    echo json_encode([
        'success' => false,
        'message' => 'Security token required'
    ]);
    exit;
}
logActivity("CSRF Token and Token ID passed successfully from Client");

if ($tokenId !== $formName) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token ID'
    ]);
    exit;
}

logActivity("[CSRF_TOKEN_VALIDATION] About to validate CSRF token passed from Client");
if (!validateCsrfToken($csrfToken, $formName)) {
    echo json_encode([
        'success' => false,
        'message' => 'Security token invalid or expired'
    ]);
    exit;
}
logActivity("[CSRF_TOKEN_VALIDATION] CSRF Token validated successfully");

// Consume token after use (one-time use)
unset($_SESSION['csrf_tokens'][$formName]);

// ==================== AUTHENTICATION & AUTHORIZATION ====================
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

// ==================== GET QUERY AND TITLE ====================
$sql = isset($input['query']) ? trim($input['query']) : '';
$queryTitle = isset($input['query_title']) ? trim($input['query_title']) : 'Untitled Report';
$format = isset($input['format']) ? trim($input['format']) : 'json';

if (empty($sql)) {
    logActivity("[SQL_QUERY_EMPTY] [ID:{$requestId}] [User:{$userId}] Empty query received");
    echo json_encode(['success' => false, 'message' => 'SQL query is required']);
    exit();
}

logActivity("[SQL_QUERY_TITLE] [ID:{$requestId}] [User:{$userId}] Title: {$queryTitle}");
logActivity("[SQL_QUERY_PREVIEW] [ID:{$requestId}] [User:{$userId}] Query preview: " . substr($sql, 0, 200) . "...");

// ==================== SECURE QUERY RUNNER CLASS ====================
class SecureQueryRunner
{
    private $conn;
    private $allowedUsers = ['Super Admin', 'Admin'];
    private $maxExecutionTime = 30;
    private $maxRows = 10000;
    private $requestId;
    private $userId;
    private $userRole;
    private $username;
    private $queryTitle;

    public function __construct($conn, $requestId, $userId, $userRole, $username, $queryTitle)
    {
        $this->conn = $conn;
        $this->requestId = $requestId;
        $this->userId = $userId;
        $this->userRole = $userRole;
        $this->username = $username;
        $this->queryTitle = $queryTitle;
        
        logActivity("[SQL_QUERY_RUNNER_INIT] [ID:{$requestId}] [User:{$userId}] [Role:{$userRole}] Query runner initialized");
    }

    public function executeQuery($userRole, $sql)
    {
        $startTime = microtime(true);
        logActivity("[SQL_QUERY_EXECUTE_START] [ID:{$this->requestId}] [User:{$this->userId}] Starting query execution");

        // 1. Authentication & Authorization
        if (!in_array($userRole, $this->allowedUsers)) {
            logActivity("[SQL_QUERY_UNAUTHORIZED] [ID:{$this->requestId}] [User:{$this->userId}] Unauthorized role: {$userRole}");
            throw new Exception("Unauthorized access");
        }

        logActivity("[SQL_QUERY_RECEIVED] [ID:{$this->requestId}] [User:{$this->userId}] Query length: " . strlen($sql) . " chars");

        // 2. Log the attempt (before validation)
        $this->logQueryAttempt($sql, 'attempted');

        // 3. Validate SQL
        $validationResult = $this->validateSQL($sql);
        if (!$validationResult['valid']) {
            logActivity("[SQL_QUERY_REJECTED] [ID:{$this->requestId}] [User:{$this->userId}] Query failed validation: " . $validationResult['reason']);
            $this->logQueryAttempt($sql, 'rejected');
            throw new Exception("Invalid SQL query: " . $validationResult['reason']);
        }

        logActivity("[SQL_QUERY_VALIDATED] [ID:{$this->requestId}] [User:{$this->userId}] Query passed validation");

        // 4. Set limits
        $this->setQueryLimits();

        // 5. Execute query
        try {
            logActivity("[SQL_QUERY_EXECUTING] [ID:{$this->requestId}] [User:{$this->userId}] Executing query");

            $result = $this->conn->query($sql);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($result === false) {
                $error = $this->conn->error;
                logActivity("[SQL_QUERY_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] [Time:{$executionTime}ms] MySQL Error: {$error}");
                $this->logQueryAttempt($sql, 'failed', 0, $executionTime, $error);
                throw new Exception("Query failed: " . $error);
            }

            $rowCount = $result->num_rows;

            logActivity("[SQL_QUERY_SUCCESS] [ID:{$this->requestId}] [User:{$this->userId}] [Time:{$executionTime}ms] [Rows:{$rowCount}] Query executed successfully");
            $this->logQueryAttempt($sql, 'success', $rowCount, $executionTime);

            $formattedResults = $this->formatResults($result);

            if ($rowCount > 1000) {
                logActivity("[SQL_QUERY_LARGE_RESULT] [ID:{$this->requestId}] [User:{$this->userId}] Large result set: {$rowCount} rows");
            }

            logActivity("[SQL_QUERY_EXECUTE_COMPLETE] [ID:{$this->requestId}] [User:{$this->userId}] Query execution complete");
            return $formattedResults;

        } catch (Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            logActivity("[SQL_QUERY_EXCEPTION] [ID:{$this->requestId}] [User:{$this->userId}] [Time:{$executionTime}ms] Exception: " . $e->getMessage());
            throw $e;
        }
    }

    private function validateSQL($sql)
    {
        $originalSql = $sql;
        $sql = strtolower(trim($sql));

        // Check query length
        if (strlen($sql) > 5000) {
            $reason = "Query too long: " . strlen($sql) . " chars";
            logActivity("[SQL_QUERY_TOO_LONG] [ID:{$this->requestId}] [User:{$this->userId}] " . $reason);
            return ['valid' => false, 'reason' => $reason];
        }

        // ==================== INJECTION PATTERNS ====================
        $injectionPatterns = [
            '/\bunion\s+select\b/i',
            '/\bexec(\s|\()+.*\)/i',
            '/\bwaitfor\s+delay\b/i',
            '/\bfrom\s+(information_schema|mysql\.|sys\.|performance_schema)/i',
            '/\bdrop\s+(table|database)\b/i',
            '/\btruncate\s+table\b/i',
            '/\balter\s+table\b/i',
            '/\bcreate\s+(table|database)\b/i',
            '/\bselect\b.*\bfrom\b.*\bwhere\b.*\b(?:1=1|or\s+1=1)/i',
            '/\border\s+by\s+\d+/i',
            '/\/\*.*\*\/.*union/i',
            '/--.*union/i',
            '/#.*union/i',
            '/information_schema\.(tables|columns)/i',
        ];

        foreach ($injectionPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                $reason = "Injection pattern detected: {$pattern}";
                logActivity("[SQL_QUERY_INJECTION_DETECTED] [ID:{$this->requestId}] " . $reason);
                return ['valid' => false, 'reason' => $reason];
            }
        }

        // ==================== DANGEROUS KEYWORDS ====================
        $dangerousKeywords = [
            'drop ',
            'delete ',
            'truncate ',
            'alter ',
            'create ',
            'insert ',
            'update ',
            'grant ',
            'revoke ',
            'exec ',
            'execute ',
            'xp_',
            'sp_',
            'shutdown',
            'kill',
            'union select',
            'information_schema',
            'into outfile',
            'into dumpfile',
            'load_file',
            'benchmark(',
            'sleep(',
            'waitfor delay',
            '--',
            '/*',
            '*/',
            '#'
        ];

        foreach ($dangerousKeywords as $keyword) {
            if (strpos($sql, $keyword) !== false) {
                $reason = "Dangerous keyword found: {$keyword}";
                logActivity("[SQL_QUERY_DANGEROUS] [ID:{$this->requestId}] [User:{$this->userId}] " . $reason);
                return ['valid' => false, 'reason' => $reason];
            }
        }

        // ==================== BLOCK SELECT * ====================
        if (preg_match('/select\s+\*/i', $sql)) {
            $reason = "SELECT * not allowed";
            logActivity("[SQL_QUERY_SELECT_STAR] [ID:{$this->requestId}] [User:{$this->userId}] " . $reason);
            return ['valid' => false, 'reason' => $reason];
        }

        // ==================== ALLOW ONLY SELECT ====================
        if (substr($sql, 0, 6) !== 'select') {
            $cleanSql = preg_replace('/^(\s|\(|--|#|\/\*)+/', '', $sql);
            if (substr($cleanSql, 0, 6) !== 'select') {
                $reason = "Not a SELECT query";
                logActivity("[SQL_QUERY_NOT_SELECT] [ID:{$this->requestId}] [User:{$this->userId}] " . $reason);
                return ['valid' => false, 'reason' => $reason];
            }
        }

        // ==================== QUERY COMPLEXITY CHECK ====================
        $complexityScore = 0;
        $complexityScore += substr_count(strtolower($sql), 'join') * 3;
        $complexityScore += substr_count(strtolower($sql), 'where') * 2;
        $complexityScore += substr_count(strtolower($sql), 'or') * 1;
        $complexityScore += substr_count(strtolower($sql), 'and') * 1;
        $complexityScore += substr_count(strtolower($sql), 'substring') * 5;

        if ($complexityScore > 50) {
            $reason = "Query too complex: score {$complexityScore}";
            logActivity("[SQL_QUERY_TOO_COMPLEX] [ID:{$this->requestId}] [User:{$this->userId}] " . $reason);
            return ['valid' => false, 'reason' => $reason];
        }

        return ['valid' => true, 'reason' => 'OK'];
    }

    private function setQueryLimits()
    {
        // PHP execution time limit
        set_time_limit($this->maxExecutionTime);
        logActivity("[SQL_QUERY_SET_LIMITS] [ID:{$this->requestId}] [User:{$this->userId}] Set PHP time limit: {$this->maxExecutionTime}s");

        // MySQL execution time limit
        $mysqlVariables = [
            'max_execution_time',
            'max_statement_time',
            'max_query_time'
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
                continue;
            }
        }

        if (!$setSuccess) {
            logActivity("[SQL_QUERY_MYSQL_LIMIT_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] Could not set MySQL execution time limit");
        }

        // Row limit
        try {
            $this->conn->query("SET SESSION sql_select_limit = " . $this->maxRows);
            logActivity("[SQL_QUERY_ROW_LIMIT] [ID:{$this->requestId}] [User:{$this->userId}] Set row limit: {$this->maxRows}");
        } catch (Exception $e) {
            logActivity("[SQL_QUERY_ROW_LIMIT_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] Could not set row limit: " . $e->getMessage());
        }
    }

    private function logQueryAttempt($sql, $status, $rowsReturned = 0, $executionTime = 0, $error = null)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sqlTruncated = strlen($sql) > 1000 ? substr($sql, 0, 1000) . '...' : $sql;
        $queryTitle = $this->queryTitle ?? '';

        logActivity("[SQL_QUERY_LOG_ATTEMPT] [ID:{$this->requestId}] [User:{$this->userId}] [Status:{$status}] Logging query attempt");

        try {
            // Create table if it doesn't exist
            $this->conn->query("
                CREATE TABLE IF NOT EXISTS query_audit_log (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    request_id VARCHAR(50),
                    query_title VARCHAR(255),
                    user_role VARCHAR(50),
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
                (user_id, request_id, query_title, user_role, query, ip_address, user_agent, rows_returned, execution_time_ms, status, error_message) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$logStmt) {
                logActivity("[SQL_QUERY_AUDIT_PREPARE_FAILED] [ID:{$this->requestId}] [User:{$this->userId}] Prepare failed: " . $this->conn->error);
                return;
            }

            $logStmt->bind_param(
                "issssssiiss",
                $this->userId,
                $this->requestId,
                $queryTitle,
                $this->userRole,
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

    private function formatResults($result)
    {
        $data = [];
        $columns = [];

        // Get column names
        $fields = $result->fetch_fields();
        foreach ($fields as $field) {
            $columns[] = $field->name;
        }

        logActivity("[SQL_QUERY_COLUMNS] [ID:{$this->requestId}] [User:{$this->userId}] Columns returned: " . implode(', ', $columns));

        // ==================== SENSITIVE DATA REDACTION ====================
        $sensitiveColumns = ['password', 'secret_answer', 'token', 'otp', 'api_key', 'hash', 'salt', 'encrypted'];

        // Get data
        $rowCount = 0;
        while ($row = $result->fetch_assoc()) {
            // Sanitize sensitive data
            $sanitizedRow = [];
            foreach ($row as $key => $value) {
                $found = false;
                foreach ($sensitiveColumns as $sensitive) {
                    if (stripos($key, $sensitive) !== false) {
                        $sanitizedRow[$key] = '[REDACTED]';
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $sanitizedRow[$key] = $value;
                }
            }
            $data[] = $sanitizedRow;
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
            'row_count' => $rowCount,
            'execution_time' => null // Will be set by caller
        ];
    }
}

// ==================== MAIN EXECUTION ====================
try {
    logActivity("[SQL_QUERY_PROCESSING] [ID:{$requestId}] [User:{$userId}] Processing query");

    $queryRunner = new SecureQueryRunner($conn, $requestId, $userId, $userRole, $username, $queryTitle);
    $results = $queryRunner->executeQuery($userRole, $sql);

    logActivity("[SQL_QUERY_COMPLETED] [ID:{$requestId}] [User:{$userId}] Query completed successfully, returning " . $results['row_count'] . " rows");

    // ==================== RESPONSE ====================
    $response = [
        'success' => true,
        'data' => $results,
        'message' => 'Query executed successfully',
        'request_id' => $requestId,
        'row_count' => $results['row_count'],
        'generated_by' => $username,
        'query_title' => $queryTitle,
        'execution_time' => isset($results['execution_time']) ? $results['execution_time'] : null
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
?>