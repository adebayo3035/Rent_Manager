<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        json_error("Not logged in", 401);
    }

    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        json_error("Unauthorized access", 403);
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    if (!$tenant_code) {
        json_error("Tenant code not found", 400);
    }

    // Get filter parameters
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $fee_type_id = isset($_GET['fee_type_id']) ? (int)$_GET['fee_type_id'] : null;
    $is_recurring = isset($_GET['is_recurring']) ? (int)$_GET['is_recurring'] : null;

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
            p.name as property_name
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        LEFT JOIN apartments a ON tf.apartment_code = a.apartment_code
        LEFT JOIN properties p ON a.property_code = p.property_code
        WHERE tf.tenant_code = ?
    ";
    
    $params = [$tenant_code];
    $types = "s";

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

    $query .= " ORDER BY tf.due_date ASC, tf.created_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $fees = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate if overdue
        $due_date = new DateTime($row['due_date']);
        $today = new DateTime();
        $is_overdue = ($row['status'] === 'pending' && $due_date < $today);
        
        // If overdue and status is still pending, update in memory (optional: update DB)
        if ($is_overdue && $row['status'] === 'pending') {
            $row['status'] = 'overdue';
        }
        
        $fees[] = [
            'tenant_fee_id' => (int)$row['tenant_fee_id'],
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
            'property_name' => $row['property_name']
        ];
    }
    $stmt->close();

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
        WHERE tf.tenant_code = ?
    ";
    
    $summary_stmt = $conn->prepare($summary_query);
    $summary_stmt->bind_param("s", $tenant_code);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result->fetch_assoc();
    $summary_stmt->close();

    $conn->close();

    $response_data = [
        'fees' => $fees,
        'summary' => [
            'total_fees' => (int)($summary['total_fees'] ?? 0),
            'total_pending' => (float)($summary['total_pending'] ?? 0),
            'total_paid' => (float)($summary['total_paid'] ?? 0),
            'total_overdue' => (float)($summary['total_overdue'] ?? 0),
            'pending_count' => (int)($summary['pending_count'] ?? 0),
            'paid_count' => (int)($summary['paid_count'] ?? 0),
            'overdue_count' => (int)($summary['overdue_count'] ?? 0)
        ]
    ];

    json_success($response_data, "Tenant fees retrieved successfully");

} catch (Exception $e) {
    logActivity("Error in fetch_tenant_fees: " . $e->getMessage());
    json_error("Failed to fetch tenant fees", 500);
}
?>