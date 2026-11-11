<?php
// get-csrf-token.php
header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'POST',
    'rate_key' => 'get_csrf_token',       // ← unique per endpoint
    'roles'    => ['Tenant'],
    // NO require_active_tenancy — token issuance is auth-only.
    // Tenancy eligibility is enforced by the endpoints that USE the token.
]);

// requireAuth already guarantees an authenticated Tenant session,
// so this is defense in depth (harmless if requireAuth changes later).
if (!isset($_SESSION['tenant_code'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$formName = $_GET['form'] ?? 'default';

// Validate form name against an allowlist to prevent token-farming for
// arbitrary form names (each unique name produces a separate HMAC bucket).
$allowedForms = [
    'tenant_withdrawal_form',
    'tenant_bank_setup_form',
    'maintenance_request_form',
    'profile_update_form',
    // add as needed
];
if (!in_array($formName, $allowedForms, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unknown form name',
    ]);
    exit;
}

$token = generateCsrfToken($formName);

echo json_encode([
    'success'    => true,
    'token'      => $token,
    'form'       => $formName,
    'expires_in' => 3600,
]);