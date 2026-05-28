<?php
// security_utils.php or add to auth_utils.php

/**
 * Generate a CSRF token and store it in session
 * @param string $formName Optional form identifier
 * @return string CSRF token
 */
function generateCsrfToken($formName = 'default') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate random token
    $token = bin2hex(random_bytes(32));
    
    // Store in session with timestamp
    $_SESSION['csrf_tokens'][$formName] = [
        'token' => $token,
        'created' => time()
    ];
    
    // Clean up old tokens (older than 1 hour)
    if (isset($_SESSION['csrf_tokens'])) {
        foreach ($_SESSION['csrf_tokens'] as $key => $data) {
            if (time() - $data['created'] > 3600) { // 1 hour expiration
                unset($_SESSION['csrf_tokens'][$key]);
            }
        }
    }
    
    return $token;
}

/**
 * Validate CSRF token
 * @param string $token Token to validate
 * @param string $formName Form identifier
 * @param int $maxAge Maximum age in seconds (default 1 hour)
 * @return bool True if valid, false otherwise
 */
function validateCsrfToken($token, $formName = 'default', $maxAge = 3600) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if token exists
    if (!isset($_SESSION['csrf_tokens'][$formName])) {
        return false;
    }
    
    $stored = $_SESSION['csrf_tokens'][$formName];
    
    // Check token value
    if (!hash_equals($stored['token'], $token)) {
        return false;
    }
    
    // Check token age
    if (time() - $stored['created'] > $maxAge) {
        // Remove expired token
        unset($_SESSION['csrf_tokens'][$formName]);
        return false;
    }
    
    return true;
}

/**
 * Get current CSRF token (generate if doesn't exist)
 * @param string $formName Form identifier
 * @return string CSRF token
 */
function getCsrfToken($formName = 'default') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_tokens'][$formName])) {
        return generateCsrfToken($formName);
    }
    
    return $_SESSION['csrf_tokens'][$formName]['token'];
}

/**
 * Clear used CSRF token (use for one-time tokens)
 * @param string $formName Form identifier
 */
function clearCsrfToken($formName = 'default') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['csrf_tokens'][$formName])) {
        unset($_SESSION['csrf_tokens'][$formName]);
    }
}