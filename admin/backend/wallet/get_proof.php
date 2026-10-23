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
    'rate_key' => 'admin_get_proof',
    'rate_limit' => [30, 60],
    'roles'  => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];
$requestCode = trim($_GET['request_code'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

logActivity("[GET_PROOF] ========== START ==========");
logActivity("[GET_PROOF] Admin: {$adminId} | Request: " . ($requestCode !== '' ? $requestCode : '(empty)')
    . " | IP: {$ip}");

if ($requestCode === '') {
    logActivity("[GET_PROOF] Missing request_code", 'WARN');
    http_response_code(400);
    exit("Missing request_code");
}

$stmt = $conn->prepare("
    SELECT bank_proof_path, user_code, user_type, amount
    FROM wallet_requests
    WHERE request_code = ? AND request_type = 'topup'
    LIMIT 1
");
if (!$stmt) {
    logActivity("[GET_PROOF] Prepare failed: " . $conn->error, 'ERROR');
    http_response_code(500);
    exit("Server error");
}
$stmt->bind_param("s", $requestCode);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['bank_proof_path'])) {
    logActivity("[GET_PROOF] Proof not found for {$requestCode}", 'WARN');
    http_response_code(404);
    exit("Proof not found");
}

// Sanitize the filename to prevent traversal
$safeName = basename($row['bank_proof_path']);
$proofPath = __DIR__ . '/../../../client/backend/wallet/proofs/' . $safeName;

// Guard against directory traversal even if basename() were bypassed
$realPath = realpath($proofPath);
$expectedDir = realpath(__DIR__ . '/../../../client/backend/wallet/proofs');
if ($realPath === false || $expectedDir === false || strpos($realPath, $expectedDir) !== 0) {
    logActivity("[GET_PROOF] SECURITY: path traversal attempt blocked for {$requestCode} "
        . "(resolved='{$realPath}', expected_dir='{$expectedDir}')", 'WARN');
    http_response_code(404);
    exit("Proof file missing");
}

if (!is_file($realPath)) {
    logActivity("[GET_PROOF] Proof file missing on disk: {$safeName} for {$requestCode}", 'WARN');
    http_response_code(404);
    exit("Proof file missing");
}

$mime = mime_content_type($realPath);
$size = filesize($realPath);

logActivity("[GET_PROOF] Serving: file={$safeName}, mime={$mime}, size={$size} bytes, "
    . "request={$requestCode}, client=" . ($row['user_code'] ?? '?')
    . ", amount=₦" . number_format((float)($row['amount'] ?? 0), 2));

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . $size);
// Prevent caching of sensitive proof files
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

readfile($realPath);

logActivity("[GET_PROOF] ========== SUCCESS ==========");
exit;