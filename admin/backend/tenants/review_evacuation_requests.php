<?php
// review_evacuation_request.php - Admin approves / rejects / declines evacuation requests
//
// Actions:
//   - approve : pending_review → approved    (schedule move-out)
//   - reject  : pending_review → rejected    (with reason)
//   - decline : approved       → pending_review (withdraw a prior approval)

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
rateLimiter();

logActivity("========== REVIEW EVACUATION REQUEST - START ==========");

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'review_evacuation',
    'rate_limit' => [20, 60],
    'csrf'       => ['enabled' => true, 'form_name' => 'review_evacuation_form'],
    'roles'      => ['Super Admin', 'Admin'],
]);

$adminId  = $auth['user_id'];
$userRole = $auth['role'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error("Invalid input data", 400);
}

$request_id             = trim($input['request_id'] ?? '');
$action                 = trim($input['action'] ?? '');
$approved_move_out_date = trim($input['approved_move_out_date'] ?? '');
$rejection_reason       = trim($input['rejection_reason'] ?? '');
$decline_reason         = trim($input['decline_reason'] ?? '');
$notes                  = trim($input['notes'] ?? '');

// ==================== VALIDATION ====================
if ($request_id === '') {
    json_error("Request ID is required", 400);
}

if (!in_array($action, ['approve', 'reject', 'decline'], true)) {
    json_error("Action must be 'approve', 'reject', or 'decline'", 400);
}

// ----- approve-specific validation -----
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

// ----- reject-specific validation -----
if ($action === 'reject' && $rejection_reason === '') {
    json_error("Rejection reason is required", 400);
}

// ----- decline-specific validation (reason optional, defaults provided) -----
if ($action === 'decline' && $decline_reason === '') {
    $decline_reason = 'Approval withdrawn by admin';
}

$conn->begin_transaction();

try {
    // ================================================================
    // FETCH REQUEST
    // ================================================================
    // approve/reject: only from pending_review
    // decline:        only from approved
    $allowedStatuses = $action === 'decline'
        ? ['approved']
        : ['pending_review'];

    $statusPlaceholders = implode(',', array_fill(0, count($allowedStatuses), '?'));

    $stmt = $conn->prepare("
        SELECT er.*,
               t.firstname, t.lastname, t.email, t.created_by,
               t.apartment_code, t.lease_start_date, t.lease_end_date, t.rent_balance
        FROM evacuation_requests er
        JOIN tenants t ON er.tenant_code = t.tenant_code
        WHERE er.request_id = ?
          AND er.status IN ($statusPlaceholders)
        FOR UPDATE
    ");

    // Build bind params: [request_id, ...statuses]
    $params = array_merge([$request_id], $allowedStatuses);
    $types  = 's' . str_repeat('s', count($allowedStatuses));

    // bind_param requires references — use a local array
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        $msg = $action === 'decline'
            ? "Request not found or not in an approved state"
            : "Request not found or already reviewed";
        throw new Exception($msg, 404);
    }

    if ($userRole !== 'Super Admin' && (int) $request['created_by'] !== (int) $adminId) {
        throw new Exception("You can only review requests for tenants you onboarded", 403);
    }

    // ================================================================
    // DISPATCH
    // ================================================================
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
        $updateReq->bind_param(
            "isss",
            $adminId,
            $approved_move_out_date,
            $notes,
            $request_id
        );
        if (!$updateReq->execute()) {
            throw new Exception("Failed to update request: " . $updateReq->error);
        }
        $updateReq->close();

        // 2. Update tenant — scheduled move-out, keep apartment occupied
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
        $updateTenant->bind_param(
            "ssis",
            $approved_move_out_date,
            $request_id,
            $adminId,
            $request['tenant_code']
        );
        if (!$updateTenant->execute()) {
            throw new Exception("Failed to update tenant: " . $updateTenant->error);
        }
        $updateTenant->close();

        // 3. Notify tenant
        try {
            $notifMessage = "Your evacuation request has been approved. "
                . "Scheduled move-out date: "
                . date('M j, Y', strtotime($approved_move_out_date)) . ". "
                . "The final settlement will be processed on your move-out date.";

            createNotification(
                $conn,
                $request['tenant_code'],
                'evacuation',
                'Evacuation Request Approved',
                $notifMessage,
                [
                    'request_id'              => $request_id,
                    'approved_move_out_date'  => $approved_move_out_date,
                ],
                'high',
                '../evacuation_status.php',
                'View Details'
            );
        } catch (Throwable $notifErr) {
            logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
        }

        $message = "Evacuation request approved. Move-out scheduled for "
            . date('M j, Y', strtotime($approved_move_out_date));

    } elseif ($action === 'reject') {

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
        $updateReq->bind_param(
            "isss",
            $adminId,
            $rejection_reason,
            $notes,
            $request_id
        );
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
                [
                    'request_id'       => $request_id,
                    'rejection_reason' => $rejection_reason,
                ],
                'high',
                '../evacuation_status.php',
                'View Details'
            );
        } catch (Throwable $notifErr) {
            logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
        }

        $message = "Evacuation request rejected. Reason: " . $rejection_reason;

    } else {

        // ==================== DECLINE (undo approval) ====================
        logActivity("Declining approved evacuation request: {$request_id}");

        // 1. Revert evacuation_requests to pending_review
        //    Clear approval metadata; store decline reason in rejection_reason
        //    so the audit trail shows why the approval was withdrawn.
        $updateReq = $conn->prepare("
            UPDATE evacuation_requests
            SET status = 'pending_review',
                reviewed_by = NULL,
                reviewed_at = NULL,
                approved_move_out_date = NULL,
                admin_notes = ?,
                rejection_reason = ?
            WHERE request_id = ?
        ");
        $updateReq->bind_param(
            "sss",
            $notes,
            $decline_reason,
            $request_id
        );
        if (!$updateReq->execute()) {
            throw new Exception("Failed to revert request: " . $updateReq->error);
        }
        $updateReq->close();

        // 2. Revert tenants to active state
        $updateTenant = $conn->prepare("
            UPDATE tenants
            SET can_request_evacuation = 1,
                evacuation_status = 'active',
                move_out_date = NULL,
                evacuation_request_id = NULL,
                last_updated_by = ?,
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ");
        $updateTenant->bind_param("is", $adminId, $request['tenant_code']);
        if (!$updateTenant->execute()) {
            throw new Exception("Failed to revert tenant: " . $updateTenant->error);
        }
        $updateTenant->close();

        // 3. Notify tenant
        try {
            $notifMessage = "Your evacuation approval has been withdrawn by the admin. "
                . "Reason: " . $decline_reason . ". "
                . "The request has been returned to pending review.";

            createNotification(
                $conn,
                $request['tenant_code'],
                'evacuation',
                'Evacuation Approval Withdrawn',
                $notifMessage,
                [
                    'request_id'     => $request_id,
                    'decline_reason' => $decline_reason,
                ],
                'high',
                '../evacuation_status.php',
                'View Details'
            );
        } catch (Throwable $notifErr) {
            logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
        }

        $message = "Evacuation approval withdrawn. Request returned to pending review.";
    }

    // ================================================================
    // COMMIT
    // ================================================================
    $conn->commit();
    logActivity("Evacuation request {$action}d successfully: {$request_id}");
    logActivity("========== REVIEW EVACUATION REQUEST - END ==========");

    // Map action → resulting status
    $newStatus = match ($action) {
        'approve' => 'approved',
        'reject'  => 'rejected',
        'decline' => 'pending_review',
        default   => 'unknown',
    };

    json_success(
        [
            'request_id' => $request_id,
            'status'     => $newStatus,
            'message'    => $message,
        ],
        $message
    );

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignore) {
        // rollback may fail if the connection died
    }
    logActivity("ERROR: " . $e->getMessage());
    logActivity("Trace: " . $e->getTraceAsString());

    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}