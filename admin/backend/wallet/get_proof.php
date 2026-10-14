<?php
// admin/backend/wallet/get_proof.php
// Securely serves a wallet proof file. Admin only.

require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$auth = requireAuth([
    'method' => 'GET',
    'roles'  => ['Super Admin', 'Admin'],
]);

$requestCode = trim($_GET['request_code'] ?? '');
if ($requestCode === '') {
    http_response_code(400);
    exit("Missing request_code");
}

$stmt = $conn->prepare("
    SELECT bank_proof_path FROM wallet_requests
    WHERE request_code = ? AND request_type = 'topup'
    LIMIT 1
");
$stmt->bind_param("s", $requestCode);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['bank_proof_path'])) {
    http_response_code(404);
    exit("Proof not found");
}

// Sanitize the filename to prevent traversal
$safeName = basename($row['bank_proof_path']);
$proofPath = __DIR__ . '/../../../client/backend/wallet/proofs/' . $safeName;

if (!is_file($proofPath)) {
    http_response_code(404);
    exit("Proof file missing");
}

$mime = mime_content_type($proofPath);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($proofPath));
readfile($proofPath);
exit;