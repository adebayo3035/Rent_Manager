<?php
function logActivity($message) {
    $logFile = __DIR__ . '/activity_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    
    // Get user ID (handling cases where it might not be set)
    $userId = isset($_SESSION['unique_id']) ? $_SESSION['unique_id'] : 'guest';
    // Alternative if using JWT or other auth:
    // $userId = isset($GLOBALS['current_user_id']) ? $GLOBALS['current_user_id'] : 'unknown';
    
    // Get request information
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $currentUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    
    // Get caller information
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $caller = $backtrace[0] ?? ['file' => 'unknown', 'line' => 0];
    $file = basename($caller['file']); // Just show filename without full path
    $line = $caller['line'];
    
    // Format the log entry with proper spacing
    $logMessage = sprintf(
        "[%s]\n" .
        "UserID: %s\n" .
        "Method: %s\n" .
        "URL: %s\n" .
        "Source: %s (Line %d)\n" .
        "Message: %s\n" .
        "----------------------------------------\n\n",
        $timestamp,
        $userId,
        $requestMethod,
        $currentUrl,
        $file,
        $line,
        trim($message)
    );
    
    // Write to log file with error handling
    try {
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Failed to write to log file: " . $e->getMessage());
    }
}