<?php
// client/backend/fees/get_payment_by_fee_id.php
// Get payment details for a fee (for receipt download)

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
    $tenant_fee_id = isset($_GET['tenant_fee_id']) ? (int)$_GET['tenant_fee_id'] : 0;

    if (!$tenant_fee_id) {
        json_error("Fee ID required", 400);
    }

    // Get payment details for this fee, verifying it belongs to client's property
    $query = "
        SELECT 
            p.id as payment_id,
            p.receipt_number,
            p.amount,
            p.payment_date,
            p.payment_method,
            p.reference_number,
            tf.tenant_fee_id,
            tf.fee_type_id,
            ft.fee_name,
            ft.fee_code,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            a.apartment_number,
            pr.name as property_name,
            pr.address as property_address
        FROM tenant_fees tf
        JOIN payments p ON tf.tenant_code = p.tenant_code AND tf.amount = p.amount
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        JOIN tenants t ON tf.tenant_code = t.tenant_code
        JOIN apartments a ON tf.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        WHERE tf.tenant_fee_id = ? 
        AND tf.status = 'paid'
        AND pr.client_code = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $tenant_fee_id, $client_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Payment record not found or you don't have permission", 404);
    }

    $payment = $result->fetch_assoc();
    $stmt->close();

    json_success([
        'payment_id' => $payment['payment_id'],
        'receipt_number' => $payment['receipt_number'],
        'amount' => $payment['amount'],
        'payment_date' => $payment['payment_date'],
        'payment_method' => $payment['payment_method'],
        'reference_number' => $payment['reference_number'],
        'fee_name' => $payment['fee_name'],
        'fee_code' => $payment['fee_code'],
        'tenant_name' => $payment['firstname'] . ' ' . $payment['lastname'],
        'tenant_email' => $payment['email'],
        'tenant_phone' => $payment['phone'],
        'apartment_number' => $payment['apartment_number'],
        'property_name' => $payment['property_name'],
        'property_address' => $payment['property_address']
    ], "Payment details retrieved");

} catch (Exception $e) {
    logActivity("Error in get_payment_by_fee_id (client): " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>