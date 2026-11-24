<?php
// tenant/backend/reports/get_statement_metadata.php
// Returns tenant identity, property/apartment info, and lease details
// for building the statement header on exports.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

requireAuth([
    'method'                 => 'GET',
    'rate_key'               => 'tenant_statement_metadata',
    'rate_limit'             => [30, 60],
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => false,
]);

try {
    $tenantCode = $_SESSION['tenant_code'] ?? null;
    if (!$tenantCode) json_error("Session expired", 401);

    $stmt = $conn->prepare("
        SELECT
            t.tenant_code,
            t.firstname,
            t.lastname,
            t.email,
            t.phone,
            t.gender,
            t.lease_start_date,
            t.lease_end_date,
            t.temp_lease_end_date,
            t.payment_frequency,
            t.agreed_rent_amount,
            t.apartment_code,
            t.property_code,
            a.apartment_number,
            a.rent_amount AS apartment_rent,
            a.security_deposit,
            p.name AS property_name,
            p.address AS property_address,
            p.property_code AS prop_code,
            (
                SELECT COUNT(*) FROM rent_payment_tracker
                WHERE tenant_code = t.tenant_code
            ) AS total_periods,
            (
                SELECT COUNT(*) FROM rent_payment_tracker
                WHERE tenant_code = t.tenant_code AND status = 'paid'
            ) AS paid_periods
        FROM tenants t
        LEFT JOIN apartments a ON t.apartment_code = a.apartment_code
        LEFT JOIN properties p ON t.property_code = p.property_code
        WHERE t.tenant_code = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) json_error("Tenant not found", 404);

    json_success([
        'tenant' => [
            'code'      => $row['tenant_code'],
            'full_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'firstname' => $row['firstname'],
            'lastname'  => $row['lastname'],
            'email'     => $row['email'],
            'phone'     => $row['phone'],
            'gender'    => $row['gender'],
        ],
        'property' => [
            'code'    => $row['prop_code'] ?? $row['property_code'],
            'name'    => $row['property_name'] ?? 'N/A',
            'address' => $row['property_address'] ?? 'N/A',
        ],
        'apartment' => [
            'code'             => $row['apartment_code'],
            'number'           => $row['apartment_number'],
            'rent_amount'      => (float)($row['apartment_rent'] ?? 0),
            'security_deposit' => (float)($row['security_deposit'] ?? 0),
        ],
        'lease' => [
            'start_date'         => $row['lease_start_date'],
            'end_date'           => $row['lease_end_date'],
            'temp_end_date'      => $row['temp_lease_end_date'],
            'payment_frequency'  => $row['payment_frequency'],
            'agreed_rent_amount' => (float)($row['agreed_rent_amount'] ?? 0),
            'total_periods'      => (int)$row['total_periods'],
            'paid_periods'       => (int)$row['paid_periods'],
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => 'Tenant Portal',
    ], "Statement metadata loaded");

} catch (Throwable $e) {
    logActivity("[STMT_META] ERROR: " . $e->getMessage(), 'ERROR');
    $code = (int)$e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}