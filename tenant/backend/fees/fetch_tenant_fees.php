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

    // ==================== BUILD MAIN QUERY ====================
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
            a.apartment_type_id,
            p.name as property_name,
            p.property_code,
            -- Check if fee is active in property_apartment_type_fees
            COALESCE(patf.is_active, 0) as is_active_in_property,
            patf.amount as configured_amount,
            patf.effective_from,
            patf.effective_to
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        LEFT JOIN apartments a ON tf.apartment_code = a.apartment_code
        LEFT JOIN properties p ON a.property_code = p.property_code
        LEFT JOIN property_apartment_type_fees patf 
            ON p.property_code = patf.property_code 
            AND a.apartment_type_id = patf.apartment_type_id 
            AND tf.fee_type_id = patf.fee_type_id
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
        
        // If overdue and status is still pending, update in memory
        if ($is_overdue && $row['status'] === 'pending') {
            $row['status'] = 'overdue';
        }
        
        // Determine if fee can be paid (must be active in property config)
        $can_pay = ($row['is_active_in_property'] == 1 && $row['status'] !== 'paid' && $row['status'] !== 'waived');
        $is_active = ($row['is_active_in_property'] == 1);
        
        // Check if fee is within effective date range
        $is_within_effective_range = true;
        if ($row['effective_from'] && $row['effective_from'] > date('Y-m-d')) {
            $is_within_effective_range = false;
        }
        if ($row['effective_to'] && $row['effective_to'] < date('Y-m-d')) {
            $is_within_effective_range = false;
        }
        
        // If not within effective range, cannot pay
        if (!$is_within_effective_range) {
            $can_pay = false;
        }

        $fees[] = [
            'tenant_fee_id' => (int)$row['tenant_fee_id'],
            'fee_type_id' => (int)$row['fee_type_id'],
            'fee_name' => $row['fee_name'],
            'fee_code' => $row['fee_code'],
            'amount' => (float)$row['amount'],
            'configured_amount' => $row['configured_amount'] ? (float)$row['configured_amount'] : null,
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
            'property_name' => $row['property_name'],
            // Property fee configuration status
            'is_active_in_property' => (bool)$is_active,
            'can_pay' => (bool)$can_pay,
            'is_within_effective_range' => (bool)$is_within_effective_range,
            'effective_from' => $row['effective_from'],
            'effective_to' => $row['effective_to'],
            'amount_mismatch' => ($row['configured_amount'] && abs($row['amount'] - $row['configured_amount']) > 0.01)
        ];
    }
    $stmt->close();

    // ==================== GET SUMMARY STATISTICS ====================
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

    // ==================== GET ACTIVE FEE TYPES FROM PROPERTY CONFIG ====================
    $active_fees_query = "
        SELECT 
            patf.fee_type_id,
            ft.fee_name,
            ft.fee_code,
            patf.amount,
            patf.is_active,
            patf.effective_from,
            patf.effective_to
        FROM property_apartment_type_fees patf
        JOIN fee_types ft ON patf.fee_type_id = ft.fee_type_id
        JOIN apartments a ON a.apartment_type_id = patf.apartment_type_id
        JOIN tenants t ON t.apartment_code = a.apartment_code
        WHERE t.tenant_code = ?
        AND patf.is_active = 1
        ORDER BY ft.fee_name ASC
    ";
    
    $active_stmt = $conn->prepare($active_fees_query);
    $active_stmt->bind_param("s", $tenant_code);
    $active_stmt->execute();
    $active_result = $active_stmt->get_result();
    
    $active_fee_types = [];
    while ($row = $active_result->fetch_assoc()) {
        $active_fee_types[] = [
            'fee_type_id' => (int)$row['fee_type_id'],
            'fee_name' => $row['fee_name'],
            'fee_code' => $row['fee_code'],
            'amount' => (float)$row['amount'],
            'is_active' => (bool)$row['is_active'],
            'effective_from' => $row['effective_from'],
            'effective_to' => $row['effective_to']
        ];
    }
    $active_stmt->close();

    $conn->close();

    $response_data = [
        'fees' => $fees,
        'active_fee_types' => $active_fee_types,
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