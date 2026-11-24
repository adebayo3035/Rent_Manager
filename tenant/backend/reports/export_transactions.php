<?php
// tenant/backend/reports/export_transactions.php
// Excel export of the tenant's transaction history with statement header.

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'                 => 'GET',
    'rate_key'               => 'tenant_transaction_export',
    'rate_limit'             => [5, 300],
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => false,
]);

try {
    $tenantCode = $_SESSION['tenant_code'] ?? null;
    if (!$tenantCode) { http_response_code(401); exit; }

    // ---- Fetch statement metadata ----
    $metaStmt = $conn->prepare("
        SELECT
            t.tenant_code, t.firstname, t.lastname, t.email, t.phone,
            t.lease_start_date, t.lease_end_date, t.payment_frequency,
            t.agreed_rent_amount, t.apartment_code, t.property_code,
            a.apartment_number,
            p.name AS property_name, p.address AS property_address
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        WHERE t.tenant_code = ? LIMIT 1
    ");
    $metaStmt->bind_param("s", $tenantCode);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc() ?: [];
    $metaStmt->close();

    $tenantName    = trim(($meta['firstname'] ?? '') . ' ' . ($meta['lastname'] ?? ''));
    $tenantEmail   = $meta['email'] ?? '';
    $tenantPhone   = $meta['phone'] ?? '';
    $propertyName  = $meta['property_name'] ?? 'N/A';
    $propertyAddr  = $meta['property_address'] ?? '';
    $propertyCode  = $meta['property_code'] ?? '';
    $apartmentNum  = $meta['apartment_number'] ?? 'N/A';
    $apartmentCode = $meta['apartment_code'] ?? '';
    $leaseStart    = $meta['lease_start_date'] ?? null;
    $leaseEnd      = $meta['lease_end_date'] ?? null;
    $paymentFreq   = $meta['payment_frequency'] ?? '';
    $agreedRent    = (float)($meta['agreed_rent_amount'] ?? 0);

    // ---- Filters ----
    $type      = isset($_GET['type'])      ? trim($_GET['type'])      : '';
    $direction = isset($_GET['direction']) ? trim($_GET['direction']) : '';
    $status    = isset($_GET['status'])    ? trim($_GET['status'])    : '';
    $dateFrom  = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo    = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
    $search    = isset($_GET['search'])    ? trim($_GET['search'])    : '';

    // ---- Build the union (same as fetch, but no pagination) ----
    $union = "
        SELECT
            rp.payment_date AS transaction_date, 'rent_payment' AS transaction_type, 'out' AS direction,
            rp.amount_paid AS amount, CONCAT('Rent payment for ', rp.payment_period) AS description,
            COALESCE(rp.receipt_number, CONCAT('RCP-', LPAD(rp.payment_id,6,'0'))) AS reference,
            rp.status AS status, NULL AS balance_after
        FROM rent_payments rp
        WHERE rp.tenant_code = ? AND rp.payment_date IS NOT NULL AND rp.amount_paid > 0
        UNION ALL
        SELECT t.payment_date, 'rent_payment', 'out', t.amount_paid,
               CONCAT('Rent paid for Period #', t.period_number),
               COALESCE(t.payment_reference, rp.receipt_number, CONCAT('TRK-', LPAD(t.tracker_id,6,'0'))),
               t.status, NULL
        FROM rent_payment_tracker t
        INNER JOIN rent_payments rp ON rp.rent_payment_id = t.rent_payment_id
        WHERE t.tenant_code = ? AND t.payment_date IS NOT NULL AND t.amount_paid > 0
          AND t.status IN ('paid','pending_verification','failed')
        UNION ALL
        SELECT tf.due_date, 'fee', 'out', tf.amount,
               CONCAT('Fee: ', COALESCE(ft.fee_name,'Unnamed Fee')),
               COALESCE(tf.receipt_number, CONCAT('FEE-', LPAD(tf.tenant_fee_id,6,'0'))),
               tf.status, NULL
        FROM tenant_fees tf LEFT JOIN fee_types ft ON ft.fee_type_id = tf.fee_type_id
        WHERE tf.tenant_code = ?
        UNION ALL
        SELECT p.payment_date, 'deposit', 'out', p.amount,
               COALESCE(p.description, 'Security deposit'),
               COALESCE(p.receipt_number, CONCAT('DEP-', LPAD(p.id,6,'0'))),
               p.payment_status, NULL
        FROM payments p
        WHERE p.tenant_code = ? AND p.payment_category = 'security_deposit'
        UNION ALL
        SELECT wt.created_at,
               CASE WHEN wt.transaction_type='topup' THEN 'topup'
                    WHEN wt.transaction_type='withdrawal' THEN 'withdrawal'
                    WHEN wt.transaction_type='settlement_credit' THEN 'settlement'
                    WHEN wt.transaction_type='refund' THEN 'refund'
                    ELSE 'adjustment' END,
               CASE WHEN wt.amount>=0 THEN 'in' ELSE 'out' END,
               ABS(wt.amount),
               COALESCE(wt.notes, wt.transaction_type),
               COALESCE(wt.request_id, CONCAT('WTX-', LPAD(wt.id,6,'0'))),
               COALESCE(wt.status, 'completed'),
               wt.running_balance
        FROM tenant_wallet_transactions wt
        WHERE wt.tenant_code = ?
    ";

    $outer = [];
    $params = [$tenantCode, $tenantCode, $tenantCode, $tenantCode, $tenantCode];
    $types  = 'sssss';

    if ($type !== '')      { $outer[] = "u.transaction_type = ?"; $params[] = $type;      $types .= 's'; }
    if ($direction !== '') { $outer[] = "u.direction = ?";        $params[] = $direction; $types .= 's'; }
    if ($status !== '')    { $outer[] = "u.status = ?";           $params[] = $status;    $types .= 's'; }
    if ($dateFrom !== '')  { $outer[] = "DATE(u.transaction_date) >= ?"; $params[] = $dateFrom; $types .= 's'; }
    if ($dateTo !== '')    { $outer[] = "DATE(u.transaction_date) <= ?"; $params[] = $dateTo;   $types .= 's'; }
    if ($search !== '') {
        $outer[] = "(u.description LIKE ? OR u.reference LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }

    $outerWhere = $outer ? 'WHERE ' . implode(' AND ', $outer) : '';
    $sql = "SELECT u.* FROM ({$union}) u {$outerWhere} ORDER BY u.transaction_date DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    // ---- Collect rows into memory so we can compute totals ----
    $rows = [];
    $totalIn = 0.0;
    $totalOut = 0.0;
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
        if ($row['direction'] === 'in') {
            $totalIn += (float)$row['amount'];
        } else {
            $totalOut += (float)$row['amount'];
        }
    }
    $stmt->close();

    // ---- Build filter description string ----
    $activeFilters = [];
    if ($type !== '') {
        $typeLabels = [
            'rent_payment' => 'Rent Payments', 'fee' => 'Fees', 'deposit' => 'Security Deposits',
            'topup' => 'Wallet Top-ups', 'withdrawal' => 'Wallet Withdrawals',
            'settlement' => 'Settlements', 'refund' => 'Refunds', 'adjustment' => 'Adjustments',
        ];
        $activeFilters[] = 'Type: ' . ($typeLabels[$type] ?? $type);
    }
    if ($direction !== '') $activeFilters[] = 'Direction: ' . ($direction === 'in' ? 'Money In' : 'Money Out');
    if ($status !== '')    $activeFilters[] = 'Status: ' . ucfirst(str_replace('_', ' ', $status));
    if ($dateFrom !== '')  $activeFilters[] = 'From: ' . $dateFrom;
    if ($dateTo !== '')    $activeFilters[] = 'To: ' . $dateTo;
    if ($search !== '')    $activeFilters[] = 'Search: "' . $search . '"';

    $filterSummary = $activeFilters ? implode(' · ', $activeFilters) : 'None — showing all transactions';

    // ---- Output Excel ----
    $filename = 'statement_' . preg_replace('/[^A-Za-z0-9_]/', '_', $tenantCode)
              . '_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";  // UTF-8 BOM

    // ---- Statement header block ----
    echo '<table border="0" cellpadding="6" style="font-family:Calibri,Arial,sans-serif;">';

    // Row 1: Big title, spanning all columns
    echo '<tr><td colspan="7" style="font-size:20pt;font-weight:bold;color:#1e3c72;padding-top:20px;">';
    echo 'Transaction Statement';
    echo '</td></tr>';

    echo '<tr><td colspan="7" style="font-size:10pt;color:#666;padding-bottom:16px;">';
    echo 'Generated ' . date('F j, Y \a\t g:i A') . ' · RentFlow Pro Tenant Portal';
    echo '</td></tr>';

    // Tenant block
    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">';
    echo 'TENANT';
    echo '</td></tr>';
    echo '<tr><td style="font-weight:bold;width:140px;">Name</td><td colspan="6">' . htmlspecialchars($tenantName) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;">Tenant Code</td><td colspan="6">' . htmlspecialchars($tenantCode) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;">Email</td><td colspan="6">' . htmlspecialchars($tenantEmail) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;padding-bottom:16px;">Phone</td><td colspan="6" style="padding-bottom:16px;">' . htmlspecialchars($tenantPhone) . '</td></tr>';

    // Property block
    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">';
    echo 'PROPERTY & APARTMENT';
    echo '</td></tr>';
    echo '<tr><td style="font-weight:bold;">Property</td><td colspan="6">' . htmlspecialchars($propertyName) . ' (' . htmlspecialchars($propertyCode) . ')</td></tr>';
    echo '<tr><td style="font-weight:bold;">Address</td><td colspan="6">' . htmlspecialchars($propertyAddr) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;">Apartment</td><td colspan="6">Apt ' . htmlspecialchars($apartmentNum) . ' (' . htmlspecialchars($apartmentCode) . ')</td></tr>';
    echo '<tr><td style="font-weight:bold;">Lease Period</td><td colspan="6">'
       . ($leaseStart ? date('M j, Y', strtotime($leaseStart)) : 'N/A')
       . ' – '
       . ($leaseEnd ? date('M j, Y', strtotime($leaseEnd)) : 'N/A')
       . '</td></tr>';
    echo '<tr><td style="font-weight:bold;padding-bottom:16px;">Rent / Frequency</td><td colspan="6" style="padding-bottom:16px;">₦'
       . number_format($agreedRent, 2) . ' · ' . htmlspecialchars($paymentFreq) . '</td></tr>';

    // Filters block
    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">';
    echo 'FILTERS APPLIED';
    echo '</td></tr>';
    echo '<tr><td colspan="7" style="font-size:10pt;color:#333;padding-bottom:16px;">';
    echo htmlspecialchars($filterSummary);
    echo '</td></tr>';

    // Summary block
    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">';
    echo 'SUMMARY';
    echo '</td></tr>';
    echo '<tr>';
    echo '<td style="font-weight:bold;border-top:1px solid #ddd;padding-top:8px;">Total In</td>';
    echo '<td style="font-weight:bold;border-top:1px solid #ddd;padding-top:8px;">Total Out</td>';
    echo '<td style="font-weight:bold;border-top:1px solid #ddd;padding-top:8px;">Net</td>';
    echo '<td colspan="4" style="font-weight:bold;border-top:1px solid #ddd;padding-top:8px;">Transactions</td>';
    echo '</tr>';
    echo '<tr><td style="padding-bottom:20px;color:#059669;font-weight:bold;">₦' . number_format($totalIn, 2) . '</td>';
    echo '<td style="padding-bottom:20px;color:#dc2626;font-weight:bold;">₦' . number_format($totalOut, 2) . '</td>';
    echo '<td style="padding-bottom:20px;font-weight:bold;">₦' . number_format($totalIn - $totalOut, 2) . '</td>';
    echo '<td colspan="4" style="padding-bottom:20px;">' . count($rows) . '</td></tr>';

    // ---- Transaction table ----
    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">';
    echo 'TRANSACTIONS (' . count($rows) . ')';
    echo '</td></tr>';
    echo '</table>';

    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:10pt;">';
    echo '<thead><tr style="background:#1e3c72;color:#fff;font-weight:bold;">';
    echo '<th>Date</th><th>Type</th><th>Description</th><th>Reference</th>'
       . '<th style="text-align:right;">Amount</th><th>Status</th><th>Balance After</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $directionColor = $row['direction'] === 'in' ? '#059669' : '#dc2626';
        $sign = $row['direction'] === 'in' ? '+' : '-';

        echo '<tr>';
        echo '<td>' . date('M j, Y', strtotime($row['transaction_date'])) . '</td>';
        echo '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $row['transaction_type']))) . '</td>';
        echo '<td>' . htmlspecialchars($row['description'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['reference'] ?? '') . '</td>';
        echo '<td style="text-align:right;color:' . $directionColor . ';font-weight:bold;">'
           . $sign . '₦' . number_format((float)$row['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $row['status'] ?? ''))) . '</td>';
        echo '<td style="text-align:right;">'
           . ($row['balance_after'] !== null ? '₦' . number_format((float)$row['balance_after'], 2) : '—')
           . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // ---- Footer note ----
    echo '<table border="0" cellpadding="6" style="font-family:Calibri,Arial,sans-serif;">';
    echo '<tr><td style="font-size:9pt;color:#999;padding-top:20px;">';
    echo 'This statement was generated automatically from the RentFlow Pro system and reflects all transactions recorded up to '
       . date('F j, Y g:i A') . '. For questions or discrepancies, contact support@rentflowpro.com.';
    echo '</td></tr></table>';

    logActivity("[TXN_EXPORT] tenant={$tenantCode} exported statement (" . count($rows) . " rows)");
    exit;

} catch (Throwable $e) {
    logActivity("[TXN_EXPORT] ERROR: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Export failed']);
}