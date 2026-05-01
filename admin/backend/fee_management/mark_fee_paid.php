<?php
// mark_fee_paid.php - Admin marks a tenant fee as paid

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
session_start();

try {
    // Check authentication - Admin only
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }

    $userRole = $_SESSION['role'] ?? '';
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        json_error("Unauthorized access", 403);
    }

    $admin_id = $_SESSION['unique_id'];

    $input = json_decode(file_get_contents('php://input'), true);
    $tenant_fee_id = isset($input['tenant_fee_id']) ? (int) $input['tenant_fee_id'] : 0;
    $notes = isset($input['notes']) ? trim($input['notes']) : '';

    if ($tenant_fee_id <= 0) {
        json_error("Invalid tenant fee ID", 400);
    }

    $conn->begin_transaction();

    try {
        // 1. Get fee details and verify exists
        $fee_query = "
            SELECT tf.*, ft.fee_name, ft.fee_code, ft.is_recurring, ft.recurrence_period,
                   a.apartment_number, a.apartment_code, p.name as property_name,
                   t.firstname, t.lastname, t.email, t.tenant_code
            FROM tenant_fees tf
            JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
            JOIN apartments a ON tf.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            JOIN tenants t ON tf.tenant_code = t.tenant_code
            WHERE tf.tenant_fee_id = ?
        ";

        $fee_stmt = $conn->prepare($fee_query);
        $fee_stmt->bind_param("i", $tenant_fee_id);
        $fee_stmt->execute();
        $fee = $fee_stmt->get_result()->fetch_assoc();
        $fee_stmt->close();

        if (!$fee) {
            throw new Exception("Fee not found", 404);
        }

        if ($fee['status'] === 'paid') {
            throw new Exception("This fee has already been paid", 400);
        }

        $amount = (float) $fee['amount'];
        $tenant_code = $fee['tenant_code'];
        $apartment_code = $fee['apartment_code'];
        $due_date = $fee['due_date'];
        $fee_name = $fee['fee_name'];
        $fee_code = $fee['fee_code'];

        // 2. Generate unique identifiers
        $receipt_number = 'RCT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $reference_number = 'ADMIN-' . date('Ymd') . '-' . $admin_id . '-' . $tenant_fee_id;
        $payment_method = 'admin_marked';
        $payment_date = date('Y-m-d H:i:s');

        // 3. Update tenant_fees table
        $admin_note = "Marked as paid by Admin on " . $payment_date . ". Receipt: " . $receipt_number;
        if ($notes) {
            $admin_note .= " | Admin note: " . $notes;
        }

        $update_fee_query = "
            UPDATE tenant_fees 
            SET status = 'paid', 
                payment_date = ?, 
                payment_method = ?, 
                receipt_number = ?,
                notes = CONCAT(IFNULL(notes, ''), ?)
            WHERE tenant_fee_id = ?
        ";

        $update_stmt = $conn->prepare($update_fee_query);
        $update_stmt->bind_param("ssssi", $payment_date, $payment_method, $receipt_number, $admin_note, $tenant_fee_id);
        $update_stmt->execute();
        $update_stmt->close();

        // 4. Record in payments table
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
            ) VALUES (?, ?, ?, 0, ?, ?, ?, 'completed', ?, ?, ?, ?, NOW())
        ";

        $balance = 0;
        $description = "Payment for fee: " . $fee_name . " (" . $fee_code . ") - Marked paid by admin";

        $payment_stmt = $conn->prepare($payment_query);

        $payment_stmt->bind_param(
            "ssdsssssss",
            $tenant_code,
            $apartment_code,
            $amount,
            $payment_date,
            $due_date,
            $payment_method,
            $receipt_number,
            $reference_number,
            $description,
            $admin_id
        );
        $payment_stmt->execute();
        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();

        // 5. If this is a recurring fee, generate next period's fee
        $next_fee_created = false;
        $next_due_date = null;

        if ($fee['is_recurring'] == 1 && $fee['recurrence_period'] !== 'one-time') {
            // Calculate next due date based on recurrence period
            switch (strtolower($fee['recurrence_period'])) {
                case 'monthly':
                    $next_due_date = date('Y-m-d', strtotime($due_date . ' +1 month'));
                    break;
                case 'quarterly':
                    $next_due_date = date('Y-m-d', strtotime($due_date . ' +3 months'));
                    break;
                case 'annually':
                    $next_due_date = date('Y-m-d', strtotime($due_date . ' +1 year'));
                    break;
                default:
                    $next_due_date = date('Y-m-d', strtotime($due_date . ' +1 month'));
            }

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
                // Create next period's fee
                $next_fee_query = "
                    INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ";
                $next_stmt = $conn->prepare($next_fee_query);
                $next_stmt->bind_param("ssids", $tenant_code, $apartment_code, $fee['fee_type_id'], $amount, $next_due_date);
                $next_stmt->execute();
                $next_fee_created = true;
                $next_stmt->close();
            }
            $check_stmt->close();
        }

        createFeeNotification($conn, $tenant_code, $fee_name, $amount, $due_date, 'paid');

        $conn->commit();

        logActivity("Tenant fee {$tenant_fee_id} marked as paid by admin {$admin_id} | Tenant: {$tenant_code} | Receipt: {$receipt_number}");

        json_success([
            'tenant_fee_id' => $tenant_fee_id,
            'receipt_number' => $receipt_number,
            'payment_id' => $payment_id,
            'amount' => $amount,
            'fee_name' => $fee_name,
            'payment_date' => $payment_date,
            'next_fee_created' => $next_fee_created,
            'next_due_date' => $next_due_date,
            'message' => 'Fee marked as paid successfully'
        ], "Fee marked as paid successfully");

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("Error in mark_fee_paid: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>