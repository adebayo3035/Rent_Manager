<?php
// ==================== RESPONSE FUNCTIONS ====================

/**
 * Send a success response
 * 
 * @param mixed $data The response data
 * @param string $message Optional success message
 * @param int $code HTTP status code
 * @param array $meta Optional metadata (pagination, etc.)
 */
function json_success($data = null, $message = "Operation successful", $code = 200, $meta = null)
{
    http_response_code($code);
    
    $payload = [
        'success' => true,
        'status_code' => $code,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Add data if provided
    if ($data !== null) {
        $payload['data'] = $data;
    }
    
    // Add metadata if provided (useful for pagination)
    if ($meta !== null) {
        $payload['meta'] = $meta;
    }
    
    // Log the response
    $logMessage = "Response Success [{$code}]: {$message}";
    if ($data !== null) {
        $logMessage .= " | Data: " . json_encode($data);
    }
    logActivity($logMessage);
    
    echo json_encode($payload);
    exit();
}

/**
 * Send an error response
 * 
 * @param string $message Error message
 * @param int $code HTTP status code
 * @param mixed $errors Optional detailed error information
 * @param string $error_code Optional error code for client-side handling
 */
function json_error($message, $code = 400, $errors = null, $error_code = null)
{
    http_response_code($code);
    
    $payload = [
        'success' => false,
        'status_code' => $code,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Add error code if provided
    if ($error_code !== null) {
        $payload['error_code'] = $error_code;
    }
    
    // Add detailed errors if provided (for validation errors)
    if ($errors !== null) {
        $payload['errors'] = $errors;
    }
    
    // Log the error
    $logMessage = "Response Error [{$code}]: {$message}";
    if ($errors !== null) {
        $logMessage .= " | Details: " . json_encode($errors);
    }
    logActivity($logMessage);
    
    echo json_encode($payload);
    exit();
}

/**
 * Send a validation error response
 * 
 * @param array $validation_errors Array of validation errors
 * @param string $message Optional custom message
 * @param int $code HTTP status code
 */
function json_validation_error($validation_errors, $message = "Validation failed", $code = 422)
{
    return json_error($message, $code, $validation_errors, 'VALIDATION_ERROR');
}

/**
 * Send a paginated response
 * 
 * @param array $data The paginated data
 * @param int $total Total number of items
 * @param int $page Current page
 * @param int $limit Items per page
 * @param string $message Optional success message
 */
function json_paginated($data, $total, $page, $limit, $message = "Data retrieved successfully")
{
    $total_pages = ceil($total / $limit);
    
    $meta = [
        'pagination' => [
            'current_page' => (int)$page,
            'per_page' => (int)$limit,
            'total_items' => (int)$total,
            'total_pages' => (int)$total_pages,
            'has_next_page' => $page < $total_pages,
            'has_previous_page' => $page > 1
        ]
    ];
    
    return json_success($data, $message, 200, $meta);
}

/**
 * Send a created response (for POST requests that create resources)
 * 
 * @param mixed $data The created resource data
 * @param string $message Success message
 * @param int $code HTTP status code (default 201)
 */
function json_created($data = null, $message = "Resource created successfully", $code = 201)
{
    return json_success($data, $message, $code);
}

/**
 * Send a no content response (for DELETE requests)
 * 
 * @param string $message Success message
 */
function json_no_content($message = "Resource deleted successfully")
{
    return json_success(null, $message, 204);
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
/**
 * Sanitize input data
 * @param mixed $data The data to sanitize
 * @return mixed Sanitized data
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
