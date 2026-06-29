<?php
// admin/backend/payment/admin_initiate_rent_payment.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../../../tenant/backend/utilities/notification_helper.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

$requestId = uniqid('admin_rent_payment_', true);
logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] ========== START ==========");
logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

// Helper functions
function generateReceiptNumber($tenant_code)
{
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -6));
    return "RENT-{$date}-{$random}";
}

function generateReferenceNumber($tenant_code)
{
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    $random = rand(1000, 9999);
    return "ADMIN-{$date}-{$tenant_short}-{$random}";
}

function calculateDueDate($period_end_date, $payment_frequency)
{
    $gracePeriods = [
        'Monthly' => 7,
        'Quarterly' => 14,
        'Semi-Annually' => 30,
        'Annually' => 90
    ];
    $daysToAdd = $gracePeriods[$payment_frequency] ?? 7;
    $dueDate = new DateTime($period_end_date);
    $dueDate->modify("+{$daysToAdd} days");
    return $dueDate->format('Y-m-d');
}

/**
 * Insert a new rent payment attempt record
 * 
 * @param mysqli $conn Database connection
 * @param int $tracker_id The rent_payment_tracker ID
 * @param string $tenant_code Tenant code
 * @param string $apartment_code Apartment code
 * @param int $period_number Period number
 * @param float $amount Payment amount
 * @param string $status Initial status (initiated/pending_verification)
 * @param string $initiated_by Who initiated (tenant_code or admin_id)
 * @param string $initiated_by_type 'tenant' or 'admin'
 * @param array $payment_details Optional payment details (method, reference, receipt, etc.)
 * @param string $notes Additional notes
 * @return int|false The inserted record ID or false on failure
 */
function insertRentPaymentAttempt($conn, $tracker_id, $tenant_code, $apartment_code, $period_number, $amount, $status, $initiated_by, $initiated_by_type, $payment_details = [], $notes = null)
{
    // Calculate attempt number (count existing attempts for this tracker)
    $attempt_query = "SELECT COUNT(*) as attempt_count FROM rent_payment_history WHERE tracker_id = ?";
    $attempt_stmt = $conn->prepare($attempt_query);
    $attempt_stmt->bind_param("i", $tracker_id);
    $attempt_stmt->execute();
    $attempt_result = $attempt_stmt->get_result();
    $attempt_count = $attempt_result->fetch_assoc()['attempt_count'] + 1;
    $attempt_stmt->close();

    $payment_method = $payment_details['payment_method'] ?? null;
    $reference_number = $payment_details['reference_number'] ?? null;
    $receipt_number = $payment_details['receipt_number'] ?? null;
    $transaction_id = $payment_details['transaction_id'] ?? null;

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $query = "INSERT INTO rent_payment_history (
        tracker_id, tenant_code, apartment_code, period_number, amount,
        attempt_number, payment_method, reference_number, receipt_number, transaction_id,
        status, initiated_by, initiated_by_type, initiated_at,
        ip_address, user_agent, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";

    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        "issidissssssssss",
        $tracker_id,
        $tenant_code,
        $apartment_code,
        $period_number,
        $amount,
        $attempt_count,
        $payment_method,
        $reference_number,
        $receipt_number,
        $transaction_id,
        $status,
        $initiated_by,
        $initiated_by_type,
        $ip_address,
        $user_agent,
        $notes
    );

    if ($stmt->execute()) {
        $history_id = $stmt->insert_id;
        $stmt->close();
        logActivity("Rent payment attempt inserted - History ID: {$history_id}, Tracker ID: {$tracker_id}, Attempt #{$attempt_count}");
        return $history_id;
    }

    $stmt->close();
    return false;
}

try {
    // ==================== STEP 1: CHECK ADMIN AUTHENTICATION ====================
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 1: Checking admin authentication");

    if (!isset($_SESSION['unique_id'])) {
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized access", 401);
    }

    $adminId = $_SESSION['unique_id'];
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Admin authenticated: ID={$adminId}");

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 2: Getting input data");

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }

    $tenant_code = $input['tenant_code'] ?? '';
    $period_number = $input['period_number'] ?? 0;
    $notes = $input['notes'] ?? '';

    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Input: tenant_code={$tenant_code}, period_number={$period_number}");

    // ==================== STEP 3: VALIDATE INPUT ====================
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 3: Validating input");

    if (empty($tenant_code)) {
        json_error("Tenant code is required", 400);
    }

    if (empty($period_number)) {
        json_error("Period number is required", 400);
    }

    // ==================== STEP 4: CHECK OTP VERIFICATION IN SESSION ====================
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 4: Checking OTP verification");

    if (!isset($_SESSION['payment_auth'][$tenant_code]) || !$_SESSION['payment_auth'][$tenant_code]['verified']) {
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] ERROR: Payment not authorized - OTP not verified");
        json_error("Payment not authorized. Please request and verify OTP first.", 403);
    }

    $verification = $_SESSION['payment_auth'][$tenant_code];
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] OTP verified at: {$verification['verified_at']}");

    // Clear verification after use
    unset($_SESSION['payment_auth'][$tenant_code]);
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] OTP verification cleared");

    // ==================== STEP 5: START TRANSACTION ====================
    $conn->begin_transaction();
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Transaction started");

    try {
        // ==================== STEP 6: GET TENANT DETAILS ====================
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 6: Fetching tenant details");

        $tenant_query = "
            SELECT 
                t.tenant_code,
                t.apartment_code,
                t.payment_frequency,
                t.payment_amount_per_period,
                t.agreed_payment_frequency,
                a.apartment_number,
                p.name as property_name
            FROM tenants t
            JOIN apartments a ON t.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            WHERE t.tenant_code = ? AND t.deleted_at IS NULL
            LIMIT 1
        ";

        $tenant_stmt = $conn->prepare($tenant_query);
        $tenant_stmt->bind_param("s", $tenant_code);
        $tenant_stmt->execute();
        $tenant = $tenant_stmt->get_result()->fetch_assoc();
        $tenant_stmt->close();

        if (!$tenant) {
            throw new Exception("Tenant information not found", 404);
        }

        $payment_frequency = $tenant['agreed_payment_frequency'] ?? $tenant['payment_frequency'];
        $payment_per_period = (float) ($tenant['payment_amount_per_period'] ?? 0);

        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Tenant: {$tenant['property_name']}, Amount: ₦{$payment_per_period}");

        // ==================== STEP 7: CHECK FOR PENDING VERIFICATION ====================
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 7: Checking for pending verification");

        $pending_check_query = "
            SELECT tracker_id, period_number
            FROM rent_payment_tracker
            WHERE tenant_code = ? AND apartment_code = ? AND status = 'pending_verification'
            LIMIT 1
        ";

        $pending_stmt = $conn->prepare($pending_check_query);
        $pending_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
        $pending_stmt->execute();
        $pending_payment = $pending_stmt->get_result()->fetch_assoc();
        $pending_stmt->close();

        if ($pending_payment) {
            throw new Exception(
                "Tenant has a pending payment for Period #{$pending_payment['period_number']} waiting for verification. Please verify or reject that payment first.",
                400
            );
        }

        // ==================== STEP 8: FIND THE SPECIFIED PERIOD ====================
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Step 8: Finding period #{$period_number}");

        $period_query = "
            SELECT tracker_id, rent_payment_id, period_number, start_date, end_date, status
            FROM rent_payment_tracker
            WHERE tenant_code = ? AND apartment_code = ? AND period_number = ?
        ";

        $period_stmt = $conn->prepare($period_query);
        $period_stmt->bind_param("ssi", $tenant_code, $tenant['apartment_code'], $period_number);
        $period_stmt->execute();
        $target_period = $period_stmt->get_result()->fetch_assoc();
        $period_stmt->close();

        if (!$target_period) {
            throw new Exception("Period #{$period_number} not found", 404);
        }

        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Period status: {$target_period['status']}");

        if ($target_period['status'] === 'paid') {
            throw new Exception("Period #{$period_number} has already been paid", 400);
        }

        if ($target_period['status'] === 'pending_verification') {
            throw new Exception("Period #{$period_number} already has a pending payment", 400);
        }

        // ==================== STEP 9: GENERATE IDENTIFIERS ====================
        $receipt_number = generateReceiptNumber($tenant_code);
        $reference_number = generateReferenceNumber($tenant_code);
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);
        $payment_date = date('Y-m-d H:i:s');
        $due_date = calculateDueDate($target_period['end_date'], $payment_frequency);

        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Receipt: {$receipt_number}");

        // ==================== STEP 10: UPDATE TRACKER RECORD ====================
        $update_tracker_query = "
            UPDATE rent_payment_tracker 
            SET status = 'pending_verification',
                payment_date = ?,
                payment_reference = ?,
                payment_method = 'admin_initiated',
                amount_paid = ?,
                admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[', NOW(), '] Admin initiated payment. Receipt: ', ?, '. Notes: ', ?)
            WHERE tracker_id = ?
            AND status IN ('available', 'failed')
        ";

        $update_stmt = $conn->prepare($update_tracker_query);
        $update_stmt->bind_param("ssdssi", $payment_date, $reference_number, $payment_per_period, $receipt_number, $notes, $target_period['tracker_id']);

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update tracker: " . $update_stmt->error);
        }

        if ($update_stmt->affected_rows == 0) {
            throw new Exception("Failed to initiate payment. Period may have been already paid or not available.", 400);
        }
        $update_stmt->close();

        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Tracker updated to pending_verification");


        // ================= INSERT RECORD INTO RENT PAYMENT HISTORY TABLE ==================================
        // After updating the tracker record, insert into history
        $history_id = insertRentPaymentAttempt(
            $conn,
            $target_period['tracker_id'],
            $tenant_code,
            $tenant['apartment_code'],
            $period_number,
            $payment_per_period,
            'pending_verification',
            (string) $adminId,        // initiated_by (admin ID)
            'admin',                  // initiated_by_type
            [
                'payment_method' => 'admin_initiated',
                'reference_number' => $reference_number,
                'receipt_number' => $receipt_number,
                'transaction_id' => $transaction_id
            ],
            $notes
        );

        // ==================== STEP 11: CREATE NOTIFICATION ====================
        $notification_title = "Rent Payment Initiated by Admin";
        $notification_message = "Your rent payment for Period #{$period_number} (₦" . number_format($payment_per_period, 2) .
            ") has been initiated by an admin. Receipt: {$receipt_number}. Please wait for verification.";



        createNotification($conn, $tenant_code, 'payment', $notification_title, $notification_message, [
            'period_number' => $period_number,
            'amount' => $payment_per_period,
            'receipt_number' => $receipt_number,
            'initiated_by_admin' => true
        ], 'high');

        // ==================== STEP 12: COMMIT ====================
        $conn->commit();
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Transaction committed");

        // ==================== STEP 13: RESPONSE ====================
        $response_data = [
            'tracker_id' => $target_period['tracker_id'],
            'period_number' => $period_number,
            'receipt_number' => $receipt_number,
            'reference_number' => $reference_number,
            'amount' => $payment_per_period,
            'due_date' => $due_date,
            'payment_date' => date('Y-m-d'),
            'payment_method' => 'admin_initiated',
            'property_name' => $tenant['property_name'],
            'apartment_number' => $tenant['apartment_number'],
            'status' => 'pending_verification',
            'transaction_id' => $transaction_id
        ];

        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] ========== SUCCESS ==========");
        json_success($response_data, "Payment initiated successfully");

    } catch (Exception $e) {
        $conn->rollback();
        logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] Rollback: " . $e->getMessage());
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[ADMIN_RENT_PAYMENT] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
