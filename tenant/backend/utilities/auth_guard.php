<?php
// utilities/auth_guard.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function requireAuth(array $options = [])
{
    header('Content-Type: application/json; charset=utf-8');

    // ---------------- Defaults ----------------
    $method    = $options['method']     ?? null;
    $rateKey   = $options['rate_key']   ?? null;
    $rateLimit = $options['rate_limit'] ?? null;
    $roles     = $options['roles']      ?? [];
    $csrf      = $options['csrf']       ?? ['enabled' => false];

    // ---------------- Rate Limiting ----------------
    if ($rateKey && is_array($rateLimit)) {
        rateLimit($rateKey, $rateLimit[0], $rateLimit[1]);
        logActivity("Rate limit passed | Key={$rateKey}");
    }

    // ---------------- Method Validation ----------------
    if ($method && $_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        logActivity("Invalid request method: {$_SERVER['REQUEST_METHOD']}");
        json_error("Invalid request method. Use {$method}.", 405);
    }

    // ---------------- Unified Request Body Accessor ----------------
    if (!isset($GLOBALS['__REQUEST_BODY'])) {
        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw  = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $body = array_merge($body, $json);
            }
        }
        $GLOBALS['__REQUEST_BODY'] = $body;
    }
    $requestBody = $GLOBALS['__REQUEST_BODY'];

    // ---------------- CSRF Validation ----------------
    if (!empty($csrf['enabled']) && $csrf['enabled'] === true) {
        $formName = $csrf['form_name'] ?? null;

        if (!$formName) {
            logActivity("CSRF enabled but no form_name supplied");
            json_error("Security configuration error.", 500);
        }

        $tokenId = $requestBody['token_id']  ?? null;
        $csrfTok = $requestBody['csrf_token'] ?? null;

        if ($tokenId === null || $tokenId !== $formName) {
            logActivity("CSRF form name mismatch | Token ID passed = "
                . ($tokenId ?? '(not set)') . " | Expected = {$formName}");
            json_error("Security token invalid or expired.", 403);
        }

        if ($csrfTok === null || !validateCsrfToken($csrfTok, $formName)) {
            logActivity("CSRF token validation failed | Form={$formName}");
            json_error("Security token invalid or expired.", 403);
        }

        logActivity("CSRF validation passed | Form={$formName}");
    }

    // ---------------- Session Check (Multi-Role) ----------------
    $roleSessionMap = [
        'Client'      => 'client_code',
        'Tenant'      => 'tenant_code',
        'Agent'       => 'agent_code',
        'Admin'       => 'unique_id',
        'Super Admin' => 'unique_id',
    ];

    $userId   = null;
    $userRole = null;

    if (!empty($roles)) {
        foreach ($roles as $allowedRole) {
            $key = $roleSessionMap[$allowedRole] ?? null;
            if ($key && isset($_SESSION[$key])) {
                $userId   = $_SESSION[$key];
                $userRole = $allowedRole;
                break;
            }
        }
        if ($userId === null) {
            logActivity("Unauthorized access — no session for allowed roles: "
                . implode(',', $roles));
            json_error("Not logged in", 401);
        }
    } else {
        foreach ($roleSessionMap as $role => $key) {
            if (isset($_SESSION[$key])) {
                $userId   = $_SESSION[$key];
                $userRole = $role;
                break;
            }
        }
        if ($userId === null) {
            logActivity("Unauthorized access — no active session");
            json_error("Not logged in", 401);
        }
    }

    // ---------------- Active-Tenancy Check (opt-in, tenant-only) ----------------
    //
    // Runs only when:
    //   • the endpoint explicitly sets 'require_active_tenancy' => true, AND
    //   • the resolved role is 'Tenant'.
    //
    // $_SESSION['tenant_status'] is the lifecycle enum from the DB:
    //   0 = Inactive, 1 = Active, 2 = Suspended, 3 = Evacuated
    //
    // Callers can widen the allowlist via 'allowed_tenancy_statuses'.
    // Default allowlist = [1] (Active only) — the safest default.
    //
    // NOTE: This is intentionally independent of $_SESSION['tenant_state']
    // (the older, unreliable string key). Only tenant_status is authoritative.

    if (!empty($options['require_active_tenancy']) && $userRole === 'Tenant') {
        $rawTenantStatus = $_SESSION['tenant_status'] ?? null;

        // Normalize: accept int or numeric string; otherwise unknown.
        $tenantStatus = is_numeric($rawTenantStatus) ? (int) $rawTenantStatus : null;

        // Optional one-time backfill for stale sessions (post-deploy only).
        // Comment this block out if you prefer forcing re-login instead.
        if ($tenantStatus === null && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            try {
                $backfill = $GLOBALS['conn']->prepare(
                    "SELECT tenant_status FROM tenants WHERE tenant_code = ? LIMIT 1"
                );
                if ($backfill) {
                    $backfill->bind_param("s", $userId);
                    $backfill->execute();
                    $row = $backfill->get_result()->fetch_assoc();
                    $backfill->close();
                    if ($row && is_numeric($row['tenant_status'])) {
                        $tenantStatus = (int) $row['tenant_status'];
                        $_SESSION['tenant_status'] = $tenantStatus;
                        logActivity(
                            "Tenancy gate: backfilled session tenant_status={$tenantStatus} for {$userId}"
                        );
                    }
                }
            } catch (Throwable $e) {
                logActivity(
                    "Tenancy gate: backfill failed for {$userId}: " . $e->getMessage(),
                    'WARN'
                );
            }
        }

        $allowedStatuses = $options['allowed_tenancy_statuses'] ?? [1];

        if ($tenantStatus === null || !in_array($tenantStatus, $allowedStatuses, true)) {
            $statusLabels = [
                0 => 'Inactive',
                1 => 'Active',
                2 => 'Suspended',
                3 => 'Evacuated',
            ];
            $label = $tenantStatus === null
                ? 'Unknown'
                : ($statusLabels[$tenantStatus] ?? "Unknown ({$tenantStatus})");

            logActivity(
                "Tenancy gate blocked: user={$userId} role={$userRole} "
                . "tenant_status=" . var_export($tenantStatus, true)
                . " (label={$label}) allowed=" . implode(',', $allowedStatuses),
                'WARN'
            );

            json_error(
                "This action is not available for your current tenancy state ({$label}).",
                403,
                null,
                'TENANCY_STATE_BLOCKED',
                [
                    'tenant_status'       => $tenantStatus,
                    'tenant_status_label' => $label,
                ]
            );
        }

        logActivity("Tenancy gate passed: user={$userId} tenant_status={$tenantStatus}");
    }

    logActivity("Auth OK | User={$userId} | Role={$userRole}");

    return [
        'user_id' => $userId,
        'role'    => $userRole,
    ];
}