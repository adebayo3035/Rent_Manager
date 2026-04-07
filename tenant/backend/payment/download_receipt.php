<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        die("Not logged in");
    }
    
    // Check if user is a tenant
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Tenant') {
        die("Unauthorized access");
    }

    $tenant_code = $_SESSION['tenant_code'] ?? null;
    $receipt_number = $_GET['receipt_number'] ?? null;

    if (!$receipt_number) {
        die("Receipt number is required");
    }

    // Query to get payment details from both tables using receipt_number
    $query = "
        SELECT 
            p.id as payment_id,
            p.tenant_code,
            p.apartment_code,
            p.amount,
            p.payment_date,
            p.payment_method,
            p.receipt_number,
            p.payment_status,
            p.receipt_number,
            p.description,
            rp.payment_type,
            rp.status as rent_payment_status,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            a.apartment_number,
            pr.name as property_name,
            pr.address as property_address
        FROM payments p
        JOIN rent_payments rp ON p.receipt_number = rp.receipt_number
        JOIN tenants t ON p.tenant_code = t.tenant_code
        JOIN apartments a ON p.apartment_code = a.apartment_code
        JOIN properties pr ON a.property_code = pr.property_code
        WHERE p.receipt_number = ? 
        AND p.tenant_code = ? 
        AND p.payment_status = 'completed'
        AND rp.payment_type IN ('rent', 'security_deposit')
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $receipt_number, $tenant_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        die("Receipt not found");
    }

    $payment = $result->fetch_assoc();
    $stmt->close();

    // Determine receipt title based on payment type
    $receipt_title = '';
    $description = '';
    
    switch($payment['payment_type']) {
        case 'rent':
            $receipt_title = 'RENT PAYMENT RECEIPT';
            $description = 'Monthly Rent Payment';
            break;
        case 'security_deposit':
            $receipt_title = 'SECURITY DEPOSIT RECEIPT';
            $description = 'Security Deposit Payment';
            break;
        default:
            $receipt_title = 'PAYMENT RECEIPT';
            $description = $payment['description'] ?: 'Payment';
    }

    // Format amount with naira symbol
    $naira = '&#8358;';
    $amount_display = $naira . number_format($payment['amount'], 2);

    // Generate HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { color: #667eea; margin: 0; }
            .receipt-title { text-align: center; margin: 20px 0; }
            .receipt-title h2 { color: #333; margin: 0; }
            .company-info { text-align: center; margin-bottom: 30px; }
            .receipt-details { margin: 20px 0; }
            .receipt-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .receipt-table th, .receipt-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
            .receipt-table th { background-color: #f2f2f2; }
            .total-row { font-weight: bold; background-color: #f9f9f9; }
            .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
            .payment-details { margin-top: 20px; }
            .payment-details p { margin: 5px 0; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>RentEase</h1>
            <p>Property Management System</p>
        </div>
        
        <div class="receipt-title">
            <h2>' . $receipt_title . '</h2>
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
                    <td>' . htmlspecialchars($description) . '</td>
                    <td>' . $amount_display . '</td>
                </tr>
                <tr class="total-row">
                    <td><strong>Total</strong></td>
                    <td><strong>' . $amount_display . '</strong></td>
                </tr>
            </tbody>
        </table>
        
        <div class="payment-details">
            <p><strong>Payment Method:</strong> ' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . '</p>
            <p><strong>Transaction Status:</strong> ' . strtoupper($payment['payment_status']) . '</p>
            <p><strong>Payment Type:</strong> ' . str_replace('_', ' ', ucfirst($payment['payment_type'])) . '</p>
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
    $pdf->Output("Receipt_{$payment['receipt_number']}.pdf", 'D');
    
    exit();

} catch (Exception $e) {
    logActivity("Error in download_rent_receipt: " . $e->getMessage());
    die("Failed to generate receipt: " . $e->getMessage());
}
?>