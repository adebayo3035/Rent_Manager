<?php
/**
 * invoice_helper.php - Invoice generation for tenants (admin module)
 * 
 * Generates PDF invoices, saves them to admin's tenant_documents/invoices/,
 * and emails them to tenants with bank details and payment link.
 * 
 * Uses TCPDF for PDF generation and sendOTPGmail.php for email.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/notification_helper.php';

// ==================== CONFIGURATION ====================
define('INVOICE_DIR', __DIR__ . '/../tenant_documents/invoices/');
define('INVOICE_URL_PATH', '/admin/backend/tenant_documents/invoices/');

// Company details for invoice (customize these)
define('COMPANY_NAME', 'RentFlow Pro');
define('COMPANY_TAGLINE', 'Professional Property Management');
define('COMPANY_SUPPORT_EMAIL', 'support@rentflowpro.com');
define('COMPANY_SUPPORT_PHONE', '+234 800 000 0000');

// Bank details for payment instructions
define('BANK_NAME', 'Your Bank Name');
define('BANK_ACCOUNT_NAME', 'RentFlow Pro Ltd');
define('BANK_ACCOUNT_NUMBER', '0123456789');
define('BANK_SORT_CODE', '000000');

// Ensure invoice directory exists
if (!is_dir(INVOICE_DIR)) {
    @mkdir(INVOICE_DIR, 0755, true);
}

// ==================== INVOICE NUMBER GENERATOR ====================
/**
 * Generate a unique invoice number
 * Format: INV-YYYYMMDD-XXXXXX
 */
function generateInvoiceNumber($prefix = 'INV') {
    $date = date('Ymd');
    $random = strtoupper(bin2hex(random_bytes(3)));
    return $prefix . '-' . $date . '-' . $random;
}

// ==================== CORE INVOICE FUNCTION ====================
/**
 * Generate an onboarding invoice for a new tenant
 * 
 * @param mysqli $conn Database connection
 * @param array $data Invoice data
 * @return array|false Result array or false on failure
 */
function generateOnboardingInvoice($conn, $data) {
    $requestId = uniqid('invoice_', true);
    logActivity("[INVOICE] [{$requestId}] ========== INVOICE GENERATION START ==========");

    try {
        // ==================== STEP 1: VALIDATE INPUT ====================
        $required = [
            'tenant_code', 'tenant_name', 'tenant_email',
            'property_code', 'apartment_code', 'rent_payment_id',
            'annual_rent', 'payment_amount_per_period',
            'balance', 'payment_frequency',
            'period_start', 'period_end', 'due_date',
            'created_by'
        ];

        foreach ($required as $key) {
            if (!isset($data[$key])) {
                logActivity("[INVOICE] [{$requestId}] ERROR: Missing required field: {$key}");
                return false;
            }
        }

        // ==================== STEP 2: GENERATE INVOICE NUMBER ====================
        $invoice_number = generateInvoiceNumber('INV');
        logActivity("[INVOICE] [{$requestId}] Generated invoice number: {$invoice_number}");

        // ==================== STEP 3: BUILD PAYMENT LINK ====================
        // Adjust this URL to match your actual payment page
        $payment_link = buildPaymentLink($data, $invoice_number);
        logActivity("[INVOICE] [{$requestId}] Payment link: {$payment_link}");

        // ==================== STEP 4: RENDER HTML ====================
        $html = renderOnboardingInvoiceHTML($data, $invoice_number, $payment_link);
        logActivity("[INVOICE] [{$requestId}] HTML rendered (" . strlen($html) . " bytes)");

        // ==================== STEP 5: GENERATE PDF ====================
        $pdf_content = generateInvoicePDF($html);
        if (!$pdf_content) {
            throw new Exception("PDF generation returned empty content");
        }
        logActivity("[INVOICE] [{$requestId}] PDF generated (" . strlen($pdf_content) . " bytes)");

        // ==================== STEP 6: SAVE PDF TO DISK ====================
        $file_hash = hash('sha256', $pdf_content);
        $stored_file_name = 'invoice_' . $file_hash . '_' . time() . '.pdf';
        $file_path = INVOICE_DIR . $stored_file_name;

        if (file_put_contents($file_path, $pdf_content) === false) {
            throw new Exception("Failed to write PDF to disk: {$file_path}");
        }
        @chmod($file_path, 0644);
        logActivity("[INVOICE] [{$requestId}] PDF saved to: {$file_path}");

        // ==================== STEP 7: REGISTER IN tenant_documents ====================
        $document_id = saveInvoiceDocument($conn, [
            'tenant_code'        => $data['tenant_code'],
            'invoice_number'     => $invoice_number,
            'stored_file_name'   => $stored_file_name,
            'file_hash'          => $file_hash,
            'storage_location'   => $data['storage_location'],
            'file_size'          => strlen($pdf_content),
            'uploaded_by'        => $data['created_by'],
            'uploaded_by_type'   => $data['uploaded_by_type']
        ]);

        if (!$document_id) {
            throw new Exception("Failed to register invoice in tenant_documents");
        }
        logActivity("[INVOICE] [{$requestId}] Document registered - ID: {$document_id}");

        // ==================== STEP 8: EMAIL TO TENANT ====================
        $email_sent = sendInvoiceEmail($data, $invoice_number, $file_path, $payment_link);
        logActivity("[INVOICE] [{$requestId}] Email sent: " . ($email_sent ? 'YES' : 'NO'));

        // ==================== STEP 9: NOTIFY TENANT (in-app) ====================
        createInvoiceNotification($conn, $data, $invoice_number, $payment_link);
        logActivity("[INVOICE] [{$requestId}] In-app notification created");

        logActivity("[INVOICE] [{$requestId}] ========== INVOICE GENERATION COMPLETE ==========");

        return [
            'success'          => true,
            'invoice_number'   => $invoice_number,
            'document_id'      => $document_id,
            'file_path'        => $file_path,
            'stored_file_name' => $stored_file_name,
            'file_size'        => strlen($pdf_content),
            'email_sent'       => $email_sent,
            'payment_link'     => $payment_link
        ];

    } catch (Exception $e) {
        logActivity("[INVOICE] [{$requestId}] ERROR: " . $e->getMessage());
        logActivity("[INVOICE] [{$requestId}] Trace: " . $e->getTraceAsString());
        return false;
    }
}

// ==================== PAYMENT LINK BUILDER ====================
/**
 * Build a payment link for the invoice
 * Adjust the URL based on your actual payment flow
 */
function buildPaymentLink($data, $invoice_number) {
    // Base URL — adjust this to your domain
    $base_url = 'http://localhost/Rent_Manager/tenant/pages/payments.php';
    
    // Build query string with invoice reference
    $params = http_build_query([
        'invoice'      => $invoice_number,
        'tenant_code'  => $data['tenant_code'],
        'amount'       => $data['payment_amount_per_period'],
        'reference'    => $data['reference_number'] ?? $invoice_number
    ]);
    
    return $base_url . '?' . $params;
}

// ==================== HTML TEMPLATE ====================
/**
 * Render the onboarding invoice as HTML
 */
// ==================== HTML TEMPLATE ====================
/**
 * Render the onboarding invoice as HTML
 * Uses DejaVu Sans-compatible fonts and clean layout
 */
function renderOnboardingInvoiceHTML($data, $invoice_number, $payment_link) {
    // Compute derived values
    $annual_rent = (float) $data['annual_rent'];
    $first_payment = (float) $data['payment_amount_per_period'];
    $balance = (float) $data['balance'];
    $security_deposit = (float) ($data['security_deposit'] ?? 0);
    $total_due = $first_payment + $security_deposit;

    $issue_date = date('F j, Y');
    $due_date_fmt = date('F j, Y', strtotime($data['due_date']));
    $period_start_fmt = date('F j, Y', strtotime($data['period_start']));
    $period_end_fmt = date('F j, Y', strtotime($data['period_end']));

    // FIX: Escape $freq_label since it derives from user input (payment_frequency)
    $freq_label = htmlspecialchars(ucfirst(str_replace('-', ' ', $data['payment_frequency'])));

    $reference = htmlspecialchars($data['reference_number'] ?? $invoice_number);
    $apartment_label = htmlspecialchars($data['apartment_number'] ?? $data['apartment_code']);
    $property_label = htmlspecialchars($data['property_name'] ?? 'N/A');

    $company_name = htmlspecialchars(COMPANY_NAME);
    $company_tagline = htmlspecialchars(COMPANY_TAGLINE);
    $company_support_email = htmlspecialchars(COMPANY_SUPPORT_EMAIL);
    $company_support_phone = htmlspecialchars(COMPANY_SUPPORT_PHONE);
    $bank_name = htmlspecialchars(BANK_NAME);
    $bank_account_name = htmlspecialchars(BANK_ACCOUNT_NAME);
    $bank_account_number = htmlspecialchars(BANK_ACCOUNT_NUMBER);
    $bank_sort_code = htmlspecialchars(BANK_SORT_CODE);

    // Use "NGN" text fallback for maximum font compatibility in PDFs
    $currency = 'NGN';

    // TCPDF CSS SUPPORT NOTES:
    //   - text-transform, !important, box-sizing, letter-spacing, border-radius, box-shadow: NOT supported
    //   - border-bottom on <table>: NOT supported → must use on <td>
    //   - border-left on <table>: inconsistent → use nested table with colored <td>
    //   - HTML attributes (width, align, valign) more reliable than CSS for tables

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "dejavusans", Arial, sans-serif; font-size: 10px; color: #1a1f36; line-height: 1.5; }

            /* Header block */
            .company-name { font-size: 22px; font-weight: bold; color: #667eea; }
            .company-tagline { font-size: 10px; color: #64748b; margin-top: 3px; font-style: italic; }
            .invoice-label { font-size: 26px; font-weight: bold; color: #1a1f36; }
            .invoice-meta-label { font-size: 9px; color: #64748b; }
            .invoice-meta-value { font-size: 11px; color: #1a1f36; font-weight: bold; }

            /* Two column boxes */
            .info-box-left { background-color: #f8fafc; padding: 10px 12px; }
            .info-box-right { background-color: #f8fafc; padding: 10px 12px; }
            .info-heading { font-size: 9px; font-weight: bold; color: #64748b; margin-bottom: 6px; }
            .info-line { font-size: 10px; margin: 3px 0; color: #1e293b; }
            .info-line strong { color: #1a1f36; font-size: 11px; }

            /* Section title */
            .section-title { font-size: 11px; font-weight: bold; color: #667eea; margin: 18px 0 10px 0; }

            /* Items table */
            .items-table { width: 100%; }
            .items-table thead th {
                background-color: #667eea; color: white; padding: 8px 10px;
                font-size: 9px; font-weight: bold;
            }
            .items-table tbody td {
                padding: 9px 10px; border-bottom: 1px solid #e2e8f0; font-size: 10px;
            }
            .items-table .item-title { font-weight: bold; color: #1a1f36; font-size: 11px; }
            .items-table .item-sub { color: #64748b; font-size: 9px; }
            .items-table .amount { font-size: 11px; font-weight: bold; color: #1a1f36; }

            /* Total row */
            .total-row td { padding: 12px 10px; background-color: #f0f4ff; }
            .total-label { font-size: 12px; font-weight: bold; color: #1a1f36; }
            .total-amount { font-size: 15px; font-weight: bold; color: #667eea; }

            /* Balance summary */
            .balance-table { width: 100%; background-color: #f1f5f9; }
            .balance-table td { padding: 5px 12px; font-size: 10px; color: #475569; }
            .balance-table tr.emphasis td { font-size: 11px; color: #1a1f36; font-weight: bold; }

            /* CTA */
            .cta-title { font-size: 12px; font-weight: bold; color: #065f46; }
            .cta-sub { font-size: 10px; color: #047857; margin-bottom: 8px; }
            .cta-button { background-color: #10b981; color: white; padding: 8px 20px; text-decoration: none; font-weight: bold; font-size: 12px; }

            /* Bank details */
            .bank-heading { font-size: 11px; font-weight: bold; color: #92400e; margin-bottom: 6px; }
            .bank-table td { padding: 3px 0; font-size: 10px; color: #78350f; }
            .bank-note { font-size: 9px; color: #92400e; font-style: italic; margin-top: 6px; padding-top: 6px; }

            /* Footer */
            .footer { text-align: center; font-size: 8px; color: #94a3b8; line-height: 1.6; }
        </style>
    </head>
    <body>

        <!-- HEADER -->
        <!-- FIX: border-bottom moved from .header-table (table) to inner <td> because
             TCPDF ignores border-bottom on <table> elements. -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 18px;">
            <tr>
                <td style="border-bottom: 3px solid #667eea; padding-bottom: 12px;">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td width="55%" valign="top" align="left">
                                <div class="company-name">' . $company_name . '</div>
                                <div class="company-tagline">' . $company_tagline . '</div>
                            </td>
                            <td width="45%" valign="top" align="right">
                                <div class="invoice-label">INVOICE</div>
                                <div style="margin-top: 8px;">
                                    <div class="invoice-meta-label">Invoice Number</div>
                                    <div class="invoice-meta-value">' . htmlspecialchars($invoice_number) . '</div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- INVOICE META -->
        <table width="100%" cellpadding="6" cellspacing="0" style="margin-bottom: 16px;">
            <tr>
                <td width="31%" align="left" style="background-color: #f8fafc;">
                    <div class="invoice-meta-label">Issue Date</div>
                    <div class="invoice-meta-value">' . $issue_date . '</div>
                </td>
                <td width="3%">&nbsp;</td>
                <td width="31%" align="left" style="background-color: #fff7ed;">
                    <div class="invoice-meta-label" style="color:#c2410c;">Due Date</div>
                    <div class="invoice-meta-value" style="color:#c2410c;">' . $due_date_fmt . '</div>
                </td>
                <td width="3%">&nbsp;</td>
                <td width="32%" align="left" style="background-color: #f8fafc;">
                    <div class="invoice-meta-label">Payment Ref</div>
                    <div class="invoice-meta-value">' . $reference . '</div>
                </td>
            </tr>
        </table>

        <!-- BILLED TO / PROPERTY -->
        <!-- FIX: Left border now provided by a thin colored <td> instead of border-left on the box.
             Guarantees the border spans the full row height in TCPDF. -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
            <tr>
                <td width="48%" valign="top">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td width="4" style="background-color: #667eea;">&nbsp;</td>
                            <td class="info-box-left" valign="top">
                                <div class="info-heading">BILLED TO</div>
                                <div class="info-line"><strong>' . htmlspecialchars($data['tenant_name']) . '</strong></div>
                                <div class="info-line">Tenant Code: ' . htmlspecialchars($data['tenant_code']) . '</div>
                                <div class="info-line">' . htmlspecialchars($data['tenant_email']) . '</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td width="4%">&nbsp;</td>
                <td width="48%" valign="top">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td width="4" style="background-color: #764ba2;">&nbsp;</td>
                            <td class="info-box-right" valign="top">
                                <div class="info-heading">PROPERTY</div>
                                <div class="info-line"><strong>' . $property_label . '</strong></div>
                                <div class="info-line">Apartment: ' . $apartment_label . '</div>
                                <div class="info-line">Property Code: ' . htmlspecialchars($data['property_code']) . '</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- RENT SUMMARY -->
        <div class="section-title">RENT SUMMARY</div>
        <!-- FIX: Added HTML border="0" attr — TCPDF respects HTML table attributes over CSS,
             which prevents unexpected default borders from interfering with the CSS borders. -->
        <table class="items-table" border="0" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th width="60%" align="left">Description</th>
                    <th width="40%" align="right">Amount (' . $currency . ')</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td width="60%" align="left">
                        <div class="item-title">Agreed Annual Rent</div>
                        <div class="item-sub">Lease Period: ' . $period_start_fmt . ' to ' . $period_end_fmt . '</div>
                        <div class="item-sub">Payment Frequency: ' . $freq_label . '</div>
                    </td>
                    <td width="40%" align="right">
                        <div class="amount">' . number_format($annual_rent, 2) . '</div>
                    </td>
                </tr>
                <tr>
                    <td width="60%" align="left">
                        <div class="item-title">Payment Per Period</div>
                        <div class="item-sub">Scheduled ' . $freq_label . ' installment</div>
                    </td>
                    <td width="40%" align="right">
                        <div class="amount">' . number_format($first_payment, 2) . '</div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- AMOUNT DUE -->
        <div class="section-title">AMOUNT DUE NOW</div>
        <table class="items-table" border="0" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th width="60%" align="left">Item</th>
                    <th width="40%" align="right">Amount (' . $currency . ')</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td width="60%" align="left">
                        <div class="item-title">First Rent Payment</div>
                        <div class="item-sub">Coverage: ' . $period_start_fmt . ' - ' . $period_end_fmt . '</div>
                    </td>
                    <td width="40%" align="right">
                        <div class="amount">' . number_format($first_payment, 2) . '</div>
                    </td>
                </tr>';

    if ($security_deposit > 0) {
        $html .= '
                <tr>
                    <td width="60%" align="left">
                        <div class="item-title">Security Deposit</div>
                        <div class="item-sub">Refundable deposit for Apartment ' . $apartment_label . '</div>
                    </td>
                    <td width="40%" align="right">
                        <div class="amount">' . number_format($security_deposit, 2) . '</div>
                    </td>
                </tr>';
    }

    // FIX: total-row border-top now added inline to each <td> so TCPDF renders it correctly.
    $html .= '
                <tr class="total-row">
                    <td width="60%" align="left" class="total-label" style="border-top: 2px solid #667eea;">TOTAL DUE NOW</td>
                    <td width="40%" align="right" class="total-amount" style="border-top: 2px solid #667eea;">' . $currency . ' ' . number_format($total_due, 2) . '</td>
                </tr>
            </tbody>
        </table>';

    if ($balance > 0) {
        $html .= '
        <table class="balance-table" border="0" cellpadding="4" cellspacing="0" style="margin-top: 12px;">
            <tr>
                <td width="60%" align="left">Total Annual Rent</td>
                <td width="40%" align="right">' . $currency . ' ' . number_format($annual_rent, 2) . '</td>
            </tr>
            <tr>
                <td width="60%" align="left">Less: First Payment (this invoice)</td>
                <td width="40%" align="right">- ' . $currency . ' ' . number_format($first_payment, 2) . '</td>
            </tr>
            <tr class="emphasis">
                <td width="60%" align="left" style="border-top: 1px dashed #cbd5e1;">Remaining Balance After Payment</td>
                <td width="40%" align="right" style="border-top: 1px dashed #cbd5e1;">' . $currency . ' ' . number_format($balance, 2) . '</td>
            </tr>
        </table>';
    }

    // CTA
    // FIX: CTA left/right border simulated with side <td> columns (TCPDF table border reliability).
    $html .= '
        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 18px 0;">
            <tr>
                <td style="background-color: #ecfdf5; border-top: 1px solid #a7f3d0; border-bottom: 1px solid #a7f3d0; border-left: 1px solid #a7f3d0; border-right: 1px solid #a7f3d0;" align="center">
                    <div style="padding: 14px 10px;">
                        <div class="cta-title">PAY ONLINE NOW</div>
                        <div class="cta-sub">Make a secure payment using the link below</div>
                        <a href="' . htmlspecialchars($payment_link) . '" class="cta-button">Pay ' . $currency . ' ' . number_format($total_due, 2) . ' Online</a>
                    </div>
                </td>
            </tr>
        </table>

        <!-- BANK DETAILS -->
        <!-- FIX: Left border now a dedicated colored <td>, guaranteed to render at full height. -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 15px 0;">
            <tr>
                <td width="4" style="background-color: #f59e0b;">&nbsp;</td>
                <td style="background-color: #fffbeb; padding: 12px;">
                    <div class="bank-heading">BANK TRANSFER DETAILS</div>
                    <table class="bank-table" border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                            <td width="40%"><strong>Bank Name:</strong></td>
                            <td width="60%">' . $bank_name . '&nbsp;</td>
                        </tr>
                        <tr>
                            <td width="40%"><strong>Account Name:</strong></td>
                            <td width="60%">' . $bank_account_name . '&nbsp;</td>
                        </tr>
                        <tr>
                            <td width="40%"><strong>Account Number:</strong></td>
                            <td width="60%">' . $bank_account_number . '&nbsp;</td>
                        </tr>
                        <tr>
                            <td width="40%"><strong>Sort Code:</strong></td>
                            <td width="60%">' . $bank_sort_code . '&nbsp;</td>
                        </tr>
                        <tr>
                            <td width="40%"><strong>Payment Reference:</strong></td>
                            <td width="60%">' . $reference . '&nbsp;</td>
                        </tr>
                    </table>
                    <div class="bank-note" style="border-top: 1px dashed #fbbf24;">
                        Please quote the reference above when making payment. Payment must be received on or before ' . $due_date_fmt . '.
                    </div>
                </td>
            </tr>
        </table>

        <!-- FOOTER -->
        <!-- FIX: Converted div to table for consistency; uses inline styles for colored <strong>. -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 18px;">
            <tr>
                <td style="border-top: 1px solid #e2e8f0; padding-top: 10px;" align="center">
                    <div class="footer">
                        This is a computer-generated invoice and requires no signature.<br>
                        For questions, contact <strong style="color: #667eea;">' . $company_support_email . '</strong> or call ' . $company_support_phone . '<br><br>
                        <strong style="color: #667eea;">' . $company_name . '</strong> &copy; ' . date('Y') . ' - All Rights Reserved
                    </div>
                </td>
            </tr>
        </table>

    </body>
    </html>';

    return $html;
}

// ==================== PDF GENERATION ====================
/**
 * Convert HTML to PDF using TCPDF with Unicode-safe fonts
 */
function generateInvoicePDF($html) {
    // Locate TCPDF — from admin/backend/utilities/ to vendor/
    $tcpdfPath = __DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php';

    if (!file_exists($tcpdfPath)) {
        throw new Exception("TCPDF not found at: {$tcpdfPath}");
    }

    require_once $tcpdfPath;

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    $pdf->SetCreator(COMPANY_NAME);
    $pdf->SetAuthor(COMPANY_NAME);
    $pdf->SetTitle('Onboarding Invoice');
    $pdf->SetSubject('Rent Invoice');

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(TRUE, 12);
    $pdf->AddPage();

    // Use DejaVu Sans — full Unicode support (Naira sign, etc.)
    $pdf->SetFont('dejavusans', '', 10);

    $pdf->writeHTML($html, true, false, true, false, '');

    // Return PDF content as string
    return $pdf->Output('', 'S');
}
// ==================== SAVE DOCUMENT RECORD ====================
/**
 * Register the invoice in the tenant_documents table
 */
function saveInvoiceDocument($conn, $data) {
    $document_name = 'Onboarding Invoice - ' . $data['invoice_number'];
    $document_type = 'INVOICE';
    $original_file_name = $data['invoice_number'] . '.pdf';
    $file_type = 'application/pdf';
    
    $query = "
        INSERT INTO tenant_documents (
            tenant_code,
            document_name,
            document_type,
            file_name,
            original_file_name,
            file_size,
            file_type,
            file_hash,
            storage_location,
            uploaded_by,
            uploaded_at,
            uploaded_by_type,
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logActivity("[INVOICE] Failed to prepare document insert: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param(
        "sssssisssss",
        $data['tenant_code'],
        $document_name,
        $document_type,
        $data['stored_file_name'],
        $original_file_name,
        $data['file_size'],
        $file_type,
        $data['file_hash'],
        $data['storage_location'],
        $data['uploaded_by'],
        $data['uploaded_by_type']
    );
    
    if (!$stmt->execute()) {
        logActivity("[INVOICE] Failed to insert document: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $document_id = $stmt->insert_id;
    $stmt->close();
    return $document_id;
}

// ==================== EMAIL SENDING ====================
/**
 * Email the invoice to the tenant using admin's sendOTPGmail helper
 */
function sendInvoiceEmail($data, $invoice_number, $pdf_path, $payment_link) {
    $emailHelperPath = __DIR__ . '/sendOTPGmail.php';
    
    if (!file_exists($emailHelperPath)) {
        logActivity("[INVOICE] Email helper not found at: {$emailHelperPath}");
        return false;
    }
    
    require_once $emailHelperPath;
    
    if (!function_exists('sendEmailWithGmailSMTP')) {
        logActivity("[INVOICE] sendEmailWithGmailSMTP() function not found");
        return false;
    }
    
    // Build email body
    $subject = 'Welcome to ' . COMPANY_NAME . ' - Your Onboarding Invoice (' . $invoice_number . ')';
    $body = buildInvoiceEmailBody($data, $invoice_number, $payment_link);
    
    // Send with attachment
    $attachments = [$pdf_path];
    
    $sent = sendEmailWithGmailSMTP(
        $data['tenant_email'],
        $body,
        $subject,
        $attachments
    );
    
    if ($sent) {
        logActivity("[INVOICE] Email sent to {$data['tenant_email']} with invoice {$invoice_number}");
    } else {
        logActivity("[INVOICE] Failed to send email to {$data['tenant_email']}");
    }
    
    return $sent;
}

/**
 * Build the welcome email body
 */
function buildInvoiceEmailBody($data, $invoice_number, $payment_link) {
    $first_name = explode(' ', trim($data['tenant_name']))[0];
    $due_date_fmt = date('F j, Y', strtotime($data['due_date']));
    $first_payment = (float) $data['payment_amount_per_period'];
    $security_deposit = (float) ($data['security_deposit'] ?? 0);
    $total_due = $first_payment + $security_deposit;
    $reference = htmlspecialchars($data['reference_number'] ?? $invoice_number);
    $apartment = htmlspecialchars($data['apartment_number'] ?? $data['apartment_code']);
    $property = htmlspecialchars($data['property_name'] ?? 'your new home');
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; background: #f5f7fb; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; color: white; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; }
            .body { padding: 30px; color: #1a1f36; line-height: 1.6; }
            .body h2 { font-size: 18px; margin-bottom: 15px; }
            .box { background: #f0f4ff; border-left: 4px solid #667eea; padding: 15px; border-radius: 6px; margin: 20px 0; }
            .box p { margin: 5px 0; font-size: 14px; }
            .box strong { color: #667eea; }
            .amount { font-size: 22px; color: #10b981; font-weight: bold; }
            .button { display: inline-block; background: #10b981; color: white; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: 600; margin: 15px 0; font-size: 15px; }
            .bank-box { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 6px; margin: 20px 0; font-size: 13px; color: #78350f; }
            .bank-box strong { color: #78350f; }
            .footer { padding: 20px 30px; background: #f8fafc; text-align: center; font-size: 12px; color: #94a3b8; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Welcome to ' . COMPANY_NAME . '!</h1>
                <p style="margin: 5px 0 0 0; opacity: 0.9;">Your tenancy has been activated</p>
            </div>
            <div class="body">
                <h2>Hello ' . htmlspecialchars($first_name) . ',</h2>
                <p>Welcome aboard! Your tenancy at <strong>' . $property . '</strong> (Apartment ' . $apartment . ') has been successfully activated.</p>
                
                <p>We\'ve prepared your <strong>onboarding invoice</strong> for your first payment. Please find it attached to this email.</p>
                
                <div class="box">
                    <p><strong>Invoice Number:</strong> ' . htmlspecialchars($invoice_number) . '</p>
                    <p><strong>Total Due Now:</strong> <span class="amount">₦' . number_format($total_due, 2) . '</span></p>
                    <p><strong>Payment Due Date:</strong> ' . $due_date_fmt . '</p>
                </div>
                
                <p><strong>What\'s included:</strong></p>
                <ul>
                    <li>First Rent Payment: ₦' . number_format($first_payment, 2) . '</li>';
    
    if ($security_deposit > 0) {
        $body .= '<li>Security Deposit: ₦' . number_format($security_deposit, 2) . '</li>';
    }
    
    $body .= '
                </ul>
                
                <div style="text-align: center; margin: 25px 0;">
                    <a href="' . htmlspecialchars($payment_link) . '" class="button">Pay ₦' . number_format($total_due, 2) . ' Online</a>
                </div>
                
                <p style="text-align: center; color: #64748b; font-size: 13px;">Or pay via bank transfer:</p>
                
                <div class="bank-box">
                    <p><strong>Bank:</strong> ' . BANK_NAME . '</p>
                    <p><strong>Account Name:</strong> ' . BANK_ACCOUNT_NAME . '</p>
                    <p><strong>Account Number:</strong> ' . BANK_ACCOUNT_NUMBER . '</p>
                    <p><strong>Reference:</strong> ' . $reference . '</p>
                </div>
                
                <p>If you have any questions, feel free to reach out to your property manager.</p>
                
                <p style="margin-top: 30px;">Best regards,<br><strong>' . COMPANY_NAME . ' Team</strong></p>
            </div>
            <div class="footer">
                This is an automated message from ' . COMPANY_NAME . '.<br>
                &copy; ' . date('Y') . ' ' . COMPANY_NAME . ' - All Rights Reserved
            </div>
        </div>
    </body>
    </html>';
}

// ==================== IN-APP NOTIFICATION ====================
/**
 * Create an in-app notification for the invoice
 * Uses the payment notification type with a custom message
 */
function createInvoiceNotification($conn, $data, $invoice_number, $payment_link) {
    try {
        $total_due = (float)$data['payment_amount_per_period'] + (float)($data['security_deposit'] ?? 0);
        $due_date_fmt = date('F j, Y', strtotime($data['due_date']));
        
        $title = "Invoice Generated: {$invoice_number}";
        $message = "Your onboarding invoice of ₦" . number_format($total_due, 2) . 
                   " has been generated. Payment is due by {$due_date_fmt}. " .
                   "Please check your email or download the invoice from your documents.";
        
        $details = [
            'invoice_number' => $invoice_number,
            'amount' => $total_due,
            'due_date' => $data['due_date'],
            'payment_link' => $payment_link,
            'document_type' => 'INVOICE'
        ];
        
        // Use the tenant notification helper
        if (function_exists('createNotification')) {
            createNotification(
                $conn,
                $data['tenant_code'],
                'payment',                          // notification type
                $title,
                $message,
                $details,
                'high',                             // priority
                '../documents.php',                 // action URL
                'View Invoice'                      // action text
            );
            logActivity("[INVOICE] Notification created via createNotification()");
            return true;
        }
        
        logActivity("[INVOICE] createNotification() not available — skipping");
        return false;
        
    } catch (Exception $e) {
        logActivity("[INVOICE] Notification error: " . $e->getMessage());
        return false;
    }
}