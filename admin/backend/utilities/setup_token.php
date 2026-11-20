<?php

/**
 * SETUP TOKEN SERVICE
 *
 * Location:
 * tenant/backend/utilities/setup_token.php
 *
 * Used by:
 * 1. verify_setup_otp.php
 * 2. complete_account_setup.php
 *
 * Token format:
 * Base64URL(JSON_PAYLOAD . "." . HMAC_SIGNATURE)
 *
 * Security:
 * - HMAC-SHA256 signature.
 * - 15-minute token expiry.
 * - Cryptographically secure nonce.
 * - No hardcoded fallback signing secret.
 * - No logging of raw tokens or signing secrets.
 */

/*
|--------------------------------------------------------------------------
| 1. TOKEN CONFIGURATION
|--------------------------------------------------------------------------
*/

// Token validity: 15 minutes.
if (!defined('SETUP_TOKEN_TTL')) {
    define('SETUP_TOKEN_TTL', 900);
}
/*
|--------------------------------------------------------------------------
| LOAD APPLICATION SECRETS
|--------------------------------------------------------------------------
*/

$secretsFile = __DIR__ . '/../../../secrets.php';

if (!file_exists($secretsFile)) {
    logActivity("[SETUP_TOKEN] ERROR: Secrets file not found.");

    throw new RuntimeException(
        "Application secrets configuration is unavailable."
    );
}

$secrets = require $secretsFile;

if (
    !is_array($secrets) ||
    empty($secrets['SETUP_TOKEN_SECRET'])
) {
    logActivity("[SETUP_TOKEN] ERROR: Setup token secret is missing.");

    throw new RuntimeException(
        "Setup token signing configuration is unavailable."
    );
}

$setupTokenSecret = trim(
    (string) $secrets['SETUP_TOKEN_SECRET']
);

if (strlen($setupTokenSecret) < 32) {
    logActivity("[SETUP_TOKEN] ERROR: Setup token secret is too short.");

    throw new RuntimeException(
        "Setup token signing configuration is invalid."
    );
}

if (!defined('SETUP_TOKEN_SECRET')) {
    define('SETUP_TOKEN_SECRET', $setupTokenSecret);
}

logActivity(
    "[SETUP_TOKEN] Secrets loaded successfully. Fingerprint: " .
    substr(hash('sha256', SETUP_TOKEN_SECRET), 0, 16)
);



/*
|--------------------------------------------------------------------------
| 2. MASK EMAIL ADDRESS FOR LOGGING
|--------------------------------------------------------------------------
*/

if (!function_exists('setupTokenMaskEmail')) {

    function setupTokenMaskEmail($email)
    {
        $email = (string) $email;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[invalid-email]';
        }

        $parts = explode('@', $email, 2);

        $username = $parts[0];
        $domain = $parts[1];

        if (strlen($username) <= 2) {
            $maskedUsername = substr($username, 0, 1) . '***';
        } else {
            $maskedUsername =
                substr($username, 0, 2) .
                str_repeat('*', min(strlen($username) - 2, 6));
        }

        return $maskedUsername . '@' . $domain;
    }
}


/*
|--------------------------------------------------------------------------
| 3. ISSUE SETUP TOKEN
|--------------------------------------------------------------------------
|
| Called after successful OTP verification.
|
| Parameters:
| $userCode - Tenant code
| $userType - User category, e.g. tenant
| $email    - Tenant email address
|
| Returns:
| Signed setup token string.
|
*/

if (!function_exists('issueSetupToken')) {

    function issueSetupToken($userCode, $userType, $email)
    {
        logActivity("[SETUP_TOKEN] Token issuance started.");

        logActivity(
            "[SETUP_TOKEN] Implementation file: " . __FILE__
        );

        $maskedEmail = setupTokenMaskEmail($email);

        // Ensure the signing secret is configured correctly.
        if (
            !defined('SETUP_TOKEN_SECRET') ||
            SETUP_TOKEN_SECRET === '' ||
            strlen(SETUP_TOKEN_SECRET) < 32
        ) {
            logActivity(
                "[SETUP_TOKEN] Issuance failed: Signing secret is missing or too short."
            );

            throw new RuntimeException(
                "Setup token signing configuration is unavailable."
            );
        }

        // Validate required input.
        if (
            empty($userCode) ||
            empty($userType) ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            logActivity(
                "[SETUP_TOKEN] Issuance failed: Invalid user details. Email: " .
                $maskedEmail
            );

            throw new InvalidArgumentException(
                "Invalid setup token details."
            );
        }

        try {

            // Create token payload.
            $payloadData = [
                'code' => (string) $userCode,
                'type' => (string) $userType,
                'email' => strtolower(trim($email)),
                'expires' => time() + SETUP_TOKEN_TTL,
                'nonce' => bin2hex(random_bytes(16))
            ];

            // Convert payload to JSON.
            $payload = json_encode(
                $payloadData,
                JSON_UNESCAPED_SLASHES
            );

            if ($payload === false) {
                logActivity(
                    "[SETUP_TOKEN] Issuance failed: JSON encoding error."
                );

                throw new RuntimeException(
                    "Unable to generate setup token."
                );
            }

            logActivity(
                "[SETUP_TOKEN] Payload generated. Email: " .
                $maskedEmail
            );

            logActivity(
                "[SETUP_TOKEN] Issued payload fingerprint: " .
                substr(hash('sha256', $payload), 0, 16)
            );

            // Generate HMAC-SHA256 signature.
            $signature = hash_hmac(
                'sha256',
                $payload,
                SETUP_TOKEN_SECRET
            );

            // Combine payload and signature.
            $tokenData = $payload . '.' . $signature;

            // Encode using Base64URL.
            $token = rtrim(
                strtr(
                    base64_encode($tokenData),
                    '+/',
                    '-_'
                ),
                '='
            );

            logActivity(
                "[SETUP_TOKEN] Token generated successfully. Email: " .
                $maskedEmail
            );

            logActivity(
                "[SETUP_TOKEN] Token fingerprint: " .
                substr(hash('sha256', $token), 0, 16)
            );

            logActivity(
                "[SETUP_TOKEN] Token expiry: " .
                date('Y-m-d H:i:s', $payloadData['expires'])
            );

            return $token;

        } catch (Throwable $e) {

            logActivity(
                "[SETUP_TOKEN] Issuance exception: " .
                $e->getMessage()
            );

            throw $e;
        }
    }
}


/*
|--------------------------------------------------------------------------
| 4. VERIFY SETUP TOKEN
|--------------------------------------------------------------------------
|
| Called by complete_account_setup.php.
|
| Parameters:
| $token - Setup token supplied by the frontend.
|
| Returns:
| Array containing code, type and email when valid.
| NULL when invalid or expired.
|
*/

if (!function_exists('verifySetupToken')) {

    function verifySetupToken($token)
    {
        logActivity("[SETUP_TOKEN] Token verification started.");

        logActivity(
            "[SETUP_TOKEN] Implementation file: " . __FILE__
        );

        // Ensure signing secret is correctly configured.
        if (
            !defined('SETUP_TOKEN_SECRET') ||
            SETUP_TOKEN_SECRET === '' ||
            strlen(SETUP_TOKEN_SECRET) < 32
        ) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Signing secret is missing or too short."
            );

            return null;
        }

        // Validate token input.
        if (
            !is_string($token) ||
            $token === '' ||
            strlen($token) > 8192
        ) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Invalid token input."
            );

            return null;
        }

        // Confirm that token contains only Base64URL characters.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Invalid Base64URL characters."
            );

            return null;
        }

        // Decode Base64URL token.
        $base64 = strtr($token, '-_', '+/');

        // Restore Base64 padding.
        $padding = strlen($base64) % 4;

        if ($padding !== 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false || $decoded === '') {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Base64 decoding failed."
            );

            return null;
        }

        logActivity(
            "[SETUP_TOKEN] Base64 decoding successful."
        );

        logActivity(
            "[SETUP_TOKEN] Token fingerprint: " .
            substr(hash('sha256', $token), 0, 16)
        );

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT: EXTRACT PAYLOAD AND SIGNATURE
        |--------------------------------------------------------------------------
        |
        | Do not use:
        |
        | explode('.', $decoded, 2)
        |
        | The JSON payload contains email addresses such as gmail.com.
        | Splitting at the first dot would corrupt the payload.
        |
        | strrpos() locates the final separator instead.
        |
        */

        $separatorPosition = strrpos($decoded, '.');

        if ($separatorPosition === false) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Token separator not found."
            );

            return null;
        }

        // Extract payload and signature.
        $payload = substr(
            $decoded,
            0,
            $separatorPosition
        );

        $signature = substr(
            $decoded,
            $separatorPosition + 1
        );

        if ($payload === '' || $signature === '') {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Empty payload or signature."
            );

            return null;
        }

        // SHA-256 HMAC signature must be 64 hexadecimal characters.
        if (!preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Invalid signature format."
            );

            return null;
        }

        logActivity(
            "[SETUP_TOKEN] Payload extracted successfully."
        );

        logActivity(
            "[SETUP_TOKEN] Received payload fingerprint: " .
            substr(hash('sha256', $payload), 0, 16)
        );

        // Generate expected signature.
        $expectedSignature = hash_hmac(
            'sha256',
            $payload,
            SETUP_TOKEN_SECRET
        );

        // Compare signatures securely.
        if (!hash_equals($expectedSignature, $signature)) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Signature mismatch."
            );

            return null;
        }

        logActivity(
            "[SETUP_TOKEN] Signature verification successful."
        );

        /*
        |--------------------------------------------------------------------------
        | 5. DECODE AND VALIDATE PAYLOAD
        |--------------------------------------------------------------------------
        */

        $payloadData = json_decode(
            $payload,
            true
        );

        if (
            !is_array($payloadData) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Invalid JSON payload."
            );

            return null;
        }

        // Validate mandatory payload fields.
        if (
            !isset(
            $payloadData['code'],
            $payloadData['type'],
            $payloadData['email'],
            $payloadData['expires'],
            $payloadData['nonce']
        )
        ) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Required payload fields are missing."
            );

            return null;
        }

        if (
            !is_string($payloadData['code']) ||
            $payloadData['code'] === '' ||
            !is_string($payloadData['type']) ||
            $payloadData['type'] === '' ||
            !is_string($payloadData['email']) ||
            !filter_var($payloadData['email'], FILTER_VALIDATE_EMAIL) ||
            !is_numeric($payloadData['expires']) ||
            !is_string($payloadData['nonce']) ||
            !preg_match('/^[a-f0-9]{32}$/i', $payloadData['nonce'])
        ) {
            logActivity(
                "[SETUP_TOKEN] Verification failed: Invalid payload values."
            );

            return null;
        }

        $expires = (int) $payloadData['expires'];

        // Reject expired tokens.
        if ($expires <= time()) {

            logActivity(
                "[SETUP_TOKEN] Verification failed: Token has expired."
            );

            logActivity(
                "[SETUP_TOKEN] Token expiry timestamp: " . $expires
            );

            return null;
        }

        logActivity(
            "[SETUP_TOKEN] Token expiry validation successful."
        );

        // Return only the claims required by the calling endpoint.
        $verifiedData = [
            'code' => $payloadData['code'],
            'type' => $payloadData['type'],
            'email' => strtolower(trim($payloadData['email']))
        ];

        logActivity(
            "[SETUP_TOKEN] Token verified successfully. Email: " .
            setupTokenMaskEmail($verifiedData['email'])
        );

        return $verifiedData;
    }
}

?>