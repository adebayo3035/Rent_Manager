<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    // Get tenant's apartment code and lease information
    $tenant_query = "
        SELECT 
            apartment_code, 
            lease_start_date, 
            lease_end_date, 
            agreed_payment_frequency,
            agreed_rent_amount,
            payment_amount_per_period,
            rent_balance
        FROM tenants 
        WHERE tenant_code = ? AND status = 1 
        LIMIT 1
    ";
    $tenant_stmt = $conn->prepare($tenant_query);
    $tenant_stmt->bind_param("s", $tenant_code);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    $tenant_info = $tenant_result->fetch_assoc();
    $tenant_stmt->close();
    
    if (!$tenant_info) {
        json_error("Tenant information not found", 404);
    }
    
    $apartment_code = $tenant_info['apartment_code'];
    $lease_start_date = $tenant_info['lease_start_date'];
    $lease_end_date = $tenant_info['lease_end_date'];
    $payment_frequency = $tenant_info['agreed_payment_frequency'] ?? 'Monthly';
    $annual_rent = (float)($tenant_info['agreed_rent_amount'] ?? 0);
    $payment_per_period = (float)($tenant_info['payment_amount_per_period'] ?? 0);
    $remaining_balance = (float)($tenant_info['rent_balance'] ?? 0);

    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $status = isset($_GET['status']) ? htmlspecialchars(trim($_GET['status'])) : null;
    $start_date = isset($_GET['start_date']) ? htmlspecialchars(trim($_GET['start_date'])) : null;
    $end_date = isset($_GET['end_date']) ? htmlspecialchars(trim($_GET['end_date'])) : null;

    // ==================== GET MAIN RENT PAYMENT RECORD ====================
    $rent_payment_query = "
        SELECT 
            rent_payment_id,
            amount as total_annual_rent,
            amount_paid as initial_payment,
            balance as remaining_balance,
            payment_date as initial_payment_date,
            payment_method,
            payment_period,
            period_start_date as lease_period_start,
            period_end_date as lease_period_end,
            due_date,
            reference_number,
            status as rent_payment_status,
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
    $rent_stmt = $conn->prepare($rent_payment_query);
    $rent_stmt->bind_param("ss", $tenant_code, $apartment_code);
    $rent_stmt->execute();
    $rent_payment_result = $rent_stmt->get_result();
    $rent_payment = $rent_payment_result->fetch_assoc();
    $rent_stmt->close();

    // ==================== GET PAYMENT TRACKER RECORDS ====================
    // Build WHERE clause for tracker records
    $tracker_where_clauses = ["tenant_code = ?", "apartment_code = ?"];
    $tracker_params = [$tenant_code, $apartment_code];
    $tracker_types = "ss";

    if ($status && in_array($status, ['paid', 'pending', 'overdue'])) {
        $tracker_where_clauses[] = "status = ?";
        $tracker_params[] = $status;
        $tracker_types .= "s";
    }

    if ($start_date) {
        $tracker_where_clauses[] = "payment_date >= ?";
        $tracker_params[] = $start_date;
        $tracker_types .= "s";
    }

    if ($end_date) {
        $tracker_where_clauses[] = "payment_date <= ?";
        $tracker_params[] = $end_date;
        $tracker_types .= "s";
    }

    $tracker_where_sql = "WHERE " . implode(" AND ", $tracker_where_clauses);

    // Get total count of tracker records
    $count_query = "SELECT COUNT(*) as total FROM rent_payment_tracker $tracker_where_sql";
    $count_stmt = $conn->prepare($count_query);
    
    if (!$count_stmt) {
        throw new Exception("Prepare failed for count: " . $conn->error);
    }
    
    if (!empty($tracker_params)) {
        $count_stmt->bind_param($tracker_types, ...$tracker_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    // Get payment tracker records (these represent individual period payments)
    $tracker_query = "
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
        $tracker_where_sql
        ORDER BY period_number DESC, payment_date DESC
        LIMIT ? OFFSET ?
    ";

    $tracker_stmt = $conn->prepare($tracker_query);
    $tracker_query_params = $tracker_params;
    $tracker_query_params[] = $limit;
    $tracker_query_params[] = $offset;
    $tracker_query_types = $tracker_types . "ii";
    
    $tracker_stmt->bind_param($tracker_query_types, ...$tracker_query_params);
    $tracker_stmt->execute();
    $tracker_result = $tracker_stmt->get_result();
    
    $payments = [];
    while ($row = $tracker_result->fetch_assoc()) {
        $start_date_obj = new DateTime($row['start_date']);
        $end_date_obj = new DateTime($row['end_date']);
        
        // Calculate due date based on period end date and payment frequency
        $dueDateConfig = [
            'Monthly' => 7,
            'Quarterly' => 14,
            'Semi-Annually' => 30,
            'Annually' => 90
        ];
        $daysToAdd = $dueDateConfig[$payment_frequency] ?? 7;
        $due_date = clone $end_date_obj;
        $due_date->modify("+{$daysToAdd} days");
        
        // Determine if payment is overdue
        $is_overdue = false;
        $current_date = new DateTime();
        if ($row['status'] === 'pending' && $due_date < $current_date) {
            $is_overdue = true;
        }
        
        // Format period display
        $period_display = formatPeriodDisplay($start_date_obj, $end_date_obj, $payment_frequency);
        
        // Determine status color
        $status_color = 'info';
        if ($row['status'] === 'paid') {
            $status_color = 'success';
        } elseif ($row['status'] === 'pending') {
            $status_color = 'warning';
        } elseif ($is_overdue) {
            $status_color = 'danger';
        }
        
        $payments[] = [
            'payment_id' => (int)$row['tracker_id'],
            'rent_payment_id' => $row['rent_payment_id'],
            'period_number' => (int)$row['period_number'],
            'amount' => (float)$row['amount_paid'],
            'amount_formatted' => '₦' . number_format($row['amount_paid'], 2),
            'payment_date' => $row['payment_date'],
            'payment_date_formatted' => $row['payment_date'] ? date('F j, Y', strtotime($row['payment_date'])) : null,
            'payment_method' => 'bank_transfer', // Default, can be updated when payment is made
            'payment_method_display' => 'Bank Transfer',
            'payment_period' => $period_display['period_name'],
            'period_start_date' => $row['start_date'],
            'period_start_formatted' => date('F j, Y', strtotime($row['start_date'])),
            'period_end_date' => $row['end_date'],
            'period_end_formatted' => date('F j, Y', strtotime($row['end_date'])),
            'period_display' => $period_display['full_display'],
            'due_date' => $due_date->format('Y-m-d'),
            'due_date_formatted' => $due_date->format('F j, Y'),
            'is_overdue' => $is_overdue,
            'reference_number' => generateReferenceFromTracker($tenant_code, $row['period_number']),
            'status' => $row['status'],
            'status_color' => $status_color,
            'status_display' => ucfirst($row['status']),
            'receipt_number' => $row['payment_id'] ? 'RCP-' . $row['payment_id'] : null,
            'payment_type' => 'rent',
            'payment_type_display' => 'Rent',
            'remaining_balance' => (float)$row['remaining_balance'],
            'remaining_balance_formatted' => '₦' . number_format($row['remaining_balance'], 2),
            'notes' => $row['status'] === 'paid' ? "Payment for period #{$row['period_number']} completed" : "Pending payment for period #{$row['period_number']}",
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('F j, Y g:i A', strtotime($row['created_at']))
        ];
    }
    $tracker_stmt->close();

    // ==================== GET SECURITY DEPOSIT PAYMENTS ====================
    $deposit_query = "
        SELECT 
            id,
            amount,
            payment_date,
            due_date,
            payment_method,
            payment_status,
            receipt_number,
            reference_number,
            description,
            created_at
        FROM payments
        WHERE tenant_code = ? 
        AND apartment_code = ?
        AND payment_category = 'security_deposit'
        ORDER BY created_at DESC
    ";
    $deposit_stmt = $conn->prepare($deposit_query);
    $deposit_stmt->bind_param("ss", $tenant_code, $apartment_code);
    $deposit_stmt->execute();
    $deposit_result = $deposit_stmt->get_result();
    
    $security_deposits = [];
    while ($row = $deposit_result->fetch_assoc()) {
        $security_deposits[] = [
            'payment_id' => (int)$row['id'],
            'amount' => (float)$row['amount'],
            'amount_formatted' => '₦' . number_format($row['amount'], 2),
            'payment_date' => $row['payment_date'],
            'payment_date_formatted' => date('F j, Y', strtotime($row['payment_date'])),
            'due_date' => $row['due_date'],
            'due_date_formatted' => $row['due_date'] ? date('F j, Y', strtotime($row['due_date'])) : null,
            'payment_method' => $row['payment_method'],
            'payment_method_display' => ucfirst(str_replace('_', ' ', $row['payment_method'])),
            'status' => $row['payment_status'],
            'status_color' => $row['payment_status'] === 'completed' ? 'success' : 'warning',
            'status_display' => ucfirst($row['payment_status']),
            'receipt_number' => $row['receipt_number'],
            'reference_number' => $row['reference_number'],
            'description' => $row['description'],
            'payment_type' => 'security_deposit',
            'payment_type_display' => 'Security Deposit',
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('F j, Y g:i A', strtotime($row['created_at']))
        ];
    }
    $deposit_stmt->close();

    // Combine rent payments and security deposits for the payments list
    $all_payments = array_merge($payments, $security_deposits);
    
    // Sort by date (most recent first)
    usort($all_payments, function($a, $b) {
        $date_a = $a['payment_date'] ?? $a['created_at'] ?? '0000-00-00';
        $date_b = $b['payment_date'] ?? $b['created_at'] ?? '0000-00-00';
        return strtotime($date_b) - strtotime($date_a);
    });

    // ==================== CALCULATE SUMMARY STATISTICS ====================
    $total_paid = array_sum(array_column($payments, 'amount'));
    $total_pending = 0;
    $total_overdue = 0;
    $pending_count = 0;
    $overdue_count = 0;
    $paid_count = 0;
    
    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid') {
            $paid_count++;
        } elseif ($payment['status'] === 'pending') {
            $pending_count++;
            if ($payment['is_overdue']) {
                $total_overdue += $payment['amount'];
                $overdue_count++;
            } else {
                $total_pending += $payment['amount'];
            }
        }
    }
    
    // Get last payment date
    $last_payment = null;
    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid' && $payment['payment_date']) {
            if (!$last_payment || $payment['payment_date'] > $last_payment) {
                $last_payment = $payment['payment_date'];
            }
        }
    }

    // ==================== GET UPCOMING PAYMENTS ====================
    $upcoming_payments = [];
    $current_date = new DateTime();
    
    foreach ($payments as $payment) {
        if ($payment['status'] === 'pending') {
            $due_date = new DateTime($payment['due_date']);
            $upcoming_payments[] = [
                'period_number' => $payment['period_number'],
                'amount' => $payment['amount'],
                'amount_formatted' => $payment['amount_formatted'],
                'payment_period' => $payment['payment_period'],
                'period_display' => $payment['period_display'],
                'period_start_date' => $payment['period_start_date'],
                'period_end_date' => $payment['period_end_date'],
                'due_date' => $payment['due_date'],
                'due_date_formatted' => $payment['due_date_formatted'],
                'is_overdue' => $payment['is_overdue'],
                'status' => $payment['status']
            ];
        }
    }
    
    // Sort upcoming payments by due date
    usort($upcoming_payments, function($a, $b) {
        return strtotime($a['due_date']) - strtotime($b['due_date']);
    });

    // ==================== CALCULATE PAYMENT TRENDS ====================
    $payment_trends = [];
    $trend_data = [];
    
    // Group payments by month for the last 12 months
    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid' && $payment['payment_date']) {
            $month_key = date('Y-m', strtotime($payment['payment_date']));
            $month_name = date('M Y', strtotime($payment['payment_date']));
            
            if (!isset($trend_data[$month_key])) {
                $trend_data[$month_key] = [
                    'month' => $month_key,
                    'month_name' => $month_name,
                    'payment_count' => 0,
                    'total_amount' => 0
                ];
            }
            $trend_data[$month_key]['payment_count']++;
            $trend_data[$month_key]['total_amount'] += $payment['amount'];
        }
    }
    
    // Sort by month descending and get last 12 months
    krsort($trend_data);
    $payment_trends = array_values(array_slice($trend_data, 0, 12));

    // ==================== GET SECURITY DEPOSIT SUMMARY ====================
    $total_deposit_paid = array_sum(array_column($security_deposits, 'amount'));
    $deposit_status = !empty($security_deposits) ? 'completed' : 'pending';

    $conn->close();

    // Prepare response
    $response_data = [
        'payments' => $all_payments,
        'rent_payments' => $payments, // Keep for backward compatibility
        'security_deposits' => $security_deposits,
        'summary' => [
            'total_payments' => count($payments),
            'total_paid' => $total_paid,
            'total_pending' => $total_pending,
            'total_overdue' => $total_overdue,
            'successful_payments' => $paid_count,
            'pending_payments' => $pending_count,
            'failed_payments' => 0,
            'overdue_payments' => $overdue_count,
            'last_payment_date' => $last_payment,
            'last_payment_formatted' => $last_payment ? date('F j, Y', strtotime($last_payment)) : null,
            'paid_this_year' => calculateYearlyTotal($payments),
            'total_rent_paid' => $total_paid,
            'total_deposit_paid' => $total_deposit_paid,
            'deposit_status' => $deposit_status,
            'annual_rent' => $annual_rent,
            'payment_per_period' => $payment_per_period,
            'remaining_balance' => $remaining_balance
        ],
        'upcoming_payments' => $upcoming_payments,
        'has_upcoming_payments' => !empty($upcoming_payments),
        'payment_trends' => $payment_trends,
        'lease_info' => [
            'start_date' => $lease_start_date,
            'end_date' => $lease_end_date,
            'payment_frequency' => $payment_frequency,
            'annual_rent' => $annual_rent,
            'payment_per_period' => $payment_per_period,
            'remaining_balance' => $remaining_balance
        ],
        'rent_agreement' => $rent_payment ? [
            'rent_payment_id' => $rent_payment['rent_payment_id'],
            'total_annual_rent' => (float)$rent_payment['total_annual_rent'],
            'initial_payment' => (float)$rent_payment['initial_payment'],
            'remaining_balance' => (float)$rent_payment['remaining_balance'],
            'initial_payment_date' => $rent_payment['initial_payment_date'],
            'reference_number' => $rent_payment['reference_number'],
            'receipt_number' => $rent_payment['receipt_number'],
            'status' => $rent_payment['rent_payment_status']
        ] : null,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $total,
            'total_pages' => ceil($total / $limit),
            'has_next_page' => $page < ceil($total / $limit),
            'has_previous_page' => $page > 1
        ]
    ];

    json_success($response_data, "Payment history retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_payment_history: " . $e->getMessage());
    logActivity("Stack trace: " . $e->getTraceAsString());
    json_error("Failed to fetch payment history: " . $e->getMessage(), 500);
}

/**
 * Format period display based on start and end dates
 */
function formatPeriodDisplay($start_date, $end_date, $frequency) {
    $start_formatted = $start_date->format('F j, Y');
    $end_formatted = $end_date->format('F j, Y');
    
    switch($frequency) {
        case 'Monthly':
            $period_name = $start_date->format('F Y');
            break;
        case 'Quarterly':
            $quarter = ceil($start_date->format('n') / 3);
            $period_name = "Q{$quarter} {$start_date->format('Y')}";
            break;
        case 'Semi-Annually':
            $half = $start_date->format('n') <= 6 ? 'H1' : 'H2';
            $period_name = "{$half} {$start_date->format('Y')}";
            break;
        case 'Annually':
            $period_name = $start_date->format('Y');
            break;
        default:
            $period_name = $start_date->format('F Y');
    }
    
    return [
        'period_name' => $period_name,
        'full_display' => "{$start_formatted} - {$end_formatted}"
    ];
}

/**
 * Generate a reference number for tracker payments
 */
function generateReferenceFromTracker($tenant_code, $period_number) {
    $prefix = 'REF';
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    return $prefix . '-' . $date . '-' . $tenant_short . '-PER' . $period_number;
}

/**
 * Calculate total paid in current year
 */
function calculateYearlyTotal($payments) {
    $current_year = date('Y');
    $total = 0;
    
    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid' && $payment['payment_date']) {
            $payment_year = date('Y', strtotime($payment['payment_date']));
            if ($payment_year == $current_year) {
                $total += $payment['amount'];
            }
        }
    }
    
    return $total;
}
?>