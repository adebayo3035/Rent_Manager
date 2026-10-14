<?php
// client/backend/wallet/topup_initiate.php
// Client submits a top-up request with proof of bank transfer

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'   => 'POST',
    'rate_key' => 'client_topup',
    'rate_limit' => [5, 300],   // 5 requests per 5 min
    'csrf'     => ['enabled' => true, 'form_name' => 'client_topup_form'],
    'roles'    => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) {
    json_error("Session expired", 401);
}

// ---- Input ----
$amountRaw   = trim($_POST['amount'] ?? '');
$reference   = trim($_POST['reference_number'] ?? '');
$notes       = trim($_POST['notes'] ?? '');

// Amount: strip commas, must be numeric, min 1000, max 50M
$amountClean = str_replace(',', '', $amountRaw);
if (!is_numeric($amountClean)) {
    json_error("Please enter a valid amount", 400);
}
$amount = round((float) $amountClean, 2);

if ($amount < 1000) {
    json_error("Minimum top-up amount is ₦1,000", 400);
}
if ($amount > 50_000_000) {
    json_error("Maximum top-up amount is ₦50,000,000", 400);
}

if ($reference === '') {
    json_error("Please provide the bank transaction reference", 400);
}

// ---- Proof upload ----
if (!isset($_FILES['proof'])) {
    json_error("Please upload proof of payment", 400);
}
$proof = $_FILES['proof'];
if ($proof['error'] !== UPLOAD_ERR_OK) {
    json_error("Upload failed. Please try again.", 400);
}

$proofExt = strtolower(pathinfo(basename($proof['name']), PATHINFO_EXTENSION));
$proofMime = mime_content_type($proof['tmp_name']);
$allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
$allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];

if (!in_array($proofExt, $allowedExt, true) ||
    !in_array($proofMime, $allowedMime, true)) {
    json_error("Proof must be JPG, PNG, or PDF", 400);
}

if ($proof['size'] > 3 * 1024 * 1024) { // 3 MB
    json_error("Proof file too large (max 3 MB)", 400);
}

// Save
$proofDir = __DIR__ . '/proofs/';
if (!is_dir($proofDir)) {
    mkdir($proofDir, 0750, true);
}
$proofName = 'TOPUP_' . $clientCode . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $proofExt;
$proofPath = $proofDir . $proofName;

if (!move_uploaded_file($proof['tmp_name'], $proofPath)) {
    json_error("Failed to save proof file", 500);
}

// ---- Insert request ----
$requestCode = 'WRQ_' . strtoupper(bin2hex(random_bytes(6)));

$conn->begin_transaction();
try {
    // Prevent duplicate reference for the same client
    $dupCheck = $conn->prepare("
        SELECT id FROM wallet_requests
        WHERE user_type = 'client' AND user_code = ?
          AND reference_number = ?
          AND status IN ('pending','approved')
        LIMIT 1
    ");
    $dupCheck->bind_param("ss", $clientCode, $reference);
    $dupCheck->execute();
    if ($dupCheck->get_result()->num_rows > 0) {
        $dupCheck->close();
        @unlink($proofPath);
        throw new Exception("A request with this reference already exists", 409);
    }
    $dupCheck->close();

    $ins = $conn->prepare("
        INSERT INTO wallet_requests
            (request_code, user_type, user_code, request_type, amount,
             bank_proof_path, bank_proof_notes, reference_number,
             status, created_at)
        VALUES (?, 'client', ?, 'topup', ?, ?, ?, ?, 'pending', NOW())
    ");
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
        throw new Exception("Failed to save request: " . $ins->error);
    }
    $ins->close();

    // Notify admins
    try {
        $admins = $conn->query("SELECT unique_id FROM admin_tbl WHERE status = 1 LIMIT 10");
        while ($adm = $admins->fetch_assoc()) {
            createNotification(
                $conn,
                $adm['unique_id'],
                'wallet',
                'New Client Top-Up Request',
                "Client {$clientCode} requested a top-up of ₦" . number_format($amount, 2)
                . ". Reference: {$reference}.",
                ['request_code' => $requestCode, 'amount' => $amount],
                'normal',
                '../wallet_requests.php',
                'Review Request'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("Top-up notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    json_success([
        'request_code' => $requestCode,
        'amount'       => $amount,
        'amount_formatted' => '₦' . number_format($amount, 2),
        'status'       => 'pending',
    ], 'Top-up request submitted. Admin will review and credit your wallet.');

} catch (Throwable $e) {
    $conn->rollback();
    @unlink($proofPath);
    logActivity("Top-up initiate error: " . $e->getMessage(), 'ERROR');
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}