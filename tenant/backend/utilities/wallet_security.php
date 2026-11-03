<?php
// tenant/backend/utilities/wallet_security.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

// ============================================================
// 1. SECRET ANSWER LOCKOUT
// ============================================================

function getSecretAttempts(mysqli $conn, string $userType, string $userCode): array
{
    $table = 'tenant_secret_attempts';
    $col   = 'tenant_code';

    $stmt = $conn->prepare("SELECT attempts, locked_until FROM {$table} WHERE {$col} = ? LIMIT 1");
    if (!$stmt) return ['attempts' => 0, 'locked_until' => null];
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
    $table = 'tenant_secret_attempts';
    $col   = 'tenant_code';

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

function checkSecretLockout(mysqli $conn, string $userType, string $userCode): array
{
    $state = getSecretAttempts($conn, $userType, $userCode);
    $attempts    = (int) ($state['attempts'] ?? 0);
    $lockedUntil = $state['locked_until'] ?? null;

    if ($attempts >= 3 && $lockedUntil) {
        $until = new DateTime($lockedUntil);
        $now   = new DateTime();
        if ($now < $until) {
            $diff = $now->diff($until);
            $mins = $diff->i + ($diff->h * 60);
            $secs = $diff->s;
            return [
                'locked'       => true,
                'locked_until' => $lockedUntil,
                'message'      => "Too many failed attempts. Try again in {$mins} min {$secs} sec.",
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
        } catch (Throwable $_) {}

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

function checkBankAccountAvailability(
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
        return ['available' => false, 'reason' => 'Unable to verify bank account uniqueness'];
    }
    $stmt->bind_param("sss", $bankCode, $accountNumber, $excludeTenantCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        logActivity(
            "Bank reuse attempt: {$bankCode}/******" . substr($accountNumber, -4)
            . " claimed by {$row['tenant_code']}, attempted by {$excludeTenantCode}",
            'WARN'
        );
        return ['available' => false, 'reason' => 'This bank account is already in use by another tenant'];
    }
    return ['available' => true, 'owned_by' => null, 'reason' => null];
}

function recordBankClaim(
    mysqli $conn,
    string $tenantCode,
    string $bankCode,
    string $accountNumber,
    string $accountName
): bool {
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

function countPendingWithdrawals(mysqli $conn, string $userType, string $userCode): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM wallet_requests
        WHERE user_type = ? AND user_code = ?
          AND request_type = 'withdrawal' AND status = 'pending'
    ");
    if (!$stmt) return PHP_INT_MAX;
    $stmt->bind_param("ss", $userType, $userCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['n'] ?? 0);
}

// ============================================================
// 4. PASSWORD VERIFICATION WITH LOCKOUT
// ============================================================

function verifyTenantPasswordWithLockout(
    mysqli $conn,
    string $tenantCode,
    string $submittedPassword,
    string $storedHash,
    ?string $currentSessionId = null
): array {
    $stmt = $conn->prepare("
        SELECT attempts, locked_until
        FROM tenant_login_attempts
        WHERE tenant_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['ok' => false, 'locked' => true, 'message' => 'Unable to verify at this time. Please try again later.'];
    }
    $stmt->bind_param("s", $tenantCode);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attempts    = (int) ($state['attempts'] ?? 0);
    $lockedUntil = $state['locked_until'] ?? null;

    if ($attempts >= 3 && $lockedUntil) {
        $until = new DateTime($lockedUntil);
        $now   = new DateTime();
        if ($now < $until) {
            $diff = $now->diff($until);
            $mins = $diff->i + ($diff->h * 60);
            $secs = $diff->s;
            return [
                'ok'      => false,
                'locked'  => true,
                'message' => "Account is temporarily locked due to failed attempts. Try again in {$mins}m {$secs}s.",
            ];
        }
        $reset = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
        if ($reset) { $reset->bind_param("s", $tenantCode); $reset->execute(); $reset->close(); }
        $attempts = 0;
    }

    if (!password_verify($submittedPassword, $storedHash)) {
        $attempts++;
        $lockedUntil = null;
        $nowLocked   = false;

        if ($attempts >= 3) {
            $lockedUntil = date('Y-m-d H:i:s', time() + 3600);
            $nowLocked   = true;

            try {
                $hist = $conn->prepare("
                    INSERT INTO tenant_lock_history
                        (tenant_code, status, locked_by, lock_reason, lock_method, locked_at)
                    VALUES (?, 'locked', 0, ?, 'Automatic lock (password reauth)', NOW())
                ");
                if ($hist) {
                    $reason = "Account locked after {$attempts} failed password attempts during password operation";
                    $hist->bind_param("ss", $tenantCode, $reason);
                    $hist->execute();
                    $hist->close();
                }
            } catch (Throwable $_) {}

            // Kill active sessions — spare the caller's own session if provided
            try {
                if ($currentSessionId !== null) {
                    $kill = $conn->prepare("
                        UPDATE tenant_active_sessions
                        SET status = 'Inactive', logged_out_at = NOW()
                        WHERE tenant_code = ? AND status = 'Active' AND session_id <> ?
                    ");
                    if ($kill) {
                        $kill->bind_param("ss", $tenantCode, $currentSessionId);
                        $kill->execute();
                        $kill->close();
                    }
                } else {
                    $kill = $conn->prepare("
                        UPDATE tenant_active_sessions
                        SET status = 'Inactive', logged_out_at = NOW()
                        WHERE tenant_code = ? AND status = 'Active'
                    ");
                    if ($kill) {
                        $kill->bind_param("s", $tenantCode);
                        $kill->execute();
                        $kill->close();
                    }
                }
            } catch (Throwable $_) {}


            logActivity("Tenant Password lockout triggered: tenant={$tenantCode} after {$attempts} attempts", 'WARN');
        }

        $up = $conn->prepare("
            INSERT INTO tenant_login_attempts (tenant_code, attempts, last_attempt, locked_until)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                attempts     = VALUES(attempts),
                last_attempt = NOW(),
                locked_until = VALUES(locked_until)
        ");
        if ($up) {
            $up->bind_param("sis", $tenantCode, $attempts, $lockedUntil);
            $up->execute();
            $up->close();
        }

        return [
            'ok'      => false,
            'locked'  => $nowLocked,
            'message' => $nowLocked
                ? "Incorrect password. Account locked for 60 minutes."
                : "Incorrect password. " . (3 - $attempts) . " attempt(s) remaining.",
        ];
    }

    $reset = $conn->prepare("DELETE FROM tenant_login_attempts WHERE tenant_code = ?");
    if ($reset) { $reset->bind_param("s", $tenantCode); $reset->execute(); $reset->close(); }

    return ['ok' => true, 'locked' => false, 'message' => null];
}