<?php
// get-csrf-token.php
header('Content-Type: application/json');
// header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN'] ?? '*');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';

session_start();

// Only allow authenticated users to get tokens
if (!isset($_SESSION['unique_id'])) {
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