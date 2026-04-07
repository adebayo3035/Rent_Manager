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
    $tenant_fee_id = $_GET['tenant_fee_id'] ?? 0;

    if (!$tenant_fee_id) {
        json_error("Fee ID required", 400);
    }

    // Get payment details for this fee
    $query = "
        SELECT p.id, p.receipt_number, p.amount, p.payment_date
        FROM payments p
        JOIN tenant_fees tf ON p.tenant_code = tf.tenant_code AND p.amount = tf.amount
        WHERE tf.tenant_fee_id = ? AND tf.tenant_code = ? AND tf.status = 'paid'
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $tenant_fee_id, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        json_error("Payment record not found", 404);
    }

    $payment = $result->fetch_assoc();
    $stmt->close();

    json_success([
        'payment_id' => $payment['id'],
        'receipt_number' => $payment['receipt_number'],
        'amount' => $payment['amount'],
        'payment_date' => $payment['payment_date']
    ], "Payment details retrieved");

} catch (Exception $e) {
    logActivity("Error in get_payment_by_fee_id: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>