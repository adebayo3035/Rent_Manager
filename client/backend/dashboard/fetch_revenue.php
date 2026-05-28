<?php
// client/backend/dashboard/fetch_revenue.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    $period = $_GET['period'] ?? 'monthly';
    $validPeriods = ['monthly', 'quarterly', 'yearly', 'all'];

    if (!in_array($period, $validPeriods, true)) {
        $period = 'monthly';
    }
    
    // Set date range based on period
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
    
    switch ($period) {
        case 'monthly':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
        case 'quarterly':
            $quarter = ceil(date('n') / 3);
            $start_date = date('Y-m-d', strtotime(date('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01'));
            $end_date = date('Y-m-d', strtotime(date('Y') . '-' . ($quarter * 3) . '-t'));
            break;
        case 'yearly':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
        case 'all':
            $start_date = '1970-01-01';
            $end_date = date('Y-m-d');
            break;
    }
    
    // Rent payments live in rent_payment_tracker. The payments table is used for non-rent payments.
    // Use tracker period dates for the selected period, because rent revenue is period-based.
    $amountDueExpression = "
        COALESCE(
            NULLIF(rpt.amount_paid, 0),
            NULLIF(rp.payment_amount_per_period, 0),
            NULLIF(t.payment_amount_per_period, 0),
            0
        )
    ";
    $dueDateExpression = "
        DATE_ADD(
            rpt.end_date,
            INTERVAL CASE COALESCE(t.agreed_payment_frequency, t.payment_frequency)
                WHEN 'Monthly' THEN 7
                WHEN 'Quarterly' THEN 14
                WHEN 'Semi-Annually' THEN 30
                WHEN 'Annually' THEN 90
                ELSE 7
            END DAY
        )
    ";

    $revenueQuery = "
        SELECT 
            COALESCE(SUM(CASE 
                WHEN rpt.status = 'paid' THEN rpt.amount_paid 
                ELSE 0 
            END), 0) as total_collected,
            COALESCE(SUM(CASE 
                WHEN rpt.status = 'pending_verification' THEN {$amountDueExpression}
                ELSE 0 
            END), 0) as total_pending,
            COALESCE(SUM(CASE 
                WHEN rpt.status IN ('available', 'pending_verification', 'failed') 
                    AND {$dueDateExpression} < CURDATE() 
                THEN {$amountDueExpression}
                ELSE 0 
            END), 0) as total_overdue,
            COALESCE(SUM({$amountDueExpression}), 0) as expected_revenue
        FROM rent_payment_tracker rpt
        JOIN apartments a ON rpt.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        LEFT JOIN rent_payments rp ON rpt.rent_payment_id = rp.rent_payment_id
        LEFT JOIN tenants t ON rpt.tenant_code = t.tenant_code
        WHERE pr.client_code = ? 
        AND pr.status = 1
        AND rpt.start_date <= ?
        AND rpt.end_date >= ?
    ";
    
    $stmt = $conn->prepare($revenueQuery);
    $stmt->bind_param("sss", $client_code, $end_date, $start_date);
    $stmt->execute();
    $revenue = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    json_success([
        'total_collected' => (float)$revenue['total_collected'],
        'total_pending' => (float)$revenue['total_pending'],
        'total_overdue' => (float)$revenue['total_overdue'],
        'expected_revenue' => (float)$revenue['expected_revenue']
    ], "Revenue data retrieved");
    
} catch (Exception $e) {
    logActivity("Error fetching revenue: " . $e->getMessage());
    json_error("Failed to fetch revenue", 500);
}
?>
