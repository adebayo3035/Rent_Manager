<?php
// client/backend/reports/export_transactions.php — Excel statement export

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

requireAuth([
    'method'   => 'GET',
    'rate_key' => 'client_transaction_export',
    'rate_limit' => [5, 300],
    'roles'    => ['Client'],
]);

try {
    $clientCode = $_SESSION['client_code'] ?? null;
    if (!$clientCode) { http_response_code(401); exit; }

    // Metadata
    $metaStmt = $conn->prepare("
        SELECT c.client_code, c.firstname, c.lastname, c.email, c.phone, c.address,
               c.wallet_balance, c.total_settlement_earned
        FROM clients c WHERE c.client_code = ? LIMIT 1
    ");
    $metaStmt->bind_param("s", $clientCode);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc() ?: [];
    $metaStmt->close();

    // Filters
    $type      = trim($_GET['type'] ?? '');
    $direction = trim($_GET['direction'] ?? '');
    $status    = trim($_GET['status'] ?? '');
    $dateFrom  = trim($_GET['date_from'] ?? '');
    $dateTo    = trim($_GET['date_to'] ?? '');
    $search    = trim($_GET['search'] ?? '');

    // Union (same as fetch, no pagination)
    $union = "
        SELECT s.settlement_date AS d, 'settlement_credit' AS t, 'in' AS dir, s.client_share AS amt,
               CONCAT('Settlement — ', COALESCE(p.property_code,''), ' (', COALESCE(ten.tenant_code,''), ')') AS descr,
               COALESCE(s.rent_payment_id, CONCAT('SET-', LPAD(s.id,6,'0'))) AS ref,
               COALESCE(s.settlement_status,'completed') AS st,
               NULL AS bal
        FROM settlement_transactions s
        LEFT JOIN properties p ON p.id = s.property_id
        LEFT JOIN tenants ten ON ten.id = s.tenant_id
        WHERE s.client_code = ?
        UNION ALL
        SELECT wt.created_at,
               CASE WHEN wt.transaction_type='topup' THEN 'topup'
                    WHEN wt.transaction_type='withdrawal' THEN 'withdrawal'
                    WHEN wt.transaction_type='rent_credit' THEN 'settlement_credit'
                    WHEN wt.transaction_type='settlement_debit' THEN 'settlement_debit'
                    WHEN wt.transaction_type='admin_adjustment' THEN 'adjustment'
                    WHEN wt.transaction_type='debt_recovery' THEN 'debt_recovery'
                    WHEN wt.transaction_type='refund' THEN 'refund'
                    ELSE wt.transaction_type END,
               CASE WHEN wt.amount>=0 THEN 'in' ELSE 'out' END,
               ABS(wt.amount),
               COALESCE(wt.notes, wt.transaction_type),
               COALESCE(wt.request_id, CONCAT('WTX-', LPAD(wt.id,6,'0'))),
               COALESCE(wt.status,'completed'),
               wt.running_balance
        FROM client_wallet_transactions wt
        WHERE wt.client_code = ?
    ";

    $outer = [];
    $params = [$clientCode, $clientCode];
    $types  = 'ss';
    if ($type !== '')      { $outer[] = "u.t = ?";     $params[] = $type;      $types .= 's'; }
    if ($direction !== '') { $outer[] = "u.dir = ?";   $params[] = $direction; $types .= 's'; }
    if ($status !== '')    { $outer[] = "u.st = ?";    $params[] = $status;    $types .= 's'; }
    if ($dateFrom !== '')  { $outer[] = "DATE(u.d) >= ?"; $params[] = $dateFrom; $types .= 's'; }
    if ($dateTo !== '')    { $outer[] = "DATE(u.d) <= ?"; $params[] = $dateTo;   $types .= 's'; }
    if ($search !== '')    { $outer[] = "(u.descr LIKE ? OR u.ref LIKE ?)"; $like = "%{$search}%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }

    $outerWhere = $outer ? 'WHERE ' . implode(' AND ', $outer) : '';
    $stmt = $conn->prepare("SELECT u.* FROM ({$union}) u {$outerWhere} ORDER BY u.d DESC");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = []; $totalIn = 0.0; $totalOut = 0.0;
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
        if ($row['dir'] === 'in') $totalIn += (float)$row['amt'];
        else                       $totalOut += (float)$row['amt'];
    }
    $stmt->close();

    // Filter summary
    $filterParts = [];
    if ($type !== '')      $filterParts[] = 'Type: ' . ucwords(str_replace('_',' ',$type));
    if ($direction !== '') $filterParts[] = 'Direction: ' . ($direction==='in' ? 'Money In' : 'Money Out');
    if ($status !== '')    $filterParts[] = 'Status: ' . ucfirst(str_replace('_',' ',$status));
    if ($dateFrom !== '')  $filterParts[] = 'From: ' . $dateFrom;
    if ($dateTo !== '')    $filterParts[] = 'To: ' . $dateTo;
    if ($search !== '')    $filterParts[] = 'Search: "' . $search . '"';
    $filterSummary = $filterParts ? implode(' · ', $filterParts) : 'None — showing all transactions';

    // Output
    $filename = 'client_statement_' . preg_replace('/[^A-Za-z0-9_]/', '_', $clientCode) . '_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache'); header('Expires: 0');
    echo "\xEF\xBB\xBF";

    echo '<table border="0" cellpadding="6" style="font-family:Calibri,Arial,sans-serif;">';

    echo '<tr><td colspan="7" style="font-size:20pt;font-weight:bold;color:#1e3c72;padding-top:20px;">';
    echo 'Client Transaction Statement'; echo '</td></tr>';
    echo '<tr><td colspan="7" style="font-size:10pt;color:#666;padding-bottom:16px;">';
    echo 'Generated ' . date('F j, Y \a\t g:i A') . ' · RentFlow Pro Client Portal'; echo '</td></tr>';

    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">CLIENT</td></tr>';
    echo '<tr><td style="font-weight:bold;width:140px;">Name</td><td colspan="6">' . htmlspecialchars(trim(($meta['firstname']??'').' '.($meta['lastname']??''))) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;">Client Code</td><td colspan="6">' . htmlspecialchars($clientCode) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;">Email</td><td colspan="6">' . htmlspecialchars($meta['email'] ?? '—') . '</td></tr>';
    echo '<tr><td style="font-weight:bold;padding-bottom:16px;">Phone</td><td colspan="6" style="padding-bottom:16px;">' . htmlspecialchars($meta['phone'] ?? '—') . '</td></tr>';

    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">WALLET BALANCE</td></tr>';
    echo '<tr><td style="font-weight:bold;">Current Balance</td><td colspan="6" style="font-weight:bold;color:#1e3c72;">₦' . number_format((float)($meta['wallet_balance'] ?? 0), 2) . '</td></tr>';
    echo '<tr><td style="font-weight:bold;padding-bottom:16px;">Total Settlement Earned</td><td colspan="6" style="padding-bottom:16px;">₦' . number_format((float)($meta['total_settlement_earned'] ?? 0), 2) . '</td></tr>';

    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">FILTERS APPLIED</td></tr>';
    echo '<tr><td colspan="7" style="font-size:10pt;color:#333;padding-bottom:16px;">' . htmlspecialchars($filterSummary) . '</td></tr>';

    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">SUMMARY</td></tr>';
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

    echo '<tr><td colspan="7" style="background:#f8fafc;font-weight:bold;color:#1e3c72;font-size:11pt;padding:10px;">TRANSACTIONS (' . count($rows) . ')</td></tr>';
    echo '</table>';

    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:10pt;">';
    echo '<thead><tr style="background:#1e3c72;color:#fff;font-weight:bold;">';
    echo '<th>Date</th><th>Type</th><th>Description</th><th>Reference</th><th style="text-align:right;">Amount</th><th>Status</th><th style="text-align:right;">Balance</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $sign = $row['dir'] === 'in' ? '+' : '-';
        $color = $row['dir'] === 'in' ? '#059669' : '#dc2626';
        echo '<tr>';
        echo '<td>' . date('M j, Y', strtotime($row['d'])) . '</td>';
        echo '<td>' . htmlspecialchars(ucwords(str_replace('_',' ',$row['t']))) . '</td>';
        echo '<td>' . htmlspecialchars($row['descr'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['ref'] ?? '') . '</td>';
        echo '<td style="text-align:right;color:' . $color . ';font-weight:bold;">' . $sign . '₦' . number_format((float)$row['amt'], 2) . '</td>';
        echo '<td>' . htmlspecialchars(ucwords(str_replace('_',' ',$row['st'] ?? ''))) . '</td>';
        echo '<td style="text-align:right;">' . ($row['bal'] !== null ? '₦' . number_format((float)$row['bal'], 2) : '—') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<table border="0" cellpadding="6" style="font-family:Calibri,Arial,sans-serif;">';
    echo '<tr><td style="font-size:9pt;color:#999;padding-top:20px;">';
    echo 'This statement was generated automatically from the RentFlow Pro system and reflects all transactions recorded up to ' . date('F j, Y g:i A') . '. For questions, contact support@rentflowpro.com.';
    echo '</td></tr></table>';

    logActivity("[CLIENT_TXN_EXPORT] client={$clientCode} exported statement (" . count($rows) . " rows)");
    exit;

} catch (Throwable $e) {
    logActivity("[CLIENT_TXN_EXPORT] ERROR: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Export failed']);
}