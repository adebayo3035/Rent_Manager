<?php
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
        die("Unauthorized access");
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    $payment_id = $_GET['payment_id'] ?? 0;

    if (!$payment_id) {
        die("Payment ID is required");
    }

    // Get payment and fee details
    $query = "
        SELECT 
            p.*,
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
        FROM payments p
        JOIN tenant_fees tf ON p.tenant_code = tf.tenant_code AND p.amount = tf.amount
        JOIN fee_types ft ON tf.fee_type_id = ft.fee_type_id
        JOIN tenants t ON p.tenant_code = t.tenant_code
        JOIN apartments a ON p.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        WHERE p.id = ? AND p.tenant_code = ? AND p.payment_status = 'completed'
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $payment_id, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        die("Receipt not found");
    }

    $payment = $result->fetch_assoc();
    $stmt->close();

    // Generate HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { color: #667eea; margin: 0; }
            .receipt-title { text-align: center; margin: 20px 0; }
            .receipt-title h2 { color: #333; }
            .company-info { text-align: center; margin-bottom: 30px; }
            .receipt-details { margin: 20px 0; }
            .receipt-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .receipt-table th, .receipt-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .receipt-table th { background-color: #f2f2f2; }
            .total-row { font-weight: bold; background-color: #f9f9f9; }
            .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>RentEase</h1>
            <p>Property Management System</p>
        </div>
        
        <div class="receipt-title">
            <h2>FEE PAYMENT RECEIPT</h2>
        </div>
        
        <div class="company-info">
            <p><strong>Receipt Number:</strong> ' . htmlspecialchars($payment['receipt_number']) . '</p>
            <p><strong>Payment Date:</strong> ' . date('F j, Y g:i A', strtotime($payment['payment_date'])) . '</p>
        </div>
        
        <div class="receipt-details">
            <h3>Tenant Information</h3>
            <p><strong>Name:</strong> ' . htmlspecialchars($payment['firstname'] . ' ' . $payment['lastname']) . '</p>
            <p><strong>Email:</strong> ' . htmlspecialchars($payment['email']) . '</p>
            <p><strong>Phone:</strong> ' . htmlspecialchars($payment['phone']) . '</p>
            <p><strong>Property:</strong> ' . htmlspecialchars($payment['property_name']) . '</p>
            <p><strong>Apartment:</strong> ' . htmlspecialchars($payment['apartment_number']) . '</p>
        </div>
        
        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>' . htmlspecialchars($payment['fee_name']) . ' (' . htmlspecialchars($payment['fee_code']) . ')</td>
                    <td>N' . number_format($payment['amount'], 2) . '</td>
                </tr>
                <tr class="total-row">
                    <td><strong>Total</strong></td>
                    <td><strong>N' . number_format($payment['amount'], 2) . '</strong></td>
                </tr>
            </tbody>
        </table>
        
        <div class="payment-details">
            <p><strong>Payment Method:</strong> ' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . '</p>
            <p><strong>Reference Number:</strong> ' . htmlspecialchars($payment['reference_number'] ?? 'N/A') . '</p>
            <p><strong>Due Date:</strong> ' . date('F j, Y', strtotime($payment['due_date'])) . '</p>
        </div>
        
        <div class="footer">
            <p>Thank you for your payment!</p>
            <p>This is a computer-generated receipt and does not require a signature.</p>
        </div>
    </body>
    </html>
    ';

    // Output as PDF
    require_once __DIR__ . '/../../../vendor/autoload.php';
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output("Fee_Receipt_{$payment['receipt_number']}.pdf", 'D');
    
    exit();

} catch (Exception $e) {
    logActivity("Error in download_fee_receipt: " . $e->getMessage());
    die("Failed to generate receipt");
}
?>