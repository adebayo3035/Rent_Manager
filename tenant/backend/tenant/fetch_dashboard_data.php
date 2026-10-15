<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
rateLimiter();

try {
    // ==================== AUTHENTICATION ====================
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) {
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }

    logActivity("=== FETCH DASHBOARD DATA START ===");
    logActivity("Tenant Code: {$tenant_code}");

    // ==================== GET TENANT / APARTMENT / PROPERTY ====================
    // Allow both active and alumni tenants — same rule as login and fetch_user_data
    $tenantQuery = "
        SELECT
            t.tenant_code,
            t.firstname,
            t.lastname,
            t.lease_start_date,
            t.lease_end_date,
            t.temp_lease_end_date,
            t.payment_frequency,
            t.agreed_rent_amount,
            t.payment_amount_per_period,
            t.rent_balance,
            t.agreed_payment_frequency,
            t.status,
            t.tenant_status,
            t.evacuation_status,
            t.move_out_date,
            t.final_settlement_amount,
            t.wallet_balance,
            a.apartment_number,
            a.apartment_code,
            a.security_deposit,
            a.occupancy_status,
            p.name    AS property_name,
            p.property_code,
            p.address AS property_address,
            CONCAT(ag.firstname, ' ', ag.lastname) AS agent_name,
            ag.phone AS agent_phone,
            ag.email AS agent_email
        FROM tenants t
        LEFT JOIN apartments a  ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p  ON t.property_code  = p.property_code
        LEFT JOIN agents ag     ON p.agent_code     = ag.agent_code
        WHERE t.tenant_code = ?
          AND (
              (t.tenant_status = '1' AND t.status = 1)
              OR (t.tenant_status = '3' AND t.status = 0 AND t.evacuation_status = 'evacuated')
          )
        LIMIT 1
    ";
    $stmt = $conn->prepare($tenantQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $tenantData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenantData) {
        logActivity("Dashboard denied: tenant {$tenant_code} not in active/alumni state");
        json_error("Tenant data not found", 404, null, 'TENANT_NOT_FOUND');
    }

    $isAlumni = ($tenantData['evacuation_status'] === 'evacuated');

    logActivity("Tenant Data - State: {$tenantData['evacuation_status']}, Alumni: " . ($isAlumni ? 'YES' : 'NO'));

    // ==================== ACTIVE MAINTENANCE COUNT ====================
    $maintenanceQuery = "
        SELECT COUNT(*) AS active_requests
        FROM maintenance_requests
        WHERE tenant_code = ? AND status IN ('pending', 'in_progress')
    ";
    $stmt = $conn->prepare($maintenanceQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $maintenanceData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ==================== COMPUTE DERIVED TENANT VALUES ====================
    $days_remaining = 0;
    if ($tenantData['lease_end_date']) {
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $end_date = new DateTime($tenantData['lease_end_date']);
        $end_date->setTime(0, 0, 0);
        if ($today <= $end_date) {
            $days_remaining = $today->diff($end_date)->days;
        }
    }

    $annual_rent              = (float) ($tenantData['agreed_rent_amount'] ?? 0);
    $payment_amount_per_period = (float) ($tenantData['payment_amount_per_period'] ?? 0);
    $rent_balance             = (float) ($tenantData['rent_balance'] ?? 0);
    $payment_frequency        = $tenantData['agreed_payment_frequency'] ?? $tenantData['payment_frequency'] ?? 'Monthly';
    $security_deposit         = (float) ($tenantData['security_deposit'] ?? 0);

    // ==================== MAIN RENT PAYMENT RECORD ====================
    $rentPaymentQuery = "
        SELECT
            rent_payment_id,
            amount AS total_annual_rent,
            amount_paid AS initial_payment,
            balance AS remaining_balance,
            payment_date,
            payment_method,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            reference_number,
            status,
            receipt_number,
            notes,
            agreed_rent_amount,
            payment_amount_per_period,
            created_at
        FROM rent_payments
        WHERE tenant_code = ?
          AND apartment_code = ?
          AND payment_type = 'rent'
        ORDER BY created_at DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($rentPaymentQuery);
    $stmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $stmt->execute();
    $rentPayment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ==================== CURRENT CYCLE IDENTIFICATION ====================
    logActivity("=== CURRENT CYCLE IDENTIFICATION START ===");

    $currentCycleQuery = "
        SELECT
            rent_payment_id,
            amount AS cycle_rent_amount,
            amount_paid AS cycle_amount_paid,
            balance AS cycle_balance,
            period_start_date,
            period_end_date,
            payment_amount_per_period,
            status
        FROM rent_payments
        WHERE tenant_code = ?
          AND apartment_code = ?
          AND payment_type = 'rent'
          AND CURDATE() BETWEEN period_start_date AND period_end_date
          AND status IN ('ongoing', 'completed', 'pending')
        ORDER BY period_start_date DESC
        LIMIT 1
    ";
    $cycleStmt = $conn->prepare($currentCycleQuery);
    $cycleStmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $cycleStmt->execute();
    $currentCycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$currentCycle) {
        $fallbackQuery = "
            SELECT
                rent_payment_id,
                amount AS cycle_rent_amount,
                amount_paid AS cycle_amount_paid,
                balance AS cycle_balance,
                period_start_date,
                period_end_date,
                payment_amount_per_period,
                status
            FROM rent_payments
            WHERE tenant_code = ?
              AND apartment_code = ?
              AND payment_type = 'rent'
              AND period_start_date <= CURDATE()
              AND status IN ('ongoing', 'completed', 'pending')
            ORDER BY period_start_date DESC
            LIMIT 1
        ";
        $fbStmt = $conn->prepare($fallbackQuery);
        $fbStmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
        $fbStmt->execute();
        $currentCycle = $fbStmt->get_result()->fetch_assoc();
        $fbStmt->close();
    }

    // Total paid in current cycle
    $amount_paid_in_cycle = 0;
    if ($currentCycle) {
        $paidStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
            FROM rent_payment_tracker
            WHERE rent_payment_id = ?
              AND tenant_code = ?
              AND status = 'paid'
        ");
        $paidStmt->bind_param("ss", $currentCycle['rent_payment_id'], $tenant_code);
        $paidStmt->execute();
        $amount_paid_in_cycle = (float) $paidStmt->get_result()->fetch_assoc()['total_paid'];
        $paidStmt->close();
    }

    logActivity("=== CURRENT CYCLE IDENTIFICATION END ===");

    // ==================== PAYMENT TRACKER RECORDS ====================
    $trackerQuery = "
        SELECT
            tracker_id,
            rent_payment_id,
            period_number,
            start_date,
            end_date,
            remaining_balance,
            amount_paid,
            payment_date,
            status,
            payment_id,
            created_at
        FROM rent_payment_tracker
        WHERE tenant_code = ?
          AND apartment_code = ?
        ORDER BY period_number ASC
    ";
    $stmt = $conn->prepare($trackerQuery);
    $stmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $stmt->execute();
    $trackerRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    logActivity("Found " . count($trackerRecords) . " payment tracker records");

    // ==================== SECURITY DEPOSIT PAYMENT ====================
    $depositQuery = "
        SELECT
            id, amount, payment_date, due_date,
            receipt_number, reference_number, description, payment_status
        FROM payments
        WHERE tenant_code = ?
          AND apartment_code = ?
          AND payment_category = 'security_deposit'
          AND payment_status = 'completed'
        ORDER BY created_at DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($depositQuery);
    $stmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $stmt->execute();
    $depositPayment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ==================== HELPERS ====================
    if (!function_exists('formatPeriod')) {
        function formatPeriod($date, $frequency)
        {
            switch ($frequency) {
                case 'Monthly':       return $date->format('F Y');
                case 'Quarterly':     return 'Q' . ceil($date->format('n') / 3) . ' ' . $date->format('Y');
                case 'Semi-Annually': return ($date->format('n') <= 6 ? 'H1' : 'H2') . ' ' . $date->format('Y');
                case 'Annually':      return $date->format('Y');
                default:              return $date->format('F Y');
            }
        }
    }

    // ==================== PROCESS TRACKER RECORDS ====================
    $paid_periods = [];
    $completed_payments = [];
    $last_paid_end_date = null;
    $last_payment_date = null;
    $last_payment_period = null;
    $has_pending_verification = false;
    $has_failed_payment = false;
    $pending_verification_period = null;
    $pending_verification_tracker = [];
    $failed_payment = [];
    $failed_payment_count = 0;

    foreach ($trackerRecords as $tracker) {
        if ($tracker['status'] === 'paid') {
            $period_start = new DateTime($tracker['start_date']);
            $paid_periods[] = [
                'period_number'      => $tracker['period_number'],
                'period'             => formatPeriod($period_start, $payment_frequency),
                'start_date'         => $tracker['start_date'],
                'end_date'           => $tracker['end_date'],
                'amount_paid'        => (float) $tracker['amount_paid'],
                'payment_date'       => $tracker['payment_date'],
                'remaining_balance'  => (float) $tracker['remaining_balance'],
            ];
            $completed_payments[] = [
                'payment_id'    => $tracker['payment_id'],
                'payment_date'  => $tracker['payment_date'],
                'period'        => formatPeriod($period_start, $payment_frequency),
                'period_number' => $tracker['period_number'],
                'start_date'    => $tracker['start_date'],
                'end_date'      => $tracker['end_date'],
                'amount'        => (float) $tracker['amount_paid'],
            ];
            if (!$last_paid_end_date || $tracker['end_date'] > $last_paid_end_date) {
                $last_paid_end_date   = $tracker['end_date'];
                $last_payment_date    = $tracker['payment_date'];
                $last_payment_period  = formatPeriod($period_start, $payment_frequency);
            }
        } elseif ($tracker['status'] === 'pending_verification') {
            $has_pending_verification = true;
            $period_start = new DateTime($tracker['start_date']);
            $pending_verification_period = [
                'period_number' => $tracker['period_number'],
                'period'        => formatPeriod($period_start, $payment_frequency),
                'start_date'    => $tracker['start_date'],
                'end_date'      => $tracker['end_date'],
                'amount_due'    => (float) $tracker['amount_paid'],
                'status'        => $tracker['status'],
            ];
            $pending_verification_tracker[] = [
                'period_number'     => $tracker['period_number'],
                'period'            => formatPeriod($period_start, $payment_frequency),
                'start_date'        => $tracker['start_date'],
                'end_date'          => $tracker['end_date'],
                'amount_paid'       => (float) $tracker['amount_paid'],
                'payment_date'      => $tracker['payment_date'],
                'remaining_balance' => (float) $tracker['remaining_balance'],
            ];
        } elseif ($tracker['status'] === 'failed') {
            $has_failed_payment = true;
            $failed_payment_count++;
            $period_start = new DateTime($tracker['start_date']);
            $failed_payment = [
                'period_number' => $tracker['period_number'],
                'period'        => formatPeriod($period_start, $payment_frequency),
                'start_date'    => $tracker['start_date'],
                'end_date'      => $tracker['end_date'],
                'amount_due'    => (float) $tracker['amount_paid'],
                'status'        => $tracker['status'],
            ];
        }
    }

    // ==================== PENDING TRACKERS ====================
    $pending_trackers = [];
    foreach ($trackerRecords as $tracker) {
        if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
            $period_start = new DateTime($tracker['start_date']);
            $pending_trackers[] = [
                'tracker_id'        => $tracker['tracker_id'],
                'period_number'     => $tracker['period_number'],
                'period'            => formatPeriod($period_start, $payment_frequency),
                'start_date'        => $tracker['start_date'],
                'end_date'          => $tracker['end_date'],
                'amount_due'        => $payment_amount_per_period,
                'status'            => $tracker['status'],
                'remaining_balance' => (float) $tracker['remaining_balance'],
            ];
        }
    }

    // ==================== CURRENT PERIOD ====================
    $current_period = null;
    $upcoming_period = null;
    $today_date = new DateTime();
    $today_date->setTime(0, 0, 0);

    // Find paid period covering today
    $found_paid_period = null;
    foreach ($paid_periods as $period) {
        $period_start = new DateTime($period['start_date']);
        $period_end   = new DateTime($period['end_date']);
        $period_end->modify('+1 day');
        if ($today_date >= $period_start && $today_date <= $period_end) {
            $found_paid_period = $period;
            break;
        }
    }

    if ($found_paid_period) {
        $period_start = new DateTime($found_paid_period['start_date']);
        $period_end   = new DateTime($found_paid_period['end_date']);
        $current_period = [
            'period_number'   => $found_paid_period['period_number'],
            'period'          => $found_paid_period['period'],
            'start_date'      => $found_paid_period['start_date'],
            'end_date'        => $found_paid_period['end_date'],
            'start_formatted' => $period_start->format('F j, Y'),
            'end_formatted'   => $period_end->format('F j, Y'),
            'is_paid'         => true,
            'amount_paid'     => $found_paid_period['amount_paid'],
            'payment_date'    => $found_paid_period['payment_date'],
            'status'          => 'active',
            'days_elapsed'    => $today_date->diff($period_start)->days,
            'days_remaining'  => $period_end->diff($today_date)->days,
        ];
    } else {
        foreach ($trackerRecords as $tracker) {
            if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
                $tracker_start = new DateTime($tracker['start_date']);
                $tracker_end   = new DateTime($tracker['end_date']);
                $tracker_end->modify('+1 day');
                if ($today_date >= $tracker_start && $today_date <= $tracker_end) {
                    $current_period = [
                        'period_number'   => $tracker['period_number'],
                        'period'          => formatPeriod($tracker_start, $payment_frequency),
                        'start_date'      => $tracker['start_date'],
                        'end_date'        => $tracker['end_date'],
                        'start_formatted' => $tracker_start->format('F j, Y'),
                        'end_formatted'   => $tracker_end->format('F j, Y'),
                        'is_paid'         => false,
                        'amount_due'      => $payment_amount_per_period,
                        'status'          => $tracker['status'],
                        'days_elapsed'    => $today_date->diff($tracker_start)->days,
                        'days_remaining'  => $tracker_end->diff($today_date)->days,
                    ];
                    break;
                }
            }
        }

        if (!$current_period && !empty($pending_trackers)) {
            $first_pending = $pending_trackers[0];
            $period_start  = new DateTime($first_pending['start_date']);
            $period_end    = new DateTime($first_pending['end_date']);
            $current_period = [
                'period_number'   => $first_pending['period_number'],
                'period'          => $first_pending['period'],
                'start_date'      => $first_pending['start_date'],
                'end_date'        => $first_pending['end_date'],
                'start_formatted' => $period_start->format('F j, Y'),
                'end_formatted'   => $period_end->format('F j, Y'),
                'is_paid'         => false,
                'amount_due'      => $payment_amount_per_period,
                'status'          => 'upcoming',
                'days_elapsed'    => 0,
                'days_remaining'  => $period_end->diff($today_date)->days,
            ];
        }
    }

    // Upcoming period
    if ($current_period && !empty($pending_trackers)) {
        foreach ($pending_trackers as $tracker) {
            if ($tracker['period_number'] > $current_period['period_number']) {
                $tracker_start = new DateTime($tracker['start_date']);
                $tracker_end   = new DateTime($tracker['end_date']);
                $upcoming_period = [
                    'period_number'   => $tracker['period_number'],
                    'period'          => $tracker['period'],
                    'start_date'      => $tracker['start_date'],
                    'end_date'        => $tracker['end_date'],
                    'start_formatted' => $tracker_start->format('F j, Y'),
                    'end_formatted'   => $tracker_end->format('F j, Y'),
                    'amount_due'      => $tracker['amount_due'],
                    'is_paid'         => false,
                ];
                break;
            }
        }
    }

    // ==================== NEXT PAYMENT ====================
    $next_payment          = null;
    $has_upcoming_payment  = false;
    $has_overdue_payments  = false;
    $overdue_amount        = 0;

    foreach ($trackerRecords as $tracker) {
        if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
            $period_start = new DateTime($tracker['start_date']);
            $period_end   = new DateTime($tracker['end_date']);
            $dueDateConfig = ['Monthly' => 7, 'Quarterly' => 14, 'Semi-Annually' => 30, 'Annually' => 90];
            $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
            $due_date = clone $period_end;
            $due_date->modify("+{$daysToAdd} days");
            $is_overdue = ($due_date < $today_date);
            if ($is_overdue) {
                $has_overdue_payments = true;
                $overdue_amount += $payment_amount_per_period;
            }
            if (!$next_payment) {
                $next_payment = [
                    'period_number'     => $tracker['period_number'],
                    'period'            => formatPeriod($period_start, $payment_frequency),
                    'period_display'    => $period_start->format('M j, Y') . ' - ' . $period_end->format('M j, Y'),
                    'start_date'        => $tracker['start_date'],
                    'end_date'          => $tracker['end_date'],
                    'amount'            => $payment_amount_per_period,
                    'due_date'          => $due_date->format('Y-m-d'),
                    'status'            => $tracker['status'],
                    'is_overdue'        => $is_overdue,
                    'remaining_balance' => (float) $tracker['remaining_balance'],
                ];
                $has_upcoming_payment = true;
            }
            break;
        }
    }

    $last_completed_payment = !empty($completed_payments) ? end($completed_payments) : null;
    $total_paid = array_sum(array_column($completed_payments, 'amount'));

    // Lease fully paid?
    $remaining_unpaid = 0;
    foreach ($trackerRecords as $tracker) {
        if (in_array($tracker['status'], ['available', 'failed', 'pending_verification'], true)) {
            $remaining_unpaid++;
        }
    }
    $is_lease_fully_paid = ($remaining_unpaid == 0);

    // ==================== RENEWAL ELIGIBILITY ====================
    // Alumni are never eligible to renew; skip the whole block for them.
    $can_renew = false;
    $renewal_message = '';
    $new_cycle_rent_amount = null;
    $new_cycle_security_deposit = null;

    if ($isAlumni) {
        $renewal_message = "Your tenancy has ended. Contact support if you wish to rent another property.";
    } elseif ($is_lease_fully_paid) {
        $today_check    = new DateTime();
        $today_check->setTime(0, 0, 0);
        $lease_end_check = new DateTime($tenantData['lease_end_date']);
        $lease_end_check->setTime(0, 0, 0);

        if ($today_check >= $lease_end_check) {
            if (!$has_pending_verification) {
                $can_renew = true;
                $rentStmt = $conn->prepare("SELECT rent_amount, security_deposit FROM apartments WHERE apartment_code = ?");
                $rentStmt->bind_param("s", $tenantData['apartment_code']);
                $rentStmt->execute();
                if ($rentRow = $rentStmt->get_result()->fetch_assoc()) {
                    $new_cycle_rent_amount      = (float) $rentRow['rent_amount'];
                    $new_cycle_security_deposit = (float) $rentRow['security_deposit'];
                }
                $rentStmt->close();
                $renewal_message = "Your lease has ended. You can start a new lease cycle.";
            } else {
                $renewal_message = "You have a pending payment verification. Please wait for admin approval before renewing.";
            }
        } else {
            $days_until_end = $today_check->diff($lease_end_check)->days;
            $renewal_message = "Your lease is fully paid but ends on "
                . $lease_end_check->format('F j, Y')
                . ". You can renew on or after that date. ({$days_until_end} days remaining)";
        }
    }

    // ==================== RECENT MAINTENANCE REQUESTS ====================
    $recentRequestsQuery = "
        SELECT request_id, issue_type, status, created_at, priority
        FROM maintenance_requests
        WHERE tenant_code = ?
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $conn->prepare($recentRequestsQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $recent_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ==================== EVACUATION ELIGIBILITY ====================
    $can_request_evacuation = true;
    $evacuation_block_reason = null;

    if ($isAlumni) {
        // Alumni can never request evacuation again
        $can_request_evacuation = false;
        $evacuation_block_reason = "Your tenancy has already ended.";
    } else {
        $pendingStmt = $conn->prepare("
            SELECT COUNT(*) AS count FROM rent_payment_tracker
            WHERE tenant_code = ? AND status = 'pending_verification'
        ");
        $pendingStmt->bind_param("s", $tenant_code);
        $pendingStmt->execute();
        if ((int) $pendingStmt->get_result()->fetch_assoc()['count'] > 0) {
            $can_request_evacuation = false;
            $evacuation_block_reason = "You have a pending payment waiting for verification.";
        }
        $pendingStmt->close();

        $existingStmt = $conn->prepare("
            SELECT COUNT(*) AS count FROM evacuation_requests
            WHERE tenant_code = ? AND status IN ('pending_review', 'approved')
        ");
        $existingStmt->bind_param("s", $tenant_code);
        $existingStmt->execute();
        if ((int) $existingStmt->get_result()->fetch_assoc()['count'] > 0) {
            $can_request_evacuation = false;
            $evacuation_block_reason = "You already have a pending or approved evacuation request.";
        }
        $existingStmt->close();
    }

    // ==================== ALUMNI EXTRAS ====================
    // When the tenant is evacuated, include settlement + debt + wallet info
    $alumniBlock = null;

    if ($isAlumni) {
        // Fetch wallet balance (already in tenantData)
        $walletBalance = (float) ($tenantData['wallet_balance'] ?? 0);

        // Outstanding debt (from tenant_debts table)
        $outstandingDebt = 0.0;
        $debtCount = 0;
        $debtStmt = $conn->prepare("
            SELECT COUNT(*) AS c, COALESCE(SUM(balance), 0) AS total
            FROM tenant_debts
            WHERE tenant_code = ? AND status IN ('unpaid', 'partial', 'disputed')
        ");
        $debtStmt->bind_param("s", $tenant_code);
        $debtStmt->execute();
        if ($debtRow = $debtStmt->get_result()->fetch_assoc()) {
            $debtCount       = (int) $debtRow['c'];
            $outstandingDebt = (float) $debtRow['total'];
        }
        $debtStmt->close();

        // Fetch the most recent completed evacuation settlement
        $evStmt = $conn->prepare("
            SELECT request_id, approved_move_out_date, processed_at,
                   final_settlement_amount, security_deposit_refund,
                   settlement_receipt_number
            FROM evacuation_requests
            WHERE tenant_code = ? AND status = 'completed'
            ORDER BY processed_at DESC
            LIMIT 1
        ");
        $evStmt->bind_param("s", $tenant_code);
        $evStmt->execute();
        $evacuation = $evStmt->get_result()->fetch_assoc();
        $evStmt->close();

        $alumniBlock = [
            'is_alumni'    => true,
            'tenant_state' => 'evacuated',
            'wallet' => [
                'balance'           => $walletBalance,
                'balance_formatted' => '₦' . number_format($walletBalance, 2),
            ],
            'outstanding_debt' => [
                'has_debt'          => $debtCount > 0,
                'count'             => $debtCount,
                'balance'           => $outstandingDebt,
                'balance_formatted' => '₦' . number_format($outstandingDebt, 2),
            ],
            'evacuation' => $evacuation ? [
                'request_id'               => $evacuation['request_id'],
                'move_out_date'            => $evacuation['approved_move_out_date'],
                'processed_at'             => $evacuation['processed_at'],
                'final_settlement_amount'  => (float) $evacuation['final_settlement_amount'],
                'security_deposit_refund'  => (float) $evacuation['security_deposit_refund'],
                'settlement_receipt_number'=> $evacuation['settlement_receipt_number'],
            ] : null,
            'banner' => [
                'title'   => 'Your tenancy has ended',
                'message' => 'You can still access your wallet, settlement, and documents.',
                'type'    => 'info',
            ],
        ];
    }

    // ==================== ASSEMBLE RESPONSE ====================
    $dashboardData = [
        // ---- Common fields (both active and alumni) ----
        'property_name'                 => $tenantData['property_name'] ?? 'N/A',
        'property_address'              => $tenantData['property_address'] ?? 'N/A',
        'apartment_number'              => $tenantData['apartment_number'] ?? 'Not Assigned',
        'apartment_code'                => $tenantData['apartment_code'] ?? 'N/A',
        'active_requests'               => (int) ($maintenanceData['active_requests'] ?? 0),
        'days_remaining'                => $days_remaining,
        'payments_count'                => count($completed_payments),
        'total_paid'                    => $total_paid,
        'annual_rent'                   => $annual_rent,
        'payment_amount_per_period'     => $payment_amount_per_period,
        'rent_balance'                  => $rent_balance,
        'security_deposit'              => $security_deposit,
        'security_deposit_paid'         => $depositPayment ? (float) $depositPayment['amount'] : 0,
        'security_deposit_payment_date' => $depositPayment ? $depositPayment['payment_date'] : null,
        'payment_frequency'             => $payment_frequency,
        'lease_start_date'              => $tenantData['lease_start_date'],
        'lease_end_date'                => $tenantData['lease_end_date'],
        'agent_name'                    => $tenantData['agent_name']  ?? 'N/A',
        'agent_phone'                   => $tenantData['agent_phone'] ?? null,
        'agent_email'                   => $tenantData['agent_email'] ?? null,
        'recent_requests'               => $recent_requests,
        'rent_payment'                  => $rentPayment ? [
            'rent_payment_id'    => $rentPayment['rent_payment_id'],
            'total_annual_rent'  => (float) $rentPayment['total_annual_rent'],
            'initial_payment'    => (float) $rentPayment['initial_payment'],
            'remaining_balance'  => (float) $rentPayment['remaining_balance'],
            'payment_date'       => $rentPayment['payment_date'],
            'status'             => $rentPayment['status'],
            'reference_number'   => $rentPayment['reference_number'],
            'receipt_number'     => $rentPayment['receipt_number'],
        ] : null,
        'current_period'        => $current_period,
        'upcoming_period'       => $upcoming_period,
        'total_periods'         => count($trackerRecords),
        'paid_periods_count'    => count($paid_periods),
        'pending_periods_count' => count($pending_trackers),
        'paid_periods'          => $paid_periods,
        'pending_periods'       => $pending_trackers,
        'pending_verification_tracker' => $pending_verification_tracker,
        'pending_verification_count'   => count($pending_verification_tracker),
        'has_pending_payment'    => $has_pending_verification,
        'pending_payment_period' => $pending_verification_period,
        'failed_payment_tracker' => $failed_payment,
        'failed_payment_count'   => $failed_payment_count,
        'has_failed_payment'     => $has_failed_payment,
        'last_payment'           => $last_completed_payment,
        'last_paid_end_date'     => $last_paid_end_date,
        'last_payment_date'      => $last_payment_date,
        'last_payment_period'    => $last_payment_period,
        'next_payment'           => $next_payment,
        'has_upcoming_payment'   => $has_upcoming_payment,
        'has_overdue_payments'   => $has_overdue_payments,
        'overdue_amount'         => $overdue_amount,
        'is_lease_fully_paid'    => $is_lease_fully_paid,

        // Payment capability — for alumni, everything is "can't"
        'can_make_payment' => !$isAlumni && !$is_lease_fully_paid && !$has_pending_verification && $has_upcoming_payment,

        // Renewal
        'can_renew'                  => $can_renew,
        'renewal_message'            => $renewal_message,
        'new_cycle_rent_amount'      => $new_cycle_rent_amount,
        'new_cycle_security_deposit' => $new_cycle_security_deposit,

        // Current cycle
        'current_cycle' => $currentCycle ? [
            'rent_payment_id'           => $currentCycle['rent_payment_id'],
            'cycle_rent_amount'         => (float) $currentCycle['cycle_rent_amount'],
            'cycle_amount_paid'         => (float) $currentCycle['cycle_amount_paid'],
            'cycle_balance'             => (float) $currentCycle['cycle_balance'],
            'cycle_start_date'          => $currentCycle['period_start_date'],
            'cycle_end_date'            => $currentCycle['period_end_date'],
            'amount_paid_in_cycle'      => $amount_paid_in_cycle,
            'payment_amount_per_period' => (float) $currentCycle['payment_amount_per_period'],
            'status'                    => $currentCycle['status'],
            'payment_frequency'         => $payment_frequency,
        ] : null,

        // Evacuation gate
        'can_request_evacuation'  => $can_request_evacuation,
        'evacuation_block_reason' => $evacuation_block_reason,

        // Summary block (for payments.js)
        'summary' => [
            'total_paid'          => (float) $total_paid,
            'successful_payments' => (int) count($completed_payments),
            'payment_per_period'  => (float) $payment_amount_per_period,
            'remaining_balance'   => (float) $rent_balance,
        ],

        // ---- Alumni marker + extras ----
        'is_alumni'    => $isAlumni,
        'tenant_state' => $isAlumni ? 'evacuated' : 'active',
    ];

    if ($isAlumni && $alumniBlock !== null) {
        $dashboardData['alumni'] = $alumniBlock;
    }

    logActivity("=== FETCH DASHBOARD DATA COMPLETED ===");
    logActivity("Alumni: " . ($isAlumni ? 'YES' : 'NO') . " | Total Paid: {$total_paid} | Balance: {$rent_balance}");

    json_success($dashboardData, "Dashboard data retrieved successfully");

} catch (Throwable $e) {
    logActivity("Error in fetch_dashboard_data: " . $e->getMessage());
    logActivity("Stack trace: " . $e->getTraceAsString());
    json_error("Failed to fetch dashboard data", 500, null, 'SERVER_ERROR');
}