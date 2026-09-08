<?php
// fetch_single_tenant_fee.php - Fetch single tenant fee details

header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (!isset($_SESSION)) session_start();
rateLimiter();

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $tenant_fee_id = isset($_GET['tenant_fee_id']) ? (int)$_GET['tenant_fee_id'] : 0;
    
    if ($tenant_fee_id <= 0) {
        json_error("Invalid tenant fee ID", 400);
    }
    
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
            tf.updated_at,
            ft.fee_name,
            ft.fee_code,
            ft.description as fee_description,
            ft.is_mandatory,
            ft.calculation_type,
            ft.is_recurring,
            ft.recurrence_period,
            CONCAT(t.firstname, ' ', t.lastname) as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            t.lease_start_date,
            t.lease_end_date,
            a.apartment_number,
            a.apartment_code,
            p.name as property_name,
            p.property_code
        FROM tenant_fees tf
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        JOIN tenants t ON tf.tenant_code = t.tenant_code
        LEFT JOIN apartments a ON tf.apartment_code = a.apartment_code
        LEFT JOIN properties p ON a.property_code = p.property_code
        WHERE tf.tenant_fee_id = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $tenant_fee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        json_error("Tenant fee not found", 404);
    }
    
    $fee = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate display status
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $due_date = new DateTime($fee['due_date']);
    $due_date->setTime(0, 0, 0);
    
    $display_status = $fee['status'];
    $is_overdue = false;
    $days_until_due = 0;
    $status_message = '';
    
    // Calculate days difference
    $diff = $today->diff($due_date);
    $days_until_due = $diff->days;
    
    if ($today < $due_date) {
        $status_message = "Due in {$days_until_due} days";
    } elseif ($today > $due_date) {
        $status_message = "Overdue by {$days_until_due} days";
    } else {
        $status_message = "Due today!";
    }
    
    // Determine display status
    if ($fee['status'] === 'paid') {
        $display_status = 'settled';
        $status_message = "Paid on " . date('F j, Y', strtotime($fee['payment_date']));
    } elseif ($fee['status'] === 'pending') {
        if ($due_date < $today) {
            $display_status = 'overdue';
            $is_overdue = true;
        } else {
            $display_status = 'pending';
        }
    }
    
    // Format the response
    $response = [
        'tenant_fee_id' => (int)$fee['tenant_fee_id'],
        'tenant_code' => $fee['tenant_code'],
        'tenant_name' => $fee['tenant_name'],
        'tenant_email' => $fee['tenant_email'],
        'tenant_phone' => $fee['tenant_phone'],
        'apartment_number' => $fee['apartment_number'],
        'apartment_code' => $fee['apartment_code'],
        'property_name' => $fee['property_name'],
        'property_code' => $fee['property_code'],
        'fee_type_id' => (int)$fee['fee_type_id'],
        'fee_name' => $fee['fee_name'],
        'fee_code' => $fee['fee_code'],
        'fee_description' => $fee['fee_description'],
        'amount' => (float)$fee['amount'],
        'due_date' => $fee['due_date'],
        'status' => $fee['status'], // Original DB status
        'display_status' => $display_status, // Calculated display status
        'is_overdue' => $is_overdue,
        'is_mandatory' => (bool)$fee['is_mandatory'],
        'is_recurring' => (bool)$fee['is_recurring'],
        'recurrence_period' => $fee['recurrence_period'],
        'calculation_type' => $fee['calculation_type'],
        'payment_date' => $fee['payment_date'],
        'payment_method' => $fee['payment_method'],
        'receipt_number' => $fee['receipt_number'],
        'notes' => $fee['notes'],
        'lease_start_date' => $fee['lease_start_date'],
        'lease_end_date' => $fee['lease_end_date'],
        'created_at' => $fee['created_at'],
        'updated_at' => $fee['updated_at'],
        'status_message' => $status_message,
        'days_until_due' => $days_until_due,
        'status_label' => getStatusLabel($display_status),
        'status_color' => getStatusColor($display_status)
    ];
    
    // Add formatted dates
    $response['due_date_formatted'] = date('F j, Y', strtotime($fee['due_date']));
    $response['amount_formatted'] = '₦' . number_format($fee['amount'], 2);
    
    if ($fee['payment_date']) {
        $response['payment_date_formatted'] = date('F j, Y g:i A', strtotime($fee['payment_date']));
    }
    
    json_success(['fee' => $response], "Tenant fee retrieved successfully");
    
} catch (Exception $e) {
    logActivity("Error in fetch_single_tenant_fee: " . $e->getMessage());
    json_error("Failed to fetch tenant fee: " . $e->getMessage(), 500);
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
        'pending' => '#f59e0b',  // Amber/Warning
        'overdue' => '#ef4444',  // Red/Danger
        'paid' => '#10b981',     // Green/Success
        'settled' => '#10b981',  // Green/Success
        'waived' => '#6b7280',   // Gray
        'cancelled' => '#6b7280' // Gray
    ];
    return $colors[$status] ?? '#6b7280';
}