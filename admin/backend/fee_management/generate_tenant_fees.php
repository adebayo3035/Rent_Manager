<?php
// Call this when assigning a tenant to an apartment
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
            throw new Exception("Apartment not found");
        }
        
        $apartment_type_id = $apartment['apartment_type_id'];
        
        // Get applicable fees for this property and apartment type
        $fee_query = "
            SELECT pf.*, ft.fee_name, ft.calculation_type, ft.is_recurring, ft.recurrence_period
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
        $lease_query = "SELECT lease_start_date FROM tenants WHERE tenant_code = ?";
        $lease_stmt = $conn->prepare($lease_query);
        $lease_stmt->bind_param("s", $tenant_code);
        $lease_stmt->execute();
        $lease_result = $lease_stmt->get_result();
        $tenant = $lease_result->fetch_assoc();
        $lease_stmt->close();
        
        $start_date = $tenant['lease_start_date'];
        
        // Generate fee entries for tenant
        $insert_query = "INSERT INTO tenant_fees (tenant_code, apartment_code, fee_type_id, amount, due_date, status) 
                         VALUES (?, ?, ?, ?, ?, 'pending')";
        $insert_stmt = $conn->prepare($insert_query);
        
        while ($fee = $fee_result->fetch_assoc()) {
            $amount = $fee['amount'];
            $due_date = $start_date;
            
            // For recurring fees, we may want to generate multiple entries
            if ($fee['is_recurring']) {
                // Generate fees for the lease duration
                $lease_end = $tenant['lease_end_date'];
                $current_date = new DateTime($start_date);
                $end_date = new DateTime($lease_end);
                
                while ($current_date <= $end_date) {
                    $insert_stmt->bind_param("ssids", $tenant_code, $apartment_code, $fee['fee_type_id'], $amount, $current_date->format('Y-m-d'));
                    $insert_stmt->execute();
                    
                    // Increment based on recurrence period
                    switch($fee['recurrence_period']) {
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
                }
            } else {
                // One-time fee
                $insert_stmt->bind_param("ssids", $tenant_code, $apartment_code, $fee['fee_type_id'], $amount, $due_date);
                $insert_stmt->execute();
            }
        }
        
        $insert_stmt->close();
        $fee_stmt->close();
        
        return true;
        
    } catch (Exception $e) {
        logActivity("Error generating tenant fees: " . $e->getMessage());
        return false;
    }
}
?>