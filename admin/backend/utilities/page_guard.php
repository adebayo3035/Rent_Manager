<?php
function requireNoActiveLockout(mysqli $conn, string $unique_id): void
{
    // ---- 1) Secret-answer lockout (checked first — shorter window) ----
    $secretTable = 'admin_secret_attempts';
    $secretCol   = 'unique_id';

    $stmt = $conn->prepare("
        SELECT attempts, locked_until
        FROM {$secretTable}
        WHERE {$secretCol} = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $unique_id);
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
        FROM admin_login_attempts
        WHERE unique_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $unique_id);
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