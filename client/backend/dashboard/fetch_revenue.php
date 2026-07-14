<?php
// client/backend/dashboard/fetch_revenue.php - FIXED

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
    
    // ==================== DATE RANGE ====================
    $today = date('Y-m-d');
    
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
    
    logActivity("Fetching revenue for client: {$client_code}, period: {$period}, start: {$start_date}, end: {$end_date}");
    
    // ==================== AMOUNT DUE CALCULATION ====================
    $amountDueExpression = "
        COALESCE(
            NULLIF(rpt.amount_paid, 0),
            NULLIF(rp.payment_amount_per_period, 0),
            NULLIF(t.payment_amount_per_period, 0),
            0
        )
    ";
    
    // ==================== CLIENT SHARE CALCULATION ====================
    $clientShareExpression = "
        COALESCE(
            s.client_share,
            (COALESCE(NULLIF(rpt.amount_paid, 0), NULLIF(rp.payment_amount_per_period, 0), NULLIF(t.payment_amount_per_period, 0), 0) * 
             COALESCE(s.client_percentage_used, 85) / 100),
            0
        )
    ";
    
    // ==================== DUE DATE CALCULATION ====================
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
    
    // ==================== DATE OVERLAP LOGIC (FIXED) ====================
    // For 'all' period, no date filter
    // For other periods, check if the tracker period overlaps with the selected period
    // OR if the tracker is already paid (show all paid trackers regardless of period)
    $dateFilter = "";
    if ($period !== 'all') {
        $dateFilter = "
            AND (
                -- Tracker period overlaps with selected date range
                (rpt.start_date <= ? AND rpt.end_date >= ?)
                -- OR tracker is already paid (always show paid trackers)
                OR rpt.status = 'paid'
            )
        ";
    }
    
    // ==================== MAIN REVENUE QUERY ====================
    $revenueQuery = "
        SELECT 
            -- Rent Revenue
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
            COALESCE(SUM({$amountDueExpression}), 0) as expected_revenue,
            
            -- Settlement Revenue (Client's Actual Share)
            COALESCE(SUM(CASE 
                WHEN s.settlement_status = 'completed' AND s.client_paid = 1 THEN s.client_share
                WHEN s.settlement_status = 'completed' AND s.client_paid = 0 THEN 0
                ELSE 0 
            END), 0) as total_settlement_paid,
            
            COALESCE(SUM(CASE 
                WHEN s.settlement_status = 'completed' AND s.client_paid = 0 THEN s.client_share
                WHEN s.settlement_status = 'pending' THEN s.client_share
                ELSE 0 
            END), 0) as total_settlement_pending,
            
            COALESCE(SUM(CASE 
                WHEN s.settlement_status = 'completed' THEN s.client_share
                ELSE 0 
            END), 0) as total_settlement_earned,
            
            -- Settlement Statistics
            COUNT(DISTINCT CASE WHEN s.settlement_status = 'completed' THEN s.id END) as completed_settlements,
            COUNT(DISTINCT CASE WHEN s.settlement_status = 'pending' THEN s.id END) as pending_settlements,
            
            -- Fee Deductions
            COALESCE(SUM(CASE 
                WHEN s.settlement_status = 'completed' THEN s.admin_share 
                ELSE 0 
            END), 0) as total_admin_fees_deducted,
            
            COALESCE(SUM(CASE 
                WHEN s.settlement_status = 'completed' THEN s.agent_share 
                ELSE 0 
            END), 0) as total_agent_commissions_deducted,
            
            -- Rent vs Settlement Comparison
            COALESCE(SUM(CASE 
                WHEN rpt.status = 'paid' AND s.settlement_status = 'completed' THEN rpt.amount_paid 
                WHEN rpt.status = 'paid' AND s.settlement_status IS NULL THEN rpt.amount_paid
                ELSE 0 
            END), 0) as total_rent_paid_without_settlement
            
        FROM rent_payment_tracker rpt
        LEFT JOIN apartments a ON rpt.apartment_code = a.apartment_code
        LEFT JOIN properties pr ON a.property_code = pr.property_code
        LEFT JOIN rent_payments rp ON rpt.rent_payment_id = rp.rent_payment_id
        LEFT JOIN tenants t ON rpt.tenant_code = t.tenant_code
        LEFT JOIN settlement_transactions s ON rpt.tracker_id = s.tracker_id
        WHERE pr.client_code = ? 
        AND pr.status = 1
        {$dateFilter}
    ";
    
    // ==================== BIND PARAMETERS ====================
    if ($period !== 'all') {
        $stmt = $conn->prepare($revenueQuery);
        // Parameters: client_code, end_date (for start_date <=), start_date (for end_date >=)
        $stmt->bind_param("sss", $client_code, $end_date, $start_date);
    } else {
        $stmt = $conn->prepare($revenueQuery);
        $stmt->bind_param("s", $client_code);
    }
    
    if (!$stmt) {
        logActivity("ERROR: Prepare failed: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $stmt->execute();
    $revenue = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // ==================== CALCULATE DERIVED METRICS ====================
    $total_rent_collected = (float)($revenue['total_collected'] ?? 0);
    $total_settlement_earned = (float)($revenue['total_settlement_earned'] ?? 0);
    $total_settlement_paid = (float)($revenue['total_settlement_paid'] ?? 0);
    $total_settlement_pending = (float)($revenue['total_settlement_pending'] ?? 0);
    $total_admin_fees = (float)($revenue['total_admin_fees_deducted'] ?? 0);
    $total_agent_commissions = (float)($revenue['total_agent_commissions_deducted'] ?? 0);
    $rent_without_settlement = (float)($revenue['total_rent_paid_without_settlement'] ?? 0);
    
    // Calculate total deductions
    $total_deductions = $total_admin_fees + $total_agent_commissions;
    
    // Calculate settlement gap (rent collected but not yet settled)
    $settlement_gap = $total_rent_collected - $total_settlement_earned;
    
    // Calculate settlement rate
    $settlement_rate = $total_rent_collected > 0 
        ? round(($total_settlement_earned / $total_rent_collected) * 100, 2) 
        : 0;
    
    // ==================== RESPONSE ====================
    $response = [
        // Rent Revenue
        'rent_revenue' => [
            'total_collected' => $total_rent_collected,
            'total_pending' => (float)($revenue['total_pending'] ?? 0),
            'total_overdue' => (float)($revenue['total_overdue'] ?? 0),
            'expected_revenue' => (float)($revenue['expected_revenue'] ?? 0),
        ],
        
        // Settlement Revenue (Client's Actual Share)
        'settlement_revenue' => [
            'total_earned' => $total_settlement_earned,
            'total_paid' => $total_settlement_paid,
            'total_pending' => $total_settlement_pending,
            'settlement_rate' => $settlement_rate,
            'completed_settlements' => (int)($revenue['completed_settlements'] ?? 0),
            'pending_settlements' => (int)($revenue['pending_settlements'] ?? 0),
        ],
        
        // Deductions
        'deductions' => [
            'admin_fees' => $total_admin_fees,
            'agent_commissions' => $total_agent_commissions,
            'total_deductions' => $total_deductions,
        ],
        
        // Summary & Insights
        'summary' => [
            'total_rent_collected' => $total_rent_collected,
            'total_settlement_earned' => $total_settlement_earned,
            'total_deductions' => $total_deductions,
            'settlement_gap' => $settlement_gap,
            'rent_without_settlement' => $rent_without_settlement,
            'net_received' => $total_settlement_paid,
            'pending_payout' => $total_settlement_pending,
        ],
        
        // Period info
        'period' => $period,
        'date_range' => [
            'start' => $start_date,
            'end' => $end_date
        ]
    ];
    
    logActivity("Revenue response for client {$client_code}: " . json_encode($response));
    
    json_success($response, "Revenue data retrieved");
    
} catch (Exception $e) {
    logActivity("Error fetching revenue: " . $e->getMessage());
    json_error("Failed to fetch revenue", 500);
}
?>