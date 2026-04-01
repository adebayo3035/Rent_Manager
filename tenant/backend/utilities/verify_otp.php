<?php

header("Content-Type: application/json");

class OTPVerification {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function verifyOTP($userType, $email, $otp, $usageContext = 'account reactivation') {
        $ip = $_SERVER['REMOTE_ADDR'];
        $logPrefix = "OTP_VERIFY_{$userType}";

        $stmt = $this->conn->prepare("
            SELECT id, user_id, otp, expires_at 
            FROM otp_requests 
            WHERE user_type = ? AND email = ? AND status = 'pending'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("ss", $userType, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $otpRecord = $result->fetch_assoc();

        if (!$otpRecord) {
            logActivity("{$logPrefix}_NOT_FOUND: No pending OTP for $email");
            return ['success' => false, 'message' => 'No pending OTP found', 'code' => 404];
        }

        if (strtotime($otpRecord['expires_at']) < time()) {
            $this->markOTPAsExpired($otpRecord['id'], "OTP has expired before usage attempt");
            logActivity("{$logPrefix}_EXPIRED: OTP expired for {$otpRecord['user_id']} ($email)");
            return ['success' => false, 'message' => 'OTP has expired', 'code' => 400];
        }

        if (!password_verify($otp, $otpRecord['otp'])) {
            $this->markOTPAsFailed($otpRecord['id'], "Invalid OTP attempt for $usageContext");
            logActivity("{$logPrefix}_INVALID: Invalid OTP for {$otpRecord['user_id']} ($email)");
            return ['success' => false, 'message' => 'Invalid OTP', 'code' => 400];
        }

        // Mark as used
        $this->markOTPAsUsed($otpRecord['id'], "OTP successfully verified for $usageContext");

        logActivity("{$logPrefix}_SUCCESS: OTP verified for {$otpRecord['user_id']} ($email)");
        return [
            'success' => true,
            'message' => 'OTP verified successfully',
            'code' => 200,
            'user_id' => $otpRecord['user_id']
        ];
    }

   private function markOTPAsUsed($otpId, $description) {
        $stmt = $this->conn->prepare("
            UPDATE otp_requests 
            SET status = 'used', 
                usage_description = ?,
                date_last_updated = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $description, $otpId);
        $stmt->execute();
    }
    private function markOTPAsFailed($otpId, $description) {
        $stmt = $this->conn->prepare("
            UPDATE otp_requests 
            SET status = 'failed', 
                usage_description = ?,
                date_last_updated = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $description, $otpId);
        $stmt->execute();
    }

    private function markOTPAsExpired($otpId, $description) {
        $stmt = $this->conn->prepare("
            UPDATE otp_requests 
            SET status = 'expired', 
                usage_description = ?,
                date_last_updated = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $description, $otpId);
        $stmt->execute();
    }
}
