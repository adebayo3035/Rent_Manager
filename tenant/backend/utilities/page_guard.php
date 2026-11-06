<?php
// tenant/backend/utilities/page_guard.php

function requireNoActiveLockout(mysqli $conn, string $tenantCode): void
{
    // ---- 1) Secret-answer lockout ----
    $stmt = $conn->prepare("
        SELECT attempts, locked_until
        FROM tenant_secret_attempts
        WHERE tenant_code = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $tenantCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row
            && (int)($row['attempts'] ?? 0) >= 3
            && !empty($row['locked_until'])
            && new DateTime() < new DateTime($row['locked_until'])) {
            $until = urlencode($row['locked_until']);
            header("Location: locked.php?source=secret_answer&until={$until}");
            exit;
        }
    }

    // ---- 2) Password lockout ----
    $stmt = $conn->prepare("
        SELECT attempts, locked_until
        FROM tenant_login_attempts
        WHERE tenant_code = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $tenantCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row
            && (int)($row['attempts'] ?? 0) >= 3
            && !empty($row['locked_until'])
            && new DateTime() < new DateTime($row['locked_until'])) {
            $until = urlencode($row['locked_until']);
            header("Location: locked.php?source=password&until={$until}");
            exit;
        }
    }
}