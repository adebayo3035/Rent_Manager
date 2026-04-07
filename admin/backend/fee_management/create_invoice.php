<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

try {
    // Check authentication
    if (!isset($_SESSION['unique_id'])) {
        json_error("Not logged in", 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error("Invalid input data", 400);
    }
    
    $tenant_code = $input['tenant_code'] ?? '';
    $apartment_code = $input['apartment_code'] ?? '';
    $fees_to_invoice = $input['fee_ids'] ?? []; // Array of tenant_fee_ids
    
    if (empty($tenant_code) || empty($fees_to_invoice)) {
        json_error("Tenant code and fees are required", 400);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Generate invoice number
        $invoice_number = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
        
        // Get total amount
        $total_amount = 0;
        $placeholders = implode(',', array_fill(0, count($fees_to_invoice), '?'));
        $fee_query = "SELECT SUM(amount) as total FROM tenant_fees WHERE tenant_fee_id IN ($placeholders) AND status = 'pending'";
        $fee_stmt = $conn->prepare($fee_query);
        $fee_stmt->bind_param(str_repeat('i', count($fees_to_invoice)), ...$fees_to_invoice);
        $fee_stmt->execute();
        $fee_result = $fee_stmt->get_result();
        $total = $fee_result->fetch_assoc();
        $total_amount = $total['total'];
        $fee_stmt->close();
        
        // Create invoice
        $invoice_query = "INSERT INTO fee_invoices (invoice_number, tenant_code, apartment_code, invoice_date, due_date, subtotal, total_amount, status, created_by) 
                          VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), ?, ?, 'sent', ?)";
        $invoice_stmt = $conn->prepare($invoice_query);
        $invoice_stmt->bind_param("sssddi", $invoice_number, $tenant_code, $apartment_code, $total_amount, $total_amount, $_SESSION['unique_id']);
        $invoice_stmt->execute();
        $invoice_id = $invoice_stmt->insert_id;
        $invoice_stmt->close();
        
        // Add invoice items
        $item_query = "INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount) 
                       SELECT ?, tf.fee_type_id, ft.fee_name, tf.amount 
                       FROM tenant_fees tf
                       JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
                       WHERE tf.tenant_fee_id IN ($placeholders)";
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bind_param("i" . str_repeat('i', count($fees_to_invoice)), $invoice_id, ...$fees_to_invoice);
        $item_stmt->execute();
        $item_stmt->close();
        
        // Update tenant fees status
        $update_query = "UPDATE tenant_fees SET status = 'invoiced', invoice_id = ? WHERE tenant_fee_id IN ($placeholders)";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("i" . str_repeat('i', count($fees_to_invoice)), $invoice_id, ...$fees_to_invoice);
        $update_stmt->execute();
        $update_stmt->close();
        
        $conn->commit();
        
        logActivity("Invoice created: $invoice_number for tenant $tenant_code");
        json_success(['invoice_id' => $invoice_id, 'invoice_number' => $invoice_number], "Invoice created successfully");
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    logActivity("Error in create_invoice: " . $e->getMessage());
    json_error($e->getMessage(), 500);
}
?>