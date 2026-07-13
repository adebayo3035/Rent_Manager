<?php
// tenant/backend/payment/fetch_tenant_rent_payment_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 401);
    }
    
    $tenant_code = $_SESSION['tenant_code'];
    $history_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($history_id <= 0) {
        json_error("Invalid history ID", 400);
    }
    
    $query = "
       SELECT 
    rph.*,
    CASE 
        WHEN rph.initiated_by_type = 'tenant' THEN 'You'
        ELSE 'Admin'
    END as initiated_by_display,
    CASE 
        WHEN rph.status = 'approved' THEN 'success'
        WHEN rph.status IN ('rejected', 'failed') THEN 'danger'
        WHEN rph.status = 'pending_verification' THEN 'warning'
        WHEN rph.status = 'initiated' THEN 'info'
        ELSE 'secondary'
    END as status_class,
    (SELECT name as property_name FROM properties p 
     JOIN apartments a ON p.property_code = a.property_code 
     WHERE a.apartment_code = rph.apartment_code LIMIT 1) as property_name,
    (SELECT apartment_number FROM apartments WHERE apartment_code = rph.apartment_code) as apartment_number,
    -- Period dates from rent_payment_tracker
    rpt.start_date AS period_start_date,
    rpt.end_date AS period_end_date,
    rpt.period_number
FROM rent_payment_history rph
LEFT JOIN rent_payment_tracker rpt ON rph.tracker_id = rpt.tracker_id
WHERE rph.id = ? AND rph.tenant_code = ?
LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $history_id, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        json_error("Payment history record not found", 404);
    }
    
    $history = $result->fetch_assoc();
    $stmt->close();
    
    // Format dates
    $history['initiated_at_formatted'] = date('F j, Y h:i:s A', strtotime($history['initiated_at']));
    $history['verified_at_formatted'] = $history['verified_at'] ? date('F j, Y h:i:s A', strtotime($history['verified_at'])) : 'Not verified';
    $history['amount_formatted'] = '₦' . number_format($history['amount'], 2);
    
    // Status text
    $status_texts = [
        'initiated' => 'Initiated',
        'pending_verification' => 'Pending Verification',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'failed' => 'Failed'
    ];
    $history['status_text'] = $status_texts[$history['status']] ?? ucfirst($history['status']);
    
    json_success($history, "Payment history details retrieved");
    
} catch (Exception $e) {
    logActivity("Error fetching payment history details: " . $e->getMessage());
    json_error("Failed to fetch details", 500);
}
