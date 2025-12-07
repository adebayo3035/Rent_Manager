<?php
require 'sendOTPGmail.php';
require 'config.php';

header("Content-Type: application/json");

class OTPService {
    private $conn;
    private $userType;
    private $userTable;
    private $maxAttempts = 3;
    private $timeWindow = 300; // 5 minutes
    private $otpExpiryMinutes = 2;
    private $title;


    public function __construct($conn, $userType, $userTable) {
        $this->conn = $conn;
        $this->userType = $userType;
        $this->userTable = $userTable;
    }

    public function generateOTP($email,  $title = "From Karakata") {
        $ip = $_SERVER['REMOTE_ADDR'];
        $logPrefix = "OTP_{$this->userType}";
        
        // Validate input
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email input - $email from IP $ip";
            logActivity("{$logPrefix}_VALIDATION_FAILED: $message");
            return ['success' => false, 'message' => 'Invalid email address', 'code' => 400];
        }

        // Get user user_id
        $user = $this->getUserByEmail($email);
        if (!$user) {
            $message = "User not found for email: $email from IP $ip";
            logActivity("{$logPrefix}_USER_NOT_FOUND: $message");
            return ['success' => false, 'message' => 'User record not found', 'code' => 404];
        }

        // Check rate limiting
        if ($this->isRateLimited($user['id'])) {
            $message = "Rate limit exceeded for {$user['id']} ($email) from IP $ip";
            logActivity("{$logPrefix}_RATE_LIMIT: $message");
            return ['success' => false, 'message' => 'Too many OTP requests. Please wait.', 'code' => 429];
        }

        // Check existing active OTP
        if ($this->hasActiveOTP($user['id'])) {
            $message = "Active OTP exists for {$user['id']} ($email)";
            logActivity("{$logPrefix}_ACTIVE_OTP_EXISTS: $message");
            return ['success' => true, 'message' => 'An active OTP already exists. Check your email.', 'code' => 200];
        }

        // Generate and send OTP
         $result = $this->createAndSendOTP($user['id'], $email, $title);
        
        if ($result['success']) {
            logActivity("{$logPrefix}_SUCCESS: OTP generated for $email from IP $ip");
        } else {
            logActivity("{$logPrefix}_FAILURE: {$result['message']} for $email from IP $ip");
        }
        
        return $result;
    }

    private function getUserByEmail($email) {
        $query = "SELECT id FROM {$this->userTable} WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    private function isRateLimited($uniqueId) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS attempts 
            FROM otp_requests 
            WHERE user_type = ? AND user_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)
        ");
        $stmt->bind_param("ssi", $this->userType, $uniqueId, $this->timeWindow);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return ($row['attempts'] >= $this->maxAttempts);
    }

    private function hasActiveOTP($uniqueId) {
        $stmt = $this->conn->prepare("
            SELECT id 
            FROM otp_requests 
            WHERE user_type = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("ss", $this->userType, $uniqueId);
        $stmt->execute();
        $result = $stmt->get_result();
        return ($result->num_rows > 0);
    }

    private function createAndSendOTP($uniqueId, $email, $title = "From Karakata") {
        $otp = rand(100000, 999999);
        $otpHashed = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->otpExpiryMinutes} minutes"));

        $this->conn->begin_transaction();

        try {
            // Store OTP
            $stmt = $this->conn->prepare("
                INSERT INTO otp_requests (user_type, user_id, email, otp, expires_at, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->bind_param("sssss", $this->userType, $uniqueId, $email, $otpHashed, $expiresAt);
            $stmt->execute();

            if ($stmt->affected_rows <= 0) {
                throw new Exception("Database insert failed");
            }

            // Send email
            //$title = "From Karakata";
            $subject = "OTP for {$title}";
            $body = "Your OTP for {$title} is: {$otp}. It expires in {$this->otpExpiryMinutes} minutes.";
            $status = sendEmailWithGmailSMTP($email, $body, $subject);

            if (!$status) {
                throw new Exception("Email send failed");
            }

            $this->conn->commit();
            return ['success' => true, 'message' => 'OTP sent successfully', 'otp' => $otp, 'code' => 200];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Failed to process OTP: ' . $e->getMessage(), 'code' => 500];
        }
    }
}

// Usage Example
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $message = "Invalid request method: " . $_SERVER["REQUEST_METHOD"];
    logActivity("OTP_INVALID_METHOD: $message");
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email']);
$userType = trim($data['user_type']);
$title = trim($data['title'] ?? 'From Karakata'); // Default title if not provided
// Validate user type
$validUserTypes = ['admin', 'customer', 'driver'];
if (!in_array($userType, $validUserTypes)) {
    $message = "Invalid user type: $userType for email: $email";
    logActivity("OTP_INVALID_TYPE: $message");
    echo json_encode(['success' => false, 'message' => 'Invalid user type']);
    exit;
}
if (!isset($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    logActivity("OTP_FAILED: Invalid email input - $email");
    exit;
}

// Map user types to their respective tables
$userTables = [
    'admin' => 'admin_tbl',
    'customer' => 'customers',
    'driver' => 'driver'
];

// Create and use OTPService
$otpService = new OTPService($conn, $userType, $userTables[$userType]);
$response = $otpService->generateOTP($email, $title);

http_response_code($response['code']);
echo json_encode($response);