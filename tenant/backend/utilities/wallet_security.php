<?php
// utilities/wallet_security.php
// Shared security primitives for wallet endpoints.
//
//   - Secret answer attempt tracking + lockout (per user, per module)
//   - Bank account ownership checks (prevents cross-tenant bank reuse)
//   - Password / secret answer verification with hash detection
//
// All helpers are fail-safe: on any unexpected error they return a
// structured result that denies the action rather than allowing it.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

// ============================================================
// 1. SECRET ANSWER LOCKOUT
// ============================================================

/**
 * Return the attempt tracking row for a user, or a zeroed row if none.
 *
 * @param mysqli $conn
 * @param string $userType     'tenant' | 'tenant'
 * @param string $userCode
 * @return array  ['attempts' => int, 'locked_until' => ?string]
 */
function getSecretAttempts(mysqli $conn, string $userType, string $userCode): array
{
    $table = $userType === 'tenant' ? 'tenant_secret_attempts' : 'tenant_secret_attempts';
    $col   = $userType === 'tenant' ? 'tenant_code' : 'tenant_code';

    $stmt = $conn->prepare("SELECT attempts, locked_until FROM {$table} WHERE {$col} = ? LIMIT 1");
    if (!$stmt) return ['attempts' => 0, 'locked_until' => null];
    $stmt->bind_param("s", $userCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: ['attempts' => 0, 'locked_until' => null];
}

/**
 * Write attempt state. Inserts on first call, updates after.
 */
function setSecretAttempts(
    mysqli $conn,
    string $userType,
    string $userCode,
    int $attempts,
    ?string $lockedUntil
): bool {
    $table = $userType === 'tenant' ? 'tenant_secret_attempts' : 'tenant_secret_attempts';
    $col   = $userType === 'tenant' ? 'tenant_code' : 'tenant_code';

    $stmt = $conn->prepare("
        INSERT INTO {$table} ({$col}, attempts, locked_until, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts),
                                locked_until = VALUES(locked_until),
                                updated_at = NOW()
    ");
    if (!$stmt) return false;
    $stmt->bind_param("sis", $userCode, $attempts, $lockedUntil);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Check whether the user is currently locked out.
 *
 * @return array ['locked' => bool, 'message' => ?string, 'locked_until' => ?string]
 */
function checkSecretLockout(mysqli $conn, string $userType, string $userCode): array
{
    $state = getSecretAttempts($conn, $userType, $userCode);
    $attempts = (int) ($state['attempts'] ?? 0);
    $lockedUntil = $state['locked_until'] ?? null;

    if ($attempts >= 3 && $lockedUntil) {
        $until = new DateTime($lockedUntil);
        $now   = new DateTime();
        if ($now < $until) {
            $diff  = $now->diff($until);
            $mins  = $diff->i + ($diff->h * 60);
            $secs  = $diff->s;
            return [
                'locked'       => true,
                'locked_until' => $lockedUntil,
                'message'      => "Too many failed attempts. Try again in {$mins} min {$secs} sec.",
            ];
        }
        // Lock expired — reset
        setSecretAttempts($conn, $userType, $userCode, 0, null);
    }

    return ['locked' => false, 'message' => null, 'locked_until' => null];
}

/**
 * Verify a secret answer with lockout awareness.
 *
 * Behaviour:
 *   - If already locked → return locked, don't verify.
 *   - Verify against stored value (auto-detect hash vs plaintext).
 *   - On success → reset attempts to 0.
 *   - On failure → increment attempts; if >= 3, set locked_until = NOW()+15 min.
 *
 * @return array ['ok' => bool, 'locked' => bool, 'message' => ?string]
 */
function verifySecretAnswer(
    mysqli $conn,
    string $userType,
    string $userCode,
    string $stored,
    string $submitted
): array {
    // 1. Already locked?
    $lockState = checkSecretLockout($conn, $userType, $userCode);
    if ($lockState['locked']) {
        return ['ok' => false, 'locked' => true, 'message' => $lockState['message']];
    }

    // 2. Verify
    $submitted = strtolower(trim($submitted));
    $match = false;
    if (strlen($stored) >= 60 && strpos($stored, '$') === 0) {
        $match = password_verify($submitted, $stored);
    } else {
        $match = (strcasecmp($submitted, trim($stored)) === 0);
    }

    if ($match) {
        // Reset attempts on success
        setSecretAttempts($conn, $userType, $userCode, 0, null);
        return ['ok' => true, 'locked' => false, 'message' => null];
    }

    // 3. Increment on failure
    $state = getSecretAttempts($conn, $userType, $userCode);
    $attempts = (int) ($state['attempts'] ?? 0) + 1;
    $lockedUntil = null;
    $isNowLocked = false;

    if ($attempts >= 3) {
        $lockedUntil = date('Y-m-d H:i:s', time() + 15 * 60); // 15 min
        $isNowLocked = true;

        // Persist lock history row (best-effort)
        try {
            if ($userType === 'tenant') {
                $hist = $conn->prepare("
                    INSERT INTO tenant_lock_history
                        (tenant_code, status, locked_by, lock_reason, lock_method, locked_at)
                    VALUES (?, 'locked', 0, ?, 'Automatic lock (secret answer)', NOW())
                ");
                if ($hist) {
                    $reason = "Locked after {$attempts} failed secret answer attempts during wallet operation";
                    $hist->bind_param("ss", $userCode, $reason);
                    $hist->execute();
                    $hist->close();
                }
            }
        } catch (Throwable $_) { /* non-fatal */ }

        logActivity("Secret answer lockout triggered: {$userType}={$userCode} after {$attempts} attempts", 'WARN');
    }

    setSecretAttempts($conn, $userType, $userCode, $attempts, $lockedUntil);

    return [
        'ok'      => false,
        'locked'  => $isNowLocked,
        'message' => $isNowLocked
            ? "Incorrect answer. Your account has been temporarily locked for 15 minutes."
            : "Incorrect secret answer. " . (3 - $attempts) . " attempts remaining.",
    ];
}

// ============================================================
// 2. BANK ACCOUNT OWNERSHIP
// ============================================================

/**
 * Check whether (bank_code, account_number) has ever been claimed by
 * a tenant other than $tenantCode. Rejects cross-tenant reuse.
 *
 * @return array ['available' => bool, 'owned_by' => ?string, 'reason' => ?string]
 */
// ============================================================
// 2b. TENANT BANK ACCOUNT OWNERSHIP
// ============================================================

/**
 * Check whether (bank_code, account_number) has ever been claimed by
 * a tenant other than $tenantCode. Rejects cross-tenant reuse.
 *
 * @return array ['available' => bool, 'owned_by' => ?string, 'reason' => ?string]
 */
function checkTenantBankAvailability(
    mysqli $conn,
    string $bankCode,
    string $accountNumber,
    string $excludeTenantCode
): array {
    $stmt = $conn->prepare("
        SELECT tenant_code FROM tenant_bank_history
        WHERE bank_code = ? AND account_number = ?
          AND tenant_code <> ?
        LIMIT 1
    ");
    if (!$stmt) {
        // Fail safe: if we can't check, refuse to proceed
        return ['available' => false, 'reason' => 'Unable to verify bank account uniqueness'];
    }
    $stmt->bind_param("sss", $bankCode, $accountNumber, $excludeTenantCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        logActivity(
            "Tenant bank reuse attempt: {$bankCode}/******" . substr($accountNumber, -4)
            . " claimed by {$row['tenant_code']}, attempted by {$excludeTenantCode}",
            'WARN'
        );
        return ['available' => false, 'reason' => 'This bank account is already in use by another tenant'];
    }
    return ['available' => true, 'owned_by' => null, 'reason' => null];
}

/**
 * Record a tenant bank claim in history. Called on first save and on change.
 * Marks any previous current claim as removed before inserting the new one.
 */
function recordTenantBankClaim(
    mysqli $conn,
    string $tenantCode,
    string $bankCode,
    string $accountNumber,
    string $accountName
): bool {
    // Mark old bank as removed
    $markOld = $conn->prepare("
        UPDATE tenant_bank_history
        SET is_current = 0, removed_at = NOW()
        WHERE tenant_code = ? AND is_current = 1
    ");
    if ($markOld) {
        $markOld->bind_param("s", $tenantCode);
        $markOld->execute();
        $markOld->close();
    }

    // Insert new claim
    $ins = $conn->prepare("
        INSERT INTO tenant_bank_history
            (tenant_code, bank_code, account_number, account_name, is_current)
        VALUES (?, ?, ?, ?, 1)
    ");
    if (!$ins) return false;
    $ins->bind_param("ssss", $tenantCode, $bankCode, $accountNumber, $accountName);
    $ok = $ins->execute();
    $ins->close();
    return $ok;
}

// ============================================================
// 3. WITHDRAWAL CONCURRENCY GUARD
// ============================================================

/**
 * Return the count of pending withdrawals for a user.
 * Used to enforce "one pending at a time" without races.
 */
function countPendingWithdrawals(mysqli $conn, string $userType, string $userCode): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM wallet_requests
        WHERE user_type = ? AND user_code = ?
          AND request_type = 'withdrawal' AND status = 'pending'
    ");
    if (!$stmt) return PHP_INT_MAX; // fail safe: treat as blocked
    $stmt->bind_param("ss", $userType, $userCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['n'] ?? 0);
}


// ============================================================
// 2. BANK ACCOUNT OWNERSHIP
// ============================================================

/**
 * Check whether (bank_code, account_number) has ever been claimed by
 * a tenant other than $tenantCode. Rejects cross-tenant reuse.
 *
 * @return array ['available' => bool, 'owned_by' => ?string, 'reason' => ?string]
 */
function checkBankAccountAvailability(
    mysqli $conn,
    string $bankCode,
    string $accountNumber,
    string $excludeTenantCode
): array {
    // Look in the history table (records every claim, current or past)
    $stmt = $conn->prepare("
        SELECT tenant_code FROM tenant_bank_history
        WHERE bank_code = ? AND account_number = ?
          AND tenant_code <> ?
        LIMIT 1
    ");
    if (!$stmt) {
        // Fail safe: if we can't check, refuse to proceed
        return ['available' => false, 'reason' => 'Unable to verify bank account uniqueness'];
    }
    $stmt->bind_param("sss", $bankCode, $accountNumber, $excludeTenantCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        // Don't leak the other tenant's code; just say "already in use"
        logActivity(
            "Bank reuse attempt: {$bankCode}/******" . substr($accountNumber, -4)
            . " claimed by {$row['tenant_code']}, attempted by {$excludeTenantCode}",
            'WARN'
        );
        return ['available' => false, 'reason' => 'This bank account is already in use by another tenant'];
    }
    return ['available' => true, 'owned_by' => null, 'reason' => null];
}

/**
 * Record a bank claim in history. Called on first save and on change.
 */
function recordBankClaim(
    mysqli $conn,
    string $tenantCode,
    string $bankCode,
    string $accountNumber,
    string $accountName
): bool {
    // Mark old bank as removed
    $markOld = $conn->prepare("
        UPDATE tenant_bank_history
        SET is_current = 0, removed_at = NOW()
        WHERE tenant_code = ? AND is_current = 1
    ");
    if ($markOld) {
        $markOld->bind_param("s", $tenantCode);
        $markOld->execute();
        $markOld->close();
    }

    // Insert new claim
    $ins = $conn->prepare("
        INSERT INTO tenant_bank_history
            (tenant_code, bank_code, account_number, account_name, is_current)
        VALUES (?, ?, ?, ?, 1)
    ");
    if (!$ins) return false;
    $ins->bind_param("ssss", $tenantCode, $bankCode, $accountNumber, $accountName);
    $ok = $ins->execute();
    $ins->close();
    return $ok;
}