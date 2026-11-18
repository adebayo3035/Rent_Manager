
<?php
// tenant/utilities/otp_verification.php
// Shared OTP verification class. Library — NO headers, NO output, NO main execution.

class OTPVerification {

    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;

        logActivity("[OTP_VERIFY] OTPVerification service constructed successfully.");
    }

    public function verifyOTP($userType, $email, $otp, $usageContext = 'account reactivation') {

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $logPrefix = "OTP_VERIFY_{$userType}";
        $maskedEmail = $this->maskEmail($email);

        logActivity("[{$logPrefix}] ===== OTP VERIFICATION STARTED =====");
        logActivity("[{$logPrefix}] Email: {$maskedEmail}");
        logActivity("[{$logPrefix}] Usage Context: {$usageContext}");
        logActivity("[{$logPrefix}] Client IP: {$ip}");

        // --------------------------------------------------
        // STEP 1: RETRIEVE LATEST PENDING OTP
        // --------------------------------------------------

        logActivity("[{$logPrefix}] Step 1: Searching for latest pending OTP.");

        logActivity("[{$logPrefix}] Search criteria: User Type={$userType}, " .
            "Email={$maskedEmail}, Status=pending.");

        $stmt = $this->conn->prepare("
            SELECT id, user_id, otp, expires_at
            FROM otp_requests
            WHERE user_type = ? AND email = ? AND status = 'pending'
            ORDER BY created_at DESC
            LIMIT 1
        ");

        if (!$stmt) {

            logActivity("[{$logPrefix}] OTP lookup PREPARE failed. " .
                "Database Error: " . $this->conn->error .
                " | Error Code: " . $this->conn->errno, 'ERROR');

            return [
                'success' => false,
                'message' => 'Database error',
                'code' => 500
            ];
        }

        logActivity("[{$logPrefix}] OTP lookup statement prepared successfully.");

        $stmt->bind_param("ss", $userType, $email);

        logActivity("[{$logPrefix}] OTP lookup parameters bound successfully.");

        if (!$stmt->execute()) {

            logActivity("[{$logPrefix}] OTP lookup EXECUTION failed. " .
                "Database Error: " . $stmt->error .
                " | Error Code: " . $stmt->errno, 'ERROR');

            $stmt->close();

            return [
                'success' => false,
                'message' => 'Database error',
                'code' => 500
            ];
        }

        logActivity("[{$logPrefix}] OTP lookup query executed successfully.");

        $result = $stmt->get_result();

        if (!$result) {

            logActivity("[{$logPrefix}] Unable to retrieve OTP query result. " .
                "Database Error: " . $stmt->error, 'ERROR');

            $stmt->close();

            return [
                'success' => false,
                'message' => 'Database error',
                'code' => 500
            ];
        }

        logActivity("[{$logPrefix}] OTP lookup completed. " .
            "Matching records: " . $result->num_rows);

        $otpRecord = $result->fetch_assoc();

        $stmt->close();

        // --------------------------------------------------
        // STEP 2: CHECK WHETHER A PENDING OTP EXISTS
        // --------------------------------------------------

        if (!$otpRecord) {

            logActivity("[{$logPrefix}] OTP verification failed. " .
                "No pending OTP record found for {$maskedEmail}.", 'WARN');

            logActivity("[{$logPrefix}] Possible reasons: OTP was never generated, " .
                "OTP was already used, OTP was marked failed/expired, " .
                "or the email/user type does not match.", 'WARN');

            return [
                'success' => false,
                'message' => 'No pending OTP found',
                'code' => 404
            ];
        }

        logActivity("[{$logPrefix}] Pending OTP record found successfully.");

        logActivity("[{$logPrefix}] OTP Record ID: " . $otpRecord['id']);
        logActivity("[{$logPrefix}] Associated User ID: " . $otpRecord['user_id']);
        logActivity("[{$logPrefix}] OTP Expiry Timestamp: " . $otpRecord['expires_at']);

        // --------------------------------------------------
        // STEP 3: CHECK OTP EXPIRY
        // --------------------------------------------------

        logActivity("[{$logPrefix}] Step 3: Checking OTP expiration.");

        $expiryTimestamp = strtotime($otpRecord['expires_at']);
        $currentTimestamp = time();

        if ($expiryTimestamp === false) {

            logActivity("[{$logPrefix}] OTP expiry validation failed. " .
                "Invalid expires_at database value: " .
                $otpRecord['expires_at'], 'ERROR');

            return [
                'success' => false,
                'message' => 'Invalid OTP expiry information',
                'code' => 500
            ];
        }

        $remainingSeconds = $expiryTimestamp - $currentTimestamp;

        logActivity("[{$logPrefix}] Current Timestamp: {$currentTimestamp}");
        logActivity("[{$logPrefix}] OTP Expiry Timestamp: {$expiryTimestamp}");
        logActivity("[{$logPrefix}] OTP Remaining Lifetime: {$remainingSeconds} seconds.");

        if ($expiryTimestamp <= $currentTimestamp) {

            logActivity("[{$logPrefix}] OTP has expired. " .
                "OTP Record ID: " . $otpRecord['id'], 'WARN');

            $this->markOTPAsExpired(
                $otpRecord['id'],
                "OTP has expired before usage attempt"
            );

            return [
                'success' => false,
                'message' => 'OTP has expired',
                'code' => 400
            ];
        }

        logActivity("[{$logPrefix}] OTP expiry validation passed. OTP is still valid.");

        // --------------------------------------------------
        // STEP 4: VERIFY SUBMITTED OTP
        // --------------------------------------------------

        logActivity("[{$logPrefix}] Step 4: Comparing submitted OTP against stored OTP hash.");

        logActivity("[{$logPrefix}] OTP hash verification initiated.");

        // Never log the submitted OTP or the stored OTP hash.

        if (!password_verify($otp, $otpRecord['otp'])) {

            logActivity("[{$logPrefix}] OTP hash verification FAILED. " .
                "Submitted OTP does not match the stored hash. " .
                "OTP Record ID: " . $otpRecord['id'], 'WARN');

            logActivity("[{$logPrefix}] Marking OTP record as failed.");

            $this->markOTPAsFailed(
                $otpRecord['id'],
                "Invalid OTP attempt for $usageContext"
            );

            return [
                'success' => false,
                'message' => 'Invalid OTP',
                'code' => 400
            ];
        }

        logActivity("[{$logPrefix}] OTP hash verification SUCCESSFUL.");

        // --------------------------------------------------
        // STEP 5: MARK OTP AS USED
        // --------------------------------------------------

        logActivity("[{$logPrefix}] Step 5: Updating OTP status to used.");

        $markedAsUsed = $this->markOTPAsUsed(
            $otpRecord['id'],
            "OTP successfully verified for $usageContext"
        );

        if (!$markedAsUsed) {

            logActivity("[{$logPrefix}] CRITICAL: OTP was verified, " .
                "but its database status could not be updated to used. " .
                "Verification will not be treated as successful.", 'ERROR');

            return [
                'success' => false,
                'message' => 'Unable to complete OTP verification',
                'code' => 500
            ];
        }

        logActivity("[{$logPrefix}] OTP record successfully marked as used.");

        // --------------------------------------------------
        // STEP 6: RETURN VERIFICATION RESULT
        // --------------------------------------------------

        logActivity("[{$logPrefix}] OTP verification completed successfully.");
        logActivity("[{$logPrefix}] Verified User ID: " . $otpRecord['user_id']);
        logActivity("[{$logPrefix}] ===== OTP VERIFICATION COMPLETED =====");

        return [
            'success' => true,
            'message' => 'OTP verified successfully',
            'code' => 200,
            'user_id' => $otpRecord['user_id']
        ];
    }


    // --------------------------------------------------
    // MARK OTP AS USED
    // --------------------------------------------------

    private function markOTPAsUsed($otpId, $description) {

        logActivity("[OTP_VERIFY] Updating OTP Record {$otpId} to USED.");

        $stmt = $this->conn->prepare("
            UPDATE otp_requests
            SET status='used',
                usage_description=?,
                date_last_updated=NOW()
            WHERE id=? AND status='pending'
        ");

        if (!$stmt) {

            logActivity("[OTP_VERIFY] markOTPAsUsed PREPARE failed. " .
                "OTP ID={$otpId}, Database Error: " . $this->conn->error, 'ERROR');

            return false;
        }

        $stmt->bind_param("si", $description, $otpId);

        if (!$stmt->execute()) {

            logActivity("[OTP_VERIFY] markOTPAsUsed EXECUTION failed. " .
                "OTP ID={$otpId}, Database Error: " .
                $stmt->error, 'ERROR');

            $stmt->close();

            return false;
        }

        $affectedRows = $stmt->affected_rows;

        $stmt->close();

        logActivity("[OTP_VERIFY] markOTPAsUsed completed. " .
            "OTP ID={$otpId}, Affected Rows={$affectedRows}.");

        if ($affectedRows !== 1) {

            logActivity("[OTP_VERIFY] OTP status update unsuccessful. " .
                "Expected 1 affected row but received {$affectedRows}. " .
                "OTP may no longer be pending.", 'ERROR');

            return false;
        }

        return true;
    }


    // --------------------------------------------------
    // MARK OTP AS FAILED
    // --------------------------------------------------

    private function markOTPAsFailed($otpId, $description) {

        logActivity("[OTP_VERIFY] Updating OTP Record {$otpId} to FAILED.");

        $stmt = $this->conn->prepare("
            UPDATE otp_requests
            SET status='failed',
                usage_description=?,
                date_last_updated=NOW()
            WHERE id=? AND status='pending'
        ");

        if (!$stmt) {

            logActivity("[OTP_VERIFY] markOTPAsFailed PREPARE failed. " .
                "OTP ID={$otpId}, Database Error: " . $this->conn->error, 'ERROR');

            return false;
        }

        $stmt->bind_param("si", $description, $otpId);

        if (!$stmt->execute()) {

            logActivity("[OTP_VERIFY] markOTPAsFailed EXECUTION failed. " .
                "OTP ID={$otpId}, Database Error: " . $stmt->error, 'ERROR');

            $stmt->close();

            return false;
        }

        $affectedRows = $stmt->affected_rows;

        $stmt->close();

        logActivity("[OTP_VERIFY] markOTPAsFailed completed. " .
            "OTP ID={$otpId}, Affected Rows={$affectedRows}.");

        if ($affectedRows !== 1) {

            logActivity("[OTP_VERIFY] OTP failure status update did not affect " .
                "exactly one pending record. OTP ID={$otpId}.", 'ERROR');
        }

        return $affectedRows === 1;
    }


    // --------------------------------------------------
    // MARK OTP AS EXPIRED
    // --------------------------------------------------

    private function markOTPAsExpired($otpId, $description) {

        logActivity("[OTP_VERIFY] Updating OTP Record {$otpId} to EXPIRED.");

        $stmt = $this->conn->prepare("
            UPDATE otp_requests
            SET status='expired',
                usage_description=?,
                date_last_updated=NOW()
            WHERE id=? AND status='pending'
        ");

        if (!$stmt) {

            logActivity("[OTP_VERIFY] markOTPAsExpired PREPARE failed. " .
                "OTP ID={$otpId}, Database Error: " . $this->conn->error, 'ERROR');

            return false;
        }

        $stmt->bind_param("si", $description, $otpId);

        if (!$stmt->execute()) {

            logActivity("[OTP_VERIFY] markOTPAsExpired EXECUTION failed. " .
                "OTP ID={$otpId}, Database Error: " . $stmt->error, 'ERROR');

            $stmt->close();

            return false;
        }

        $affectedRows = $stmt->affected_rows;

        $stmt->close();

        logActivity("[OTP_VERIFY] markOTPAsExpired completed. " .
            "OTP ID={$otpId}, Affected Rows={$affectedRows}.");

        if ($affectedRows !== 1) {

            logActivity("[OTP_VERIFY] OTP expiry status update did not affect " .
                "exactly one pending record. OTP ID={$otpId}.", 'ERROR');
        }

        return $affectedRows === 1;
    }


    // --------------------------------------------------
    // EMAIL MASKING
    // --------------------------------------------------

    private function maskEmail($email) {

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[INVALID EMAIL]';
        }

        [$local, $domain] = explode('@', $email, 2);

        $show = max(1, min(3, strlen($local) - 2));

        return substr($local, 0, $show)
             . str_repeat('*', max(0, strlen($local) - $show - 1))
             . substr($local, -1) . '@' . $domain;
    }
}
