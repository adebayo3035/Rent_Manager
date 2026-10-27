<?php
// client/backend/wallet/request_withdrawal.php
// Client (landlord) requests withdrawal to their saved bank account.
//
// SECURITY: The beneficiary is looked up from the client's saved bank
// record. The client cannot redirect to another account. Balance is
// debited immediately (reserve-then-approve model).
//
// SECURITY: Secret answer verified with lockout (3 fails → 15 min lock).
// The lockout state is SHARED across all wallet endpoints — a client who
// fails 3 times on save-bank cannot then succeed on withdraw.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../utilities/config.php';
require_once __DIR__ . '/../utilities/auth_utils.php';
require_once __DIR__ . '/../utilities/utils.php';
require_once __DIR__ . '/../utilities/auth_guard.php';
require_once __DIR__ . '/../utilities/notification_helper.php';
require_once __DIR__ . '/../utilities/rate_limit.php';
require_once __DIR__ . '/../utilities/wallet_security.php';

if (session_status() === PHP_SESSION_NONE) session_start();
rateLimiter();

$auth = requireAuth([
    'method'     => 'POST',
    'rate_key'   => 'client_withdrawal',
    'rate_limit' => [3, 600],
    'csrf'       => ['enabled' => true, 'form_name' => 'client_withdrawal_form'],
    'roles'      => ['Client'],
]);

$clientCode = $_SESSION['client_code'] ?? null;
if (!$clientCode) json_error("Session expired", 401);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$amountRaw     = trim($input['amount'] ?? '');
$accountNumber = trim($input['account_number'] ?? '');
$bankCode      = trim($input['bank_code'] ?? '');
$secretAns     = trim($input['secret_answer'] ?? '');

logActivity("[CLIENT_WITHDRAW] ========== START ==========");
logActivity("[CLIENT_WITHDRAW] Client: {$clientCode}");

// ---- Input validation ----
$amount = round((float) str_replace(',', '', $amountRaw), 2);
if ($amount <= 0)       json_error("Enter a valid amount", 400);
if ($amount < 1000)     json_error("Minimum withdrawal is ₦1,000", 400);
if (!preg_match('/^\d{10}$/', $accountNumber)) json_error("Account number must be 10 digits", 400);
if ($bankCode === '')   json_error("Bank is required", 400);
if ($secretAns === '')  json_error("Secret answer is required", 400);

$conn->begin_transaction();
logActivity("[CLIENT_WITHDRAW] Transaction started");

try {
    // ---- 1) Lock client row ----
    $stmt = $conn->prepare("
        SELECT wallet_balance, secret_answer, has_secret_set,
               bank_name, bank_account_name, bank_account_number, bank_code
        FROM clients WHERE client_code = ? FOR UPDATE
    ");
    $stmt->bind_param("s", $clientCode);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) throw new Exception("Client not found", 404);

    // ---- 2) Bank must match saved account ----
    if (empty($client['bank_account_number'])) {
        throw new Exception("No bank account on file. Please add one first.", 400);
    }
    if ($client['bank_account_number'] !== $accountNumber) {
        logActivity("[CLIENT_WITHDRAW] Account mismatch for {$clientCode}: submitted ******"
            . substr($accountNumber, -4) . ", on file ******"
            . substr($client['bank_account_number'], -4), 'WARN');
        throw new Exception("Account number does not match the account on file.", 400);
    }
    if ((string)$client['bank_code'] !== (string)$bankCode) {
        throw new Exception("Bank does not match the account on file.", 400);
    }

    // ---- 3) Lockout check FIRST (before verifying) ----
    $lockState = checkSecretLockout($conn, 'client', $clientCode);
    if ($lockState['locked']) {
        logActivity("[CLIENT_WITHDRAW] Blocked: account locked for {$clientCode}", 'WARN');
        throw new Exception($lockState['message'], 429);
    }

    // ---- 4) Secret verification with lockout ----
    if ((int)$client['has_secret_set'] !== 1 || empty($client['secret_answer'])) {
        throw new Exception("Please set up your secret question first", 400);
    }

    $verify = verifySecretAnswer($conn, 'client', $clientCode, $client['secret_answer'], $secretAns);
    if (!$verify['ok']) {
        logActivity("[CLIENT_WITHDRAW] Secret answer failed for {$clientCode}: "
            . ($verify['message'] ?? ''), 'WARN');
        throw new Exception(
            $verify['message'] ?? 'Incorrect secret answer',
            $verify['locked'] ? 429 : 401
        );
    }

    // ---- 5) Balance check ----
    $oldBalance = round((float) $client['wallet_balance'], 2);
    if ($amount > $oldBalance) {
        logActivity("[CLIENT_WITHDRAW] Insufficient balance: requested ₦" . number_format($amount, 2)
            . ", available ₦" . number_format($oldBalance, 2), 'WARN');
        throw new Exception(
            "Insufficient wallet balance. Available: ₦" . number_format($oldBalance, 2),
            400
        );
    }

    // ---- 6) One pending withdrawal at a time ----
    if (countPendingWithdrawals($conn, 'client', $clientCode) > 0) {
        logActivity("[CLIENT_WITHDRAW] Blocked: pending withdrawal for {$clientCode}", 'WARN');
        throw new Exception("You already have a pending withdrawal request", 409);
    }

    // ---- 7) Reserve funds ----
    $newBalance  = round($oldBalance - $amount, 2);
    $requestCode = 'CWD_' . strtoupper(bin2hex(random_bytes(6)));
    $masked      = '******' . substr($accountNumber, -4);
    $note        = "Withdrawal request to {$client['bank_name']} ({$masked}) — pending admin approval";

    $debit = $conn->prepare("UPDATE clients SET wallet_balance = wallet_balance - ? WHERE client_code = ?");
    $debit->bind_param("ds", $amount, $clientCode);
    if (!$debit->execute()) throw new Exception("Failed to reserve funds: " . $debit->error);
    $debit->close();

    logActivity("[CLIENT_WITHDRAW] Wallet debited: {$clientCode} ₦" . number_format($oldBalance, 2)
        . " → ₦" . number_format($newBalance, 2));

    // ---- 8) Ledger row (Pending) ----
    $ledger = $conn->prepare("
        INSERT INTO client_wallet_transactions
            (client_code, transaction_type, amount, running_balance,
             request_id, status, notes, created_by)
        VALUES (?, 'withdrawal', ?, ?, ?, 'Pending', ?, NULL)
    ");
    if (!$ledger) throw new Exception("Prepare failed (ledger): " . $conn->error);
    $debitAmount = -$amount;
    $ledger->bind_param("sddss", $clientCode, $debitAmount, $newBalance, $requestCode, $note);
    if (!$ledger->execute()) throw new Exception("Ledger insert failed: " . $ledger->error);
    $ledgerId = $ledger->insert_id;
    $ledger->close();
    logActivity("[CLIENT_WITHDRAW] Ledger inserted: id={$ledgerId}, status=Pending, "
        . "request_id={$requestCode}, running_balance=₦" . number_format($newBalance, 2));

    // ---- 9) wallet_requests row ----
    $req = $conn->prepare("
        INSERT INTO wallet_requests
            (request_code, user_type, user_code, request_type, amount,
             bank_name, bank_account_name, bank_account_number,
             status, created_at)
        VALUES (?, 'client', ?, 'withdrawal', ?, ?, ?, ?, 'pending', NOW())
    ");
    if (!$req) throw new Exception("Prepare failed (request): " . $conn->error);
    $req->bind_param(
        "ssdsss",
        $requestCode, $clientCode, $amount,
        $client['bank_name'], $client['bank_account_name'], $accountNumber
    );
    if (!$req->execute()) throw new Exception("Request insert failed: " . $req->error);
    $req->close();
    logActivity("[CLIENT_WITHDRAW] wallet_requests row inserted: request_code={$requestCode}");

    // ---- 10) Reset lockout on success ----
    // (verifySecretAnswer already resets on success, but explicit is safer
    //  in case the caller ever refactors to non-resetting mode.)
    setSecretAttempts($conn, 'client', $clientCode, 0, null);

    // ---- 11) Notify admins (best-effort) ----
    try {
        $admins = $conn->query("SELECT unique_id FROM admin_tbl WHERE status = 1 LIMIT 10");
        while ($adm = $admins->fetch_assoc()) {
            createNotification(
                $conn, $adm['unique_id'], 'wallet',
                'New Client Withdrawal Request',
                "Client {$clientCode} requested a withdrawal of ₦" . number_format($amount, 2)
                . " to {$client['bank_name']} ({$masked}).",
                ['request_code' => $requestCode, 'amount' => $amount],
                'high', '../wallet_requests.php', 'Review Request'
            );
        }
    } catch (Throwable $notifErr) {
        logActivity("[CLIENT_WITHDRAW] Admin notification failed (non-fatal): " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();
    logActivity("[CLIENT_WITHDRAW] Transaction committed");
    logActivity("[CLIENT_WITHDRAW] ========== SUCCESS ==========");
    logActivity("[CLIENT_WITHDRAW] request_code={$requestCode}, client={$clientCode}, "
        . "amount=₦" . number_format($amount, 2));

    json_success('Withdrawal request submitted. Admin will review and process shortly.', [
        'request_code'       => $requestCode,
        'amount'             => $amount,
        'amount_formatted'   => '₦' . number_format($amount, 2),
        'new_wallet_balance' => $newBalance,
        'status'             => 'pending',
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("[CLIENT_WITHDRAW] ROLLBACK: " . $e->getMessage(), 'ERROR');
    logActivity("[CLIENT_WITHDRAW] ========== FAILED ==========");
    json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
}