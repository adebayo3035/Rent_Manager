<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

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

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400);
    }

    $tenant_fee_id = $input['tenant_fee_id'] ?? 0;
    $payment_method = $input['payment_method'] ?? '';
    $reference_number = $input['reference_number'] ?? null;
    $amount_paid = $input['amount'] ?? null;

    // Validate input
    if (!$tenant_fee_id) {
        json_error("Fee ID is required", 400);
    }

    $allowed_methods = ['bank_transfer', 'card', 'cash', 'cheque'];
    if (!in_array($payment_method, $allowed_methods)) {
        json_error("Invalid payment method", 400);
    }

    // Begin transaction
    $conn->begin_transaction();

    try {
        // 1. Get fee details and verify ownership
        $fee_query = "
            SELECT tf.*, ft.fee_name, ft.fee_code, ft.is_recurring, ft.recurrence_period,
                   a.apartment_number, a.apartment_code, p.name as property_name
            FROM tenant_fees tf
            JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
            JOIN apartments a ON tf.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            WHERE tf.tenant_fee_id = ? AND tf.tenant_code = ?
        ";
        
        $fee_stmt = $conn->prepare($fee_query);
        $fee_stmt->bind_param("is", $tenant_fee_id, $tenant_code);
        $fee_stmt->execute();
        $fee_result = $fee_stmt->get_result();
        $fee = $fee_result->fetch_assoc();
        $fee_stmt->close();

        if (!$fee) {
            throw new Exception("Fee not found or unauthorized", 404);
        }

        if ($fee['status'] === 'paid') {
            throw new Exception("This fee has already been paid", 400);
        }

        // Use provided amount or fee amount
        $amount = $amount_paid ?: $fee['amount'];

        // 2. Generate unique identifiers
        $receipt_number = 'RCT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);

        // 3. Update tenant_fees table
        $update_fee_query = "
            UPDATE tenant_fees 
            SET status = 'paid', 
                payment_date = NOW(), 
                payment_method = ?, 
                receipt_number = ?,
                notes = CONCAT(IFNULL(notes, ''), ' Paid on ', NOW(), ' via ', ?, '. Receipt: ', ?)
            WHERE tenant_fee_id = ?
        ";
        
        $update_stmt = $conn->prepare($update_fee_query);
        $update_stmt->bind_param("ssssi", $payment_method, $receipt_number, $payment_method, $receipt_number, $tenant_fee_id);
        $update_stmt->execute();
        $update_stmt->close();

        // 4. Record in payments table (for comprehensive payment tracking)
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
            ) VALUES (?, ?, ?, ?, NOW(), ?, ?, 'completed', ?, ?, ?, ?, NOW())
        ";
        
        // Calculate balance (assuming full payment)
        $balance = 0;
        $due_date = $fee['due_date'];
        $description = "Payment for fee: " . $fee['fee_name'] . " (" . $fee['fee_code'] . ")";
        
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bind_param(
            "ssddsssssi",
            $tenant_code,
            $fee['apartment_code'],
            $amount,
            $balance,
            $due_date,
            $payment_method,
            $receipt_number,
            $reference_number,
            $description,
            $tenant_code  // recorded_by (using tenant_code as identifier)
        );
        $payment_stmt->execute();
        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();

        // // 5. Also record in rent_payments table for consistency (if it's a rent-related fee)
        // $rent_payment_query = "
        //     INSERT INTO rent_payments (
        //         tenant_code,
        //         apartment_code,
        //         amount,
        //         payment_date,
        //         payment_method,
        //         reference_number,
        //         status,
        //         payment_type,
        //         receipt_number,
        //         notes,
        //         created_at
        //     ) VALUES (?, ?, ?, NOW(), ?, ?, 'completed', 'fee', ?, ?, NOW())
        // ";
        
        // $rent_payment_stmt = $conn->prepare($rent_payment_query);
        // $notes = "Payment for fee: " . $fee['fee_name'];
        // $rent_payment_stmt->bind_param(
        //     "ssdssss",
        //     $tenant_code,
        //     $fee['apartment_code'],
        //     $amount,
        //     $payment_method,
        //     $reference_number,
        //     $receipt_number,
        //     $notes
        // );
        // $rent_payment_stmt->execute();
        // $rent_payment_stmt->close();

        // 6. If this is a recurring fee, generate next month's fee
        $next_fee_created = false;
        if ($fee['is_recurring'] == 1 && $fee['recurrence_period'] !== 'one-time') {
            // Calculate next due date based on recurrence period
            $next_due_date = date('Y-m-d', strtotime($fee['due_date'] . ' +1 month'));
            
            // Check if next fee already exists
            $check_next_query = "
                SELECT tenant_fee_id FROM tenant_fees 
                WHERE tenant_code = ? AND fee_type_id = ? AND due_date = ?
            ";
            $check_stmt = $conn->prepare($check_next_query);
            $check_stmt->bind_param("sis", $tenant_code, $fee['fee_type_id'], $next_due_date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                // Create next month's fee
                $next_fee_query = "
                    INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ";
                $next_stmt = $conn->prepare($next_fee_query);
                $next_stmt->bind_param("ssids", $tenant_code, $fee['apartment_code'], $fee['fee_type_id'], $amount, $next_due_date);
                $next_stmt->execute();
                $next_fee_created = true;
                $next_stmt->close();
            }
            $check_stmt->close();
        }

        // Commit transaction
        $conn->commit();

        // Log the activity
        logActivity("Fee payment completed | Tenant: $tenant_code | Fee: {$fee['fee_name']} | Amount: $amount | Receipt: $receipt_number");

        // Prepare response
        $response_data = [
            'payment_id' => $payment_id,
            'tenant_fee_id' => $tenant_fee_id,
            'receipt_number' => $receipt_number,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'fee_name' => $fee['fee_name'],
            'fee_code' => $fee['fee_code'],
            'payment_date' => date('Y-m-d H:i:s'),
            'next_fee_created' => $next_fee_created,
            'next_due_date' => $next_fee_created ? $next_due_date : null,
            'receipt_url' => "../backend/tenant/download_fee_receipt.php?payment_id={$payment_id}"
        ];

        json_success($response_data, "Payment successful!");

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("Error in pay_fee: " . $e->getMessage());
    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>