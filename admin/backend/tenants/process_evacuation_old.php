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

date_default_timezone_set('Africa/Lagos');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
rateLimiter();

logActivity("========== PROCESS EVACUATION - START ==========");

// ----------------------------------------------------------------
// Small local helpers (prefixed to avoid clashing with utilities)
// ----------------------------------------------------------------
function ev_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    return $stmt;
}

function ev_execute(mysqli_stmt $stmt, string $failMessage): void
{
    if (!$stmt->execute()) {
        throw new Exception($failMessage . ": " . $stmt->error);
    }
}

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'process_evacuation',
    'rate_limit' => [10, 60],
    'csrf' => ['enabled' => true, 'form_name' => 'process_evacuation_form'],
    'roles' => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];
$userRole = $auth['role'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

if (!$input) {
    json_error("Invalid input data", 400);
}

$request_id = trim((string) ($input['request_id'] ?? ''));
$deductions = $input['deductions'] ?? [];
$actual_move_out_date = trim((string) ($input['actual_move_out_date'] ?? date('Y-m-d')));
$includeOutstandingFees = !isset($input['include_outstanding_fees'])
    || filter_var($input['include_outstanding_fees'], FILTER_VALIDATE_BOOLEAN);

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
    $n = $idx + 1;
    if (!is_array($d)) {
        json_error("Deduction #{$n}: invalid format", 400);
    }
    if (!isset($d['type']) || !is_string($d['type']) || trim($d['type']) === '') {
        json_error("Deduction #{$n}: type is required", 400);
    }
    if (
        !isset($d['amount']) || !is_numeric($d['amount'])
        || !is_finite((float) $d['amount']) || (float) $d['amount'] < 0
    ) {
        json_error("Deduction #{$n}: amount must be a non-negative number", 400);
    }
    $amt = round((float) $d['amount'], 2);
    $cleanDeductions[] = [
        'type' => trim($d['type']),
        'amount' => $amt,
        'description' => trim((string) ($d['description'] ?? '')),
        'source' => 'manual',
        'source_id' => null,
    ];
    // Sum the ROUNDED amounts so the total always equals the stored rows
    $total_deductions += $amt;
}
$total_deductions = round($total_deductions, 2);

$outstandingFees = [];
$outstandingFeesTotal = 0.0;

// ===== Track debt variables =====
$debtRecorded = null;
$offsetApplied = 0.0;
$remainingDebt = 0.0;
$debtAmount = 0.0;

$conn->begin_transaction();

try {
    // ================================================================
    // FETCH APPROVED REQUEST + CLIENT WALLET
    // (explicit columns: avoids silent name collisions from er.*)
    // ================================================================
    $stmt = ev_prepare($conn, "
        SELECT er.request_id, er.tenant_code, er.apartment_code, er.status,
               t.rent_balance, t.created_by,
               t.wallet_balance AS tenant_wallet_balance,
               a.security_deposit, a.rent_amount, a.property_code,
               p.client_code,
               c.wallet_balance AS client_wallet_balance,
               c.firstname AS client_firstname, c.lastname AS client_lastname
        FROM evacuation_requests er
        JOIN tenants t     ON er.tenant_code = t.tenant_code
        JOIN apartments a  ON er.apartment_code = a.apartment_code
        JOIN properties p  ON a.property_code = p.property_code
        LEFT JOIN clients c ON p.client_code = c.client_code
        WHERE er.request_id = ? AND er.status = 'approved'
        FOR UPDATE
    ");
    $stmt->bind_param("s", $request_id);
    ev_execute($stmt, "Failed to fetch evacuation request");
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request)
        throw new Exception("Approved evacuation request not found", 404);
    if (empty($request['client_code']))
        throw new Exception("No client (landlord) linked to this apartment", 400);

    if ($userRole !== 'Super Admin' && (int) $request['created_by'] !== $adminId) {
        throw new Exception("You can only process requests for tenants you onboarded", 403);
    }

       // ================================================================
    // TIMING GUARD: prevent premature evacuation processing
    // ================================================================
    // The request carries an approved_move_out_date. If TODAY is still
    // BEFORE that date, the tenant is (per the approved schedule) still
    // occupying the apartment. Processing now would free the apartment
    // prematurely and create overlapping tenancies when a new tenant is
    // onboarded.
    //
    // To override (e.g., the tenant genuinely left early), the admin must
    // explicitly set force_early_move_out = true and provide a reason.
    // ================================================================
    $approvedMoveOutDate = $request['approved_move_out_date'] ?? null;

    if ($approvedMoveOutDate) {
        $today = date('Y-m-d');   // server's "today" in Africa/Lagos

        if ($today < $approvedMoveOutDate) {
            $forceEarlyMoveOut = filter_var(
                $input['force_early_move_out'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if (!$forceEarlyMoveOut) {
                $daysEarly = (int) (
                    (strtotime($approvedMoveOutDate) - strtotime($today)) / 86400
                );

                logActivity(
                    "BLOCKED premature processing: approved={$approvedMoveOutDate}, "
                    . "today={$today}, days_early={$daysEarly}, "
                    . "request={$request_id}"
                );

                throw new Exception(
                    "This evacuation cannot be processed yet. "
                    . "The approved move-out date is "
                    . date('F j, Y', strtotime($approvedMoveOutDate))
                    . " — that is {$daysEarly} day(s) from today. "
                    . "The tenant is still scheduled to occupy the apartment until then. "
                    . "If the tenant has genuinely moved out early, please confirm by "
                    . "resubmitting with force_early_move_out set to true and providing "
                    . "an early_move_out_reason.",
                    400
                );
            }

            // Admin is forcing early move-out — require a reason
            $earlyMoveOutReason = trim((string) ($input['early_move_out_reason'] ?? ''));
            if ($earlyMoveOutReason === '') {
                throw new Exception(
                    "When forcing early move-out, please provide a reason "
                    . "(early_move_out_reason field is required).",
                    400
                );
            }

            // Log the override for audit
            $daysEarly = (int) (
                (strtotime($approvedMoveOutDate) - strtotime($today)) / 86400
            );
            logActivity(
                "EARLY MOVE-OUT OVERRIDE: request {$request_id} processed "
                . "before approved move-out date ({$approvedMoveOutDate}). "
                . "Today: {$today}. Days early: {$daysEarly}. "
                . "Reason: {$earlyMoveOutReason} | Overridden by admin {$adminId}"
            );
        }
    }

    // ================================================================
    // FETCH OUTSTANDING FEES
    // ================================================================
    if ($includeOutstandingFees) {
        $feeStmt = ev_prepare($conn, "
            SELECT tf.tenant_fee_id, tf.fee_type_id, tf.amount, tf.due_date,
                   tf.status, tf.notes, ft.fee_name, ft.fee_code
            FROM tenant_fees tf
            LEFT JOIN fee_types ft ON ft.fee_type_id = tf.fee_type_id
            WHERE tf.tenant_code = ?
              AND tf.status IN ('pending', 'overdue')
            ORDER BY tf.due_date ASC
            FOR UPDATE
        ");
        $feeStmt->bind_param("s", $request['tenant_code']);
        ev_execute($feeStmt, "Failed to fetch outstanding fees");
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

        logActivity("Outstanding fees included: " . count($outstandingFees) . " | Total: ₦{$outstandingFeesTotal}");
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

    $total_paid_in_cycle = getTotalPaidInCycle($conn, $currentCycle['rent_payment_id'], $request['tenant_code']);
    $cycle_total_days = (int) $cycle_start->diff($cycle_end)->days + 1;

    $moveOut = new DateTime($actual_move_out_date);
    $moveOut->setTime(0, 0, 0);

    if ($moveOut < $cycle_start)
        $cycle_days_used = 0;
    elseif ($moveOut > $cycle_end)
        $cycle_days_used = $cycle_total_days;
    else
        $cycle_days_used = (int) $cycle_start->diff($moveOut)->days + 1;

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

    $final_settlement = round((float) $breakdown['final_settlement_amount'], 2);
    logActivity("Settlement computed for {$request_id}: " . json_encode($breakdown));

    // ================================================================
    // DETERMINE FLOW: REFUND vs DEBT
    // ================================================================
    $isRefund = $final_settlement > 0;
    $isDebt = $final_settlement < 0;

    $refundAmount = $isRefund ? $final_settlement : 0.0;
    $debtAmount = $isDebt ? round(abs($final_settlement), 2) : 0.0;
    $clientWalletBalance = (float) ($request['client_wallet_balance'] ?? 0.0);
    $tenantWalletBalance = (float) ($request['tenant_wallet_balance'] ?? 0.0);

    // If refund, client must cover it (HARD GATE)
    if ($isRefund && $clientWalletBalance < $refundAmount) {
        $shortfall = round($refundAmount - $clientWalletBalance, 2);
        logActivity("BLOCKED: Client wallet insufficient. Required ₦{$refundAmount}, Available ₦{$clientWalletBalance}, Shortfall ₦{$shortfall}");
        throw new Exception(
            "Client has insufficient wallet balance to process this evacuation. "
            . "Refund due to tenant: ₦" . number_format($refundAmount, 2) . ". "
            . "Client wallet balance: ₦" . number_format($clientWalletBalance, 2) . ". "
            . "Shortfall: ₦" . number_format($shortfall, 2) . ". "
            . "Please ask the client to top up their wallet and try again.",
            400
        );
    }

    // ================================================================
    // GENERATE SETTLEMENT-LEVEL IDENTIFIERS
    // ================================================================
    $settlementReceipt = 'EVSET-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $settlementTxnId = 'TXN-EVSET-' . time() . '-' . substr(md5($request_id), 0, 6);

    logActivity("Settlement identifiers: Receipt={$settlementReceipt} | Txn={$settlementTxnId}");

    // ================================================================
    // UPDATE EVACUATION REQUEST
    // ================================================================
    $updateRequest = ev_prepare($conn, "
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
        WHERE request_id = ? AND status = 'approved'
    ");

    $bind_rent_payment_id = $currentCycle['rent_payment_id'];
    $bind_cycle_rent_amount = (float) $currentCycle['cycle_rent_amount'];
    $bind_total_paid = (float) $total_paid_in_cycle;
    $bind_rent_used = (float) $breakdown['rent_used'];
    $bind_unused_rent = (float) $breakdown['unused_rent'];
    $bind_tenant_share = (float) $breakdown['tenant_rent_share'];
    $bind_landlord_share = (float) $breakdown['landlord_rent_share'];
    $bind_dmg_deposit = (float) $breakdown['damages_from_deposit'];
    $bind_dmg_share = (float) $breakdown['damages_from_share'];
    $bind_dmg_owed = (float) $breakdown['damages_owed_by_tenant'];
    $bind_deposit_refund = (float) $breakdown['security_deposit_refund'];

    $updateRequest->bind_param(
        "sdiidddddddddddssis",   // s d i i + 11 x d + s s i s = 19
        $bind_rent_payment_id,   // s  settled_rent_payment_id
        $bind_cycle_rent_amount, // d  cycle_rent_amount
        $cycle_total_days,       // i  cycle_total_days
        $cycle_days_used,        // i  cycle_days_used
        $bind_total_paid,        // d  total_paid_in_cycle
        $bind_rent_used,         // d  rent_used
        $bind_unused_rent,       // d  unused_rent
        $bind_tenant_share,      // d  tenant_rent_share
        $bind_landlord_share,    // d  landlord_rent_share
        $total_deductions,       // d  damages_total
        $bind_dmg_deposit,       // d  damages_from_deposit
        $bind_dmg_share,         // d  damages_from_share
        $bind_dmg_owed,          // d  damages_owed_by_tenant
        $final_settlement,       // d  final_settlement_amount
        $bind_deposit_refund,    // d  security_deposit_refund
        $settlementReceipt,      // s  settlement_receipt_number
        $settlementTxnId,        // s  settlement_transaction_id
        $adminId,                // i  processed_by
        $request_id              // s  request_id
    );
    ev_execute($updateRequest, "Failed to update evacuation request");
    if ($updateRequest->affected_rows < 1) {
        throw new Exception("Evacuation request was not updated (already processed?)", 409);
    }
    $updateRequest->close();

    // ================================================================
    // INSERT DEDUCTIONS
    // ================================================================
    if (!empty($cleanDeductions)) {
        $insertDeduction = ev_prepare($conn, "
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
            ev_execute($insertDeduction, "Failed to insert deduction");
        }
        $insertDeduction->close();
    }

    // ================================================================
    // SETTLE OUTSTANDING FEES + RECORD PAYMENTS
    // ================================================================
    $settledFeeReceipts = [];

    if (!empty($outstandingFees)) {
        $updateFee = ev_prepare($conn, "
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

        $insertPayment = ev_prepare($conn, "
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
            $feeDesc = "Settled via evacuation {$request_id} | " . ($fee['fee_name'] ?: 'Fee') . " (" . $fee['fee_code'] . ")";
            $feeDueDate = $fee['due_date'];
            $feeId = $fee['tenant_fee_id'];

            $insertPayment->bind_param(
                "ssdssssi",
                $request['tenant_code'],
                $request['apartment_code'],
                $feeAmount,
                $feeDueDate,
                $feeReceipt,
                $feeTxnId,
                $feeDesc,
                $adminId
            );
            ev_execute($insertPayment, "Failed to insert fee payment");
            $feePaymentId = (int) $insertPayment->insert_id;

            $updateFee->bind_param("sissi", $feeReceipt, $feePaymentId, $request_id, $feeReceipt, $feeId);
            ev_execute($updateFee, "Failed to update fee with receipt");

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
    // RECORD REFUND IN PAYMENTS (if refund)
    // ================================================================
    $refundReceipt = null;
    $refundTxnId = null;
    $refundPaymentId = null;

    if ($isRefund) {
        $refundReceipt = 'EVRFD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $refundTxnId = 'TXN-EVRFD-' . time() . '-' . strtoupper(bin2hex(random_bytes(4)));
        $refundDesc = "Evacuation refund for {$request_id} (move-out {$actual_move_out_date})";

        $refundStmt = ev_prepare($conn, "
            INSERT INTO payments (
                tenant_code, apartment_code, amount, balance,
                payment_date, due_date, payment_method, payment_status,
                receipt_number, reference_number, description, recorded_by,
                payment_category, payment_type, created_at
            ) VALUES (?, ?, ?, 0, NOW(), NOW(), 'settlement', 'completed',
                      ?, ?, ?, ?, 'refund', 'outflow', NOW())
        ");
        $refundStmt->bind_param(
            "ssdsssi",
            $request['tenant_code'],
            $request['apartment_code'],
            $refundAmount,
            $refundReceipt,
            $refundTxnId,
            $refundDesc,
            $adminId
        );
        ev_execute($refundStmt, "Failed to record refund");
        $refundPaymentId = (int) $refundStmt->insert_id;
        $refundStmt->close();

        logActivity("Refund recorded: ₦{$refundAmount} | Receipt={$refundReceipt} | PaymentID={$refundPaymentId}");
    }

    // ================================================================
    // WALLET MOVEMENTS — REFUND BRANCH
    // ================================================================
    $clientWalletNewBalance = $clientWalletBalance;
    $tenantWalletNewBalance = $tenantWalletBalance;

    if ($isRefund) {
        // Debit client (guarded: balance must still cover the refund)
        $clientWalletNewBalance = round($clientWalletBalance - $refundAmount, 2);

        $debitClient = ev_prepare($conn, "
            UPDATE clients SET wallet_balance = wallet_balance - ?
            WHERE client_code = ? AND wallet_balance >= ?
        ");
        $debitClient->bind_param("dsd", $refundAmount, $request['client_code'], $refundAmount);
        ev_execute($debitClient, "Failed to debit client wallet");
        if ($debitClient->affected_rows !== 1) {
            throw new Exception("Client wallet debit failed: insufficient balance or client not found", 400);
        }
        $debitClient->close();

        $clientLedger = ev_prepare($conn, "
            INSERT INTO client_wallet_transactions
                (client_code, transaction_type, amount, running_balance, request_id, notes, created_by)
            VALUES (?, 'settlement_debit', ?, ?, ?, ?, ?)
        ");
        $clientDebitAmount = -$refundAmount;
        $clientLedgerNote = "Evacuation refund debit for {$request_id} (tenant {$request['tenant_code']}, receipt {$refundReceipt})";
        $clientLedger->bind_param(
            "sddssi",
            $request['client_code'],
            $clientDebitAmount,
            $clientWalletNewBalance,
            $request_id,
            $clientLedgerNote,
            $adminId
        );
        ev_execute($clientLedger, "Failed to write client ledger");
        $clientLedger->close();

        // Credit tenant
        $tenantWalletNewBalance = round($tenantWalletBalance + $refundAmount, 2);

        $creditTenant = ev_prepare($conn, "UPDATE tenants SET wallet_balance = wallet_balance + ? WHERE tenant_code = ?");
        $creditTenant->bind_param("ds", $refundAmount, $request['tenant_code']);
        ev_execute($creditTenant, "Failed to credit tenant wallet");
        if ($creditTenant->affected_rows !== 1) {
            throw new Exception("Tenant wallet credit failed: tenant not found");
        }
        $creditTenant->close();

        $tenantLedger = ev_prepare($conn, "
            INSERT INTO tenant_wallet_transactions
                (tenant_code, transaction_type, amount, running_balance, request_id, notes, created_by)
            VALUES (?, 'refund_credit', ?, ?, ?, ?, ?)
        ");
        $tenantLedgerNote = "Evacuation refund for {$request_id} (settlement {$settlementReceipt})";
        $tenantLedger->bind_param(
            "sddssi",
            $request['tenant_code'],
            $refundAmount,
            $tenantWalletNewBalance,
            $request_id,
            $tenantLedgerNote,
            $adminId
        );
        ev_execute($tenantLedger, "Failed to write tenant ledger");
        $tenantLedger->close();

        logActivity("REFUND: Client debited ₦{$refundAmount} → Tenant credited ₦{$refundAmount}");
    }

    // ================================================================
    // WALLET MOVEMENTS — DEBT BRANCH
    // ================================================================
    if ($isDebt) {
        // How much can the tenant pay immediately from their wallet?
        // max(0, ...) protects against a negative wallet balance.
        $offsetApplied = max(0.0, min($tenantWalletBalance, $debtAmount));
        $offsetApplied = round($offsetApplied, 2);
        $remainingDebt = round($debtAmount - $offsetApplied, 2);

        if ($offsetApplied > 0) {
            // ---- Debit tenant wallet (guarded) ----
            $tenantWalletNewBalance = round($tenantWalletBalance - $offsetApplied, 2);

            $debitTenant = ev_prepare($conn, "
                UPDATE tenants SET wallet_balance = wallet_balance - ?
                WHERE tenant_code = ? AND wallet_balance >= ?
            ");
            $debitTenant->bind_param("dsd", $offsetApplied, $request['tenant_code'], $offsetApplied);
            ev_execute($debitTenant, "Failed to debit tenant");
            if ($debitTenant->affected_rows !== 1) {
                throw new Exception("Tenant wallet debit failed: insufficient balance or tenant not found", 400);
            }
            $debitTenant->close();

            $tenantLedger = ev_prepare($conn, "
                INSERT INTO tenant_wallet_transactions
                    (tenant_code, transaction_type, amount, running_balance, request_id, notes, created_by)
                VALUES (?, 'debt_offset', ?, ?, ?, ?, ?)
            ");
            $tenantDebitAmount = -$offsetApplied;
            $tenantLedgerNote = "Debt offset for evacuation {$request_id}";
            $tenantLedger->bind_param(
                "sddssi",
                $request['tenant_code'],
                $tenantDebitAmount,
                $tenantWalletNewBalance,
                $request_id,
                $tenantLedgerNote,
                $adminId
            );
            ev_execute($tenantLedger, "Failed to write tenant ledger");
            $tenantLedger->close();

            // ---- Credit client wallet (client absorbs the loss, gets it back) ----
            $clientWalletNewBalance = round($clientWalletBalance + $offsetApplied, 2);

            $creditClient = ev_prepare($conn, "UPDATE clients SET wallet_balance = wallet_balance + ? WHERE client_code = ?");
            $creditClient->bind_param("ds", $offsetApplied, $request['client_code']);
            ev_execute($creditClient, "Failed to credit client");
            if ($creditClient->affected_rows !== 1) {
                throw new Exception("Client wallet credit failed: client not found");
            }
            $creditClient->close();

            $clientLedger = ev_prepare($conn, "
                INSERT INTO client_wallet_transactions
                    (client_code, transaction_type, amount, running_balance, request_id, notes, created_by)
                VALUES (?, 'debt_recovery', ?, ?, ?, ?, ?)
            ");
            $clientCreditNote = "Debt recovery from tenant {$request['tenant_code']} for evacuation {$request_id}";
            $clientLedger->bind_param(
                "sddssi",
                $request['client_code'],
                $offsetApplied,
                $clientWalletNewBalance,
                $request_id,
                $clientCreditNote,
                $adminId
            );
            ev_execute($clientLedger, "Failed to write client ledger");
            $clientLedger->close();

            logActivity("DEBT OFFSET: Tenant debited ₦{$offsetApplied} → Client credited ₦{$offsetApplied}");
        }

        // ---- Record debt in tenant_debts table ----
        $debtCode = 'DBT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $debtNotes = "Evacuation settlement debt for {$request_id}. "
            . "Total owed: ₦" . number_format($debtAmount, 2)
            . " | Immediately offset: ₦" . number_format($offsetApplied, 2)
            . " | Remaining: ₦" . number_format($remainingDebt, 2);

        $debtStatus = $remainingDebt <= 0 ? 'resolved' : 'unpaid';
        $resolvedAt = $remainingDebt <= 0 ? date('Y-m-d H:i:s') : null;
        $resolvedBy = $remainingDebt <= 0 ? $adminId : null;   // only set when actually resolved

        $insertDebt = ev_prepare($conn, "
            INSERT INTO tenant_debts
                (debt_code, tenant_code, source_type, source_id,
                 original_amount, amount_paid, balance, offset_applied,
                 client_code, status, notes, resolved_at, resolved_by)
            VALUES (?, ?, 'evacuation', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insertDebt->bind_param(
            "sssddddssssi",
            $debtCode,
            $request['tenant_code'],
            $request_id,
            $debtAmount,
            $offsetApplied,
            $remainingDebt,
            $offsetApplied,
            $request['client_code'],
            $debtStatus,
            $debtNotes,
            $resolvedAt,
            $resolvedBy
        );
        ev_execute($insertDebt, "Failed to record tenant debt");
        $debtId = (int) $insertDebt->insert_id;
        $insertDebt->close();

        // Log the offset as a debt payment (if any was applied)
        if ($offsetApplied > 0) {
            $payment_method = "wallet_offset";
            $debtPaymentNote = "Auto-offset from tenant wallet at evacuation time";
            $tenantCode = $request['tenant_code'];
            $offsetAmount = round((float) $offsetApplied, 2);

            $insertDebtPayment = ev_prepare($conn, "
                INSERT INTO tenant_debt_payments
                    (debt_id, debt_code, tenant_code, amount, payment_method, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $insertDebtPayment->bind_param(
                "issdssi",          // i s s d s s i = 7 types for 7 placeholders
                $debtId,            // i  debt_id
                $debtCode,          // s  debt_code
                $tenantCode,        // s  tenant_code
                $offsetAmount,      // d  amount
                $payment_method,    // s  payment_method
                $debtPaymentNote,   // s  notes
                $adminId            // i  recorded_by
            );
            ev_execute($insertDebtPayment, "Failed to record debt payment");
            $insertDebtPayment->close();
        }

        $debtRecorded = [
            'debt_code' => $debtCode,
            'debt_id' => $debtId,
            'original_amount' => $debtAmount,
            'offset_applied' => $offsetApplied,
            'remaining_balance' => $remainingDebt,
            'status' => $debtStatus,
        ];

        logActivity("Debt recorded: {$debtCode} | Original: ₦{$debtAmount} | Offset: ₦{$offsetApplied} | Remaining: ₦{$remainingDebt}");
    }

    // ================================================================
    // FREE APARTMENT
    // ================================================================
    $updateApartment = ev_prepare($conn, "
        UPDATE apartments SET occupancy_status = 'NOT OCCUPIED', occupied_by = NULL
        WHERE apartment_code = ?
    ");
    $updateApartment->bind_param("s", $request['apartment_code']);
    ev_execute($updateApartment, "Failed to free apartment");
    if ($updateApartment->affected_rows < 1) {
        logActivity("WARNING: Apartment {$request['apartment_code']} was not changed (already NOT OCCUPIED?)");
    }
    $updateApartment->close();

    // ================================================================
    // DECREMENT PROPERTY COUNT
    // ================================================================
    if (!empty($request['property_code'])) {
        $updateProperty = ev_prepare($conn, "
            UPDATE properties SET occupied_apartments = GREATEST(occupied_apartments - 1, 0)
            WHERE property_code = ?
        ");
        $updateProperty->bind_param("s", $request['property_code']);
        ev_execute($updateProperty, "Failed to update property occupancy");
        $updateProperty->close();
    }

    // ================================================================
    // FINALIZE TENANT
    // ================================================================
    $updateTenant = ev_prepare($conn, "
        UPDATE tenants
        SET status = 0, tenant_status = '3', evacuation_status = 'evacuated',
            move_out_date = ?, evacuated_by = ?, evacuated_at = NOW(),
            final_settlement_amount = ?, early_termination_fee = 0,
            can_request_evacuation = 0, last_updated_by = ?, last_updated_at = NOW()
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
    ev_execute($updateTenant, "Failed to update tenant");
    $updateTenant->close();

    // ================================================================
    // COMMIT
    // (notifications are sent AFTER this point so a failed commit
    //  never results in "success" messages going out)
    // ================================================================
    $conn->commit();
    logActivity("Evacuation processed: {$request_id} - settlement: ₦{$final_settlement}");

    // ================================================================
    // NOTIFY TENANT (post-commit; failures never affect the result)
    // ================================================================
    try {
        if ($isRefund) {
            $refundMsg = "Final refund of ₦" . number_format($final_settlement, 2)
                . " has been credited to your wallet. You can request a withdrawal from your dashboard.";
        } elseif ($isDebt) {
            $refundMsg = "You owe ₦" . number_format($debtAmount, 2) . " for evacuation damages and fees. ";
            if ($offsetApplied > 0) {
                $refundMsg .= "₦" . number_format($offsetApplied, 2) . " was offset from your wallet. ";
            }
            if ($remainingDebt > 0) {
                $refundMsg .= "Remaining balance: ₦" . number_format($remainingDebt, 2)
                    . ". Please settle to avoid being blocked from future rentals.";
            } else {
                $refundMsg .= "Your wallet covered the full amount.";
            }
        } else {
            $refundMsg = "Settlement balanced with zero amount.";
        }

        $notifMessage = "Your evacuation has been completed. Move-out date: "
            . date('M j, Y', strtotime($actual_move_out_date)) . ". " . $refundMsg;

        if (!empty($outstandingFees)) {
            $notifMessage .= " " . count($outstandingFees) . " outstanding fee(s) totaling ₦"
                . number_format($outstandingFeesTotal, 2) . " were included in your final settlement.";
        }
        if ($refundReceipt)
            $notifMessage .= " Refund receipt: {$refundReceipt}.";

        createNotification(
            $conn,
            $request['tenant_code'],
            'evacuation',
            $isDebt ? 'Evacuation Completed — Outstanding Balance' : 'Evacuation Completed',
            $notifMessage,
            [
                'request_id' => $request_id,
                'final_settlement_amount' => $final_settlement,
                'security_deposit_refund' => $breakdown['security_deposit_refund'],
                'move_out_date' => $actual_move_out_date,
                'outstanding_fees_total' => $outstandingFeesTotal,
                'outstanding_fees_count' => count($outstandingFees),
                'refund_receipt_number' => $refundReceipt,
                'settlement_receipt_number' => $settlementReceipt,
                'tenant_wallet_balance' => $tenantWalletNewBalance,
                'debt_code' => $debtRecorded['debt_code'] ?? null,
                'remaining_debt' => $remainingDebt,
            ],
            'high',
            '../wallet.php',
            'View Wallet'
        );
    } catch (Throwable $notifErr) {
        logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
    }

    // ================================================================
    // NOTIFY CLIENT (post-commit)
    // ================================================================
    try {
        if ($isRefund && $refundAmount > 0) {
            $clientNotifMessage = "A tenant refund of ₦" . number_format($refundAmount, 2)
                . " was processed for tenant {$request['tenant_code']}. "
                . "Your wallet balance is now ₦" . number_format($clientWalletNewBalance, 2) . ".";

            createNotification(
                $conn,
                $request['client_code'],
                'wallet',
                'Wallet Debited — Tenant Refund',
                $clientNotifMessage,
                [
                    'request_id' => $request_id,
                    'amount_debited' => $refundAmount,
                    'new_wallet_balance' => $clientWalletNewBalance,
                    'tenant_code' => $request['tenant_code'],
                ],
                'high',
                '../wallet.php',
                'View Wallet'
            );
        } elseif ($isDebt && $offsetApplied > 0) {
            $clientNotifMessage = "Tenant {$request['tenant_code']} owed ₦"
                . number_format($debtAmount, 2) . " from evacuation. "
                . "₦" . number_format($offsetApplied, 2) . " was recovered from their wallet and credited to you. "
                . "Your wallet balance is now ₦" . number_format($clientWalletNewBalance, 2) . ".";
            if ($remainingDebt > 0) {
                $clientNotifMessage .= " ₦" . number_format($remainingDebt, 2)
                    . " remains outstanding and is being tracked.";
            }

            createNotification(
                $conn,
                $request['client_code'],
                'wallet',
                'Debt Recovery — Tenant Settlement',
                $clientNotifMessage,
                [
                    'request_id' => $request_id,
                    'debt_amount' => $debtAmount,
                    'recovered' => $offsetApplied,
                    'remaining' => $remainingDebt,
                    'new_wallet_balance' => $clientWalletNewBalance,
                ],
                'high',
                '../wallet.php',
                'View Wallet'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("WARNING: Client notification failed: " . $notifErr->getMessage());
    }

    logActivity("========== PROCESS EVACUATION - END ==========");

    // ================================================================
    // RESPONSE
    // ================================================================
    $message = $isRefund
        ? "Tenant is due a refund of ₦" . number_format($final_settlement, 2)
        : ($isDebt
            ? "Tenant owes ₦" . number_format($debtAmount, 2)
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

            'settlement_receipt_number' => $settlementReceipt,
            'settlement_transaction_id' => $settlementTxnId,
            'outstanding_fees' => $outstandingFees,
            'outstanding_fees_total' => $outstandingFeesTotal,
            'outstanding_fees_count' => count($outstandingFees),
            'settled_fee_receipts' => $settledFeeReceipts,

            // Refund branch
            'refund_receipt_number' => $refundReceipt,
            'refund_transaction_id' => $refundTxnId,
            'refund_payment_id' => $refundPaymentId,

            // Debt branch
            'debt_record' => $debtRecorded,
            'debt_amount' => $debtAmount,
            'offset_applied' => $offsetApplied,
            'remaining_debt' => $remainingDebt,

            'final_settlement_amount' => $final_settlement,
            'security_deposit_refund' => $breakdown['security_deposit_refund'],

            // Wallet movements
            'client_wallet_debited' => $isRefund ? $refundAmount : 0,
            'client_wallet_credited' => $isDebt ? $offsetApplied : 0,
            'client_wallet_new_balance' => $clientWalletNewBalance,
            'tenant_wallet_credited' => $isRefund ? $refundAmount : 0,
            'tenant_wallet_debited' => $isDebt ? $offsetApplied : 0,
            'tenant_wallet_new_balance' => $tenantWalletNewBalance,

            'move_out_date' => $actual_move_out_date,
        ]
    );

} catch (Throwable $e) {
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
    } catch (Throwable $ignore) {
    }

    // Always log the real error; only expose it to the client for deliberate 4xx errors
    logActivity("ERROR: " . $e->getMessage());

    $code = (int) $e->getCode();
    $status = ($code >= 400 && $code < 600) ? $code : 500;
    $publicMessage = $status >= 500
        ? "An internal error occurred while processing the evacuation. Please try again or contact support."
        : $e->getMessage();

    json_error($publicMessage, $status);
}