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

    // Get tenant, apartment, and property details
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
            a.apartment_number,
            a.apartment_code,
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
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $end_date = new DateTime($tenantData['lease_end_date']);
        $end_date->setTime(0, 0, 0);

        if ($today <= $end_date) {
            $days_remaining = $today->diff($end_date)->days;
        }
    }

    // Get the correct rent amounts from tenant data
    $annual_rent = (float) ($tenantData['agreed_rent_amount'] ?? 0);
    $payment_amount_per_period = (float) ($tenantData['payment_amount_per_period'] ?? 0);
    $rent_balance = (float) ($tenantData['rent_balance'] ?? 0);
    $payment_frequency = $tenantData['agreed_payment_frequency'] ?? $tenantData['payment_frequency'] ?? 'Monthly';
    $security_deposit = (float) ($tenantData['security_deposit'] ?? 0);

    logActivity("Annual Rent: {$annual_rent}, Payment Per Period: {$payment_amount_per_period}, Balance: {$rent_balance}, Frequency: {$payment_frequency}");

    // Get the main rent payment record (from onboarding)
    $rentPaymentQuery = "
        SELECT 
            rent_payment_id,
            amount as total_annual_rent,
            amount_paid as initial_payment,
            balance as remaining_balance,
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
    $rentPaymentResult = $stmt->get_result();
    $rentPayment = $rentPaymentResult->fetch_assoc();
    $stmt->close();

    // Get ALL payment tracker records (periodic payments)
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
    $trackerResult = $stmt->get_result();
    $trackerRecords = $trackerResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    logActivity("Found " . count($trackerRecords) . " payment tracker records");

    // Get security deposit payment
    $depositQuery = "
        SELECT 
            id,
            amount,
            payment_date,
            due_date,
            receipt_number,
            reference_number,
            description,
            payment_status
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
    $depositResult = $stmt->get_result();
    $depositPayment = $depositResult->fetch_assoc();
    $stmt->close();

    // Function to format period based on frequency and date
    function formatPeriod($date, $frequency)
    {
        switch ($frequency) {
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
    function calculatePeriodRange($start_date, $frequency)
    {
        $start = new DateTime($start_date);
        $end = clone $start;

        switch ($frequency) {
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

    // Process tracker records
    $paid_periods = [];
    $completed_payments = [];
    $last_paid_end_date = null;
    $last_payment_date = null;
    $last_payment_period = null;
    $has_pending_verification = false;
    $pending_verification_period = null;

    foreach ($trackerRecords as $tracker) {
        if ($tracker['status'] === 'paid') {
            $period_start = new DateTime($tracker['start_date']);
            $period_end = new DateTime($tracker['end_date']);

            $paid_periods[] = [
                'period_number' => $tracker['period_number'],
                'period' => formatPeriod($period_start, $payment_frequency),
                'start_date' => $tracker['start_date'],
                'end_date' => $tracker['end_date'],
                'amount_paid' => (float) $tracker['amount_paid'],
                'payment_date' => $tracker['payment_date'],
                'remaining_balance' => (float) $tracker['remaining_balance']
            ];

            $completed_payments[] = [
                'payment_id' => $tracker['payment_id'],
                'payment_date' => $tracker['payment_date'],
                'period' => formatPeriod($period_start, $payment_frequency),
                'period_number' => $tracker['period_number'],
                'start_date' => $tracker['start_date'],
                'end_date' => $tracker['end_date'],
                'amount' => (float) $tracker['amount_paid']
            ];

            if (!$last_paid_end_date || $tracker['end_date'] > $last_paid_end_date) {
                $last_paid_end_date = $tracker['end_date'];
                $last_payment_date = $tracker['payment_date'];
                $last_payment_period = formatPeriod($period_start, $payment_frequency);
            }
        } elseif ($tracker['status'] === 'pending_verification') {
            $has_pending_verification = true;
            $period_start = new DateTime($tracker['start_date']);
            $pending_verification_period = [
                'period_number' => $tracker['period_number'],
                'period' => formatPeriod($period_start, $payment_frequency),
                'start_date' => $tracker['start_date'],
                'end_date' => $tracker['end_date'],
                'amount_due' => (float) $tracker['amount_paid'],
                'status' => $tracker['status']
            ];
        }
    }

    // Get pending/unpaid tracker records (available and failed)
    $pending_trackers = [];
    foreach ($trackerRecords as $tracker) {
        // Include 'available' and 'failed' statuses for pending periods
        // Exclude 'paid' and 'pending_verification' from pending periods
        if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
            $period_start = new DateTime($tracker['start_date']);

            // FIX: Use payment_amount_per_period for amount_due, not tracker's amount_paid
            // Because amount_paid is 0 for available periods
            $amount_due = $payment_amount_per_period;

            $pending_trackers[] = [
                'tracker_id' => $tracker['tracker_id'],
                'period_number' => $tracker['period_number'],
                'period' => formatPeriod($period_start, $payment_frequency),
                'start_date' => $tracker['start_date'],
                'end_date' => $tracker['end_date'],
                'amount_due' => $amount_due,  // ← FIXED: Now using correct amount
                'status' => $tracker['status'],
                'remaining_balance' => (float) $tracker['remaining_balance']
            ];
        }
    }

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

    // Check if any paid period covers today's date
    $found_paid_period = null;
    foreach ($paid_periods as $period) {
        $period_start = new DateTime($period['start_date']);
        $period_end = new DateTime($period['end_date']);
        $period_end->modify('+1 day');

        if ($today_date >= $period_start && $today_date <= $period_end) {
            $found_paid_period = $period;
            logActivity("Found paid period covering today: Period #{$period['period_number']}");
            break;
        }
    }

    if ($found_paid_period) {
        $period_start = new DateTime($found_paid_period['start_date']);
        $period_end = new DateTime($found_paid_period['end_date']);

        $current_period = [
            'period_number' => $found_paid_period['period_number'],
            'period' => $found_paid_period['period'],
            'start_date' => $found_paid_period['start_date'],
            'end_date' => $found_paid_period['end_date'],
            'start_formatted' => $period_start->format('F j, Y'),
            'end_formatted' => $period_end->format('F j, Y'),
            'is_paid' => true,
            'amount_paid' => $found_paid_period['amount_paid'],
            'payment_date' => $found_paid_period['payment_date'],
            'status' => 'active',
            'days_elapsed' => $today_date->diff($period_start)->days,
            'days_remaining' => $period_end->diff($today_date)->days
        ];
        logActivity("Current period set from paid period: {$current_period['period']}");
    } else {
        foreach ($trackerRecords as $tracker) {
            if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
                $tracker_start = new DateTime($tracker['start_date']);
                $tracker_end = new DateTime($tracker['end_date']);
                $tracker_end->modify('+1 day');

                if ($today_date >= $tracker_start && $today_date <= $tracker_end) {
                    $current_period = [
                        'period_number' => $tracker['period_number'],
                        'period' => formatPeriod($tracker_start, $payment_frequency),
                        'start_date' => $tracker['start_date'],
                        'end_date' => $tracker['end_date'],
                        'start_formatted' => $tracker_start->format('F j, Y'),
                        'end_formatted' => $tracker_end->format('F j, Y'),
                        'is_paid' => false,
                        'amount_due' => (float) $tracker['amount_paid'],
                        'status' => $tracker['status'],
                        'days_elapsed' => $today_date->diff($tracker_start)->days,
                        'days_remaining' => $tracker_end->diff($today_date)->days
                    ];
                    logActivity("Current period set from tracker: {$current_period['period']}");
                    break;
                }
            }
        }

        if (!$current_period && !empty($pending_trackers)) {
            $first_pending = $pending_trackers[0];
            $period_start = new DateTime($first_pending['start_date']);
            $period_end = new DateTime($first_pending['end_date']);

            $current_period = [
                'period_number' => $first_pending['period_number'],
                'period' => $first_pending['period'],
                'start_date' => $first_pending['start_date'],
                'end_date' => $first_pending['end_date'],
                'start_formatted' => $period_start->format('F j, Y'),
                'end_formatted' => $period_end->format('F j, Y'),
                'is_paid' => false,
                'amount_due' => $payment_amount_per_period,  // ← FIXED: Use correct amount
                'status' => 'upcoming',
                'days_elapsed' => 0,
                'days_remaining' => $period_end->diff($today_date)->days
            ];
            logActivity("Current period set as upcoming: {$current_period['period']}");
        }
    }

    // Calculate upcoming period
    if ($current_period && !empty($pending_trackers)) {
        $current_period_number = $current_period['period_number'];
        foreach ($pending_trackers as $tracker) {
            if ($tracker['period_number'] > $current_period_number) {
                $tracker_start = new DateTime($tracker['start_date']);
                $tracker_end = new DateTime($tracker['end_date']);

                $upcoming_period = [
                    'period_number' => $tracker['period_number'],
                    'period' => $tracker['period'],
                    'start_date' => $tracker['start_date'],
                    'end_date' => $tracker['end_date'],
                    'start_formatted' => $tracker_start->format('F j, Y'),
                    'end_formatted' => $tracker_end->format('F j, Y'),
                    'amount_due' => $tracker['amount_due'],
                    'is_paid' => false
                ];
                logActivity("Upcoming period: {$upcoming_period['period']}");
                break;
            }
        }
    }

    // ==================== NEXT PAYMENT CALCULATION ====================
    $next_payment = null;
    $has_upcoming_payment = false;
    $has_overdue_payments = false;
    $overdue_amount = 0;

    // Find the next unpaid period (available or failed)
    // Find the next unpaid period (available or failed)
    foreach ($trackerRecords as $tracker) {
        if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
            $period_start = new DateTime($tracker['start_date']);
            $period_end = new DateTime($tracker['end_date']);

            $dueDateConfig = ['Monthly' => 7, 'Quarterly' => 14, 'Semi-Annually' => 30, 'Annually' => 90];
            $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
            $due_date = clone $period_end;
            $due_date->modify("+{$daysToAdd} days");

            $is_overdue = ($due_date < $today_date);

            if ($is_overdue) {
                $has_overdue_payments = true;
                $overdue_amount += $payment_amount_per_period;  // ← FIXED: Use correct amount
            }

            if (!$next_payment) {
                $next_payment = [
                    'period_number' => $tracker['period_number'],
                    'period' => formatPeriod($period_start, $payment_frequency),
                    'period_display' => $period_start->format('M j, Y') . ' - ' . $period_end->format('M j, Y'),
                    'start_date' => $tracker['start_date'],
                    'end_date' => $tracker['end_date'],
                    'amount' => $payment_amount_per_period,  // ← FIXED: Use correct amount
                    'due_date' => $due_date->format('Y-m-d'),
                    'status' => $tracker['status'],
                    'is_overdue' => $is_overdue,
                    'remaining_balance' => (float) $tracker['remaining_balance']
                ];
                $has_upcoming_payment = true;
            }
            break;
        }
    }

    // Get last completed payment from tracker
    $last_completed_payment = !empty($completed_payments) ? $completed_payments[count($completed_payments) - 1] : null;

    // Calculate total paid amount
    $total_paid = array_sum(array_column($completed_payments, 'amount'));

    // Check if lease is fully paid (no available or failed periods left)
    $remaining_unpaid = 0;
    foreach ($trackerRecords as $tracker) {
        if ($tracker['status'] === 'available' || $tracker['status'] === 'failed') {
            $remaining_unpaid++;
        }
    }
    $is_lease_fully_paid = ($remaining_unpaid == 0);

        // ==================== RENEWAL ELIGIBILITY CHECK ====================
    logActivity("=== RENEWAL ELIGIBILITY CHECK START ===");
    
    $can_renew = false;
    $renewal_message = '';
    $new_cycle_rent_amount = null;
    $new_cycle_security_deposit = null;
    
    if ($is_lease_fully_paid) {
        logActivity("Lease is fully paid - checking renewal eligibility");
        
        $today_check = new DateTime();
        $today_check->setTime(0, 0, 0);
        $lease_end_check = new DateTime($tenantData['lease_end_date']);
        $lease_end_check->setTime(0, 0, 0);
        
        logActivity("Today: " . $today_check->format('Y-m-d'));
        logActivity("Lease End Date: " . $lease_end_check->format('Y-m-d'));
        
        // Check if lease has ended (today >= lease_end_date)
        // Allow renewal ON the lease end date (May 4)
        if ($today_check >= $lease_end_check) {
            logActivity("Lease has ended (today >= lease_end_date)");
            
            // Check if there's any pending verification payment (should not allow renewal)
            if (!$has_pending_verification) {
                logActivity("No pending verification found - tenant is eligible for renewal");
                $can_renew = true;
                
                // Get current apartment rent (may have been updated by admin)
                $currentRentQuery = "
                    SELECT a.rent_amount, a.security_deposit 
                    FROM apartments a 
                    WHERE a.apartment_code = ?
                ";
                $rentStmt = $conn->prepare($currentRentQuery);
                $rentStmt->bind_param("s", $tenantData['apartment_code']);
                $rentStmt->execute();
                $rentResult = $rentStmt->get_result();
                if ($rentRow = $rentResult->fetch_assoc()) {
                    $new_cycle_rent_amount = (float)$rentRow['rent_amount'];
                    $new_cycle_security_deposit = (float)$rentRow['security_deposit'];
                    logActivity("Current apartment rent amount: ₦{$new_cycle_rent_amount}");
                    logActivity("Current security deposit: ₦{$new_cycle_security_deposit}");
                } else {
                    logActivity("WARNING: Could not fetch apartment rent amount for code: " . $tenantData['apartment_code']);
                }
                $rentStmt->close();
                
                $renewal_message = "Your lease has ended. You can start a new lease cycle.";
                logActivity("Renewal eligibility: YES (can_renew = true)");
            } else {
                logActivity("Renewal blocked: Has pending verification payment");
                $renewal_message = "You have a pending payment verification. Please wait for admin approval before renewing.";
            }
        } else {
            $days_until_end = $today_check->diff($lease_end_check)->days;
            logActivity("Lease not yet ended - {$days_until_end} days remaining until " . $lease_end_check->format('Y-m-d'));
            $renewal_message = "Your lease is fully paid but ends on " . $lease_end_check->format('F j, Y') . ". You can renew on or after that date. ($days_until_end days remaining)";
        }
    } else {
        logActivity("Lease is NOT fully paid - renewal not eligible");
    }
    
    logActivity("=== RENEWAL ELIGIBILITY CHECK END ===");
    logActivity("can_renew: " . ($can_renew ? 'true' : 'false'));
    logActivity("renewal_message: " . $renewal_message);

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
        'active_requests' => (int) ($maintenanceData['active_requests'] ?? 0),
        'days_remaining' => $days_remaining,
        'payments_count' => count($completed_payments),
        'total_paid' => $total_paid,
        'annual_rent' => $annual_rent,
        'payment_amount_per_period' => $payment_amount_per_period,
        'rent_balance' => $rent_balance,
        'security_deposit' => $security_deposit,
        'security_deposit_paid' => $depositPayment ? (float) $depositPayment['amount'] : 0,
        'security_deposit_payment_date' => $depositPayment ? $depositPayment['payment_date'] : null,
        'payment_frequency' => $payment_frequency,
        'lease_start_date' => $tenantData['lease_start_date'],
        'lease_end_date' => $tenantData['lease_end_date'],
        'agent_name' => $tenantData['agent_name'] ?? 'N/A',
        'agent_phone' => $tenantData['agent_phone'] ?? null,
        'agent_email' => $tenantData['agent_email'] ?? null,
        'recent_requests' => $recent_requests,
        // Main rent payment record
        'rent_payment' => $rentPayment ? [
            'rent_payment_id' => $rentPayment['rent_payment_id'],
            'total_annual_rent' => (float) $rentPayment['total_annual_rent'],
            'initial_payment' => (float) $rentPayment['initial_payment'],
            'remaining_balance' => (float) $rentPayment['remaining_balance'],
            'payment_date' => $rentPayment['payment_date'],
            'status' => $rentPayment['status'],
            'reference_number' => $rentPayment['reference_number'],
            'receipt_number' => $rentPayment['receipt_number']
        ] : null,
        // Current running period
        'current_period' => $current_period,
        'upcoming_period' => $upcoming_period,
        // Payment tracker summary
        'total_periods' => count($trackerRecords),
        'paid_periods_count' => count($paid_periods),
        'pending_periods_count' => count($pending_trackers),
        'paid_periods' => $paid_periods,
        'pending_periods' => $pending_trackers, // Only available and failed statuses
        // Pending verification info
        'has_pending_payment' => $has_pending_verification,
        'pending_payment_period' => $pending_verification_period,
        // Last completed payment
        'last_payment' => $last_completed_payment,
        'last_paid_end_date' => $last_paid_end_date,
        'last_payment_date' => $last_payment_date,
        'last_payment_period' => $last_payment_period,
        // Next payment info
        'next_payment' => $next_payment,
        'has_upcoming_payment' => $has_upcoming_payment,
        // Overdue payments
        'has_overdue_payments' => $has_overdue_payments,
        'overdue_amount' => $overdue_amount,
        // Lease status
        'is_lease_fully_paid' => $is_lease_fully_paid,
        'can_make_payment' => !$is_lease_fully_paid && !$has_pending_verification && $has_upcoming_payment,
         // ==================== NEW: RENEWAL ELIGIBILITY ====================
        'can_renew' => $can_renew,
        'renewal_message' => $renewal_message,
        'new_cycle_rent_amount' => $new_cycle_rent_amount,
        'new_cycle_security_deposit' => $new_cycle_security_deposit,
    ];

    logActivity("=== FETCH DASHBOARD DATA COMPLETED ===");
    logActivity("Total Paid: {$total_paid}, Balance: {$rent_balance}, Fully Paid: " . ($is_lease_fully_paid ? 'Yes' : 'No'));
    logActivity("Has Pending Verification: " . ($has_pending_verification ? 'Yes' : 'No'));
    logActivity("Pending Periods Count: " . count($pending_trackers));

    json_success($dashboardData, "Dashboard data retrieved successfully");
} catch (Exception $e) {
    logActivity("Error in fetch_dashboard_data: " . $e->getMessage());
    logActivity("Stack trace: " . $e->getTraceAsString());
    json_error("Failed to fetch dashboard data: " . $e->getMessage(), 500, null, 'SERVER_ERROR');
}
?>