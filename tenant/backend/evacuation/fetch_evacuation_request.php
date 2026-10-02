<?php
// tenant/backend/evacuation/fetch_my_requests.php
// List all evacuation requests for the logged-in tenant

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
 if (!isset($_SESSION)) session_start();
 rateLimiter();

logActivity("========== FETCH MY REQUESTS - START ==========");

try {
    // Authentication
    if (!isset($_SESSION['tenant_code']) || ($_SESSION['role'] ?? '') !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'];

    // Fetch all requests (newest first)
    $stmt = $conn->prepare("
        SELECT 
            request_id,
            status,
            requested_move_out_date,
            reason,
            notes,
            early_termination_fee,
            outstanding_amount,
            approved_move_out_date,
            rejection_reason,
            final_settlement_amount,
            security_deposit_refund,
            created_at,
            reviewed_at,
            processed_at,
            cancelled_at,
            tenant_rent_share,
            unused_rent,
            damages_total,
            damages_owed_by_tenant,
            cycle_rent_amount,
            cycle_days_used,
            cycle_total_days,
            total_paid_in_cycle,
            rent_used
        FROM evacuation_requests
        WHERE tenant_code = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("s", $tenant_code);
    $stmt->execute();
    $res = $stmt->get_result();

    $requests = [];
    while ($row = $res->fetch_assoc()) {
        $status = $row['status'];

        // Status label for display
        $statusLabels = [
            'pending_review' => 'Pending Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];

        // Determine what to show as primary amount
        $primaryAmount = null;
        $primaryAmountLabel = null;

        if ($status === 'pending_review') {
            // Show estimated refund
            $securityDeposit = 0;
            // Fetch deposit from the request snapshot if stored, else 0
            $estimatedRefund = (float) $row['tenant_rent_share'];
            if ($estimatedRefund > 0) {
                $primaryAmount = $estimatedRefund;
                $primaryAmountLabel = 'Estimated share of unused rent';
            }
        } elseif ($status === 'completed') {
            $primaryAmount = (float) $row['final_settlement_amount'];
            $primaryAmountLabel = $primaryAmount >= 0 ? 'Final refund' : 'Amount owed';
        }

        // Format dates
        $createdAt = new DateTime($row['created_at']);
        $now = new DateTime();
        $diff = $now->diff($createdAt);
        $submittedAgo = 'Just now';
        if ($diff->days > 0) {
            $submittedAgo = $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            $submittedAgo = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            $submittedAgo = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }

        $requests[] = [
            'request_id' => $row['request_id'],
            'status' => $status,
            'status_label' => $statusLabels[$status] ?? ucfirst($status),
            'requested_move_out_date' => $row['requested_move_out_date'],
            'requested_move_out_date_formatted' => $row['requested_move_out_date']
                ? date('M j, Y', strtotime($row['requested_move_out_date']))
                : 'N/A',
            'reason' => $row['reason'],
            'notes' => $row['notes'],
            'approved_move_out_date' => $row['approved_move_out_date'],
            'approved_move_out_date_formatted' => $row['approved_move_out_date']
                ? date('M j, Y', strtotime($row['approved_move_out_date']))
                : null,
            'rejection_reason' => $row['rejection_reason'],
            'primary_amount' => $primaryAmount,
            'primary_amount_label' => $primaryAmountLabel,
            'final_settlement_amount' => (float) $row['final_settlement_amount'],
            'security_deposit_refund' => (float) $row['security_deposit_refund'],
            'created_at' => $row['created_at'],
            'created_at_formatted' => date('M j, Y', strtotime($row['created_at'])),
            'submitted_ago' => $submittedAgo,
            'can_cancel' => ($status === 'pending_review'),
            'can_view_settlement' => ($status === 'completed'),
        ];
    }
    $stmt->close();

    // Also compute whether the tenant can submit a new request
    $canSubmitNew = true;
    $blockReason = null;

    $checkPending = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM evacuation_requests 
        WHERE tenant_code = ? AND status IN ('pending_review', 'approved')
    ");
    $checkPending->bind_param("s", $tenant_code);
    $checkPending->execute();
    $pendingCount = (int) $checkPending->get_result()->fetch_assoc()['c'];
    $checkPending->close();

    if ($pendingCount > 0) {
        $canSubmitNew = false;
        $blockReason = "You already have a pending or approved evacuation request.";
    }

    // Check if tenant has pending payments
    $checkPayments = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM rent_payment_tracker 
        WHERE tenant_code = ? AND status = 'pending_verification'
    ");
    $checkPayments->bind_param("s", $tenant_code);
    $checkPayments->execute();
    $paymentCount = (int) $checkPayments->get_result()->fetch_assoc()['c'];
    $checkPayments->close();

    if ($paymentCount > 0) {
        $canSubmitNew = false;
        $blockReason = "You have pending payment verifications.";
    }

    // Also check tenant's current eligibility flag
    $checkElig = $conn->prepare("
        SELECT can_request_evacuation
        FROM tenants WHERE tenant_code = ? LIMIT 1
    ");
    $checkElig->bind_param("s", $tenant_code);
    $checkElig->execute();
    $eligRow = $checkElig->get_result()->fetch_assoc();
    $checkElig->close();

    logActivity("Fetched " . count($requests) . " evacuation requests for tenant: {$tenant_code}");

    json_success(
        [
            'requests' => $requests,
            'can_submit_new' => $canSubmitNew,
            'block_reason' => $blockReason,
        ],
        "Requests retrieved successfully"
    );

} catch (Exception $e) {
    logActivity("ERROR in fetch_my_requests: " . $e->getMessage());
    json_error("Failed to fetch evacuation requests", 500);
}