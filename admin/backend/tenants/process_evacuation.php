<?php
// process_evacuation.php - Admin processes final move-out and settlement
//
// Scope (this revision):
//   - Outstanding fees auto-deducted and marked paid with full audit trail
//   - Fee settlements recorded in `payments` as category='fee'
//   - Refund recorded in `payments` as category='refund'
//   - Settlement-level receipt stored on the request
//   - Shortfalls (refund > deposit coverage) logged to settlement_shortfalls
//
// Out of scope (business decision pending):
//   - Reversing the 85/10/5 rent split
//   - Who bears shortfall losses
//   - Automated refund disbursement

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/evacuation_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

if (!$input) {
    json_error("Invalid input data", 400);
}

$request_id = trim($input['request_id'] ?? '');
$deductions = $input['deductions'] ?? [];
$actual_move_out_date = trim($input['actual_move_out_date'] ?? date('Y-m-d'));
$includeOutstandingFees = !isset($input['include_outstanding_fees'])
    || $input['include_outstanding_fees'] === true;

// ==================== VALIDATION ====================
if ($request_id === '') {
    json_error("Request ID is required", 400);
}

$moveOutDate = DateTime::createFromFormat('Y-m-d', $actual_move_out_date);
if (!$moveOutDate || $moveOutDate->format('Y-m-d') !== $actual_move_out_date) {
    json_error("Invalid actual move-out date format. Use YYYY-MM-DD", 400);
}

if (!is_array($deductions)) {
    json_error("Deductions must be an array", 400);
}

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
        'description' => trim($d['description'] ?? ''),
        'source' => 'manual',
        'source_id' => null,
    ];
    $total_deductions += (float) $d['amount'];
}
$total_deductions = round($total_deductions, 2);

// Populated inside the transaction
$outstandingFees = [];
$outstandingFeesTotal = 0.0;

$conn->begin_transaction();

try {
    // ================================================================
    // FETCH APPROVED REQUEST
    // ================================================================
    $stmt = $conn->prepare("
        SELECT er.*,
               t.rent_balance, t.created_by, t.apartment_code AS tenant_apt,
               a.security_deposit, a.apartment_code, a.rent_amount, a.property_code
        FROM evacuation_requests er
        JOIN tenants t   ON er.tenant_code = t.tenant_code
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

    // ================================================================
    // FETCH OUTSTANDING FEES (if requested)
    // ================================================================
    if ($includeOutstandingFees) {
        $feeStmt = $conn->prepare("
            SELECT
                tf.tenant_fee_id,
                tf.fee_type_id,
                tf.amount,
                tf.due_date,
                tf.status,
                tf.notes,
                ft.fee_name,
                ft.fee_code
            FROM tenant_fees tf
            LEFT JOIN fee_types ft ON ft.fee_type_id = tf.fee_type_id
            WHERE tf.tenant_code = ?
              AND tf.status IN ('pending', 'overdue')
            ORDER BY tf.due_date ASC
            FOR UPDATE
        ");
        $feeStmt->bind_param("s", $request['tenant_code']);
        $feeStmt->execute();
        $feeResult = $feeStmt->get_result();

        while ($fee = $feeResult->fetch_assoc()) {
            $feeAmount = round((float) $fee['amount'], 2);

            $outstandingFees[] = [
                'tenant_fee_id' => (int) $fee['tenant_fee_id'],
                'fee_type_id' => (int) $fee['fee_type_id'],
                'fee_name' => $fee['fee_name'] ?: 'Unnamed Fee',
                'fee_code' => $fee['fee_code'] ?: '',
                'amount' => $feeAmount,
                'due_date' => $fee['due_date'],
                'status' => $fee['status'],
            ];

            $cleanDeductions[] = [
                'type' => 'Outstanding Fee',
                'amount' => $feeAmount,
                'description' => sprintf(
                    '%s (due %s, was %s)',
                    $fee['fee_name'] ?: 'Unnamed Fee',
                    $fee['due_date'],
                    $fee['status']
                ),
                'source' => 'tenant_fee',
                'source_id' => (int) $fee['tenant_fee_id'],
            ];

            $outstandingFeesTotal += $feeAmount;
        }
        $feeStmt->close();
        $outstandingFeesTotal = round($outstandingFeesTotal, 2);

        $total_deductions = round($total_deductions + $outstandingFeesTotal, 2);

        logActivity("Outstanding fees included: " . count($outstandingFees)
            . " | Total: ₦{$outstandingFeesTotal}");
    } else {
        logActivity("Outstanding fees excluded (admin choice)");
    }

    // ================================================================
    // RE-IDENTIFY CURRENT CYCLE
    // ================================================================
    try {
        $currentCycle = getCurrentRentCycle($conn, $request['tenant_code']);
    } catch (Exception $cycleErr) {
        throw new Exception($cycleErr->getMessage(), 500);
    }

    $cycle_start = new DateTime($currentCycle['period_start_date']);
    $cycle_end = new DateTime($currentCycle['period_end_date']);
    $cycle_start->setTime(0, 0, 0);
    $cycle_end->setTime(0, 0, 0);

    $total_paid_in_cycle = getTotalPaidInCycle(
        $conn,
        $currentCycle['rent_payment_id'],
        $request['tenant_code']
    );

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

    // ================================================================
    // COMPUTE SETTLEMENT
    // ================================================================
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

    // ================================================================
    // GENERATE SETTLEMENT-LEVEL IDENTIFIERS
    // ================================================================
    $settlementReceipt = 'EVSET-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $settlementTxnId = 'TXN-EVSET-' . time() . '-' . substr(md5($request_id), 0, 6);

    logActivity("Settlement identifiers: Receipt={$settlementReceipt} | Txn={$settlementTxnId}");

    // ================================================================
    // UPDATE EVACUATION REQUEST
    // ================================================================
    $updateRequest = $conn->prepare("
        UPDATE evacuation_requests
        SET status = 'completed',
            settled_rent_payment_id    = ?,
            cycle_rent_amount          = ?,
            cycle_total_days           = ?,
            cycle_days_used            = ?,
            total_paid_in_cycle        = ?,
            rent_used                  = ?,
            unused_rent                = ?,
            tenant_rent_share          = ?,
            landlord_rent_share        = ?,
            damages_total              = ?,
            damages_from_deposit       = ?,
            damages_from_share         = ?,
            damages_owed_by_tenant     = ?,
            final_settlement_amount    = ?,
            security_deposit_refund    = ?,
            settlement_receipt_number  = ?,
            settlement_transaction_id  = ?,
            processed_by               = ?,
            processed_at               = NOW()
        WHERE request_id = ?
    ");
        $updateRequest->bind_param(
        "sdiidddddddddddssis",    // ← 19 chars — CORRECT
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
        $settlementReceipt,
        $settlementTxnId,
        $adminId,
        $request_id
    );
    if (!$updateRequest->execute()) {
        throw new Exception("Failed to update evacuation request: " . $updateRequest->error);
    }
    $updateRequest->close();

    // ================================================================
    // INSERT DEDUCTIONS (with source traceability)
    // ================================================================
    if (!empty($cleanDeductions)) {
        $insertDeduction = $conn->prepare("
            INSERT INTO evacuation_deductions
            (request_id, tenant_code, deduction_type, amount, description, source, source_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($cleanDeductions as $d) {
            $insertDeduction->bind_param(
                "sssdssi",
                $request_id,
                $request['tenant_code'],
                $d['type'],
                $d['amount'],
                $d['description'],
                $d['source'],
                $d['source_id']
            );
            if (!$insertDeduction->execute()) {
                throw new Exception("Failed to insert deduction: " . $insertDeduction->error);
            }
        }
        $insertDeduction->close();
    }

    // ================================================================
    // SETTLE OUTSTANDING FEES + RECORD PAYMENTS
    // ================================================================
    $settledFeeReceipts = [];

    if (!empty($outstandingFees)) {
        // Shared prepared statements
        $updateFee = $conn->prepare("
            UPDATE tenant_fees
            SET status = 'paid',
                payment_date = NOW(),
                payment_method = 'settlement',
                receipt_number = ?,
                payment_id = ?,
                notes = CONCAT(
                    COALESCE(notes, ''),
                    CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE '\n' END,
                    'Settled via evacuation ', ?, ' | Receipt: ', ?
                ),
                updated_at = NOW()
            WHERE tenant_fee_id = ?
        ");

        $insertPayment = $conn->prepare("
            INSERT INTO payments (
                tenant_code, apartment_code, amount, balance,
                payment_date, due_date, payment_method, payment_status,
                receipt_number, reference_number, description, recorded_by,
                payment_category, created_at
            ) VALUES (?, ?, ?, 0, NOW(), ?, 'settlement', 'completed',
                      ?, ?, ?, ?, 'fee', NOW())
        ");

        foreach ($outstandingFees as $fee) {
            $feeAmount = $fee['amount'];
            $feeReceipt = 'EVFEE-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $feeTxnId = 'TXN-EVFEE-' . time() . '-' . $fee['tenant_fee_id'];
            $feeDesc = "Settled via evacuation {$request_id} | "
                . ($fee['fee_name'] ?: 'Fee') . " (" . $fee['fee_code'] . ")";

            // 1. Insert payments row
            $insertPayment->bind_param(
                "ssdssssi",
                $request['tenant_code'],
                $request['apartment_code'],
                $feeAmount,
                $fee['due_date'],
                $feeReceipt,
                $feeTxnId,
                $feeDesc,
                $adminId
            );
            if (!$insertPayment->execute()) {
                throw new Exception("Failed to insert fee payment: " . $insertPayment->error);
            }
            $feePaymentId = $insertPayment->insert_id;

            // 2. Update tenant_fees with audit trail
            $updateFee->bind_param(
                "sissi",
                $feeReceipt,
                $feePaymentId,
                $request_id,
                $feeReceipt,
                $fee['tenant_fee_id']
            );
            if (!$updateFee->execute()) {
                throw new Exception("Failed to update fee with receipt: " . $updateFee->error);
            }

            $settledFeeReceipts[] = [
                'tenant_fee_id' => $fee['tenant_fee_id'],
                'fee_name' => $fee['fee_name'],
                'amount' => $feeAmount,
                'receipt' => $feeReceipt,
                'payment_id' => $feePaymentId,
            ];

            logActivity("Fee settled: ID={$fee['tenant_fee_id']} | Receipt={$feeReceipt} | PaymentID={$feePaymentId}");
        }

        $updateFee->close();
        $insertPayment->close();
    }

    // ================================================================
    // RECORD REFUND IN PAYMENTS (if applicable)
    // ================================================================
    $refundReceipt = null;
    $refundTxnId = null;
    $refundPaymentId = null;

    if ($final_settlement > 0) {
        $refundReceipt = 'EVRFD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $refundTxnId = 'TXN-EVRFD-' . time() . '-' . rand(1000, 9999);
        $refundDesc = "Evacuation refund for {$request_id} (move-out {$actual_move_out_date})";

        $refundStmt = $conn->prepare("
            INSERT INTO payments (
                tenant_code, apartment_code, amount, balance,
                payment_date, due_date, payment_method, payment_status,
                receipt_number, reference_number, description, recorded_by,
                payment_category, created_at
            ) VALUES (?, ?, ?, 0, NOW(), NOW(), 'settlement', 'completed',
                      ?, ?, ?, ?, 'refund', NOW())
        ");
        $refundStmt->bind_param(
            "ssdsssi",
            $request['tenant_code'],
            $request['apartment_code'],
            $final_settlement,
            $refundReceipt,
            $refundTxnId,
            $refundDesc,
            $adminId
        );
        if (!$refundStmt->execute()) {
            throw new Exception("Failed to record refund: " . $refundStmt->error);
        }
        $refundPaymentId = $refundStmt->insert_id;
        $refundStmt->close();

        logActivity("Refund recorded: ₦{$final_settlement} | Receipt={$refundReceipt} | PaymentID={$refundPaymentId}");
    }

    // ================================================================
    // LOG SHORTFALL (refund exceeds what the deposit covered)
    // ================================================================
    $shortfallAmount = 0.0;

    if ($final_settlement > 0 && $final_settlement > $security_deposit_refund) {
        $shortfallAmount = round($final_settlement - $security_deposit_refund, 2);

        $shortfallStmt = $conn->prepare("
            INSERT INTO settlement_shortfalls
                (request_id, tenant_code, apartment_code,
                 total_refund, deposit_covered, shortfall_amount, status)
            VALUES (?, ?, ?, ?, ?, ?, 'unresolved')
        ");
        $shortfallStmt->bind_param(
            "sssddd",
            $request_id,
            $request['tenant_code'],
            $request['apartment_code'],
            $final_settlement,
            $security_deposit_refund,
            $shortfallAmount
        );
        if (!$shortfallStmt->execute()) {
            // Non-fatal — log but don't fail the whole transaction
            logActivity("WARNING: Failed to log shortfall: " . $shortfallStmt->error);
        }
        $shortfallStmt->close();

        logActivity("SHORTFALL logged: ₦{$shortfallAmount} "
            . "(refund ₦{$final_settlement} > deposit coverage ₦{$security_deposit_refund})");
    }

    // ================================================================
    // FREE APARTMENT
    // ================================================================
    $updateApartment = $conn->prepare("
        UPDATE apartments
        SET occupancy_status = 'NOT OCCUPIED',
            occupied_by = NULL
        WHERE apartment_code = ?
    ");
    $updateApartment->bind_param("s", $request['apartment_code']);
    $updateApartment->execute();
    $updateApartment->close();

    // ================================================================
    // DECREMENT PROPERTY COUNT
    // ================================================================
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

    // ================================================================
    // FINALIZE TENANT
    // ================================================================
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
        "sidis",
        $actual_move_out_date,
        $adminId,
        $final_settlement,
        $adminId,
        $request['tenant_code']
    );
    if (!$updateTenant->execute()) {
        throw new Exception("Failed to update tenant: " . $updateTenant->error);
    }
    $updateTenant->close();

    // ================================================================
    // NOTIFY TENANT
    // ================================================================
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

        if (!empty($outstandingFees)) {
            $notifMessage .= " " . count($outstandingFees)
                . " outstanding fee(s) totaling ₦"
                . number_format($outstandingFeesTotal, 2)
                . " were settled from your deposit.";
        }

        if ($refundReceipt) {
            $notifMessage .= " Refund receipt: {$refundReceipt}.";
        }

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
                'move_out_date' => $actual_move_out_date,
                'outstanding_fees_total' => $outstandingFeesTotal,
                'outstanding_fees_count' => count($outstandingFees),
                'refund_receipt_number' => $refundReceipt,
                'settlement_receipt_number' => $settlementReceipt,
            ],
            'high',
            '../evacuation_status.php',
            'View Settlement'
        );
    } catch (Exception $notifErr) {
        logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
    }

    // ================================================================
    // COMMIT
    // ================================================================
    $conn->commit();
    logActivity("Evacuation processed: {$request_id} - settlement: ₦{$final_settlement}");
    logActivity("========== PROCESS EVACUATION - END ==========");

    // ================================================================
    // RESPONSE
    // ================================================================
    $message = $final_settlement > 0
        ? "Tenant is due a refund of ₦" . number_format($final_settlement, 2)
        : ($final_settlement < 0
            ? "Tenant owes ₦" . number_format(abs($final_settlement), 2)
            : "Settlement complete with zero balance");

    json_success(
        $message,
        [
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
                'outstanding_fees_total' => $outstandingFeesTotal,
                'manual_deductions_total' => round($total_deductions - $outstandingFeesTotal, 2),
            ],

            // Settlement-level audit
            'settlement_receipt_number' => $settlementReceipt,
            'settlement_transaction_id' => $settlementTxnId,

            // Fees settled
            'outstanding_fees' => $outstandingFees,
            'outstanding_fees_total' => $outstandingFeesTotal,
            'outstanding_fees_count' => count($outstandingFees),
            'settled_fee_receipts' => $settledFeeReceipts,

            // Refund
            'refund_receipt_number' => $refundReceipt,
            'refund_transaction_id' => $refundTxnId,
            'refund_payment_id' => $refundPaymentId,

            // Totals
            'final_settlement_amount' => $final_settlement,
            'security_deposit_refund' => $security_deposit_refund,
            'shortfall_amount' => $shortfallAmount,
            'has_shortfall' => $shortfallAmount > 0,

            // Context
            'move_out_date' => $actual_move_out_date,
        ]
    );

} catch (Throwable $e) {
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
    } catch (Throwable $ignore) {
        // rollback may fail if the connection died
    }
    logActivity("ERROR: " . $e->getMessage());
    $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}
