<?php
// client/backend/wallet/change_bank_account.php
// Change a client's destination bank account.
//
// SECURITY:
//   - Refuses if pending withdrawal exists.
//   - Enforces 24h cooldown between changes.
//   - Secret answer is checked with lockout (3 fails → 15 min lock).
//   - Refuses bank accounts already used by another client.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
rateLimiter();

$auth = requireAuth([
    'method' => 'POST',
    'rate_key' => 'client_change_bank',
    'rate_limit' => [3, 600],
    'csrf' => ['enabled' => true, 'form_name' => 'client_withdrawal_form'],
    'roles' => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode)
    json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input))
    $input = $_POST;

$newBankName = trim($input['bank_name'] ?? '');
$newBankCode = trim($input['bank_code'] ?? '');
$newAccountName = trim($input['account_name'] ?? '');
$newAccountNo = trim($input['account_number'] ?? '');
$currentSecret = trim($input['secret_answer'] ?? '');

logActivity("[CLIENT_CHANGE_BANK] ========== START ==========");
logActivity("[CLIENT_CHANGE_BANK] Client: {$clientCode}");

// ---- Validation ----
if ($newBankName === '')
    json_error("Bank name is required", 400);
if ($newBankCode === '')
    json_error("Bank code is required", 400);
if ($newAccountName === '')
    json_error("Account name is required", 400);
if (!preg_match('/^\d{10}$/', $newAccountNo))
    json_error("Account number must be 10 digits", 400);
if ($currentSecret === '')
    json_error("Current secret answer is required", 400);

$conn->begin_transaction();
logActivity("[CLIENT_CHANGE_BANK] Transaction started");

try {
    // ---- 1) Lock client row ----
    $stmt = $conn->prepare("
        SELECT wallet_balance, secret_answer, has_secret_set,
               bank_name, bank_code, bank_account_name, bank_account_number, bank_changed_at
        FROM clients WHERE client_code = ? FOR UPDATE
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client)
        throw new Exception("Client not found", 404);

    // ---- 2) Lockout check FIRST (before touching anything) ----
    $lockState = checkSecretLockout($conn, 'client', $clientCode);
    if ($lockState['locked']) {
        logActivity("[CLIENT_CHANGE_BANK] Blocked: account locked for {$clientCode}", 'WARN');
        throw new Exception($lockState['message'], 429);
    }

    // ---- 3) No pending withdrawal ----
    if (countPendingWithdrawals($conn, 'client', $clientCode) > 0) {
        logActivity("[CLIENT_CHANGE_BANK] Blocked: pending withdrawal for {$clientCode}", 'WARN');
        throw new Exception(
            "You have a pending withdrawal. Please wait for it to be processed before changing your bank.",
            409
        );
    }

    // ---- 4) 24h cooldown ----
    if (!empty($client['bank_changed_at']) && strtotime($client['bank_changed_at']) > time() - 86400) {
        $nextAllowed = date('Y-m-d H:i', strtotime($client['bank_changed_at']) + 86400);
        throw new Exception("You can only change your bank once every 24 hours. Next allowed: {$nextAllowed}", 429);
    }

    // ---- 5) Secret answer verification with lockout ----
    if ((int) $client['has_secret_set'] !== 1 || empty($client['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    $verify = verifySecretAnswer($conn, 'client', $clientCode, $client['secret_answer'], $currentSecret);
    if (!$verify['ok']) {
        logActivity("[CLIENT_CHANGE_BANK] Secret answer failed for {$clientCode}: " . ($verify['message'] ?? ''), 'WARN');
        throw new Exception($verify['message'] ?? 'Incorrect secret answer', $verify['locked'] ? 429 : 401);
    }

    $isSameBank = (
        $client['bank_account_number'] === $newAccountNo
        && (string) $client['bank_code'] === (string) $newBankCode
    );
    if ($isSameBank) {
        logActivity("[CLIENT_CHANGE_BANK] No-op: same bank submitted for {$clientCode}", 'WARN');
        throw new Exception(
            "This is already your current bank account. No change was made.",
            400
        );
    }

    // ---- 7) Bank account uniqueness ----
    $bankAvail = checkBankAccountAvailability($conn, $newBankCode, $newAccountNo, $clientCode);
    if (!$bankAvail['available']) {
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

    // ---- 7) Apply change ----
    $oldMasked = !empty($client['bank_account_number'])
        ? '******' . substr($client['bank_account_number'], -4)
        : 'none';
    $newMasked = '******' . substr($newAccountNo, -4);
    logActivity("[CLIENT_CHANGE_BANK] Changing bank for {$clientCode}: "
        . "{$client['bank_name']} ({$oldMasked}) → {$newBankName} ({$newMasked})");

    $upd = $conn->prepare("
            UPDATE clients
            SET bank_name = ?, bank_code = ?, bank_account_name = ?, bank_account_number = ?,
                bank_changed_at = NOW()
            WHERE client_code = ?
        ");
    $upd->bind_param("sssss", $newBankName, $newBankCode, $newAccountName, $newAccountNo, $clientCode);
    if (!$upd)
        throw new Exception("Prepare failed (update): " . $conn->error);
    if (!$upd->execute())
        throw new Exception("Update failed: " . $upd->error);
    $upd->close();

    // ---- 8) Record in bank history ----
    recordBankClaim($conn, $clientCode, $newBankCode, $newAccountNo, $newAccountName);

    // ---- 9) Reset lockout attempts on success (defense in depth) ----
    setSecretAttempts($conn, 'client', $clientCode, 0, null);

    $conn->commit();
    logActivity("[CLIENT_CHANGE_BANK] Transaction committed");
    logActivity("[CLIENT_CHANGE_BANK] ========== SUCCESS ==========");
    logActivity("[CLIENT_CHANGE_BANK] client={$clientCode}, new_bank={$newBankName} ({$newMasked})");

    json_success('Bank account updated successfully', [
        'bank_name' => $newBankName,
        'bank_code' => $newBankCode,
        'bank_account_name' => $newAccountName,
        'masked_account' => $newMasked,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[CLIENT_CHANGE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_CHANGE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}