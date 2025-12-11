<?php
function json_error($message, $code = 400)
{
    http_response_code($code);

    $payload = [
        'success'      => false,
        'responseCode' => $code,
        'message'      => $message
    ];

    // Convert message safely for logs
    $safeMessage = is_array($message) ? json_encode($message) : $message;

    logActivity("Response error: {$safeMessage} | Code: {$code}");

    echo json_encode($payload);
    exit();
}


function json_success($message, $data = null, $code = 200)
{
    http_response_code($code);

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

    $logMessage = "Response success: {$safeMessage} | Code: {$code}";

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
     return strtoupper(bin2hex(random_bytes(4)));
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
