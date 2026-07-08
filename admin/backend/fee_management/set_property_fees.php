<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION))
    session_start();
rateLimiter();

// Generate unique request ID for tracking
$requestId = uniqid('set_property_fees_', true);
logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ========== START ==========");
logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Step 1: Checking authentication");

    if (!isset($_SESSION['unique_id'])) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR: Not logged in");
        json_error("Not logged in", 401);
    }

    $user_id = $_SESSION['unique_id'];
    $user_role = $_SESSION['role'] ?? '';

    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] User: {$user_id}, Role: {$user_role}");

    // Check authorization - Super Admin or Admin
    if (!in_array($user_role, ['Super Admin', 'Admin'])) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR: Unauthorized access - Role: {$user_role}");
        json_error("Unauthorized access", 403);
    }

    // ==================== STEP 2: GET INPUT DATA ====================
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Step 2: Getting input data");

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR: Invalid input data");
        json_error("Invalid input data", 400);
    }

    $property_code = $input['property_code'] ?? '';
    $fees = $input['fees'] ?? [];
    $effective_from = $input['effective_from'] ?? date('Y-m-d');
    $action_type = $input['action_type'] ?? 'bulk'; // 'bulk' or 'single'

    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Property Code: {$property_code}, Action Type: {$action_type}");
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Fees data: " . json_encode($fees));

    // ==================== STEP 3: VALIDATE INPUT ====================
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Step 3: Validating input");

    if (empty($property_code)) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR: Property code required");
        json_error("Property code required", 400);
    }

    if (empty($fees)) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR: Fees data required");
        json_error("Fees data required", 400);
    }

    // Validate effective_from format
    if (!strtotime($effective_from)) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR: Invalid effective date: {$effective_from}");
        json_error("Invalid effective date", 400);
    }

    // ==================== STEP 4: START TRANSACTION ====================
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Step 4: Starting database transaction");
    $conn->begin_transaction();

    try {
        $total_fees_processed = 0;
        $fees_updated = 0;
        $fees_inserted = 0;

        // ==================== PROCESS FEES ====================
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Step 5: Processing fees");

        foreach ($fees as $apartment_type_id => $fee_types) {
            logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Processing Apartment Type ID: {$apartment_type_id}");

            foreach ($fee_types as $fee_type_id => $amount) {
                $amount = floatval($amount);
                logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Fee Type ID: {$fee_type_id}, Amount: {$amount}");

                if ($amount <= 0) {
                    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Skipping fee type {$fee_type_id} - amount is zero or negative");
                    continue;
                }

                // Check if this fee configuration already exists
                $check_query = "
            SELECT fee_id, amount, is_active 
            FROM property_apartment_type_fees 
            WHERE property_code = ? 
            AND apartment_type_id = ? 
            AND fee_type_id = ?
            AND is_active = 1
            LIMIT 1
        ";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("sii", $property_code, $apartment_type_id, $fee_type_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    // ==================== UPDATE EXISTING FEE ====================
                    $existing = $check_result->fetch_assoc();
                    $fee_id = $existing['fee_id'];

                    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Updating existing fee - Config ID: {$fee_id}");

                    $update_query = "
                UPDATE property_apartment_type_fees 
                SET amount = ?,
                    effective_from = ?,
                    updated_at = NOW(),
                    updated_by = ?
                WHERE fee_id = ?
            ";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("dsii", $amount, $effective_from, $user_id, $fee_id);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $fees_updated++;
                    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Fee updated - Config ID: {$fee_id}");

                } else {
                    // ==================== INSERT NEW FEE ====================
                    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Inserting new fee");

                    // Check if there's an inactive record we can reactivate
                    $inactive_check = "
                SELECT fee_id 
                FROM property_apartment_type_fees 
                WHERE property_code = ? 
                AND apartment_type_id = ? 
                AND fee_type_id = ?
                AND is_active = 0
                LIMIT 1
            ";
                    $inactive_stmt = $conn->prepare($inactive_check);
                    $inactive_stmt->bind_param("sii", $property_code, $apartment_type_id, $fee_type_id);
                    $inactive_stmt->execute();
                    $inactive_result = $inactive_stmt->get_result();

                    if ($inactive_result->num_rows > 0) {
                        // Reactivate existing inactive record
                        $inactive_data = $inactive_result->fetch_assoc();
                        $fee_id = $inactive_data['fee_id'];

                        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Reactivating inactive fee - Config ID: {$fee_id}");

                        $reactivate_query = "
                    UPDATE property_apartment_type_fees 
                    SET amount = ?,
                        effective_from = ?,
                        is_active = 1,
                        updated_at = NOW(),
                        updated_by = ?
                    WHERE fee_id = ?
                ";
                        $reactivate_stmt = $conn->prepare($reactivate_query);
                        $reactivate_stmt->bind_param("dsii", $amount, $effective_from, $user_id, $fee_id);
                        $reactivate_stmt->execute();
                        $reactivate_stmt->close();

                        $fees_updated++;
                    } else {
                        // Insert brand new fee - FIXED bind types
                        $insert_query = "
                    INSERT INTO property_apartment_type_fees 
                    (property_code, apartment_type_id, fee_type_id, amount, effective_from, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ";
                        $insert_stmt = $conn->prepare($insert_query);
                        // FIXED: "siids" instead of "siiids" (removed one 'i' because amount is 'd')
                        $insert_stmt->bind_param("siidsi", $property_code, $apartment_type_id, $fee_type_id, $amount, $effective_from, $user_id);
                        $insert_stmt->execute();
                        $insert_stmt->close();

                        $fees_inserted++;
                        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] New fee inserted");
                    }
                    $inactive_stmt->close();
                }
                $check_stmt->close();
                $total_fees_processed++;
            }
        }

        // ==================== STEP 6: COMMIT TRANSACTION ====================
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Step 6: Committing transaction");
        $conn->commit();

        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Transaction committed successfully");

        // ==================== STEP 7: LOG ACTIVITY ====================
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Property fees processed for: {$property_code}");
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Total processed: {$total_fees_processed}, Updated: {$fees_updated}, Inserted: {$fees_inserted}");

        // ==================== STEP 8: RETURN SUCCESS RESPONSE ====================
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ========== SUCCESS ==========");

        json_success([
            'property_code' => $property_code,
            'total_processed' => $total_fees_processed,
            'fees_updated' => $fees_updated,
            'fees_inserted' => $fees_inserted,
            'effective_from' => $effective_from
        ], "Property fees saved successfully");

    } catch (Exception $e) {
        logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[SET_PROPERTY_FEES] [ID:{$requestId}] Error Line: " . $e->getLine());

    json_error($e->getMessage(), 500);
}
?>