<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check if user is logged in
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401, null, 'AUTH_REQUIRED');
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403, null, 'UNAUTHORIZED');
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;

    if (!$tenant_code) {
        json_error("Tenant code not found", 400, null, 'TENANT_CODE_MISSING');
    }

    logActivity("=== FETCH DASHBOARD DATA START ===");
    logActivity("Tenant Code: {$tenant_code}");

    // Get active maintenance requests count
    $maintenanceQuery = "
        SELECT COUNT(*) as active_requests
        FROM maintenance_requests
        WHERE tenant_code = ? AND status IN ('pending', 'in_progress')
    ";
    $stmt = $conn->prepare($maintenanceQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $maintenanceResult = $stmt->get_result();
    $maintenanceData = $maintenanceResult->fetch_assoc();
    $stmt->close();

    // Get payments summary for current year
    $paymentsQuery = "
        SELECT 
            COUNT(*) as payments_count,
            COALESCE(SUM(amount), 0) as total_paid
        FROM rent_payments
        WHERE tenant_code = ? AND status = 'completed' 
        AND payment_type = 'rent'
        AND YEAR(payment_date) = YEAR(CURDATE())
    ";
    $stmt = $conn->prepare($paymentsQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $paymentsResult = $stmt->get_result();
    $paymentsData = $paymentsResult->fetch_assoc();
    $stmt->close();

    // Get tenant, apartment, and property details
    $tenantQuery = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            t.lease_start_date,
            t.lease_end_date,
            t.payment_frequency,
            t.temp_lease_end_date,
            a.apartment_number,
            a.apartment_code,
            a.rent_amount,
            a.security_deposit,
            a.occupancy_status,
            p.name as property_name,
            p.property_code,
            p.address as property_address,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
            ag.phone as agent_phone,
            ag.email as agent_email
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        LEFT JOIN agents ag ON p.agent_code = ag.agent_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";
    $stmt = $conn->prepare($tenantQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $tenantResult = $stmt->get_result();
    $tenantData = $tenantResult->fetch_assoc();
    $stmt->close();

    if (!$tenantData) {
        json_error("Tenant data not found", 404, null, 'TENANT_NOT_FOUND');
    }

    logActivity("Tenant Data - Lease Start: {$tenantData['lease_start_date']}, Lease End: {$tenantData['lease_end_date']}, Frequency: {$tenantData['payment_frequency']}");

    // Calculate days remaining on lease
    $days_remaining = 0;
    if ($tenantData['lease_end_date']) {
        $end_date = new DateTime($tenantData['lease_end_date']);
        $today = new DateTime();
        $days_remaining = $today->diff($end_date)->days;
        if ($today > $end_date) {
            $days_remaining = 0;
        }
    }

    // Get the correct rent amount
    $rent_amount = (float)($tenantData['rent_amount'] ?? 0);
    $payment_frequency = $tenantData['payment_frequency'] ?? 'Monthly';

    // Function to format period based on frequency and date
    function formatPeriod($date, $frequency) {
        switch($frequency) {
            case 'Monthly':
                return $date->format('F Y');
            case 'Quarterly':
                $quarter = ceil($date->format('n') / 3);
                return "Q{$quarter} {$date->format('Y')}";
            case 'Semi-Annually':
                $half = $date->format('n') <= 6 ? 'H1' : 'H2';
                return "{$half} {$date->format('Y')}";
            case 'Annually':
                return $date->format('Y');
            default:
                return $date->format('F Y');
        }
    }

    // Function to calculate period range
    function calculatePeriodRange($start_date, $frequency) {
        $start = new DateTime($start_date);
        $end = clone $start;
        
        switch($frequency) {
            case 'Monthly':
                $end->modify('+1 month')->modify('-1 day');
                break;
            case 'Quarterly':
                $end->modify('+3 months')->modify('-1 day');
                break;
            case 'Semi-Annually':
                $end->modify('+6 months')->modify('-1 day');
                break;
            case 'Annually':
                $end->modify('+1 year')->modify('-1 day');
                break;
            default:
                $end->modify('+1 month')->modify('-1 day');
        }
        
        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'display' => $start->format('M j, Y') . ' - ' . $end->format('M j, Y')
        ];
    }

    // Function to determine which period a date falls into
    function getPeriodForDate($date, $start_date, $frequency) {
        $dateObj = new DateTime($date);
        $start = new DateTime($start_date);
        $period_start = clone $start;
        $period_end = clone $start;
        
        // If date is before lease start, return the first period
        if ($dateObj < $start) {
            switch($frequency) {
                case 'Monthly':
                    $period_end->modify('+1 month')->modify('-1 day');
                    break;
                case 'Quarterly':
                    $period_end->modify('+3 months')->modify('-1 day');
                    break;
                case 'Semi-Annually':
                    $period_end->modify('+6 months')->modify('-1 day');
                    break;
                case 'Annually':
                    $period_end->modify('+1 year')->modify('-1 day');
                    break;
                default:
                    $period_end->modify('+1 month')->modify('-1 day');
            }
            return [
                'start' => $period_start->format('Y-m-d'),
                'end' => $period_end->format('Y-m-d'),
                'start_obj' => $period_start,
                'end_obj' => $period_end
            ];
        }
        
        switch($frequency) {
            case 'Monthly':
                $months_diff = ($dateObj->format('Y') - $start->format('Y')) * 12 + 
                              ($dateObj->format('n') - $start->format('n'));
                $period_start->modify("+{$months_diff} months");
                $period_end = clone $period_start;
                $period_end->modify('+1 month')->modify('-1 day');
                break;
                
            case 'Quarterly':
                $quarters_diff = floor(($dateObj->format('Y') - $start->format('Y')) * 4 + 
                                      ($dateObj->format('n') - $start->format('n')) / 3);
                $period_start->modify("+{$quarters_diff} months");
                $period_end = clone $period_start;
                $period_end->modify('+3 months')->modify('-1 day');
                break;
                
            case 'Semi-Annually':
                $half_years_diff = floor(($dateObj->format('Y') - $start->format('Y')) * 2 + 
                                        ($dateObj->format('n') - $start->format('n')) / 6);
                $period_start->modify("+{$half_years_diff} months");
                $period_end = clone $period_start;
                $period_end->modify('+6 months')->modify('-1 day');
                break;
                
            case 'Annually':
                $years_diff = $dateObj->format('Y') - $start->format('Y');
                $period_start->modify("+{$years_diff} years");
                $period_end = clone $period_start;
                $period_end->modify('+1 year')->modify('-1 day');
                break;
                
            default:
                $period_start = clone $start;
                $period_end = clone $start;
                $period_end->modify('+1 month')->modify('-1 day');
        }
        
        return [
            'start' => $period_start->format('Y-m-d'),
            'end' => $period_end->format('Y-m-d'),
            'start_obj' => $period_start,
            'end_obj' => $period_end
        ];
    }

    // Get ALL completed rent payments
    $allPaymentsQuery = "
        SELECT 
            payment_id,
            payment_date,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            amount,
            status,
            created_at
        FROM rent_payments
        WHERE tenant_code = ? 
        AND apartment_code = ?
        AND payment_type = 'rent'
        AND status = 'completed'
        ORDER BY period_start_date ASC
    ";
    $stmt = $conn->prepare($allPaymentsQuery);
    $stmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $stmt->execute();
    $allPaymentsResult = $stmt->get_result();
    $allPayments = $allPaymentsResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    logActivity("Found " . count($allPayments) . " completed rent payments");

    // Get pending/overdue payments
    $pendingPaymentsQuery = "
        SELECT 
            payment_id,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            amount,
            status,
            created_at
        FROM rent_payments
        WHERE tenant_code = ? 
        AND apartment_code = ?
        AND payment_type = 'rent'
        AND status IN ('pending', 'overdue')
        ORDER BY due_date ASC
    ";
    $stmt = $conn->prepare($pendingPaymentsQuery);
    $stmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $stmt->execute();
    $pendingResult = $stmt->get_result();
    $pendingPayments = $pendingResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate paid periods summary
    $last_paid_end_date = null;
    $last_payment_date = null;
    $last_payment_due_date = null;
    $last_payment_period = null;
    $paid_periods = [];
    
    foreach ($allPayments as $payment) {
        $paid_periods[] = [
            'period' => $payment['payment_period'],
            'start_date' => $payment['period_start_date'],
            'end_date' => $payment['period_end_date'],
            'due_date' => $payment['due_date'],
            'payment_date' => $payment['payment_date']
        ];
        if ($payment['period_end_date'] && (!$last_paid_end_date || $payment['period_end_date'] > $last_paid_end_date)) {
            $last_paid_end_date = $payment['period_end_date'];
            $last_payment_date = $payment['payment_date'];
            $last_payment_due_date = $payment['due_date'];
            $last_payment_period = $payment['payment_period'];
        }
    }

    // Get last completed payment
    $lastCompletedPaymentQuery = "
        SELECT 
            payment_id,
            payment_date,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            amount,
            status,
            created_at
        FROM rent_payments
        WHERE tenant_code = ? 
        AND apartment_code = ?
        AND payment_type = 'rent'
        AND status = 'completed'
        ORDER BY period_end_date DESC, payment_date DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($lastCompletedPaymentQuery);
    $stmt->bind_param("ss", $tenant_code, $tenantData['apartment_code']);
    $stmt->execute();
    $lastPaymentResult = $stmt->get_result();
    $lastCompletedPayment = $lastPaymentResult->fetch_assoc();
    $stmt->close();

    // ==================== CURRENT PERIOD CALCULATION ====================
    $current_period = null;
    $upcoming_period = null;
    $today_date = new DateTime();
    $today_date->setTime(0, 0, 0);
    $lease_start = new DateTime($tenantData['lease_start_date']);
    $lease_end = new DateTime($tenantData['lease_end_date']);

    logActivity("=== CURRENT PERIOD CALCULATION ===");
    logActivity("Today: " . $today_date->format('Y-m-d'));
    logActivity("Lease: {$tenantData['lease_start_date']} to {$tenantData['lease_end_date']}");

    // First, check if any paid period covers today's date (including periods before lease start)
    $found_paid_period = null;
    foreach ($paid_periods as $period) {
        if ($period['start_date'] && $period['end_date']) {
            $period_start = new DateTime($period['start_date']);
            $period_end = new DateTime($period['end_date']);
            $period_end_inclusive = clone $period_end;
            $period_end_inclusive->modify('+1 day');
            
            if ($today_date >= $period_start && $today_date <= $period_end_inclusive) {
                $found_paid_period = $period;
                logActivity("Found paid period covering today: {$period['period']} ({$period['start_date']} to {$period['end_date']})");
                break;
            }
        }
    }
    
    if ($found_paid_period) {
        // Use the found paid period as current period
        $period_start = new DateTime($found_paid_period['start_date']);
        $period_end = new DateTime($found_paid_period['end_date']);
        
        $current_period = [
            'period' => $found_paid_period['period'],
            'start_date' => $found_paid_period['start_date'],
            'end_date' => $found_paid_period['end_date'],
            'start_formatted' => $period_start->format('F j, Y'),
            'end_formatted' => $period_end->format('F j, Y'),
            'is_paid' => true,
            'payment_id' => null,
            'payment_date' => $found_paid_period['payment_date'],
            'due_date' => $found_paid_period['due_date'],
            'status' => 'active',
            'days_elapsed' => $today_date->diff($period_start)->days,
            'days_remaining' => $period_end->diff($today_date)->days
        ];
        logActivity("Current period set from paid period: {$current_period['period']}");
    } else {
        // No paid period covers today, calculate theoretical period
        if ($today_date >= $lease_start && $today_date <= $lease_end) {
            $theoretical_period = getPeriodForDate($today_date->format('Y-m-d'), $tenantData['lease_start_date'], $payment_frequency);
            $period_start = new DateTime($theoretical_period['start']);
            $period_end = new DateTime($theoretical_period['end']);
            
            $current_period = [
                'period' => formatPeriod($period_start, $payment_frequency),
                'start_date' => $theoretical_period['start'],
                'end_date' => $theoretical_period['end'],
                'start_formatted' => $period_start->format('F j, Y'),
                'end_formatted' => $period_end->format('F j, Y'),
                'is_paid' => false,
                'payment_id' => null,
                'payment_date' => null,
                'due_date' => null,
                'status' => 'pending',
                'days_elapsed' => $today_date->diff($period_start)->days,
                'days_remaining' => $period_end->diff($today_date)->days
            ];
            logActivity("Current period set as theoretical (unpaid): {$current_period['period']}");
        } elseif ($today_date < $lease_start) {
            // Before lease start - show the first period
            $first_period = calculatePeriodRange($tenantData['lease_start_date'], $payment_frequency);
            $period_start = new DateTime($first_period['start']);
            $period_end = new DateTime($first_period['end']);
            
            $current_period = [
                'period' => formatPeriod($period_start, $payment_frequency),
                'start_date' => $first_period['start'],
                'end_date' => $first_period['end'],
                'start_formatted' => $period_start->format('F j, Y'),
                'end_formatted' => $period_end->format('F j, Y'),
                'is_paid' => false,
                'payment_id' => null,
                'payment_date' => null,
                'due_date' => null,
                'status' => 'upcoming',
                'days_elapsed' => 0,
                'days_remaining' => $period_end->diff($today_date)->days
            ];
            logActivity("Current period set as upcoming (before lease): {$current_period['period']}");
        }
    }
    
    // Calculate upcoming period (next period after current)
    if ($current_period) {
        $current_end = new DateTime($current_period['end_date']);
        $next_start = clone $current_end;
        $next_start->modify('+1 day');
        
        if ($next_start <= $lease_end) {
            $next_period = calculatePeriodRange($next_start->format('Y-m-d'), $payment_frequency);
            $next_start_display = new DateTime($next_period['start']);
            $next_end_display = new DateTime($next_period['end']);
            
            // Check if this upcoming period is already paid
            $is_upcoming_paid = false;
            foreach ($paid_periods as $period) {
                if ($period['start_date'] && $period['end_date']) {
                    $paid_start = new DateTime($period['start_date']);
                    $paid_end = new DateTime($period['end_date']);
                    
                    if ($paid_start <= new DateTime($next_period['end']) && $paid_end >= new DateTime($next_period['start'])) {
                        $is_upcoming_paid = true;
                        break;
                    }
                }
            }
            
            $upcoming_period = [
                'period' => formatPeriod($next_start_display, $payment_frequency),
                'start_date' => $next_period['start'],
                'end_date' => $next_period['end'],
                'start_formatted' => $next_start_display->format('F j, Y'),
                'end_formatted' => $next_end_display->format('F j, Y'),
                'amount' => $rent_amount,
                'is_paid' => $is_upcoming_paid
            ];
            logActivity("Upcoming period: {$upcoming_period['period']} (Paid: " . ($is_upcoming_paid ? 'Yes' : 'No') . ")");
        }
    }

    // ==================== NEXT PAYMENT CALCULATION ====================
    $next_payment_date = null;
    $next_payment_period = null;
    $next_payment_period_display = null;
    $next_payment_amount = $rent_amount;
    $has_upcoming_payment = false;
    $next_payment_due_date = null;
    $next_payment_period_start = null;
    $next_payment_period_end = null;

    $latestPendingPayment = !empty($pendingPayments) ? $pendingPayments[0] : null;
    
    if ($latestPendingPayment) {
        $has_upcoming_payment = true;
        $next_payment_period = $latestPendingPayment['payment_period'];
        $next_payment_period_start = $latestPendingPayment['period_start_date'];
        $next_payment_period_end = $latestPendingPayment['period_end_date'];
        $next_payment_period_display = calculatePeriodRange($next_payment_period_start, $payment_frequency)['display'];
        $next_payment_due_date = $latestPendingPayment['due_date'];
        $next_payment_date = $latestPendingPayment['period_start_date'];
    } elseif ($last_paid_end_date) {
        $last_end = new DateTime($last_paid_end_date);
        $next_start = clone $last_end;
        $next_start->modify('+1 day');
        
        if ($next_start <= $lease_end) {
            $next_period = calculatePeriodRange($next_start->format('Y-m-d'), $payment_frequency);
            $next_payment_date = $next_period['start'];
            $next_payment_period = formatPeriod(new DateTime($next_period['start']), $payment_frequency);
            $next_payment_period_display = $next_period['display'];
            $next_payment_period_start = $next_period['start'];
            $next_payment_period_end = $next_period['end'];
            $has_upcoming_payment = true;
            
            $dueDateConfig = ['Monthly' => 7, 'Quarterly' => 14, 'Semi-Annually' => 30, 'Annually' => 90];
            $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
            $due_date = new DateTime($next_period['end']);
            $due_date->modify("+{$daysToAdd} days");
            $next_payment_due_date = $due_date->format('Y-m-d');
        } else {
            $has_upcoming_payment = false;
            $next_payment_period_display = "Lease paid in full";
        }
    } else {
        $first_period = calculatePeriodRange($tenantData['lease_start_date'], $payment_frequency);
        $next_payment_date = $first_period['start'];
        $next_payment_period = formatPeriod(new DateTime($first_period['start']), $payment_frequency);
        $next_payment_period_display = $first_period['display'];
        $next_payment_period_start = $first_period['start'];
        $next_payment_period_end = $first_period['end'];
        $has_upcoming_payment = true;
        
        $dueDateConfig = ['Monthly' => 7, 'Quarterly' => 14, 'Semi-Annually' => 30, 'Annually' => 90];
        $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
        $due_date = new DateTime($first_period['end']);
        $due_date->modify("+{$daysToAdd} days");
        $next_payment_due_date = $due_date->format('Y-m-d');
    }

    // Check for overdue payments
    $has_overdue_payments = false;
    $overdue_amount = 0;
    foreach ($pendingPayments as $pending) {
        if ($pending['status'] === 'overdue' || ($pending['due_date'] && new DateTime($pending['due_date']) < new DateTime())) {
            $has_overdue_payments = true;
            $overdue_amount += $pending['amount'];
        }
    }

    $has_pending_payments = !empty($pendingPayments);
    $pending_amount = array_sum(array_column($pendingPayments, 'amount'));

    // Get recent maintenance requests
    $recentRequestsQuery = "
        SELECT request_id, issue_type, status, created_at, priority
        FROM maintenance_requests
        WHERE tenant_code = ?
        ORDER BY created_at DESC LIMIT 5
    ";
    $stmt = $conn->prepare($recentRequestsQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $recentRequestsResult = $stmt->get_result();
    $recent_requests = [];
    while ($row = $recentRequestsResult->fetch_assoc()) {
        $recent_requests[] = $row;
    }
    $stmt->close();

    $conn->close();

    // Prepare dashboard data
    $dashboardData = [
        'property_name' => $tenantData['property_name'] ?? 'N/A',
        'property_address' => $tenantData['property_address'] ?? 'N/A',
        'apartment_number' => $tenantData['apartment_number'] ?? 'Not Assigned',
        'apartment_code' => $tenantData['apartment_code'] ?? 'N/A',
        'active_requests' => (int)($maintenanceData['active_requests'] ?? 0),
        'days_remaining' => $days_remaining,
        'payments_count' => count($allPayments),
        'total_paid' => array_sum(array_column($allPayments, 'amount')),
        'rent_amount' => $rent_amount,
        'security_deposit' => (float)($tenantData['security_deposit'] ?? 0),
        'payment_frequency' => $payment_frequency,
        'lease_start_date' => $tenantData['lease_start_date'],
        'lease_end_date' => $tenantData['lease_end_date'],
        'agent_name' => $tenantData['agent_name'] ?? 'N/A',
        'agent_phone' => $tenantData['agent_phone'] ?? null,
        'agent_email' => $tenantData['agent_email'] ?? null,
        'recent_requests' => $recent_requests,
        // Current running period
        'current_period' => $current_period,
        'upcoming_period' => $upcoming_period,
        // Last completed payment
        'last_payment' => $lastCompletedPayment ? [
            'payment_id' => $lastCompletedPayment['payment_id'],
            'payment_date' => $lastCompletedPayment['payment_date'],
            'payment_period' => $lastCompletedPayment['payment_period'],
            'period_start_date' => $lastCompletedPayment['period_start_date'],
            'period_end_date' => $lastCompletedPayment['period_end_date'],
            'due_date' => $lastCompletedPayment['due_date'],
            'amount' => (float)$lastCompletedPayment['amount']
        ] : null,
        // Next payment info
        'next_payment_amount' => $has_upcoming_payment ? $next_payment_amount : 0,
        'next_payment_date' => $next_payment_date,
        'next_payment_period' => $next_payment_period,
        'next_payment_period_display' => $next_payment_period_display,
        'next_payment_period_start' => $next_payment_period_start,
        'next_payment_period_end' => $next_payment_period_end,
        'next_payment_due_date' => $next_payment_due_date,
        'has_upcoming_payment' => $has_upcoming_payment,
        // Pending payments
        'has_pending_payments' => $has_pending_payments,
        'has_overdue_payments' => $has_overdue_payments,
        'pending_payments_count' => count($pendingPayments),
        'pending_payments_amount' => $pending_amount,
        'overdue_amount' => $overdue_amount,
        'pending_payments' => $pendingPayments,
        // Paid periods summary
        'paid_periods' => $paid_periods,
        'last_paid_end_date' => $last_paid_end_date,
        'last_payment_date' => $last_payment_date,
        'last_payment_due_date' => $last_payment_due_date,
        'last_payment_period' => $last_payment_period,
        'can_make_payment' => !$has_pending_payments && $has_upcoming_payment
    ];

    logActivity("=== FETCH DASHBOARD DATA COMPLETED ===");
    json_success($dashboardData, "Dashboard data retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_dashboard_data: " . $e->getMessage());
    json_error("Failed to fetch dashboard data", 500, null, 'SERVER_ERROR');
}
?>