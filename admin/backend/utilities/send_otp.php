<?php
// tenant/backend/utilities/send_otp.php

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/otp_service.php';

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$requestId = uniqid('otp_req_', true);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

logActivity("[REQUEST_START] [ID:{$requestId}] [IP:{$ipAddress}] OTP Generation Request Started - Method: {$_SERVER['REQUEST_METHOD']}");

// ---- DB check ----
if (!isset($conn) || !($conn instanceof mysqli)) {
    logActivity("[DB_ERROR] [ID:{$requestId}] Database connection not initialized");
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable.', 'request_id' => $requestId]);
    exit();
}
if ($conn->connect_error) {
    logActivity("[DB_CONNECTION_FAILED] [ID:{$requestId}] " . $conn->connect_error);
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable.', 'request_id' => $requestId]);
    exit();
}
logActivity("[DB_CONNECTED] [ID:{$requestId}] Database connection established successfully");

// ---- Method check ----
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    logActivity("[REQUEST_METHOD_ERROR] [ID:{$requestId}] Invalid method: {$_SERVER['REQUEST_METHOD']}");
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Please use POST.', 'request_id' => $requestId]);
    exit();
}

// ---- Read body ----
$input = file_get_contents("php://input");
if (empty($input)) {
    logActivity("[EMPTY_REQUEST_ERROR] [ID:{$requestId}] No body received");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No input data received', 'request_id' => $requestId]);
    exit();
}
logActivity("[REQUEST_BODY_RECEIVED] [ID:{$requestId}] Length: " . strlen($input) . " bytes");

// ---- Parse JSON ----
$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logActivity("[JSON_DECODE_ERROR] [ID:{$requestId}] " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data', 'request_id' => $requestId]);
    exit();
}
logActivity("[JSON_DECODE_SUCCESS] [ID:{$requestId}] JSON decoded successfully");

// ---- Validate required fields ----
if (!isset($data['email']) || !isset($data['user_type'])) {
    logActivity("[MISSING_FIELD_ERROR] [ID:{$requestId}] Missing email or user_type");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'email and user_type are required', 'request_id' => $requestId]);
    exit();
}

$email    = trim($data['email']);
$userType = trim($data['user_type']);
$title    = trim($data['title'] ?? 'From Rent Manager');

logActivity("[FIELDS_EXTRACTED] [ID:{$requestId}] Email: {$email}, User Type: {$userType}, Title: {$title}");

// ---- Validate user_type ----
$validUserTypes = ['admin', 'agent', 'client', 'tenant'];
if (!in_array($userType, $validUserTypes, true)) {
    logActivity("[INVALID_USER_TYPE_ERROR] [ID:{$requestId}] Bad user_type: {$userType}");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.', 'request_id' => $requestId]);
    exit();
}

$userTables = [
    'admin'  => 'admin_tbl',
    'agent'  => 'agents',
    'client' => 'clients',
    'tenant' => 'tenants',
];

// ---- Validate email format ----
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    logActivity("[EMAIL_VALIDATION_ERROR] [ID:{$requestId}] Bad email: {$email}");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address', 'request_id' => $requestId]);
    exit();
}
logActivity("[EMAIL_VALID] [ID:{$requestId}] Email '{$email}' passed format validation");

// ---- Call OTPService ----
try {
    $otpService = new OTPService($conn, $userType, $userTables[$userType], $requestId, $ipAddress);
    $response = $otpService->generateOTP($email, $title);

    http_response_code($response['code']);
    $response['request_id'] = $requestId;
    echo json_encode($response);
    logActivity("[REQUEST_COMPLETE] [ID:{$requestId}] Response code: {$response['code']}");
} catch (Throwable $e) {
    logActivity("[UNEXPECTED_EXCEPTION] [ID:{$requestId}] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.', 'request_id' => $requestId]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}