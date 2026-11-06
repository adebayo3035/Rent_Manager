<?php
// tenant/pages/locked.php
// Standalone locked page. Reached only via page_guard.php redirect.
// Renders a countdown and a logout button. No app chrome.

if (session_status() === PHP_SESSION_NONE) session_start();

$tenantCode = $_SESSION['tenant_code'] ?? null;
if (!$tenantCode) {
    header('Location: index.php');
    exit;
}

$source = $_GET['source'] ?? 'security';
$until  = $_GET['until']  ?? '';

// Basic validation — if source is bad, treat as 'security'
if (!in_array($source, ['password', 'secret_answer', 'security'], true)) {
    $source = 'security';
}

// ISO string passed to JS for countdown computation
$untilIso = '';
if ($until !== '') {
    try {
        $untilIso = (new DateTime($until))->format('c');
    } catch (Throwable $e) {
        $untilIso = '';
    }
}

$sourceLabel = $source === 'password'
    ? 'password'
    : ($source === 'secret_answer' ? 'security-answer' : 'security');

$title = $source === 'password'
    ? 'Account Locked — Too Many Failed Password Attempts'
    : ($source === 'secret_answer'
        ? 'Account Locked — Too Many Failed Security-Answer Attempts'
        : 'Account Temporarily Locked');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Locked</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #1e293b;
        }
        .locked-card {
            background: #fff;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.15);
            overflow: hidden;
        }
        .locked-header {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            padding: 28px 32px;
            color: #fff;
        }
        .locked-header h1 {
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .locked-header h1 i { font-size: 24px; }
        .locked-body { padding: 28px 32px; }
        .alert-box {
            background: #fef2f2;
            border-left: 4px solid #dc2626;
            padding: 16px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-box p { color: #7f1d1d; font-size: 14px; line-height: 1.6; margin: 0; }
        .alert-box p + p { margin-top: 8px; }
        .countdown-line {
            font-size: 14px;
            color: #991b1b;
            font-weight: 500;
        }
        .countdown-line strong {
            font-size: 18px;
            font-weight: 700;
            margin-left: 4px;
        }
        .body-text {
            font-size: 14px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .body-text span { font-weight: 600; color: #475569; }
        .small-note {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s;
        }
        .btn-logout {
            background: #dc2626;
            color: #fff;
        }
        .btn-logout:hover { background: #b91c1c; }
        .btn-continue {
            background: #10b981;
            color: #fff;
        }
        .btn-continue:hover { background: #059669; }
    </style>
</head>
<body>
    <div class="locked-card">
        <div class="locked-header">
            <h1>
                <i class="fas fa-lock"></i>
                <?= htmlspecialchars($title) ?>
            </h1>
        </div>
        <div class="locked-body">
            <div class="alert-box">
                <p>For your security, your account has been locked after too many failed
                   <strong><?= htmlspecialchars($sourceLabel) ?></strong> attempts.</p>
                <p class="countdown-line">
                    <i class="fas fa-clock"></i>
                    Try again in <strong id="countdown">—</strong>
                </p>
            </div>

            <p class="body-text">
                You cannot access any part of the system until the lockout expires.
                Please log out and try again later.
            </p>

            <p class="small-note">
                If you believe this is a mistake, contact support.
                Do not share your password or secret answer with anyone.
            </p>

            <div class="actions">
                <button type="button" class="btn btn-logout" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i> Log Out Now
                </button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const lockedUntilIso = <?= json_encode($untilIso) ?>;
            const lockedUntil = lockedUntilIso ? new Date(lockedUntilIso) : null;

            const countdownEl = document.getElementById('countdown');
            const logoutBtn = document.getElementById('logoutBtn');

            function formatRemaining(ms) {
                if (ms <= 0) return "now";
                const totalSec = Math.ceil(ms / 1000);
                const mins = Math.floor(totalSec / 60);
                const secs = totalSec % 60;
                if (mins > 0) return `${mins}m ${secs.toString().padStart(2, "0")}s`;
                return `${secs}s`;
            }

            function tick() {
                if (!lockedUntil) {
                    countdownEl.textContent = "—";
                    return;
                }
                const remaining = lockedUntil.getTime() - Date.now();
                if (remaining <= 0) {
                    countdownEl.textContent = "expired";
                    logoutBtn.innerHTML = '<i class="fas fa-check"></i> Continue';
                    logoutBtn.classList.remove('btn-logout');
                    logoutBtn.classList.add('btn-continue');
                    logoutBtn.onclick = () => {
                        window.location.href = '/tenant/pages/dashboard.php';
                    };
                    clearInterval(timer);
                    return;
                }
                countdownEl.textContent = formatRemaining(remaining);
            }

            tick();
            const timer = setInterval(tick, 1000);

            logoutBtn.addEventListener('click', async () => {
                try {
                    await fetch('/tenant/backend/authentication/logout.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ logout_id: <?= json_encode($tenantCode) ?> }),
                        credentials: 'same-origin',
                    });
                } catch (_) {}

                try {
                    localStorage.removeItem('userData');
                    localStorage.removeItem('userPreferences');
                    localStorage.removeItem('tenant_session');
                    sessionStorage.clear();
                } catch (_) {}

                window.location.href = 'index.php?reason=account_locked';
            });
        })();
    </script>
</body>
</html>