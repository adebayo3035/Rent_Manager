<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Generate unique request ID for tracing
$requestId = uniqid('reactivation_req_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Log request start
logActivity("[REACTIVATION_REQUEST_START] [ID:{$requestId}] [IP:{$ipAddress}] Account Reactivation Request Started - Method: {$_SERVER['REQUEST_METHOD']}");

class ReactivationService {
    private $conn;
    private $maxRequestsPerDay = 2;
    private $requestId;
    private $ipAddress;
    private $userAgent;

    public function __construct($conn, $requestId, $ipAddress, $userAgent) {
        $this->conn = $conn;
        $this->requestId = $requestId;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        
        logActivity("[REACTIVATION_SERVICE_INIT] [ID:{$requestId}] Service initialized");
    }

    public function submitReactivationRequest($data) {
        $logPrefix = "[SUBMIT_REACTIVATION] [ID:{$this->requestId}] [IP:{$this->ipAddress}]";
        
        logActivity("{$logPrefix} Starting reactivation request submission");
        
        // Validate required fields
        $requiredFields = ['email', 'user_type', 'otp', 'request_reason'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errorMsg = "Missing required field: {$field}";
                logActivity("[VALIDATION_ERROR] {$logPrefix} {$errorMsg}");
                
                return [
                    'success' => false,
                    'message' => "Missing required field: {$field}",
                    'code' => 400
                ];
            }
        }

        $email = trim($data['email']);
        $userType = trim($data['user_type']);
        $otp = trim($data['otp']);
        $requestReason = trim($data['request_reason']);
        
        logActivity("{$logPrefix} Validated fields - Email: {$email}, User Type: {$userType}, Reason length: " . strlen($requestReason));

        // Validate user type
        $validUserTypes = ['admin', 'agent', 'client', 'tenant'];
        if (!in_array($userType, $validUserTypes)) {
            $errorMsg = "Invalid user type: {$userType}";
            logActivity("[INVALID_USER_TYPE] {$logPrefix} {$errorMsg}");
            
            return [
                'success' => false,
                'message' => 'Invalid user type',
                'code' => 400
            ];
        }

        // Map user types to their respective tables
        $userTables = [
            'admin' => 'admin_tbl',
            'agent' => 'agents',
            'client' => 'clients',
            'tenant' => 'tenants'
        ];

        if (!isset($userTables[$userType])) {
            $errorMsg = "No table mapping for user type: {$userType}";
            logActivity("[TABLE_MAPPING_ERROR] {$logPrefix} {$errorMsg}");
            
            return [
                'success' => false,
                'message' => 'Invalid user type configuration',
                'code' => 500
            ];
        }

        $userTable = $userTables[$userType];
        logActivity("{$logPrefix} User table mapped: {$userTable}");

        // Step 1: Get user details
        $user = $this->getUserDetails($email, $userType, $userTable);
        if (!$user) {
            $errorMsg = "User not found or inactive - Email: {$email}, Type: {$userType}";
            logActivity("[USER_NOT_FOUND] {$logPrefix} {$errorMsg}");
            
            // Generic response for security
            return [
                'success' => false,
                'message' => 'Invalid request. Please check your details and try again.',
                'code' => 400
            ];
        }

        logActivity("{$logPrefix} User found - ID: {$user['id']}, Status: {$user['status']}");

        // Step 2: Check if user is already active
        if ($user['status'] != '0') {
            $errorMsg = "User is already active - Status: {$user['status']}";
            logActivity("[USER_ALREADY_ACTIVE] {$logPrefix} {$errorMsg}");
            
            return [
                'success' => false,
                'message' => 'Your account is already active.',
                'code' => 400
            ];
        }

        // Step 3: Verify OTP
        $otpVerification = $this->verifyOTP($user['id'], $userType, $otp, $email);
        if (!$otpVerification['success']) {
            logActivity("[OTP_VERIFICATION_FAILED] {$logPrefix} {$otpVerification['message']}");
            return $otpVerification;
        }

        $otpRequestId = $otpVerification['otp_request_id'];
        logActivity("{$logPrefix} OTP verified successfully - OTP Request ID: {$otpRequestId}");

        // Step 4: Check rate limiting (max 2 requests per day)
        $rateLimitCheck = $this->checkRateLimit($user['id'], $userType);
        if (!$rateLimitCheck['allowed']) {
            $errorMsg = "Rate limit exceeded - Requests today: {$rateLimitCheck['count']}";
            logActivity("[RATE_LIMIT_EXCEEDED] {$logPrefix} {$errorMsg}");
            
            return [
                'success' => false,
                'message' => 'You have reached the maximum number of reactivation requests for today. Please try again tomorrow.',
                'code' => 429
            ];
        }

        // Step 5: Check for existing pending request
        $existingRequest = $this->getExistingPendingRequest($user['id'], $userType);
        if ($existingRequest) {
            $errorMsg = "Existing pending request found - Request ID: {$existingRequest['id']}";
            logActivity("[EXISTING_PENDING_REQUEST] {$logPrefix} {$errorMsg}");
            
            return [
                'success' => false,
                'message' => 'You already have a pending reactivation request. Please wait for it to be reviewed.',
                'code' => 400,
                'existing_request_id' => $existingRequest['id']
            ];
        }

        // Step 6: Check last rejection (prevent immediate re-request)
        $lastRejection = $this->getLastRejection($user['id'], $userType);
        if ($lastRejection && $this->isWithinCooldownPeriod($lastRejection['created_at'])) {
            $errorMsg = "Within cooldown period after rejection - Last rejection: {$lastRejection['created_at']}";
            logActivity("[COOLDOWN_PERIOD] {$logPrefix} {$errorMsg}");
            
            $hoursRemaining = $this->getCooldownHoursRemaining($lastRejection['created_at']);
            return [
                'success' => false,
                'message' => "Your last request was rejected. Please wait {$hoursRemaining} hours before submitting a new request.",
                'code' => 429
            ];
        }
        //Generate unique request ID
        $request_id = random_unique_id();
        // Step 7: Submit the reactivation request
        return $this->createReactivationRequest(
            $request_id,
            $user['id'],
            $userType,
            $email,
            $otpRequestId,
            $requestReason
        );
    }

    private function getUserDetails($email, $userType, $userTable) {
    $logPrefix = "[USER_LOOKUP] [ID:{$this->requestId}]";
    
    // Define identifier column for each user type
    $idColumns = [
        'admin' => 'unique_id',
        'agent' => 'agent_code',
        'client' => 'client_code',
        'tenant' => 'tenant_code'
    ];
    
    // Validate user type
    if (!isset($idColumns[$userType])) {
        $errorMsg = "Invalid user type for ID mapping: {$userType}";
        logActivity("[INVALID_USER_TYPE] {$logPrefix} {$errorMsg}");
        return false;
    }
    
    $idColumn = $idColumns[$userType];
    
    // Adjust column names based on your schema
    // Different tables might have different column names for full_name and phone
    $nameColumn = 'firstname'; // Adjust if needed per table
    $phoneColumn = 'phone'; // Adjust if needed per table
    
    // Build query with dynamic column names
    $query = "SELECT {$idColumn} as id, status, email
              FROM {$userTable} WHERE email = ?";
    
    logActivity("{$logPrefix} Query: {$query} for email: {$email}");
    
    $stmt = $this->conn->prepare($query);
    if (!$stmt) {
        $errorMsg = "Failed to prepare user statement: " . $this->conn->error;
        logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
        return false;
    }

    $stmt->bind_param("s", $email);
    
    if (!$stmt->execute()) {
        $errorMsg = "Failed to execute user query: " . $stmt->error;
        logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logActivity("{$logPrefix} No user found with email: {$email}");
        $stmt->close();
        return false;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    logActivity("{$logPrefix} User retrieved - Type: {$userType}, ID: {$user['id']}, Status: {$user['status']}, E-mail: {$user['email']}");
    return $user;
}

    private function verifyOTP($userId, $userType, $otp, $email) {
        $logPrefix = "[OTP_VERIFICATION] [ID:{$this->requestId}]";
        
        logActivity("{$logPrefix} Verifying OTP for user ID: {$userId}, type: {$userType}");
        
        // Find the most recent valid OTP for this user
        $query = "SELECT id, otp, expires_at, status FROM otp_requests 
                  WHERE user_type = ? AND user_id = ? AND email = ? 
                  AND status = 'pending' AND expires_at > NOW()
                  ORDER BY created_at DESC LIMIT 1";
        
        logActivity("{$logPrefix} OTP Query: {$query}");
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            $errorMsg = "Failed to prepare OTP statement: " . $this->conn->error;
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
            return ['success' => false, 'message' => 'OTP verification failed'];
        }

        $stmt->bind_param("sss", $userType, $userId, $email);
        
        if (!$stmt->execute()) {
            $errorMsg = "Failed to execute OTP query: " . $stmt->error;
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
            $stmt->close();
            return ['success' => false, 'message' => 'OTP verification failed'];
        }

        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            logActivity("{$logPrefix} No valid OTP found for user ID: {$userId}");
            $stmt->close();
            return [
                'success' => false,
                'message' => 'Invalid or expired OTP. Please request a new OTP.',
                'code' => 400
            ];
        }

        $otpRecord = $result->fetch_assoc();
        $stmt->close();
        
        // Verify the OTP
        if (!password_verify($otp, $otpRecord['otp'])) {
            logActivity("{$logPrefix} OTP verification failed - OTP does not match");
            
            // Update OTP status to mark as used (failed attempt)
            $this->updateOTPStatus($otpRecord['id'], 'invalid_attempt');
            
            return [
                'success' => false,
                'message' => 'Invalid OTP. Please try again.',
                'code' => 400
            ];
        }

        logActivity("{$logPrefix} OTP verified successfully - OTP Request ID: {$otpRecord['id']}");
        
        // Mark OTP as used
        $this->updateOTPStatus($otpRecord['id'], 'verified');
        
        return [
            'success' => true,
            'otp_request_id' => $otpRecord['id'],
            'message' => 'OTP verified successfully'
        ];
    }

    private function updateOTPStatus($otpId, $status) {
        $logPrefix = "[UPDATE_OTP_STATUS] [ID:{$this->requestId}]";
        
        try {
            $query = "UPDATE otp_requests SET status = ?, date_last_updated = NOW() WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            
            if ($stmt) {
                $stmt->bind_param("si", $status, $otpId);
                $stmt->execute();
                $stmt->close();
                logActivity("{$logPrefix} Updated OTP ID {$otpId} status to '{$status}'");
            }
        } catch (Exception $e) {
            logActivity("[OTP_STATUS_UPDATE_ERROR] {$logPrefix} " . $e->getMessage());
        }
    }

    private function checkRateLimit($userId, $userType) {
        $logPrefix = "[RATE_LIMIT_CHECK] [ID:{$this->requestId}]";
        
        $query = "SELECT COUNT(*) as request_count FROM account_reactivation_requests 
                  WHERE user_type = ? AND user_id = ? 
                  AND DATE(created_at) = CURDATE() 
                  AND status IN ('pending', 'approved', 'rejected')";
        
        logActivity("{$logPrefix} Rate limit query: {$query}");
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} Failed to prepare rate limit statement");
            return ['allowed' => true, 'count' => 0]; // Fail open for safety
        }

        $stmt->bind_param("ss", $userType, $userId);
        
        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} Failed to execute rate limit query");
            $stmt->close();
            return ['allowed' => true, 'count' => 0];
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $count = $row['request_count'] ?? 0;
        $stmt->close();
        
        $allowed = ($count < $this->maxRequestsPerDay);
        
        logActivity("{$logPrefix} Rate limit check - Count: {$count}, Allowed: " . ($allowed ? 'Yes' : 'No'));
        
        return [
            'allowed' => $allowed,
            'count' => $count,
            'max_allowed' => $this->maxRequestsPerDay
        ];
    }

    private function getExistingPendingRequest($userId, $userType) {
        $logPrefix = "[EXISTING_REQUEST_CHECK] [ID:{$this->requestId}]";
        
        $query = "SELECT id, status, created_at FROM account_reactivation_requests 
                  WHERE user_type = ? AND user_id = ? AND status = 'pending' 
                  ORDER BY created_at DESC LIMIT 1";
        
        logActivity("{$logPrefix} Existing request query: {$query}");
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} Failed to prepare existing request statement");
            return null;
        }

        $stmt->bind_param("ss", $userType, $userId);
        
        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} Failed to execute existing request query");
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $request = $result->fetch_assoc();
            $stmt->close();
            logActivity("{$logPrefix} Found existing pending request - ID: {$request['id']}");
            return $request;
        }
        
        $stmt->close();
        logActivity("{$logPrefix} No existing pending request found");
        return null;
    }

    private function getLastRejection($userId, $userType) {
        $logPrefix = "[LAST_REJECTION_CHECK] [ID:{$this->requestId}]";
        
        $query = "SELECT id, created_at, rejection_reason FROM account_reactivation_requests 
                  WHERE user_type = ? AND user_id = ? AND status = 'rejected' 
                  ORDER BY created_at DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} Failed to prepare rejection statement");
            return null;
        }

        $stmt->bind_param("ss", $userType, $userId);
        
        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} Failed to execute rejection query");
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $rejection = $result->fetch_assoc();
            $stmt->close();
            logActivity("{$logPrefix} Last rejection found - ID: {$rejection['id']}, Date: {$rejection['created_at']}");
            return $rejection;
        }
        
        $stmt->close();
        logActivity("{$logPrefix} No previous rejections found");
        return null;
    }

    private function isWithinCooldownPeriod($rejectionDate) {
        $cooldownHours = 24; // 24-hour cooldown after rejection
        $rejectionTime = strtotime($rejectionDate);
        $currentTime = time();
        $hoursSinceRejection = ($currentTime - $rejectionTime) / 3600;
        
        return $hoursSinceRejection < $cooldownHours;
    }

    private function getCooldownHoursRemaining($rejectionDate) {
        $cooldownHours = 24;
        $rejectionTime = strtotime($rejectionDate);
        $currentTime = time();
        $hoursSinceRejection = ($currentTime - $rejectionTime) / 3600;
        $hoursRemaining = ceil($cooldownHours - $hoursSinceRejection);
        
        return max(1, $hoursRemaining); // At least 1 hour
    }

    private function createReactivationRequest($requestId, $userId, $userType, $email, $otpRequestId, $requestReason) {
        $logPrefix = "[CREATE_REQUEST] [ID:{$this->requestId}]";
        
        logActivity("{$logPrefix} Creating reactivation request for user ID: {$userId}");
        
        $this->conn->begin_transaction();
        
        try {
            $query = "INSERT INTO account_reactivation_requests 
                      (request_id, user_type, user_id, email, otp_request_id, request_reason, 
                       request_ip, request_user_agent, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            
            logActivity("{$logPrefix} Insert query: {$query}");
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare request statement: " . $this->conn->error);
            }

            $stmt->bind_param("ssssisss", 
                $requestId,
                $userType, 
                $userId, 
                $email, 
                $otpRequestId,
                $requestReason,
                $this->ipAddress,
                $this->userAgent
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to execute request insert: " . $stmt->error);
            }
            
            $requestId = $stmt->insert_id;
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows <= 0) {
                throw new Exception("No rows affected - request insert failed");
            }
            
            // Also update the OTP record with usage description
            $this->updateOTPUsageDescription($otpRequestId, "Account reactivation request #{$requestId}");
            
            $this->conn->commit();
            
            logActivity("[REACTIVATION_REQUEST_CREATED] {$logPrefix} Request created successfully - ID: {$requestId}");
            
            // Send notification to admins (optional)
            $this->notifyAdmins($userId, $userType, $email, $requestId);
            
            return [
                'success' => true,
                'message' => 'Reactivation request submitted successfully. Our team will review your request shortly.',
                'code' => 200,
                'request_id' => $requestId,
                'review_time' => '24-48 hours' // Estimated review time
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            
            $errorMsg = "Failed to create reactivation request: " . $e->getMessage();
            logActivity("[REQUEST_CREATION_FAILED] {$logPrefix} {$errorMsg}");
            
            return [
                'success' => false,
                'message' => 'Failed to submit reactivation request. Please try again.',
                'code' => 500
            ];
        }
    }

    private function updateOTPUsageDescription($otpId, $description) {
        $logPrefix = "[UPDATE_OTP_DESC] [ID:{$this->requestId}]";
        
        try {
            $query = "UPDATE otp_requests SET usage_description = ?, date_last_updated = NOW() WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            
            if ($stmt) {
                $stmt->bind_param("si", $description, $otpId);
                $stmt->execute();
                $stmt->close();
                logActivity("{$logPrefix} Updated OTP ID {$otpId} usage description");
            }
        } catch (Exception $e) {
            logActivity("[OTP_DESC_UPDATE_ERROR] {$logPrefix} " . $e->getMessage());
        }
    }

    private function notifyAdmins($userId, $userType, $email, $requestId) {
        $logPrefix = "[ADMIN_NOTIFICATION] [ID:{$this->requestId}]";
        
        try {
            // Get super admins
            $query = "SELECT email, firstname, lastname FROM admin_tbl WHERE role = 'Super Admin' AND status = '1'";
            $result = $this->conn->query($query);
            
            if ($result && $result->num_rows > 0) {
                $adminCount = 0;
                while ($admin = $result->fetch_assoc()) {
                    // In a real implementation, you would send email notifications here
                    logActivity("{$logPrefix} Would notify admin: {$admin['email']} about request ID: {$requestId}");
                    $adminCount++;
                }
                logActivity("{$logPrefix} Notified {$adminCount} super admins about new reactivation request");
            }
        } catch (Exception $e) {
            logActivity("[ADMIN_NOTIFICATION_ERROR] {$logPrefix} " . $e->getMessage());
        }
    }
}

// === Main Execution ===
logActivity("[MAIN_EXECUTION_START] [ID:{$requestId}] Starting main execution");

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    $errorMsg = "Database connection object not initialized";
    logActivity("[DB_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");
    
    http_response_code(503);
    echo json_encode([
        'success' => false, 
        'message' => 'Service temporarily unavailable. Please try again later.',
        'request_id' => $requestId
    ]);
    exit();
}

if ($conn->connect_error) {
    $errorMsg = "Database connection failed: " . $conn->connect_error;
    logActivity("[DB_CONNECTION_FAILED] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");
    
    http_response_code(503);
    echo json_encode([
        'success' => false, 
        'message' => 'Service temporarily unavailable. Please try again later.',
        'request_id' => $requestId
    ]);
    exit();
}

// Validate request method
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $errorMsg = "Invalid request method: " . $_SERVER["REQUEST_METHOD"];
    logActivity("[REQUEST_METHOD_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");
    
    http_response_code(405);
    echo json_encode([
        "success" => false, 
        "message" => "Method not allowed. Please use POST.",
        "request_id" => $requestId
    ]);
    exit();
}

// Get and validate JSON input
$input = file_get_contents("php://input");
if (empty($input)) {
    $errorMsg = "Empty request body received";
    logActivity("[EMPTY_REQUEST_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");
    
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'No input data received',
        'request_id' => $requestId
    ]);
    exit();
}

$data = json_decode($input, true);

// Validate JSON decoding
if (json_last_error() !== JSON_ERROR_NONE) {
    $errorMsg = "JSON decode error: " . json_last_error_msg();
    logActivity("[JSON_DECODE_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");
    
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid JSON data: ' . json_last_error_msg(),
        'request_id' => $requestId
    ]);
    exit();
}

// Create and use ReactivationService
try {
    $reactivationService = new ReactivationService($conn, $requestId, $ipAddress, $userAgent);
    $response = $reactivationService->submitReactivationRequest($data);
    
    http_response_code($response['code']);
    $response['request_id'] = $requestId;
    echo json_encode($response);
    
    logActivity("[REQUEST_COMPLETE] [ID:{$requestId}] Request processed successfully");
    
} catch (Exception $e) {
    $errorMsg = "Unexpected exception: " . $e->getMessage();
    logActivity("[UNEXPECTED_EXCEPTION] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again later.',
        'request_id' => $requestId
    ]);
}

// Close connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

logActivity("[SCRIPT_END] [ID:{$requestId}] Script execution completed");
