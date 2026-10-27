<?php
// client/backend/wallet/save_bank_account.php
// Save a client's first bank account. Refuses if one already exists —
// changing an existing bank is a separate, harder flow (change_bank_account.php).
//
// SECURITY:
//   - Secret answer verified with lockout (3 fails → 15 min lock)
//   - Refuses bank accounts already used by another client (cross-client reuse)
//   - Records claim in client_bank_history for future uniqueness checks

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';   // shared helpers

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_setup_bank',
    'rate_limit' => [5, 600],   // 5 per 10 min — enough for retries
    'csrf'       => ['enabled' => true, 'form_name' => 'client_withdrawal_form'],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) $input = $_POST;

$bankName    = trim($input['bank_name'] ?? '');
$bankCode    = trim($input['bank_code'] ?? '');
$accountName = trim($input['account_name'] ?? '');
$accountNo   = trim($input['account_number'] ?? '');
$secretAns   = trim($input['secret_answer'] ?? '');

logActivity("[CLIENT_SAVE_BANK] ========== START ==========");
logActivity("[CLIENT_SAVE_BANK] Client: {$clientCode}");

// ---- Input validation ----
if ($bankName === '')    json_error("Bank name is required", 400);
if ($bankCode === '')    json_error("Bank code is required", 400);
if ($accountName === '') json_error("Account name is required", 400);
if (strlen($accountName) > 100) json_error("Account name is too long", 400);
if (!preg_match('/^\d{10}$/', $accountNo)) json_error("Account number must be 10 digits", 400);
if ($secretAns === '')   json_error("Please provide your secret answer", 400);

$conn->begin_transaction();
logActivity("[CLIENT_SAVE_BANK] Transaction started");

try {
    // ---- 1) Lock client row ----
    $stmt = $conn->prepare("
        SELECT secret_answer, has_secret_set, bank_account_number
        FROM clients
        WHERE client_code = ?
        FOR UPDATE
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) throw new Exception("Client not found", 404);

    // ---- 2) Refuse if bank already exists ----
    if (!empty($client['bank_account_number'])) {
        logActivity("[CLIENT_SAVE_BANK] Refused: bank already on file for {$clientCode}", 'WARN');
        throw new Exception(
            "A bank account is already on file. Please use the change-bank flow to update it.",
            409
        );
    }

    // ---- 3) Lockout check FIRST (before verifying) ----
    $lockState = checkSecretLockout($conn, 'client', $clientCode);
    if ($lockState['locked']) {
        logActivity("[CLIENT_SAVE_BANK] Blocked: account locked for {$clientCode}", 'WARN');
        throw new Exception($lockState['message'], 429);
    }

    // ---- 4) Secret verification with lockout tracking ----
    if ((int)$client['has_secret_set'] !== 1 || empty($client['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    $verify = verifySecretAnswer($conn, 'client', $clientCode, $client['secret_answer'], $secretAns);
    if (!$verify['ok']) {
        logActivity("[CLIENT_SAVE_BANK] Secret answer failed for {$clientCode}: "
            . ($verify['message'] ?? ''), 'WARN');
        throw new Exception(
            $verify['message'] ?? 'Incorrect secret answer',
            $verify['locked'] ? 429 : 401
        );
    }

    // ---- 5) Bank uniqueness (must be BEFORE writing) ----
    $bankAvail = checkBankAccountAvailability($conn, $bankCode, $accountNo, $clientCode);
    if (!$bankAvail['available']) {
        logActivity("[CLIENT_SAVE_BANK] Bank reuse refused for {$clientCode}: "
            . ($bankAvail['reason'] ?? ''), 'WARN');
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

    // ---- 6) Save bank details ----
    // bank_changed_at is set even on the FIRST save, so the 24h cooldown
    // applies uniformly. If you'd rather the first save not count against
    // the cooldown, remove the bank_changed_at = NOW() here.
    $save = $conn->prepare("
        UPDATE clients
        SET bank_name = ?, bank_code = ?, bank_account_name = ?, bank_account_number = ?,
            bank_changed_at = NOW()
        WHERE client_code = ?
    ");
    $save->bind_param("sssss", $bankName, $bankCode, $accountName, $accountNo, $clientCode);
    if (!$save->execute()) throw new Exception("Failed to save bank details: " . $save->error);
    $save->close();

    // ---- 7) Record bank claim for uniqueness ----
    recordBankClaim($conn, $clientCode, $bankCode, $accountNo, $accountName);

    // ---- 8) Reset lockout on success (defense in depth) ----
    setSecretAttempts($conn, 'client', $clientCode, 0, null);

    $conn->commit();
    logActivity("[CLIENT_SAVE_BANK] Transaction committed");

    $masked = '******' . substr($accountNo, -4);
    logActivity("[CLIENT_SAVE_BANK] ========== SUCCESS ==========");
    logActivity("[CLIENT_SAVE_BANK] client={$clientCode}, bank={$bankName} ({$masked})");

    json_success([
        'bank_name'           => $bankName,
        'bank_code'           => $bankCode,
        'bank_account_name'   => $accountName,
        'bank_account_number' => $accountNo,
        'masked_account'      => $masked,
        'has_bank_account'    => true,
    ], "Bank account saved");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[CLIENT_SAVE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_SAVE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}