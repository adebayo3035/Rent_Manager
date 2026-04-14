<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

logActivity("========== INITIATE RENT PAYMENT - START ==========");
logActivity("Request Time: " . date('Y-m-d H:i:s'));

// Helper functions
function generateReceiptNumber($tenant_code, $type)
{
    $prefix = ($type === 'rent') ? 'RENT' : 'DEP';
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -6));
    $receipt = "{$prefix}-{$date}-{$random}";
    logActivity("Generated Receipt Number: {$receipt} for tenant: {$tenant_code}");
    return $receipt;
}

function generateReferenceNumber($tenant_code, $payment_method, $type)
{
    $prefix = ($type === 'rent') ? 'RENT' : 'DEP';
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    $random = rand(1000, 9999);
    $reference = "{$prefix}-{$date}-{$tenant_short}-{$random}";
    logActivity("Generated Reference Number: {$reference} for tenant: {$tenant_code} | Method: {$payment_method}");
    return $reference;
}

function calculateNewLeaseEndDate($current_end_date, $payment_frequency, $period_end_date = null)
{
    logActivity("Calculating New Lease End Date | Current: {$current_end_date} | Frequency: {$payment_frequency} | Period End: " . ($period_end_date ?? 'null'));
    
    if ($period_end_date) {
        logActivity("Using period end date as new lease end date: {$period_end_date}");
        return $period_end_date;
    }

    $current_end = new DateTime($current_end_date);
    $new_end = clone $current_end;

    switch ($payment_frequency) {
        case 'Monthly':
            $new_end->modify('+1 month');
            break;
        case 'Quarterly':
            $new_end->modify('+3 months');
            break;
        case 'Semi-Annually':
            $new_end->modify('+6 months');
            break;
        case 'Annually':
            $new_end->modify('+1 year');
            break;
        default:
            $new_end->modify('+1 month');
    }
    
    $new_date = $new_end->format('Y-m-d');
    logActivity("Calculated new lease end date: {$new_date}");
    return $new_date;
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
    logActivity("Calculating Due Date | Period End: {$period_end_date} | Frequency: {$payment_frequency} | Grace Days: {$daysToAdd}");

    $dueDate = new DateTime($period_end_date);
    $dueDate->modify("+{$daysToAdd} days");
    
    $due_date = $dueDate->format('Y-m-d');
    logActivity("Calculated Due Date: {$due_date}");
    return $due_date;
}

try {
    logActivity("Step 1: Checking authentication and session");
    
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("ERROR: Authentication failed - No tenant code in session");
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    logActivity("Authenticated Tenant Code: {$tenant_code}");

    if (!$tenant_code) {
        logActivity("ERROR: Tenant code not found in session");
        json_error("Tenant code not found", 400);
    }

    // ==================== CHECK FOR PENDING RENT PAYMENTS ONLY ====================
    logActivity("Step 2: Checking for pending rent payments");
    
    $pending_payments_query = "
        SELECT COUNT(*) as pending_count, SUM(amount) as pending_amount
        FROM payments
        WHERE tenant_code = ? 
        AND payment_status IN ('pending', 'overdue')
        AND is_deleted = 0
        AND payment_category = 'rent'
    ";
    logActivity("Executing pending payments query on payments table");
    $pending_stmt = $conn->prepare($pending_payments_query);
    $pending_stmt->bind_param("s", $tenant_code);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result();
    $pending_data = $pending_result->fetch_assoc();
    $pending_stmt->close();
    logActivity("Payments table pending check - Count: {$pending_data['pending_count']}, Amount: {$pending_data['pending_amount']}");

    $pending_rent_query = "
        SELECT COUNT(*) as pending_count, SUM(amount) as pending_amount
        FROM rent_payments
        WHERE tenant_code = ? 
        AND status IN ('pending', 'overdue')
        AND payment_type = 'rent'
    ";
    logActivity("Executing pending payments query on rent_payments table");
    $pending_rent_stmt = $conn->prepare($pending_rent_query);
    $pending_rent_stmt->bind_param("s", $tenant_code);
    $pending_rent_stmt->execute();
    $pending_rent_result = $pending_rent_stmt->get_result();
    $pending_rent_data = $pending_rent_result->fetch_assoc();
    $pending_rent_stmt->close();
    logActivity("Rent payments table pending check - Count: {$pending_rent_data['pending_count']}, Amount: {$pending_rent_data['pending_amount']}");

    $total_pending_count = ($pending_data['pending_count'] ?? 0) + ($pending_rent_data['pending_count'] ?? 0);
    $total_pending_amount = ($pending_rent_data['pending_amount'] ?? 0);
    logActivity("Total pending payments - Count: {$total_pending_count}, Amount: {$total_pending_amount}");

    if ($total_pending_count > 0) {
        logActivity("ERROR: Blocking payment due to pending payments - Count: {$total_pending_count}, Amount: {$total_pending_amount}");
        json_error(
            "You have pending rent payment(s) totaling ₦" . number_format($total_pending_amount, 2) .
            ". Please complete your pending rent payment(s) before initiating a new rent payment.",
            400,
            null,
            'PENDING_RENT_PAYMENTS_EXIST'
        );
    }
    logActivity("No pending payments found. Proceeding with payment initiation.");

    // Get input data
    logActivity("Step 3: Processing input data");
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        logActivity("ERROR: Invalid JSON input received");
        json_error("Invalid input data", 400);
    }
    
    logActivity("Input data received: " . json_encode([
        'payment_method' => $input['payment_method'] ?? 'null',
        'payment_period' => $input['payment_period'] ?? 'null',
        'period_start_date' => $input['period_start_date'] ?? 'null',
        'period_end_date' => $input['period_end_date'] ?? 'null',
        'amount' => $input['amount'] ?? 'null',
        'is_advance' => $input['is_advance'] ?? 'false'
    ]));

    $payment_method = $input['payment_method'] ?? '';
    $payment_period = $input['payment_period'] ?? null;
    $period_start_date = $input['period_start_date'] ?? null;
    $period_end_date = $input['period_end_date'] ?? null;
    $amount_paid = $input['amount'] ?? null;
    $reference_number_input = $input['reference_number'] ?? null;
    $notes = $input['notes'] ?? '';
    $is_advance_payment = $input['is_advance'] ?? false;

    // Validate payment method
    $allowed_methods = ['bank_transfer', 'card', 'cash', 'cheque'];
    if (!in_array($payment_method, $allowed_methods)) {
        logActivity("ERROR: Invalid payment method - {$payment_method}");
        json_error("Invalid payment method", 400);
    }
    logActivity("Payment method validated: {$payment_method}");

    // Begin transaction
    logActivity("Step 4: Starting database transaction");
    $conn->begin_transaction();
    logActivity("Transaction started successfully");

    try {
        // 1. Get tenant details
        logActivity("Step 5: Fetching tenant details from database");
        $tenant_query = "
            SELECT 
                t.tenant_code,
                t.apartment_code,
                t.lease_start_date,
                t.lease_end_date,
                t.payment_frequency,
                t.status as tenant_status,
                a.rent_amount,
                a.security_deposit,
                a.apartment_number,
                a.apartment_type_id,
                p.name as property_name,
                p.property_code
            FROM tenants t
            JOIN apartments a ON t.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            WHERE t.tenant_code = ? AND t.status = 1
            LIMIT 1
        ";

        $tenant_stmt = $conn->prepare($tenant_query);
        $tenant_stmt->bind_param("s", $tenant_code);
        $tenant_stmt->execute();
        $tenant_result = $tenant_stmt->get_result();
        $tenant = $tenant_result->fetch_assoc();
        $tenant_stmt->close();

        if (!$tenant) {
            logActivity("ERROR: Tenant information not found for code: {$tenant_code}");
            throw new Exception("Tenant information not found", 404);
        }
        
        logActivity("Tenant details retrieved - Name: {$tenant['property_name']}, Apartment: {$tenant['apartment_number']}, Frequency: {$tenant['payment_frequency']}, Rent: {$tenant['rent_amount']}");

        // Use current rent amount from apartments table
        $rent_amount = (float) $tenant['rent_amount'];
        $amount = $amount_paid ?: $rent_amount;
        logActivity("Rent amount validated - Expected: {$rent_amount}, Received: {$amount}");

        if ($amount != $rent_amount) {
            logActivity("ERROR: Payment amount mismatch - Expected: {$rent_amount}, Received: {$amount}");
            throw new Exception("Payment amount does not match current rent amount", 400);
        }

        // 2. Get the last completed rent payment
        logActivity("Step 6: Fetching last completed rent payment");
        $last_payment_query = "
            SELECT payment_date, payment_period, period_end_date, created_at
            FROM rent_payments
            WHERE tenant_code = ? 
            AND payment_type = 'rent'
            AND status = 'completed'
            ORDER BY payment_date DESC
            LIMIT 1
        ";

        $last_stmt = $conn->prepare($last_payment_query);
        $last_stmt->bind_param("s", $tenant_code);
        $last_stmt->execute();
        $last_result = $last_stmt->get_result();
        $last_payment = $last_result->fetch_assoc();
        $last_stmt->close();
        
        if ($last_payment) {
            logActivity("Last payment found - Date: {$last_payment['payment_date']}, Period: {$last_payment['payment_period']}, Period End: {$last_payment['period_end_date']}");
        } else {
            logActivity("No previous payments found - This will be the first payment");
        }

        // 3. Calculate period start and end dates if not provided
        logActivity("Step 7: Calculating period dates");
        if (!$period_start_date || !$period_end_date) {
            $reference_date = null;
            if ($last_payment && $last_payment['period_end_date']) {
                $reference_date = new DateTime($last_payment['period_end_date']);
                $reference_date->modify('+1 day');
                logActivity("Using last payment period end as reference: {$last_payment['period_end_date']} + 1 day = {$reference_date->format('Y-m-d')}");
            } else {
                $reference_date = new DateTime($tenant['lease_start_date']);
                logActivity("Using lease start date as reference: {$tenant['lease_start_date']}");
            }

            $period_start_date = $reference_date->format('Y-m-d');

            $period_end = clone $reference_date;
            switch ($tenant['payment_frequency']) {
                case 'Monthly':
                    $period_end->modify('+1 month')->modify('-1 day');
                    break;
                case 'Quarterly':
                    $period_end->modify('+3 months')->modify('-1 day');
                    break;
                case 'Semi-Annually':
                    $period_end->modify('+6 months')->modify('-1 day');
                    break;
                case 'Annually':
                    $period_end->modify('+1 year')->modify('-1 day');
                    break;
                default:
                    $period_end->modify('+1 month')->modify('-1 day');
            }
            $period_end_date = $period_end->format('Y-m-d');
            logActivity("Calculated period - Start: {$period_start_date}, End: {$period_end_date}");
        } else {
            logActivity("Using provided period dates - Start: {$period_start_date}, End: {$period_end_date}");
        }

        // 4. Calculate due date
        logActivity("Step 8: Calculating due date");
        $due_date = calculateDueDate($period_end_date, $tenant['payment_frequency']);

        // 5. Calculate new lease end date
        logActivity("Step 9: Calculating new lease end date");
        $new_lease_end_date = calculateNewLeaseEndDate(
            $tenant['lease_end_date'],
            $tenant['payment_frequency'],
            $period_end_date
        );

        // 6. Check if payment for this period already exists
        logActivity("Step 10: Checking for duplicate payment");
        $check_query = "
            SELECT payment_id FROM rent_payments 
            WHERE tenant_code = ? 
            AND payment_type = 'rent'
            AND period_start_date = ?
            AND period_end_date = ?
            AND status = 'completed'
            LIMIT 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("sss", $tenant_code, $period_start_date, $period_end_date);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            logActivity("ERROR: Duplicate payment detected for period {$period_start_date} to {$period_end_date}");
            throw new Exception("Payment for period {$period_start_date} to {$period_end_date} has already been made", 400);
        }
        $check_stmt->close();
        logActivity("No duplicate payment found");

        // 7. Generate unique identifiers
        logActivity("Step 11: Generating unique identifiers");
        $receipt_number = generateReceiptNumber($tenant_code, 'rent');
        $reference_number = $reference_number_input ?: generateReferenceNumber($tenant_code, $payment_method, 'rent');
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);
        logActivity("Generated - Receipt: {$receipt_number}, Reference: {$reference_number}, Transaction: {$transaction_id}");

        // 8. Set payment date
        $payment_date = date('Y-m-d');
        logActivity("Payment date set to: {$payment_date}");

        // Format period for display
        $period_display = $payment_period ?: "{$period_start_date} to {$period_end_date}";
        logActivity("Period display: {$period_display}");

        // 9. Insert into rent_payments table
        logActivity("Step 12: Inserting record into rent_payments table");
        $rent_payment_query = "
            INSERT INTO rent_payments (
                tenant_code,
                apartment_code,
                amount,
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
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'rent', ?, ?, NOW())
        ";

        $rent_notes = "Rent payment for period: {$period_display}\n"
            . "Property: {$tenant['property_name']}\n"
            . "Apartment: {$tenant['apartment_number']}\n"
            . "Period Start: {$period_start_date}\n"
            . "Period End: {$period_end_date}\n"
            . "Due Date: {$due_date}\n"
            . "{$notes}";

        $rent_stmt = $conn->prepare($rent_payment_query);

        if (!$rent_stmt) {
            logActivity("ERROR: Rent Payment Prepare Failed: " . $conn->error);
            throw new Exception("Rent Payment Prepare Failed: " . $conn->error);
        }

        $rent_stmt->bind_param(
            "ssdsssssssss",
            $tenant_code,
            $tenant['apartment_code'],
            $amount,
            $payment_date,
            $payment_method,
            $period_display,
            $period_start_date,
            $period_end_date,
            $due_date,
            $reference_number,
            $receipt_number,
            $rent_notes
        );

        if (!$rent_stmt->execute()) {
            logActivity("ERROR: Rent Payment Execute Failed: " . $rent_stmt->error);
            throw new Exception("Rent Payment Execute Failed: " . $rent_stmt->error);
        }

        $rent_payment_id = $rent_stmt->insert_id;
        $rent_stmt->close();
        logActivity("Rent payment record inserted successfully - ID: {$rent_payment_id}");

        // 10. Insert into payments table
        logActivity("Step 13: Inserting record into payments table");
        $payment_query = "
            INSERT INTO payments (
                tenant_code,
                apartment_code,
                amount,
                balance,
                payment_date,
                due_date,
                payment_method,
                payment_status,
                receipt_number,
                reference_number,
                description,
                payment_category,
                recorded_by,
                created_at
            ) VALUES (?, ?, ?, 0, NOW(), ?, ?, 'pending', ?, ?, ?, 'rent', ?, NOW())
        ";

        $payment_description = "Rent payment for period: {$period_display}\n"
            . "Property: {$tenant['property_name']}\n"
            . "Apartment: {$tenant['apartment_number']}\n"
            . "Period: {$period_start_date} to {$period_end_date}";

        $payment_stmt = $conn->prepare($payment_query);

        if (!$payment_stmt) {
            logActivity("ERROR: Payment Prepare Failed: " . $conn->error);
            throw new Exception("Payment Prepare Failed: " . $conn->error);
        }

        $payment_stmt->bind_param(
            "ssdssssss",
            $tenant_code,
            $tenant['apartment_code'],
            $amount,
            $due_date,
            $payment_method,
            $receipt_number,
            $reference_number,
            $payment_description,
            $tenant_code
        );

        if (!$payment_stmt->execute()) {
            logActivity("ERROR: Payment Execute Failed: " . $payment_stmt->error);
            throw new Exception("Payment Execute Failed: " . $payment_stmt->error);
        }

        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();
        logActivity("Payment record inserted successfully - ID: {$payment_id}");

        // 11. Update tenant's lease end date
        logActivity("Step 14: Updating tenant lease end date");
        if ($is_advance_payment || $new_lease_end_date > $tenant['lease_end_date']) {
            $update_tenant_query = "
                UPDATE tenants 
                SET temp_lease_end_date = ?, 
                    last_updated_at = NOW()
                WHERE tenant_code = ?
            ";
            $update_stmt = $conn->prepare($update_tenant_query);
            $update_stmt->bind_param("ss", $new_lease_end_date, $tenant_code);
            $update_stmt->execute();
            $update_stmt->close();
            logActivity("Lease end date updated for tenant: $tenant_code | New date: $new_lease_end_date");
        } else {
            logActivity("No lease end date update needed - Current: {$tenant['lease_end_date']}, New: {$new_lease_end_date}");
        }

        // 12. Log the payment activity
        logActivity("Step 15: Committing transaction and logging completion");
        logActivity("Rent payment completed | Tenant: $tenant_code | Amount: $amount | Period: $period_display | Period Start: $period_start_date | Period End: $period_end_date | Due Date: $due_date | Receipt: $receipt_number | New lease end date: $new_lease_end_date | Advance Payment: " . ($is_advance_payment ? 'Yes' : 'No'));

        $conn->commit();
        logActivity("Transaction committed successfully");

        // Prepare response
        $response_data = [
            'payment_id' => $payment_id,
            'rent_payment_id' => $rent_payment_id,
            'receipt_number' => $receipt_number,
            'reference_number' => $reference_number,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'payment_period' => $period_display,
            'period_start_date' => $period_start_date,
            'period_end_date' => $period_end_date,
            'due_date' => $due_date,
            'payment_date' => $payment_date,
            'payment_method' => $payment_method,
            'new_lease_end_date' => $new_lease_end_date,
            'property_name' => $tenant['property_name'],
            'apartment_number' => $tenant['apartment_number'],
            'is_advance_payment' => $is_advance_payment,
            'status' => 'completed',
            'message' => $is_advance_payment ? 'Advance payment processed successfully. Your lease has been extended.' : 'Payment processed successfully'
        ];

        logActivity("========== INITIATE RENT PAYMENT - SUCCESS ==========");
        json_success($response_data, "Payment processed successfully");

    } catch (Exception $e) {
        logActivity("ERROR: Exception caught in transaction - Rolling back");
        $conn->rollback();
        logActivity("Transaction rolled back due to error: " . $e->getMessage());
        throw $e;
    }

} catch (Exception $e) {
    logActivity("ERROR: Fatal error in initiate_rent_payment - " . $e->getMessage());
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>