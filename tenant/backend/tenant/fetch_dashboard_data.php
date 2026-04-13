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

    // Get tenant, apartment, and property details with rent_amount from apartments table
    $tenantQuery = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            t.lease_start_date,
            t.lease_end_date,
            t.payment_frequency,
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

    // Calculate days remaining on lease
    $days_remaining = 0;
    $lease_end_date = null;
    if ($tenantData['lease_end_date']) {
        $lease_end_date = new DateTime($tenantData['lease_end_date']);
        $today = new DateTime();
        $days_remaining = $today->diff($lease_end_date)->days;
        if ($today > $lease_end_date) {
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

    // Get ALL completed rent payments for the current apartment with due dates
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

    // Get the last completed rent payment with its due date
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

    // Get any pending/overdue payments with their due dates
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

    // Calculate the last paid period end date
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

    // Determine next payment information
    $next_payment_date = null;
    $next_payment_period = null;
    $next_payment_period_display = null;
    $next_payment_amount = $rent_amount;
    $has_upcoming_payment = false;
    $next_payment_due_date = null;
    $next_payment_period_start = null;
    $next_payment_period_end = null;

    $lease_end = new DateTime($tenantData['lease_end_date']);
    $today = new DateTime();
    
    // Get the latest payment record to check if there's an existing pending payment
    $latestPendingPayment = !empty($pendingPayments) ? $pendingPayments[0] : null;
    
    // If there's a pending payment, use its information
    if ($latestPendingPayment) {
        $has_upcoming_payment = true;
        $next_payment_period = $latestPendingPayment['payment_period'];
        $next_payment_period_start = $latestPendingPayment['period_start_date'];
        $next_payment_period_end = $latestPendingPayment['period_end_date'];
        $next_payment_period_display = calculatePeriodRange($next_payment_period_start, $payment_frequency)['display'];
        $next_payment_due_date = $latestPendingPayment['due_date'];
        $next_payment_date = $latestPendingPayment['period_start_date'];
    }
    // If there's a last paid period, calculate next period
    elseif ($last_paid_end_date) {
        $last_end = new DateTime($last_paid_end_date);
        $next_start = clone $last_end;
        $next_start->modify('+1 day');
        
        // Calculate next period based on payment frequency
        $next_period = calculatePeriodRange($next_start->format('Y-m-d'), $payment_frequency);
        
        // Check if next period is within or after lease end
        $next_period_end = new DateTime($next_period['end']);
        
        if ($next_period_end <= $lease_end) {
            // Next period is within current lease
            $next_payment_date = $next_period['start'];
            $next_payment_period = formatPeriod(new DateTime($next_period['start']), $payment_frequency);
            $next_payment_period_display = $next_period['display'];
            $next_payment_period_start = $next_period['start'];
            $next_payment_period_end = $next_period['end'];
            $has_upcoming_payment = true;
            
            // Calculate due date based on payment frequency
            $dueDateConfig = [
                'Monthly' => 7,
                'Quarterly' => 14,
                'Semi-Annually' => 30,
                'Annually' => 90
            ];
            $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
            $due_date = new DateTime($next_period['end']);
            $due_date->modify("+{$daysToAdd} days");
            $next_payment_due_date = $due_date->format('Y-m-d');
        } elseif ($next_period['start'] <= $lease_end->format('Y-m-d')) {
            // Partial period until lease end
            $next_payment_date = $next_period['start'];
            $next_payment_period = formatPeriod(new DateTime($next_period['start']), $payment_frequency);
            $next_payment_period_display = "Partial period: " . $next_period['start'] . " to " . $lease_end->format('Y-m-d');
            $next_payment_period_start = $next_period['start'];
            $next_payment_period_end = $lease_end->format('Y-m-d');
            $has_upcoming_payment = true;
            
            $due_date = clone $lease_end;
            $due_date->modify('+7 days');
            $next_payment_due_date = $due_date->format('Y-m-d');
        } else {
            // No upcoming payment needed
            $has_upcoming_payment = false;
            $next_payment_period_display = "Lease paid in full";
        }
    } else {
        // No payments yet - first payment is due at lease start
        $first_period = calculatePeriodRange($tenantData['lease_start_date'], $payment_frequency);
        $next_payment_date = $first_period['start'];
        $next_payment_period = formatPeriod(new DateTime($first_period['start']), $payment_frequency);
        $next_payment_period_display = $first_period['display'];
        $next_payment_period_start = $first_period['start'];
        $next_payment_period_end = $first_period['end'];
        $has_upcoming_payment = true;
        
        // Calculate due date for first payment
        $dueDateConfig = [
            'Monthly' => 7,
            'Quarterly' => 14,
            'Semi-Annually' => 30,
            'Annually' => 90
        ];
        $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
        $due_date = new DateTime($first_period['end']);
        $due_date->modify("+{$daysToAdd} days");
        $next_payment_due_date = $due_date->format('Y-m-d');
    }

    // Check if there are any overdue payments
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
        // Last completed payment info
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
        // Pending payments info
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

    // Return success with dashboard data
    json_success($dashboardData, "Dashboard data retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_dashboard_data: " . $e->getMessage());
    json_error("Failed to fetch dashboard data", 500, null, 'SERVER_ERROR');
}
?>