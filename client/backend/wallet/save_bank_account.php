<?php
// client/backend/wallet/save_bank_account.php
// Save a client's first bank account. Refuses if one already exists —
// changing an existing bank is a separate, harder flow (change_bank_account.php).
//
// IMPORTANT: Secret-answer verification runs OUTSIDE the transaction.
// The attempt counter must persist even when the request fails — otherwise
// rollback undoes the counter and lockout never engages.

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
    'rate_key'   => 'client_setup_bank',
    'rate_limit' => [5, 600],
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

// ============ OUTSIDE TRANSACTION: LOCKOUT + SECRET VERIFICATION ============

// 1) Lockout check first
$lockState = checkSecretLockout($conn, 'client', $clientCode);
if ($lockState['locked']) {
    logActivity("[CLIENT_SAVE_BANK] Blocked: account locked for {$clientCode}", 'WARN');
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

// 2) Load secret-answer state (lightweight)
$stmt = $conn->prepare("
    SELECT secret_answer, has_secret_set, bank_account_number
    FROM clients
    WHERE client_code = ? AND status = 1
    LIMIT 1
");
if (!$stmt) throw new Exception("Prepare failed (load client): " . $conn->error);
$stmt->bind_param("s", $clientCode);
if (!$stmt->execute()) throw new Exception("Load client failed: " . $stmt->error);
$clientSecret = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$clientSecret) json_error("Client not found", 404);

// 3) Refuse if bank already exists (cheap, no secret answer consumed)
if (!empty($clientSecret['bank_account_number'])) {
    logActivity("[CLIENT_SAVE_BANK] Refused: bank already on file for {$clientCode}", 'WARN');
    json_error("A bank account is already on file. Please use the change-bank flow to update it.", 409);
}

// 4) Secret verification (autocommits — counter persists on failure)
if ((int)$clientSecret['has_secret_set'] !== 1 || empty($clientSecret['secret_answer'])) {
    json_error("Please set up your secret question first", 400);
}

$verify = verifySecretAnswer($conn, 'client', $clientCode, $clientSecret['secret_answer'], $secretAns);

if (!$verify['ok']) {
    $isLocked = !empty($verify['locked']);
    logActivity(
        "[CLIENT_SAVE_BANK] Secret answer rejected for {$clientCode}: " . ($verify['message'] ?? ''),
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
logActivity("[CLIENT_SAVE_BANK] Secret answer verified for {$clientCode}");

// ============ INSIDE TRANSACTION: BUSINESS LOGIC ============

$conn->begin_transaction();
logActivity("[CLIENT_SAVE_BANK] Transaction started");

try {
    // Lock client row + re-verify no bank exists (concurrent-safety)
    $stmt = $conn->prepare("
        SELECT bank_account_number FROM clients WHERE client_code = ? FOR UPDATE
    ");
    if (!$stmt) throw new Exception("Prepare failed (lock client): " . $conn->error);
    $stmt->bind_param("s", $clientCode);
    if (!$stmt->execute()) throw new Exception("Lock client failed: " . $stmt->error);
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client) throw new Exception("Client not found", 404);

    if (!empty($client['bank_account_number'])) {
        logActivity("[CLIENT_SAVE_BANK] Concurrent: bank appeared for {$clientCode}", 'WARN');
        throw new Exception("A bank account is already on file. Please use the change-bank flow to update it.", 409);
    }

    // Bank uniqueness
    $bankAvail = checkBankAccountAvailability($conn, $bankCode, $accountNo, $clientCode);
    if (!$bankAvail['available']) {
        logActivity("[CLIENT_SAVE_BANK] Bank reuse refused for {$clientCode}: " . ($bankAvail['reason'] ?? ''), 'WARN');
        throw new Exception($bankAvail['reason'] ?? 'This bank account is not available', 409);
    }

    // Save bank details
    $save = $conn->prepare("
        UPDATE clients
        SET bank_name = ?, bank_code = ?, bank_account_name = ?, bank_account_number = ?,
            bank_changed_at = NOW()
        WHERE client_code = ?
    ");
    if (!$save) throw new Exception("Prepare failed (save): " . $conn->error);
    $save->bind_param("sssss", $bankName, $bankCode, $accountName, $accountNo, $clientCode);
    if (!$save->execute()) throw new Exception("Failed to save bank details: " . $save->error);
    $save->close();

    // Record claim
    recordBankClaim($conn, $clientCode, $bankCode, $accountNo, $accountName);

    // Reset lockout (safe — verification already passed, counter outside transaction)
    setSecretAttempts($conn, 'client', $clientCode, 0, null);

    $conn->commit();
    logActivity("[CLIENT_SAVE_BANK] Transaction committed");

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[CLIENT_SAVE_BANK] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_SAVE_BANK] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    exit;
} finally {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        $conn->close();
    }
}

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