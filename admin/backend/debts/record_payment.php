<?php
// admin/backend/debts/record_payment.php
// Admin records a payment toward a tenant debt

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
    'method'     => 'POST',
    'rate_key'   => 'admin_debt_payment',
    'rate_limit' => [20, 60],
    'csrf'       => ['enabled' => true, 'form_name' => 'admin_debt_form'],
    'roles'      => ['Super Admin', 'Admin'],
]);

$adminId = (int) $auth['user_id'];

// Read JSON body first, expose to CSRF guard
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}
if (!is_array($input)) {
    json_error("Invalid input", 400);
}
$GLOBALS['__REQUEST_BODY'] = $input;

$debtCode = trim($input['debt_code'] ?? '');
$amount   = round((float) str_replace(',', '', $input['amount'] ?? '0'), 2);
$method   = $input['payment_method'] ?? 'bank_transfer';
$refNum   = trim($input['reference_number'] ?? '');
$notes    = trim($input['notes'] ?? '');

// --- Validation ---
if ($debtCode === '') json_error("Debt code is required", 400);
if ($amount <= 0) json_error("Amount must be greater than zero", 400);

$allowedMethods = ['bank_transfer','cash','cheque','card','wallet_offset','other'];
if (!in_array($method, $allowedMethods, true)) {
    json_error("Invalid payment method", 400);
}

// --- Reference validation ---
$refCheck = validatePaymentReference($refNum, $method);

if (!$refCheck['valid']) {
    // If the admin didn't provide one, generate a server-side reference
    if ($refNum === '') {
        $refNum = generatePaymentReference($method);   // fallback server-side gen
    } else {
        json_error("Invalid reference: " . $refCheck['message'], 400);
    }
} else {
    $refNum = $refCheck['normalized'];   // uppercase, trimmed
}

// --- Duplicate check (defense in depth — DB UNIQUE is the ultimate guard) ---
$dupStmt = $conn->prepare("
    SELECT id FROM tenant_debt_payments
    WHERE reference_number = ?
    LIMIT 1
");
$dupStmt->bind_param("s", $refNum);
$dupStmt->execute();
$dupExists = $dupStmt->get_result()->num_rows > 0;
$dupStmt->close();

if ($dupExists) {
    json_error("A payment with this reference already exists", 409);
}

$conn->begin_transaction();

try {
    // Lock debt + tenant wallet
    $stmt = $conn->prepare("
        SELECT td.*, t.wallet_balance AS tenant_wallet
        FROM tenant_debts td
        JOIN tenants t ON t.tenant_code = td.tenant_code
        WHERE td.debt_code = ?
          AND td.status IN ('unpaid','partial')
        FOR UPDATE
    ");
    $stmt->bind_param("s", $debtCode);
    $stmt->execute();
    $debt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$debt) throw new Exception("Debt not found or already resolved", 404);

    $currentBalance = (float) $debt['balance'];
    if ($amount > $currentBalance) {
        throw new Exception(
            "Amount (₦" . number_format($amount, 2) . ") exceeds remaining balance (₦"
            . number_format($currentBalance, 2) . ")",
            400
        );
    }

    $newPaid    = round((float) $debt['amount_paid'] + $amount, 2);
    $newBalance = round((float) $debt['original_amount'] - $newPaid, 2);
    $newStatus  = $newBalance <= 0 ? 'resolved' : 'partial';

    // 1. Update debt
    if ($newStatus === 'resolved') {
        $update = $conn->prepare("
            UPDATE tenant_debts
            SET amount_paid = ?, balance = ?, status = 'resolved',
                resolved_at = NOW(), resolved_by = ?
            WHERE debt_code = ?
        ");
        $update->bind_param("ddis", $newPaid, $newBalance, $adminId, $debtCode);
    } else {
        $update = $conn->prepare("
            UPDATE tenant_debts
            SET amount_paid = ?, balance = ?, status = 'partial'
            WHERE debt_code = ?
        ");
        $update->bind_param("dds", $newPaid, $newBalance, $debtCode);
    }
    if (!$update->execute()) throw new Exception("Failed to update debt: " . $update->error);
    $update->close();

    // 2. Log the payment
    $logPayment = $conn->prepare("
        INSERT INTO tenant_debt_payments
            (debt_id, debt_code, tenant_code, amount, payment_method,
             reference_number, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $logPayment->bind_param(
        "issdsssi",
        $debt['id'],
        $debtCode,
        $debt['tenant_code'],
        $amount,
        $method,
        $refNum,
        $notes,
        $adminId
    );
    if (!$logPayment->execute()) throw new Exception("Failed to log payment: " . $logPayment->error);
    $logPayment->close();

    // 3. Credit the client wallet (they absorbed the original loss)
    $clientCredited = false;
    if (!empty($debt['client_code'])) {
        $lockClient = $conn->prepare("SELECT wallet_balance FROM clients WHERE client_code = ? FOR UPDATE");
        $lockClient->bind_param("s", $debt['client_code']);
        $lockClient->execute();
        $clientRow = $lockClient->get_result()->fetch_assoc();
        $lockClient->close();

        if ($clientRow) {
            $clientNew = round((float) $clientRow['wallet_balance'] + $amount, 2);

            $creditClient = $conn->prepare("
                UPDATE clients SET wallet_balance = wallet_balance + ?
                WHERE client_code = ?
            ");
            $creditClient->bind_param("ds", $amount, $debt['client_code']);
            $creditClient->execute();
            $creditClient->close();

            $clientLedger = $conn->prepare("
                INSERT INTO client_wallet_transactions
                    (client_code, transaction_type, amount, running_balance,
                     request_id, notes, created_by)
                VALUES (?, 'debt_recovery', ?, ?, ?, ?, ?)
            ");
            $clientNote = "Debt recovery from tenant {$debt['tenant_code']} (debt {$debtCode})";
            $clientLedger->bind_param(
                "sddssi",
                $debt['client_code'],
                $amount,
                $clientNew,
                $debt['source_id'],
                $clientNote,
                $adminId
            );
            $clientLedger->execute();
            $clientLedger->close();

            $clientCredited = true;
            logActivity("Debt payment: Client {$debt['client_code']} credited ₦{$amount}");
        }
    }

    // 4. Notify tenant
    try {
        $msg = "Payment of ₦" . number_format($amount, 2) . " received for debt {$debtCode}. ";
        $msg .= $newStatus === 'resolved'
            ? "Your debt has been fully settled. Thank you."
            : "Remaining balance: ₦" . number_format($newBalance, 2) . ".";

        createNotification(
            $conn,
            $debt['tenant_code'],
            'wallet',
            'Debt Payment Recorded',
            $msg,
            ['debt_code' => $debtCode, 'amount' => $amount, 'balance' => $newBalance],
            'normal',
            '../wallet.php',
            'View Wallet'
        );
    } catch (Throwable $notifErr) {
        logActivity("Debt payment notification failed: " . $notifErr->getMessage(), 'WARN');
    }

    $conn->commit();

    json_success('Payment recorded successfully',[
        'debt_code'         => $debtCode,
        'amount'            => $amount,
        'amount_formatted'  => '₦' . number_format($amount, 2),
        'amount_paid'       => $newPaid,
        'balance'           => $newBalance,
        'balance_formatted' => '₦' . number_format($newBalance, 2),
        'status'            => $newStatus,
        'client_credited'   => $clientCredited,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    logActivity("Record debt payment error: " . $e->getMessage(), 'ERROR');
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    json_error($e->getMessage(), $status);
}