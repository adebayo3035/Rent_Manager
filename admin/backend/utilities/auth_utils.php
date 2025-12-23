<?php
function hashPassword($password) {
    $options = [
        'memory_cost' => 1 << 17, // 128 MB
        'time_cost'   => 4,
        'threads'     => 2
    ];

    return password_hash($password, PASSWORD_ARGON2ID, $options);
}
function passwordVerify($password, $hash) {
    return password_verify($password, $hash);
}
function verifyAndRehashPassword($pdo, $adminId, $inputPassword, $storedHash) {

    if (!passwordVerify($inputPassword, $storedHash)) {
        return false; // Incorrect password
    }

    // If PHP recommends rehash, update it
    if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {

        $newHash = hashPassword($inputPassword);

        $stmt = $pdo->prepare("UPDATE admin_tbl SET password = ? WHERE unique_id = ?");
        $stmt->execute([$newHash, $adminId]);
    }

    return true;
}
function password_needsRehashPassword($pdo, $adminId, $inputPassword, $storedHash) {

    // If PHP recommends rehash, update it
    if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {

        $newHash = hashPassword($inputPassword);

        $stmt = $pdo->prepare("UPDATE admin_tbl SET password = ? WHERE unique_id = ?");
        $stmt->execute([$newHash, $adminId]);
    }
}

// Add to auth_utils.php
/**
 * Generate and store CSRF token
 */
function generateCsrfToken($formName = 'default') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $token = bin2hex(random_bytes(32));
    
    $_SESSION['csrf_tokens'][$formName] = [
        'token' => $token,
        'created' => time()
    ];
    
    // Clean old tokens
    if (isset($_SESSION['csrf_tokens'])) {
        foreach ($_SESSION['csrf_tokens'] as $key => $data) {
            if (time() - $data['created'] > 3600) {
                unset($_SESSION['csrf_tokens'][$key]);
            }
        }
    }
    
    return $token;
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token, $formName = 'default', $maxAge = 3600) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_tokens'][$formName])) {
        return false;
    }
    
    $stored = $_SESSION['csrf_tokens'][$formName];
    
    if (!hash_equals($stored['token'], $token)) {
        return false;
    }
    
    if (time() - $stored['created'] > $maxAge) {
        unset($_SESSION['csrf_tokens'][$formName]);
        return false;
    }
    
    return true;
}

/**
 * Get existing token or generate new one
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

function consumeCsrfToken(string $formName): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    unset($_SESSION['csrf_tokens'][$formName]);
}
