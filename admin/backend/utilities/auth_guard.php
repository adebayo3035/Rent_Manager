<?php
// utilities/auth_guard.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Centralized authentication & security guard
 *
 * Handles:
 *   - Rate limiting
 *   - HTTP method enforcement
 *   - CSRF validation (works for both form-data AND JSON bodies)
 *   - Session authentication
 *   - Role-based access control
 *
 * @param array $options
 * @return array ['user_id' => string, 'role' => string]
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

    // ---------------- CSRF Validation ----------------
    if (!empty($csrf['enabled']) && $csrf['enabled'] === true) {

        $formName = $csrf['form_name'] ?? null;

        if (!$formName) {
            logActivity("CSRF enabled but no form_name supplied");
            json_error("Security configuration error.", 500);
        }

        // ============================================================
        // JSON BRIDGE
        // ============================================================
        // Why: PHP auto-populates $_POST only for multipart/form-data and
        // application/x-www-form-urlencoded. For application/json, $_POST
        // stays empty and the body lives only in php://input.
        //
        // This block merges JSON into $_POST so the CSRF checks below
        // work uniformly for all three content types.
        //
        // - Form-data:      $_POST already has keys → skip
        // - URL-encoded:    $_POST already has keys → skip
        // - JSON:           $_POST empty → parse php://input, merge
        // - Empty body:     $_POST empty, php://input empty → no-op
        // ============================================================
        if (empty($_POST)) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    $_POST = $decoded;
                    logActivity("CSRF: JSON body merged into \$_POST for validation");
                }
            }
        }

        // ---- Check token_id matches the configured form ----
        if (!isset($_POST['token_id']) || $_POST['token_id'] !== $formName) {
            $token_id = $_POST['token_id'] ?? '(missing)';
            logActivity("CSRF form name mismatch | Token ID from client = {$token_id} | Expected = {$formName}");
            json_error("Security token invalid or expired.", 403);
        }

        // ---- Check csrf_token is present and valid ----
        if (
            !isset($_POST['csrf_token']) ||
            !validateCsrfToken($_POST['csrf_token'], $formName)
        ) {
            logActivity("CSRF token validation failed | Form={$formName}");
            json_error("Security token invalid or expired.", 403);
        }

        // Uncomment to enforce one-time-use tokens:
        // unset($_SESSION['csrf_tokens'][$formName]);

        logActivity("CSRF validation passed | Form={$formName}");
    }

    // ---------------- Session Check ----------------
    if (!isset($_SESSION['unique_id'])) {
        logActivity("Unauthorized access — no active session");
        json_error("Not logged in", 401);
    }

    $userId   = $_SESSION['unique_id'];
    $userRole = $_SESSION['role'] ?? 'UNKNOWN';

    // ---------------- Role Check ----------------
    if (!empty($roles) && !in_array($userRole, $roles, true)) {
        logActivity("Access denied | User={$userId} | Role={$userRole}");
        json_error("Access denied. Permission not granted.", 403);
    }

    logActivity("Auth OK | User={$userId} | Role={$userRole}");

    // ---------------- Return Auth Context ----------------
    return [
        'user_id' => $userId,
        'role'    => $userRole
    ];
}