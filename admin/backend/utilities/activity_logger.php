<?php
function logActivity($message) {
    $logFile = __DIR__ . '/activity_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    
    // Get user ID (handling cases where it might not be set)
    $userId = isset($_SESSION['unique_id']) ? $_SESSION['unique_id'] : 'guest';
    
    // Get request information
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $currentUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    
    // Get caller information - go deeper to find actual caller
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    
    // Find the actual caller (skip logActivity itself and any wrapper functions)
    $caller = $backtrace[0];
    $skipFunctions = ['logActivity', 'json_error', 'json_success'];
    foreach ($backtrace as $trace) {
        if (isset($trace['function']) && !in_array($trace['function'], $skipFunctions)) {
            $caller = $trace;
            break;
        }
    }
    
    $file = basename($caller['file'] ?? 'unknown');
    $line = $caller['line'] ?? 0;
    $function = $caller['function'] ?? 'global';
    
    // Format the log entry with proper spacing
    $logMessage = sprintf(
        "[%s]\n" .
        "UserID: %s\n" .
        "Method: %s\n" .
        "URL: %s\n" .
        "Source: %s (Line %d) - Function: %s\n" .
        "Message: %s\n" .
        "----------------------------------------\n\n",
        $timestamp,
        $userId,
        $requestMethod,
        $currentUrl,
        $file,
        $line,
        $function,
        trim($message)
    );
    
    // Write to log file with error handling
    try {
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Failed to write to log file: " . $e->getMessage());
    }
}

