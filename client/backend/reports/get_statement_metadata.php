<?php
// client/backend/reports/get_statement_metadata.php
// Returns client identity + property portfolio + wallet balance for the statement header.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

requireAuth([
    'method'   => 'GET',
    'rate_key' => 'client_statement_metadata',
    'rate_limit' => [30, 60],
    'roles'    => ['Client'],
]);

try {
    $clientCode = $_SESSION['client_code'] ?? null;
    if (!$clientCode) json_error("Session expired", 401);

    // Client identity + wallet
    $stmt = $conn->prepare("
        SELECT client_code, firstname, lastname, email, phone, address,
               gender, wallet_balance, settlement_balance, total_settlement_earned,
               last_settlement_date
        FROM clients WHERE client_code = ? LIMIT 1
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client) json_error("Client not found", 404);

    // Property portfolio summary
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_properties,
            COALESCE(SUM(occupied_apartments), 0) AS total_occupied_units,
            COALESCE(SUM(property_type_unit), 0) AS total_units
        FROM properties WHERE client_code = ?
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $portfolio = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Lifetime transaction stats (settlements only, for context)
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(client_share), 0) AS lifetime_settlements,
            COUNT(*) AS total_settlements
        FROM settlement_transactions
        WHERE client_code = ? AND settlement_status = 'completed'
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $lifetime = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_success([
        'client' => [
            'code'      => $client['client_code'],
            'full_name' => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')),
            'firstname' => $client['firstname'],
            'lastname'  => $client['lastname'],
            'email'     => $client['email'],
            'phone'     => $client['phone'],
            'address'   => $client['address'],
        ],
        'wallet' => [
            'balance'                  => (float)($client['wallet_balance'] ?? 0),
            'settlement_balance'       => (float)($client['settlement_balance'] ?? 0),
            'total_settlement_earned'  => (float)($client['total_settlement_earned'] ?? 0),
            'last_settlement_date'     => $client['last_settlement_date'],
        ],
        'portfolio' => [
            'total_properties'      => (int)($portfolio['total_properties'] ?? 0),
            'total_units'           => (int)($portfolio['total_units'] ?? 0),
            'total_occupied_units'  => (int)($portfolio['total_occupied_units'] ?? 0),
        ],
        'lifetime' => [
            'lifetime_settlements'  => (float)($lifetime['lifetime_settlements'] ?? 0),
            'total_settlements'     => (int)($lifetime['total_settlements'] ?? 0),
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => 'Client Portal',
    ], "Statement metadata loaded");

} catch (Throwable $e) {
    logActivity("[CLIENT_STMT_META] ERROR: " . $e->getMessage(), 'ERROR');
    $code = (int)$e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    json_error($e->getMessage(), $code);
}