<?php
// admin/backend/payment/verify_payment_otp.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

$requestId = uniqid('verify_payment_otp_', true);
logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] ========== START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] ERROR: Unauthorized");
        json_error("Unauthorized", 401);
    }
    
    $adminId = $_SESSION['unique_id'];
    
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error("Invalid input data", 400);
    }
    
    $tenant_code = isset($input['tenant_code']) ? trim($input['tenant_code']) : '';
    $otp_code = isset($input['otp_code']) ? trim($input['otp_code']) : '';
    
    logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] Verifying OTP for tenant: {$tenant_code}");
    
    if (empty($tenant_code)) {
        json_error("Tenant code is required", 400);
    }
    
    if (empty($otp_code) || strlen($otp_code) !== 6) {
        json_error("Please enter a valid 6-digit OTP code", 400);
    }
    
    // Get tenant email
    $tenant_query = "SELECT email, firstname, lastname FROM tenants WHERE tenant_code = ? AND deleted_at IS NULL LIMIT 1";
    $tenant_stmt = $conn->prepare($tenant_query);
    $tenant_stmt->bind_param("s", $tenant_code);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    $tenant = $tenant_result->fetch_assoc();
    $tenant_stmt->close();
    
    if (!$tenant) {
        logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] Tenant not found: {$tenant_code}");
        json_error("Tenant not found", 404);
    }
    
    $tenant_email = $tenant['email'];
    
    // Verify OTP from otp_requests table
    $verify_query = "SELECT id, otp, expires_at, status FROM otp_requests 
                     WHERE user_type = 'tenant' 
                     AND user_id = ? 
                     AND email = ? 
                     AND status = 'pending' 
                     AND expires_at > NOW()
                     ORDER BY created_at DESC LIMIT 1";
    
    $verify_stmt = $conn->prepare($verify_query);
    $verify_stmt->bind_param("ss", $tenant_code, $tenant_email);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] No valid OTP found");
        json_error("Invalid or expired OTP. Please request a new OTP.", 400);
    }
    
    $otpRecord = $verify_result->fetch_assoc();
    $verify_stmt->close();
    
    // Verify the OTP
    if (!password_verify($otp_code, $otpRecord['otp'])) {
        logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] OTP verification failed - incorrect code");
        
        // Update OTP status to failed attempt
        $update_stmt = $conn->prepare("UPDATE otp_requests SET status = 'invalid_attempt', date_last_updated = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $otpRecord['id']);
        $update_stmt->execute();
        $update_stmt->close();
        
        json_error("Invalid OTP. Please try again.", 400);
    }
    
    // Mark OTP as verified
    $update_stmt = $conn->prepare("UPDATE otp_requests SET status = 'verified', date_last_updated = NOW() WHERE id = ?");
    $update_stmt->bind_param("i", $otpRecord['id']);
    $update_stmt->execute();
    $update_stmt->close();
    
    logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] OTP verified successfully");
    
    // Store verification in session (no auth_token needed)
    $_SESSION['payment_auth'][$tenant_code] = [
        'verified' => true,
        'verified_at' => date('Y-m-d H:i:s'),
        'verified_by' => $adminId,
        'otp_request_id' => $otpRecord['id']
    ];
    
    json_success([
        'verified' => true,
        'tenant_code' => $tenant_code,
        'tenant_name' => $tenant['firstname'] . ' ' . $tenant['lastname']
    ], "OTP verified successfully");
    
} catch (Exception $e) {
    logActivity("[VERIFY_PAYMENT_OTP] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Verification failed: " . $e->getMessage(), 500);
}