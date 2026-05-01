<?php
// initiate_new_lease_cycle.php - Tenant starts new lease cycle after lease ends

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

logActivity("========== INITIATE NEW LEASE CYCLE ==========");

try {
    // Check authentication - Tenant can initiate
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("ERROR: No tenant code in session");
        json_error("Not logged in", 401);
    }
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }
    
    $tenant_code = $_SESSION['tenant_code'];
    logActivity("Tenant Code: {$tenant_code}");
    
    $conn->begin_transaction();
    logActivity("Transaction started");
    
    // 1. Verify current lease is fully paid AND has ended
    $checkQuery = "
        SELECT 
            t.tenant_code,
            t.lease_end_date,
            t.lease_start_date,
            t.apartment_code,
            t.property_code,
            t.agreed_payment_frequency,
            t.agreed_rent_amount as old_rent,
            t.payment_amount_per_period as old_payment_per_period,
            COUNT(CASE WHEN tr.status != 'paid' THEN 1 END) as unpaid_periods,
            COUNT(CASE WHEN tr.status = 'pending_verification' THEN 1 END) as pending_verification_count
        FROM tenants t
        LEFT JOIN rent_payment_tracker tr ON t.tenant_code = tr.tenant_code
        WHERE t.tenant_code = ? AND t.status = 1
        GROUP BY t.tenant_code
    ";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $tenant_code);
    $checkStmt->execute();
    $tenant = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$tenant) {
        throw new Exception("Tenant not found", 404);
    }
    
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $lease_end = new DateTime($tenant['lease_end_date']);
    $lease_end->setTime(0, 0, 0);
    
    logActivity("Today: " . $today->format('Y-m-d'));
    logActivity("Lease End Date: " . $lease_end->format('Y-m-d'));
    logActivity("Unpaid Periods: " . $tenant['unpaid_periods']);
    logActivity("Pending Verification Count: " . $tenant['pending_verification_count']);
    
    // Validation checks
    if ($tenant['unpaid_periods'] > 0) {
        throw new Exception("Cannot start new cycle. You have " . $tenant['unpaid_periods'] . " unpaid period(s). Please complete all payments first.", 400);
    }
    
    if ($tenant['pending_verification_count'] > 0) {
        throw new Exception("Cannot start new cycle. You have a payment pending verification. Please wait for admin approval.", 400);
    }
    
    if ($today < $lease_end) {
        $days_left = $today->diff($lease_end)->days;
        throw new Exception("Cannot start new cycle. Your current lease ends on " . 
                           $lease_end->format('F j, Y') . " ($days_left days remaining).", 400);
    }
    
    logActivity("Eligibility check passed - Tenant can start new lease cycle");
    
    // 2. Get current apartment rent amount (may have been updated by admin)
    $aptQuery = "
        SELECT a.rent_amount, a.security_deposit, a.apartment_number
        FROM apartments a
        WHERE a.apartment_code = ?
    ";
    $aptStmt = $conn->prepare($aptQuery);
    $aptStmt->bind_param("s", $tenant['apartment_code']);
    $aptStmt->execute();
    $apartment = $aptStmt->get_result()->fetch_assoc();
    $aptStmt->close();
    
    $new_rent_amount = (float)$apartment['rent_amount'];
    $new_security_deposit = (float)$apartment['security_deposit'];
    $payment_frequency = $tenant['agreed_payment_frequency'];
    
    logActivity("Current apartment rent: ₦{$new_rent_amount} (Old rent was: ₦{$tenant['old_rent']})");
    
    // 3. Calculate payment per period based on new rent
    switch ($payment_frequency) {
        case 'Monthly': $payment_per_period = $new_rent_amount / 12; break;
        case 'Quarterly': $payment_per_period = $new_rent_amount / 4; break;
        case 'Semi-Annually': $payment_per_period = $new_rent_amount / 2; break;
        case 'Annually': $payment_per_period = $new_rent_amount; break;
        default: $payment_per_period = $new_rent_amount;
    }
    $payment_per_period = round($payment_per_period, 2);
    
    // 4. Calculate new lease dates
    $new_start = clone $lease_end;
    $new_start->modify('+1 day');
    $new_end = clone $new_start;
    $new_end->modify('+1 year')->modify('-1 day');
    
    logActivity("New lease period: {$new_start->format('Y-m-d')} to {$new_end->format('Y-m-d')}");
    logActivity("New payment per period: ₦{$payment_per_period}");
    
    // 5. Create new rent_payments record
    $new_rent_payment_id = 'RENT_' . strtoupper(uniqid());
    $receipt_number = 'RCP-' . date('Ymd') . '-' . strtoupper(uniqid());
    $reference_number = 'REF-' . date('Ymd') . '-' . substr($tenant_code, -6) . '-RENEW';
    $due_date = calculateDueDate($new_end->format('Y-m-d'), $payment_frequency);
    
    $insertRent = $conn->prepare("
        INSERT INTO rent_payments (
            rent_payment_id, 
            tenant_code, 
            apartment_code, 
            amount, 
            amount_paid, 
            balance,
            payment_date, 
            payment_method, 
            payment_period, 
            period_start_date, 
            period_end_date,
            due_date, 
            reference_number, 
            status, 
            payment_type, 
            receipt_number, 
            notes,
            agreed_rent_amount, 
            payment_amount_per_period, 
            created_at
        ) VALUES (?, ?, ?, ?, 0, ?, NOW(), 'pending', ?, ?, ?, ?, ?, 'ongoing', 'rent', ?, ?, ?, ?, NOW())
    ");
    
    $notes = "New lease cycle initiated by tenant on " . date('Y-m-d H:i:s');
    $new_start_date = $new_start->format('Y-m-d');
    $new_end_date =   $new_end->format('Y-m-d');
    $payment_period = $payment_frequency . ' Cycle';
    
    $insertRent->bind_param(
        "sssddsssssssdd",
        $new_rent_payment_id, 
        $tenant_code, 
        $tenant['apartment_code'],
        $new_rent_amount, 
        $new_rent_amount, 
        $payment_period,
        $new_start_date,
        $new_end_date,
        $due_date, 
        $reference_number, 
        $receipt_number, 
        $notes,
        $new_rent_amount, 
        $payment_per_period
    );
    
    if (!$insertRent->execute()) {
        throw new Exception("Failed to create rent payment record: " . $insertRent->error);
    }
    $insertRent->close();
    logActivity("New rent_payments record created: {$new_rent_payment_id}");
    
    // 6. Create all tracker records for new cycle
    $tracker_start = clone $new_start;
    $interval_months = 0;
    switch ($payment_frequency) {
        case 'Monthly': $interval_months = 1; break;
        case 'Quarterly': $interval_months = 3; break;
        case 'Semi-Annually': $interval_months = 6; break;
        case 'Annually': $interval_months = 12; break;
        default: $interval_months = 1;
    }
    
    $period_number = 1;
    $remaining_balance = $new_rent_amount;
    $trackers_created = 0;
    
    $insertTracker = $conn->prepare("
        INSERT INTO rent_payment_tracker (
            rent_payment_id, 
            tenant_code, 
            apartment_code, 
            period_number,
            start_date, 
            end_date, 
            remaining_balance, 
            amount_paid,
            payment_date, 
            status, 
            payment_id, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 'available', ?, NOW())
    ");
    
    $current_start = clone $tracker_start;
    
    while ($current_start <= $new_end) {
        $current_end = clone $current_start;
        $current_end->modify("+{$interval_months} months");
        $current_end->modify('-1 day');
        
        if ($current_end > $new_end) {
            $current_end = clone $new_end;
        }
        
        $tracker_payment_id = 'TRAK_' . strtoupper(uniqid()) . '_' . $period_number;
        $current_start_date = $current_start->format('Y-m-d');
        $current_end_date = $current_end->format('Y-m-d');
        
        $insertTracker->bind_param(
            "sssissds",
            $new_rent_payment_id, 
            $tenant_code, 
            $tenant['apartment_code'],
            $period_number, 
            $current_start_date,
            $current_end_date,
            $remaining_balance, 
            $tracker_payment_id
        );
        
        if ($insertTracker->execute()) {
            $trackers_created++;
            logActivity("Created tracker #{$period_number}: {$current_start->format('Y-m-d')} to {$current_end->format('Y-m-d')}");
        } else {
            logActivity("WARNING: Failed to create tracker #{$period_number}: " . $insertTracker->error);
        }
        
        $remaining_balance -= $payment_per_period;
        $current_start = clone $current_end;
        $current_start->modify('+1 day');
        $period_number++;
        
        if ($period_number > 100) break;
    }
    $insertTracker->close();
    logActivity("Created {$trackers_created} tracker records for new cycle");
    
    // 7. Update tenant record
    $updateTenant = $conn->prepare("
        UPDATE tenants 
        SET lease_start_date = ?,
            lease_end_date = ?,
            agreed_rent_amount = ?,
            payment_amount_per_period = ?,
            rent_balance = ?,
            last_rent_update_date = NOW()
        WHERE tenant_code = ?
    ");
    $new_start_date = $new_start->format('Y-m-d');
    $new_end_date =  $new_end->format('Y-m-d');
    $updateTenant->bind_param(
        "ssddds", 
        $new_start_date,
        $new_end_date,
        $new_rent_amount, 
        $payment_per_period, 
        $new_rent_amount, 
        $tenant_code
    );
    $updateTenant->execute();
    $updateTenant->close();
    logActivity("Tenant record updated with new lease dates");

    // Create notification
    createLeaseNotification($conn, $tenant_code, 'renewed', $new_end->format('Y-m-d'));
    
    $conn->commit();
    logActivity("Transaction committed successfully");
    
    // Prepare response
    $response_data = [
        'tenant_code' => $tenant_code,
        'new_lease_start' => $new_start->format('Y-m-d'),
        'new_lease_end' => $new_end->format('Y-m-d'),
        'new_rent_amount' => $new_rent_amount,
        'old_rent_amount' => (float)$tenant['old_rent'],
        'rent_increased' => $new_rent_amount > (float)$tenant['old_rent'],
        'rent_increase_percentage' => $tenant['old_rent'] > 0 ? round((($new_rent_amount - $tenant['old_rent']) / $tenant['old_rent']) * 100, 2) : 0,
        'payment_per_period' => $payment_per_period,
        'payment_frequency' => $payment_frequency,
        'total_periods' => $trackers_created,
        'rent_payment_id' => $new_rent_payment_id,
        'message' => "New lease cycle started successfully! Your new rent is ₦" . number_format($new_rent_amount, 2) . " per year."
    ];
    
    logActivity("========== INITIATE NEW LEASE CYCLE - SUCCESS ==========");
    json_success($response_data, $response_data['message']);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
        logActivity("Transaction rolled back");
    }
    logActivity("ERROR: " . $e->getMessage());
    logActivity("Stack trace: " . $e->getTraceAsString());
    json_error($e->getMessage(), 500);
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
?>
