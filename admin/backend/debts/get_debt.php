<?php
// admin/backend/debts/get_debt.php
// Single debt with payment history

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'GET',
    'rate_key' => 'admin_get_debt',
    'roles'    => ['Super Admin', 'Admin'],
]);

$debtCode = trim($_GET['debt_code'] ?? '');
if ($debtCode === '') json_error("Debt code is required", 400);

try {
    $stmt = $conn->prepare("
        SELECT td.*,
               CONCAT(t.firstname, ' ', t.lastname) AS tenant_name,
               t.phone AS tenant_phone,
               t.email AS tenant_email,
               t.wallet_balance AS tenant_wallet,
               CONCAT(c.firstname, ' ', c.lastname) AS client_name
        FROM tenant_debts td
        LEFT JOIN tenants t ON t.tenant_code = td.tenant_code
        LEFT JOIN clients c ON c.client_code = td.client_code
        WHERE td.debt_code = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $debtCode);
    $stmt->execute();
    $debt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$debt) json_error("Debt not found", 404);

    // Payment history
    $pmtStmt = $conn->prepare("
        SELECT id, amount, payment_method, reference_number, notes, created_at, recorded_by
        FROM tenant_debt_payments
        WHERE debt_id = ?
        ORDER BY created_at DESC
    ");
    $pmtStmt->bind_param("i", $debt['id']);
    $pmtStmt->execute();
    $pmtRes = $pmtStmt->get_result();

    $payments = [];
    while ($p = $pmtRes->fetch_assoc()) {
        $payments[] = [
            'id'               => (int) $p['id'],
            'amount'           => (float) $p['amount'],
            'amount_formatted' => '₦' . number_format((float) $p['amount'], 2),
            'method'           => $p['payment_method'],
            'reference_number' => $p['reference_number'],
            'notes'            => $p['notes'],
            'created_at'       => $p['created_at'],
            'created_at_formatted' => date('M j, Y H:i', strtotime($p['created_at'])),
            'recorded_by'      => $p['recorded_by'],
        ];
    }
    $pmtStmt->close();

    json_success('Debt retrieved Successfully', [
        'debt' => [
            'id'                        => (int) $debt['id'],
            'debt_code'                 => $debt['debt_code'],
            'tenant_code'               => $debt['tenant_code'],
            'tenant_name'               => trim($debt['tenant_name'] ?? '') ?: $debt['tenant_code'],
            'tenant_phone'              => $debt['tenant_phone'],
            'tenant_email'              => $debt['tenant_email'],
            'tenant_wallet'             => (float) $debt['tenant_wallet'],
            'tenant_wallet_formatted'   => '₦' . number_format((float) $debt['tenant_wallet'], 2),
            'client_name'               => $debt['client_name'],
            'source_type'               => $debt['source_type'],
            'source_id'                 => $debt['source_id'],
            'original_amount'           => (float) $debt['original_amount'],
            'original_amount_formatted' => '₦' . number_format((float) $debt['original_amount'], 2),
            'amount_paid'               => (float) $debt['amount_paid'],
            'amount_paid_formatted'     => '₦' . number_format((float) $debt['amount_paid'], 2),
            'balance'                   => (float) $debt['balance'],
            'balance_formatted'         => '₦' . number_format((float) $debt['balance'], 2),
            'offset_applied'            => (float) $debt['offset_applied'],
            'offset_applied_formatted'  => '₦' . number_format((float) $debt['offset_applied'], 2),
            'status'                    => $debt['status'],
            'notes'                     => $debt['notes'],
            'created_at'                => $debt['created_at'],
            'created_at_formatted'      => date('M j, Y H:i', strtotime($debt['created_at'])),
            'resolved_at'               => $debt['resolved_at'],
            'resolved_at_formatted'     => $debt['resolved_at']
                                            ? date('M j, Y H:i', strtotime($debt['resolved_at']))
                                            : null,
        ],
        'payments' => $payments,
    ]);

} catch (Throwable $e) {
    logActivity("Get debt error: " . $e->getMessage(), 'ERROR');
    json_error("Failed to load debt", 500);
}