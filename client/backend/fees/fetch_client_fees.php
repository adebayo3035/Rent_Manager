<?php
// client/backend/fees/fetch_client_fees.php

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication - Client role
    if (!isset($_SESSION['client_logged_in']) || !isset($_SESSION['client_code'])) {
        json_error("Unauthorized", 401);
    }

    $client_code = $_SESSION['client_code'];
    
    // Get client's properties first
    $propertyQuery = "SELECT property_code FROM properties WHERE client_code = ? AND status = 1";
    $propertyStmt = $conn->prepare($propertyQuery);
    $propertyStmt->bind_param("s", $client_code);
    $propertyStmt->execute();
    $propertyResult = $propertyStmt->get_result();
    
    $propertyCodes = [];
    while ($row = $propertyResult->fetch_assoc()) {
        $propertyCodes[] = $row['property_code'];
    }
    $propertyStmt->close();
    
    if (empty($propertyCodes)) {
        json_success([
            'fees' => [],
            'summary' => [],
            'properties' => []
        ], "No properties found for this client");
        return;
    }
    
    // Create placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($propertyCodes), '?'));
    
    // Get filter parameters
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $fee_type_id = isset($_GET['fee_type_id']) ? (int)$_GET['fee_type_id'] : null;
    $is_recurring = isset($_GET['is_recurring']) ? (int)$_GET['is_recurring'] : null;
    $property_code = isset($_GET['property_code']) ? $_GET['property_code'] : null;

    // Build query
    $query = "
        SELECT 
            tf.tenant_fee_id,
            tf.tenant_code,
            tf.apartment_code,
            tf.fee_type_id,
            tf.amount,
            tf.due_date,
            tf.status,
            tf.payment_date,
            tf.payment_method,
            tf.receipt_number,
            tf.notes,
            tf.created_at,
            ft.fee_name,
            ft.fee_code,
            ft.description as fee_description,
            ft.is_mandatory,
            ft.calculation_type,
            ft.is_recurring,
            ft.recurrence_period,
            a.apartment_number,
            a.property_code,
            p.name as property_name,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            t.tenant_code
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        JOIN apartments a ON tf.apartment_code = a.apartment_code
        JOIN properties p ON a.property_code = p.property_code
        JOIN tenants t ON tf.tenant_code = t.tenant_code
        WHERE a.property_code IN ($placeholders)
    ";
    
    // Build parameters array
    $params = $propertyCodes;
    $types = str_repeat('s', count($propertyCodes));
    
    // Add filters to query
    if ($status && in_array($status, ['pending', 'paid', 'overdue', 'waived'])) {
        $query .= " AND tf.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($fee_type_id) {
        $query .= " AND tf.fee_type_id = ?";
        $params[] = $fee_type_id;
        $types .= "i";
    }
    
    if ($is_recurring !== null) {
        $query .= " AND ft.is_recurring = ?";
        $params[] = $is_recurring;
        $types .= "i";
    }
    
    if ($property_code) {
        $query .= " AND p.property_code = ?";
        $params[] = $property_code;
        $types .= "s";
    }
    
    $query .= " ORDER BY tf.due_date ASC, tf.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    // Bind parameters dynamically
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $fees = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate if overdue
        $due_date = new DateTime($row['due_date']);
        $today = new DateTime();
        $is_overdue = ($row['status'] === 'pending' && $due_date < $today);
        
        $fees[] = [
            'tenant_fee_id' => (int)$row['tenant_fee_id'],
            'tenant_code' => $row['tenant_code'],
            'tenant_name' => $row['tenant_name'],
            'tenant_email' => $row['tenant_email'],
            'tenant_phone' => $row['tenant_phone'],
            'fee_type_id' => (int)$row['fee_type_id'],
            'fee_name' => $row['fee_name'],
            'fee_code' => $row['fee_code'],
            'amount' => (float)$row['amount'],
            'due_date' => $row['due_date'],
            'status' => $is_overdue ? 'overdue' : $row['status'],
            'is_mandatory' => (bool)$row['is_mandatory'],
            'is_recurring' => (bool)$row['is_recurring'],
            'recurrence_period' => $row['recurrence_period'],
            'payment_date' => $row['payment_date'],
            'payment_method' => $row['payment_method'],
            'receipt_number' => $row['receipt_number'],
            'notes' => $row['notes'],
            'apartment_number' => $row['apartment_number'],
            'property_code' => $row['property_code'],
            'property_name' => $row['property_name'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    // Get property list for filter dropdown
    $propertyListQuery = "
        SELECT property_code, name 
        FROM properties 
        WHERE client_code = ? AND status = 1
        ORDER BY name ASC
    ";
    $propertyListStmt = $conn->prepare($propertyListQuery);
    $propertyListStmt->bind_param("s", $client_code);
    $propertyListStmt->execute();
    $propertyListResult = $propertyListStmt->get_result();
    
    $propertyList = [];
    while ($row = $propertyListResult->fetch_assoc()) {
        $propertyList[] = $row;
    }
    $propertyListStmt->close();
    
    // Get summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_fees,
            SUM(CASE WHEN tf.status = 'pending' OR (tf.status = 'pending' AND tf.due_date < CURDATE()) THEN tf.amount ELSE 0 END) as total_pending,
            SUM(CASE WHEN tf.status = 'paid' THEN tf.amount ELSE 0 END) as total_paid,
            SUM(CASE WHEN tf.status = 'overdue' OR (tf.status = 'pending' AND tf.due_date < CURDATE()) THEN tf.amount ELSE 0 END) as total_overdue,
            COUNT(CASE WHEN tf.status = 'pending' OR (tf.status = 'pending' AND tf.due_date < CURDATE()) THEN 1 END) as pending_count,
            COUNT(CASE WHEN tf.status = 'paid' THEN 1 END) as paid_count,
            COUNT(CASE WHEN tf.status = 'overdue' OR (tf.status = 'pending' AND tf.due_date < CURDATE()) THEN 1 END) as overdue_count
        FROM tenant_fees tf
        JOIN apartments a ON tf.apartment_code = a.apartment_code
        WHERE a.property_code IN ($placeholders)
    ";
    
    $summary_stmt = $conn->prepare($summary_query);
    if (!$summary_stmt) {
        throw new Exception("Prepare failed for summary: " . $conn->error);
    }
    
    // Bind parameters for summary query using property codes only
    $summary_params = $propertyCodes;
    $summary_types = str_repeat('s', count($propertyCodes));
    
    if (!empty($summary_params)) {
        $summary_stmt->bind_param($summary_types, ...$summary_params);
    }
    
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result->fetch_assoc();
    $summary_stmt->close();
    
    $conn->close();
    
    $response_data = [
        'fees' => $fees,
        'properties' => $propertyList,
        'summary' => [
            'total_fees' => (int)($summary['total_fees'] ?? 0),
            'total_pending' => (float)($summary['total_pending'] ?? 0),
            'total_paid' => (float)($summary['total_paid'] ?? 0),
            'total_overdue' => (float)($summary['total_overdue'] ?? 0),
            'pending_count' => (int)($summary['pending_count'] ?? 0),
            'paid_count' => (int)($summary['paid_count'] ?? 0),
            'overdue_count' => (int)($summary['overdue_count'] ?? 0)
        ],
        'filters' => [
            'available_statuses' => ['pending', 'paid', 'overdue', 'waived'],
            'current_status' => $status,
            'current_property' => $property_code
        ]
    ];
    
    json_success($response_data, "Client fees retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_client_fees: " . $e->getMessage());
    json_error("Failed to fetch fees: " . $e->getMessage(), 500);
}
?>