<?php
require_once __DIR__ . '/../../../mailer.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

// Error handling setup
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

// Generate unique request ID for tracing
$requestId = uniqid('otp_req_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Log request start
logActivity("[REQUEST_START] [ID:{$requestId}] [IP:{$ipAddress}] OTP Generation Request Started - Method: {$_SERVER['REQUEST_METHOD']}");

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

// Log successful DB connection
logActivity("[DB_CONNECTED] [ID:{$requestId}] Database connection established successfully");

class OTPService
{
    private $conn;
    private $userType;
    private $userTable;
    private $maxAttempts = 3;
    private $timeWindow = 300; // 5 minutes
    private $otpExpiryMinutes = 2;
    private $otpLength = 6;
    private $requestId;
    private $ipAddress;

    public function __construct($conn, $userType, $userTable, $requestId, $ipAddress)
    {
        $this->conn = $conn;
        $this->userType = $userType;
        $this->userTable = $userTable;
        $this->requestId = $requestId;
        $this->ipAddress = $ipAddress;

        logActivity("[OTP_SERVICE_INIT] [ID:{$requestId}] Service initialized for user_type: {$userType}, table: {$userTable}");
    }

    public function generateOTP($email, $title = "From Rent Manager")
    {
        $logPrefix = "[OTP_GENERATE] [ID:{$this->requestId}] [IP:{$this->ipAddress}]";

        logActivity("{$logPrefix} Starting OTP generation for email: {$email}, title: {$title}");

        // Validate input
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "Email validation failed - Invalid email format: {$email}";
            logActivity("[VALIDATION_ERROR] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Invalid email address',
                'code' => 400,
                'request_id' => $this->requestId
            ];
        }

        logActivity("{$logPrefix} Email validation passed: {$email}");

        // Get user user_id - check both id and status
        $user = $this->getUserByEmail($email);

        if (!$user) {
            $errorMsg = "User lookup failed - Email not found or inactive: {$email}";
            logActivity("[USER_NOT_FOUND] {$logPrefix} {$errorMsg}");

            // Generic success message to prevent email enumeration
            return [
                'success' => true,
                'message' => 'If your email is registered, you will receive an OTP shortly.',
                'code' => 200,
                'request_id' => $this->requestId
            ];
        }

        logActivity("{$logPrefix} User found - ID: {$user['id']}, Status: {$user['status']}");

        $cleanupResult = $this->updateExpiredPendingOTPs($user['id']);
        if ($cleanupResult['updated_count'] > 0) {
            logActivity("{$logPrefix} Cleaned up {$cleanupResult['updated_count']} expired OTPs for user: {$user['id']}");
        }

        // Check rate limiting
        $rateLimitResult = $this->isRateLimited($user['id']);
        if ($rateLimitResult === true) {
            $errorMsg = "Rate limit exceeded for user ID: {$user['id']}, email: {$email}";
            logActivity("[RATE_LIMIT_EXCEEDED] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Too many OTP requests. Please wait 5 minutes before trying again.',
                'code' => 429,
                'request_id' => $this->requestId
            ];
        } elseif ($rateLimitResult === null) {
            logActivity("[RATE_LIMIT_CHECK_ERROR] {$logPrefix} Rate limit check returned null, proceeding anyway");
        } else {
            logActivity("{$logPrefix} Rate limit check passed - attempts within limit");
        }

        // Check existing active OTP with cooldown
        $activeOTPCheck = $this->hasActiveOTP($user['id']);
        if ($activeOTPCheck['hasActive']) {
            logActivity("[ACTIVE_OTP_FOUND] {$logPrefix} Active OTP exists for user ID: {$user['id']}, seconds since last: {$activeOTPCheck['secondsSinceLast']}");

            // If recently sent, tell them to wait
            if ($activeOTPCheck['secondsSinceLast'] < 30) {
                $waitTime = 30 - $activeOTPCheck['secondsSinceLast'];
                $errorMsg = "OTP requested too soon - Wait time required: {$waitTime} seconds";
                logActivity("[OTP_COOLDOWN] {$logPrefix} {$errorMsg}");

                return [
                    'success' => false,
                    'message' => "Please wait {$waitTime} seconds before requesting a new OTP.",
                    'code' => 429,
                    'request_id' => $this->requestId
                ];
            }

            // Otherwise, just inform them to check email
            logActivity("[ACTIVE_OTP_EXISTS] {$logPrefix} Informing user to check existing OTP");
            return [
                'success' => true,
                'message' => 'An active OTP already exists. Please check your email.',
                'code' => 200,
                'request_id' => $this->requestId
            ];
        }

        logActivity("{$logPrefix} No active OTP found, proceeding to generate new OTP");


        // Generate and send OTP
        $result = $this->createAndSendOTP($user['id'], $email, $title);

        if ($result['success']) {
            logActivity("[OTP_GENERATION_SUCCESS] {$logPrefix} OTP generated successfully for email: {$email}, user ID: {$user['id']}");
        } else {
            $errorMsg = "OTP generation failed: {$result['message']}";
            logActivity("[OTP_GENERATION_FAILED] {$logPrefix} {$errorMsg}");
        }

        return array_merge($result, ['request_id' => $this->requestId]);
    }

    private function getUserByEmail($email)
    {
        $logPrefix = "[USER_LOOKUP] [ID:{$this->requestId}]";

        // Check both id and status - adjust column names based on your actual schema
        $query = "SELECT unique_id as id, status FROM {$this->userTable} WHERE email = ? AND status = '0'";
        logActivity("{$logPrefix} Preparing query: {$query} for email: {$email}");

        $stmt = $this->conn->prepare($query);

        if (!$stmt) {
            $errorMsg = "Failed to prepare user lookup statement: " . $this->conn->error;
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
            return false;
        }

        logActivity("{$logPrefix} Statement prepared successfully, binding parameters");

        $stmt->bind_param("s", $email);

        if (!$stmt->execute()) {
            $errorMsg = "Failed to execute user lookup: " . $stmt->error;
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
            $stmt->close();
            return false;
        }

        logActivity("{$logPrefix} Query executed successfully, fetching results");

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            logActivity("{$logPrefix} No user found with email: {$email}");
            $stmt->close();
            return false;
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        logActivity("{$logPrefix} User retrieved - ID: {$user['id']}, Status: {$user['status']}");
        return $user;
    }

    private function isRateLimited($uniqueId)
    {
        $logPrefix = "[RATE_LIMIT_CHECK] [ID:{$this->requestId}]";

        logActivity("{$logPrefix} Checking rate limit for user ID: {$uniqueId}");

        // Add small random delay to prevent timing attacks
        $delay = rand(10000, 50000);
        logActivity("{$logPrefix} Adding random delay: {$delay} microseconds");
        usleep($delay);

        $query = "SELECT COUNT(*) AS attempts FROM otp_requests WHERE user_type = ? AND user_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)";
        logActivity("{$logPrefix} Preparing rate limit query: {$query}");

        $stmt = $this->conn->prepare($query);

        if (!$stmt) {
            $errorMsg = "Failed to prepare rate limit statement: " . $this->conn->error;
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
            return null; // Return null on error
        }

        logActivity("{$logPrefix} Statement prepared, binding parameters: user_type={$this->userType}, user_id={$uniqueId}, timeWindow={$this->timeWindow}");

        $stmt->bind_param("ssi", $this->userType, $uniqueId, $this->timeWindow);

        if (!$stmt->execute()) {
            $errorMsg = "Failed to execute rate limit query: " . $stmt->error;
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
            $stmt->close();
            return null;
        }

        logActivity("{$logPrefix} Rate limit query executed, fetching results");

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $attempts = $row['attempts'] ?? 0;
        $stmt->close();

        logActivity("{$logPrefix} Found {$attempts} attempts in last {$this->timeWindow} seconds, max allowed: {$this->maxAttempts}");

        $isLimited = ($attempts >= $this->maxAttempts);
        if ($isLimited) {
            logActivity("{$logPrefix} RATE LIMIT EXCEEDED - User ID: {$uniqueId} has {$attempts} attempts");
        }

        return $isLimited;
    }

    private function updateExpiredPendingOTPs($uniqueId)
    {
        $logPrefix = "[UPDATE_EXPIRED_OTPS] [ID:{$this->requestId}]";

        logActivity("{$logPrefix} Checking and updating expired OTPs with pending status for user ID: {$uniqueId}");

        // Start transaction
        $this->conn->begin_transaction();

        try {
            // First, select all expired OTPs with pending status for this user
            $selectQuery = "SELECT id, created_at, expires_at, email 
                       FROM otp_requests 
                       WHERE user_type = ? 
                       AND user_id = ? 
                       AND status = 'pending' 
                       AND expires_at < NOW() 
                       ORDER BY created_at DESC";

            logActivity("{$logPrefix} Query to find expired pending OTPs: {$selectQuery}");

            $selectStmt = $this->conn->prepare($selectQuery);

            if (!$selectStmt) {
                $errorMsg = "Failed to prepare select statement: " . $this->conn->error;
                logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
                throw new Exception($errorMsg);
            }

            $selectStmt->bind_param("ss", $this->userType, $uniqueId);

            if (!$selectStmt->execute()) {
                $errorMsg = "Failed to execute select query: " . $selectStmt->error;
                logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
                $selectStmt->close();
                throw new Exception($errorMsg);
            }

            $result = $selectStmt->get_result();
            $expiredOTPs = [];

            while ($row = $result->fetch_assoc()) {
                $expiredOTPs[] = $row;
            }

            $selectStmt->close();

            $totalExpired = count($expiredOTPs);
            logActivity("{$logPrefix} Found {$totalExpired} expired OTPs with pending status");

            if ($totalExpired === 0) {
                $this->conn->commit();
                logActivity("{$logPrefix} No expired pending OTPs found, transaction committed");
                return [
                    'success' => true,
                    'message' => 'No expired pending OTPs found',
                    'updated_count' => 0
                ];
            }

            // Update all expired OTPs to 'expired' status
            $updateQuery = "UPDATE otp_requests 
                       SET status = 'expired', 
                           usage_description = 'UNUSED (Auto-expired)',
                           date_last_updated = NOW()
                       WHERE user_type = ? 
                       AND user_id = ? 
                       AND status = 'pending' 
                       AND expires_at < NOW()";

            logActivity("{$logPrefix} Update query for expired OTPs: {$updateQuery}");

            $updateStmt = $this->conn->prepare($updateQuery);

            if (!$updateStmt) {
                $errorMsg = "Failed to prepare update statement: " . $this->conn->error;
                logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
                throw new Exception($errorMsg);
            }

            $updateStmt->bind_param("ss", $this->userType, $uniqueId);

            if (!$updateStmt->execute()) {
                $errorMsg = "Failed to execute update query: " . $updateStmt->error;
                logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
                $updateStmt->close();
                throw new Exception($errorMsg);
            }

            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();

            // Log details of each expired OTP
            foreach ($expiredOTPs as $otp) {
                logActivity("{$logPrefix} Marked OTP as expired - ID: {$otp['id']}, Email: {$otp['email']}, Created: {$otp['created_at']}, Expired at: {$otp['expires_at']}");
            }

            $this->conn->commit();

            logActivity("[EXPIRED_OTPS_UPDATED] {$logPrefix} Successfully updated {$affectedRows} expired OTPs for user ID: {$uniqueId}");

            return [
                'success' => true,
                'message' => "Updated {$affectedRows} expired OTPs to 'expired' status",
                'updated_count' => $affectedRows,
                'total_found' => $totalExpired
            ];

        } catch (Exception $e) {
            $this->conn->rollback();

            $errorMsg = "Failed to update expired pending OTPs: " . $e->getMessage();
            logActivity("[UPDATE_EXPIRED_OTPS_FAILED] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Failed to process expired OTPs',
                'error' => $e->getMessage(),
                'updated_count' => 0
            ];
        }
    }


    private function hasActiveOTP($uniqueId)
    {
        $logPrefix = "[ACTIVE_OTP_CHECK] [ID:{$this->requestId}]";

        logActivity("{$logPrefix} Checking for active OTP for user ID: {$uniqueId}");
        $cleanupResult = $this->updateExpiredPendingOTPs($uniqueId);
        if ($cleanupResult['success'] && $cleanupResult['updated_count'] > 0) {
            logActivity("{$logPrefix} Cleaned up {$cleanupResult['updated_count']} expired OTPs");
        } elseif (!$cleanupResult['success']) {
            logActivity("[CLEANUP_FAILED] {$logPrefix} Failed to clean up expired OTPs: " . $cleanupResult['message']);
        }

        $query = "SELECT id, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_since FROM otp_requests WHERE user_type = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1";
        logActivity("{$logPrefix} Preparing active OTP query: {$query}");

        $stmt = $this->conn->prepare($query);

        if (!$stmt) {
            $errorMsg = "Failed to prepare active OTP statement: " . $this->conn->error;
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} {$errorMsg}");
            return ['hasActive' => false, 'secondsSinceLast' => 0];
        }

        logActivity("{$logPrefix} Statement prepared, binding parameters: user_type={$this->userType}, user_id={$uniqueId}");

        $stmt->bind_param("ss", $this->userType, $uniqueId);

        if (!$stmt->execute()) {
            $errorMsg = "Failed to execute active OTP query: " . $stmt->error;
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} {$errorMsg}");
            $stmt->close();
            return ['hasActive' => false, 'secondsSinceLast' => 0];
        }

        logActivity("{$logPrefix} Active OTP query executed, fetching results");

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $secondsSince = (int) $row['seconds_since'];
            $stmt->close();

            logActivity("{$logPrefix} Found active OTP - ID: {$row['id']}, created: {$row['created_at']}, seconds since: {$secondsSince}");
            return [
                'hasActive' => true,
                'secondsSinceLast' => $secondsSince
            ];
        }

        $stmt->close();
        logActivity("{$logPrefix} No active OTP found for user ID: {$uniqueId}");
        return ['hasActive' => false, 'secondsSinceLast' => 0];
    }

    private function createAndSendOTP($uniqueId, $email, $title = "From Rent Manager")
    {
        $logPrefix = "[OTP_CREATION] [ID:{$this->requestId}]";

        logActivity("{$logPrefix} Starting OTP creation for user ID: {$uniqueId}, email: {$email}");

        // Generate cryptographically secure OTP
        $min = pow(10, $this->otpLength - 1);
        $max = pow(10, $this->otpLength) - 1;

        logActivity("{$logPrefix} Generating OTP with length: {$this->otpLength}, range: {$min} to {$max}");

        try {
            $otp = random_int($min, $max);
            $otp = str_pad($otp, $this->otpLength, '0', STR_PAD_LEFT);
            logActivity("{$logPrefix} OTP generated: {$otp}");
        } catch (Exception $e) {
            $errorMsg = "Failed to generate secure OTP: " . $e->getMessage();
            logActivity("[OTP_GENERATION_ERROR] {$logPrefix} {$errorMsg}");
            return [
                'success' => false,
                'message' => 'Failed to generate OTP. Please try again.',
                'code' => 500
            ];
        }

        $otpHashed = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->otpExpiryMinutes} minutes"));

        logActivity("{$logPrefix} OTP hashed, expires at: {$expiresAt}");

        logActivity("{$logPrefix} Starting database transaction");
        $this->conn->begin_transaction();

        try {
            // Store OTP
            $query = "INSERT INTO otp_requests (user_type, user_id, email, otp, expires_at, status, ip_address) VALUES (?, ?, ?, ?, ?, 'pending', ?)";
            logActivity("{$logPrefix} Preparing OTP insert query: {$query}");

            $stmt = $this->conn->prepare($query);

            if (!$stmt) {
                $errorMsg = "Failed to prepare OTP insert statement: " . $this->conn->error;
                throw new Exception($errorMsg);
            }

            $ip = $this->ipAddress;
            logActivity("{$logPrefix} Binding parameters: user_type={$this->userType}, user_id={$uniqueId}, email={$email}, expires_at={$expiresAt}, ip={$ip}");

            $stmt->bind_param("ssssss", $this->userType, $uniqueId, $email, $otpHashed, $expiresAt, $ip);

            logActivity("{$logPrefix} Executing OTP insert");
            if (!$stmt->execute()) {
                $errorMsg = "Failed to execute OTP insert: " . $stmt->error;
                throw new Exception($errorMsg);
            }

            $affectedRows = $stmt->affected_rows;
            logActivity("{$logPrefix} OTP inserted successfully, affected rows: {$affectedRows}");

            if ($affectedRows <= 0) {
                throw new Exception("No rows affected - OTP insert failed");
            }

            $stmt->close();

            // Create email content
            $subject = "Your OTP Code - {$title}";
            $body = "Your OTP for {$title} is: {$otp}. It expires in {$this->otpExpiryMinutes} minutes.\n\n";
            $body .= "Do not share this code with anyone.\n";
            $body .= "If you didn't request this, please ignore this email.";

            logActivity("{$logPrefix} Prepared email - Subject: {$subject}, Body length: " . strlen($body));

            // Send email - BUT don't rollback transaction if this fails
            logActivity("{$logPrefix} Attempting to send email to: {$email}");
            $emailSent = sendEmailWithGmailSMTP($email, $body, $subject);

            if ($emailSent) {
                logActivity("{$logPrefix} Email sent successfully, committing transaction");
                $this->conn->commit();

                logActivity("[OTP_SENT_SUCCESS] {$logPrefix} OTP {$otp} sent to {$email}, expires: {$expiresAt}");

                return [
                    'success' => true,
                    'message' => 'OTP sent successfully. Please check your email.',
                    'code' => 200,
                    'email_sent' => true
                ];
            } else {
                // Email failed but OTP was stored
                logActivity("[EMAIL_SEND_FAILED] {$logPrefix} Email sending failed but OTP was stored");

                // Update OTP record to indicate email wasn't sent
                $this->updateOTPStatusAfterEmailFailure($uniqueId, $email);

                logActivity("{$logPrefix} Committing transaction even though email failed");
                $this->conn->commit();

                return [
                    'success' => false,
                    'message' => 'OTP was generated but we encountered an issue sending it to your email. Please try again or contact support.',
                    'code' => 500,
                    'email_sent' => false,
                    'otp_generated' => true
                ];
            }

        } catch (Exception $e) {
            logActivity("[TRANSACTION_ROLLBACK] {$logPrefix} Rolling back transaction due to error: " . $e->getMessage());
            $this->conn->rollback();

            $errorMsg = "OTP creation failed at step: " . $e->getMessage() . " for email: {$email}";
            logActivity("[OTP_CREATION_FAILED] [ID:{$this->requestId}] {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Failed to process OTP request. Please try again.',
                'code' => 500,
                'email_sent' => false,
                'otp_generated' => false
            ];
        }
    }

    private function updateOTPStatusAfterEmailFailure($uniqueId, $email)
    {
        $logPrefix = "[UPDATE_OTP_STATUS] [ID:{$this->requestId}]";

        try {
            $query = "UPDATE otp_requests SET status = 'email_failed' WHERE user_type = ? AND user_id = ? AND email = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);

            if ($stmt) {
                $stmt->bind_param("sss", $this->userType, $uniqueId, $email);
                $stmt->execute();
                $stmt->close();
                logActivity("{$logPrefix} Updated OTP status to 'email_failed' for user ID: {$uniqueId}");
            }
        } catch (Exception $e) {
            logActivity("[STATUS_UPDATE_ERROR] {$logPrefix} Failed to update OTP status: " . $e->getMessage());
        }
    }
}

// === Main Execution ===
logActivity("[MAIN_EXECUTION_START] [ID:{$requestId}] Starting main execution");

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

logActivity("[REQUEST_METHOD_VALID] [ID:{$requestId}] POST method validated");

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

logActivity("[REQUEST_BODY_RECEIVED] [ID:{$requestId}] Request body length: " . strlen($input) . " bytes");

$data = json_decode($input, true);

// Validate JSON decoding
if (json_last_error() !== JSON_ERROR_NONE) {
    $errorMsg = "JSON decode error: " . json_last_error_msg();
    logActivity("[JSON_DECODE_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg} - Input preview: " . substr($input, 0, 200));

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data: ' . json_last_error_msg(),
        'request_id' => $requestId
    ]);
    exit();
}

logActivity("[JSON_DECODE_SUCCESS] [ID:{$requestId}] JSON decoded successfully");

// Check required fields
if (!isset($data['email'])) {
    $errorMsg = "Missing required field: email";
    logActivity("[MISSING_FIELD_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg} - Received fields: " . implode(', ', array_keys($data)));

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required field: email',
        'request_id' => $requestId
    ]);
    exit();
}

if (!isset($data['user_type'])) {
    $errorMsg = "Missing required field: user_type";
    logActivity("[MISSING_FIELD_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required field: user_type',
        'request_id' => $requestId
    ]);
    exit();
}

$email = trim($data['email']);
$userType = trim($data['user_type']);
$title = trim($data['title'] ?? 'From Rent Manager');

logActivity("[FIELDS_EXTRACTED] [ID:{$requestId}] Extracted - Email: {$email}, User Type: {$userType}, Title: {$title}");

// Validate user type
$validUserTypes = ['admin', 'agent', 'client'];
if (!in_array($userType, $validUserTypes)) {
    $errorMsg = "Invalid user type: {$userType}. Valid types: " . implode(', ', $validUserTypes);
    logActivity("[INVALID_USER_TYPE_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");

    // Generic error to prevent information leakage
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request. Please try again.',
        'request_id' => $requestId
    ]);
    exit();
}

logActivity("[USER_TYPE_VALID] [ID:{$requestId}] User type '{$userType}' is valid");

// Map user types to their respective tables
$userTables = [
    'admin' => 'admin_tbl',
    'agent' => 'agents',
    'client' => 'clients'
];

logActivity("[TABLE_MAPPING] [ID:{$requestId}] Mapping user type '{$userType}' to table '{$userTables[$userType]}'");

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errorMsg = "Email format validation failed: {$email}";
    logActivity("[EMAIL_VALIDATION_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] {$errorMsg}");

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address',
        'request_id' => $requestId
    ]);
    exit();
}

logActivity("[EMAIL_VALID] [ID:{$requestId}] Email '{$email}' passed format validation");

// Create and use OTPService
try {
    logActivity("[SERVICE_CREATION] [ID:{$requestId}] Creating OTPService instance");

    $otpService = new OTPService($conn, $userType, $userTables[$userType], $requestId, $ipAddress);

    logActivity("[OTP_GENERATION_START] [ID:{$requestId}] Calling generateOTP method");
    $response = $otpService->generateOTP($email, $title);

    logActivity("[RESPONSE_PREPARATION] [ID:{$requestId}] Preparing HTTP response with code: {$response['code']}");
    http_response_code($response['code']);

    $response['request_id'] = $requestId;
    echo json_encode($response);

    logActivity("[REQUEST_COMPLETE] [ID:{$requestId}] Request processed successfully, response sent");

} catch (Exception $e) {
    // Catch any unexpected exceptions
    $errorMsg = "Unexpected exception: " . $e->getMessage() . " in file: " . $e->getFile() . " on line: " . $e->getLine();
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
    logActivity("[DB_CONNECTION_CLOSE] [ID:{$requestId}] Closing database connection");
    $conn->close();
}

logActivity("[SCRIPT_END] [ID:{$requestId}] Script execution completed");
