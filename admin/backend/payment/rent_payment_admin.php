<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../../../tenant/backend/utilities/notification_helper.php';
session_start();

header('Content-Type: application/json');

logActivity("========== RENT PAYMENT ADMIN API - START ==========");

try {
    // Authentication check
    if (!isset($_SESSION['unique_id'])) {
        logActivity("SECURITY ALERT: Unauthorized rent payment access attempt");
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit();
    }

    $adminId = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'Admin';
    logActivity("Authenticated user - ID: $adminId | Role: $userRole");

    $action = isset($_GET['action']) ? $_GET['action'] : 'fetch_pending';
    logActivity("Action: $action");

    switch ($action) {
        case 'fetch_pending':
            fetchPendingVerifications($conn);
            break;
        case 'fetch_history':
            fetchPaymentHistory($conn);
            break;
        case 'verify':
            verifyPayment($conn, $adminId);
            break;
        case 'get_tracker_summary':
            getTrackerSummary($conn);
            break;
        case 'get_tenant_periods':
            getTenantPeriods($conn);
            break;
        case 'get_statistics':
            getRentStatistics($conn);
            break;
        default:
            echo json_encode(["success" => false, "message" => "Invalid action."]);
    }

} catch (Exception $e) {
    logActivity("CRITICAL ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An error occurred: " . $e->getMessage()]);
}

logActivity("========== RENT PAYMENT ADMIN API - END ==========");

// ==================== FUNCTIONS ====================

function fetchPendingVerifications($conn) {
    logActivity("Fetching pending verifications");
    
    $query = "
        SELECT 
            t.tracker_id,
            t.rent_payment_id,
            t.period_number,
            t.start_date,
            t.end_date,
            t.amount_paid,
            t.payment_date,
            t.payment_method,
            t.payment_reference,
            t.status,
            t.created_at,
            t.payment_id as payments_table_id,
            ten.tenant_code,
            ten.firstname,
            ten.lastname,
            ten.email,
            ten.phone,
            a.apartment_number,
            a.apartment_code,
            p.name as property_name,
            p.property_code,
            rp.amount as annual_rent,
            rp.amount_paid as total_paid,
            rp.balance as remaining_balance,
            rp.receipt_number
        FROM rent_payment_tracker t
        JOIN tenants ten ON t.tenant_code COLLATE utf8mb4_unicode_ci = ten.tenant_code COLLATE utf8mb4_unicode_ci
        JOIN apartments a ON t.apartment_code COLLATE utf8mb4_unicode_ci = a.apartment_code COLLATE utf8mb4_unicode_ci
        JOIN properties p ON a.property_code COLLATE utf8mb4_unicode_ci = p.property_code COLLATE utf8mb4_unicode_ci
        JOIN rent_payments rp ON t.rent_payment_id COLLATE utf8mb4_unicode_ci = rp.rent_payment_id COLLATE utf8mb4_unicode_ci
        WHERE t.status = 'pending_verification'
        ORDER BY t.created_at ASC
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        logActivity("Query error: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        return;
    }
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $row['tenant_name'] = $row['firstname'] . ' ' . $row['lastname'];
        $row['period_display'] = formatPeriodDisplay($row['start_date'], $row['end_date']);
        $row['amount_formatted'] = '₦' . number_format($row['amount_paid'], 2);
        $row['payment_date_formatted'] = $row['payment_date'] ? date('M d, Y H:i', strtotime($row['payment_date'])) : 'N/A';
        $row['created_at_formatted'] = date('M d, Y H:i', strtotime($row['created_at']));
        $receipt_number = $row['receipt_number'];
        $payments[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "pending_count" => count($payments),
        "payments" => $payments
    ]);
}

function fetchPaymentHistory($conn) {
    logActivity("Fetching rent payment history");
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    
    $whereClauses = ["t.status IN ('paid', 'failed')"];
    $params = [];
    $types = '';
    
    if ($status && in_array($status, ['paid', 'failed'])) {
        $whereClauses[] = "t.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if ($search) {
        $whereClauses[] = "(ten.firstname LIKE ? OR ten.lastname LIKE ? OR ten.email LIKE ? OR ten.tenant_code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= 'ssss';
    }
    
    $whereSQL = "WHERE " . implode(" AND ", $whereClauses);
    
    // Count query
    $countQuery = "
        SELECT COUNT(*) as total
        FROM rent_payment_tracker t
        JOIN tenants ten ON t.tenant_code COLLATE utf8mb4_unicode_ci = ten.tenant_code COLLATE utf8mb4_unicode_ci
        $whereSQL
    ";
    
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        logActivity("Count prepare error: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        return;
    }
    
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    // Data query
    $query = "
        SELECT 
            t.tracker_id,
            t.rent_payment_id,
            t.period_number,
            t.start_date,
            t.end_date,
            t.amount_paid,
            t.payment_date,
            t.payment_method,
            t.payment_reference,
            t.status,
            t.verified_by,
            t.verified_at,
            t.admin_notes,
            ten.tenant_code,
            ten.firstname,
            ten.lastname,
            ten.email,
            ten.phone,
            a.apartment_number,
            a.apartment_code,
            p.name as property_name,
            rp.amount as annual_rent,
            rp.balance as remaining_balance
        FROM rent_payment_tracker t
        JOIN tenants ten ON t.tenant_code COLLATE utf8mb4_unicode_ci = ten.tenant_code COLLATE utf8mb4_unicode_ci
        JOIN apartments a ON t.apartment_code COLLATE utf8mb4_unicode_ci = a.apartment_code COLLATE utf8mb4_unicode_ci
        JOIN properties p ON a.property_code COLLATE utf8mb4_unicode_ci = p.property_code COLLATE utf8mb4_unicode_ci
        JOIN rent_payments rp ON t.rent_payment_id COLLATE utf8mb4_unicode_ci = rp.rent_payment_id COLLATE utf8mb4_unicode_ci
        $whereSQL
        ORDER BY t.payment_date DESC, t.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("Query prepare error: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        return;
    }
    
    $paramsWithPagination = $params;
    $paramsWithPagination[] = $limit;
    $paramsWithPagination[] = $offset;
    $stmtTypes = $types . 'ii';
    
    if (!empty($paramsWithPagination)) {
        $stmt->bind_param($stmtTypes, ...$paramsWithPagination);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $row['tenant_name'] = $row['firstname'] . ' ' . $row['lastname'];
        $row['period_display'] = formatPeriodDisplay($row['start_date'], $row['end_date']);
        $row['amount_formatted'] = '₦' . number_format($row['amount_paid'], 2);
        $row['payment_date_formatted'] = $row['payment_date'] ? date('M d, Y', strtotime($row['payment_date'])) : 'N/A';
        $row['verified_at_formatted'] = $row['verified_at'] ? date('M d, Y H:i', strtotime($row['verified_at'])) : 'N/A';
        $row['status_badge'] = $row['status'] === 'paid' ? 'success' : 'danger';
        $row['status_text'] = $row['status'] === 'paid' ? 'Paid' : 'Failed';
        $payments[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        "success" => true,
        "payments" => $payments,
        "pagination" => [
            "total" => $total,
            "page" => $page,
            "limit" => $limit,
            "total_pages" => ceil($total / $limit)
        ]
    ]);
}

function verifyPayment($conn, $adminId) {
    logActivity("Starting verifyPayment() - Admin ID: $adminId");
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $trackerId = isset($input['tracker_id']) ? (int)$input['tracker_id'] : 0;
    $action = isset($input['action']) ? trim($input['action']) : ''; // 'approve' or 'reject'
    $notes = isset($input['notes']) ? trim($input['notes']) : '';
    
    if (!$trackerId || !in_array($action, ['approve', 'reject'])) {
        echo json_encode(["success" => false, "message" => "Tracker ID and valid action (approve/reject) are required."]);
        return;
    }
    
    $conn->begin_transaction();
    
    try {
        // Get tracker details - use CAST to avoid collation issues
        $trackerQuery = "
            SELECT 
                t.tracker_id,
                t.rent_payment_id,
                t.period_number,
                t.start_date,
                t.end_date,
                t.amount_paid,
                t.status,
                t.payment_id,
                r.balance as rent_payment_balance,
                r.amount as annual_rent,
                r.receipt_number,
                ten.tenant_code,
                ten.lease_end_date,
                ten.temp_lease_end_date
            FROM rent_payment_tracker t
            INNER JOIN rent_payments r ON t.rent_payment_id = r.rent_payment_id
            INNER JOIN tenants ten ON t.tenant_code = ten.tenant_code
            WHERE t.tracker_id = ?
        ";
        $trackerStmt = $conn->prepare($trackerQuery);
        $trackerStmt->bind_param("i", $trackerId);
        $trackerStmt->execute();
        $tracker = $trackerStmt->get_result()->fetch_assoc();
        $trackerStmt->close();
        
        if (!$tracker) {
            throw new Exception("Tracker record not found");
        }
        
        if ($tracker['status'] !== 'pending_verification') {
            throw new Exception("Payment is not pending verification. Current status: " . $tracker['status']);
        }
        
        $periodAmount = (float)$tracker['amount_paid'];
        $periodEndDate = $tracker['end_date'];
        $receipt_number = $tracker['receipt_number'];
        $newStatus = ($action === 'approve') ? 'paid' : 'failed';
        
        logActivity("Processing payment - Period #{$tracker['period_number']}, Action: $action, New Status: $newStatus");
        
        // Update tracker record
        $updateTrackerQuery = "
            UPDATE rent_payment_tracker 
            SET status = ?,
                verified_by = ?,
                verified_at = NOW(),
                admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n[" . strtoupper($action) . "] ', NOW(), ' by Admin ID: {$adminId}\nNotes: {$notes}'),
                notes = ?
            WHERE tracker_id = ?
        ";
        $updateStmt = $conn->prepare($updateTrackerQuery);
        $updateStmt->bind_param("sisi", $newStatus, $adminId, $notes, $trackerId);
        $updateStmt->execute();
        $updateStmt->close();
        
        if ($action === 'approve') {
            // Update rent_payments balance
            $newRentBalance = $tracker['rent_payment_balance'] - $periodAmount;
            $updateRentQuery = "
                UPDATE rent_payments 
                SET amount_paid = amount_paid + ?,
                    balance = ?,
                    updated_at = NOW()
                WHERE rent_payment_id = ?
            ";
            $updateRentStmt = $conn->prepare($updateRentQuery);
            $updateRentStmt->bind_param("dds", $periodAmount, $newRentBalance, $tracker['rent_payment_id']);
            $updateRentStmt->execute();
            $updateRentStmt->close();
            
            // Update tenant's rent_balance and temp_lease_end_date
            $updateTenantQuery = "
                UPDATE tenants 
                SET rent_balance = rent_balance - ?,
                    temp_lease_end_date = ?,
                    last_updated_at = NOW()
                WHERE tenant_code = ?
            ";
            $updateTenantStmt = $conn->prepare($updateTenantQuery);
            $updateTenantStmt->bind_param("dss", $periodAmount, $periodEndDate, $tracker['tenant_code']);
            $updateTenantStmt->execute();
            $updateTenantStmt->close();
            
            logActivity("Payment approved - Period #{$tracker['period_number']}, New balance: {$newRentBalance}");
            
            // Check if all periods are paid
            $remainingQuery = "
                SELECT COUNT(*) as remaining_count 
                FROM rent_payment_tracker 
                WHERE rent_payment_id = ? AND status != 'paid'
            ";
            $remainingStmt = $conn->prepare($remainingQuery);
            $remainingStmt->bind_param("s", $tracker['rent_payment_id']);
            $remainingStmt->execute();
            $remaining = $remainingStmt->get_result()->fetch_assoc();
            $remainingStmt->close();
            
            if ($remaining['remaining_count'] == 0) {
                // Mark rent_payments as completed
                $completeQuery = "
                    UPDATE rent_payments 
                    SET status = 'completed', updated_at = NOW()
                    WHERE rent_payment_id = ?
                ";
                $completeStmt = $conn->prepare($completeQuery);
                $completeStmt->bind_param("s", $tracker['rent_payment_id']);
                $completeStmt->execute();
                $completeStmt->close();
                
                logActivity("All periods completed for rent_payment_id: {$tracker['rent_payment_id']}");
            }
             createPaymentNotification($conn, $tracker['tenant_code'], $periodAmount, 'approved', $tracker['period_number'], $receipt_number);
        }
        else if($action = "reject"){
            createPaymentNotification($conn, $tracker['tenant_code'], $periodAmount, 'rejected', $tracker['period_number'], $receipt_number);
        }
        
       
        
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => $action === 'approve' 
                ? "Payment for Period #{$tracker['period_number']} has been approved and marked as paid."
                : "Payment for Period #{$tracker['period_number']} has been rejected and marked as failed.",
            "tracker_id" => $trackerId,
            "period_number" => $tracker['period_number'],
            "new_status" => $newStatus
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        logActivity("ERROR in verifyPayment: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}

function getTrackerSummary($conn) {
    logActivity("Getting tracker summary");
    
    $query = "
        SELECT 
            COUNT(CASE WHEN status = 'pending_verification' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
            COUNT(CASE WHEN status = 'available' THEN 1 END) as available_count,
            SUM(CASE WHEN status = 'paid' THEN amount_paid ELSE 0 END) as total_paid_amount,
            SUM(CASE WHEN status = 'pending_verification' THEN amount_paid ELSE 0 END) as pending_amount,
            COUNT(DISTINCT tenant_code) as active_tenants
        FROM rent_payment_tracker
    ";
    
    $result = $conn->query($query);
    $summary = $result->fetch_assoc();
    
    echo json_encode([
        "success" => true,
        "summary" => $summary
    ]);
}

function getTenantPeriods($conn) {
    $tenantCode = isset($_GET['tenant_code']) ? $_GET['tenant_code'] : null;
    
    if (!$tenantCode) {
        echo json_encode(["success" => false, "message" => "Tenant code required"]);
        return;
    }
    
    $query = "
        SELECT 
            tracker_id,
            period_number,
            start_date,
            end_date,
            amount_paid,
            status,
            payment_date,
            verified_at,
            admin_notes
        FROM rent_payment_tracker
        WHERE tenant_code = ?
        ORDER BY period_number ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $row['period_display'] = formatPeriodDisplay($row['start_date'], $row['end_date']);
        $row['amount_formatted'] = '₦' . number_format($row['amount_paid'], 2);
        $row['status_badge'] = getStatusBadgeClass($row['status']);
        $periods[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        "success" => true,
        "periods" => $periods
    ]);
}

function getRentStatistics($conn) {
    // Simplified query without COLLATE issues
    $query = "
        SELECT 
            COUNT(DISTINCT rp.rent_payment_id) as active_leases,
            COALESCE(SUM(rp.balance), 0) as total_outstanding,
            COALESCE(SUM(rp.amount_paid), 0) as total_collected,
            (SELECT COUNT(*) FROM rent_payment_tracker WHERE status = 'pending_verification') as pending_verifications,
            (SELECT COUNT(*) FROM rent_payment_tracker WHERE status = 'failed') as failed_payments
        FROM rent_payments rp
        WHERE rp.payment_type = 'rent'
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        logActivity("Statistics query error: " . $conn->error);
        echo json_encode([
            "success" => true,
            "statistics" => [
                "summary" => [
                    "active_leases" => 0,
                    "total_outstanding" => 0,
                    "total_collected" => 0,
                    "pending_verifications" => 0,
                    "failed_payments" => 0
                ],
                "monthly_trend" => []
            ]
        ]);
        return;
    }
    
    $stats = $result->fetch_assoc();
    
    // Monthly collection trend
    $trendQuery = "
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            DATE_FORMAT(payment_date, '%b %Y') as month_name,
            COALESCE(SUM(amount_paid), 0) as total_collected,
            COUNT(*) as payments_count
        FROM rent_payment_tracker
        WHERE status = 'paid' 
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b %Y')
        ORDER BY month ASC
    ";
    
    $trendResult = $conn->query($trendQuery);
    $trend = [];
    if ($trendResult) {
        while ($row = $trendResult->fetch_assoc()) {
            $trend[] = $row;
        }
    }
    
    echo json_encode([
        "success" => true,
        "statistics" => [
            "summary" => [
                "active_leases" => (int)($stats['active_leases'] ?? 0),
                "total_outstanding" => (float)($stats['total_outstanding'] ?? 0),
                "total_collected" => (float)($stats['total_collected'] ?? 0),
                "pending_verifications" => (int)($stats['pending_verifications'] ?? 0),
                "failed_payments" => (int)($stats['failed_payments'] ?? 0)
            ],
            "monthly_trend" => $trend
        ]
    ]);
}
// Helper functions
function formatPeriodDisplay($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    return $start->format('M j, Y') . ' - ' . $end->format('M j, Y');
}

function getStatusBadgeClass($status) {
    switch($status) {
        case 'paid': return 'success';
        case 'pending_verification': return 'warning';
        case 'failed': return 'danger';
        case 'available': return 'info';
        default: return 'secondary';
    }
}
?>