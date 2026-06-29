<?php
// admin/backend/payment/fetch_rent_payment_history_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

try {
    if (!isset($_SESSION['unique_id'])) {
        json_error("Unauthorized", 401);
    }
    
    $history_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($history_id <= 0) {
        json_error("Invalid history ID", 400);
    }
    
    $query = "
        SELECT 
            rph.*,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            t.lease_start_date,
            t.lease_end_date,
            t.agreed_payment_frequency as payment_frequency,
            a.apartment_number,
            a.apartment_number,
            a.apartment_code,
            rpt.start_date,
            rpt.end_date,
            p.name as property_name,
            p.address as property_address,
            p.city,
            p.state,
            CASE 
                WHEN rph.initiated_by_type = 'tenant' THEN CONCAT(t.firstname, ' ', t.lastname)
                ELSE (SELECT unique_id FROM admin_tbl WHERE unique_id = rph.initiated_by LIMIT 1)
            END as initiated_by_name,
            CASE 
                WHEN rph.verified_by IS NOT NULL THEN (SELECT unique_id FROM admin_tbl WHERE unique_id = rph.verified_by LIMIT 1)
                ELSE NULL
            END as verified_by_name
        FROM rent_payment_history rph
        JOIN tenants t ON rph.tenant_code = t.tenant_code
        JOIN apartments a ON rph.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN rent_payment_tracker rpt ON rph.tracker_id = rpt.tracker_id
        WHERE rph.id = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $history_id);
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
    $history['amount_formatted'] = 'NGN ' . number_format($history['amount'], 2);
    
    // Status badge
    $status_badges = [
        'initiated' => ['class' => 'warning', 'text' => 'Initiated'],
        'pending_verification' => ['class' => 'info', 'text' => 'Pending Verification'],
        'paid' => ['class' => 'success', 'text' => 'Paid'],
        'approved' => ['class' => 'success', 'text' => 'Approved'],
        'rejected' => ['class' => 'danger', 'text' => 'Rejected'],
        'failed' => ['class' => 'danger', 'text' => 'Failed']
    ];
    $history['status_badge'] = $status_badges[$history['status']] ?? ['class' => 'secondary', 'text' => ucfirst($history['status'])];
    
    json_success("Payment history details retrieved", $history);
    
} catch (Exception $e) {
    logActivity("Error fetching payment history details: " . $e->getMessage());
    json_error("Failed to fetch details", 500);
}

