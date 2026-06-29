<?php
// admin/backend/payment/get_tenant_period.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

// Generate unique request ID for tracking
$requestId = uniqid('get_tenant_period_', true);
logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ========== START ==========");
logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id'])) {
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ERROR: Unauthorized - No session");
        json_error("Unauthorized", 401);
    }
    
    $adminId = $_SESSION['unique_id'];
    $adminRole = $_SESSION['role'] ?? 'Admin';
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Admin authenticated: ID={$adminId}, Role={$adminRole}");
    
    // ==================== STEP 2: GET AND VALIDATE INPUT ====================
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Step 2: Getting input parameters");
    
    $tenant_code = isset($_GET['tenant_code']) ? trim($_GET['tenant_code']) : '';
    
    if (empty($tenant_code)) {
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ERROR: Tenant code is required");
        json_error("Tenant code is required", 400);
    }
    
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Tenant code: {$tenant_code}");
    
    // ==================== STEP 3: FETCH TENANT DETAILS ====================
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Step 3: Fetching tenant details");
    
    $tenant_query = "
        SELECT 
            t.tenant_code,
            t.firstname,
            t.lastname,
            CONCAT(t.firstname, ' ', t.lastname) as full_name,
            t.email,
            t.phone,
            t.apartment_code,
            t.lease_start_date,
            t.lease_end_date,
            t.agreed_payment_frequency as payment_frequency,
            t.payment_amount_per_period,
            t.agreed_rent_amount,
            t.tenant_status as status,
            a.apartment_number,
            p.name as property_name,
            p.property_code
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ? 
        AND t.deleted_at IS NULL
        LIMIT 1
    ";
    
    $tenant_stmt = $conn->prepare($tenant_query);
    if (!$tenant_stmt) {
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ERROR: Failed to prepare tenant query: " . $conn->error);
        json_error("Database error", 500);
    }
    
    $tenant_stmt->bind_param("s", $tenant_code);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    $tenant = $tenant_result->fetch_assoc();
    $tenant_stmt->close();
    
    if (!$tenant) {
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ERROR: Tenant not found: {$tenant_code}");
        json_error("Tenant not found", 404);
    }
    
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Tenant found: {$tenant['full_name']}");
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Payment frequency: {$tenant['payment_frequency']}");
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Amount per period: ₦{$tenant['payment_amount_per_period']}");
    
    // ==================== STEP 4: CHECK FOR PENDING VERIFICATION ====================
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Step 4: Checking for pending verification payments");
    
    $pending_query = "
        SELECT 
            tracker_id,
            period_number,
            start_date,
            end_date,
            amount_paid,
            status,
            payment_date
        FROM rent_payment_tracker
        WHERE tenant_code = ? 
        AND apartment_code = ?
        AND status = 'pending_verification'
        ORDER BY period_number ASC
        LIMIT 1
    ";
    
    $pending_stmt = $conn->prepare($pending_query);
    $pending_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result();
    $pending_period = $pending_result->fetch_assoc();
    $pending_stmt->close();
    
    if ($pending_period) {
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Found pending verification for Period #{$pending_period['period_number']}");
        
        // Calculate due date for this period
        $due_date = calculateDueDate($pending_period['end_date'], $tenant['payment_frequency']);
        
        $response_data = [
            'tenant' => [
                'tenant_code' => $tenant['tenant_code'],
                'firstname' => $tenant['firstname'],
                'lastname' => $tenant['lastname'],
                'full_name' => $tenant['full_name'],
                'email' => $tenant['email'],
                'phone' => $tenant['phone'],
                'apartment_number' => $tenant['apartment_number'],
                'property_name' => $tenant['property_name'],
                'property_code' => $tenant['property_code'],
                'payment_frequency' => $tenant['payment_frequency'],
                'payment_amount_per_period' => $tenant['payment_amount_per_period'],
                'lease_start_date' => $tenant['lease_start_date'],
                'lease_end_date' => $tenant['lease_end_date']
            ],
            'current_period' => [
                'tracker_id' => $pending_period['tracker_id'],
                'period_number' => $pending_period['period_number'],
                'start_date' => $pending_period['start_date'],
                'end_date' => $pending_period['end_date'],
                'amount' => $pending_period['amount_paid'],
                'status' => $pending_period['status'],
                'due_date' => $due_date,
                'is_pending' => true,
                'is_failed' => false,
                'payment_date' => $pending_period['payment_date']
            ],
            'has_pending' => true,
            'has_failed' => false,
            'can_proceed' => false,
            'message' => "Tenant has a pending payment for Period #{$pending_period['period_number']} waiting for verification. Please verify or reject it first."
        ];
        
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Returning pending period info");
        json_success($response_data, "Pending payment found");
    }
    
    // ==================== STEP 5: GET EARLIEST UNRESOLVED PERIOD (FAILED OR AVAILABLE) ====================
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Step 5: Finding earliest unresolved period");
    
    // Priority: FAILED (retry) comes before AVAILABLE
    $unresolved_query = "
        SELECT 
            tracker_id,
            period_number,
            start_date,
            end_date,
            amount_paid,
            status,
            payment_date,
            payment_reference,
            payment_method,
            admin_notes,
            verified_at,
            verified_by
        FROM rent_payment_tracker
        WHERE tenant_code = ? 
        AND apartment_code = ?
        AND status IN ('failed', 'available')
        ORDER BY 
            CASE status 
                WHEN 'failed' THEN 1 
                WHEN 'available' THEN 2 
            END,
            period_number ASC
        LIMIT 1
    ";
    
    $unresolved_stmt = $conn->prepare($unresolved_query);
    $unresolved_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
    $unresolved_stmt->execute();
    $unresolved_result = $unresolved_stmt->get_result();
    $current_period = $unresolved_result->fetch_assoc();
    $unresolved_stmt->close();
    
    if (!$current_period) {
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] No unresolved periods found. Checking if all paid.");
        
        // Check if all periods are paid
        $all_paid_query = "
            SELECT COUNT(*) as unpaid_count
            FROM rent_payment_tracker
            WHERE tenant_code = ? 
            AND apartment_code = ?
            AND status NOT IN ('paid')
        ";
        $all_paid_stmt = $conn->prepare($all_paid_query);
        $all_paid_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
        $all_paid_stmt->execute();
        $all_paid_result = $all_paid_stmt->get_result();
        $unpaid_count = $all_paid_result->fetch_assoc()['unpaid_count'];
        $all_paid_stmt->close();
        
        if ($unpaid_count == 0) {
            logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] All periods are paid");
            json_error("All rent payments for this lease have been completed.", 400);
        } else {
            logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Unexpected state: No failed/available but unpaid count = {$unpaid_count}");
            json_error("Unable to determine next payment period. Please contact support.", 400);
        }
    }
    
    // Calculate due date for this period
    $due_date = calculateDueDate($current_period['end_date'], $tenant['payment_frequency']);
    
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Found period #{$current_period['period_number']} with status: {$current_period['status']}");
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Period dates: {$current_period['start_date']} to {$current_period['end_date']}");
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Due date: {$due_date}");
    
    // ==================== STEP 6: PREPARE RESPONSE ====================
    $is_failed = ($current_period['status'] === 'failed');
    $is_available = ($current_period['status'] === 'available');
    
    $period_data = [
        'tracker_id' => $current_period['tracker_id'],
        'period_number' => $current_period['period_number'],
        'start_date' => $current_period['start_date'],
        'end_date' => $current_period['end_date'],
        'amount' => $tenant['payment_amount_per_period'],
        'status' => $current_period['status'],
        'due_date' => $due_date,
        'is_pending' => false,
        'is_failed' => $is_failed,
        'is_available' => $is_available
    ];
    
    // Add failure details if applicable
    if ($is_failed) {
        $period_data['failed_at'] = $current_period['payment_date'];
        $period_data['failure_reason'] = $current_period['admin_notes'] ?? 'Payment failed during processing';
        $period_data['retry_count'] = 1; // Can be expanded to track retries
        $period_data['resolution_required'] = true;
        
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] This is a FAILED period that needs resolution");
    } else {
        $period_data['resolution_required'] = false;
        logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] This is an AVAILABLE period ready for payment");
    }
    
    $response_data = [
        'tenant' => [
            'tenant_code' => $tenant['tenant_code'],
            'firstname' => $tenant['firstname'],
            'lastname' => $tenant['lastname'],
            'full_name' => $tenant['full_name'],
            'email' => $tenant['email'],
            'phone' => $tenant['phone'],
            'apartment_number' => $tenant['apartment_number'],
            'property_name' => $tenant['property_name'],
            'property_code' => $tenant['property_code'],
            'payment_frequency' => $tenant['payment_frequency'],
            'payment_amount_per_period' => $tenant['payment_amount_per_period'],
            'lease_start_date' => $tenant['lease_start_date'],
            'lease_end_date' => $tenant['lease_end_date'],
            'lease_start_formatted' => date('F j, Y', strtotime($tenant['lease_start_date'])),
            'lease_end_formatted' => date('F j, Y', strtotime($tenant['lease_end_date']))
        ],
        'current_period' => $period_data,
        'has_pending' => false,
        'has_failed' => $is_failed,
        'has_available' => $is_available,
        'can_proceed' => true,
        'message' => $is_failed 
            ? "Period #{$current_period['period_number']} payment failed. You can retry or mark as paid."
            : "Next available payment period found"
    ];
    
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ========== SUCCESS ==========");
    json_success($response_data, $response_data['message']);
    
} catch (Exception $e) {
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] ERROR EXCEPTION: " . $e->getMessage());
    logActivity("[GET_TENANT_PERIOD] [ID:{$requestId}] Stack trace: " . $e->getTraceAsString());
    json_error("Failed to fetch tenant payment information: " . $e->getMessage(), 500);
}

/**
 * Calculate due date based on period end date and payment frequency
 */
function calculateDueDate($period_end_date, $payment_frequency) {
    $gracePeriods = [
        'Monthly' => 7,
        'Quarterly' => 14,
        'Semi-Annually' => 30,
        'Annually' => 90
    ];
    
    $daysToAdd = $gracePeriods[$payment_frequency] ?? 7;
    $dueDate = new DateTime($period_end_date);
    $dueDate->modify("+{$daysToAdd} days");
    return $dueDate->format('Y-m-d');
}

/**
 * Helper function to send JSON error response (overrides the one from utils)
 */
// function json_error($message, $code = 400, $data = null) {
//     http_response_code($code);
//     echo json_encode([
//         'success' => false,
//         'message' => $message,
//         'status_code' => $code,
//         'data' => $data,
//         'timestamp' => date('Y-m-d H:i:s')
//     ]);
//     exit();
// }

/**
 * Helper function to send JSON success response (overrides the one from utils)
 */
// function json_success($data, $message = "Success") {
//     echo json_encode([
//         'success' => true,
//         'message' => $message,
//         'status_code' => 200,
//         'data' => $data,
//         'timestamp' => date('Y-m-d H:i:s')
//     ]);
//     exit();
// }
?>