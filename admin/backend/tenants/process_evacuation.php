<?php
// process_evacuation.php - Admin processes final move-out

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';

$auth = requireAuth([
    'method' => 'POST',
    'roles' => ['Super Admin', 'Admin']
]);

$adminId = $auth['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$request_id = $input['request_id'] ?? null;
$deductions = $input['deductions'] ?? [];
$actual_move_out_date = $input['actual_move_out_date'] ?? date('Y-m-d');

if (!$request_id) {
    json_error("Request ID is required", 400);
}

$conn->begin_transaction();

try {
    // Get approved request
    $query = "
        SELECT er.*, t.rent_balance, a.security_deposit, a.apartment_code, a.rent_amount
        FROM evacuation_requests er
        JOIN tenants t ON er.tenant_code = t.tenant_code
        JOIN apartments a ON er.apartment_code = a.apartment_code
        WHERE er.request_id = ? AND er.status = 'approved'
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$request) {
        throw new Exception("Approved evacuation request not found", 404);
    }
    
    // Calculate final settlement
    $security_deposit = (float)$request['security_deposit'];
    $outstanding_balance = (float)$request['outstanding_amount'];
    $early_termination_fee = (float)$request['early_termination_fee'];
    $total_deductions = array_sum(array_column($deductions, 'amount'));
    
    $final_settlement = $security_deposit - $outstanding_balance - $early_termination_fee - $total_deductions;
    $security_deposit_refund = $final_settlement > 0 ? $final_settlement : 0;
    
    // Update evacuation request
    $updateRequest = $conn->prepare("
        UPDATE evacuation_requests 
        SET status = 'completed',
            final_settlement_amount = ?,
            security_deposit_refund = ?,
            processed_by = ?,
            processed_at = NOW()
        WHERE request_id = ?
    ");
    $updateRequest->bind_param("ddss", $final_settlement, $security_deposit_refund, $adminId, $request_id);
    $updateRequest->execute();
    $updateRequest->close();
    
    // Insert deductions
    if (!empty($deductions)) {
        $insertDeduction = $conn->prepare("
            INSERT INTO evacuation_deductions (request_id, tenant_code, deduction_type, amount, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($deductions as $deduction) {
            $insertDeduction->bind_param("sssds", $request_id, $request['tenant_code'], $deduction['type'], $deduction['amount'], $deduction['description']);
            $insertDeduction->execute();
        }
        $insertDeduction->close();
    }
    
    // Update apartment occupancy
    $updateApartment = $conn->prepare("
        UPDATE apartments 
        SET occupancy_status = 'NOT OCCUPIED', 
            occupied_by = NULL,
            updated_at = NOW()
        WHERE apartment_code = ?
    ");
    $updateApartment->bind_param("s", $request['apartment_code']);
    $updateApartment->execute();
    $updateApartment->close();
    
    // Update property occupied count
    $propertyCodeQuery = "SELECT property_code FROM apartments WHERE apartment_code = ?";
    $propStmt = $conn->prepare($propertyCodeQuery);
    $propStmt->bind_param("s", $request['apartment_code']);
    $propStmt->execute();
    $propertyCode = $propStmt->get_result()->fetch_assoc()['property_code'];
    $propStmt->close();
    
    $updateProperty = $conn->prepare("
        UPDATE properties 
        SET occupied_apartments = occupied_apartments - 1,
            updated_at = NOW()
        WHERE property_code = ?
    ");
    $updateProperty->bind_param("s", $propertyCode);
    $updateProperty->execute();
    $updateProperty->close();
    
    // Update tenant status
    $updateTenant = $conn->prepare("
        UPDATE tenants 
        SET status = 0,
            evacuation_status = 'evacuated',
            move_out_date = ?,
            last_updated_at = NOW()
        WHERE tenant_code = ?
    ");
    $updateTenant->bind_param("ss", $actual_move_out_date, $request['tenant_code']);
    $updateTenant->execute();
    $updateTenant->close();
    
    $conn->commit();
    
    json_success("Evacuation completed successfully", [
        'request_id' => $request_id,
        'security_deposit' => $security_deposit,
        'total_deductions' => $total_deductions,
        'early_termination_fee' => $early_termination_fee,
        'outstanding_balance' => $outstanding_balance,
        'final_settlement' => $final_settlement,
        'security_deposit_refund' => $security_deposit_refund,
        'message' => $final_settlement > 0 ? "Tenant is due a refund of ₦" . number_format($final_settlement, 2) : 
                    ($final_settlement < 0 ? "Tenant owes ₦" . number_format(abs($final_settlement), 2) : "Settlement complete with zero balance")
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    json_error($e->getMessage(), 500);
}
?>
