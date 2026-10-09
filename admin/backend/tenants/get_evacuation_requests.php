<?php
// backend/payment/get_evacuation_requests.php - Fetch evacuation requests

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION))
    session_start();
rateLimiter();

$auth = requireAuth([
    'method' => 'GET',
    'roles' => ['Super Admin', 'Admin']
]);

// Handle statistics request
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    $statsQuery = "
        SELECT 
            COUNT(CASE WHEN status = 'pending_review' THEN 1 END) as pending_review,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
        FROM evacuation_requests
    ";
    $statsResult = $conn->query($statsQuery);
    $stats = $statsResult->fetch_assoc();

    json_success("Statistics retrieved successfully", $stats);
    exit();
}

// Handle single request fetch
if (isset($_GET['request_id'])) {
    $request_id = $_GET['request_id'];

    $query = "
        SELECT 
            er.*,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            a.apartment_number,
            a.security_deposit,
            p.name as property_name,
            p.property_code
        FROM evacuation_requests er
        JOIN tenants t ON er.tenant_code = t.tenant_code
        JOIN apartments a ON er.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE er.request_id = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if ($request) {
        $request['tenant_name'] = $request['firstname'] . ' ' . $request['lastname'];
        $request['requested_move_out_date_formatted'] = date('M d, Y', strtotime($request['requested_move_out_date']));
        $request['created_at_formatted'] = date('M d, Y H:i', strtotime($request['created_at']));

        $deductionsStmt = $conn->prepare("
            SELECT deduction_type, amount, description
            FROM evacuation_deductions
            WHERE request_id = ?
        ");
        $deductionsStmt->bind_param("s", $request_id);
        $deductionsStmt->execute();
        $deductionsResult = $deductionsStmt->get_result();

        $deductions = [];
        while ($deduction = $deductionsResult->fetch_assoc()) {
            $deductions[] = $deduction;
        }
        $deductionsStmt->close();

        $request['deductions'] = $deductions;

        // ==================== OUTSTANDING FEES ====================
        // Fetch unpaid fees (pending/overdue) owed by this tenant.
        // These are NOT yet part of the deductions — they're shown to the
        // admin so they can decide whether to include them at settlement time.
        $outstandingFees = [];
        $outstandingFeesTotal = 0.0;

        $feesStmt = $conn->prepare("
            SELECT
                tf.tenant_fee_id,
                tf.fee_type_id,
                tf.amount,
                tf.due_date,
                tf.status,
                tf.notes,
                ft.fee_name AS fee_type_name
            FROM tenant_fees tf
            LEFT JOIN fee_types ft ON ft.fee_type_id = tf.fee_type_id
            WHERE tf.tenant_code = ?
              AND tf.status IN ('pending', 'overdue')
            ORDER BY tf.due_date ASC
        ");
        $feesStmt->bind_param("s", $request['tenant_code']);
        $feesStmt->execute();
        $feesResult = $feesStmt->get_result();

        while ($fee = $feesResult->fetch_assoc()) {
            $amount = (float) $fee['amount'];
            $outstandingFeesTotal += $amount;

            $outstandingFees[] = [
                'tenant_fee_id' => (int) $fee['tenant_fee_id'],
                'fee_type_id' => (int) $fee['fee_type_id'],
                'fee_type_name' => $fee['fee_type_name'] ?: 'Unnamed Fee',
                'amount' => round($amount, 2),
                'amount_formatted' => '₦' . number_format($amount, 2),
                'due_date' => $fee['due_date'],
                'due_date_formatted' => $fee['due_date']
                    ? date('M d, Y', strtotime($fee['due_date']))
                    : null,
                'status' => $fee['status'],
                'is_overdue' => $fee['status'] === 'overdue'
                    || ($fee['due_date'] && strtotime($fee['due_date']) < time()),
                'notes' => $fee['notes'],
            ];
        }
        $feesStmt->close();

        $outstandingFeesTotal = round($outstandingFeesTotal, 2);

        $request['outstanding_fees'] = $outstandingFees;
        $request['outstanding_fees_count'] = count($outstandingFees);
        $request['outstanding_fees_total'] = $outstandingFeesTotal;
        $request['outstanding_fees_total_formatted'] = '₦' . number_format($outstandingFeesTotal, 2);
        $request['has_outstanding_fees'] = count($outstandingFees) > 0;

        logActivity("Outstanding fees for {$request_id}: "
            . count($outstandingFees) . " | Total: ₦{$outstandingFeesTotal}");

        json_success("Request retrieved successfully", ['requests' => [$request]]);
    } else {
        json_success("Request not found", ['requests' => []]);
    }
    exit();
}

// Handle list requests
$status = isset($_GET['status']) ? $_GET['status'] : 'pending_review';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$offset = ($page - 1) * $limit;

$query = "
    SELECT 
        er.*,
        t.firstname,
        t.lastname,
        t.email,
        t.phone,
        a.apartment_number,
        p.name as property_name,
        CONCAT(adm.firstname, ' ', adm.lastname) as reviewed_by_name
    FROM evacuation_requests er
    JOIN tenants t ON er.tenant_code = t.tenant_code
    JOIN apartments a ON er.apartment_code = a.apartment_code
    JOIN properties p ON a.property_code = p.property_code
    LEFT JOIN admin_tbl adm ON er.reviewed_by = adm.unique_id
    WHERE er.status = ?
    ORDER BY er.created_at ASC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("sii", $status, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];
while ($row = $result->fetch_assoc()) {
    $row['tenant_name'] = $row['firstname'] . ' ' . $row['lastname'];
    $row['requested_move_out_date_formatted'] = date('M d, Y', strtotime($row['requested_move_out_date']));
    $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
    $requests[] = $row;
}
$stmt->close();

// Get count
$countQuery = "SELECT COUNT(*) as total FROM evacuation_requests WHERE status = ?";
$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param("s", $status);
$countStmt->execute();
$total = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

json_success("Requests retrieved successfully", [
    'requests' => $requests,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);
?>