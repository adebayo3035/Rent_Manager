<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Helper functions
function generateReceiptNumber($tenant_code, $type)
{
    $prefix = ($type === 'rent') ? 'RENT' : 'DEP';
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -6));
    return "{$prefix}-{$date}-{$random}";
}

function generateReferenceNumber($tenant_code, $payment_method, $type)
{
    $prefix = ($type === 'rent') ? 'RENT' : 'DEP';
    $date = date('Ymd');
    $tenant_short = substr($tenant_code, -6);
    $random = rand(1000, 9999);
    return "{$prefix}-{$date}-{$tenant_short}-{$random}";
}

function calculateNewLeaseEndDate($current_end_date, $payment_frequency, $payment_period)
{
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

    return $new_end->format('Y-m-d');
}

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;

    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    // ==================== CHECK FOR PENDING RENT PAYMENTS ONLY ====================
    // Check payments table for pending/overdue rent payments
    $pending_payments_query = "
    SELECT COUNT(*) as pending_count, SUM(amount) as pending_amount
    FROM payments
    WHERE tenant_code = ? 
    AND payment_status IN ('pending', 'overdue')
    AND is_deleted = 0
    AND payment_category = 'rent'
";
    $pending_stmt = $conn->prepare($pending_payments_query);
    $pending_stmt->bind_param("s", $tenant_code);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result();
    $pending_data = $pending_result->fetch_assoc();
    $pending_stmt->close();

    // Check rent_payments table for pending/overdue rent payments
    $pending_rent_query = "
        SELECT COUNT(*) as pending_count, SUM(amount) as pending_amount
        FROM rent_payments
        WHERE tenant_code = ? 
        AND status IN ('pending', 'overdue')
        AND payment_type = 'rent'
    ";
    $pending_rent_stmt = $conn->prepare($pending_rent_query);
    $pending_rent_stmt->bind_param("s", $tenant_code);
    $pending_rent_stmt->execute();
    $pending_rent_result = $pending_rent_stmt->get_result();
    $pending_rent_data = $pending_rent_result->fetch_assoc();
    $pending_rent_stmt->close();

    $total_pending_count = ($pending_data['pending_count'] ?? 0) + ($pending_rent_data['pending_count'] ?? 0);
    $total_pending_amount = ($pending_rent_data['pending_amount'] ?? 0);

    // Only block if there are pending RENT payments
    if ($total_pending_count > 0) {
        json_error(
            "You have pending rent payment(s) totaling ₦" . number_format($total_pending_amount, 2) .
            ". Please complete your pending rent payment(s) before initiating a new rent payment.",
            400,
            null,
            'PENDING_RENT_PAYMENTS_EXIST'
        );
    }

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        json_error("Invalid input data", 400);
    }

    $payment_method = $input['payment_method'] ?? '';
    $payment_period = $input['payment_period'] ?? null;
    $amount_paid = $input['amount'] ?? null;
    $reference_number_input = $input['reference_number'] ?? null;
    $notes = $input['notes'] ?? '';

    // Validate payment method
    $allowed_methods = ['bank_transfer', 'card', 'cash', 'cheque'];
    if (!in_array($payment_method, $allowed_methods)) {
        json_error("Invalid payment method", 400);
    }

    // Begin transaction
    $conn->begin_transaction();

    try {
        // 1. Get tenant details with current apartment and rent amount
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
            throw new Exception("Tenant information not found", 404);
        }

        // Use current rent amount from apartments table
        $rent_amount = (float) $tenant['rent_amount'];
        $amount = $amount_paid ?: $rent_amount;

        // Validate amount matches expected rent
        if ($amount != $rent_amount) {
            throw new Exception("Payment amount does not match current rent amount", 400);
        }

        // 2. Get the last completed rent payment
        $last_payment_query = "
            SELECT payment_date, payment_period, created_at
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

        // 3. Calculate new lease end date
        $new_lease_end_date = calculateNewLeaseEndDate(
            $tenant['lease_end_date'],
            $tenant['payment_frequency'],
            $payment_period
        );

        // 4. Check if payment for this period already exists
        if ($payment_period) {
            $check_query = "
                SELECT payment_id FROM rent_payments 
                WHERE tenant_code = ? 
                AND payment_type = 'rent'
                AND payment_period = ?
                AND status = 'completed'
                LIMIT 1
            ";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("ss", $tenant_code, $payment_period);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                throw new Exception("Payment for period {$payment_period} has already been made", 400);
            }
            $check_stmt->close();
        }

        // 5. Generate unique identifiers
        $receipt_number = generateReceiptNumber($tenant_code, 'rent');
        $reference_number = $reference_number_input ?: generateReferenceNumber($tenant_code, $payment_method, 'rent');
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);

        // 6. Set payment dates
        $payment_date = date('Y-m-d');
        $due_date = date('Y-m-d', strtotime('+7 days')); // Due in 7 days

        // 7. Insert into rent_payments table
        $rent_payment_query = "
            INSERT INTO rent_payments (
                tenant_code,
                apartment_code,
                amount,
                payment_date,
                payment_method,
                reference_number,
                status,
                payment_type,
                receipt_number,
                payment_period,
                notes,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'rent', ?, ?, ?, NOW())
        ";

        $rent_notes = "Rent payment for period: {$payment_period}\nProperty: {$tenant['property_name']}\nApartment: {$tenant['apartment_number']}\n{$notes}";
        $rent_stmt = $conn->prepare($rent_payment_query);
        $rent_stmt->bind_param(
            "ssdssssss",
            $tenant_code,
            $tenant['apartment_code'],
            $amount,
            $payment_date,
            $payment_method,
            $reference_number,
            $receipt_number,
            $payment_period,
            $rent_notes
        );
        $rent_stmt->execute();
        $rent_payment_id = $rent_stmt->insert_id;
        $rent_stmt->close();

        // 8. Insert into payments table
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
                recorded_by,
                created_at
            ) VALUES (?, ?, ?, 0, NOW(), ?, ?, 'pending', ?, ?, ?, ?, NOW())
        ";

        $payment_description = "Rent payment for period: {$payment_period}\nProperty: {$tenant['property_name']}\nApartment: {$tenant['apartment_number']}";
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bind_param(
            "ssdssssss",  // 9 type characters for 9 placeholders: s,s,d,s,s,s,s,s,s
            $tenant_code,               // 1: s
            $tenant['apartment_code'],  // 2: s
            $amount,                    // 3: d
            $due_date,                  // 4: s
            $payment_method,            // 5: s
            $receipt_number,            // 6: s
            $reference_number,          // 7: s
            $payment_description,       // 8: s
            $tenant_code                // 9: s
        );
        $payment_stmt->execute();
        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();

        // 9. Update tenant's lease end date
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

        // 10. Log the payment activity
        logActivity("Rent payment initiated | Tenant: $tenant_code | Amount: $amount | Period: $payment_period | Receipt: $receipt_number | New Temporary lease end date: $new_lease_end_date");

        $conn->commit();

        // Prepare response
        $response_data = [
            'payment_id' => $payment_id,
            'rent_payment_id' => $rent_payment_id,
            'receipt_number' => $receipt_number,
            'reference_number' => $reference_number,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'payment_period' => $payment_period,
            'payment_date' => $payment_date,
            'due_date' => $due_date,
            'payment_method' => $payment_method,
            'new_lease_end_date' => $new_lease_end_date,
            'property_name' => $tenant['property_name'],
            'apartment_number' => $tenant['apartment_number'],
            'status' => 'pending',
            'message' => 'Payment initiated successfully. Please complete the payment process.'
        ];

        json_success($response_data, "Payment initiated successfully");

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("Error in initiate_rent_payment: " . $e->getMessage());
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>