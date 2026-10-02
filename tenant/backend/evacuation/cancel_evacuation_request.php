<?php
// tenant/backend/evacuation/cancel_request.php
// Tenant cancels their own pending evacuation request

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/notification_helper.php';  // FIXED path
require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

logActivity("========== CANCEL REQUEST - START ==========");

try {
    if (!isset($_SESSION['tenant_code']) || ($_SESSION['role'] ?? '') !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];
    $input = json_decode(file_get_contents('php://input'), true);
    $request_id = trim($input['request_id'] ?? '');

    if ($request_id === '') {
        json_error("Request ID is required", 400);
    }

    $conn->begin_transaction();

    try {
        // Fetch request (locked)
        $stmt = $conn->prepare("
            SELECT er.*, t.firstname, t.lastname, t.created_by
            FROM evacuation_requests er
            JOIN tenants t ON er.tenant_code = t.tenant_code
            WHERE er.request_id = ? 
              AND er.tenant_code = ?
              AND er.status = 'pending_review'
            FOR UPDATE
        ");
        $stmt->bind_param("ss", $request_id, $tenant_code);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            throw new Exception("Request not found or cannot be cancelled", 404);
        }

        // 1. Mark request as cancelled
        $updateReq = $conn->prepare("
            UPDATE evacuation_requests 
            SET status = 'cancelled',
                cancelled_at = NOW(),
                cancelled_by = ?
            WHERE request_id = ?
        ");
        $updateReq->bind_param("ss", $tenant_code, $request_id);
        if (!$updateReq->execute()) {
            throw new Exception("Failed to update request: " . $updateReq->error);
        }
        $updateReq->close();

        // 2. Reset tenant flags so they can request again
        $updateTenant = $conn->prepare("
            UPDATE tenants 
            SET can_request_evacuation = 1,
                evacuation_request_id = NULL,
                evacuation_status = 'active',
                last_updated_at = NOW()
            WHERE tenant_code = ?
        ");
        $updateTenant->bind_param("s", $tenant_code);
        if (!$updateTenant->execute()) {
            throw new Exception("Failed to update tenant: " . $updateTenant->error);
        }
        $updateTenant->close();

        // 3. Notify tenant (in-app confirmation)
        try {
            createNotification(
                $conn,
                $tenant_code,
                'evacuation',
                'Evacuation Request Cancelled',
                "Your evacuation request ({$request_id}) has been cancelled successfully. You may submit a new request at any time.",
                ['request_id' => $request_id],
                'low',
                '../evacuation_status.php',
                'View Requests'
            );
        } catch (Exception $notifErr) {
            logActivity("WARNING: Tenant notification failed: " . $notifErr->getMessage());
        }

        // 4. Admin audit log
        logActivity("ADMIN AUDIT: Tenant {$tenant_code} cancelled request {$request_id} (created_by admin: {$request['created_by']})");

        $conn->commit();
        logActivity("Tenant {$tenant_code} cancelled request {$request_id}");

        // FIXED: data first, message second
        json_success(
            [
                'request_id' => $request_id,
                'status' => 'cancelled',
            ],
            "Request cancelled successfully"
        );

    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $ignore) {}
        throw $e;
    }

} catch (Exception $e) {
    logActivity("ERROR in cancel_request: " . $e->getMessage());
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}