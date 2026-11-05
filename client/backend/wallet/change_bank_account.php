<?php
// client/backend/wallet/change_bank_account.php
// Change a client's destination bank account.
//
// IMPORTANT: Secret-answer verification runs OUTSIDE the transaction.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_change_bank',
    'rate_limit' => [3, 600],
    'csrf'       => ['enabled' => true, 'form_name' => 'client_withdrawal_form'],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$newBankName    = trim($input['bank_name'] ?? '');
$newBankCode    = trim($input['bank_code'] ?? '');
$newAccountName = trim($input['account_name'] ?? '');
$newAccountNo   = trim($input['account_number'] ?? '');
$currentSecret  = trim($input['secret_answer'] ?? '');

logActivity("[CLIENT_CHANGE_BANK] ========== START ==========");
logActivity("[CLIENT_CHANGE_BANK] Client: {$clientCode}");

// ---- Validation ----
if ($newBankName === '')    json_error("Bank name is required", 400);
if ($newBankCode === '')    json_error("Bank code is required", 400);
if ($newAccountName === '') json_error("Account name is required", 400);
if (!preg_match('/^\d{10}$/', $newAccountNo)) json_error("Account number must be 10 digits", 400);
if ($currentSecret === '')  json_error("Current secret answer is required", 400);

// ============ OUTSIDE TRANSACTION: LOCKOUT + SECRET VERIFICATION ============

$lockState = checkSecretLockout($conn, 'client', $clientCode);
if ($lockState['locked']) {
    logActivity("[CLIENT_CHANGE_BANK] Blocked: account locked for {$clientCode}", 'WARN');
    json_error(
        $lockState['message'],
        423,
        null,
        'ACCOUNT_LOCKED',
        [
            'client_code'  => $clientCode,
            'locked_until' => $lockState['locked_until'],
            'source'       => 'secret_answer',
        ]
    );
}

$stmt = $conn->prepare("
    SELECT secret_answer, has_secret_set
    FROM clients WHERE client_code = ? AND status = 1 LIMIT 1
");
if (!$stmt) throw new Exception("Prepare failed (load client): " . $conn->error);
$stmt->bind_param("s", $clientCode);
if (!$stmt->execute()) throw new Exception("Load client failed: " . $stmt->error);
$clientSecret = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$clientSecret) json_error("Client not found", 404);
if ((int) $clientSecret['has_secret_set'] !== 1 || empty($clientSecret['secret_answer'])) {
    json_error("Please set up your secret question first", 400);
}

$verify = verifySecretAnswer($conn, 'client', $clientCode, $clientSecret['secret_answer'], $currentSecret);

if (!$verify['ok']) {
    $isLocked = !empty($verify['locked']);
    logActivity(
        "[CLIENT_CHANGE_BANK] Secret answer rejected for {$clientCode}: " . ($verify['message'] ?? ''),
        'WARN'
    );

    // Pull the real lockout expiry so the frontend countdown matches the
    // 15-minute secret-answer lock (not the 60-minute password default).
    $lockoutData = null;
    if ($isLocked) {
        $attemptState = getSecretAttempts($conn, 'client', $clientCode);
        $lockoutData = [
            'client_code'  => $clientCode,
            'locked_until' => $attemptState['locked_until'] ?? date('Y-m-d H:i:s', time() + 900),
            'source'       => 'secret_answer',
        ];
    }

    json_error(
        $verify['message'] ?? 'Incorrect secret answer',
        $isLocked ? 423 : 401,
        null,
        $isLocked ? 'ACCOUNT_LOCKED' : 'INVALID_SECRET_ANSWER',
        $lockoutData
    );
}
logActivity("[CLIENT_CHANGE_BANK] Secret answer verified for {$clientCode}");

// ============ INSIDE TRANSACTION ============

$conn->begin_transaction();
logActivity("[CLIENT_CHANGE_BANK] Transaction started");

try {
    $stmt = $conn->prepare("
        SELECT wallet_balance, bank_name, bank_code, bank_account_name, bank_account_number, bank_changed_at
        FROM clients WHERE client_code = ? FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (load client): " . $conn->error);
    $stmt->bind_param("s", $clientCode);
    if (!$stmt->execute()) throw new Exception("Load client failed: " . $stmt->error);
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client) throw new Exception("Client not found", 404);

    // No pending withdrawal
    $pending = countPendingWithdrawals($conn, 'client', $clientCode);
    if ($pending === PHP_INT_MAX) {
        throw new Exception("Unable to verify pending withdrawals — please try again", 500);
    }
    if ($pending > 0) {
        logActivity("[CLIENT_CHANGE_BANK] Blocked: pending withdrawal for {$clientCode}", 'WARN');
        throw new Exception("You have a pending withdrawal. Please wait for it to be processed before changing your bank.", 409);
    }

    // 24h cooldown
    if (!empty($client['bank_changed_at']) && strtotime($client['bank_changed_at']) > time() - 86400) {
        $nextAllowed = date('Y-m-d H:i', strtotime($client['bank_changed_at']) + 86400);
        throw new Exception("You can only change your bank once every 24 hours. Next allowed: {$nextAllowed}", 429);
    }

    // Same-bank check
    $isSameBank = (
        $client['bank_account_number'] === $newAccountNo
        && (string) $client['bank_code'] === (string) $newBankCode
    );
    if ($isSameBank) {
        logActivity("[CLIENT_CHANGE_BANK] No-op: same bank submitted for {$clientCode}", 'WARN');
        throw new Exception("This is already your current bank account. No change was made.", 400);
    }

    // Bank availability
    $bankAvail = checkBankAccountAvailability($conn, $newBankCode, $newAccountNo, $clientCode);
    if (!$bankAvail['available']) {
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

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
    if (!$upd) throw new Exception("Prepare failed (update): " . $conn->error);
    $upd->bind_param("sssss", $newBankName, $newBankCode, $newAccountName, $newAccountNo, $clientCode);
    if (!$upd->execute()) throw new Exception("Update failed: " . $upd->error);
    $upd->close();

    recordBankClaim($conn, $clientCode, $newBankCode, $newAccountNo, $newAccountName);
    setSecretAttempts($conn, 'client', $clientCode, 0, null);

    $conn->commit();
    logActivity("[CLIENT_CHANGE_BANK] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[CLIENT_CHANGE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_CHANGE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    exit;
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}

$newMasked = '******' . substr($newAccountNo, -4);
logActivity("[CLIENT_CHANGE_BANK] ========== SUCCESS ==========");
logActivity("[CLIENT_CHANGE_BANK] client={$clientCode}, new_bank={$newBankName} ({$newMasked})");

json_success('Bank account updated successfully', [
    'bank_name'         => $newBankName,
    'bank_code'         => $newBankCode,
    'bank_account_name' => $newAccountName,
    'masked_account'    => $newMasked,
]);