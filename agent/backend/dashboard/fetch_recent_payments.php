<?php
// client/backend/dashboard/fetch_recent_payments.php

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
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = max(1, min($limit, 50));

    $amountDueExpression = "
        COALESCE(
            NULLIF(rpt.amount_paid, 0),
            NULLIF(rp.payment_amount_per_period, 0),
            NULLIF(t.payment_amount_per_period, 0),
            0
        )
    ";
    
    $query = "
        SELECT 
            rpt.tracker_id as id,
            {$amountDueExpression} as amount,
            COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at) as payment_date,
            CASE
                WHEN rpt.status = 'paid' THEN 'completed'
                WHEN rpt.status = 'pending_verification' THEN 'pending'
                ELSE rpt.status
            END as status,
            rpt.status as tracker_status,
            COALESCE(rp.receipt_number, CONCAT('RCP-', rpt.payment_id)) as receipt_number,
            rpt.payment_reference as reference_number,
            rpt.period_number,
            rpt.start_date as period_start_date,
            rpt.end_date as period_end_date,
            rpt.payment_method,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            pr.name as property_name,
            a.apartment_number
        FROM rent_payment_tracker rpt
        JOIN tenants t ON rpt.tenant_code = t.tenant_code
        JOIN apartments a ON rpt.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        LEFT JOIN rent_payments rp ON rpt.rent_payment_id = rp.rent_payment_id
        WHERE pr.client_code = ?
        AND pr.status = 1
        AND rpt.status IN ('paid', 'pending_verification', 'failed')
        AND COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at) IS NOT NULL
        ORDER BY COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at) DESC, rpt.tracker_id DESC
        LIMIT ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $client_code, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
    
    json_success(['payments' => $payments], "Recent payments retrieved");
    
} catch (Exception $e) {
    logActivity("Error fetching recent payments: " . $e->getMessage());
    json_error("Failed to fetch recent payments", 500);
}
?>
