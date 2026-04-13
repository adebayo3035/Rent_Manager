<?php
function json_error($message, $code = 400)
{
    http_response_code($code);

    // Get caller location for error logging
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = $backtrace[0];
    $callerFile = basename($caller['file'] ?? 'unknown');
    $callerLine = $caller['line'] ?? 0;
    $callerFunction = $caller['function'] ?? 'unknown';

    $payload = [
        'success'      => false,
        'responseCode' => $code,
        'message'      => $message
    ];

    // Convert message safely for logs
    $safeMessage = is_array($message) ? json_encode($message) : $message;

    logActivity("Response error: {$safeMessage} | Code: {$code} | Called from: {$callerFile}:{$callerLine} ({$callerFunction})");

    echo json_encode($payload);
    exit();
}

function json_success($message, $data = null, $code = 200)
{
    http_response_code($code);

    // Get caller location for success logging
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = $backtrace[0];
    $callerFile = basename($caller['file'] ?? 'unknown');
    $callerLine = $caller['line'] ?? 0;
    $callerFunction = $caller['function'] ?? 'unknown';

    $payload = [
        'success'      => true,
        'responseCode' => $code,
        'message'      => $message
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    // Convert message to string safely for logging
    $safeMessage = is_array($message) ? json_encode($message) : $message;

    $logMessage = "Response success: {$safeMessage} | Code: {$code} | Called from: {$callerFile}:{$callerLine} ({$callerFunction})";

    if ($data !== null) {
        $logMessage .= " | Data: " . json_encode($data);
    }

    logActivity($logMessage);

    echo json_encode($payload);
    exit();
}

function sanitize_inputs(array $data) : array {
    return array_map(function($v){
        if (is_string($v)) return trim($v);
        return $v;
    }, $data);
}

function validate_phone(string $phone) : bool {
    // Accept only Nigerian 11-digit numbers
    return (bool) preg_match('/^\d{11}$/', $phone);
}

function random_unique_id() {
    // 16 hex chars (8 bytes) - enough for uniqueness
     return strtoupper(bin2hex(random_bytes(2)));
}
// =========================================================
//  SECURITY UTILITIES WITH RATE LIMITING + IP LOGGING
// =========================================================

// Get user IP safely
function getClientIP()
{
    foreach ([
        'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP', 'REMOTE_ADDR'
    ] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            return trim($ip);
        }
    }
    return 'UNKNOWN_IP';
}
// =========================================================
//  RATE LIMITING (per IP + per endpoint)
// =========================================================
function rateLimit($key, $limit = 20, $seconds = 60)
{
    $ip = getClientIP();
    $identifier = "ratelimit_{$key}_{$ip}";

    if (!isset($_SESSION)) session_start();

    if (!isset($_SESSION[$identifier])) {
        $_SESSION[$identifier] = [
            'count' => 0,
            'expires' => time() + $seconds
        ];
    }

    // Reset counter if expired
    if (time() > $_SESSION[$identifier]['expires']) {
        $_SESSION[$identifier] = [
            'count' => 0,
            'expires' => time() + $seconds
        ];
    }

    $_SESSION[$identifier]['count']++;

    if ($_SESSION[$identifier]['count'] > $limit) {
        logActivity("RATE LIMIT BLOCKED | IP: {$ip} | Key: {$key} | Limit {$limit}/{$seconds}s");

        json_error("Rate limit exceeded. Try again later.", 429);
        exit;
    }

    // Log each hit for audit visibility
    logActivity("Rate limit check OK | IP: {$ip} | Key: {$key} | Count: {$_SESSION[$identifier]['count']}/{$limit}");
}

/**
 * Sanitize number input by removing commas and validating
 * @param mixed $value The input value
 * @param bool $allowNull Whether to allow null values
 * @param float $min Minimum allowed value
 * @param float $max Maximum allowed value
 * @return float|null Sanitized number or null
 * @throws Exception if validation fails
 */
function sanitizeNumberWithCommas($value, $allowNull = false, $min = null, $max = null) {
    // Handle empty values
    if ($value === null || $value === '' || $value === 'null') {
        if ($allowNull) {
            return null;
        }
        throw new Exception("Number value is required.");
    }
    
    // Remove commas and any whitespace
    $cleaned = preg_replace('/[,\s]/', '', trim($value));
    
    // Check if it's a valid number
    if (!is_numeric($cleaned)) {
        throw new Exception("Invalid number format: {$value}");
    }
    
    // Convert to float
    $number = (float) $cleaned;
    
    // Apply min/max validation if provided
    if ($min !== null && $number < $min) {
        throw new Exception("Value must be at least {$min}.");
    }
    
    if ($max !== null && $number > $max) {
        throw new Exception("Value cannot exceed {$max}.");
    }
    
    return $number;
}
