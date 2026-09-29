<?php
// review_evacuation_request.php - Admin approves/rejects evacuation request

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

if (!isset($_SESSION))
    session_start();
rateLimiter();

logActivity("========== REVIEW EVACUATION REQUEST - START ==========");

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'review_evacuation',
    'rate_limit' => [20, 60],
    'csrf' => ['enabled' => true, 'form_name' => 'review_evacuation_form'],
    'roles' => ['Super Admin', 'Admin']
]);

$adminId = $auth['user_id'];
$userRole = $auth['role'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input)
    json_error("Invalid input data", 400);

$request_id = trim($input['request_id'] ?? '');
$action = trim($input['action'] ?? '');
$approved_move_out_date = trim($input['approved_move_out_date'] ?? '');
$rejection_reason = trim($input['rejection_reason'] ?? '');
$notes = trim($input['notes'] ?? '');

// ==================== VALIDATION ====================
if ($request_id === '')
    json_error("Request ID is required", 400);
if (!in_array($action, ['approve', 'reject'], true)) {
    json_error("Action must be 'approve' or 'reject'", 400);
}

if ($action === 'approve') {
    if ($approved_move_out_date === '') {
        json_error("Approved move-out date is required for approval", 400);
    }
    $date = DateTime::createFromFormat('Y-m-d', $approved_move_out_date);
    if (!$date || $date->format('Y-m-d') !== $approved_move_out_date) {
        json_error("Invalid move-out date format. Use YYYY-MM-DD", 400);
    }
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    if ($date < $today) {
        json_error("Approved move-out date cannot be in the past", 400);
    }
}

if ($action === 'reject' && $rejection_reason === '') {
    json_error("Rejection reason is required", 400);
}

$conn->begin_transaction();

try {
    // ==================== FETCH REQUEST ====================
    $stmt = $conn->prepare("
        SELECT er.*, 
               t.firstname, t.lastname, t.email, t.created_by,
               t.apartment_code, t.lease_start_date, t.lease_end_date, t.rent_balance
        FROM evacuation_requests er
        JOIN tenants t ON er.tenant_code = t.tenant_code
        WHERE er.request_id = ? AND er.status = 'pending_review'
        FOR UPDATE
    ");
    $stmt->bind_param("s", $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new Exception("Request not found or already reviewed", 404);
    }

    if ($userRole !== 'Super Admin' && (int) $request['created_by'] !== (int) $adminId) {
        throw new Exception("You can only review requests for tenants you onboarded", 403);
    }

    if ($action === 'approve') {
        // ==================== APPROVE ====================
        logActivity("Approving evacuation request: {$request_id}");

        // Date must not be after lease end
        $leaseEnd = new DateTime($request['lease_end_date']);
        $leaseEnd->setTime(0, 0, 0);
        $approvedDate = new DateTime($approved_move_out_date);
        $approvedDate->setTime(0, 0, 0);

        if ($approvedDate > $leaseEnd) {
            throw new Exception(
                "Approved move-out date cannot be after the tenant's lease end date ("
                . $leaseEnd->format('M j, Y') . ").",
                400
            );
        }

        // 1. Update evacuation_requests
        $updateReq = $conn->prepare("
            UPDATE evacuation_requests 
            SET status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                approved_move_out_date = ?,
                admin_notes = ?
            WHERE request_id = ?
        ");
        $updateReq->bind_param("isss", $adminId, $approved_move_out_date, $notes, $request_id);
        if (!$updateReq->execute()) {
            throw new Exception("Failed to update request: " . $updateReq->error);
        }
        $updateReq->close();

        // 2. Update tenant — mark scheduled, keep apartment occupied
        $updateTenant = $conn->prepare("
            UPDATE tenants 
            SET can_request_evacuation = 0,
                evacuation_status = 'pending_evacuation',
                move_out_date = ?,
                evacuation_request_id = ?,
                last_updated_by = ?,
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ");
        $updateTenant->bind_param("ssis", $approved_move_out_date, $request_id, $adminId, $request['tenant_code']);
        if (!$updateTenant->execute()) {
            throw new Exception("Failed to update tenant: " . $updateTenant->error);
        }
        $updateTenant->close();

        // 3. Notify tenant
        try {
            $notifMessage = "Your evacuation request has been approved. "
                . "Scheduled move-out date: " . date('M j, Y', strtotime($approved_move_out_date)) . ". "
                . "The final settlement will be processed on your move-out date.";
            createNotification(
                $conn,
                $request['tenant_code'],
                'evacuation',
                'Evacuation Request Approved',
                $notifMessage,
                [
                    'request_id' => $request_id,
                    'approved_move_out_date' => $approved_move_out_date
                ],
                'high',
                '../evacuation_status.php',
                'View Details'
            );
        } catch (Exception $notifErr) {
            logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
        }

        $message = "Evacuation request approved. Move-out scheduled for "
            . date('M j, Y', strtotime($approved_move_out_date));

    } else {
        // ==================== REJECT ====================
        logActivity("Rejecting evacuation request: {$request_id}");

        $updateReq = $conn->prepare("
            UPDATE evacuation_requests 
            SET status = 'rejected',
                reviewed_by = ?,
                reviewed_at = NOW(),
                rejection_reason = ?,
                admin_notes = ?
            WHERE request_id = ?
        ");
        $updateReq->bind_param("isss", $adminId, $rejection_reason, $notes, $request_id);
        if (!$updateReq->execute()) {
            throw new Exception("Failed to update request: " . $updateReq->error);
        }
        $updateReq->close();

        $updateTenant = $conn->prepare("
            UPDATE tenants 
            SET can_request_evacuation = 1,
                evacuation_request_id = NULL,
                evacuation_status = 'active',
                last_updated_by = ?,
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ");
        $updateTenant->bind_param("is", $adminId, $request['tenant_code']);
        if (!$updateTenant->execute()) {
            throw new Exception("Failed to update tenant: " . $updateTenant->error);
        }
        $updateTenant->close();

        try {
            createNotification(
                $conn,
                $request['tenant_code'],
                'evacuation',
                'Evacuation Request Rejected',
                "Your evacuation request was rejected. Reason: " . $rejection_reason
                . ". You may submit a new request.",
                ['request_id' => $request_id, 'rejection_reason' => $rejection_reason],
                'high',
                '../evacuation_status.php',
                'View Details'
            );
        } catch (Exception $notifErr) {
            logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
        }

        $message = "Evacuation request rejected. Reason: " . $rejection_reason;
    }

    $conn->commit();
    logActivity("Evacuation request {$action}d successfully: {$request_id}");
    logActivity("========== REVIEW EVACUATION REQUEST - END ==========");

    json_success([
        'request_id' => $request_id,
        'status' => $action === 'approve' ? 'approved' : 'rejected',
        'message' => $message
    ], $message);

} catch (Exception $e) {
    try {
        $conn->rollback();
    } catch (Exception $ignore) {
    }
    logActivity("ERROR: " . $e->getMessage());
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}