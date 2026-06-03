<?php
// tenant/backend/payment/fetch_tenant_rent_payment_history.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

$requestId = uniqid('tenant_payment_history_', true);
logActivity("[TENANT_RENT_PAYMENT_HISTORY] [ID:{$requestId}] ========== START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        logActivity("[TENANT_RENT_PAYMENT_HISTORY] [ID:{$requestId}] ERROR: Unauthorized");
        json_error("Unauthorized access", 401);
    }
    
    $tenant_code = $_SESSION['tenant_code'];
    logActivity("[TENANT_RENT_PAYMENT_HISTORY] [ID:{$requestId}] Tenant: {$tenant_code}");
    
    // Get pagination and filter parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 20;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $period_number = isset($_GET['period_number']) ? (int)$_GET['period_number'] : 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'initiated_at';
    $sort_order = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
    
    // Allowed sort columns
    $allowed_sort = ['id', 'period_number', 'amount', 'attempt_number', 'status', 'initiated_at', 'verified_at'];
    if (!in_array($sort_by, $allowed_sort)) {
        $sort_by = 'initiated_at';
    }
    
    // Build base query
    $baseQuery = "
        FROM rent_payment_history rph
        WHERE rph.tenant_code = ?
    ";
    
    $params = [$tenant_code];
    $types = "s";
    
    // Filter by period number
    if ($period_number > 0) {
        $baseQuery .= " AND rph.period_number = ?";
        $params[] = $period_number;
        $types .= "i";
    }
    
    // Filter by status
    if (!empty($status)) {
        $baseQuery .= " AND rph.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Filter by date range
    if (!empty($date_from)) {
        $baseQuery .= " AND DATE(rph.initiated_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    
    if (!empty($date_to)) {
        $baseQuery .= " AND DATE(rph.initiated_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
    
    // Search by receipt number or reference number
    if (!empty($search)) {
        $searchTerm = "%{$search}%";
        $baseQuery .= " AND (rph.receipt_number LIKE ? OR rph.reference_number LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total " . $baseQuery;
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    // Calculate pagination
    $totalPages = ceil($totalRecords / $limit);
    $from = $totalRecords > 0 ? $offset + 1 : 0;
    $to = min($offset + $limit, $totalRecords);
    
    // Main query
    $query = "
        SELECT 
            rph.*,
            CASE 
                WHEN rph.initiated_by_type = 'tenant' THEN 'You'
                ELSE 'Admin'
            END as initiated_by_display,
            CASE 
                WHEN rph.status = 'paid' THEN 'success'
                WHEN rph.status IN ('rejected', 'failed') THEN 'danger'
                WHEN rph.status = 'pending_verification' THEN 'warning'
                WHEN rph.status = 'initiated' THEN 'info'
                ELSE 'secondary'
            END as status_class
        " . $baseQuery . "
        ORDER BY {$sort_by} {$sort_order}
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $histories = [];
    while ($row = $result->fetch_assoc()) {
        // Format dates
        $row['initiated_at_formatted'] = date('M d, Y h:i A', strtotime($row['initiated_at']));
        $row['verified_at_formatted'] = $row['verified_at'] ? date('M d, Y h:i A', strtotime($row['verified_at'])) : 'N/A';
        $row['amount_formatted'] = '₦' . number_format($row['amount'], 2);
        
        // Status text
        $status_texts = [
            'initiated' => 'Initiated',
            'pending_verification' => 'Pending Verification',
            'paid' => 'Paid',
            'rejected' => 'Rejected',
            'failed' => 'Failed'
        ];
        $row['status_text'] = $status_texts[$row['status']] ?? ucfirst($row['status']);
        
        $histories[] = $row;
    }
    $stmt->close();
    
    // Get summary statistics for tenant
    $summaryQuery = "
        SELECT 
            COUNT(*) as total_attempts,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as successful_payments,
            SUM(CASE WHEN status = 'pending_verification' THEN 1 ELSE 0 END) as pending_payments,
            SUM(CASE WHEN status IN ('rejected', 'failed') THEN 1 ELSE 0 END) as failed_payments,
            SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_paid
        FROM rent_payment_history
        WHERE tenant_code = ?
    ";
    $summaryStmt = $conn->prepare($summaryQuery);
    $summaryStmt->bind_param("s", $tenant_code);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();
    
    json_success([
        'history' => $histories,
        'summary' => [
            'total_attempts' => (int)$summary['total_attempts'],
            'successful_payments' => (int)$summary['successful_payments'],
            'pending_payments' => (int)$summary['pending_payments'],
            'failed_payments' => (int)$summary['failed_payments'],
             'total_paid' => '₦' . number_format($summary['total_paid'] ?? 0, 2)
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages
        ],
        'filters' => [
            'available_statuses' => ['initiated', 'pending_verification', 'paid', 'rejected', 'failed']
        ]
    ], "Payment history retrieved successfully");
    
} catch (Exception $e) {
    logActivity("[TENANT_RENT_PAYMENT_HISTORY] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Failed to fetch payment history", 500);
}