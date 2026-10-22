<?php
// tenant/backend/tenant/request_prelease_cancellation.php
// Tenant requests cancellation BEFORE their lease starts.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'request_prelease_cancellation',
    'rate_limit' => [5, 300],
    'roles'      => ['Tenant'],
]);

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) {
    json_error("Session expired", 401);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}
if (!is_array($input)) {
    json_error("Invalid input data", 400);
}

// ---- Validation ----
$cancellationDate = trim((string) ($input['cancellation_date'] ?? ''));
$reason           = trim((string) ($input['reason'] ?? ''));
$notes            = trim((string) ($input['notes'] ?? ''));

if ($cancellationDate === '') {
    json_error("Cancellation date is required", 400);
}
$dateObj = DateTime::createFromFormat('Y-m-d', $cancellationDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $cancellationDate) {
    json_error("Invalid cancellation date format. Use YYYY-MM-DD", 400);
}
if ($reason === '') {
    json_error("Please provide a reason for cancellation", 400);
}
if (strlen($reason) > 100) {
    json_error("Reason is too long (max 100 characters)", 400);
}
if (strlen($notes) > 1000) {
    json_error("Notes are too long (max 1000 characters)", 400);
}

$conn->begin_transaction();

try {
    // ---- Load the tenant ----
    $stmt = $conn->prepare("
        SELECT tenant_code, apartment_code, property_code,
               lease_start_date, lease_end_date,
               status, tenant_status, evacuation_status,
               firstname, lastname,
               rent_balance
        FROM tenants
        WHERE tenant_code = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        throw new Exception("Tenant not found", 404);
    }

    // ---- Rule 1: Must be an active tenant ----
    if ((int) $tenant['status'] !== 1 || $tenant['tenant_status'] !== '1') {
        throw new Exception(
            "You can only cancel a booking while your tenancy is active. "
            . "Your account is not in an eligible state.",
            403
        );
    }

    // ---- Rule 2: Lease must not have started yet ----
    $today = date('Y-m-d');
    if ($tenant['lease_start_date'] <= $today) {
        throw new Exception(
            "Your lease has already started. To end your tenancy, please "
            . "use the 'Request Move-out' option instead.",
            403
        );
    }

    // ---- Rule 3: Cancellation date must not be after lease start ----
    if ($cancellationDate > $tenant['lease_start_date']) {
        throw new Exception(
            "Cancellation date cannot be after your lease start date ("
            . date('F j, Y', strtotime($tenant['lease_start_date'])) . ").",
            400
        );
    }

    // ---- Rule 4: No existing pending request ----
    $dupStmt = $conn->prepare("
        SELECT request_id, request_type, status
        FROM evacuation_requests
        WHERE tenant_code = ?
          AND status IN ('pending_review', 'approved')
        LIMIT 1
        FOR UPDATE
    ");
    $dupStmt->bind_param("s", $tenantCode);
    $dupStmt->execute();
    $existing = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if ($existing) {
        $msg = $existing['request_type'] === 'prelease_cancellation'
            ? "You already have a pending cancellation request."
            : "You already have an evacuation request in progress.";
        throw new Exception($msg, 409);
    }

    // ================================================================
    // NEW: Fetch the upcoming (pre-lease) rent cycle
    // ================================================================
    $cycleStmt = $conn->prepare("
        SELECT rent_payment_id,
               amount AS cycle_rent_amount,
               amount_paid AS cycle_amount_paid,
               balance AS cycle_balance,
               period_start_date,
               period_end_date,
               payment_amount_per_period
        FROM rent_payments
        WHERE tenant_code = ?
          AND apartment_code = ?
          AND payment_type = 'rent'
          AND period_start_date > CURDATE()
          AND status IN ('ongoing', 'completed', 'pending')
        ORDER BY period_start_date ASC
        LIMIT 1
    ");
    $cycleStmt->bind_param("ss", $tenantCode, $tenant['apartment_code']);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) {
        throw new Exception(
            "Unable to locate your upcoming rent cycle. Please contact support.",
            500
        );
    }

    // ================================================================
    // NEW: Compute total paid in cycle
    // ================================================================
    $paidStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
        FROM rent_payment_tracker
        WHERE rent_payment_id = ?
          AND tenant_code = ?
          AND status = 'paid'
    ");
    $paidStmt->bind_param("ss", $cycle['rent_payment_id'], $tenantCode);
    $paidStmt->execute();
    $totalPaidInCycle = (float) $paidStmt->get_result()->fetch_assoc()['total_paid'];
    $paidStmt->close();

    // ================================================================
    // NEW: Compute cycle length
    // ================================================================
    $cycleStartDt = new DateTime($cycle['period_start_date']);
    $cycleEndDt   = new DateTime($cycle['period_end_date']);
    $cycleTotalDays = (int) $cycleStartDt->diff($cycleEndDt)->days + 1;

    // ================================================================
    // NEW: Pre-lease settlement — 100% to tenant, no pro-rating
    // ================================================================
    $cycleRentAmount     = (float) $cycle['cycle_rent_amount'];
    $cycleDaysUsed       = 0; // Never occupied
    $rentUsed            = 0.0;
    $unusedRent          = round($totalPaidInCycle, 2);
    $tenantRentShare     = round($totalPaidInCycle, 2);   // 100% to tenant
    $landlordRentShare   = 0.0;

    // ================================================================
    // NEW: Outstanding fees (pending/overdue)
    // ================================================================
    // $feesStmt = $conn->prepare("
    //     SELECT COALESCE(SUM(amount), 0) AS total
    //     FROM tenant_fees
    //     WHERE tenant_code = ?
    //       AND status IN ('pending', 'overdue')
    // ");
    // $feesStmt->bind_param("s", $tenantCode);
    // $feesStmt->execute();
    // $outstandingAmount = (float) $feesStmt->get_result()->fetch_assoc()['total'];
    // $feesStmt->close();

    // $hasOutstanding = $outstandingAmount > 0.01 ? 1 : 0;

        // ================================================================
    // Outstanding balance — mirrors standard flow
    // ================================================================
    // `outstanding_amount` on evacuation_requests is meant to capture
    // the tenant's RENT BALANCE (same as standard evacuation), NOT the
    // sum of tenant_fees. The admin process modal fetches fees
    // separately and deducts them via `outstanding_fees_total`, so if
    // we set outstanding_amount = fees_sum here, the process modal
    // double-counts that money.
    //
    // The tenant's `rent_balance` was already fetched above in the
    // tenant SELECT. For a pre-lease tenant, it's typically 0 (they
    // paid at onboarding), but we honor the actual value to stay
    // consistent with the standard flow.
    $outstandingAmount = (float) ($tenant['rent_balance'] ?? 0.0);
    $hasOutstanding    = $outstandingAmount > 0.01 ? 1 : 0;

    // ================================================================
    // NEW: Preview the refund (before damages)
    // ================================================================
    $securityDeposit = 0.0;
    $depStmt = $conn->prepare("
        SELECT amount FROM payments
        WHERE tenant_code = ?
          AND apartment_code = ?
          AND payment_category = 'security_deposit'
          AND payment_status = 'completed'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $depStmt->bind_param("ss", $tenantCode, $tenant['apartment_code']);
    $depStmt->execute();
    if ($depRow = $depStmt->get_result()->fetch_assoc()) {
        $securityDeposit = (float) $depRow['amount'];
    }
    $depStmt->close();

    $estimatedRefundBeforeDamages = round(
        $tenantRentShare + $securityDeposit,
        2
    );

    // ---- Create the request ----
    $requestId   = 'EVAC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $requestType = 'prelease_cancellation';

    // Keep backward-compat fields at their defaults
    $earlyTerminationApplicable = 0;
    $earlyTerminationFee        = 0.00;

    // ================================================================
    // UPDATED: Full INSERT — mirror the standard flow's fields
    // ================================================================
    $insertStmt = $conn->prepare("
        INSERT INTO evacuation_requests (
            request_id, tenant_code, apartment_code,
            requested_move_out_date, reason, notes,
            request_type, status,
            has_outstanding_balance, outstanding_amount,
            early_termination_fee_applicable, early_termination_fee,
            settled_rent_payment_id,
            cycle_rent_amount, cycle_total_days, cycle_days_used,
            total_paid_in_cycle, rent_used, unused_rent,
            tenant_rent_share, landlord_rent_share,
            created_at
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, 'pending_review',
            ?, ?,
            ?, ?,
            ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            NOW()
        )
    ");

    // Type string:
    //  sss           = request_id, tenant_code, apartment_code
    //  sss           = requested_move_out_date, reason, notes
    //  s             = request_type
    //  i d           = has_outstanding_balance, outstanding_amount
    //  i d           = early_termination_fee_applicable, early_termination_fee
    //  s             = settled_rent_payment_id
    //  d i i         = cycle_rent_amount, cycle_total_days, cycle_days_used
    //  d d d         = total_paid_in_cycle, rent_used, unused_rent
    //  d d           = tenant_rent_share, landlord_rent_share
    // = 21 params

        $insertStmt->bind_param(
        "sssssssididsdiiddddd",   // 20 chars for 20 placeholders
        $requestId,                    // 1  s
        $tenantCode,                   // 2  s
        $tenant['apartment_code'],     // 3  s
        $cancellationDate,             // 4  s
        $reason,                       // 5  s
        $notes,                        // 6  s
        $requestType,                  // 7  s
        $hasOutstanding,               // 8  i
        $outstandingAmount,            // 9  d
        $earlyTerminationApplicable,   // 10 i
        $earlyTerminationFee,          // 11 d
        $cycle['rent_payment_id'],     // 12 s
        $cycleRentAmount,              // 13 d
        $cycleTotalDays,               // 14 i
        $cycleDaysUsed,                // 15 i
        $totalPaidInCycle,             // 16 d
        $rentUsed,                     // 17 d
        $unusedRent,                   // 18 d
        $tenantRentShare,              // 19 d
        $landlordRentShare             // 20 d
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create request: " . $insertStmt->error);
    }
    $insertStmt->close();

    // ================================================================
    // NEW: Update tenant state (same as standard flow)
    // ================================================================
    $updateTenant = $conn->prepare("
        UPDATE tenants
        SET can_request_evacuation = 0,
            evacuation_request_id = ?,
            evacuation_status = 'pending_evacuation',
            last_updated_at = NOW()
        WHERE tenant_code = ?
    ");
    $updateTenant->bind_param("ss", $requestId, $tenantCode);
    if (!$updateTenant->execute()) {
        throw new Exception("Failed to update tenant flag: " . $updateTenant->error);
    }
    $updateTenant->close();

    // ---- Notify all active admins ----
    try {
        $admins = $conn->query("
            SELECT unique_id FROM admin_tbl
            WHERE status = 1
            LIMIT 20
        ");
        while ($admin = $admins->fetch_assoc()) {
            createNotification(
                $conn,
                $admin['unique_id'],
                'evacuation',
                'New Pre-Lease Cancellation Request',
                "Tenant {$tenant['firstname']} {$tenant['lastname']} "
                . "({$tenantCode}) has requested to cancel their booking "
                . "before lease start. Estimated refund: ₦"
                . number_format($estimatedRefundBeforeDamages, 2),
                [
                    'request_id'   => $requestId,
                    'request_type' => $requestType,
                ],
                'normal',
                '../evacuation_requests.php',
                'Review Request'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("Cancellation admin notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    logActivity("Pre-lease cancellation request created: {$requestId} for {$tenantCode}");

    json_success([
        'request_id'        => $requestId,
        'cancellation_date' => $cancellationDate,
        'status'            => 'pending_review',
        'settlement_preview' => [
            'cycle_start'              => $cycle['period_start_date'],
            'cycle_end'                => $cycle['period_end_date'],
            'cycle_rent_amount'        => $cycleRentAmount,
            'cycle_total_days'         => $cycleTotalDays,
            'cycle_days_used'          => 0,
            'total_paid_in_cycle'      => $totalPaidInCycle,
            'rent_used'                => 0,
            'unused_rent'              => $unusedRent,
            'tenant_share'             => $tenantRentShare,
            'landlord_share'           => $landlordRentShare,
            'security_deposit'         => $securityDeposit,
            'outstanding_fees'         => $outstandingAmount,
            'estimated_refund_before_damages' => $estimatedRefundBeforeDamages,
        ],
    ], 'Cancellation request submitted. Admin will review it shortly.');

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Pre-lease cancellation error: " . $e->getMessage(), 'ERROR');
    $code = (int) $e->getCode();
    $status = ($code >= 400 && $code < 600) ? $code : 500;
    json_error($e->getMessage(), $status);
}