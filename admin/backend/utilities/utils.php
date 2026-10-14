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

// utilities/utils.php

/**
 * Generate a unique admin ID that doesn't already exist in the database
 * 
 * @param mysqli $conn Database connection
 * @param int $min Minimum value (default: 100)
 * @param int $max Maximum value (default: 999999)
 * @param int $maxAttempts Maximum attempts to find unique ID
 * @return int|null Unique ID or null if failed
 */
function generateUniqueAdminId($conn, $min = 100, $max = 999999, $maxAttempts = 10) {
    $attempts = 0;
    
    while ($attempts < $maxAttempts) {
        // Generate a random ID
        $id = random_int($min, $max);
        
        // Check if ID exists in admin_tbl
        $checkStmt = $conn->prepare("SELECT unique_id FROM admin_tbl WHERE unique_id = ? LIMIT 1");
        if (!$checkStmt) {
            logActivity("ERROR: Failed to prepare unique_id check: " . $conn->error);
            return null;
        }
        
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            $checkStmt->close();
            logActivity("Generated unique admin ID: {$id} (attempt: " . ($attempts + 1) . ")");
            return $id;
        }
        
        $checkStmt->close();
        $attempts++;
        logActivity("ID {$id} already exists, retrying... (attempt {$attempts}/{$maxAttempts})");
    }
    
    logActivity("ERROR: Failed to generate unique admin ID after {$maxAttempts} attempts");
    return null;
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

/**
 * Validate a payment reference against the payment method.
 *
 * Format: PREFIX-YYYYMMDD-HEX(6)
 *   BNK-20261014-8F3A2C
 *   CSH-20261014-4D9E1B
 *
 * @param string $ref     The reference to validate
 * @param string $method  The payment method it claims to be for
 * @return array { valid: bool, message: string, normalized: string }
 */
function validatePaymentReference(string $ref, string $method): array
{
    $ref = strtoupper(trim($ref));

    if ($ref === '') {
        return ['valid' => false, 'message' => 'Reference is required', 'normalized' => ''];
    }

    // Length guard (avoid regex on huge strings)
    if (strlen($ref) > 30) {
        return ['valid' => false, 'message' => 'Reference is too long', 'normalized' => ''];
    }

    // Format: PREFIX-YYYYMMDD-HEX6
    if (!preg_match('/^([A-Z]{3})-(\d{8})-([0-9A-F]{6})$/', $ref, $m)) {
        return [
            'valid' => false,
            'message' => 'Reference must be in format XXX-YYYYMMDD-XXXXXX',
            'normalized' => '',
        ];
    }

    $prefix = $m[1];
    $datePart = $m[2];

    // Validate the date part is a real date
    $y = (int) substr($datePart, 0, 4);
    $mo = (int) substr($datePart, 4, 2);
    $d = (int) substr($datePart, 6, 2);
    if (!checkdate($mo, $d, $y)) {
        return ['valid' => false, 'message' => 'Reference contains an invalid date', 'normalized' => ''];
    }

    // Reject dates more than 7 days in the future or older than 1 year
    $ts = mktime(0, 0, 0, $mo, $d, $y);
    $now = time();
    if ($ts > $now + (7 * 86400)) {
        return ['valid' => false, 'message' => 'Reference date is too far in the future', 'normalized' => ''];
    }
    if ($ts < $now - (365 * 86400)) {
        return ['valid' => false, 'message' => 'Reference is more than a year old', 'normalized' => ''];
    }

    // Prefix must match the method
    $expectedPrefixes = [
        'bank_transfer' => 'BNK',
        'cash'          => 'CSH',
        'cheque'        => 'CHQ',
        'card'          => 'CRD',
        'wallet_offset' => 'WLT',
        'other'         => 'OTH',
    ];

    $methodSlug = strtolower(trim($method));
    $expectedPrefix = $expectedPrefixes[$methodSlug] ?? null;

    if ($expectedPrefix && $prefix !== $expectedPrefix) {
        return [
            'valid' => false,
            'message' => "Reference prefix '{$prefix}' does not match payment method '{$methodSlug}'",
            'normalized' => '',
        ];
    }

    return ['valid' => true, 'message' => '', 'normalized' => $ref];
}

function generatePaymentReference(string $method): string
{
    $prefixes = [
        'bank_transfer' => 'BNK',
        'cash'          => 'CSH',
        'cheque'        => 'CHQ',
        'card'          => 'CRD',
        'wallet_offset' => 'WLT',
        'other'         => 'OTH',
    ];
    $prefix = $prefixes[strtolower($method)] ?? 'PMT';
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}
