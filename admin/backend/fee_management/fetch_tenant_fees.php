<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

try {
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    // ==================== GET FILTER PARAMETERS ====================
    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    $property_code = $_GET['property_code'] ?? null;
    $apartment_code = $_GET['apartment_code'] ?? null;
    
    // ==================== GET PAGINATION PARAMETERS ====================
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // ==================== BUILD BASE QUERY ====================
    $baseQuery = "
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        JOIN tenants t ON tf.tenant_code = t.tenant_code
        LEFT JOIN apartments a ON tf.apartment_code = a.apartment_code
        LEFT JOIN properties p ON a.property_code = p.property_code
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";
    
    // ==================== APPLY FILTERS ====================
    if ($status) {
        if ($status === 'overdue') {
            $baseQuery .= " AND tf.status = 'pending' AND tf.due_date < CURDATE()";
        } else {
            $baseQuery .= " AND tf.status = ?";
            $params[] = $status;
            $types .= "s";
        }
    }
    
    if ($search) {
        $baseQuery .= " AND (t.firstname LIKE ? OR t.lastname LIKE ? OR t.tenant_code LIKE ? OR CONCAT(t.firstname, ' ', t.lastname) LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ssss";
    }
    
    if ($property_code) {
        $baseQuery .= " AND p.property_code = ?";
        $params[] = $property_code;
        $types .= "s";
    }
    
    if ($apartment_code) {
        $baseQuery .= " AND a.apartment_code = ?";
        $params[] = $apartment_code;
        $types .= "s";
    }
    
    // ==================== GET TOTAL COUNT ====================
    $countQuery = "SELECT COUNT(*) as total " . $baseQuery;
    $countStmt = $conn->prepare($countQuery);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = (int)$countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    $totalPages = ceil($totalRecords / $limit);
    
    // ==================== GET DATA WITH PAGINATION ====================
    $dataQuery = "
        SELECT 
            tf.tenant_fee_id,
            tf.amount,
            tf.due_date,
            tf.status,
            tf.notes,
            tf.payment_date,
            tf.receipt_number,
            ft.fee_name,
            ft.fee_code,
            ft.is_recurring,
            ft.recurrence_period,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.tenant_code,
            t.email as tenant_email,
            t.phone as tenant_phone,
            a.apartment_number,
            a.apartment_code,
            p.name as property_name,
            p.property_code
        " . $baseQuery . "
        ORDER BY 
            CASE 
                WHEN tf.status = 'pending' AND tf.due_date < CURDATE() THEN 1
                WHEN tf.status = 'pending' AND tf.due_date >= CURDATE() THEN 2
                ELSE 3
            END,
            tf.due_date ASC
        LIMIT ? OFFSET ?
    ";
    
    // Add pagination parameters
    $dataParams = $params;
    $dataTypes = $types;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes .= "ii";
    
    $dataStmt = $conn->prepare($dataQuery);
    if (!empty($dataParams)) {
        $dataStmt->bind_param($dataTypes, ...$dataParams);
    }
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    
    // ==================== PROCESS RESULTS ====================
    $fees = [];
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    while ($row = $result->fetch_assoc()) {
        $due_date = new DateTime($row['due_date']);
        $due_date->setTime(0, 0, 0);
        
        $display_status = $row['status'];
        
        if ($row['status'] === 'pending') {
            if ($due_date < $today) {
                $display_status = 'overdue';
            }
        }
        
        if ($row['status'] === 'paid') {
            $display_status = 'settled';
        }
        
        $days_diff = $today->diff($due_date)->days;
        $is_overdue = ($row['status'] === 'pending' && $due_date < $today);
        
        $fees[] = [
            'tenant_fee_id' => (int)$row['tenant_fee_id'],
            'tenant_code' => $row['tenant_code'],
            'tenant_name' => $row['tenant_name'],
            'tenant_email' => $row['tenant_email'],
            'tenant_phone' => $row['tenant_phone'],
            'fee_name' => $row['fee_name'],
            'fee_code' => $row['fee_code'],
            'amount' => (float)$row['amount'],
            'due_date' => $row['due_date'],
            'status' => $row['status'],
            'display_status' => $display_status,
            'is_overdue' => $is_overdue,
            'is_recurring' => (bool)$row['is_recurring'],
            'recurrence_period' => $row['recurrence_period'],
            'payment_date' => $row['payment_date'],
            'receipt_number' => $row['receipt_number'],
            'notes' => $row['notes'],
            'apartment_number' => $row['apartment_number'],
            'apartment_code' => $row['apartment_code'],
            'property_name' => $row['property_name'],
            'property_code' => $row['property_code'],
            'days_until_due' => $is_overdue ? -$days_diff : $days_diff,
            'status_label' => getStatusLabel($display_status),
            'status_color' => getStatusColor($display_status)
        ];
    }
    $dataStmt->close();
    
    // ==================== GET SUMMARY STATISTICS ====================
    $summaryQuery = "
        SELECT 
            COUNT(*) as total_fees,
            SUM(CASE WHEN tf.status = 'paid' THEN tf.amount ELSE 0 END) as total_paid,
            SUM(CASE WHEN tf.status = 'pending' AND tf.due_date < CURDATE() THEN tf.amount ELSE 0 END) as total_overdue,
            SUM(CASE WHEN tf.status = 'pending' AND tf.due_date >= CURDATE() THEN tf.amount ELSE 0 END) as total_pending,
            COUNT(CASE WHEN tf.status = 'paid' THEN 1 END) as paid_count,
            COUNT(CASE WHEN tf.status = 'pending' AND tf.due_date < CURDATE() THEN 1 END) as overdue_count,
            COUNT(CASE WHEN tf.status = 'pending' AND tf.due_date >= CURDATE() THEN 1 END) as pending_count
        FROM tenant_fees tf
        WHERE 1=1
    ";
    
    $summaryStmt = $conn->prepare($summaryQuery);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    $summary = $summaryResult->fetch_assoc();
    $summaryStmt->close();
    
    // ==================== RETURN RESPONSE ====================
    json_success([
        'fees' => $fees,
        'summary' => [
            'total' => (int)$summary['total_fees'],
            'total_paid' => (float)$summary['total_paid'],
            'total_overdue' => (float)$summary['total_overdue'],
            'total_pending' => (float)$summary['total_pending'],
            'paid_count' => (int)$summary['paid_count'],
            'overdue_count' => (int)$summary['overdue_count'],
            'pending_count' => (int)$summary['pending_count']
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $totalPages ? $page + 1 : null,
            'start_offset' => $offset + 1,
            'end_offset' => min($offset + $limit, $totalRecords)
        ]
    ], "Tenant fees retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_tenant_fees: " . $e->getMessage());
    json_error("Failed to fetch tenant fees: " . $e->getMessage(), 500);
}

// ==================== HELPER FUNCTIONS ====================

function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'overdue' => 'Overdue',
        'paid' => 'Settled',
        'settled' => 'Settled',
        'waived' => 'Waived',
        'cancelled' => 'Cancelled'
    ];
    return $labels[$status] ?? ucfirst($status);
}

function getStatusColor($status) {
    $colors = [
        'pending' => '#f59e0b',
        'overdue' => '#ef4444',
        'paid' => '#10b981',
        'settled' => '#10b981',
        'waived' => '#6b7280',
        'cancelled' => '#6b7280'
    ];
    return $colors[$status] ?? '#6b7280';
}