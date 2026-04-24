<?php
// request_evacuation.php - Tenant submits evacuation request

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

logActivity("========== REQUEST EVACUATION - START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }
    
    $tenant_code = $_SESSION['tenant_code'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $requested_move_out_date = $input['move_out_date'] ?? null;
    $reason = $input['reason'] ?? '';
    $notes = $input['notes'] ?? '';
    
    if (!$requested_move_out_date || !$reason) {
        json_error("Move-out date and reason are required", 400);
    }
    
    $conn->begin_transaction();
    
    // 1. Get tenant and apartment details
    $tenantQuery = "
        SELECT t.*, a.apartment_code, a.rent_amount, a.security_deposit, 
               p.property_code, p.name as property_name
        FROM tenants t
        JOIN apartments a ON t.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE t.tenant_code = ? AND t.status = 1
    ";
    $stmt = $conn->prepare($tenantQuery);
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$tenant) {
        throw new Exception("Tenant information not found", 404);
    }
    
    // 2. Check if tenant can request evacuation
    $can_request = true;
    $validation_messages = [];
    
    // Check for pending verification payments
    $pendingQuery = "SELECT COUNT(*) as count FROM rent_payment_tracker 
                     WHERE tenant_code = ? AND status = 'pending_verification'";
    $pendingStmt = $conn->prepare($pendingQuery);
    $pendingStmt->bind_param("s", $tenant_code);
    $pendingStmt->execute();
    $pendingCount = $pendingStmt->get_result()->fetch_assoc()['count'];
    $pendingStmt->close();
    
    if ($pendingCount > 0) {
        $can_request = false;
        $validation_messages[] = "You have a pending payment waiting for verification.";
    }
    
    // Check for outstanding balance
    $balanceQuery = "SELECT rent_balance FROM tenants WHERE tenant_code = ?";
    $balanceStmt = $conn->prepare($balanceQuery);
    $balanceStmt->bind_param("s", $tenant_code);
    $balanceStmt->execute();
    $balance = $balanceStmt->get_result()->fetch_assoc()['rent_balance'];
    $balanceStmt->close();
    
    if ($balance > 0) {
        $can_request = false;
        $validation_messages[] = "You have an outstanding balance of ₦" . number_format($balance, 2);
    }
    
    // Check for existing pending request
    $existingQuery = "SELECT COUNT(*) as count FROM evacuation_requests 
                      WHERE tenant_code = ? AND status IN ('pending_review', 'approved')";
    $existingStmt = $conn->prepare($existingQuery);
    $existingStmt->bind_param("s", $tenant_code);
    $existingStmt->execute();
    $existingCount = $existingStmt->get_result()->fetch_assoc()['count'];
    $existingStmt->close();
    
    if ($existingCount > 0) {
        $can_request = false;
        $validation_messages[] = "You already have a pending or approved evacuation request.";
    }
    
    if (!$can_request) {
        throw new Exception(implode(" ", $validation_messages), 400);
    }
    
    // 3. Calculate early termination fee if applicable
    $lease_end_date = new DateTime($tenant['lease_end_date']);
    $move_out_date = new DateTime($requested_move_out_date);
    $early_termination_fee = 0;
    $early_termination_applicable = false;
    
    if ($move_out_date < $lease_end_date) {
        $early_termination_applicable = true;
        $months_diff = $lease_end_date->diff($move_out_date);
        $months_remaining = ($months_diff->y * 12) + $months_diff->m;
        $monthly_rent = (float)$tenant['rent_amount'] / 12;
        $early_termination_fee = round($monthly_rent * $months_remaining * 0.5, 2);
    }
    
    // 4. Generate request ID
    $request_id = 'EVAC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
    
    // 5. Create evacuation request
    $insertQuery = "
        INSERT INTO evacuation_requests (
            request_id, tenant_code, apartment_code, requested_move_out_date,
            reason, notes, has_outstanding_balance, outstanding_amount,
            early_termination_fee_applicable, early_termination_fee, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review')
    ";
    
    $outstanding_amount = $balance;
    $has_outstanding = $balance > 0;
    
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param(
        "sssssssidd",
        $request_id, $tenant_code, $tenant['apartment_code'],
        $requested_move_out_date, $reason, $notes,
        $has_outstanding, $outstanding_amount,
        $early_termination_applicable, $early_termination_fee
    );
    $insertStmt->execute();
    $insertStmt->close();
    
    // 6. Update tenant can_request_evacuation flag
    $updateTenant = $conn->prepare("
        UPDATE tenants SET can_request_evacuation = FALSE, evacuation_request_id = ? 
        WHERE tenant_code = ?
    ");
    $updateTenant->bind_param("ss", $request_id, $tenant_code);
    $updateTenant->execute();
    $updateTenant->close();
    
    $conn->commit();
    
    logActivity("Evacuation request created: {$request_id} for tenant: {$tenant_code}");
    
    json_success([
        'request_id' => $request_id,
        'status' => 'pending_review',
        'early_termination_fee' => $early_termination_fee,
        'early_termination_applicable' => $early_termination_applicable,
        'message' => 'Your evacuation request has been submitted and is pending admin review.'
    ], "Evacuation request submitted successfully");
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    logActivity("ERROR: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>
