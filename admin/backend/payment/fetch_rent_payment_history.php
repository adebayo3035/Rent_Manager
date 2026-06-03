<?php
// admin/backend/payment/fetch_rent_payment_history.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

$requestId = uniqid('rent_payment_history_', true);
logActivity("[RENT_PAYMENT_HISTORY] [ID:{$requestId}] ========== START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        json_error("Unauthorized", 401);
    }
    
    // Get pagination and filter parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 20;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $tenant_code = isset($_GET['tenant_code']) ? trim($_GET['tenant_code']) : '';
    $period_number = isset($_GET['period_number']) ? (int)$_GET['period_number'] : 0;
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $initiated_by = isset($_GET['initiated_by']) ? trim($_GET['initiated_by']) : '';
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
        JOIN tenants t ON rph.tenant_code = t.tenant_code
        JOIN apartments a ON rph.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";
    
    // Filter by tenant
    if (!empty($tenant_code)) {
        $baseQuery .= " AND rph.tenant_code = ?";
        $params[] = $tenant_code;
        $types .= "s";
    }
    
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
    
    // Filter by initiated by
    if (!empty($initiated_by)) {
        $baseQuery .= " AND rph.initiated_by_type = ?";
        $params[] = $initiated_by;
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
    
    // Search by receipt number, reference number, or tenant name
    if (!empty($search)) {
        $searchTerm = "%{$search}%";
        $baseQuery .= " AND (rph.receipt_number LIKE ? OR rph.reference_number LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ? OR t.tenant_code LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ssss";
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total " . $baseQuery;
    $countStmt = $conn->prepare($countQuery);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
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
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            a.apartment_number,
            p.name as property_name,
            p.property_code,
            CASE 
                WHEN rph.initiated_by_type = 'tenant' THEN CONCAT(t.firstname, ' ', t.lastname)
                ELSE (SELECT unique_id FROM admin_tbl WHERE unique_id = rph.initiated_by LIMIT 1)
            END as initiated_by_name
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
        $row['amount_formatted'] = 'NGN ' . number_format($row['amount'], 2);
        
        // Status badge class
        $status_classes = [
            'initiated' => 'warning',
            'pending_verification' => 'info',
            'paid' => 'success',
            'approved' => 'success',
            'rejected' => 'danger',
            'failed' => 'danger'
        ];
        $row['status_class'] = $status_classes[$row['status']] ?? 'secondary';
        
        // Initiated by display
        $row['initiated_by_display'] = $row['initiated_by_type'] === 'tenant' ? 'Tenant' : 'Admin';
        
        $histories[] = $row;
    }
    $stmt->close();
    
    // Get filter options (for dropdowns)
    $statuses = ['initiated', 'pending_verification', 'paid', 'failed', 'approved', 'rejected'];
    $initiated_by_types = ['tenant' => 'Tenant', 'admin' => 'Admin'];
    
    json_success("Rent payment history retrieved successfully", [
        'history' => $histories,
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
            'available_statuses' => $statuses,
            'available_initiated_by' => $initiated_by_types
        ]
    ]);
    
} catch (Exception $e) {
    logActivity("[RENT_PAYMENT_HISTORY] [ID:{$requestId}] ERROR: " . $e->getMessage());
    json_error("Failed to fetch rent payment history", 500);
}

// function json_error($message, $code = 400) {
//     http_response_code($code);
//     echo json_encode(['success' => false, 'message' => $message]);
//     exit();
// }

// function json_success($data, $message = "Success") {
//     echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
//     exit();
// }
