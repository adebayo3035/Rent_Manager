<?php
// client/backend/utilities/page_guard.php
//
// Require at the top of every authenticated CLIENT page, AFTER session_start()
// and after confirming $clientCode is set.
//
// If the user has an active password or secret-answer lockout, redirect to
// the dedicated locked page. Runs before any HTML is rendered, so there is
// no way to bypass via JS, DevTools, or browser cache.
//
// Usage in every authenticated client page:
//   require_once __DIR__ . '/../backend/utilities/page_guard.php';
//   session_start();
//   $clientCode = $_SESSION['client_code'] ?? null;
//   if (!$clientCode) { header('Location: /client/pages/index.php'); exit; }
//   requireNoActiveLockout($conn, 'clientCode');
//   requireNoActiveLockout($conn, $clientCode);

function requireNoActiveLockout(mysqli $conn, string $clientCode): void
{
    // ---- 1) Secret-answer lockout (checked first — shorter window) ----
    $secretTable = 'client_secret_attempts';
    $secretCol   = 'client_code';

    $stmt = $conn->prepare("
        SELECT attempts, locked_until
        FROM {$secretTable}
        WHERE {$secretCol} = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $clientCode);
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
        FROM client_login_attempts
        WHERE client_code = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $clientCode);
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