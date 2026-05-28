<?php
// client/backend/payments/fetch_payment_history.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }
    
    $client_code = $_SESSION['client_code'];
    
    // Get pagination and filter parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 20;
    $offset = ($page - 1) * $limit;
    
    // Filter parameters
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $year_filter = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $property_filter = isset($_GET['property_code']) ? trim($_GET['property_code']) : '';
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'payment_date';
    $sort_order = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
    
    // Validate sort_by to prevent SQL injection
    $allowed_sort_columns = ['payment_date', 'amount', 'status', 'payment_method', 'period_number', 'property_name'];
    if (!in_array($sort_by, $allowed_sort_columns)) {
        $sort_by = 'payment_date';
    }
    
    // Map sort_by to actual column
    $sort_column_map = [
        'payment_date' => 'payment_date',
        'amount' => 'amount',
        'status' => 'status',
        'payment_method' => 'payment_method',
        'period_number' => 'period_number',
        'property_name' => 'property_name'
    ];
    $sort_column = $sort_column_map[$sort_by];
    
    // Amount due expression
    $amountDueExpression = "
        COALESCE(
            NULLIF(rpt.amount_paid, 0),
            NULLIF(rp.payment_amount_per_period, 0),
            NULLIF(t.payment_amount_per_period, 0),
            0
        )
    ";
    
    // Base query
    $baseQuery = "
        FROM rent_payment_tracker rpt
        JOIN tenants t ON rpt.tenant_code = t.tenant_code
        JOIN apartments a ON rpt.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        LEFT JOIN rent_payments rp ON rpt.rent_payment_id = rp.rent_payment_id
        WHERE pr.client_code = ?
        AND pr.status = 1
        AND rpt.status IN ('paid', 'pending_verification', 'failed', 'approved')
        AND COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at) IS NOT NULL
    ";
    
    // Build filters
    $params = [$client_code];
    $types = "s";
    
    // Status filter
    if (!empty($status_filter) && $status_filter !== 'all') {
        $baseQuery .= " AND rpt.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }
    
    // Year filter
    if ($year_filter > 0) {
        $baseQuery .= " AND YEAR(COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at)) = ?";
        $params[] = $year_filter;
        $types .= "i";
    }
    
    // Property filter
    if (!empty($property_filter)) {
        $baseQuery .= " AND pr.property_code = ?";
        $params[] = $property_filter;
        $types .= "s";
    }
    
    // Search filter (by receipt number, reference, or tenant name)
    if (!empty($search_term)) {
        $search_term = "%{$search_term}%";
        $baseQuery .= " AND (
            COALESCE(rp.receipt_number, CONCAT('RCP-', rpt.payment_id)) LIKE ? 
            OR rpt.payment_reference LIKE ? 
            OR CONCAT(t.firstname, ' ', t.lastname) LIKE ?
        )";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "sss";
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(DISTINCT rpt.tracker_id) as total " . $baseQuery;
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    // Calculate pagination info
    $totalPages = ceil($totalRecords / $limit);
    $from = $totalRecords > 0 ? $offset + 1 : 0;
    $to = min($offset + $limit, $totalRecords);
    
    // Main query with sorting and pagination
    $query = "
        SELECT 
            rpt.tracker_id as id,
            {$amountDueExpression} as amount,
            COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at) as payment_date,
            rpt.payment_date as raw_payment_date,
            rpt.verified_at,
            rpt.created_at,
            CASE
                WHEN rpt.status = 'paid' THEN 'completed'
                WHEN rpt.status = 'pending_verification' THEN 'pending'
                WHEN rpt.status = 'approved' THEN 'completed'
                ELSE rpt.status
            END as status,
            rpt.status as raw_status,
            COALESCE(rp.receipt_number, CONCAT('RCP-', rpt.payment_id)) as receipt_number,
            rpt.payment_reference as reference_number,
            rpt.period_number,
            rpt.start_date as period_start_date,
            rpt.end_date as period_end_date,
            rpt.payment_method,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            pr.name as property_name,
            pr.property_code,
            a.apartment_number,
            rp.payment_amount_per_period as scheduled_amount,
            rpt.amount_paid as actual_paid_amount,
            rpt.admin_notes as notes,
            rpt.updated_at as last_updated
        " . $baseQuery . "
        ORDER BY 
            CASE 
                WHEN ? = 'payment_date' THEN COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at)
                WHEN ? = 'amount' THEN {$amountDueExpression}
                WHEN ? = 'property_name' THEN pr.name
                ELSE COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at)
            END {$sort_order},
            rpt.tracker_id DESC
        LIMIT ? OFFSET ?
    ";
    
    // Add sort parameters
    $params[] = $sort_column;
    $params[] = $sort_column;
    $params[] = $sort_column;
    $params[] = $limit;
    $params[] = $offset;
    $types .= "sssii";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $payments = [];
    $summary = [
        'total_paid' => 0,
        'total_pending' => 0,
        'total_failed' => 0,
        'total_amount' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        // Format dates
        if ($row['payment_date']) {
            $row['formatted_payment_date'] = date('M d, Y', strtotime($row['payment_date']));
            $row['payment_date_full'] = date('F d, Y h:i A', strtotime($row['payment_date']));
        }
        
        if ($row['period_start_date']) {
            $row['formatted_period'] = date('M d', strtotime($row['period_start_date'])) . ' - ' . date('M d, Y', strtotime($row['period_end_date']));
        }
        
        // Format amount
        $row['formatted_amount'] = '₦' . number_format($row['amount'], 2);
        
        // Add to summary
        $amount = floatval($row['amount']);
        $summary['total_amount'] += $amount;
        
        switch ($row['raw_status']) {
            case 'paid':
            case 'approved':
                $summary['total_paid'] += $amount;
                break;
            case 'pending_verification':
                $summary['total_pending'] += $amount;
                break;
            case 'failed':
                $summary['total_failed'] += $amount;
                break;
        }
        
        $payments[] = $row;
    }
    $stmt->close();
    
    // Get available years for filter
    $yearQuery = "
        SELECT DISTINCT YEAR(COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at)) as year
        FROM rent_payment_tracker rpt
        JOIN apartments a ON rpt.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        WHERE pr.client_code = ?
        AND pr.status = 1
        AND COALESCE(rpt.payment_date, rpt.verified_at, rpt.created_at) IS NOT NULL
        ORDER BY year DESC
    ";
    $yearStmt = $conn->prepare($yearQuery);
    $yearStmt->bind_param("s", $client_code);
    $yearStmt->execute();
    $yearResult = $yearStmt->get_result();
    $available_years = [];
    while ($row = $yearResult->fetch_assoc()) {
        $available_years[] = $row['year'];
    }
    $yearStmt->close();
    
    // Get properties for filter
    $propertyQuery = "
        SELECT DISTINCT pr.property_code, pr.name
        FROM properties pr
        WHERE pr.client_code = ?
        AND pr.status = 1
        ORDER BY pr.name
    ";
    $propertyStmt = $conn->prepare($propertyQuery);
    $propertyStmt->bind_param("s", $client_code);
    $propertyStmt->execute();
    $propertyResult = $propertyStmt->get_result();
    $properties = [];
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }
    $propertyStmt->close();
    
    // Return success response with pagination data
    json_success([
        'payments' => $payments,
        'summary' => [
            'total_paid' => '₦' . number_format($summary['total_paid'], 2),
            'total_pending' => '₦' . number_format($summary['total_pending'], 2),
            'total_failed' => '₦' . number_format($summary['total_failed'], 2),
            'total_amount' => '₦' . number_format($summary['total_amount'], 2),
            'total_records' => $totalRecords,
            'total_paid_count' => count(array_filter($payments, fn($p) => in_array($p['raw_status'], ['paid', 'approved']))),
            'total_pending_count' => count(array_filter($payments, fn($p) => $p['raw_status'] === 'pending_verification')),
            'total_failed_count' => count(array_filter($payments, fn($p) => $p['raw_status'] === 'failed'))
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $totalPages ? $page + 1 : null
        ],
        'filters' => [
            'available_statuses' => ['all', 'paid', 'pending_verification', 'failed', 'approved'],
            'available_years' => $available_years,
            'properties' => $properties,
            'current_filters' => [
                'status' => $status_filter ?: 'all',
                'year' => $year_filter,
                'property_code' => $property_filter,
                'search' => $search_term,
                'sort_by' => $sort_by,
                'sort_order' => strtolower($sort_order)
            ]
        ]
    ], "Payment history retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error fetching payment history: " . $e->getMessage());
    json_error("Failed to fetch payment history: " . $e->getMessage(), 500);
}