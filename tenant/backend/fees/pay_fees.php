<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('fee_payment_', true);
logActivity("[FEE_PAYMENT] [ID:{$requestId}] ========== START ==========");
logActivity("[FEE_PAYMENT] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[FEE_PAYMENT] [ID:{$requestId}] Request Method: " . $_SERVER['REQUEST_METHOD']);

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 1: Checking authentication");

    if (!isset($_SESSION['tenant_code'])) {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: No tenant code in session");
        json_error("Not logged in", 401);
    }
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 1 - Authentication passed: tenant_code found");

    // ==================== STEP 2: CHECK USER ROLE ====================
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 2: Checking user role");

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Unauthorized access - Role: " . ($_SESSION['role'] ?? 'none'));
        json_error("Unauthorized access", 403);
    }
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 2 - Role validation passed: Tenant");

    // ==================== STEP 3: GET TENANT CODE ====================
    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Tenant code not found");
        json_error("Tenant code not found", 400);
    }
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 3 - Tenant Code: {$tenant_code}");

    // ==================== STEP 4: GET INPUT DATA ====================
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 4: Getting input data");

    $raw_input = file_get_contents('php://input');
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 4.1 - Raw input received: " . ($raw_input ? strlen($raw_input) . " bytes" : "empty"));

    $input = json_decode($raw_input, true);

    if (!$input) {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Invalid JSON input");
        json_error("Invalid input data", 400);
    }
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 4.2 - JSON decoded successfully");

    // ==================== STEP 5: EXTRACT INPUT FIELDS ====================
    $tenant_fee_id = $input['tenant_fee_id'] ?? 0;
    $payment_method = $input['payment_method'] ?? '';
    $reference_number = $input['reference_number'] ?? null;
    $amount_paid = $input['amount'] ?? null;

    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 5 - Extracted input:");
    logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - tenant_fee_id: {$tenant_fee_id}");
    logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - payment_method: {$payment_method}");
    logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - reference_number: " . ($reference_number ?? 'null'));
    logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - amount_paid: " . ($amount_paid ?? 'null'));

    // ==================== STEP 6: VALIDATE INPUT ====================
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 6: Validating input");

    if (!$tenant_fee_id) {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee ID is missing");
        json_error("Fee ID is required", 400);
    }
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 6.1 - Tenant fee ID validated: {$tenant_fee_id}");

    $allowed_methods = ['bank_transfer', 'card', 'cash', 'cheque'];
    if (!in_array($payment_method, $allowed_methods)) {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Invalid payment method: {$payment_method}");
        json_error("Invalid payment method", 400);
    }
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 6.2 - Payment method validated: {$payment_method}");

    // ==================== STEP 7: START TRANSACTION ====================
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 7: Starting database transaction");
    $conn->begin_transaction();
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 7 - Transaction started");

    try {
        // ==================== STEP 8: GET FEE DETAILS ====================
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 8: Fetching fee details");

        $fee_query = "
            SELECT tf.*, ft.fee_name, ft.fee_code, ft.is_recurring, ft.recurrence_period,
                   a.apartment_number, a.apartment_code, p.name as property_name
            FROM tenant_fees tf
            JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
            JOIN apartments a ON tf.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            WHERE tf.tenant_fee_id = ? AND tf.tenant_code = ?
        ";

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 8.1 - Query prepared to fetch fee with ID: {$tenant_fee_id}");

        $fee_stmt = $conn->prepare($fee_query);
        $fee_stmt->bind_param("is", $tenant_fee_id, $tenant_code);
        $fee_stmt->execute();
        $fee_result = $fee_stmt->get_result();
        $fee = $fee_result->fetch_assoc();
        $fee_stmt->close();

        if (!$fee) {
            logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee not found or unauthorized for tenant: {$tenant_code}");
            throw new Exception("Fee not found or unauthorized", 404);
        }

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 8.2 - Fee found:");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - fee_name: {$fee['fee_name']}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - amount: {$fee['amount']}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - status: {$fee['status']}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - due_date: {$fee['due_date']}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - is_recurring: " . ($fee['is_recurring'] ? 'Yes' : 'No'));

        if ($fee['status'] === 'paid') {
            logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Fee already paid - Status: {$fee['status']}");
            throw new Exception("This fee has already been paid", 400);
        }
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 8.3 - Fee status verified: not paid");

        // Use provided amount or fee amount
        $amount = $amount_paid ?: $fee['amount'];
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 8.4 - Payment amount: ₦{$amount}");

        // ==================== STEP 9: GENERATE UNIQUE IDENTIFIERS ====================
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 9: Generating unique identifiers");

        $receipt_number = 'RCT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 9.1 - Generated receipt_number: {$receipt_number}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 9.2 - Generated transaction_id: {$transaction_id}");

        // ==================== STEP 10: UPDATE TENANT FEES TABLE ====================
        // ==================== STEP 10: UPDATE TENANT FEES TABLE ====================
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 10: Updating tenant_fees table");

        $update_fee_query = "
    UPDATE tenant_fees 
    SET status = 'paid', 
        payment_date = NOW(), 
        payment_method = ?, 
        receipt_number = ?,
        payment_id = ?,
        notes = CONCAT(IFNULL(notes, ''), ' Paid on ', NOW(), ' via ', ?, '. Receipt: ', ?)
    WHERE tenant_fee_id = ?
";

        $update_stmt = $conn->prepare($update_fee_query);
        $update_stmt->bind_param(
            "sssssi",
            $payment_method,      // payment_method for the payment_method column
            $receipt_number,      // receipt_number for the receipt_number column
            $transaction_id,      // payment_id (FIXED: transaction_id)
            $payment_method,      // payment_method for notes (via)
            $receipt_number,      // receipt_number for notes (Receipt:)
            $tenant_fee_id        // WHERE clause
        );

        if (!$update_stmt->execute()) {
            logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Failed to update tenant_fees table: " . $update_stmt->error);
            throw new Exception("Failed to update fee status", 500);
        }

        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 10.1 - tenant_fees updated successfully. Affected rows: {$affected_rows}");

        // ==================== STEP 11: RECORD IN PAYMENTS TABLE ====================
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 11: Recording in payments table");

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

        $balance = 0;
        $due_date = $fee['due_date'];
        $description = "Payment for fee: " . $fee['fee_name'] . " (" . $fee['fee_code'] . ")";

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 11.1 - Payment details:");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - tenant_code: {$tenant_code}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - apartment_code: {$fee['apartment_code']}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - amount: {$amount}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - due_date: {$due_date}");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}]   - reference_number: " . ($reference_number ?? 'null'));

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
            $tenant_code
        );

        if (!$payment_stmt->execute()) {
            logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Failed to insert into payments table: " . $payment_stmt->error);
            throw new Exception("Failed to record payment", 500);
        }

        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 11.2 - Payment record created. Payment ID: {$payment_id}");

        // ==================== STEP 12: GENERATE NEXT RECURRING FEE (IF APPLICABLE) ====================
        $next_fee_created = false;
        $next_due_date = null;

        if ($fee['is_recurring'] == 1 && $fee['recurrence_period'] !== 'one-time') {
            logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 12: Processing recurring fee generation");

            // Calculate next due date based on recurrence period
            switch (strtolower($fee['recurrence_period'])) {
                case 'monthly':
                    $next_due_date = date('Y-m-d', strtotime($fee['due_date'] . ' +1 month'));
                    break;
                case 'quarterly':
                    $next_due_date = date('Y-m-d', strtotime($fee['due_date'] . ' +3 months'));
                    break;
                case 'annually':
                    $next_due_date = date('Y-m-d', strtotime($fee['due_date'] . ' +1 year'));
                    break;
                default:
                    $next_due_date = date('Y-m-d', strtotime($fee['due_date'] . ' +1 month'));
            }

            logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 12.1 - Calculated next due date: {$next_due_date}");

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
                logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 12.2 - No existing next fee found. Creating new one.");

                // Create next month's fee
                $next_fee_query = "
                    INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ";
                $next_stmt = $conn->prepare($next_fee_query);
                $next_stmt->bind_param("ssids", $tenant_code, $fee['apartment_code'], $fee['fee_type_id'], $amount, $next_due_date);

                if ($next_stmt->execute()) {
                    $next_fee_created = true;
                    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 12.3 - Next recurring fee created successfully. Due date: {$next_due_date}");
                } else {
                    logActivity("[FEE_PAYMENT] [ID:{$requestId}] WARNING: Failed to create next recurring fee: " . $next_stmt->error);
                }
                $next_stmt->close();
            } else {
                logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 12.2 - Next fee already exists. Skipping creation.");
            }
            $check_stmt->close();
        } else {
            logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 12 - Not recurring or one-time fee. Skipping recurring generation.");
        }

        // ==================== STEP 13: COMMIT TRANSACTION ====================
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 13: Committing transaction");
        $conn->commit();
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 13 - Transaction committed successfully");

        // ==================== STEP 14: LOG ACTIVITY ====================
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 14: Logging payment activity");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Fee payment completed - Tenant: {$tenant_code} | Fee: {$fee['fee_name']} | Amount: ₦{$amount} | Receipt: {$receipt_number} | Next fee created: " . ($next_fee_created ? 'Yes' : 'No'));

        // ==================== STEP 15: PREPARE RESPONSE ====================
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

        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Step 15 - Response prepared successfully");
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ========== END - SUCCESS ==========");

        json_success($response_data, "Payment successful!");

    } catch (Exception $e) {
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] ERROR: Exception in transaction - Rolling back");
        $conn->rollback();
        logActivity("[FEE_PAYMENT] [ID:{$requestId}] Transaction rolled back. Error: " . $e->getMessage());
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] ========== END - ERROR ==========");
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[FEE_PAYMENT] [ID:{$requestId}] Error Line: " . $e->getLine());

    $error_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_error($e->getMessage(), $error_code);
}
?>