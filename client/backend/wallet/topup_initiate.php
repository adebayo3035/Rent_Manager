<?php
// client/backend/wallet/topup_initiate.php
// Client submits a top-up request with proof of bank transfer.
//
// SECURITY: The reference_number is derived server-side from the
// client_code. Whatever the client sends in `reference_number` is
// IGNORED (or rejected, if STRICT_REFERENCE_CHECK is true).

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/reference_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

define('STRICT_REFERENCE_CHECK', true);

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_topup',
    'rate_limit' => [5, 300],
    'csrf'       => ['enabled' => true, 'form_name' => 'client_topup_form'],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

// ---- Log entry ----
logActivity("[TOPUP_INITIATE] ========== START ==========");
logActivity("[TOPUP_INITIATE] Client: {$clientCode} | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// ---- Input ----
$amountRaw       = trim($_POST['amount'] ?? '');
$clientReference = trim($_POST['reference_number'] ?? '');
$notes           = trim($_POST['notes'] ?? '');
$proofFileName   = $_FILES['proof']['name'] ?? 'none';
$proofFileSize   = $_FILES['proof']['size'] ?? 0;

logActivity("[TOPUP_INITIATE] Input: amount_raw={$amountRaw}, "
    . "has_client_reference=" . ($clientReference !== '' ? 'yes' : 'no') . ", "
    . "proof={$proofFileName} ({$proofFileSize} bytes), "
    . "notes_len=" . strlen($notes));

// ---- Derive authoritative bank reference ----
$reference = clientTopupReference($clientCode);
logActivity("[TOPUP_INITIATE] Server-derived bank reference: {$reference}");

if ($clientReference !== '' && !clientTopupReferenceMatches($clientCode, $clientReference)) {
    if (STRICT_REFERENCE_CHECK) {
        logActivity("[TOPUP_INITIATE] SECURITY: reference mismatch — "
            . "client sent '{$clientReference}', expected '{$reference}'. Rejecting.", 'WARN');
        json_error("Reference mismatch. Please refresh and try again.", 400);
    }
    logActivity("[TOPUP_INITIATE] Non-strict: overriding client reference '{$clientReference}' "
        . "with server value '{$reference}'.", 'WARN');
}

// ---- Amount (strict: digits + up to 2 decimals only) ----
$amountClean = str_replace(',', '', $amountRaw);
if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amountClean)) {
    logActivity("[TOPUP_INITIATE] Invalid amount format: '{$amountRaw}'", 'WARN');
    json_error("Please enter a valid amount (numbers only, up to 2 decimal places)", 400);
}
$amount = round((float) $amountClean, 2);
if ($amount < 1000) {
    logActivity("[TOPUP_INITIATE] Amount below minimum: {$amount}", 'WARN');
    json_error("Minimum top-up amount is ₦1,000", 400);
}
if ($amount > 50_000_000) {
    logActivity("[TOPUP_INITIATE] Amount above maximum: {$amount}", 'WARN');
    json_error("Maximum top-up amount is ₦50,000,000", 400);
}
logActivity("[TOPUP_INITIATE] Validated amount: ₦" . number_format($amount, 2));

// ---- Proof upload ----
if (!isset($_FILES['proof'])) {
    logActivity("[TOPUP_INITIATE] No proof file uploaded", 'WARN');
    json_error("Please upload proof of payment", 400);
}
$proof = $_FILES['proof'];
if ($proof['error'] !== UPLOAD_ERR_OK) {
    logActivity("[TOPUP_INITIATE] Upload error code: {$proof['error']}", 'WARN');
    json_error("Upload failed. Please try again.", 400);
}

$proofExt = strtolower(pathinfo(basename($proof['name']), PATHINFO_EXTENSION));

$extMimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'pdf'  => 'application/pdf',
];
if (!isset($extMimeMap[$proofExt])) {
    logActivity("[TOPUP_INITIATE] Rejected proof: unsupported extension '{$proofExt}'", 'WARN');
    json_error("Proof must be JPG, PNG, or PDF", 400);
}

if (!function_exists('finfo_open')) {
    logActivity("[TOPUP_INITIATE] ERROR: fileinfo extension unavailable", 'ERROR');
    json_error("Server misconfiguration — please contact support", 500);
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    logActivity("[TOPUP_INITIATE] ERROR: finfo_open failed", 'ERROR');
    json_error("Server misconfiguration — please contact support", 500);
}
$proofMime = finfo_file($finfo, $proof['tmp_name']);
// finfo_close() is deprecated in PHP 8.5 — the finfo object is freed automatically.

if ($proofMime !== $extMimeMap[$proofExt]) {
    logActivity("[TOPUP_INITIATE] Rejected proof: extension/content mismatch — "
        . "ext={$proofExt}, actual_mime={$proofMime}", 'WARN');
    json_error("Proof file content does not match its extension", 400);
}

if ($proof['size'] > 3 * 1024 * 1024) {
    logActivity("[TOPUP_INITIATE] Proof too large: {$proof['size']} bytes", 'WARN');
    json_error("Proof file too large (max 3 MB)", 400);
}

$proofDir = __DIR__ . '/proofs/';
if (!is_dir($proofDir)) mkdir($proofDir, 0750, true);
$proofName = 'TOPUP_' . $clientCode . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $proofExt;
$proofPath = $proofDir . $proofName;
if (!move_uploaded_file($proof['tmp_name'], $proofPath)) {
    logActivity("[TOPUP_INITIATE] Failed to move uploaded proof to {$proofPath}", 'ERROR');
    json_error("Failed to save proof file", 500);
}
logActivity("[TOPUP_INITIATE] Proof saved: {$proofName}");

$requestCode = 'WRQ_' . strtoupper(bin2hex(random_bytes(6)));
logActivity("[TOPUP_INITIATE] Generated request_code: {$requestCode}");

$conn->begin_transaction();
logActivity("[TOPUP_INITIATE] Transaction started");

try {
    // ---- Duplicate check (fast reject; DB UNIQUE is the real guard) ----
    $dupCheck = $conn->prepare("
        SELECT id FROM wallet_requests
        WHERE user_type = 'client' AND user_code = ?
          AND reference_number = ?
          AND request_type = 'topup'
          AND status = 'pending'
        LIMIT 1
    ");
    if (!$dupCheck) throw new Exception("Prepare failed (dup check): " . $conn->error);
    $dupCheck->bind_param("ss", $clientCode, $reference);
    if (!$dupCheck->execute()) throw new Exception("Dup check failed: " . $dupCheck->error);
    if ($dupCheck->get_result()->num_rows > 0) {
        $dupCheck->close();
        @unlink($proofPath);
        logActivity("[TOPUP_INITIATE] Duplicate pending top-up for reference {$reference}", 'WARN');
        throw new Exception(
            "You already have a pending top-up. Wait for it to be processed before submitting another.",
            409
        );
    }
    $dupCheck->close();
    logActivity("[TOPUP_INITIATE] Duplicate check passed");

    // ---- Insert ----
    $ins = $conn->prepare("
        INSERT INTO wallet_requests
            (request_code, user_type, user_code, request_type, amount,
             bank_proof_path, bank_proof_notes, reference_number,
             status, created_at)
        VALUES (?, 'client', ?, 'topup', ?, ?, ?, ?, 'pending', NOW())
    ");
    if (!$ins) throw new Exception("Prepare failed (insert): " . $conn->error);
    $ins->bind_param(
        "ssdsss",
        $requestCode,
        $clientCode,
        $amount,
        $proofName,
        $notes,
        $reference
    );
    if (!$ins->execute()) {
        // Handle DB-level UNIQUE violations:
        //  - pending_marker (one pending top-up per client) OR
        //  - request_code collision (astronomically rare).
        if ($ins->errno === 1062) {
            $errText = $ins->error;
            @unlink($proofPath);
            if (stripos($errText, 'pending_marker') !== false
                || stripos($errText, 'uniq_pending_per_user_type') !== false) {
                logActivity("[TOPUP_INITIATE] DB rejected: pending top-up already exists (concurrent insert) for {$clientCode}", 'WARN');
                throw new Exception(
                    "You already have a pending top-up. Wait for it to be processed before submitting another.",
                    409
                );
            }
            logActivity("[TOPUP_INITIATE] DB rejected: request_code collision {$requestCode}", 'WARN');
            throw new Exception("Please try again (transient issue)", 409);
        }
        throw new Exception("Failed to save request: " . $ins->error);
    }
    $ins->close();
    logActivity("[TOPUP_INITIATE] wallet_requests row inserted: "
        . "request_code={$requestCode}, amount=₦" . number_format($amount, 2)
        . ", reference={$reference}, status=pending");

    $conn->commit();
    logActivity("[TOPUP_INITIATE] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    @unlink($proofPath);
    logActivity("[TOPUP_INITIATE] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[TOPUP_INITIATE] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    exit;
} 
// finally {
//     if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
//         $conn->close();
//     }
// }
// ---- Notify admins (best-effort, post-commit) ----
try {
    $notifiedAdmins = 0;
    $admins = $conn->query("SELECT unique_id FROM admin_tbl
    WHERE status = '1' AND role IN ('Super Admin', 'Admin')
    LIMIT 20");
    if ($admins) {
        while ($adm = $admins->fetch_assoc()) {
            if (function_exists('createAdminNotification')) {
                createAdminNotification(
                    $conn,
                    (int) $adm['unique_id'],
                    'payment_confirmation',
                    'New Client Top-Up Request',
                    "Client {$clientCode} requested a top-up of ₦" . number_format($amount, 2)
                        . ". Reference: {$reference}.",
                    'info',
                    0
                );
            }
            $notifiedAdmins++;
        }
    }
    logActivity("[TOPUP_INITIATE] Admins notified: {$notifiedAdmins}");
} catch (Throwable $notifErr) {
    logActivity("[TOPUP_INITIATE] Admin notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
}

// ---- Acknowledge to the client (best-effort) ----
try {
    if (function_exists('createClientNotification')) {
        createClientNotification(
            $conn,
            $clientCode,
            'wallet',
            'Top-Up Request Received',
            "We received your top-up request of ₦" . number_format($amount, 2)
                . ". An admin will review your bank proof and credit your wallet shortly. "
                . "Reference: {$reference}.",
            [
                'request_code'     => $requestCode,
                'amount'           => $amount,
                'reference_number' => $reference,
                'status'           => 'pending',
            ],
            'normal',
            '../wallet.php',
            'View Wallet'
        );
        logActivity("[TOPUP_INITIATE] Client acknowledgment sent: {$clientCode}");
    }
} catch (Throwable $notifErr) {
    logActivity("[TOPUP_INITIATE] Client acknowledgment failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
}
// ---- Close connection LAST ----
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $conn->close();
}
logActivity("[TOPUP_INITIATE] ========== SUCCESS ==========");
logActivity("[TOPUP_INITIATE] request_code={$requestCode}, client={$clientCode}, "
    . "amount=₦" . number_format($amount, 2) . ", reference={$reference}");

json_success('Top-up request submitted. Admin will review and credit your wallet.', [
    'request_code'     => $requestCode,
    'reference_number' => $reference,
    'amount'           => $amount,
    'amount_formatted' => '₦' . number_format($amount, 2),
    'status'           => 'pending',
]);