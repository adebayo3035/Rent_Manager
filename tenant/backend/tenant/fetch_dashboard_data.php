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

    // Get payments this year
    $paymentsQuery = "
        SELECT 
            COUNT(*) as payments_count,
            COALESCE(SUM(amount), 0) as total_paid
        FROM rent_payments
        WHERE tenant_code = ? AND status = 'completed' 
        AND YEAR(payment_date) = YEAR(CURDATE())
    ";
    $stmt = $conn->prepare($paymentsQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $paymentsResult = $stmt->get_result();
    $paymentsData = $paymentsResult->fetch_assoc();
    $stmt->close();

    // Get tenant and apartment details
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
            p.name as property_name,
            p.property_code,
            CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
            ag.phone as agent_phone,
            ag.email as agent_email
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        LEFT JOIN agents ag ON p.agent_code = ag.agent_code
        WHERE t.tenant_code = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($tenantQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $tenantResult = $stmt->get_result();
    $tenantData = $tenantResult->fetch_assoc();
    $stmt->close();

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

    // Get last payment date to calculate next payment
    $lastPaymentQuery = "
        SELECT payment_date, amount
        FROM rent_payments
        WHERE tenant_code = ? AND status = 'completed'
        ORDER BY payment_date DESC LIMIT 1
    ";
    $stmt = $conn->prepare($lastPaymentQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $lastPaymentResult = $stmt->get_result();
    $lastPayment = $lastPaymentResult->fetch_assoc();
    $stmt->close();

    // Calculate next payment date
    $next_payment_date = date('Y-m-d');
    $next_payment_amount = (float)($tenantData['rent_amount'] ?? 0);
    
    if ($lastPayment) {
        $payment_frequency = $tenantData['payment_frequency'] ?? 'Monthly';
        $interval = match($payment_frequency) {
            'Monthly' => '+1 month',
            'Quarterly' => '+3 months',
            'Semi-Annually' => '+6 months',
            'Annually' => '+1 year',
            default => '+1 month'
        };
        $next_payment_date = date('Y-m-d', strtotime($lastPayment['payment_date'] . ' ' . $interval));
    }

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
    $recent_requests = $recentRequestsResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $conn->close();

    // Prepare dashboard data
    $dashboardData = [
        'property_name' => $tenantData['property_name'] ?? 'N/A',
        'apartment_number' => $tenantData['apartment_number'] ?? 'Not Assigned',
        'active_requests' => (int)($maintenanceData['active_requests'] ?? 0),
        'days_remaining' => $days_remaining,
        'payments_count' => (int)($paymentsData['payments_count'] ?? 0),
        'total_paid' => (float)($paymentsData['total_paid'] ?? 0),
        'rent_amount' => (float)($tenantData['rent_amount'] ?? 0),
        'next_payment_amount' => $next_payment_amount,
        'next_payment_date' => $next_payment_date,
        'agent_name' => $tenantData['agent_name'] ?? 'N/A',
        'agent_phone' => $tenantData['agent_phone'] ?? null,
        'agent_email' => $tenantData['agent_email'] ?? null,
        'recent_requests' => $recent_requests
    ];

    // Return success with dashboard data
    json_success($dashboardData, "Dashboard data retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_dashboard_data: " . $e->getMessage());
    json_error("Failed to fetch dashboard data", 500, null, 'SERVER_ERROR');
}