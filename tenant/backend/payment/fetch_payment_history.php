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

    // Get tenant's apartment code for filtering
    $apartment_query = "SELECT apartment_code FROM tenants WHERE tenant_code = ? AND status = 1 LIMIT 1";
    $apt_stmt = $conn->prepare($apartment_query);
    $apt_stmt->bind_param("s", $tenant_code);
    $apt_stmt->execute();
    $apt_result = $apt_stmt->get_result();
    $tenant_apt = $apt_result->fetch_assoc();
    $apt_stmt->close();
    $apartment_code = $tenant_apt['apartment_code'] ?? null;

    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $status = isset($_GET['status']) ? htmlspecialchars(trim($_GET['status'])) : null;
    $payment_type = isset($_GET['payment_type']) ? htmlspecialchars(trim($_GET['payment_type'])) : null;
    $start_date = isset($_GET['start_date']) ? htmlspecialchars(trim($_GET['start_date'])) : null;
    $end_date = isset($_GET['end_date']) ? htmlspecialchars(trim($_GET['end_date'])) : null;

    // Build WHERE clause
    $where_clauses = ["tenant_code = ?"];
    $params = [$tenant_code];
    $types = "s";

    if ($status && in_array($status, ['pending', 'completed', 'failed', 'refunded', 'overdue'])) {
        $where_clauses[] = "status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($payment_type && in_array($payment_type, ['rent', 'security_deposit', 'fee'])) {
        $where_clauses[] = "payment_type = ?";
        $params[] = $payment_type;
        $types .= "s";
    }

    if ($start_date) {
        $where_clauses[] = "payment_date >= ?";
        $params[] = $start_date;
        $types .= "s";
    }

    if ($end_date) {
        $where_clauses[] = "payment_date <= ?";
        $params[] = $end_date;
        $types .= "s";
    }

    $where_sql = "WHERE " . implode(" AND ", $where_clauses);

    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM rent_payments $where_sql";
    $count_stmt = $conn->prepare($count_query);
    
    if (!$count_stmt) {
        throw new Exception("Prepare failed for count: " . $conn->error);
    }
    
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    // Get payment history with all columns including period dates and due dates
    // Removed the ucfirst() function from SQL - will handle in PHP
    $query = "
        SELECT 
            payment_id,
            amount,
            payment_date,
            payment_method,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            reference_number,
            status,
            receipt_number,
            payment_type,
            notes,
            created_at,
            updated_at
        FROM rent_payments
        $where_sql
        ORDER BY payment_date DESC, created_at DESC, payment_id DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $query_types = $types . "ii";
    
    $stmt->bind_param($query_types, ...$query_params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        // Determine if payment is overdue
        $is_overdue = false;
        if ($row['status'] === 'pending' && $row['due_date'] && new DateTime($row['due_date']) < new DateTime()) {
            $is_overdue = true;
        }
        
        // Format payment type display in PHP
        $payment_type_display = ucfirst(str_replace('_', ' ', $row['payment_type']));
        
        // Determine status color
        $status_color = 'info';
        if ($row['status'] === 'completed') {
            $status_color = 'success';
        } elseif ($row['status'] === 'pending') {
            $status_color = 'warning';
        } elseif ($row['status'] === 'overdue' || $row['status'] === 'failed') {
            $status_color = 'danger';
        }
        
        $payments[] = [
            'payment_id' => (int)$row['payment_id'],
            'amount' => (float)$row['amount'],
            'amount_formatted' => '₦' . number_format($row['amount'], 2),
            'payment_date' => $row['payment_date'],
            'payment_date_formatted' => date('F j, Y', strtotime($row['payment_date'])),
            'payment_method' => $row['payment_method'],
            'payment_method_display' => ucfirst(str_replace('_', ' ', $row['payment_method'])),
            'payment_period' => $row['payment_period'],
            'period_start_date' => $row['period_start_date'],
            'period_start_formatted' => $row['period_start_date'] ? date('F j, Y', strtotime($row['period_start_date'])) : null,
            'period_end_date' => $row['period_end_date'],
            'period_end_formatted' => $row['period_end_date'] ? date('F j, Y', strtotime($row['period_end_date'])) : null,
            'due_date' => $row['due_date'],
            'due_date_formatted' => $row['due_date'] ? date('F j, Y', strtotime($row['due_date'])) : null,
            'is_overdue' => $is_overdue,
            'reference_number' => $row['reference_number'],
            'status' => $row['status'],
            'status_color' => $status_color,
            'status_display' => ucfirst($row['status']),
            'receipt_number' => $row['receipt_number'],
            'payment_type' => $row['payment_type'],
            'payment_type_display' => $payment_type_display,
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('F j, Y g:i A', strtotime($row['created_at'])),
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();

    // Calculate summary statistics with more details
    $summary_query = "
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_paid,
            SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending,
            SUM(CASE WHEN status = 'overdue' OR (status = 'pending' AND due_date < CURDATE()) THEN amount ELSE 0 END) as total_overdue,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_payments,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_payments,
            COUNT(CASE WHEN status = 'overdue' OR (status = 'pending' AND due_date < CURDATE()) THEN 1 END) as overdue_payments,
            MAX(CASE WHEN status = 'completed' THEN payment_date END) as last_payment_date,
            SUM(CASE WHEN YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed' THEN amount ELSE 0 END) as paid_this_year,
            SUM(CASE WHEN payment_type = 'rent' AND status = 'completed' THEN amount ELSE 0 END) as total_rent_paid,
            SUM(CASE WHEN payment_type = 'security_deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposit_paid
        FROM rent_payments
        $where_sql
    ";
    $summary_stmt = $conn->prepare($summary_query);
    
    if (!$summary_stmt) {
        throw new Exception("Prepare failed for summary: " . $conn->error);
    }
    
    if (!empty($params)) {
        $summary_stmt->bind_param($types, ...$params);
    }
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result->fetch_assoc();
    $summary_stmt->close();

    // Get payment trends by month (last 12 months)
    $trend_query = "
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            DATE_FORMAT(payment_date, '%b %Y') as month_name,
            COUNT(*) as payment_count,
            SUM(amount) as total_amount,
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed_amount
        FROM rent_payments
        WHERE tenant_code = ? 
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b %Y')
        ORDER BY month DESC
    ";
    $trend_stmt = $conn->prepare($trend_query);
    $trend_stmt->bind_param("s", $tenant_code);
    $trend_stmt->execute();
    $trend_result = $trend_stmt->get_result();
    $payment_trends = [];
    while ($row = $trend_result->fetch_assoc()) {
        $payment_trends[] = $row;
    }
    $trend_stmt->close();

    // Get upcoming payments (pending/overdue)
    $upcoming_query = "
        SELECT 
            payment_id,
            amount,
            payment_period,
            period_start_date,
            period_end_date,
            due_date,
            status,
            payment_type
        FROM rent_payments
        WHERE tenant_code = ? 
        AND status IN ('pending', 'overdue')
        AND payment_type = 'rent'
        ORDER BY due_date ASC
    ";
    $upcoming_stmt = $conn->prepare($upcoming_query);
    $upcoming_stmt->bind_param("s", $tenant_code);
    $upcoming_stmt->execute();
    $upcoming_result = $upcoming_stmt->get_result();
    $upcoming_payments = [];
    while ($row = $upcoming_result->fetch_assoc()) {
        $upcoming_payments[] = [
            'payment_id' => (int)$row['payment_id'],
            'amount' => (float)$row['amount'],
            'amount_formatted' => '₦' . number_format($row['amount'], 2),
            'payment_period' => $row['payment_period'],
            'period_start_date' => $row['period_start_date'],
            'period_end_date' => $row['period_end_date'],
            'due_date' => $row['due_date'],
            'due_date_formatted' => $row['due_date'] ? date('F j, Y', strtotime($row['due_date'])) : null,
            'status' => $row['status'],
            'payment_type' => $row['payment_type']
        ];
    }
    $upcoming_stmt->close();

    // Get tenant's lease information
    $lease_query = "
        SELECT 
            lease_start_date,
            lease_end_date,
            payment_frequency
        FROM tenants 
        WHERE tenant_code = ? AND status = 1
        LIMIT 1
    ";
    $lease_stmt = $conn->prepare($lease_query);
    $lease_stmt->bind_param("s", $tenant_code);
    $lease_stmt->execute();
    $lease_result = $lease_stmt->get_result();
    $lease_info = $lease_result->fetch_assoc();
    $lease_stmt->close();

    $conn->close();

    $response_data = [
        'payments' => $payments,
        'summary' => [
            'total_payments' => (int)($summary['total_payments'] ?? 0),
            'total_paid' => (float)($summary['total_paid'] ?? 0),
            'total_pending' => (float)($summary['total_pending'] ?? 0),
            'total_overdue' => (float)($summary['total_overdue'] ?? 0),
            'successful_payments' => (int)($summary['successful_payments'] ?? 0),
            'pending_payments' => (int)($summary['pending_payments'] ?? 0),
            'failed_payments' => (int)($summary['failed_payments'] ?? 0),
            'overdue_payments' => (int)($summary['overdue_payments'] ?? 0),
            'last_payment_date' => $summary['last_payment_date'],
            'last_payment_formatted' => $summary['last_payment_date'] ? date('F j, Y', strtotime($summary['last_payment_date'])) : null,
            'paid_this_year' => (float)($summary['paid_this_year'] ?? 0),
            'total_rent_paid' => (float)($summary['total_rent_paid'] ?? 0),
            'total_deposit_paid' => (float)($summary['total_deposit_paid'] ?? 0)
        ],
        'upcoming_payments' => $upcoming_payments,
        'has_upcoming_payments' => !empty($upcoming_payments),
        'payment_trends' => $payment_trends,
        'lease_info' => [
            'start_date' => $lease_info['lease_start_date'],
            'end_date' => $lease_info['lease_end_date'],
            'payment_frequency' => $lease_info['payment_frequency']
        ],
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
    json_error("Failed to fetch payment history: " . $e->getMessage(), 500);
}
?>