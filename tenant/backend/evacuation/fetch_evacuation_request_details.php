<?php
// tenant/backend/evacuation/fetch_request_details.php
// Fetch full details of a single evacuation request (with settlement breakdown)

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

if (!isset($_SESSION)) session_start();
// rateLimiter();

logActivity("========== FETCH REQUEST DETAILS - START ==========");

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

    // Fetch the request — must belong to this tenant
    $stmt = $conn->prepare("
        SELECT 
            er.*,
            CONCAT(t.firstname, ' ', t.lastname) AS tenant_name,
            t.email AS tenant_email,
            p.name AS property_name,
            a.apartment_number,
            a.security_deposit
        FROM evacuation_requests er
        JOIN tenants t ON er.tenant_code = t.tenant_code
        LEFT JOIN apartments a ON er.apartment_code = a.apartment_code
        LEFT JOIN properties p ON a.property_code = p.property_code
        WHERE er.request_id = ? AND er.tenant_code = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $request_id, $tenant_code);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        json_error("Request not found", 404);
    }

    // Fetch deductions (if any)
    $deductions = [];
    $dedStmt = $conn->prepare("
        SELECT deduction_type, amount, description
        FROM evacuation_deductions
        WHERE request_id = ?
        ORDER BY id ASC
    ");
    $dedStmt->bind_param("s", $request_id);
    $dedStmt->execute();
    $dedRes = $dedStmt->get_result();
    while ($row = $dedRes->fetch_assoc()) {
        $deductions[] = [
            'type'        => $row['deduction_type'],
            'amount'      => (float)$row['amount'],
            'amount_formatted' => '₦' . number_format((float)$row['amount'], 2),
            'description' => $row['description'],
        ];
    }
    $dedStmt->close();

    // Status label
    $statusLabels = [
        'pending_review' => 'Pending Review',
        'approved'       => 'Approved',
        'rejected'       => 'Rejected',
        'completed'      => 'Completed',
        'cancelled'      => 'Cancelled',
    ];

    // Format helper
    $fmtDate = function ($d) {
        return $d ? date('M j, Y', strtotime($d)) : null;
    };
    $fmtDateTime = function ($d) {
        return $d ? date('M j, Y g:i A', strtotime($d)) : null;
    };

    // Build response
    $response = [
        'request_id'            => $request['request_id'],
        'status'                => $request['status'],
        'status_label'          => $statusLabels[$request['status']] ?? ucfirst($request['status']),
        
        // Tenant + property
        'tenant_name'           => $request['tenant_name'],
        'property_name'         => $request['property_name'] ?? 'N/A',
        'apartment_number'      => $request['apartment_number'] ?? 'N/A',
        
        // Dates
        'created_at'            => $request['created_at'],
        'created_at_formatted'  => $fmtDateTime($request['created_at']),
        'requested_move_out_date' => $request['requested_move_out_date'],
        'requested_move_out_date_formatted' => $fmtDate($request['requested_move_out_date']),
        'approved_move_out_date' => $request['approved_move_out_date'],
        'approved_move_out_date_formatted' => $fmtDate($request['approved_move_out_date']),
        'processed_at_formatted' => $fmtDateTime($request['processed_at']),
        'cancelled_at_formatted' => $fmtDateTime($request['cancelled_at']),
        
        // Reason / notes
        'reason'                => $request['reason'],
        'notes'                 => $request['notes'],
        'rejection_reason'      => $request['rejection_reason'],
        
        // Cancellation
        'can_cancel'            => ($request['status'] === 'pending_review'),
        
        // Settlement breakdown (only meaningful when completed)
        'has_settlement'        => ($request['status'] === 'completed'),
        'settlement'            => null,
        
        // Deductions (only when completed)
        'deductions'            => $deductions,
    ];

    // Add settlement breakdown if completed
    if ($request['status'] === 'completed') {
        $response['settlement'] = [
            'cycle_start'              => $fmtDate($request['settled_rent_payment_id'] ? null : null), // fallback
            'cycle_rent_amount'        => (float)$request['cycle_rent_amount'],
            'cycle_rent_amount_formatted' => '₦' . number_format((float)$request['cycle_rent_amount'], 2),
            'cycle_total_days'         => (int)$request['cycle_total_days'],
            'cycle_days_used'          => (int)$request['cycle_days_used'],
            'total_paid_in_cycle'      => (float)$request['total_paid_in_cycle'],
            'total_paid_in_cycle_formatted' => '₦' . number_format((float)$request['total_paid_in_cycle'], 2),
            'rent_used'                => (float)$request['rent_used'],
            'rent_used_formatted'      => '₦' . number_format((float)$request['rent_used'], 2),
            'unused_rent'              => (float)$request['unused_rent'],
            'unused_rent_formatted'    => '₦' . number_format((float)$request['unused_rent'], 2),
            'tenant_rent_share'        => (float)$request['tenant_rent_share'],
            'tenant_rent_share_formatted' => '₦' . number_format((float)$request['tenant_rent_share'], 2),
            'landlord_rent_share'      => (float)$request['landlord_rent_share'],
            'landlord_rent_share_formatted' => '₦' . number_format((float)$request['landlord_rent_share'], 2),
            'security_deposit' => (float)$request['security_deposit'],
            'security_deposit_formatted' => '₦' . number_format((float)$request['security_deposit'], 2),
            'damages_total'            => (float)$request['damages_total'],
            'damages_total_formatted'  => '₦' . number_format((float)$request['damages_total'], 2),
            'damages_from_deposit'     => (float)$request['damages_from_deposit'],
            'damages_from_deposit_formatted' => '₦' . number_format((float)$request['damages_from_deposit'], 2),
            'damages_from_share'       => (float)$request['damages_from_share'],
            'damages_from_share_formatted' => '₦' . number_format((float)$request['damages_from_share'], 2),
            'damages_owed_by_tenant'   => (float)$request['damages_owed_by_tenant'],
            'damages_owed_by_tenant_formatted' => '₦' . number_format((float)$request['damages_owed_by_tenant'], 2),
            'final_settlement_amount'  => (float)$request['final_settlement_amount'],
            'final_settlement_amount_formatted' => '₦' . number_format((float)$request['final_settlement_amount'], 2),
            
            'security_deposit_refund'  => (float)$request['security_deposit_refund'],
            'security_deposit_refund_formatted' => '₦' . number_format((float)$request['security_deposit_refund'], 2),
        ];
    }

    // Also indicate whether the tenant has unread notifications for this request
    // (for the red dot logic on the notification bell, not this page)
    // This is just informational

    logActivity("Fetched details for request: {$request_id}");

    json_success($response, "Request details retrieved");

} catch (Exception $e) {
    logActivity("ERROR in fetch_request_details: " . $e->getMessage());
    json_error("Failed to fetch request details", 500);
}