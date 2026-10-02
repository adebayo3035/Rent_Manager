<?php
// request_evacuation.php - Tenant submits evacuation request

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/evacuation_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

logActivity("========== REQUEST EVACUATION - START ==========");

try {
    // ==================== AUTHENTICATION ====================
    if (
        !isset($_SESSION['tenant_code'])
        || !isset($_SESSION['role'])
        || $_SESSION['role'] !== 'Tenant'
    ) {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) json_error("Invalid input data", 400);

    $requested_move_out_date = trim($input['move_out_date'] ?? '');
    $reason = trim($input['reason'] ?? '');
    $notes = trim($input['notes'] ?? '');

    // ==================== VALIDATION ====================
    if ($requested_move_out_date === '') json_error("Move-out date is required", 400);

    $move_out = DateTime::createFromFormat('Y-m-d', $requested_move_out_date);
    if (!$move_out || $move_out->format('Y-m-d') !== $requested_move_out_date) {
        json_error("Invalid move-out date format. Use YYYY-MM-DD", 400);
    }
    $move_out->setTime(0, 0, 0);

    $today = new DateTime();
    $today->setTime(0, 0, 0);
    if ($move_out < $today) json_error("Move-out date cannot be in the past", 400);

    if ($reason === '') json_error("Reason is required", 400);
    if (strlen($reason) > 255) json_error("Reason is too long (max 255 characters)", 400);
    if (strlen($notes) > 2000) json_error("Notes are too long (max 2000 characters)", 400);

    $conn->begin_transaction();

    // ==================== FETCH TENANT ====================
    $stmt = $conn->prepare("
        SELECT t.*, 
               a.apartment_code, a.rent_amount, a.security_deposit,
               p.property_code, p.name AS property_name
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ?
          AND t.status = 1
          AND t.deleted_at IS NULL
        FOR UPDATE
    ");
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant) throw new Exception("Tenant information not found or inactive", 404);

    // ==================== ELIGIBILITY CHECKS ====================
    $validation_messages = [];

    if ((int)$tenant['can_request_evacuation'] !== 1) {
        $validation_messages[] = "You are not currently eligible to submit an evacuation request.";
    }

    if ($tenant['evacuation_status'] === 'evacuated') {
        $validation_messages[] = "You have already been evacuated.";
    } elseif ($tenant['evacuation_status'] === 'pending_evacuation') {
        $validation_messages[] = "You already have an evacuation in progress.";
    }

    $pendingStmt = $conn->prepare("
        SELECT COUNT(*) AS count FROM rent_payment_tracker
        WHERE tenant_code = ? AND status = 'pending_verification'
    ");
    $pendingStmt->bind_param("s", $tenant_code);
    $pendingStmt->execute();
    $pendingCount = (int)$pendingStmt->get_result()->fetch_assoc()['count'];
    $pendingStmt->close();

    if ($pendingCount > 0) {
        $validation_messages[] = "You have {$pendingCount} payment(s) pending verification.";
    }

    $balance = (float)$tenant['rent_balance'];
    if ($balance > 0.01) {
        $validation_messages[] = "You have an outstanding balance of ₦" . number_format($balance, 2);
    }

    $existingStmt = $conn->prepare("
        SELECT COUNT(*) AS count FROM evacuation_requests
        WHERE tenant_code = ? AND status IN ('pending_review', 'approved')
    ");
    $existingStmt->bind_param("s", $tenant_code);
    $existingStmt->execute();
    $existingCount = (int)$existingStmt->get_result()->fetch_assoc()['count'];
    $existingStmt->close();

    if ($existingCount > 0) {
        $validation_messages[] = "You already have a pending or approved evacuation request.";
    }

    // ==================== IDENTIFY CURRENT CYCLE ====================
    try {
        $currentCycle = getCurrentRentCycle($conn, $tenant_code);
    } catch (Exception $cycleErr) {
        throw new Exception($cycleErr->getMessage(), 400);
    }

    $cycle_start = new DateTime($currentCycle['period_start_date']);
    $cycle_end   = new DateTime($currentCycle['period_end_date']);
    $cycle_start->setTime(0, 0, 0);
    $cycle_end->setTime(0, 0, 0);

    // Move-out must be within the current cycle
    if ($move_out < $cycle_start) {
        $validation_messages[] = "Move-out date cannot be before the current cycle start ("
            . $cycle_start->format('M j, Y') . ").";
    }
    if ($move_out > $cycle_end) {
        $validation_messages[] =
            "Move-out date cannot be after your current lease end date ("
            . $cycle_end->format('M j, Y')
            . "). If you wish to stay beyond your lease, please contact your property manager to discuss renewal options.";
    }

    if (!empty($validation_messages)) {
        throw new Exception(implode(" ", $validation_messages), 400);
    }

    // ==================== PREVIEW SETTLEMENT ====================
    $total_paid_in_cycle = getTotalPaidInCycle($conn, $currentCycle['rent_payment_id'], $tenant_code);

    try {
        $preview = computeSettlementBreakdown(
            (float)$currentCycle['cycle_rent_amount'],
            (int)$cycle_start->diff($cycle_end)->days + 1,
            (int)$cycle_start->diff($move_out)->days + 1,
            $total_paid_in_cycle,
            (float)$tenant['security_deposit'],
            0.0 // No damages at preview time
        );
    } catch (Exception $previewErr) {
        throw new Exception($previewErr->getMessage(), 400);
    }

    $unused_rent = $preview['unused_rent'];
    $tenant_share = $preview['tenant_rent_share'];
    $landlord_share = $preview['landlord_rent_share'];
    $estimated_refund_before_damages = round((float)$tenant['security_deposit'] + $tenant_share, 2);

    // ==================== GENERATE REQUEST ID ====================
    $request_id = 'EVAC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

    // ==================== INSERT REQUEST ====================
    $outstanding_amount = $balance;
    $has_outstanding = $balance > 0.01 ? 1 : 0;
    // Keep these two for backward compat — now always 0
    $early_termination_applicable = 0;
    $early_termination_fee = 0.00;

    $insertQuery = "
        INSERT INTO evacuation_requests (
            request_id, tenant_code, apartment_code, requested_move_out_date,
            reason, notes, has_outstanding_balance, outstanding_amount,
            early_termination_fee_applicable, early_termination_fee,
            settled_rent_payment_id,
            cycle_rent_amount, cycle_total_days, cycle_days_used,
            total_paid_in_cycle, rent_used, unused_rent,
            tenant_rent_share, landlord_rent_share,
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review')
    ";

    $insertStmt = $conn->prepare($insertQuery);
    if (!$insertStmt) throw new Exception("Prepare failed: " . $conn->error);

    $cycle_total_days = (int)$cycle_start->diff($cycle_end)->days + 1;
    $cycle_days_used  = (int)$cycle_start->diff($move_out)->days + 1;
    $rent_used = (float)$preview['rent_used'];

    $insertStmt->bind_param(
    "ssssssididsdiiddddd",
        $request_id,
        $tenant_code,
        $tenant['apartment_code'],
        $requested_move_out_date,
        $reason,
        $notes,
        $has_outstanding,
        $outstanding_amount,
        $early_termination_applicable,
        $early_termination_fee,
        $currentCycle['rent_payment_id'],
        $currentCycle['cycle_rent_amount'],
        $cycle_total_days,
        $cycle_days_used,
        $total_paid_in_cycle,
        $rent_used,
        $unused_rent,
        $tenant_share,
        $landlord_share
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create request: " . $insertStmt->error);
    }
    $insertStmt->close();

    // ==================== UPDATE TENANT FLAG ====================
    $updateTenant = $conn->prepare("
        UPDATE tenants 
        SET can_request_evacuation = 0, 
            evacuation_request_id = ?,
            evacuation_status = 'pending_evacuation',
            last_updated_at = NOW()
        WHERE tenant_code = ?
    ");
    $updateTenant->bind_param("ss", $request_id, $tenant_code);
    if (!$updateTenant->execute()) {
        throw new Exception("Failed to update tenant flag: " . $updateTenant->error);
    }
    $updateTenant->close();

    $conn->commit();

    logActivity("Evacuation request created: {$request_id} for tenant: {$tenant_code}");
    logActivity("========== REQUEST EVACUATION - END ==========");

    json_success([
        'request_id' => $request_id,
        'status' => 'pending_review',
        'settlement_preview' => [
            'cycle_start' => $currentCycle['period_start_date'],
            'cycle_end' => $currentCycle['period_end_date'],
            'cycle_rent_amount' => (float)$currentCycle['cycle_rent_amount'],
            'total_paid_in_cycle' => $total_paid_in_cycle,
            'rent_used' => (float)$preview['rent_used'],
            'unused_rent' => $unused_rent,
            'tenant_share' => $tenant_share,
            'landlord_share' => $landlord_share,
            'security_deposit' => (float)$tenant['security_deposit'],
            'estimated_refund_before_damages' => $estimated_refund_before_damages,
        ],
        'message' => 'Your evacuation request has been submitted and is pending admin review. '
                   . 'Estimated refund (before any damages): ₦' . number_format($estimated_refund_before_damages, 2)
    ], "Evacuation request submitted successfully");

} catch (Exception $e) {
    try { $conn->rollback(); } catch (Exception $ignore) {}
    logActivity("ERROR: " . $e->getMessage());
    $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}