<?php
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';

session_start();

logActivity("========== DOWNLOAD RECEIPT - START ==========");

try {
    // Check authentication
    if (!isset($_SESSION['tenant_code'])) {
        logActivity("ERROR: No tenant code in session");
        header('Location: ../login.php');
        exit();
    }

    // CORRECT PATH to TCPDF - from tenant/backend/payment/ to root vendor folder
    // Current file: /Rent_Manager/tenant/backend/payment/download_receipt.php
    // Target: /Rent_Manager/vendor/tecnickcom/tcpdf/tcpdf.php
    $tcpdfPath = __DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php';
    
    if (!file_exists($tcpdfPath)) {
    logActivity("ERROR: TCPDF library not found at: " . $tcpdfPath);
    
    // Fallback to HTML receipt instead of PDF
    logActivity("Falling back to HTML receipt generation");
    
    // FIXED: Pass all required parameters
    $tenant_code = $_SESSION['tenant_code'];
    $receipt_number = isset($_GET['receipt_number']) ? trim($_GET['receipt_number']) : null;
    $tracker_id = isset($_GET['tracker_id']) ? (int) $_GET['tracker_id'] : 0;
    
    $receipt_data = getReceiptData($conn, $tenant_code, $receipt_number, $tracker_id);
    
    if ($receipt_data) {
        generateHTMLReceipt($receipt_data);
    } else {
        $_SESSION['error'] = "Receipt not found.";
        header('Location: ../payments.php');
    }
    exit();
}

    require_once $tcpdfPath;

    $tenant_code = $_SESSION['tenant_code'];
    $receipt_number = isset($_GET['receipt_number']) ? trim($_GET['receipt_number']) : null;
    $tracker_id = isset($_GET['tracker_id']) ? (int) $_GET['tracker_id'] : 0;

    logActivity("Tenant Code: {$tenant_code}, Receipt: {$receipt_number}, Tracker ID: {$tracker_id}");

    if (!$receipt_number && !$tracker_id) {
        logActivity("ERROR: No receipt number or tracker ID provided");
        $_SESSION['error'] = "Receipt information not found.";
        header('Location: ../payments.php');
        exit();
    }

    // Fetch receipt data
    $receipt_data = getReceiptData($conn, $tenant_code, $receipt_number, $tracker_id);

    if (!$receipt_data) {
        logActivity("ERROR: Receipt not found for tenant: {$tenant_code}");
        $_SESSION['error'] = "Receipt not found.";
        header('Location: ../payments.php');
        exit();
    }

    logActivity("Receipt data found - Type: " . ($receipt_data['receipt_type'] ?? 'period_payment'));

    // Generate PDF receipt
    generatePDFReceipt($receipt_data);

} catch (Exception $e) {
    logActivity("ERROR in download_receipt: " . $e->getMessage());
    $_SESSION['error'] = "Failed to generate receipt: " . $e->getMessage();
    header('Location: ../payments.php');
    exit();
}

/**
 * Fetch receipt data from database
 */
function getReceiptData($conn, $tenant_code, $receipt_number, $tracker_id) {
    $receipt_data = null;

    // Try to find by tracker_id first (for period payments)
    if ($tracker_id) {
        $query = "
            SELECT 
                t.tracker_id,
                t.period_number,
                t.start_date,
                t.end_date,
                t.amount_paid,
                t.payment_date,
                t.payment_method,
                t.payment_reference,
                t.status,
                t.verified_at,
                t.verified_by,
                r.rent_payment_id,
                r.receipt_number as rent_receipt_number,
                r.amount as annual_rent,
                ten.tenant_code,
                ten.firstname,
                ten.lastname,
                ten.email,
                ten.phone,
                ten.lease_start_date,
                ten.lease_end_date,
                a.apartment_number,
                a.apartment_code,
                p.name as property_name,
                p.address as property_address,
                CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
                ag.phone as agent_phone,
                ag.email as agent_email
            FROM rent_payment_tracker t
            JOIN rent_payments r ON t.rent_payment_id = r.rent_payment_id
            JOIN tenants ten ON t.tenant_code = ten.tenant_code
            JOIN apartments a ON t.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            LEFT JOIN agents ag ON p.agent_code = ag.agent_code
            WHERE t.tracker_id = ? 
            AND t.tenant_code = ?
            AND t.status = 'paid'
            LIMIT 1
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $tracker_id, $tenant_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $receipt_data = $result->fetch_assoc();
        $stmt->close();
    }

    // If not found by tracker_id, try by receipt_number
    if (!$receipt_data && $receipt_number) {
        // Check in rent_payments (initial rent)
        $query = "
            SELECT 
                NULL as tracker_id,
                NULL as period_number,
                NULL as start_date,
                NULL as end_date,
                r.amount_paid as amount_paid,
                r.payment_date,
                r.payment_method,
                r.reference_number as payment_reference,
                r.status,
                NULL as verified_at,
                NULL as verified_by,
                r.rent_payment_id,
                r.receipt_number as rent_receipt_number,
                r.amount as annual_rent,
                ten.tenant_code,
                ten.firstname,
                ten.lastname,
                ten.email,
                ten.phone,
                ten.lease_start_date,
                ten.lease_end_date,
                a.apartment_number,
                a.apartment_code,
                p.name as property_name,
                p.address as property_address,
                CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
                ag.phone as agent_phone,
                ag.email as agent_email,
                'initial_rent' as receipt_type
            FROM rent_payments r
            JOIN tenants ten ON r.tenant_code = ten.tenant_code
            JOIN apartments a ON r.apartment_code = a.apartment_code
            JOIN properties p ON a.property_code = p.property_code
            LEFT JOIN agents ag ON p.agent_code = ag.agent_code
            WHERE r.receipt_number = ? 
            AND r.tenant_code = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $receipt_number, $tenant_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $receipt_data = $result->fetch_assoc();
        $stmt->close();
    }

    // If still not found, check in payments table (security deposit)
    if (!$receipt_data && $receipt_number) {
        $query = "
            SELECT 
                NULL as tracker_id,
                NULL as period_number,
                NULL as start_date,
                NULL as end_date,
                p.amount as amount_paid,
                p.payment_date,
                p.payment_method,
                p.reference_number as payment_reference,
                p.payment_status as status,
                NULL as verified_at,
                NULL as verified_by,
                NULL as rent_payment_id,
                p.receipt_number as rent_receipt_number,
                NULL as annual_rent,
                ten.tenant_code,
                ten.firstname,
                ten.lastname,
                ten.email,
                ten.phone,
                ten.lease_start_date,
                ten.lease_end_date,
                a.apartment_number,
                a.apartment_code,
                pr.name as property_name,
                pr.address as property_address,
                CONCAT(ag.firstname, ' ', ag.lastname) as agent_name,
                ag.phone as agent_phone,
                ag.email as agent_email,
                'security_deposit' as receipt_type
            FROM payments p
            JOIN tenants ten ON p.tenant_code = ten.tenant_code
            JOIN apartments a ON p.apartment_code = a.apartment_code
            JOIN properties pr ON a.property_code = pr.property_code
            LEFT JOIN agents ag ON pr.agent_code = ag.agent_code
            WHERE p.receipt_number = ? 
            AND p.tenant_code = ?
            AND p.payment_category = 'security_deposit'
            AND p.payment_status = 'completed'
            LIMIT 1
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $receipt_number, $tenant_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $receipt_data = $result->fetch_assoc();
        $stmt->close();
    }
    
    return $receipt_data;
}

/**
 * Generate HTML receipt (fallback when TCPDF is not available)
 */
function generateHTMLReceipt($data) {
    $receipt_type = $data['receipt_type'] ?? ($data['period_number'] ? 'period_payment' : 'initial_rent');
    
    if ($receipt_type === 'security_deposit') {
        $receipt_title = 'SECURITY DEPOSIT RECEIPT';
    } elseif ($receipt_type === 'initial_rent') {
        $receipt_title = 'INITIAL RENT PAYMENT RECEIPT';
    } else {
        $receipt_title = 'RENT PAYMENT RECEIPT';
    }
    
    $period_display = '';
    if ($data['start_date'] && $data['end_date']) {
        $period_display = date('F j, Y', strtotime($data['start_date'])) . ' - ' . date('F j, Y', strtotime($data['end_date']));
    }
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Payment Receipt</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "Helvetica Neue", Arial, sans-serif;
                background: #f5f7fb;
                padding: 40px;
            }
            .receipt-container {
                max-width: 800px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            .receipt-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            .receipt-header h1 {
                font-size: 28px;
                margin-bottom: 5px;
            }
            .receipt-body {
                padding: 30px;
            }
            .receipt-title {
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 15px;
                border-bottom: 2px solid #eef2f6;
            }
            .receipt-title h2 {
                color: #1a1f36;
                font-size: 20px;
            }
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 30px;
            }
            .info-box {
                background: #f8fafc;
                padding: 15px;
                border-radius: 12px;
            }
            .info-box h4 {
                color: #64748b;
                font-size: 12px;
                text-transform: uppercase;
                margin-bottom: 10px;
            }
            .info-box p {
                color: #1e293b;
                font-size: 14px;
                margin: 5px 0;
            }
            .payment-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .payment-table th {
                background: #f1f5f9;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                color: #475569;
            }
            .payment-table td {
                padding: 12px;
                border-bottom: 1px solid #e2e8f0;
            }
            .total-row {
                background: #f8fafc;
                font-weight: bold;
            }
            .amount {
                text-align: right;
            }
            .footer {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #eef2f6;
                text-align: center;
                font-size: 12px;
                color: #94a3b8;
            }
            .status-badge {
                display: inline-block;
                background: #10b981;
                color: white;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
            @media print {
                body {
                    background: white;
                    padding: 0;
                }
                .receipt-container {
                    box-shadow: none;
                    border-radius: 0;
                }
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <div class="receipt-container">
            <div class="receipt-header">
                <h1>RentFlow Pro</h1>
                <p>Professional Property Management</p>
            </div>
            <div class="receipt-body">
                <div class="receipt-title">
                    <h2>' . $receipt_title . '</h2>
                    <p>Receipt No: ' . htmlspecialchars($data['rent_receipt_number'] ?? $data['receipt_number']) . '</p>
                </div>
                
                <div class="info-grid">
                    <div class="info-box">
                        <h4>RECEIPT DETAILS</h4>
                        <p><strong>Payment Date:</strong> ' . date('F j, Y', strtotime($data['payment_date'])) . '</p>
                        <p><strong>Payment Method:</strong> ' . ucfirst(str_replace('_', ' ', $data['payment_method'])) . '</p>
                        <p><strong>Reference:</strong> ' . htmlspecialchars($data['payment_reference'] ?? 'N/A') . '</p>
                        <p><strong>Status:</strong> <span class="status-badge">PAID</span></p>
                    </div>
                    <div class="info-box">
                        <h4>TENANT INFORMATION</h4>
                        <p><strong>Name:</strong> ' . htmlspecialchars($data['firstname'] . ' ' . $data['lastname']) . '</p>
                        <p><strong>Email:</strong> ' . htmlspecialchars($data['email']) . '</p>
                        <p><strong>Phone:</strong> ' . htmlspecialchars($data['phone']) . '</p>
                        <p><strong>Tenant Code:</strong> ' . htmlspecialchars($data['tenant_code']) . '</p>
                    </div>
                    <div class="info-box">
                        <h4>PROPERTY DETAILS</h4>
                        <p><strong>Property:</strong> ' . htmlspecialchars($data['property_name']) . '</p>
                        <p><strong>Address:</strong> ' . htmlspecialchars($data['property_address']) . '</p>
                        <p><strong>Apartment:</strong> ' . htmlspecialchars($data['apartment_number']) . '</p>
                    </div>
                    <div class="info-box">
                        <h4>LEASE INFORMATION</h4>
                        <p><strong>Lease Start:</strong> ' . date('F j, Y', strtotime($data['lease_start_date'])) . '</p>
                        <p><strong>Lease End:</strong> ' . date('F j, Y', strtotime($data['lease_end_date'])) . '</p>
                    </div>
                </div>
                
                <table class="payment-table">
                    <thead>
                        <tr><th>Description</th><th class="amount">Amount (₦)</th></tr>
                    </thead>
                    <tbody>';
    
    if ($receipt_type === 'security_deposit') {
        $html .= '<tr><td>Security Deposit for Apartment ' . htmlspecialchars($data['apartment_number']) . '</td>
                  <td class="amount">' . number_format($data['amount_paid'], 2) . '</td></tr>';
    } elseif ($receipt_type === 'initial_rent') {
        $html .= '<tr><td>Initial Rent Payment (Lease Start: ' . date('F j, Y', strtotime($data['lease_start_date'])) . ')</td>
                  <td class="amount">' . number_format($data['amount_paid'], 2) . '</td></tr>';
    } else {
        $html .= '<tr><td>Rent Payment - Period #' . $data['period_number'] . '<br>
                  <small>' . $period_display . '</small></td>
                  <td class="amount">' . number_format($data['amount_paid'], 2) . '</td></tr>';
    }
    
    $html .= '
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td style="font-weight: bold;">TOTAL</td>
                            <td class="amount" style="font-weight: bold;">₦' . number_format($data['amount_paid'], 2) . '</td>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="footer">
                    <p>This is a computer-generated receipt and requires no signature.</p>
                    <p>Generated on: ' . date('F j, Y g:i A') . '</p>
                    <p>&copy; ' . date('Y') . ' RentFlow Pro - All Rights Reserved</p>
                </div>
            </div>
        </div>
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer;">
                🖨️ Print Receipt
            </button>
            <button onclick="window.close()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 8px; cursor: pointer; margin-left: 10px;">
                Close
            </button>
        </div>
    </body>
    </html>';
    
    echo $html;
}

/**
 * Generate PDF receipt
 */
function generatePDFReceipt($data) {
    // Create PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('RentFlow Pro');
    $pdf->SetAuthor('RentFlow Pro');
    $pdf->SetTitle('Rent Payment Receipt');
    $pdf->SetSubject('Payment Receipt');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 10);

    // Get receipt type
    $receipt_type = $data['receipt_type'] ?? ($data['period_number'] ? 'period_payment' : 'initial_rent');

    // Determine receipt title
    if ($receipt_type === 'security_deposit') {
        $receipt_title = 'SECURITY DEPOSIT RECEIPT';
    } elseif ($receipt_type === 'initial_rent') {
        $receipt_title = 'INITIAL RENT PAYMENT RECEIPT';
    } else {
        $receipt_title = 'RENT PAYMENT RECEIPT';
    }

    // Company Header
    $html = '
    <table width="100%" cellpadding="5">
        <tr>
            <td width="60%" style="border-bottom: 1px solid #333;">
                <h1 style="color: #667eea; margin: 0;">RentFlow Pro</h1>
                <p style="margin: 5px 0 0 0; color: #666;">Professional Property Management</p>
            </td>
            <td width="40%" style="border-bottom: 1px solid #333; text-align: right;">
                <h2 style="margin: 0;">RECEIPT</h2>
                <p style="margin: 5px 0 0 0; font-size: 12px;">No: ' . htmlspecialchars($data['rent_receipt_number'] ?? $data['receipt_number']) . '</p>
            </td>
        </tr>
    </table>
    
    <br><br>
    
    <table width="100%" cellpadding="5">
        <tr>
            <td width="50%" style="background-color: #f8f9fa;">
                <strong>RECEIPT DETAILS</strong><br>
                Receipt Number: ' . htmlspecialchars($data['rent_receipt_number'] ?? $data['receipt_number']) . '<br>
                Payment Date: ' . date('F j, Y', strtotime($data['payment_date'])) . '<br>
                Payment Method: ' . ucfirst(str_replace('_', ' ', $data['payment_method'])) . '<br>
                Reference: ' . htmlspecialchars($data['payment_reference'] ?? 'N/A') . '<br>
                Status: <span style="color: #10b981;">PAID</span>
             </td>
            <td width="50%" style="background-color: #f8f9fa;">
                <strong>TENANT INFORMATION</strong><br>
                Name: ' . htmlspecialchars($data['firstname'] . ' ' . $data['lastname']) . '<br>
                Email: ' . htmlspecialchars($data['email']) . '<br>
                Phone: ' . htmlspecialchars($data['phone']) . '<br>
                Tenant Code: ' . htmlspecialchars($data['tenant_code']) . '
             </td>
        </tr>
    </table>
    
    <br><br>
    
    <table width="100%" cellpadding="5">
        <tr>
            <td width="50%" style="background-color: #f8f9fa;">
                <strong>PROPERTY DETAILS</strong><br>
                Property: ' . htmlspecialchars($data['property_name']) . '<br>
                Address: ' . htmlspecialchars($data['property_address']) . '<br>
                Apartment: ' . htmlspecialchars($data['apartment_number']) . '<br>
                Apartment Code: ' . htmlspecialchars($data['apartment_code']) . '
             </td>
            <td width="50%" style="background-color: #f8f9fa;">
                <strong>AGENT INFORMATION</strong><br>
                Agent: ' . htmlspecialchars($data['agent_name'] ?? 'N/A') . '<br>
                Phone: ' . htmlspecialchars($data['agent_phone'] ?? 'N/A') . '<br>
                Email: ' . htmlspecialchars($data['agent_email'] ?? 'N/A') . '
             </td>
        </tr>
    </table>
    
    <br><br>
    
    <h3>' . $receipt_title . '</h3>
    
    <table width="100%" cellpadding="8" style="border-collapse: collapse;">
        <tr style="background-color: #667eea; color: white;">
            <th style="padding: 8px; text-align: left;">Description</th>
            <th style="padding: 8px; text-align: right;">Amount (₦)</th>
        </tr>';

    // Payment description based on type
    if ($receipt_type === 'security_deposit') {
        $description = 'Security Deposit for Apartment ' . $data['apartment_number'];
        $html .= '
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 8px;">' . $description . '</td>
            <td style="padding: 8px; text-align: right;">' . number_format($data['amount_paid'], 2) . '</td>
         </tr>';
    } elseif ($receipt_type === 'initial_rent') {
        $description = 'Initial Rent Payment (Lease Start: ' . date('F j, Y', strtotime($data['lease_start_date'])) . ')';
        $html .= '
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 8px;">' . $description . '</td>
            <td style="padding: 8px; text-align: right;">' . number_format($data['amount_paid'], 2) . '</td>
         </tr>';
    } else {
        $period_display = date('F j, Y', strtotime($data['start_date'])) . ' - ' . date('F j, Y', strtotime($data['end_date']));
        $description = 'Rent Payment - Period #' . $data['period_number'] . ' (' . $period_display . ')';
        $html .= '
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 8px;">' . $description . '</td>
            <td style="padding: 8px; text-align: right;">' . number_format($data['amount_paid'], 2) . '</td>
         </tr>';
    }

    $html .= '
        <tr style="background-color: #f8f9fa; font-weight: bold;">
            <td style="padding: 8px; text-align: right;">TOTAL</td>
            <td style="padding: 8px; text-align: right; border-top: 2px solid #333;">₦' . number_format($data['amount_paid'], 2) . '</td>
         </tr>
     </table>
    
    <br><br>
    
    <table width="100%" cellpadding="5">
        <tr>
            <td style="border-top: 1px solid #ddd; padding-top: 15px;">
                <p style="font-size: 11px; color: #666;">
                    <strong>Payment Status:</strong> This receipt confirms that the above payment has been successfully processed.<br>
                    <strong>Lease Period:</strong> ' . date('F j, Y', strtotime($data['lease_start_date'])) . ' - ' . date('F j, Y', strtotime($data['lease_end_date'])) . '<br>
                    <strong>Payment Frequency:</strong> ' . ($data['period_number'] ? 'Per Period' : 'One-time') . '<br>
                    <br>
                    Thank you for your payment. This is a computer-generated receipt and requires no signature.
                </p>
             </td>
         </tr>
     </table>
    
    <br>
    
    <table width="100%" cellpadding="5">
        <tr>
            <td style="text-align: center;">
                <p style="font-size: 10px; color: #999;">
                    RentFlow Pro - Property Management System<br>
                    Generated on: ' . date('F j, Y g:i A') . '
                </p>
             </td>
         </tr>
     </table>';

    // Output the HTML content
    $pdf->writeHTML($html, true, false, true, false, '');

    // Close and output PDF
    $pdf->Output('Receipt_' . ($data['rent_receipt_number'] ?? $data['receipt_number']) . '.pdf', 'I');
}
?>