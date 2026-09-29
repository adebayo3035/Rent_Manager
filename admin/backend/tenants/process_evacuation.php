<?php
// process_evacuation.php - Admin processes final move-out and settlement

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/evacuation_helper.php';

if (!isset($_SESSION))
    session_start();
rateLimiter();

logActivity("========== PROCESS EVACUATION - START ==========");

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'process_evacuation',
    'rate_limit' => [10, 60],
    'csrf' => ['enabled' => true, 'form_name' => 'process_evacuation_form'],
    'roles' => ['Super Admin', 'Admin']
]);

$adminId = $auth['user_id'];
$userRole = $auth['role'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input)
    json_error("Invalid input data", 400);

$request_id = trim($input['request_id'] ?? '');
$deductions = $input['deductions'] ?? [];
$actual_move_out_date = trim($input['actual_move_out_date'] ?? date('Y-m-d'));

// ==================== VALIDATION ====================
if ($request_id === '')
    json_error("Request ID is required", 400);

$moveOutDate = DateTime::createFromFormat('Y-m-d', $actual_move_out_date);
if (!$moveOutDate || $moveOutDate->format('Y-m-d') !== $actual_move_out_date) {
    json_error("Invalid actual move-out date format. Use YYYY-MM-DD", 400);
}

if (!is_array($deductions))
    json_error("Deductions must be an array", 400);

$cleanDeductions = [];
$total_deductions = 0.0;
foreach ($deductions as $idx => $d) {
    if (!isset($d['type']) || trim($d['type']) === '') {
        json_error("Deduction #" . ($idx + 1) . ": type is required", 400);
    }
    if (!isset($d['amount']) || !is_numeric($d['amount']) || (float) $d['amount'] < 0) {
        json_error("Deduction #" . ($idx + 1) . ": amount must be a non-negative number", 400);
    }
    $cleanDeductions[] = [
        'type' => trim($d['type']),
        'amount' => round((float) $d['amount'], 2),
        'description' => trim($d['description'] ?? '')
    ];
    $total_deductions += (float) $d['amount'];
}
$total_deductions = round($total_deductions, 2);

$conn->begin_transaction();

try {
    // ==================== FETCH APPROVED REQUEST ====================
    $stmt = $conn->prepare("
        SELECT er.*, 
               t.rent_balance, t.created_by, t.apartment_code AS tenant_apt,
               a.security_deposit, a.apartment_code, a.rent_amount, a.property_code
        FROM evacuation_requests er
        JOIN tenants t ON er.tenant_code = t.tenant_code
        JOIN apartments a ON er.apartment_code = a.apartment_code
        WHERE er.request_id = ? AND er.status = 'approved'
        FOR UPDATE
    ");
    $stmt->bind_param("s", $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new Exception("Approved evacuation request not found", 404);
    }

    if ($userRole !== 'Super Admin' && (int) $request['created_by'] !== (int) $adminId) {
        throw new Exception("You can only process requests for tenants you onboarded", 403);
    }

    // ==================== RE-IDENTIFY CURRENT CYCLE ====================
    // We re-check at process time because the tenant might have paid more,
    // or the current cycle might have shifted since the request was submitted.
    try {
        $currentCycle = getCurrentRentCycle($conn, $request['tenant_code']);
    } catch (Exception $cycleErr) {
        throw new Exception($cycleErr->getMessage(), 500);
    }

    $cycle_start = new DateTime($currentCycle['period_start_date']);
    $cycle_end = new DateTime($currentCycle['period_end_date']);
    $cycle_start->setTime(0, 0, 0);
    $cycle_end->setTime(0, 0, 0);

    $total_paid_in_cycle = getTotalPaidInCycle($conn, $currentCycle['rent_payment_id'], $request['tenant_code']);

    $cycle_total_days = (int) $cycle_start->diff($cycle_end)->days + 1;

    // Clamp move_out to cycle
    $moveOut = new DateTime($actual_move_out_date);
    $moveOut->setTime(0, 0, 0);
    if ($moveOut < $cycle_start) {
        $cycle_days_used = 0;
    } elseif ($moveOut > $cycle_end) {
        $cycle_days_used = $cycle_total_days;
    } else {
        $cycle_days_used = (int) $cycle_start->diff($moveOut)->days + 1;
    }

    // ==================== COMPUTE SETTLEMENT ====================
    try {
        $breakdown = computeSettlementBreakdown(
            (float) $currentCycle['cycle_rent_amount'],
            $cycle_total_days,
            $cycle_days_used,
            $total_paid_in_cycle,
            (float) $request['security_deposit'],
            $total_deductions
        );
    } catch (Exception $calcErr) {
        throw new Exception($calcErr->getMessage(), 400);
    }

    $final_settlement = $breakdown['final_settlement_amount'];
    $security_deposit_refund = $breakdown['security_deposit_refund'];

    logActivity("Settlement computed for {$request_id}: " . json_encode($breakdown));

    // ==================== UPDATE REQUEST ====================
    $updateRequest = $conn->prepare("
        UPDATE evacuation_requests 
        SET status = 'completed',
            settled_rent_payment_id = ?,
            cycle_rent_amount = ?,
            cycle_total_days = ?,
            cycle_days_used = ?,
            total_paid_in_cycle = ?,
            rent_used = ?,
            unused_rent = ?,
            tenant_rent_share = ?,
            landlord_rent_share = ?,
            damages_total = ?,
            damages_from_deposit = ?,
            damages_from_share = ?,
            damages_owed_by_tenant = ?,
            final_settlement_amount = ?,
            security_deposit_refund = ?,
            processed_by = ?,
            processed_at = NOW()
        WHERE request_id = ?
    ");
    $updateRequest->bind_param(
        "sdiidddddddddddis",
        $currentCycle['rent_payment_id'],
        $currentCycle['cycle_rent_amount'],
        $cycle_total_days,
        $cycle_days_used,
        $total_paid_in_cycle,
        $breakdown['rent_used'],
        $breakdown['unused_rent'],
        $breakdown['tenant_rent_share'],
        $breakdown['landlord_rent_share'],
        $total_deductions,
        $breakdown['damages_from_deposit'],
        $breakdown['damages_from_share'],
        $breakdown['damages_owed_by_tenant'],
        $final_settlement,
        $security_deposit_refund,
        $adminId,
        $request_id
    );
    $updateRequest->execute();
    $updateRequest->close();

    // ==================== INSERT DEDUCTIONS ====================
    if (!empty($cleanDeductions)) {
        $insertDeduction = $conn->prepare("
            INSERT INTO evacuation_deductions 
            (request_id, tenant_code, deduction_type, amount, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($cleanDeductions as $d) {
            $insertDeduction->bind_param(
                "sssds",
                $request_id,
                $request['tenant_code'],
                $d['type'],
                $d['amount'],
                $d['description']
            );
            if (!$insertDeduction->execute()) {
                throw new Exception("Failed to insert deduction: " . $insertDeduction->error);
            }
        }
        $insertDeduction->close();
    }

    // ==================== FREE APARTMENT ====================
    $updateApartment = $conn->prepare("
        UPDATE apartments 
        SET occupancy_status = 'NOT OCCUPIED', 
            occupied_by = NULL
        WHERE apartment_code = ?
    ");
    $updateApartment->bind_param("s", $request['apartment_code']);
    $updateApartment->execute();
    $updateApartment->close();

    // ==================== DECREMENT PROPERTY COUNT ====================
    if (!empty($request['property_code'])) {
        $updateProperty = $conn->prepare("
            UPDATE properties 
            SET occupied_apartments = GREATEST(occupied_apartments - 1, 0)
            WHERE property_code = ?
        ");
        $updateProperty->bind_param("s", $request['property_code']);
        $updateProperty->execute();
        $updateProperty->close();
    }

    // ==================== FINALIZE TENANT ====================
    $updateTenant = $conn->prepare("
        UPDATE tenants 
        SET status = 0,
            tenant_status = '3',
            evacuation_status = 'evacuated',
            move_out_date = ?,
            evacuated_by = ?,
            evacuated_at = NOW(),
            final_settlement_amount = ?,
            early_termination_fee = 0,
            can_request_evacuation = 0,
            last_updated_by = ?,
            last_updated_at = NOW()
        WHERE tenant_code = ?
    ");
    $updateTenant->bind_param(
        "sidis",                 // 5 chars: s, i, d, i, s
        $actual_move_out_date,   // s - move_out_date
        $adminId,                // i - evacuated_by
        $final_settlement,       // d - final_settlement_amount
        $adminId,                // i - last_updated_by
        $request['tenant_code']  // s - tenant_code
    );
    if (!$updateTenant->execute()) {
        throw new Exception("Failed to update tenant: " . $updateTenant->error);
    }
    $updateTenant->close();

    // ==================== NOTIFY TENANT ====================
    try {
        if ($final_settlement > 0) {
            $refundMsg = "Final refund due: ₦" . number_format($final_settlement, 2);
        } elseif ($final_settlement < 0) {
            $refundMsg = "Outstanding amount owed: ₦" . number_format(abs($final_settlement), 2);
        } else {
            $refundMsg = "Settlement balanced with zero amount.";
        }

        $notifMessage = "Your evacuation has been completed. Move-out date: "
            . date('M j, Y', strtotime($actual_move_out_date)) . ". "
            . $refundMsg;

        createNotification(
            $conn,
            $request['tenant_code'],
            'evacuation',
            'Evacuation Completed',
            $notifMessage,
            [
                'request_id' => $request_id,
                'final_settlement_amount' => $final_settlement,
                'security_deposit_refund' => $security_deposit_refund,
                'move_out_date' => $actual_move_out_date
            ],
            'high',
            '../evacuation_status.php',
            'View Settlement'
        );
    } catch (Exception $notifErr) {
        logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
    }

    $conn->commit();
    logActivity("Evacuation processed: {$request_id} - settlement: ₦{$final_settlement}");
    logActivity("========== PROCESS EVACUATION - END ==========");

    // ==================== RESPONSE ====================
    $message = $final_settlement > 0
        ? "Tenant is due a refund of ₦" . number_format($final_settlement, 2)
        : ($final_settlement < 0
            ? "Tenant owes ₦" . number_format(abs($final_settlement), 2)
            : "Settlement complete with zero balance");

    json_success(
        $message,                    // ← string goes first
        [                            // ← payload goes second
            'request_id' => $request_id,
            'breakdown' => [
                'cycle_start' => $currentCycle['period_start_date'],
                'cycle_end' => $currentCycle['period_end_date'],
                'cycle_rent_amount' => (float) $currentCycle['cycle_rent_amount'],
                'cycle_total_days' => $cycle_total_days,
                'cycle_days_used' => $cycle_days_used,
                'total_paid_in_cycle' => $total_paid_in_cycle,
                'rent_used' => $breakdown['rent_used'],
                'unused_rent' => $breakdown['unused_rent'],
                'tenant_rent_share' => $breakdown['tenant_rent_share'],
                'landlord_rent_share' => $breakdown['landlord_rent_share'],
                'security_deposit' => (float) $request['security_deposit'],
                'damages_total' => $total_deductions,
                'damages_from_deposit' => $breakdown['damages_from_deposit'],
                'damages_from_share' => $breakdown['damages_from_share'],
                'damages_owed_by_tenant' => $breakdown['damages_owed_by_tenant'],
            ],
            'final_settlement_amount' => $final_settlement,
            'security_deposit_refund' => $security_deposit_refund,
            'move_out_date' => $actual_move_out_date
        ]
    );

} catch (Exception $e) {
    try {
        $conn->rollback();
    } catch (Exception $ignore) {
    }
    logActivity("ERROR: " . $e->getMessage());
    $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}