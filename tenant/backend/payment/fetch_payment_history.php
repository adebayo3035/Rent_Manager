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

    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $status = isset($_GET['status']) ? htmlspecialchars(trim($_GET['status'])) : null;
    $start_date = isset($_GET['start_date']) ? htmlspecialchars(trim($_GET['start_date'])) : null;
    $end_date = isset($_GET['end_date']) ? htmlspecialchars(trim($_GET['end_date'])) : null;

    // Build WHERE clause
    $where_clauses = ["tenant_code = ?"];
    $params = [$tenant_code];
    $types = "s";

    if ($status && in_array($status, ['pending', 'completed', 'failed', 'refunded'])) {
        $where_clauses[] = "status = ?";
        $params[] = $status;
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

    // Get payment history with pagination
    $query = "
        SELECT 
            payment_id,
            amount,
            payment_date,
            payment_method,
            payment_period,
            reference_number,
            status,
            receipt_number,
            notes,
            created_at,
            updated_at,
            CASE 
                WHEN status = 'completed' THEN 'success'
                WHEN status = 'pending' THEN 'warning'
                WHEN status = 'failed' THEN 'danger'
                ELSE 'info'
            END as status_color
        FROM rent_payments
        $where_sql
        ORDER BY payment_date DESC, created_at DESC
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
        $payments[] = [
            'payment_id' => (int)$row['payment_id'],
            'amount' => (float)$row['amount'],
            'payment_date' => $row['payment_date'],
            'payment_method' => $row['payment_method'],
            'payment_period' => $row['payment_period'],
            'payment_method_display' => ucfirst(str_replace('_', ' ', $row['payment_method'])),
            'reference_number' => $row['reference_number'],
            'status' => $row['status'],
            'receipt_number' => $row['receipt_number'],
            'status_color' => $row['status_color'],
            'status_display' => ucfirst($row['status']),
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();

    // Calculate summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_paid,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_payments,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_payments,
            MAX(CASE WHEN status = 'completed' THEN payment_date END) as last_payment_date,
            SUM(CASE WHEN YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed' THEN amount ELSE 0 END) as paid_this_year
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

    // Get last payment date to calculate next payment
    $last_payment_query = "
        SELECT payment_date, amount 
        FROM rent_payments 
        WHERE tenant_code = ? AND status = 'completed'
        ORDER BY payment_date DESC LIMIT 1
    ";
    $last_stmt = $conn->prepare($last_payment_query);
    $last_stmt->bind_param("s", $tenant_code);
    $last_stmt->execute();
    $last_result = $last_stmt->get_result();
    $last_payment = $last_result->fetch_assoc();
    $last_stmt->close();

    // Get tenant's payment frequency and apartment code, then fetch rent amount from apartments table
    $tenant_query = "
        SELECT t.payment_frequency, t.apartment_code, a.rent_amount 
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        WHERE t.tenant_code = ? AND t.status = 1
        LIMIT 1
    ";
    $tenant_stmt = $conn->prepare($tenant_query);
    $tenant_stmt->bind_param("s", $tenant_code);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    $tenant_data = $tenant_result->fetch_assoc();
    $tenant_stmt->close();

    $next_payment_amount = (float)($tenant_data['rent_amount'] ?? 0);
    $next_payment_date = date('Y-m-d');
    
    // If there's a last payment, calculate next payment date based on payment frequency
    if ($last_payment && isset($tenant_data['payment_frequency'])) {
        $payment_frequency = $tenant_data['payment_frequency'];
        $interval = match($payment_frequency) {
            'Monthly' => '+1 month',
            'Quarterly' => '+3 months',
            'Semi-Annually' => '+6 months',
            'Annually' => '+1 year',
            default => '+1 month'
        };
        $next_payment_date = date('Y-m-d', strtotime($last_payment['payment_date'] . ' ' . $interval));
    }

    $conn->close();

    $response_data = [
        'payments' => $payments,
        'summary' => [
            'total_payments' => (int)($summary['total_payments'] ?? 0),
            'total_paid' => (float)($summary['total_paid'] ?? 0),
            'successful_payments' => (int)($summary['successful_payments'] ?? 0),
            'pending_payments' => (int)($summary['pending_payments'] ?? 0),
            'failed_payments' => (int)($summary['failed_payments'] ?? 0),
            'last_payment_date' => $summary['last_payment_date'],
            'paid_this_year' => (float)($summary['paid_this_year'] ?? 0)
        ],
        'upcoming_payment' => [
            'amount' => $next_payment_amount,
            'due_date' => $next_payment_date,
            'formatted_amount' => '₦' . number_format($next_payment_amount, 2),
            'formatted_date' => date('F j, Y', strtotime($next_payment_date))
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