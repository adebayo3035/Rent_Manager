<?php
/**
 * evacuation_helper.php
 * Shared logic for evacuation settlement calculations.
 */

/**
 * Identify the current rent cycle for a tenant.
 *
 * Strategy:
 *   1. Find the cycle whose period brackets today.
 *   2. Fallback: the most recent cycle that started on or before today.
 *   3. If multiple match, block (data anomaly).
 *   4. If none match, block (data missing).
 *
 * @param mysqli $conn
 * @param string $tenant_code
 * @return array Cycle row [rent_payment_id, cycle_rent_amount, cycle_amount_paid,
 *                           period_start_date, period_end_date, payment_amount_per_period]
 * @throws Exception on any anomaly
 */
function getCurrentRentCycle($conn, $tenant_code) {
    // Step 1: Cycles that contain today
    $stmt = $conn->prepare("
        SELECT rent_payment_id, 
               amount AS cycle_rent_amount, 
               amount_paid AS cycle_amount_paid,
               balance AS cycle_balance,
               period_start_date, 
               period_end_date,
               payment_amount_per_period
        FROM rent_payments
        WHERE tenant_code = ?
          AND CURDATE() BETWEEN period_start_date AND period_end_date
          AND status IN ('ongoing', 'completed', 'pending')
        ORDER BY period_start_date DESC
        LIMIT 2
    ");
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $res = $stmt->get_result();
    $matches = [];
    while ($row = $res->fetch_assoc()) {
        $matches[] = $row;
    }
    $stmt->close();

    if (count($matches) > 1) {
        logActivity("DATA ANOMALY: Tenant {$tenant_code} has " . count($matches) . " overlapping cycles on " . date('Y-m-d'));
        throw new Exception("Multiple active rent cycles detected for this tenant. Please contact support.");
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    // Step 2: Fallback — most recent cycle that started on or before today
    $stmt = $conn->prepare("
        SELECT rent_payment_id, 
               amount AS cycle_rent_amount, 
               amount_paid AS cycle_amount_paid,
               balance AS cycle_balance,
               period_start_date, 
               period_end_date,
               payment_amount_per_period
        FROM rent_payments
        WHERE tenant_code = ?
          AND period_start_date <= CURDATE()
          AND status IN ('ongoing', 'completed', 'pending')
        ORDER BY period_start_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $res = $stmt->get_result();
    $fallback = $res->fetch_assoc();
    $stmt->close();

    if (!$fallback) {
        logActivity("CRITICAL: No rent cycle found for tenant {$tenant_code} on " . date('Y-m-d'));
        throw new Exception("Unable to identify the current rent cycle for this tenant. Please contact support.");
    }

    logActivity("Using fallback cycle for tenant {$tenant_code}: {$fallback['rent_payment_id']} "
        . "({$fallback['period_start_date']} to {$fallback['period_end_date']})");
    return $fallback;
}

/**
 * Sum all paid amounts in the tracker for a given cycle.
 */
function getTotalPaidInCycle($conn, $rent_payment_id, $tenant_code) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
        FROM rent_payment_tracker
        WHERE rent_payment_id = ?
          AND tenant_code = ?
          AND status = 'paid'
    ");
    $stmt->bind_param("ss", $rent_payment_id, $tenant_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)$row['total_paid'];
}

/**
 * Calculate the settlement.
 *
 * @param array $cycle  Row from getCurrentRentCycle()
 * @param string $actual_move_out_date  Y-m-d
 * @param float $security_deposit
 * @param float $total_deductions
 * @return array Full breakdown
 * @throws Exception on shortfall or bad dates
 */
function calculateEvacuationSettlement($cycle, $actual_move_out_date, $security_deposit, $total_deductions) {
    $cycle_start = new DateTime($cycle['period_start_date']);
    $cycle_end   = new DateTime($cycle['period_end_date']);
    $cycle_start->setTime(0, 0, 0);
    $cycle_end->setTime(0, 0, 0);

    $move_out = new DateTime($actual_move_out_date);
    $move_out->setTime(0, 0, 0);

    $total_cycle_days = $cycle_start->diff($cycle_end)->days + 1;

    // Clamp move_out into cycle window
    if ($move_out < $cycle_start) {
        $days_used = 0;
    } elseif ($move_out > $cycle_end) {
        $days_used = $total_cycle_days;
    } else {
        $days_used = $cycle_start->diff($move_out)->days + 1;
    }

    $cycle_rent_amount = (float)$cycle['cycle_rent_amount'];
    $daily_rate = $cycle_rent_amount / $total_cycle_days;
    $rent_used = round($daily_rate * $days_used, 2);

    return [
        'cycle_start' => $cycle_start->format('Y-m-d'),
        'cycle_end' => $cycle_end->format('Y-m-d'),
        'total_cycle_days' => $total_cycle_days,
        'days_used' => $days_used,
        'daily_rate' => $daily_rate,
        'cycle_rent_amount' => $cycle_rent_amount,
        'rent_used' => $rent_used,
    ];
}

/**
 * Compute final settlement including the 50/50 split and damage cascade.
 */
function computeSettlementBreakdown(
    $cycle_rent_amount,
    $total_cycle_days,
    $days_used,
    $total_paid_in_cycle,
    $security_deposit,
    $total_deductions
) {
    $daily_rate = $cycle_rent_amount / $total_cycle_days;
    $rent_used = round($daily_rate * $days_used, 2);

    // Shortfall check
    if ($total_paid_in_cycle + 0.01 < $rent_used) {
        throw new Exception(
            "Tenant has underpaid for the current cycle. "
            . "Paid: ₦" . number_format($total_paid_in_cycle, 2)
            . ", Owed for period used: ₦" . number_format($rent_used, 2)
            . ". Please resolve the shortfall first."
        );
    }

    $unused_rent = max(0, round($total_paid_in_cycle - $rent_used, 2));

    // 50/50 split
    $tenant_share = round($unused_rent * 0.5, 2);
    $landlord_share = round($unused_rent - $tenant_share, 2);

    // Damage cascade
    $deposit_remaining = (float)$security_deposit;
    $share_remaining = $tenant_share;
    $damages_remaining = (float)$total_deductions;

    // 1. Deposit covers damages first
    $damages_from_deposit = min($deposit_remaining, $damages_remaining);
    $deposit_remaining -= $damages_from_deposit;
    $damages_remaining -= $damages_from_deposit;

    // 2. Tenant share covers remaining damages
    $damages_from_share = min($share_remaining, $damages_remaining);
    $share_remaining -= $damages_from_share;
    $damages_remaining -= $damages_from_share;

    // 3. Anything left = tenant owes
    $damages_owed_by_tenant = round($damages_remaining, 2);

    // Final refund from remaining pool
    $refund_pool = $deposit_remaining + $share_remaining;
    $final_settlement = round($refund_pool - $damages_owed_by_tenant, 2);

    // Security deposit refund = remaining deposit (only) — informational
    $security_deposit_refund = round($deposit_remaining, 2);

    return [
        'rent_used' => $rent_used,
        'unused_rent' => $unused_rent,
        'tenant_rent_share' => $tenant_share,
        'landlord_rent_share' => $landlord_share,
        'damages_from_deposit' => round($damages_from_deposit, 2),
        'damages_from_share' => round($damages_from_share, 2),
        'damages_owed_by_tenant' => $damages_owed_by_tenant,
        'final_settlement_amount' => $final_settlement,
        'security_deposit_refund' => $security_deposit_refund,
    ];
}