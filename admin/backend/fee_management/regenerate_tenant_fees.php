<?php
// regenerate_tenant_fees.php - Apply property fees to existing tenants

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

// Generate unique request ID for tracking
$requestId = uniqid('regenerate_fees_', true);
logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== START ==========");
logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Request Time: " . date('Y-m-d H:i:s'));

try {
    // ==================== STEP 1: CHECK AUTHENTICATION ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 1: Checking authentication");
    
    if (!isset($_SESSION['unique_id'])) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR: Not logged in");
        json_error("Not logged in", 401);
    }
    
    $userRole = $_SESSION['role'] ?? '';
    $admin_id = $_SESSION['unique_id'];
    
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Admin ID: {$admin_id}, Role: {$userRole}");
    
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR: Unauthorized access");
        json_error("Unauthorized access", 403);
    }
    
    // ==================== STEP 2: GET INPUT ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 2: Getting input");
    
    $input = json_decode(file_get_contents('php://input'), true);
    $property_code = $_POST['property_code'] ?? $_GET['property_code'] ?? ($input['property_code'] ?? '');
    $action_type = $input['action_type'] ?? 'full_sync';
    
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Property Code: {$property_code}, Action Type: {$action_type}");
    
    if (empty($property_code)) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR: Property code is required");
        json_error("Property code is required", 400);
    }
    
    // ==================== STEP 3: GET PROPERTY NAME ====================
    $property_query = "SELECT name FROM properties WHERE property_code = ?";
    $prop_stmt = $conn->prepare($property_query);
    $prop_stmt->bind_param("s", $property_code);
    $prop_stmt->execute();
    $prop_result = $prop_stmt->get_result();
    $property_name = $prop_result->fetch_assoc()['name'] ?? $property_code;
    $prop_stmt->close();
    
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Property Name: {$property_name}");
    
    // ==================== STEP 4: GET TENANTS ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 3: Fetching tenants for property");
    
    $tenants_query = "
        SELECT 
            t.tenant_code, 
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.apartment_code, 
            t.lease_start_date,
            t.lease_end_date,
            a.apartment_type_id
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        WHERE a.property_code = ? AND t.status = 1
    ";
    $tenants_stmt = $conn->prepare($tenants_query);
    $tenants_stmt->bind_param("s", $property_code);
    $tenants_stmt->execute();
    $tenants_result = $tenants_stmt->get_result();
    
    if ($tenants_result->num_rows === 0) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] No active tenants found");
        json_success([
            'property_code' => $property_code,
            'tenants_processed' => 0,
            'fees_created' => 0,
            'fees_updated' => 0,
            'fees_skipped_paid' => 0,
            'message' => 'No active tenants found for this property'
        ], "No tenants to process");
        exit();
    }
    
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Found " . $tenants_result->num_rows . " tenants");
    
    // ==================== STEP 5: GET FEE CONFIGURATIONS ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 4: Fetching fee configurations");
    
    $fees_query = "
        SELECT 
            pf.*,
            ft.fee_name,
            ft.fee_code,
            ft.is_mandatory,
            ft.is_recurring,
            ft.recurrence_period,
            ft.calculation_type
        FROM property_apartment_type_fees pf
        JOIN fee_types ft ON pf.fee_type_id = ft.fee_type_id
        WHERE pf.property_code = ? 
        AND pf.is_active = 1
    ";
    $fees_stmt = $conn->prepare($fees_query);
    $fees_stmt->bind_param("s", $property_code);
    $fees_stmt->execute();
    $fees_result = $fees_stmt->get_result();
    
    if ($fees_result->num_rows === 0) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] No active fee configurations found");
        json_success([
            'property_code' => $property_code,
            'tenants_processed' => 0,
            'fees_created' => 0,
            'fees_updated' => 0,
            'fees_skipped_paid' => 0,
            'message' => 'No fee configurations found for this property'
        ], "No fees to apply");
        exit();
    }
    
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Found " . $fees_result->num_rows . " fee configurations");
    
    // Collect fee names for summary
    $fee_names = [];
    $fees_result->data_seek(0);
    while ($fee = $fees_result->fetch_assoc()) {
        $fee_names[] = $fee['fee_name'] . " (₦" . number_format($fee['amount'], 2) . ")";
    }
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fee configurations: " . implode(", ", $fee_names));
    
    // ==================== STEP 6: START TRANSACTION ====================
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 5: Starting transaction");
    $conn->begin_transaction();
    
    try {
        $total_fees_created = 0;
        $total_fees_updated = 0;
        $total_fees_skipped_paid = 0;
        $tenants_processed = 0;
        $tenant_summaries = [];
        
        // Prepare statements
        $insert_stmt = $conn->prepare("
            INSERT INTO tenant_fees (
                tenant_code, 
                apartment_code, 
                fee_type_id, 
                amount, 
                due_date, 
                status, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        // ONLY UPDATE AMOUNT - KEEP EXISTING DUE DATE
        $update_stmt = $conn->prepare("
            UPDATE tenant_fees 
            SET amount = ?,
                updated_at = NOW()
            WHERE tenant_fee_id = ?
        ");
        
        // ==================== STEP 7: PROCESS EACH TENANT ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 6: Processing tenants");
        
        while ($tenant = $tenants_result->fetch_assoc()) {
            $tenants_processed++;
            $tenant_fees_created = 0;
            $tenant_fees_updated = 0;
            $tenant_fees_skipped_paid = 0;
            $created_fees_list = [];
            $updated_fees_list = [];
            $skipped_fees_list = [];
            
            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Processing tenant: {$tenant['tenant_code']} ({$tenant['tenant_name']}), Apartment Type: {$tenant['apartment_type_id']}");
            
            // Reset fee results pointer for each tenant
            $fees_result->data_seek(0);
            
            while ($fee = $fees_result->fetch_assoc()) {
                // Check if this fee applies to this tenant's apartment type
                if ($fee['apartment_type_id'] != $tenant['apartment_type_id']) {
                    continue;
                }
                
                logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Processing fee: {$fee['fee_name']} (ID: {$fee['fee_type_id']})");
                
                $amount = (float)$fee['amount'];
                
                // ==================== CHECK EXISTING FEE ====================
                // Only check pending/overdue fees (paid fees should not be modified)
                $check_query = "
                    SELECT tenant_fee_id, amount, due_date, status 
                    FROM tenant_fees 
                    WHERE tenant_code = ? AND fee_type_id = ? 
                    AND status IN ('pending', 'overdue')
                    LIMIT 1
                ";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("si", $tenant['tenant_code'], $fee['fee_type_id']);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($existing) {
                    // ==================== UPDATE EXISTING PENDING/OVERDUE FEE ====================
                    // Only update amount - KEEP EXISTING DUE DATE
                    $changes = [];
                    if ($existing['amount'] != $amount) {
                        $changes[] = "amount: ₦" . number_format($existing['amount'], 2) . " → ₦" . number_format($amount, 2);
                    }
                    
                    if (!empty($changes)) {
                        $update_stmt->bind_param("di", $amount, $existing['tenant_fee_id']);
                        
                        if ($update_stmt->execute()) {
                            $total_fees_updated++;
                            $tenant_fees_updated++;
                            $updated_fees_list[] = $fee['fee_name'] . " (" . implode(", ", $changes) . ")";
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] UPDATED: {$fee['fee_name']} for {$tenant['tenant_name']} - Changes: " . implode(", ", $changes) . " (Due date unchanged: " . date('M d, Y', strtotime($existing['due_date'])) . ")");
                        } else {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR updating {$fee['fee_name']} for {$tenant['tenant_name']}: " . $update_stmt->error);
                        }
                    } else {
                        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED: {$fee['fee_name']} for {$tenant['tenant_name']} - No changes needed");
                    }
                } else {
                    // ==================== CHECK IF PAID FEE EXISTS ====================
                    $paid_check_query = "
                        SELECT tenant_fee_id, status 
                        FROM tenant_fees 
                        WHERE tenant_code = ? AND fee_type_id = ? 
                        AND status = 'paid'
                        LIMIT 1
                    ";
                    $paid_stmt = $conn->prepare($paid_check_query);
                    $paid_stmt->bind_param("si", $tenant['tenant_code'], $fee['fee_type_id']);
                    $paid_stmt->execute();
                    $paid_fee = $paid_stmt->get_result()->fetch_assoc();
                    $paid_stmt->close();
                    
                    if ($paid_fee) {
                        // Fee already paid - skip
                        $total_fees_skipped_paid++;
                        $tenant_fees_skipped_paid++;
                        $skipped_fees_list[] = $fee['fee_name'];
                        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] SKIPPED (Paid): {$fee['fee_name']} for {$tenant['tenant_name']} - Already paid");
                    } else {
                        // ==================== INSERT NEW FEE ====================
                        // Calculate due date using effective_from from fee configuration
                        $start_date = new DateTime($fee['effective_from']);
                        
                        if ($fee['is_recurring']) {
                            switch(strtolower($fee['recurrence_period'])) {
                                case 'monthly':
                                    $due_date = clone $start_date;
                                    $due_date->modify('+1 month');
                                    break;
                                case 'quarterly':
                                    $due_date = clone $start_date;
                                    $due_date->modify('+3 months');
                                    break;
                                case 'annually':
                                    $due_date = clone $start_date;
                                    $due_date->modify('+1 year');
                                    break;
                                default:
                                    $due_date = clone $start_date;
                                    $due_date->modify('+1 month');
                            }
                        } else {
                            // One-time fee - due 7 days after effective_from
                            $due_date = clone $start_date;
                            $due_date->modify('+7 days');
                        }
                        
                        $due_date_str = $due_date->format('Y-m-d');
                        
                        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Calculated due date from effective_from ({$fee['effective_from']}) for {$fee['fee_name']}: {$due_date_str}");
                        
                        $insert_stmt->bind_param(
                            "ssids", 
                            $tenant['tenant_code'], 
                            $tenant['apartment_code'], 
                            $fee['fee_type_id'], 
                            $amount, 
                            $due_date_str
                        );
                        
                        if ($insert_stmt->execute()) {
                            $total_fees_created++;
                            $tenant_fees_created++;
                            $created_fees_list[] = $fee['fee_name'] . " (₦" . number_format($amount, 2) . ")";
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] CREATED: {$fee['fee_name']} for {$tenant['tenant_name']} - Amount: ₦" . number_format($amount, 2) . ", Due: " . date('M d, Y', strtotime($due_date_str)) . " (effective_from: " . date('M d, Y', strtotime($fee['effective_from'])) . ")");
                        } else {
                            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR creating {$fee['fee_name']} for {$tenant['tenant_name']}: " . $insert_stmt->error);
                        }
                    }
                }
            }
            
            // ==================== LOG TENANT SUMMARY ====================
            $tenant_summary = "Tenant: {$tenant['tenant_name']} ({$tenant['tenant_code']})";
            $parts = [];
            if (!empty($created_fees_list)) {
                $parts[] = "CREATED: " . implode(", ", $created_fees_list);
            }
            if (!empty($updated_fees_list)) {
                $parts[] = "UPDATED: " . implode(", ", $updated_fees_list);
            }
            if (!empty($skipped_fees_list)) {
                $parts[] = "SKIPPED (Paid): " . implode(", ", $skipped_fees_list);
            }
            
            if (!empty($parts)) {
                logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] {$tenant_summary} - " . implode(" | ", $parts));
            } else {
                logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] {$tenant_summary} - No changes");
            }
            
            $tenant_summaries[] = [
                'tenant_code' => $tenant['tenant_code'],
                'tenant_name' => $tenant['tenant_name'],
                'fees_created' => $tenant_fees_created,
                'fees_updated' => $tenant_fees_updated,
                'fees_skipped_paid' => $tenant_fees_skipped_paid,
                'created_fees' => $created_fees_list,
                'updated_fees' => $updated_fees_list,
                'skipped_fees' => $skipped_fees_list
            ];
        }
        
        // ==================== STEP 8: LOG SUMMARY ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== SUMMARY ==========");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Property: {$property_name} ({$property_code})");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Tenants Processed: {$tenants_processed}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Created: {$total_fees_created}");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Updated: {$total_fees_updated} (amount only)");
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Fees Skipped (Paid): {$total_fees_skipped_paid}");
        
        // List which fees were affected
        $all_updated_fees = [];
        $all_created_fees = [];
        foreach ($tenant_summaries as $summary) {
            $all_created_fees = array_merge($all_created_fees, $summary['created_fees']);
            $all_updated_fees = array_merge($all_updated_fees, $summary['updated_fees']);
        }
        if (!empty($all_created_fees)) {
            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Created Fees: " . implode(", ", array_unique($all_created_fees)));
        }
        if (!empty($all_updated_fees)) {
            logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Updated Fees: " . implode(", ", array_unique($all_updated_fees)));
        }
        
        // ==================== STEP 9: COMMIT ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Step 8: Committing transaction");
        $conn->commit();
        
        // ==================== STEP 10: RETURN SUCCESS ====================
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== SUCCESS ==========");
        
        $message = "Successfully processed {$tenants_processed} tenant(s): {$total_fees_created} new fee(s) created, {$total_fees_updated} fee(s) updated (amount only), {$total_fees_skipped_paid} paid fee(s) skipped.";
        
        json_success([
            'property_code' => $property_code,
            'property_name' => $property_name,
            'tenants_processed' => $tenants_processed,
            'fees_created' => $total_fees_created,
            'fees_updated' => $total_fees_updated,
            'fees_skipped_paid' => $total_fees_skipped_paid,
            'details' => $tenant_summaries,
            'message' => $message
        ], $message);
        
    } catch (Exception $e) {
        logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ERROR in transaction: " . $e->getMessage());
        $conn->rollback();
        throw $e;
    }
    
    // Clean up statements
    if (isset($insert_stmt)) $insert_stmt->close();
    if (isset($update_stmt)) $update_stmt->close();
    if (isset($tenants_stmt)) $tenants_stmt->close();
    if (isset($fees_stmt)) $fees_stmt->close();
    
} catch (Exception $e) {
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] ========== ERROR ==========");
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error Message: " . $e->getMessage());
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error Code: " . $e->getCode());
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error File: " . $e->getFile());
    logActivity("[REGENERATE_TENANT_FEES] [ID:{$requestId}] Error Line: " . $e->getLine());
    
    json_error($e->getMessage(), 500);
}
?>