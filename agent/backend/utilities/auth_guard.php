<?php
// utilities/auth_guard.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Centralized authentication & security guard.
 *
 * Automatically reads the request body for CSRF validation whether the
 * request is application/json, multipart/form-data, or x-www-form-urlencoded.
 *
 * Supports multi-role sessions (Client, Tenant, Agent, Admin, Super Admin).
 */
function requireAuth(array $options = [])
{
    header('Content-Type: application/json; charset=utf-8');

    // ---------------- Defaults ----------------
    $method    = $options['method']      ?? null;
    $rateKey   = $options['rate_key']    ?? null;
    $rateLimit = $options['rate_limit']  ?? null;
    $roles     = $options['roles']       ?? [];
    $csrf      = $options['csrf']        ?? ['enabled' => false];

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
    // Reads the body from php://input ONCE and merges:
    //   - $_POST (multipart / urlencoded)
    //   - JSON body (application/json)
    //
    // The result is exposed as $GLOBALS['__REQUEST_BODY'] so downstream code
    // (and the CSRF check below) can consume it consistently.
    if (!isset($GLOBALS['__REQUEST_BODY'])) {
        $body = $_POST;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                // JSON takes priority but does NOT wipe form fields
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

    logActivity("Auth OK | User={$userId} | Role={$userRole}");

    return [
        'user_id' => $userId,
        'role'    => $userRole,
    ];
}