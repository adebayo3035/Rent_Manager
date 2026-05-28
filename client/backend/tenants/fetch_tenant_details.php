<?php
// client/backend/tenants/fetch_tenant_details.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('fetch_tenant_details_', true);
logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ========== START ==========");

try {
    // Check authentication
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Checking authentication");
    
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ERROR: Unauthorized - No client session");
        json_error("Unauthorized", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    $tenant_code = isset($_GET['tenant_code']) ? trim($_GET['tenant_code']) : '';
    
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Client: {$client_code}, Tenant: {$tenant_code}");
    
    if (empty($tenant_code)) {
        logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ERROR: Tenant code is required");
        json_error("Tenant code is required", 400);
    }
    
    // Main query with all tenant fields
    $query = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            CONCAT(t.firstname, ' ', t.lastname) as full_name,
            t.email,
            t.phone,
            t.gender,
            t.photo,
            t.occupation,
            t.name_of_employer,
            t.employer_address,
            t.employer_contact,
            t.lease_start_date,
            t.lease_end_date,
            t.move_out_date,
            t.temp_lease_end_date,
            t.payment_frequency,
            t.referee_name,
            t.referee_phone,
            t.emergency_contact_name,
            t.emergency_contact_phone,
            t.status as status,
            t.tenant_status as account_status,
            t.evacuation_status,
            t.evacuation_reason,
            t.evacuation_notes,
            t.early_termination_fee,
            t.final_settlement_amount,
            t.evacuated_at,
            t.can_request_evacuation,
            t.created_at,
            t.created_by,
            t.last_updated_at,
            t.last_updated_by,
            t.password_changed,
            t.has_secret_set,
            t.agreed_rent_amount,
            t.rent_balance,
            t.agreed_payment_frequency,
            t.payment_amount_per_period,
            t.last_rent_update_date,
            t.rent_updated_by,
            t.rent_update_reason,
            t.old_rent_amount,
            a.apartment_code,
            a.apartment_number,
            p.name as property_name,
            p.address as property_address,
            p.city,
            p.state,
            p.country,
            COALESCE(tr.avg_rating, 0) as avg_rating,
            COALESCE(tr.rating_count, 0) as rating_count,
            (
                SELECT SUM(amount_paid) 
                FROM rent_payment_tracker 
                WHERE tenant_code = t.tenant_code AND status IN ('paid', 'approved')
            ) as total_paid,
            (
                SELECT SUM(amount_paid) 
                FROM rent_payment_tracker 
                WHERE tenant_code = t.tenant_code AND status = 'pending_verification'
            ) as total_pending,
            (
                SELECT COUNT(*) 
                FROM maintenance_requests 
                WHERE tenant_code = t.tenant_code
            ) as total_maintenance_requests,
            (
                SELECT COUNT(*) 
                FROM maintenance_requests 
                WHERE tenant_code = t.tenant_code AND status = 'completed'
            ) as completed_maintenance,
            (
                SELECT COUNT(*) 
                FROM tenant_ratings 
                WHERE tenant_code = t.tenant_code AND client_code = ?
            ) as user_has_rated
        FROM tenants t
        INNER JOIN apartments a ON t.apartment_code = a.apartment_code
        INNER JOIN properties p ON a.property_code = p.property_code
        LEFT JOIN (
            SELECT tenant_code, AVG(rating) as avg_rating, COUNT(*) as rating_count
            FROM tenant_ratings
            WHERE client_code = ?
            GROUP BY tenant_code
        ) tr ON t.tenant_code = tr.tenant_code
        WHERE t.tenant_code = ? AND p.client_code = ?
        AND t.deleted_at IS NULL
        LIMIT 1
    ";
    
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Executing main query");
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ERROR: Failed to prepare query: " . $conn->error);
        throw new Exception("Database prepare error");
    }
    
    $stmt->bind_param("ssss", $client_code, $client_code, $tenant_code, $client_code);
    
    if (!$stmt->execute()) {
        logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ERROR: Failed to execute query: " . $stmt->error);
        throw new Exception("Database execute error");
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ERROR: Tenant not found: {$tenant_code}");
        json_error("Tenant not found", 404);
    }
    
    $tenant = $result->fetch_assoc();
    $stmt->close();
    
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Tenant found: {$tenant['full_name']}");
    
    // Format dates
    $tenant['lease_start_formatted'] = $tenant['lease_start_date'] ? date('F j, Y', strtotime($tenant['lease_start_date'])) : 'N/A';
    $tenant['lease_end_formatted'] = $tenant['lease_end_date'] ? date('F j, Y', strtotime($tenant['lease_end_date'])) : 'N/A';
    $tenant['move_out_date_formatted'] = $tenant['move_out_date'] ? date('F j, Y', strtotime($tenant['move_out_date'])) : 'N/A';
    $tenant['created_at_formatted'] = $tenant['created_at'] ? date('F j, Y', strtotime($tenant['created_at'])) : 'N/A';
    $tenant['last_updated_formatted'] = $tenant['last_updated_at'] ? date('F j, Y g:i A', strtotime($tenant['last_updated_at'])) : 'N/A';
    $tenant['evacuated_at_formatted'] = $tenant['evacuated_at'] ? date('F j, Y g:i A', strtotime($tenant['evacuated_at'])) : 'N/A';
    
    // Map status to readable format
    $status_map = [
        '0' => 'Inactive',
        '1' => 'Active',
        '2' => 'Suspended',
        '3' => 'Evicted'
    ];
    $tenant['status_text'] = $status_map[$tenant['status']] ?? 'Unknown';
    $tenant['status_badge'] = $tenant['status'] == '1' ? 'active' : ($tenant['status'] == '2' ? 'suspended' : 'inactive');
    
    // Map evacuation status
    $evac_status_map = [
        'active' => 'Active',
        'pending_evacuation' => 'Pending Evacuation',
        'evacuated' => 'Evacuated'
    ];
    $tenant['evacuation_status_text'] = $evac_status_map[$tenant['evacuation_status']] ?? 'Active';
    
    // Calculate lease remaining
    if ($tenant['lease_end_date']) {
        $end = new DateTime($tenant['lease_end_date']);
        $today = new DateTime();
        $tenant['days_remaining'] = $today->diff($end)->days;
        $tenant['months_remaining'] = floor($tenant['days_remaining'] / 30);
        $tenant['lease_expired'] = $end < $today;
    } else {
        $tenant['days_remaining'] = 0;
        $tenant['months_remaining'] = 0;
        $tenant['lease_expired'] = false;
    }
    
    // Calculate payment stats
    $tenant['total_paid'] = floatval($tenant['total_paid'] ?? 0);
    $tenant['total_pending'] = floatval($tenant['total_pending'] ?? 0);
    $tenant['total_maintenance_requests'] = intval($tenant['total_maintenance_requests'] ?? 0);
    $tenant['completed_maintenance'] = intval($tenant['completed_maintenance'] ?? 0);
    $tenant['maintenance_completion_rate'] = $tenant['total_maintenance_requests'] > 0 
        ? round(($tenant['completed_maintenance'] / $tenant['total_maintenance_requests']) * 100) 
        : 0;
    
    // Format currency values
    $tenant['agreed_rent_amount_formatted'] = '₦' . number_format($tenant['agreed_rent_amount'] ?? 0, 2);
    $tenant['rent_balance_formatted'] = '₦' . number_format($tenant['rent_balance'] ?? 0, 2);
    $tenant['payment_amount_per_period_formatted'] = '₦' . number_format($tenant['payment_amount_per_period'] ?? 0, 2);
    $tenant['early_termination_fee_formatted'] = '₦' . number_format($tenant['early_termination_fee'] ?? 0, 2);
    $tenant['final_settlement_amount_formatted'] = '₦' . number_format($tenant['final_settlement_amount'] ?? 0, 2);
    $tenant['total_paid_formatted'] = '₦' . number_format($tenant['total_paid'], 2);
    $tenant['total_pending_formatted'] = '₦' . number_format($tenant['total_pending'], 2);
    
    // Get recent payments
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Fetching recent payments for tenant");
    
    $paymentQuery = "
        SELECT 
            tracker_id,
            period_number,
            amount_paid,
            payment_date,
            payment_method,
            status,
            payment_reference
        FROM rent_payment_tracker
        WHERE tenant_code = ?
        ORDER BY payment_date DESC
        LIMIT 5
    ";
    $payStmt = $conn->prepare($paymentQuery);
    $payStmt->bind_param("s", $tenant_code);
    $payStmt->execute();
    $tenant['recent_payments'] = $payStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $payStmt->close();
    
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Found " . count($tenant['recent_payments']) . " recent payments");
    
    // Get recent maintenance requests
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Fetching recent maintenance requests");
    
    $maintenanceQuery = "
        SELECT 
            request_id,
            issue_type,
            status,
            priority,
            created_at,
            resolved_at
        FROM maintenance_requests
        WHERE tenant_code = ?
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $mainStmt = $conn->prepare($maintenanceQuery);
    $mainStmt->bind_param("s", $tenant_code);
    $mainStmt->execute();
    $tenant['recent_maintenance'] = $mainStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mainStmt->close();
    
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Found " . count($tenant['recent_maintenance']) . " recent maintenance requests");
    
    // Get rating categories if user has rated
    if ($tenant['user_has_rated'] > 0) {
        $ratingQuery = "
            SELECT category, rating, comment, created_at
            FROM tenant_ratings
            WHERE tenant_code = ? AND client_code = ?
        ";
        $rateStmt = $conn->prepare($ratingQuery);
        $rateStmt->bind_param("ss", $tenant_code, $client_code);
        $rateStmt->execute();
        $tenant['user_ratings'] = $rateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rateStmt->close();
    } else {
        $tenant['user_ratings'] = [];
    }
    
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] User has rated: " . ($tenant['user_has_rated'] ? 'Yes' : 'No'));
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ========== SUCCESS ==========");
    
    json_success($tenant, "Tenant details retrieved successfully");
    
} catch (Exception $e) {
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] ERROR EXCEPTION: " . $e->getMessage());
    logActivity("[FETCH_TENANT_DETAILS] [ID:{$requestId}] Stack trace: " . $e->getTraceAsString());
    json_error("Failed to fetch tenant details: " . $e->getMessage(), 500);
}