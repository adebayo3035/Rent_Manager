<?php
// generate_tenant_fees.php - Function to generate fees for a tenant

function generateTenantFees($conn, $tenant_code, $apartment_code, $property_code) {
    try {
        // Get apartment type from apartment
        $apt_query = "SELECT apartment_type_id FROM apartments WHERE apartment_code = ?";
        $apt_stmt = $conn->prepare($apt_query);
        $apt_stmt->bind_param("s", $apartment_code);
        $apt_stmt->execute();
        $apt_result = $apt_stmt->get_result();
        $apartment = $apt_result->fetch_assoc();
        $apt_stmt->close();
        
        if (!$apartment) {
            logActivity("ERROR: Apartment not found for code: {$apartment_code}");
            return false;
        }
        
        $apartment_type_id = $apartment['apartment_type_id'];
        logActivity("Apartment type ID: {$apartment_type_id}");
        
        // Get applicable fees for this property and apartment type
        $fee_query = "
            SELECT pf.*, ft.fee_name, ft.calculation_type, ft.is_recurring, ft.recurrence_period, ft.is_mandatory
            FROM property_apartment_type_fees pf
            JOIN fee_types ft ON pf.fee_type_id = ft.fee_type_id
            WHERE pf.property_code = ? 
            AND pf.apartment_type_id = ?
            AND pf.is_active = 1
        ";
        $fee_stmt = $conn->prepare($fee_query);
        $fee_stmt->bind_param("si", $property_code, $apartment_type_id);
        $fee_stmt->execute();
        $fee_result = $fee_stmt->get_result();
        
        // Get tenant's lease start date
        $lease_query = "SELECT lease_start_date, lease_end_date FROM tenants WHERE tenant_code = ?";
        $lease_stmt = $conn->prepare($lease_query);
        $lease_stmt->bind_param("s", $tenant_code);
        $lease_stmt->execute();
        $lease_result = $lease_stmt->get_result();
        $tenant = $lease_result->fetch_assoc();
        $lease_stmt->close();
        
        if (!$tenant) {
            logActivity("ERROR: Tenant not found for code: {$tenant_code}");
            return false;
        }
        
        $start_date = $tenant['lease_start_date'];
        $lease_end = $tenant['lease_end_date'];
        
        logActivity("Lease period: {$start_date} to {$lease_end}");
        
        // Prepare insert statement
        $insert_query = "INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status, created_at) 
                         VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
        $insert_stmt = $conn->prepare($insert_query);
        
        $fees_generated = 0;
        
        while ($fee = $fee_result->fetch_assoc()) {
            $amount = (float)$fee['amount'];
            logActivity("Processing fee: {$fee['fee_name']} - Amount: {$amount} - Recurring: " . ($fee['is_recurring'] ? 'Yes' : 'No'));
            
            if ($fee['is_recurring']) {
                // Generate fees for the lease duration
                $current_date = new DateTime($start_date);
                $end_date = new DateTime($lease_end);
                $period_count = 0;
                
                while ($current_date <= $end_date) {
                    $due_date = clone $current_date;
                    // For monthly fees, due date is 7 days after period start
                    $due_date->modify('+7 days');
                    
                    $insert_stmt->bind_param("ssids", $tenant_code, $apartment_code, $fee['fee_type_id'], $amount, $due_date->format('Y-m-d'));
                    if ($insert_stmt->execute()) {
                        $fees_generated++;
                        $new_period_count = $period_count + 1;
                        logActivity("  Generated recurring fee #{$new_period_count} due: " . $due_date->format('Y-m-d'));
                    } else {
                        logActivity("  ERROR inserting recurring fee: " . $insert_stmt->error);
                    }
                    
                    // Increment based on recurrence period
                    switch(strtolower($fee['recurrence_period'])) {
                        case 'monthly':
                            $current_date->modify('+1 month');
                            break;
                        case 'quarterly':
                            $current_date->modify('+3 months');
                            break;
                        case 'annually':
                            $current_date->modify('+1 year');
                            break;
                        default:
                            $current_date->modify('+1 month');
                    }
                    $period_count++;
                    
                    // Safety break to prevent infinite loop
                    if ($period_count > 100) break;
                }
            } else {
                // One-time fee - due 7 days after lease start
                $due_date = new DateTime($start_date);
                $due_date->modify('+7 days');
                
                $insert_stmt->bind_param("ssids", $tenant_code, $apartment_code, $fee['fee_type_id'], $amount, $due_date->format('Y-m-d'));
                if ($insert_stmt->execute()) {
                    $fees_generated++;
                    logActivity("  Generated one-time fee due: " . $due_date->format('Y-m-d'));
                } else {
                    logActivity("  ERROR inserting one-time fee: " . $insert_stmt->error);
                }
            }
        }
        
        $insert_stmt->close();
        $fee_stmt->close();
        
        logActivity("Total tenant fees generated: {$fees_generated}");
        return $fees_generated > 0;
        
    } catch (Exception $e) {
        logActivity("Error generating tenant fees: " . $e->getMessage());
        logActivity("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}
?>