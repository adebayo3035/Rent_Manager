<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    
    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400);
    }

    $request_id = $input['request_id'] ?? null;
    $cancel_reason = $input['cancel_reason'] ?? 'User cancelled';

    if (!$request_id) {
        json_error("Request ID is required", 400);
    }

    // Verify the request belongs to this tenant and is cancellable
    $checkQuery = "
        SELECT request_id, status 
        FROM maintenance_requests 
        WHERE request_id = ? AND tenant_code = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("is", $request_id, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        json_error("Maintenance request not found", 404);
    }

    // Check if request can be cancelled (only pending requests can be cancelled)
    if ($request['status'] !== 'pending') {
        json_error("Only pending requests can be cancelled. Current status: " . $request['status'], 400);
    }

    // Update request status to cancelled
    $updateQuery = "
        UPDATE maintenance_requests 
        SET status = 'cancelled', 
            resolution_notes = ?,
            updated_at = NOW()
        WHERE request_id = ? AND tenant_code = ?
    ";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("sis", $cancel_reason, $request_id, $tenant_code);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to cancel maintenance request: " . $stmt->error);
    }
    
    $affected = $stmt->affected_rows;
    $stmt->close();

    logActivity("Maintenance request cancelled | Request ID: $request_id | Tenant: $tenant_code | Reason: $cancel_reason");

    json_success([
        'request_id' => $request_id,
        'status' => 'cancelled',
        'cancelled_at' => date('Y-m-d H:i:s')
    ], "Maintenance request cancelled successfully");

} catch (Exception $e) {
    logActivity("Error in cancel_maintenance_request: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>