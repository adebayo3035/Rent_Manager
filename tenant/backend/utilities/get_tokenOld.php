<?php
// get-csrf-token.php
header('Content-Type: application/json');
// header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN'] ?? '*');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
if (!isset($_SESSION)) session_start();
 rateLimiter();

$auth = requireAuth([
    'method'                 => 'POST',
    'rate_key'               => 'create_maintenance_request',
    'roles'                  => ['Tenant'],
    'require_active_tenancy' => true,
]);


// Only allow authenticated users to get tokens
if (!isset($_SESSION['tenant_code'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated'
    ]);
    exit;
}

// Get form name from request or use default
$formName = $_GET['form'] ?? 'default';

// Generate token
$token = generateCsrfToken($formName);

// Return token
echo json_encode([
    'success' => true,
    'token' => $token,
    'form' => $formName,
    'expires_in' => 3600 // 1 hour
]);