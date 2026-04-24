<?php
// review_evacuation_request.php - Admin approves/rejects evacuation request

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
$action = $input['action'] ?? null; // 'approve' or 'reject'
$approved_move_out_date = $input['approved_move_out_date'] ?? null;
$rejection_reason = $input['rejection_reason'] ?? null;

if (!$request_id || !in_array($action, ['approve', 'reject'])) {
    json_error("Request ID and valid action are required", 400);
}

$conn->begin_transaction();

try {
    // Get request details
    $query = "SELECT * FROM evacuation_requests WHERE request_id = ? AND status = 'pending_review'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$request) {
        throw new Exception("Request not found or already reviewed", 404);
    }
    
    if ($action === 'approve') {
        if (!$approved_move_out_date) {
            throw new Exception("Approved move-out date is required", 400);
        }
        
        $updateQuery = "
            UPDATE evacuation_requests 
            SET status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                approved_move_out_date = ?
            WHERE request_id = ?
        ";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("iss", $adminId, $approved_move_out_date, $request_id);
        $updateStmt->execute();
        $updateStmt->close();
        
        $message = "Evacuation request approved. Move-out scheduled for " . date('M d, Y', strtotime($approved_move_out_date));
        
    } else {
        // Reject
        $updateQuery = "
            UPDATE evacuation_requests 
            SET status = 'rejected',
                reviewed_by = ?,
                reviewed_at = NOW(),
                rejection_reason = ?
            WHERE request_id = ?
        ";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("iss", $adminId, $rejection_reason, $request_id);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Allow tenant to request again
        $updateTenant = $conn->prepare("
            UPDATE tenants SET can_request_evacuation = TRUE, evacuation_request_id = NULL 
            WHERE tenant_code = ?
        ");
        $updateTenant->bind_param("s", $request['tenant_code']);
        $updateTenant->execute();
        $updateTenant->close();
        
        $message = "Evacuation request rejected. Reason: " . ($rejection_reason ?? 'Not specified');
    }
    
    $conn->commit();
    
    json_success($message, [
        'request_id' => $request_id,
        'status' => $action === 'approve' ? 'approved' : 'rejected',
        'message' => $message
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    json_error($e->getMessage(), 500);
}
?>
