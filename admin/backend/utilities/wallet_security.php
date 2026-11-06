<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

// ============================================================
// 1. SECRET ANSWER LOCKOUT
// ============================================================

/**
 * Resolve the secret-attempts table + code column for a user type.
 */
function _secretAttemptsTable(string $userType): array
{
    switch ($userType) {
        case 'tenant':
            return ['table' => 'tenant_secret_attempts', 'col' => 'tenant_code'];
        case 'admin':
            return ['table' => 'admin_secret_attempts', 'col' => 'unique_id'];
        case 'client':
        default:
            return ['table' => 'client_secret_attempts', 'col' => 'client_code'];
    }
}

/**
 * Resolve the login-attempts table + code column for a user type.
 */
function _loginAttemptsTable(string $userType): array
{
    switch ($userType) {
        case 'tenant':
            return ['table' => 'tenant_login_attempts', 'col' => 'tenant_code'];
        case 'admin':
            return ['table' => 'admin_login_attempts', 'col' => 'unique_id'];
        case 'client':
        default:
            return ['table' => 'client_login_attempts', 'col' => 'client_code'];
    }
}

/**
 * Resolve the lock-history table + code column for a user type.
 */
function _lockHistoryTable(string $userType): array
{
    switch ($userType) {
        case 'tenant':
            return ['table' => 'tenant_lock_history', 'col' => 'tenant_code'];
        case 'admin':
            return ['table' => 'admin_lock_history', 'col' => 'unique_id'];
        case 'client':
        default:
            return ['table' => 'client_lock_history', 'col' => 'client_code'];
    }
}

/**
 * Resolve the active-sessions table + code column for a user type.
 * Admin has no session-tracking table in the current schema.
 */
function _activeSessionsTable(string $userType): ?array
{
    switch ($userType) {
        case 'tenant':
            return ['table' => 'tenant_active_sessions', 'col' => 'tenant_code'];
        case 'client':
            return ['table' => 'client_active_sessions', 'col' => 'client_code'];
        case 'admin':
        default:
            return null;
    }
}

function getSecretAttempts(mysqli $conn, string $userType, string $userCode): array
{
    $cfg = _secretAttemptsTable($userType);

    $stmt = $conn->prepare("SELECT attempts, locked_until FROM {$cfg['table']} WHERE {$cfg['col']} = ? LIMIT 1");
    if (!$stmt)
        return ['attempts' => 0, 'locked_until' => null];
    $stmt->bind_param("s", $userCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: ['attempts' => 0, 'locked_until' => null];
}

function setSecretAttempts(
    mysqli $conn,
    string $userType,
    string $userCode,
    int $attempts,
    ?string $lockedUntil
): bool {
    $cfg = _secretAttemptsTable($userType);

    $stmt = $conn->prepare("
        INSERT INTO {$cfg['table']} ({$cfg['col']}, attempts, locked_until, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts),
                                locked_until = VALUES(locked_until),
                                updated_at = NOW()
    ");
    if (!$stmt)
        return false;
    $stmt->bind_param("sis", $userCode, $attempts, $lockedUntil);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function checkSecretLockout(mysqli $conn, string $userType, string $userCode): array
{
    $state = getSecretAttempts($conn, $userType, $userCode);
    $attempts = (int) ($state['attempts'] ?? 0);
    $lockedUntil = $state['locked_until'] ?? null;

    if ($attempts >= 3 && $lockedUntil) {
        $until = new DateTime($lockedUntil);
        $now = new DateTime();
        if ($now < $until) {
            $diff = $now->diff($until);
            $mins = $diff->i + ($diff->h * 60);
            $secs = $diff->s;
            return [
                'locked' => true,
                'locked_until' => $lockedUntil,
                'message' => "Too many failed attempts. Try again in {$mins} min {$secs} sec.",
            ];
        }
        setSecretAttempts($conn, $userType, $userCode, 0, null);
    }

    return ['locked' => false, 'message' => null, 'locked_until' => null];
}

function verifySecretAnswer(
    mysqli $conn,
    string $userType,
    string $userCode,
    string $stored,
    string $submitted
): array {
    $lockState = checkSecretLockout($conn, $userType, $userCode);
    if ($lockState['locked']) {
        return ['ok' => false, 'locked' => true, 'message' => $lockState['message']];
    }

    $submitted = strtolower(trim($submitted));
    $match = false;
    if (strlen($stored) >= 60 && strpos($stored, '$') === 0) {
        $match = password_verify($submitted, $stored);
    } else {
        $match = (strcasecmp($submitted, trim($stored)) === 0);
    }

    if ($match) {
        setSecretAttempts($conn, $userType, $userCode, 0, null);
        return ['ok' => true, 'locked' => false, 'message' => null];
    }

    $state = getSecretAttempts($conn, $userType, $userCode);
    $attempts = (int) ($state['attempts'] ?? 0) + 1;
    $lockedUntil = null;
    $isNowLocked = false;

    if ($attempts >= 3) {
        $lockedUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        $isNowLocked = true;

        try {
            $histCfg = _lockHistoryTable($userType);
            $hist = $conn->prepare("
                INSERT INTO {$histCfg['table']}
                    ({$histCfg['col']}, status, locked_by, lock_reason, lock_method, locked_at)
                VALUES (?, 'locked', 0, ?, 'Automatic lock (secret answer)', NOW())
            ");
            if ($hist) {
                $reason = "Locked after {$attempts} failed secret answer attempts";
                $hist->bind_param("ss", $userCode, $reason);
                $hist->execute();
                $hist->close();
            }
        } catch (Throwable $_) {}

        logActivity("Secret answer lockout triggered: {$userType}={$userCode} after {$attempts} attempts", 'WARN');
    }

    setSecretAttempts($conn, $userType, $userCode, $attempts, $lockedUntil);

    return [
        'ok' => false,
        'locked' => $isNowLocked,
        'message' => $isNowLocked
            ? "Incorrect answer. Your account has been temporarily locked for 15 minutes."
            : "Incorrect secret answer. " . (3 - $attempts) . " attempts remaining.",
    ];
}

// ============================================================
// 2. BANK ACCOUNT OWNERSHIP (client/tenant only)
// ============================================================

function checkBankAccountAvailability(
    mysqli $conn,
    string $bankCode,
    string $accountNumber,
    string $excludeClientCode
): array {
    $stmt = $conn->prepare("
        SELECT client_code FROM client_bank_history
        WHERE bank_code = ? AND account_number = ?
          AND client_code <> ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['available' => false, 'reason' => 'Unable to verify bank account uniqueness'];
    }
    $stmt->bind_param("sss", $bankCode, $accountNumber, $excludeClientCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        logActivity(
            "Bank reuse attempt: {$bankCode}/******" . substr($accountNumber, -4)
            . " claimed by {$row['client_code']}, attempted by {$excludeClientCode}",
            'WARN'
        );
        return ['available' => false, 'reason' => 'This bank account is already in use by another client'];
    }
    return ['available' => true, 'owned_by' => null, 'reason' => null];
}

function recordBankClaim(
    mysqli $conn,
    string $clientCode,
    string $bankCode,
    string $accountNumber,
    string $accountName
): bool {
    $markOld = $conn->prepare("
        UPDATE client_bank_history
        SET is_current = 0, removed_at = NOW()
        WHERE client_code = ? AND is_current = 1
    ");
    if ($markOld) {
        $markOld->bind_param("s", $clientCode);
        $markOld->execute();
        $markOld->close();
    }

    $ins = $conn->prepare("
        INSERT INTO client_bank_history
            (client_code, bank_code, account_number, account_name, is_current)
        VALUES (?, ?, ?, ?, 1)
    ");
    if (!$ins)
        return false;
    $ins->bind_param("ssss", $clientCode, $bankCode, $accountNumber, $accountName);
    $ok = $ins->execute();
    $ins->close();
    return $ok;
}

// ============================================================
// 3. WITHDRAWAL CONCURRENCY GUARD
// ============================================================

function countPendingWithdrawals(mysqli $conn, string $userType, string $userCode): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM wallet_requests
        WHERE user_type = ? AND user_code = ?
          AND request_type = 'withdrawal' AND status = 'pending'
    ");
    if (!$stmt)
        return PHP_INT_MAX;
    $stmt->bind_param("ss", $userType, $userCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['n'] ?? 0);
}

// ============================================================
// 4. PASSWORD VERIFICATION WITH LOCKOUT
// ============================================================

/**
 * Generic password verification with lockout for any user type.
 * Used by client, tenant, and admin flows.
 */
function verifyPasswordWithLockout(
    mysqli $conn,
    string $userType,
    string $userCode,
    string $submittedPassword,
    string $storedHash,
    ?string $currentSessionId = null
): array {
    $cfg = _loginAttemptsTable($userType);

    // ---- 1) Check current lockout ----
    $stmt = $conn->prepare("
        SELECT attempts, locked_until
        FROM {$cfg['table']}
        WHERE {$cfg['col']} = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['ok' => false, 'locked' => true, 'message' => 'Unable to verify at this time. Please try again later.'];
    }
    $stmt->bind_param("s", $userCode);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attempts = (int) ($state['attempts'] ?? 0);
    $lockedUntil = $state['locked_until'] ?? null;

    if ($attempts >= 3 && $lockedUntil) {
        $until = new DateTime($lockedUntil);
        $now = new DateTime();
        if ($now < $until) {
            $diff = $now->diff($until);
            $mins = $diff->i + ($diff->h * 60);
            $secs = $diff->s;
            return [
                'ok' => false,
                'locked' => true,
                'message' => "Account is temporarily locked due to failed attempts. Try again in {$mins}m {$secs}s.",
            ];
        }
        $reset = $conn->prepare("DELETE FROM {$cfg['table']} WHERE {$cfg['col']} = ?");
        if ($reset) {
            $reset->bind_param("s", $userCode);
            $reset->execute();
            $reset->close();
        }
        $attempts = 0;
    }

    // ---- 2) Verify password ----
    if (!password_verify($submittedPassword, $storedHash)) {
        $attempts++;
        $lockedUntil = null;
        $nowLocked = false;

        if ($attempts >= 3) {
            $lockedUntil = date('Y-m-d H:i:s', time() + 3600);
            $nowLocked = true;

            try {
                $histCfg = _lockHistoryTable($userType);
                $hist = $conn->prepare("
                    INSERT INTO {$histCfg['table']}
                        ({$histCfg['col']}, status, locked_by, lock_reason, lock_method, locked_at)
                    VALUES (?, 'locked', 0, ?, 'Automatic lock (password reauth)', NOW())
                ");
                if ($hist) {
                    $reason = "Account locked after {$attempts} failed password attempts";
                    $hist->bind_param("ss", $userCode, $reason);
                    $hist->execute();
                    $hist->close();
                }
            } catch (Throwable $_) {}

            // Kill active sessions (client/tenant only — admin has no session table)
            try {
                $sessCfg = _activeSessionsTable($userType);
                if ($sessCfg) {
                    if ($currentSessionId !== null) {
                        $kill = $conn->prepare("
                            UPDATE {$sessCfg['table']}
                            SET status = 'Inactive', logged_out_at = NOW()
                            WHERE {$sessCfg['col']} = ? AND status = 'Active' AND session_id <> ?
                        ");
                        if ($kill) {
                            $kill->bind_param("ss", $userCode, $currentSessionId);
                            $kill->execute();
                            $kill->close();
                        }
                    } else {
                        $kill = $conn->prepare("
                            UPDATE {$sessCfg['table']}
                            SET status = 'Inactive', logged_out_at = NOW()
                            WHERE {$sessCfg['col']} = ? AND status = 'Active'
                        ");
                        if ($kill) {
                            $kill->bind_param("s", $userCode);
                            $kill->execute();
                            $kill->close();
                        }
                    }
                }
            } catch (Throwable $_) {}

            logActivity("Password lockout triggered: {$userType}={$userCode} after {$attempts} attempts", 'WARN');
        }

        $up = $conn->prepare("
            INSERT INTO {$cfg['table']} ({$cfg['col']}, attempts, last_attempt, locked_until)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                attempts = VALUES(attempts),
                last_attempt = NOW(),
                locked_until = VALUES(locked_until)
        ");
        if ($up) {
            $up->bind_param("sis", $userCode, $attempts, $lockedUntil);
            $up->execute();
            $up->close();
        }

        return [
            'ok' => false,
            'locked' => $nowLocked,
            'message' => $nowLocked
                ? "Incorrect password. Account locked for 60 minutes."
                : "Incorrect password. " . (3 - $attempts) . " attempt(s) remaining.",
        ];
    }

    // ---- 3) Success — reset attempts ----
    $reset = $conn->prepare("DELETE FROM {$cfg['table']} WHERE {$cfg['col']} = ?");
    if ($reset) {
        $reset->bind_param("s", $userCode);
        $reset->execute();
        $reset->close();
    }

    return ['ok' => true, 'locked' => false, 'message' => null];
}

/**
 * Backwards-compatible wrappers — client and tenant flows use these by name.
 */
function verifyClientPasswordWithLockout(
    mysqli $conn,
    string $clientCode,
    string $submittedPassword,
    string $storedHash,
    ?string $currentSessionId = null
): array {
    return verifyPasswordWithLockout(
        $conn, 'client', $clientCode, $submittedPassword, $storedHash, $currentSessionId
    );
}

function verifyTenantPasswordWithLockout(
    mysqli $conn,
    string $tenantCode,
    string $submittedPassword,
    string $storedHash,
    ?string $currentSessionId = null
): array {
    return verifyPasswordWithLockout(
        $conn, 'tenant', $tenantCode, $submittedPassword, $storedHash, $currentSessionId
    );
}

/**
 * Admin-specific entry point — no session-kill (no admin_active_sessions table).
 */
function verifyAdminPasswordWithLockout(
    mysqli $conn,
    int $adminId,
    string $submittedPassword,
    string $storedHash
): array {
    return verifyPasswordWithLockout(
        $conn, 'admin', (string) $adminId, $submittedPassword, $storedHash, null
    );
}

/**
 * Pre-check password lockout without attempting verification.
 * Returns ['locked' => bool, 'message' => ?string, 'locked_until' => ?string].
 */
function checkPasswordLockout(mysqli $conn, string $userType, string $userCode): array
{
    $cfg = _loginAttemptsTable($userType);

    $stmt = $conn->prepare("
        SELECT attempts, locked_until FROM {$cfg['table']}
        WHERE {$cfg['col']} = ? LIMIT 1
    ");
    if (!$stmt) {
        return ['locked' => false, 'message' => null, 'locked_until' => null];
    }
    $stmt->bind_param("s", $userCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && (int)$row['attempts'] >= 3 && !empty($row['locked_until'])) {
        if (new DateTime() < new DateTime($row['locked_until'])) {
            $diff = (new DateTime())->diff(new DateTime($row['locked_until']));
            $mins = $diff->i + ($diff->h * 60);
            return [
                'locked'       => true,
                'message'      => "Account is temporarily locked. Try again in {$mins} minutes.",
                'locked_until' => $row['locked_until'],
            ];
        }
    }
    return ['locked' => false, 'message' => null, 'locked_until' => null];
}