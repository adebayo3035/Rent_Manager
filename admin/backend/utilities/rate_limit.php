<?php
// utilities/rate_limit.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

/**
 * Enhanced rate limiting with centralized configuration
 * 
 * @param string $endpoint_key The endpoint identifier (auto-detected if not provided)
 * @param array|null $override_config Optional override config for this specific call
 * @return array Rate limit info (limit, remaining, reset)
 */
function rateLimiter($endpoint_key = null, $override_config = null) {
    static $config = null;
    
    // Load configuration (only once)
    if ($config === null) {
        $configPath = __DIR__ . '/rate_limit_config.php';
        $config = file_exists($configPath) ? include $configPath : ['endpoints' => []];
        
        // Ensure default config exists
        if (!isset($config['default'])) {
            $config['default'] = ['limit' => 20, 'seconds' => 60];
        }
        
        // Ensure endpoints array exists
        if (!isset($config['endpoints'])) {
            $config['endpoints'] = [];
        }
    }
    
    // Determine the endpoint key
    if ($endpoint_key === null) {
        // Auto-detect endpoint key from script name
        $script_name = basename($_SERVER['PHP_SELF'], '.php');
        $endpoint_key = $script_name;
    }
    
    // Get endpoint-specific config or default
    $endpoint_config = $config['endpoints'][$endpoint_key] ?? $config['default'];
    
    // Override with passed config if provided
    if ($override_config !== null) {
        if (is_array($override_config)) {
            $limit = $override_config['limit'] ?? $endpoint_config['limit'];
            $seconds = $override_config['seconds'] ?? $endpoint_config['seconds'];
        } elseif (is_int($override_config)) {
            // Backward compatibility: if numeric, treat as limit with default seconds
            $limit = $override_config;
            $seconds = $endpoint_config['seconds'];
        }
    } else {
        $limit = $endpoint_config['limit'];
        $seconds = $endpoint_config['seconds'];
    }
    
    $ip = getClientIPAddr();
    $identifier = "ratelimit_{$endpoint_key}_{$ip}";
    
    if (!isset($_SESSION)) session_start();
    
    // Check if IP is globally blocked
    $block_identifier = "blocked_ip_{$ip}";
    if (isset($_SESSION[$block_identifier]) && time() < $_SESSION[$block_identifier]) {
        $remaining = $_SESSION[$block_identifier] - time();
        $minutes = ceil($remaining / 60);
        logActivity("RATE LIMIT BLOCKED (IP block) | IP: {$ip} | Endpoint: {$endpoint_key} | Remaining: {$minutes}m");
        
        json_error("Too many failed attempts. IP blocked for {$minutes} minutes.", 429);
        exit;
    }
    
    // Initialize or reset expired counter
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
    $current_count = $_SESSION[$identifier]['count'];
    $remaining_attempts = $limit - $current_count;
    
    // Check if rate limit exceeded
    if ($current_count > $limit) {
        // Log the violation
        $log_message = "RATE LIMIT BLOCKED | IP: {$ip} | Endpoint: {$endpoint_key} | Limit: {$limit}/{$seconds}s | Attempt: {$current_count}";
        logActivity($log_message);
        
        // Track violations for IP blocking
        if (isset($config['security']['block_ip_after']) && $config['security']['block_ip_after'] > 0) {
            $violation_key = "violations_{$ip}";
            if (!isset($_SESSION[$violation_key])) {
                $_SESSION[$violation_key] = 0;
            }
            $_SESSION[$violation_key]++;
            
            if ($_SESSION[$violation_key] >= $config['security']['block_ip_after']) {
                $block_duration = $config['security']['block_duration'] ?? 3600;
                $_SESSION[$block_identifier] = time() + $block_duration;
                logActivity("IP BLOCKED | IP: {$ip} | Duration: {$block_duration}s | Violations: {$_SESSION[$violation_key]}");
            }
        }
        
        // Return 429 Too Many Requests
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => "Too many Request. Please try again later.",
            'retry_after' => $_SESSION[$identifier]['expires'] - time(),
            'limit' => $limit,
            'remaining' => 0
        ]);
        exit;
    }
    
    // Log successful attempt (optional)
    if (isset($config['security']['enable_logging']) && $config['security']['enable_logging']) {
        logActivity("Rate limit OK | IP: {$ip} | Endpoint: {$endpoint_key} | Count: {$current_count}/{$limit} | Window: {$seconds}s");
    }
    
    // Return remaining attempts for potential frontend use
    return [
        'limit' => $limit,
        'remaining' => $remaining_attempts,
        'reset' => $_SESSION[$identifier]['expires'],
        'endpoint' => $endpoint_key
    ];
}

/**
 * Get client IP address
 */
function getClientIPAddr() {
    $ipaddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_X_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if(isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}