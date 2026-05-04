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
    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 11px;
        color: #333;
    }

    .container {
        border: 1px solid #ccc;
        padding: 10px;
    }

    .title {
        font-size: 16px;
        font-weight: bold;
    }

    .right {
        text-align: right;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    td {
        padding: 4px;
        vertical-align: top;
    }

    .section-title {
        font-weight: bold;
        font-size: 13px;
        margin-top: 10px;
    }

    .table th {
        border: 1px solid #ccc;
        background: #f2f2f2;
        padding: 5px;
        text-align: left;
    }

    .table td {
        border: 1px solid #ccc;
        padding: 5px;
    }

    .total {
        font-weight: bold;
    }

    .footer {
        text-align: center;
        font-size: 10px;
        margin-top: 10px;
        color: #666;
    }
</style>
</head>

<body>

<div class="container">

<!-- HEADER -->
<table>
    <tr>
        <td width="60%">
            <span class="title">RentEase</span><br>
            Property Management System
        </td>
        <td width="40%" class="right">
            <b>RECEIPT</b><br>
            Receipt No: ' . htmlspecialchars($payment['receipt_number']) . '<br>
            Date: ' . date('d M Y, H:i', strtotime($payment['payment_date'])) . '
        </td>
    </tr>
</table>

<br>

<!-- TENANT INFO -->
<div class="section-title">Tenant Information</div>
<table border="0">
    <tr>
        <td width="25%"><b>Name:</b></td>
        <td width="25%">' . htmlspecialchars($payment['firstname'] . ' ' . $payment['lastname']) . '</td>

        <td width="25%"><b>Phone:</b></td>
        <td width="25%">' . htmlspecialchars($payment['phone']) . '</td>
    </tr>

    <tr>
        <td><b>Email:</b></td>
        <td>' . htmlspecialchars($payment['email']) . '</td>

        <td><b>Apartment:</b></td>
        <td>' . htmlspecialchars($payment['apartment_number']) . '</td>
    </tr>

    <tr>
        <td><b>Property:</b></td>
        <td colspan="3">' . htmlspecialchars($payment['property_name']) . '</td>
    </tr>
</table>

<br>

<!-- PAYMENT DETAILS -->
<div class="section-title">Payment Details</div>

<table class="table">
    <tr>
        <th width="70%">Description</th>
        <th width="30%">Amount (₦)</th>
    </tr>

    <tr>
        <td>' . htmlspecialchars($payment['fee_name']) . ' (' . htmlspecialchars($payment['fee_code']) . ')</td>
        <td>' . number_format($payment['amount'], 2) . '</td>
    </tr>

    <tr class="total">
        <td>Total</td>
        <td>' . number_format($payment['amount'], 2) . '</td>
    </tr>
</table>

<br>

<table>
    <tr>
        <td width="25%"><b>Payment Method:</b></td>
        <td width="25%">' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . '</td>

        <td width="25%"><b>Reference:</b></td>
        <td width="25%">' . htmlspecialchars($payment['reference_number'] ?? 'N/A') . '</td>
    </tr>

    <tr>
        <td><b>Due Date:</b></td>
        <td>' . date('d M Y', strtotime($payment['due_date'])) . '</td>
    </tr>
</table>

<br>

<!-- FOOTER -->
<div class="footer">
    Thank you for your payment.<br>
    This is a system-generated receipt.
</div>

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