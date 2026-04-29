<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

logActivity("========== INITIATE RENT PAYMENT - START ==========");
logActivity("Request Time: " . date('Y-m-d H:i:s'));

// Helper functions
function generateReceiptNumber($tenant_code, $type)
{
    $prefix = ($type === 'rent') ? 'RENT' : 'DEP';
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -6));
    return "{$prefix}-{$date}-{$random}";
}

function generateReferenceNumber($tenant_code, $payment_method)
{
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    $random = rand(1000, 9999);
    return "REF-{$date}-{$tenant_short}-{$random}";
}

function calculateDueDate($period_end_date, $payment_frequency)
{
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

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }
    
    $tenant_code = $_SESSION['tenant_code'];
    logActivity("Authenticated Tenant Code: {$tenant_code}");
    
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error("Invalid input data", 400);
    }
    
    $payment_method = $input['payment_method'] ?? '';
    $reference_number_input = $input['reference_number'] ?? null;
    $notes = $input['notes'] ?? '';
    
    $allowed_methods = ['bank_transfer', 'card', 'cash', 'cheque'];
    if (!in_array($payment_method, $allowed_methods)) {
        json_error("Invalid payment method", 400);
    }
    
    $conn->begin_transaction();
    logActivity("Transaction started");
    
    try {
        // 1. Get tenant details
        $tenant_query = "
            SELECT 
                t.tenant_code,
                t.apartment_code,
                t.lease_start_date,
                t.lease_end_date,
                t.payment_frequency,
                t.agreed_rent_amount,
                t.payment_amount_per_period,
                t.rent_balance,
                t.agreed_payment_frequency,
                a.apartment_number,
                p.name as property_name
            FROM tenants t
            JOIN apartments a ON t.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            WHERE t.tenant_code = ? AND t.status = 1
            LIMIT 1
        ";
        
        $tenant_stmt = $conn->prepare($tenant_query);
        $tenant_stmt->bind_param("s", $tenant_code);
        $tenant_stmt->execute();
        $tenant = $tenant_stmt->get_result()->fetch_assoc();
        $tenant_stmt->close();
        
        if (!$tenant) {
            throw new Exception("Tenant information not found", 404);
        }
        
        $payment_frequency = $tenant['agreed_payment_frequency'] ?? $tenant['payment_frequency'];
        $payment_per_period = (float)($tenant['payment_amount_per_period'] ?? 0);
        
        logActivity("Tenant: {$tenant['property_name']}, Apartment: {$tenant['apartment_number']}");
        logActivity("Payment Frequency: {$payment_frequency}, Amount per period: {$payment_per_period}");
        
        // 2. FIRST: Check for ANY pending_verification payment (BLOCK new payment)
        $pending_check_query = "
            SELECT tracker_id, period_number, start_date, end_date, status
            FROM rent_payment_tracker
            WHERE tenant_code = ? 
            AND apartment_code = ?
            AND status = 'pending_verification'
            LIMIT 1
        ";
        
        $pending_stmt = $conn->prepare($pending_check_query);
        $pending_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
        $pending_stmt->execute();
        $pending_payment = $pending_stmt->get_result()->fetch_assoc();
        $pending_stmt->close();
        
        if ($pending_payment) {
            logActivity("BLOCK: Found pending verification - Period #{$pending_payment['period_number']}");
            throw new Exception(
                "You have a pending payment for Period #{$pending_payment['period_number']} " .
                "waiting for admin verification. Please wait for confirmation before making another payment.",
                400
            );
        }
        
        // 3. Find the next AVAILABLE period (not pending_verification, not paid, not failed)
        $next_period_query = "
            SELECT 
                tracker_id,
                rent_payment_id,
                period_number,
                start_date,
                end_date,
                remaining_balance,
                amount_paid,
                status,
                payment_id as existing_payment_id
            FROM rent_payment_tracker
            WHERE tenant_code = ? 
            AND apartment_code = ?
            AND status = 'available'
            ORDER BY period_number ASC
            LIMIT 1
        ";
        
        $next_stmt = $conn->prepare($next_period_query);
        $next_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
        $next_stmt->execute();
        $next_period = $next_stmt->get_result()->fetch_assoc();
        $next_stmt->close();
        
        if (!$next_period) {
            // Check for failed period that needs attention
            $failed_check = "
                SELECT period_number, status 
                FROM rent_payment_tracker 
                WHERE tenant_code = ? AND apartment_code = ? AND status = 'failed'
                ORDER BY period_number ASC
                LIMIT 1
            ";
            $failed_stmt = $conn->prepare($failed_check);
            $failed_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
            $failed_stmt->execute();
            $failed_period = $failed_stmt->get_result()->fetch_assoc();
            $failed_stmt->close();
            
            if ($failed_period) {
                throw new Exception(
                    "Your previous payment for Period #{$failed_period['period_number']} failed. " .
                    "Please contact support to resolve this issue.",
                    400
                );
            }
            
            // Check if all periods are paid
            $all_paid_check = "
                SELECT COUNT(*) as unpaid_count 
                FROM rent_payment_tracker 
                WHERE tenant_code = ? AND apartment_code = ? AND status != 'paid'
            ";
            $all_paid_stmt = $conn->prepare($all_paid_check);
            $all_paid_stmt->bind_param("ss", $tenant_code, $tenant['apartment_code']);
            $all_paid_stmt->execute();
            $unpaid_result = $all_paid_stmt->get_result()->fetch_assoc();
            $all_paid_stmt->close();
            
            if ($unpaid_result['unpaid_count'] == 0) {
                throw new Exception("All rent payments for your lease have been completed. Your lease is fully paid.", 400);
            } else {
                throw new Exception("No available payment periods found. Please contact support.", 400);
            }
        }
        
        logActivity("Next available period found - Period #{$next_period['period_number']}: {$next_period['start_date']} to {$next_period['end_date']}");
        
        // 4. Get the main rent payment record
        $rent_payment_query = "
            SELECT 
                rent_payment_id,
                amount as total_annual_rent,
                amount_paid as initial_payment,
                balance as remaining_balance
            FROM rent_payments
            WHERE rent_payment_id = ?
            LIMIT 1
        ";
        
        $rent_stmt = $conn->prepare($rent_payment_query);
        $rent_stmt->bind_param("s", $next_period['rent_payment_id']);
        $rent_stmt->execute();
        $rent_payment = $rent_stmt->get_result()->fetch_assoc();
        $rent_stmt->close();
        
        if (!$rent_payment) {
            throw new Exception("Rent payment agreement not found", 404);
        }
        
        // 5. Generate unique identifiers
        $receipt_number = generateReceiptNumber($tenant_code, 'rent');
        $reference_number = $reference_number_input ?: generateReferenceNumber($tenant_code, $payment_method);
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);
        $payment_date = date('Y-m-d H:i:s');
        $due_date = calculateDueDate($next_period['end_date'], $payment_frequency);
        
        // 6. IMPORTANT: USE THE EXISTING payment_id from onboarding, don't create a new one
        $existing_payment_id = $next_period['existing_payment_id'];
        logActivity("Using existing payment_id from onboarding: {$existing_payment_id}");
        
        // 7. Update the tracker record to 'pending_verification'
        $update_tracker_query = "
            UPDATE rent_payment_tracker 
            SET status = 'pending_verification',
                payment_date = ?,
                payment_reference = ?,
                payment_method = ?,
                amount_paid = ?
            WHERE tracker_id = ?
            AND status = 'available'
        ";
        
        $update_stmt = $conn->prepare($update_tracker_query);
        $update_stmt->bind_param("ssssi", $payment_date, $reference_number, $payment_method, $payment_per_period, $next_period['tracker_id']);
        
        if (!$update_stmt->execute() || $update_stmt->affected_rows == 0) {
            throw new Exception("Failed to initiate payment. Period may have been already paid.", 500);
        }
        $update_stmt->close();
        logActivity("Tracker record updated to 'pending_verification' - payment_id remains: {$existing_payment_id}");
        
        // Payment initiated (pending verification) Initiate Payment Notification 
        createPaymentNotification($conn, $tenant_code, $payment_per_period, 'initiated', $next_period['period_number'], $receipt_number);

        $conn->commit();
        logActivity("Transaction committed successfully");
        
        // Prepare response
        $response_data = [
            'payment_id' => $existing_payment_id,  // Use existing payment_id
            'tracker_id' => $next_period['tracker_id'],
            'period_number' => $next_period['period_number'],
            'receipt_number' => $receipt_number,
            'reference_number' => $reference_number,
            'transaction_id' => $transaction_id,
            'amount' => $payment_per_period,
            'period_start_date' => $next_period['start_date'],
            'period_end_date' => $next_period['end_date'],
            'due_date' => $due_date,
            'payment_date' => date('Y-m-d'),
            'payment_method' => $payment_method,
            'property_name' => $tenant['property_name'],
            'apartment_number' => $tenant['apartment_number'],
            'status' => 'pending_verification',
            'message' => 'Your payment has been initiated and is pending admin verification.'
        ];
        
        logActivity("========== INITIATE RENT PAYMENT - SUCCESS ==========");
        json_success($response_data, "Payment initiated successfully");
        
    } catch (Exception $e) {
        $conn->rollback();
        logActivity("Transaction rolled back: " . $e->getMessage());
        throw $e;
    }
    
} catch (Exception $e) {
    logActivity("ERROR: " . $e->getMessage());
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}

function formatPeriodDisplay($start_date, $end_date, $frequency) {
    $start = new DateTime($start_date);
    switch($frequency) {
        case 'Monthly': return $start->format('F Y');
        case 'Quarterly': $quarter = ceil($start->format('n') / 3); return "Q{$quarter} {$start->format('Y')}";
        case 'Semi-Annually': $half = $start->format('n') <= 6 ? 'H1' : 'H2'; return "{$half} {$start->format('Y')}";
        case 'Annually': return $start->format('Y');
        default: return $start->format('F Y');
    }
}

function formatDateRange($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
}
?>