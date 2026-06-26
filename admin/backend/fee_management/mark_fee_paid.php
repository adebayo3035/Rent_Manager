<?php
// mark_fee_paid.php - Admin marks a tenant fee as paid

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('mark_fee_paid_', true);
logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ========== START ==========");
logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));
logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Request Method: " . $_SERVER['REQUEST_METHOD']);

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id'])) {
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Not logged in");
        json_error("Not logged in", 401);
    }

    $userRole = $_SESSION['role'] ?? '';
    $admin_id = $_SESSION['unique_id'];
    
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$userRole}");
    
    // Check authorization - Super Admin or Admin
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Unauthorized access - Role: {$userRole}");
        json_error("Unauthorized access", 403);
    }
    
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Authorization passed");

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 2: Getting input data");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }
    
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Input keys: " . implode(', ', array_keys($input)));
    
    $tenant_fee_id = isset($input['tenant_fee_id']) ? (int) $input['tenant_fee_id'] : 0;
    $notes = isset($input['notes']) ? trim($input['notes']) : '';
    
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Tenant Fee ID: {$tenant_fee_id}");
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Notes: " . ($notes ? substr($notes, 0, 50) . '...' : 'None'));

    // ==================== STEP 3: VALIDATE INPUT ====================
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 3: Validating input");
    
    if ($tenant_fee_id <= 0) {
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Invalid tenant fee ID: {$tenant_fee_id}");
        json_error("Invalid tenant fee ID", 400);
    }
    
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Input validation passed");

    // ==================== STEP 4: START TRANSACTION ====================
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 4: Starting database transaction");
    $conn->begin_transaction();
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Transaction started");

    try {
        // ==================== STEP 5: GET FEE DETAILS ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 5: Fetching fee details");
        
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

        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Executing fee details query for ID: {$tenant_fee_id}");
        
        $fee_stmt = $conn->prepare($fee_query);
        if (!$fee_stmt) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to prepare fee query: " . $conn->error);
            throw new Exception("Database prepare error", 500);
        }
        
        $fee_stmt->bind_param("i", $tenant_fee_id);
        
        if (!$fee_stmt->execute()) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to execute fee query: " . $fee_stmt->error);
            throw new Exception("Database execute error", 500);
        }
        
        $fee = $fee_stmt->get_result()->fetch_assoc();
        $fee_stmt->close();
        
        if (!$fee) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Fee not found - ID: {$tenant_fee_id}");
            throw new Exception("Fee not found", 404);
        }
        
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Fee found: {$fee['fee_name']} - Amount: {$fee['amount']} - Status: {$fee['status']}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Tenant: {$fee['tenant_code']} - {$fee['firstname']} {$fee['lastname']}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Property: {$fee['property_name']} - Apartment: {$fee['apartment_number']}");

        // ==================== STEP 6: VALIDATE FEE STATUS ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 6: Validating fee status");
        
        if ($fee['status'] === 'paid') {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Fee already paid - ID: {$tenant_fee_id}");
            throw new Exception("This fee has already been paid", 400);
        }
        
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Fee status validation passed");

        // ==================== STEP 7: GENERATE UNIQUE IDENTIFIERS ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 7: Generating unique identifiers");
        
        $amount = (float) $fee['amount'];
        $tenant_code = $fee['tenant_code'];
        $apartment_code = $fee['apartment_code'];
        $due_date = $fee['due_date'];
        $fee_name = $fee['fee_name'];
        $fee_code = $fee['fee_code'];
        
        $receipt_number = 'RCT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $reference_number = 'ADMIN-' . date('Ymd') . '-' . $admin_id . '-' . $tenant_fee_id;
        $payment_method = 'admin_marked';
        $payment_date = date('Y-m-d H:i:s');
        $transaction_id = 'TXN-' . date('Ymd') . '-' . time() . '-' . rand(1000, 9999);
        
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Generated identifiers:");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Receipt Number: {$receipt_number}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Reference Number: {$reference_number}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Transaction ID: {$transaction_id}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Payment Date: {$payment_date}");

        // ==================== STEP 8: UPDATE TENANT_FEES TABLE ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 8: Updating tenant_fees table");
        
        $admin_note = "Marked as paid by Admin on " . $payment_date . ". Receipt: " . $receipt_number;
        if ($notes) {
            $admin_note .= " | Admin note: " . $notes;
        }
        
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Admin note: " . substr($admin_note, 0, 100) . "...");

        $update_fee_query = "
            UPDATE tenant_fees 
            SET status = 'paid', 
                payment_date = ?, 
                payment_method = ?, 
                receipt_number = ?,
                payment_id = ?,
                notes = CONCAT(IFNULL(notes, ''), ?)
            WHERE tenant_fee_id = ?
        ";

        $update_stmt = $conn->prepare($update_fee_query);
        if (!$update_stmt) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to prepare update query: " . $conn->error);
            throw new Exception("Database prepare error", 500);
        }
        
        $update_stmt->bind_param("sssssi", $payment_date, $payment_method, $receipt_number, $transaction_id, $admin_note, $tenant_fee_id);
        
        if (!$update_stmt->execute()) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to execute update: " . $update_stmt->error);
            throw new Exception("Database execute error", 500);
        }
        
        $affected_rows = $update_stmt->affected_rows;
        $update_stmt->close();
        
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] tenant_fees updated - Affected rows: {$affected_rows}");

        // ==================== STEP 9: RECORD IN PAYMENTS TABLE ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 9: Recording in payments table");
        
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

        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Payment details:");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Tenant: {$tenant_code}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Amount: {$amount}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}]   - Due Date: {$due_date}");

        $payment_stmt = $conn->prepare($payment_query);
        if (!$payment_stmt) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to prepare payment insert: " . $conn->error);
            throw new Exception("Database prepare error", 500);
        }

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
        
        if (!$payment_stmt->execute()) {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to execute payment insert: " . $payment_stmt->error);
            throw new Exception("Database execute error", 500);
        }
        
        $payment_id = $payment_stmt->insert_id;
        $payment_stmt->close();
        
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Payment recorded - Payment ID: {$payment_id}");

        // ==================== STEP 10: GENERATE NEXT RECURRING FEE ====================
        $next_fee_created = false;
        $next_due_date = null;
        
        if ($fee['is_recurring'] == 1 && $fee['recurrence_period'] !== 'one-time') {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 10: Processing recurring fee generation");
            
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
            
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Calculated next due date: {$next_due_date}");
            
            // Check if next fee already exists
            $check_next_query = "
                SELECT tenant_fee_id FROM tenant_fees 
                WHERE tenant_code = ? AND fee_type_id = ? AND due_date = ?
            ";
            $check_stmt = $conn->prepare($check_next_query);
            if (!$check_stmt) {
                logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to prepare check query: " . $conn->error);
                throw new Exception("Database prepare error", 500);
            }
            
            $check_stmt->bind_param("sis", $tenant_code, $fee['fee_type_id'], $next_due_date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                logActivity("[MARK_FEE_PAID] [ID:{$requestId}] No existing next fee found. Creating new one.");
                
                // Create next period's fee
                $next_fee_query = "
                    INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ";
                $next_stmt = $conn->prepare($next_fee_query);
                if (!$next_stmt) {
                    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to prepare next fee insert: " . $conn->error);
                    throw new Exception("Database prepare error", 500);
                }
                
                $next_stmt->bind_param("ssids", $tenant_code, $apartment_code, $fee['fee_type_id'], $amount, $next_due_date);
                
                if (!$next_stmt->execute()) {
                    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR: Failed to execute next fee insert: " . $next_stmt->error);
                    throw new Exception("Database execute error", 500);
                }
                
                $next_fee_created = true;
                $next_stmt->close();
                
                logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Next recurring fee created - Due date: {$next_due_date}");
            } else {
                logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Next fee already exists - Skipping creation");
            }
            $check_stmt->close();
        } else {
            logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Not recurring or one-time fee - Skipping recurring generation");
        }

        // ==================== STEP 11: CREATE NOTIFICATION ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 11: Creating notification");
        
        createFeeNotification($conn, $tenant_code, $fee_name, $amount, $due_date, 'paid');
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Notification created for tenant: {$tenant_code}");

        // ==================== STEP 12: COMMIT TRANSACTION ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Step 12: Committing transaction");
        $conn->commit();
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 13: LOG ACTIVITY ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Admin {$admin_id} marked fee {$tenant_fee_id} as paid");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Tenant: {$tenant_code} - Fee: {$fee_name} - Amount: {$amount} - Receipt: {$receipt_number}");
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Next fee created: " . ($next_fee_created ? "Yes - Due: {$next_due_date}" : "No"));

        // ==================== STEP 14: RETURN SUCCESS RESPONSE ====================
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ========== SUCCESS ==========");
        
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
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Rolling back transaction");
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[MARK_FEE_PAID] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}
?>