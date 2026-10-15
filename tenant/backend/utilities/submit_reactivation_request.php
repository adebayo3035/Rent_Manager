<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notifications.php';

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

class ReactivationService
{
    private $conn;
    private $maxRequestsPerDay = 20; // was 2 during testing
    private $requestId;
    private $ipAddress;
    private $userAgent;

    public function __construct($conn, $requestId, $ipAddress, $userAgent)
    {
        $this->conn      = $conn;
        $this->requestId = $requestId;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;

        logActivity("[REACTIVATION_SERVICE_INIT] [ID:{$requestId}] Service initialized");
    }

    public function submitReactivationRequest($data)
    {
        $logPrefix = "[SUBMIT_REACTIVATION] [ID:{$this->requestId}] [IP:{$this->ipAddress}]";

        logActivity("{$logPrefix} Starting reactivation request submission");

        // ================================================================
        // Step 1: Validate required fields
        // ================================================================
        $requiredFields = ['email', 'user_type', 'otp', 'request_reason'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errorMsg = "Missing required field: {$field}";
                logActivity("[VALIDATION_ERROR] {$logPrefix} {$errorMsg}");

                return [
                    'success' => false,
                    'message' => "Missing required field: {$field}",
                    'code'    => 400,
                ];
            }
        }

        $email         = trim($data['email']);
        $userType      = trim($data['user_type']);
        $otp           = trim($data['otp']);
        $requestReason = trim($data['request_reason']);

        logActivity("{$logPrefix} Validated fields - Email: {$email}, User Type: {$userType}, Reason length: " . strlen($requestReason));

        // ================================================================
        // Step 2: Validate user type
        // ================================================================
        $validUserTypes = ['admin', 'agent', 'client', 'tenant'];
        if (!in_array($userType, $validUserTypes, true)) {
            $errorMsg = "Invalid user type: {$userType}";
            logActivity("[INVALID_USER_TYPE] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Invalid user type',
                'code'    => 400,
            ];
        }

        $userTables = [
            'admin'  => 'admin_tbl',
            'agent'  => 'agents',
            'client' => 'clients',
            'tenant' => 'tenants',
        ];

        if (!isset($userTables[$userType])) {
            $errorMsg = "No table mapping for user type: {$userType}";
            logActivity("[TABLE_MAPPING_ERROR] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Invalid user type configuration',
                'code'    => 500,
            ];
        }

        $userTable = $userTables[$userType];
        logActivity("{$logPrefix} User table mapped: {$userTable}");

        // ================================================================
        // Step 3: Fetch user by email
        // ================================================================
        $user = $this->getUserDetails($email, $userType, $userTable);
        if (!$user) {
            $errorMsg = "User not found - Email: {$email}, Type: {$userType}";
            logActivity("[USER_NOT_FOUND] {$logPrefix} {$errorMsg}");

            // Generic response for security (don't leak whether the account exists)
            return [
                'success' => false,
                'message' => 'Invalid request. Please check your details and try again.',
                'code'    => 400,
            ];
        }

        logActivity("{$logPrefix} User found - ID: {$user['id']}, Status: {$user['status']}");

        // ================================================================
        // ===== NEW: Step 3.5: Tenant eligibility check =====
        // Reject:
        //   - Evacuated tenants (tenant_status='3' or evacuation_status='evacuated')
        //   - Suspended tenants (tenant_status='2')
        //   - Blocked tenants (tenant_status='0')
        // Allow only:
        //   - Tenants whose tenancy is intact but the binary status was toggled off
        //     by admin (tenant_status='1', status=0)
        // ================================================================
        if ($userType === 'tenant') {
            $eligibility = $this->checkTenantEligibility($user['id']);
            if (!$eligibility['eligible']) {
                logActivity("[ELIGIBILITY_FAILED] {$logPrefix} " . $eligibility['message']);
                return [
                    'success'    => false,
                    'message'    => $eligibility['message'],
                    'code'       => $eligibility['code'],
                    'error_code' => $eligibility['error_code'] ?? null,
                ];
            }
            logActivity("{$logPrefix} Tenant eligibility check passed");
        }

        // ================================================================
        // Step 4: Reject if account is already active
        // ================================================================
        if ($user['status'] != '0') {
            $errorMsg = "User is already active - Status: {$user['status']}";
            logActivity("[USER_ALREADY_ACTIVE] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'Your account is already active.',
                'code'    => 400,
            ];
        }

        // ================================================================
        // Step 5: Verify OTP
        // ================================================================
        $otpVerification = $this->verifyOTP($user['id'], $userType, $otp, $email);
        if (!$otpVerification['success']) {
            logActivity("[OTP_VERIFICATION_FAILED] {$logPrefix} {$otpVerification['message']}");
            return $otpVerification;
        }

        $otpRequestId = $otpVerification['otp_request_id'];
        logActivity("{$logPrefix} OTP verified successfully - OTP Request ID: {$otpRequestId}");

        // ================================================================
        // Step 6: Rate limit check
        // ================================================================
        $rateLimitCheck = $this->checkRateLimit($user['id'], $userType);
        if (!$rateLimitCheck['allowed']) {
            $errorMsg = "Rate limit exceeded - Requests today: {$rateLimitCheck['count']}";
            logActivity("[RATE_LIMIT_EXCEEDED] {$logPrefix} {$errorMsg}");

            return [
                'success' => false,
                'message' => 'You have reached the maximum number of reactivation requests for today. Please try again tomorrow.',
                'code'    => 429,
            ];
        }

        // ================================================================
        // Step 7: Reject if a pending request already exists
        // ================================================================
        $existingRequest = $this->getExistingPendingRequest($user['id'], $userType);
        if ($existingRequest) {
            $errorMsg = "Existing pending request found - Request ID: {$existingRequest['id']}";
            logActivity("[EXISTING_PENDING_REQUEST] {$logPrefix} {$errorMsg}");

            return [
                'success'             => false,
                'message'             => 'You already have a pending reactivation request. Please wait for it to be reviewed.',
                'code'                => 400,
                'existing_request_id' => $existingRequest['id'],
            ];
        }

        // ================================================================
        // Step 8: Reject if within cooldown after last rejection
        // ================================================================
        $lastRejection = $this->getLastRejection($user['id'], $userType);
        if ($lastRejection && $this->isWithinCooldownPeriod($lastRejection['created_at'])) {
            $errorMsg = "Within cooldown period after rejection - Last rejection: {$lastRejection['created_at']}";
            logActivity("[COOLDOWN_PERIOD] {$logPrefix} {$errorMsg}");

            $hoursRemaining = $this->getCooldownHoursRemaining($lastRejection['created_at']);
            return [
                'success' => false,
                'message' => "Your last request was rejected. Please wait {$hoursRemaining} hours before submitting a new request.",
                'code'    => 429,
            ];
        }

        // ================================================================
        // Step 9: Generate request ID and submit
        // ================================================================
        $request_id = generateUniqueRequestId();

        return $this->createReactivationRequest(
            requestId:     $request_id,
            userId:        $user['id'],
            userType:      $userType,
            email:         $email,
            otpRequestId:  $otpRequestId,
            requestReason: $requestReason
        );
    }

    // ====================================================================
    // ===== NEW: Tenant eligibility check =====
    // ====================================================================
    /**
     * Determine whether a tenant is eligible to submit a reactivation request.
     *
     * Rules:
     *   - Evacuated (tenant_status='3' OR evacuation_status='evacuated')
     *       → not eligible. Tenancy has ended. Nothing to reactivate.
     *   - Suspended (tenant_status='2')
     *       → not eligible. Must contact support.
     *   - Blocked/Inactive (tenant_status='0')
     *       → not eligible. Must contact support.
     *   - Deactivated while status is intact (tenant_status='1', status=0)
     *       → eligible. This is exactly what self-service reactivation is for.
     *
     * @param string $tenantCode
     * @return array{eligible: bool, message?: string, code?: int, error_code?: string}
     */
    private function checkTenantEligibility($tenantCode)
    {
        $logPrefix = "[TENANT_ELIGIBILITY] [ID:{$this->requestId}]";

        $stmt = $this->conn->prepare("
            SELECT status, tenant_status, evacuation_status
            FROM tenants
            WHERE tenant_code = ?
            LIMIT 1
        ");

        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} Failed to prepare eligibility query");
            // Fail open: let downstream checks handle it
            return ['eligible' => true];
        }

        $stmt->bind_param("s", $tenantCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            logActivity("{$logPrefix} Tenant not found: {$tenantCode}");
            return [
                'eligible'   => false,
                'message'    => 'Tenant account not found.',
                'code'       => 404,
                'error_code' => 'TENANT_NOT_FOUND',
            ];
        }

        $evacuationStatus = $row['evacuation_status'] ?? '';
        $tenantStatus     = (string) ($row['tenant_status'] ?? '');

        logActivity("{$logPrefix} Tenant state - status: {$row['status']}, "
            . "tenant_status: {$tenantStatus}, evacuation_status: {$evacuationStatus}");

        // --- Evacuated: not reactivatable ---
        if ($evacuationStatus === 'evacuated' || $tenantStatus === '3') {
            logActivity("{$logPrefix} Blocked - tenant has evacuated");
            return [
                'eligible'   => false,
                'message'    => 'Your tenancy has ended. Reactivation is not applicable. '
                              . 'To rent a property through us again, please contact our support team '
                              . 'or reach out to an agent.',
                'code'       => 403,
                'error_code' => 'EVACUATED_NOT_REACTIVATABLE',
            ];
        }

        // --- Suspended: direct to support ---
        if ($tenantStatus === '2') {
            logActivity("{$logPrefix} Blocked - tenant is suspended");
            return [
                'eligible'   => false,
                'message'    => 'Your account is suspended. Please contact our support team to discuss reactivation.',
                'code'       => 403,
                'error_code' => 'SUSPENDED_CONTACT_SUPPORT',
            ];
        }

        // --- Blocked / Inactive: direct to support ---
        if ($tenantStatus === '0') {
            logActivity("{$logPrefix} Blocked - tenant account is inactive/blocked");
            return [
                'eligible'   => false,
                'message'    => 'Your account is blocked. Please contact our support team for assistance.',
                'code'       => 403,
                'error_code' => 'BLOCKED_CONTACT_SUPPORT',
            ];
        }

        // --- All other combinations are eligible for reactivation ---
        // Typically: tenant_status='1' with status=0 (deactivated but tenancy intact)
        logActivity("{$logPrefix} Tenant is eligible for reactivation");
        return ['eligible' => true];
    }

    // ====================================================================
    // Existing methods below (unchanged)
    // ====================================================================

    private function getUserDetails($email, $userType, $userTable)
    {
        $logPrefix = "[USER_LOOKUP] [ID:{$this->requestId}]";

        $idColumns = [
            'admin'  => 'unique_id',
            'agent'  => 'agent_code',
            'client' => 'client_code',
            'tenant' => 'tenant_code',
        ];

        if (!isset($idColumns[$userType])) {
            logActivity("[INVALID_USER_TYPE] {$logPrefix} Invalid user type for ID mapping: {$userType}");
            return false;
        }

        $idColumn    = $idColumns[$userType];
        $nameColumn  = 'firstname';
        $phoneColumn = 'phone';

        $query = "SELECT {$idColumn} as id, status, email
                  FROM {$userTable}
                  WHERE email = ?";

        logActivity("{$logPrefix} Query: {$query} for email: {$email}");

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} " . $this->conn->error);
            return false;
        }

        $stmt->bind_param("s", $email);
        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} " . $stmt->error);
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

        logActivity("{$logPrefix} User retrieved - Type: {$userType}, ID: {$user['id']}, Status: {$user['status']}");
        return $user;
    }

    private function verifyOTP($userId, $userType, $otp, $email)
    {
        $logPrefix = "[OTP_VERIFICATION] [ID:{$this->requestId}]";

        logActivity("{$logPrefix} Verifying OTP for user ID: {$userId}, type: {$userType}");

        $query = "SELECT id, otp, expires_at, status FROM otp_requests
                  WHERE user_type = ? AND user_id = ? AND email = ?
                  AND status = 'pending' AND expires_at > NOW()
                  ORDER BY created_at DESC LIMIT 1";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix} " . $this->conn->error);
            return ['success' => false, 'message' => 'OTP verification failed'];
        }

        $stmt->bind_param("sss", $userType, $userId, $email);

        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix} " . $stmt->error);
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
                'code'    => 400,
            ];
        }

        $otpRecord = $result->fetch_assoc();
        $stmt->close();

        if (!password_verify($otp, $otpRecord['otp'])) {
            logActivity("{$logPrefix} OTP verification failed - OTP does not match");
            $this->updateOTPStatus($otpRecord['id'], 'invalid_attempt');
            return [
                'success' => false,
                'message' => 'Invalid OTP. Please try again.',
                'code'    => 400,
            ];
        }

        logActivity("{$logPrefix} OTP verified successfully - OTP Request ID: {$otpRecord['id']}");
        $this->updateOTPStatus($otpRecord['id'], 'verified');

        return [
            'success'        => true,
            'otp_request_id' => $otpRecord['id'],
            'message'        => 'OTP verified successfully',
        ];
    }

    private function updateOTPStatus($otpId, $status)
    {
        $logPrefix = "[UPDATE_OTP_STATUS] [ID:{$this->requestId}]";

        try {
            $stmt = $this->conn->prepare("UPDATE otp_requests SET status = ?, date_last_updated = NOW() WHERE id = ?");
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

    private function checkRateLimit($userId, $userType)
    {
        $logPrefix = "[RATE_LIMIT_CHECK] [ID:{$this->requestId}]";

        $query = "SELECT COUNT(*) as request_count FROM account_reactivation_requests
                  WHERE user_type = ? AND user_id = ?
                  AND DATE(created_at) = CURDATE()
                  AND status IN ('pending', 'approved', 'rejected')";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix}");
            return ['allowed' => true, 'count' => 0];
        }

        $stmt->bind_param("ss", $userType, $userId);

        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix}");
            $stmt->close();
            return ['allowed' => true, 'count' => 0];
        }

        $row = $stmt->get_result()->fetch_assoc();
        $count = $row['request_count'] ?? 0;
        $stmt->close();

        $allowed = ($count < $this->maxRequestsPerDay);

        logActivity("{$logPrefix} Rate limit check - Count: {$count}, Allowed: " . ($allowed ? 'Yes' : 'No'));

        return [
            'allowed'     => $allowed,
            'count'       => $count,
            'max_allowed' => $this->maxRequestsPerDay,
        ];
    }

    private function getExistingPendingRequest($userId, $userType)
    {
        $logPrefix = "[EXISTING_REQUEST_CHECK] [ID:{$this->requestId}]";

        $stmt = $this->conn->prepare("
            SELECT id, status, created_at FROM account_reactivation_requests
            WHERE user_type = ? AND user_id = ? AND status = 'pending'
            ORDER BY created_at DESC LIMIT 1
        ");

        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix}");
            return null;
        }

        $stmt->bind_param("ss", $userType, $userId);

        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix}");
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
        return null;
    }

    private function getLastRejection($userId, $userType)
    {
        $logPrefix = "[LAST_REJECTION_CHECK] [ID:{$this->requestId}]";

        $stmt = $this->conn->prepare("
            SELECT id, created_at, rejection_reason FROM account_reactivation_requests
            WHERE user_type = ? AND user_id = ? AND status = 'rejected'
            ORDER BY created_at DESC LIMIT 1
        ");

        if (!$stmt) {
            logActivity("[DB_PREPARE_ERROR] {$logPrefix}");
            return null;
        }

        $stmt->bind_param("ss", $userType, $userId);

        if (!$stmt->execute()) {
            logActivity("[DB_EXECUTE_ERROR] {$logPrefix}");
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $rejection = $result->fetch_assoc();
            $stmt->close();
            logActivity("{$logPrefix} Last rejection found - ID: {$rejection['id']}");
            return $rejection;
        }

        $stmt->close();
        return null;
    }

    private function isWithinCooldownPeriod($rejectionDate)
    {
        // Cooldown disabled during testing (0 hours).
        // Set to 24 to enable a 24-hour cooldown after rejection.
        $cooldownHours = 0;
        $hoursSinceRejection = (time() - strtotime($rejectionDate)) / 3600;
        return $hoursSinceRejection < $cooldownHours;
    }

    private function getCooldownHoursRemaining($rejectionDate)
    {
        $cooldownHours = 24;
        $hoursSinceRejection = (time() - strtotime($rejectionDate)) / 3600;
        $hoursRemaining = ceil($cooldownHours - $hoursSinceRejection);
        return max(1, $hoursRemaining);
    }

    private function createReactivationRequest($requestId, $userId, $userType, $email, $otpRequestId, $requestReason)
    {
        $logPrefix = "[CREATE_REQUEST] [ID:{$this->requestId}]";

        logActivity("{$logPrefix} Creating reactivation request for user ID: {$userId}");

        $this->conn->begin_transaction();

        try {
            $query = "INSERT INTO account_reactivation_requests
                      (request_id, user_type, user_id, email, otp_request_id, request_reason,
                       request_ip, request_user_agent, status, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";

            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare request statement: " . $this->conn->error);
            }

            $stmt->bind_param(
                "ssssisss",
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

            $insertedId   = $stmt->insert_id;
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            if ($affectedRows <= 0) {
                throw new Exception("No rows affected - request insert failed");
            }

            // Update OTP usage description
            $this->updateOTPUsageDescription($otpRequestId, "Account reactivation request #{$insertedId}");

            // Notify the user (or, more accurately, log a notification for tracking)
            try {
                createNotification($this->conn, [
                    'user_id'  => $userId,
                    'title'    => 'New Account Reactivation Request',
                    'message'  => "User {$userId} has submitted an account reactivation request.",
                    'type'     => 'INFO',
                    'category' => 'account_reactivation',
                ]);
                logActivity("{$logPrefix} Notification created for user ID: {$userId}");
            } catch (Exception $e) {
                logActivity("[NOTIFICATION_ERROR] {$logPrefix} " . $e->getMessage());
            }

            $this->conn->commit();
            logActivity("[REACTIVATION_REQUEST_CREATED] {$logPrefix} ID: {$insertedId}");

            return [
                'success'     => true,
                'message'     => 'Reactivation request submitted successfully. Our team will review your request shortly.',
                'code'        => 200,
                'request_id'  => $insertedId,
                'user_id'     => $userId,
                'review_time' => '24-48 hours',
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            logActivity("[REQUEST_CREATION_FAILED] {$logPrefix} " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to submit reactivation request. Please try again.',
                'code'    => 500,
            ];
        }
    }

    private function updateOTPUsageDescription($otpId, $description)
    {
        $logPrefix = "[UPDATE_OTP_DESC] [ID:{$this->requestId}]";

        try {
            $stmt = $this->conn->prepare("UPDATE otp_requests SET usage_description = ?, date_last_updated = NOW() WHERE id = ?");
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

    private function notifyAdmins($userId, $userType, $email, $requestId)
    {
        $logPrefix = "[ADMIN_NOTIFICATION] [ID:{$this->requestId}]";

        try {
            $query = "SELECT unique_id as id, email, firstname, lastname FROM admin_tbl WHERE role = 'Super Admin' AND status = '1'";
            $result = $this->conn->query($query);

            if ($result && $result->num_rows > 0) {
                $adminCount = 0;
                while ($admin = $result->fetch_assoc()) {
                    try {
                        createNotification($this->conn, [
                            'user_id'  => $admin['id'],
                            'title'    => 'New Account Reactivation Request',
                            'message'  => "User {$email} ({$userType}) has submitted an account reactivation request.",
                            'type'     => 'INFO',
                            'category' => 'admin_alert',
                        ]);
                        $adminCount++;
                    } catch (Exception $e) {
                        logActivity("[ADMIN_NOTIFICATION_ERROR] {$logPrefix} " . $e->getMessage());
                    }
                }
                logActivity("{$logPrefix} Created notifications for {$adminCount} super admins");
            } else {
                logActivity("{$logPrefix} No active Super Admins found to notify");
            }
        } catch (Exception $e) {
            logActivity("[ADMIN_NOTIFICATION_ERROR] {$logPrefix} " . $e->getMessage());
        }
    }
}

// ========================================================================
// Main Execution
// ========================================================================
logActivity("[MAIN_EXECUTION_START] [ID:{$requestId}] Starting main execution");

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    logActivity("[DB_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] Database connection object not initialized");

    http_response_code(503);
    echo json_encode([
        'success'    => false,
        'message'    => 'Service temporarily unavailable. Please try again later.',
        'request_id' => $requestId,
    ]);
    exit();
}

if ($conn->connect_error) {
    logActivity("[DB_CONNECTION_FAILED] [ID:{$requestId}] [IP:{$ipAddress}] " . $conn->connect_error);

    http_response_code(503);
    echo json_encode([
        'success'    => false,
        'message'    => 'Service temporarily unavailable. Please try again later.',
        'request_id' => $requestId,
    ]);
    exit();
}

// Method check
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    logActivity("[REQUEST_METHOD_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] " . $_SERVER["REQUEST_METHOD"]);

    http_response_code(405);
    echo json_encode([
        "success"    => false,
        "message"    => "Method not allowed. Please use POST.",
        "request_id" => $requestId,
    ]);
    exit();
}

// Read input
$input = file_get_contents("php://input");
if (empty($input)) {
    logActivity("[EMPTY_REQUEST_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] Empty request body");

    http_response_code(400);
    echo json_encode([
        'success'    => false,
        'message'    => 'No input data received',
        'request_id' => $requestId,
    ]);
    exit();
}

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logActivity("[JSON_DECODE_ERROR] [ID:{$requestId}] [IP:{$ipAddress}] " . json_last_error_msg());

    http_response_code(400);
    echo json_encode([
        'success'    => false,
        'message'    => 'Invalid JSON data: ' . json_last_error_msg(),
        'request_id' => $requestId,
    ]);
    exit();
}

// Execute the service
try {
    $reactivationService = new ReactivationService($conn, $requestId, $ipAddress, $userAgent);
    $response = $reactivationService->submitReactivationRequest($data);

    http_response_code($response['code']);
    $response['request_id'] = $requestId;
    echo json_encode($response);

    logActivity("[REQUEST_COMPLETE] [ID:{$requestId}] Request processed");

} catch (Exception $e) {
    logActivity("[UNEXPECTED_EXCEPTION] [ID:{$requestId}] [IP:{$ipAddress}] " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'message'    => 'An unexpected error occurred. Please try again later.',
        'request_id' => $requestId,
    ]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

logActivity("[SCRIPT_END] [ID:{$requestId}] Script execution completed");