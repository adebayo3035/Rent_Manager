<?php
// regenerate_tenant_fees.php - Apply property fees to existing tenants

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
session_start();

logActivity("========== REGENERATE TENANT FEES START ==========");

try {
    // Check authentication and admin role
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $userRole = $_SESSION['role'] ?? '';
    if (!in_array($userRole, ['Super Admin', 'Admin'])) {
        json_error("Unauthorized access", 403);
    }
    
    // Get input - support both GET and POST
    $input = json_decode(file_get_contents('php://input'), true);
    $property_code = $_POST['property_code'] ?? $_GET['property_code'] ?? ($input['property_code'] ?? '');
    
    if (empty($property_code)) {
        json_error("Property code is required", 400);
    }
    
    logActivity("Processing property: {$property_code}");
    
    // Get all active tenants for this property
    $tenants_query = "
        SELECT 
            t.tenant_code, 
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
        logActivity("No active tenants found for property: {$property_code}");
        json_success(['fees_created' => 0, 'message' => 'No active tenants found for this property'], "No tenants to process");
        exit();
    }
    
    // Get active fee configurations for this property
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
        logActivity("No active fee configurations found for property: {$property_code}");
        json_success(['fees_created' => 0, 'message' => 'No fee configurations found for this property'], "No fees to apply");
        exit();
    }
    
    $total_fees_created = 0;
    $tenants_processed = 0;
    $details = [];
    
    // Prepare insert statement for tenant fees
    $insert_query = "
        INSERT INTO tenant_fees (
            tenant_code, 
            apartment_code, 
            fee_type_id, 
            amount, 
            due_date, 
            status, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ";
    $insert_stmt = $conn->prepare($insert_query);
    
    // Process each tenant
    while ($tenant = $tenants_result->fetch_assoc()) {
        $tenants_processed++;
        $tenant_fees_created = 0;
        $tenant_fees_skipped = [];
        
        logActivity("Processing tenant: {$tenant['tenant_code']}, Apartment Type: {$tenant['apartment_type_id']}");
        
        // Reset fee results pointer for each tenant
        $fees_result->data_seek(0);
        
        while ($fee = $fees_result->fetch_assoc()) {
            // Check if this fee applies to this tenant's apartment type
            if ($fee['apartment_type_id'] != $tenant['apartment_type_id']) {
                continue;
            }
            
            // Check if this fee already exists for this tenant
            $check_query = "
                SELECT tenant_fee_id FROM tenant_fees 
                WHERE tenant_code = ? AND fee_type_id = ? 
                AND status != 'paid'
                LIMIT 1
            ";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $tenant['tenant_code'], $fee['fee_type_id']);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if ($existing) {
                $tenant_fees_skipped[] = $fee['fee_name'];
                logActivity("  Fee '{$fee['fee_name']}' already exists for tenant, skipping");
                continue;
            }
            
            // Calculate due date
            $due_date = new DateTime($tenant['lease_start_date']);
            
            if ($fee['is_recurring']) {
                // For recurring fees, just create one for now (next due date)
                switch(strtolower($fee['recurrence_period'])) {
                    case 'monthly':
                        $due_date->modify('+1 month');
                        break;
                    case 'quarterly':
                        $due_date->modify('+3 months');
                        break;
                    case 'annually':
                        $due_date->modify('+1 year');
                        break;
                    default:
                        $due_date->modify('+1 month');
                }
            } else {
                // One-time fee - due 7 days after lease start
                $due_date->modify('+7 days');
            }
            
            $amount = (float)$fee['amount'];
            $due_date_str = $due_date->format('Y-m-d');
            
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
                logActivity("  Created fee: {$fee['fee_name']} - ₦{$amount} due {$due_date_str}");
            } else {
                logActivity("  ERROR creating fee: {$fee['fee_name']} - " . $insert_stmt->error);
            }
        }
        
        $details[] = [
            'tenant_code' => $tenant['tenant_code'],
            'tenant_name' => getTenantName($conn, $tenant['tenant_code']),
            'fees_generated' => $tenant_fees_created,
            'fees_skipped' => $tenant_fees_skipped
        ];
    }
    
    $insert_stmt->close();
    $tenants_stmt->close();
    $fees_stmt->close();
    
    logActivity("Regeneration complete. Tenants processed: {$tenants_processed}, Fees created: {$total_fees_created}");
    
    json_success([
        'property_code' => $property_code,
        'tenants_processed' => $tenants_processed,
        'fees_created' => $total_fees_created,
        'details' => $details
    ], "Successfully created {$total_fees_created} fee(s) for {$tenants_processed} tenant(s)");
    
} catch (Exception $e) {
    logActivity("ERROR in regenerate_tenant_fees: " . $e->getMessage());
    logActivity("Stack trace: " . $e->getTraceAsString());
    json_error($e->getMessage(), 500);
}

// Helper function to get tenant name
function getTenantName($conn, $tenant_code) {
    $query = "SELECT CONCAT(firstname, ' ', lastname) as name FROM tenants WHERE tenant_code = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $name = $result->fetch_assoc()['name'] ?? $tenant_code;
    $stmt->close();
    return $name;
}
?>