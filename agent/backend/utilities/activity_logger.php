<?php
// utilities/activity_logger.php

/**
 * Central activity logger.
 *
 * Usage:
 *   logActivity("User logged in");                     // default: INFO
 *   logActivity("Payment failed", 'ERROR');            // with level
 *   logActivity("Order created", 'INFO', ['id' => 5]); // with context
 *   logActivity("Debug info", 'DEBUG');                // only if LOG_LEVEL allows
 */

// ==================== CONFIGURATION ====================
// Move these to config.php if you prefer
if (!defined('LOG_DIR')) {
    define('LOG_DIR', __DIR__ . '/../logs/');
}
if (!defined('LOG_LEVEL')) {
    // DEBUG < INFO < WARN < ERROR < FATAL
    define('LOG_LEVEL', 'INFO');          // production: INFO ; dev: DEBUG
}
if (!defined('LOG_ROTATION')) {
    define('LOG_ROTATION', 'daily');      // 'hourly' | 'daily' | 'size' | 'single'
}
if (!defined('LOG_MAX_BYTES')) {
    define('LOG_MAX_BYTES', 10 * 1024 * 1024); // 10 MB, for 'size' rotation
}
if (!defined('LOG_FORMAT')) {
    define('LOG_FORMAT', 'text');         // 'text' | 'json'
}
if (!defined('LOG_REDACT_KEYS')) {
    define('LOG_REDACT_KEYS', [
        'password', 'passwd', 'pwd', 'token', 'access_token',
        'api_key', 'secret', 'authorization', 'otp', 'pin',
    ]);
}

// ==================== LEVELS ====================
const LOG_LEVELS = [
    'DEBUG' => 10,
    'INFO'  => 20,
    'WARN'  => 30,
    'ERROR' => 40,
    'FATAL' => 50,
];

/**
 * Main logging entry point.
 *
 * @param string $message Human-readable message
 * @param string $level   DEBUG | INFO | WARN | ERROR | FATAL
 * @param array  $context Optional associative array of extra fields
 */
function logActivity(string $message, string $level = 'INFO', array $context = []): void
{
    $level = strtoupper($level);

    // Filter by configured minimum level
    if (!isset(LOG_LEVELS[$level])) {
        $level = 'INFO';
    }
    $minLevel = LOG_LEVELS[LOG_LEVEL] ?? LOG_LEVELS['INFO'];
    if (LOG_LEVELS[$level] < $minLevel) {
        return; // below threshold → skip
    }

    try {
        $entry = buildLogEntry($message, $level, $context);
        $file  = resolveLogFilePath($level);

        ensureDirectory(dirname($file));

        $line = LOG_FORMAT === 'json'
            ? json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : formatTextEntry($entry);

        // Single atomic append. Suppress warnings if disk is full, etc.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

    } catch (Throwable $e) {
        // Never let logging crash the app
        error_log('[logActivity] ' . $e->getMessage());
    }
}

// ==================== ENTRY BUILDER ====================
function buildLogEntry(string $message, string $level, array $context): array
{
    $sessionUserId = $_SESSION['unique_id'] ?? null;
    $sessionRole   = $_SESSION['role'] ?? null;

    // Fall back to whatever key your modules use
    if (!$sessionUserId) {
        $sessionUserId = $_SESSION['client_code']
                      ?? $_SESSION['tenant_id']
                      ?? $_SESSION['agent_code']
                      ?? null;
    }

    $requestId = getOrCreateRequestId();

    return [
        'ts'        => date('Y-m-d H:i:s') . '.' . sprintf('%03d', (int)(explode(' ', microtime())[0] * 1000)),
        'level'     => $level,
        'request_id' => $requestId,
        'user_id'   => $sessionUserId ?: 'guest',
        'role'      => $sessionRole ?: 'guest',
        'ip'        => getClientIPForLog(),
        'method'    => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'url'       => buildRequestUrl(),
        'file'      => basename(findCallerFile()),
        'line'      => findCallerLine(),
        'function'  => findCallerFunction(),
        'message'   => $message,
        'context'   => redactSensitive($context),
        'duration_ms' => isset($GLOBALS['__REQUEST_START']) 
            ? round((microtime(true) - $GLOBALS['__REQUEST_START']) * 1000, 2) 
            : null,
    ];
}

// ==================== CALLER DETECTION ====================
function findCallerFile(): string
{
    $skip = ['logActivity', 'json_error', 'json_success', 'logInfo', 'logError', 'logDebug', 'logWarn'];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $t) {
        if (isset($t['function']) && !in_array($t['function'], $skip, true)) {
            return $t['file'] ?? 'unknown';
        }
    }
    return 'unknown';
}

function findCallerLine(): int
{
    $skip = ['logActivity', 'json_error', 'json_success', 'logInfo', 'logError', 'logDebug', 'logWarn'];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $t) {
        if (isset($t['function']) && !in_array($t['function'], $skip, true)) {
            return $t['line'] ?? 0;
        }
    }
    return 0;
}

function findCallerFunction(): string
{
    $skip = ['logActivity', 'json_error', 'json_success', 'logInfo', 'logError', 'logDebug', 'logWarn'];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $t) {
        if (isset($t['function']) && !in_array($t['function'], $skip, true)) {
            return $t['function'];
        }
    }
    return 'global';
}

// ==================== REQUEST CONTEXT ====================
function getOrCreateRequestId(): string
{
    if (empty($GLOBALS['__REQUEST_ID'])) {
        $GLOBALS['__REQUEST_ID'] = bin2hex(random_bytes(8));
    }
    return $GLOBALS['__REQUEST_ID'];
}

function buildRequestUrl(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli:' . implode(' ', $_SERVER['argv'] ?? []);
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST']   ?? 'unknown';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return "{$scheme}://{$host}{$uri}";
}

function getClientIPForLog(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    return 'unknown';
}

// ==================== REDACTION ====================
function redactSensitive(array $data): array
{
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $data[$k] = redactSensitive($v);
            continue;
        }
        if (in_array(strtolower((string)$k), LOG_REDACT_KEYS, true)) {
            $data[$k] = '***REDACTED***';
        }
    }
    return $data;
}

// ==================== FILE RESOLUTION / ROTATION ====================
function resolveLogFilePath(string $level): string
{
    $subdir = strtolower($level) . '/'; // logs/error/, logs/info/, etc.

    switch (LOG_ROTATION) {
        case 'hourly':
            $file = 'app-' . date('Y-m-d_H') . '.log';
            break;
        case 'daily':
            $file = 'app-' . date('Y-m-d') . '.log';
            break;
        case 'size':
            $file = 'app.log';
            $full = LOG_DIR . $subdir . $file;
            if (is_file($full) && filesize($full) > LOG_MAX_BYTES) {
                @rename($full, $full . '.' . date('Ymd-His'));
            }
            break;
        case 'single':
        default:
            $file = 'activity.log';
            break;
    }

    // Group by level in subdirectories for easier triage
    return LOG_DIR . $subdir . $file;
}

function ensureDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

// ==================== TEXT FORMATTER ====================
function formatTextEntry(array $e): string
{
    $ctx = '';
    if (!empty($e['context'])) {
        $ctx = ' | ctx=' . json_encode($e['context'], JSON_UNESCAPED_SLASHES);
    }
    $dur = $e['duration_ms'] !== null ? " | {$e['duration_ms']}ms" : '';

    return sprintf(
        "[%s] %-5s req=%s user=%s role=%s ip=%s %s %s → %s:%d (%s)%s\n  msg: %s%s\n",
        $e['ts'],
        $e['level'],
        $e['request_id'],
        $e['user_id'],
        $e['role'],
        $e['ip'],
        $e['method'],
        $e['url'],
        $e['file'],
        $e['line'],
        $e['function'],
        $dur,
        $e['message'],
        $ctx
    );
}

// ==================== LEVEL-SPECIFIC HELPERS ====================
function logDebug(string $m, array $c = []): void { logActivity($m, 'DEBUG', $c); }
function logInfo (string $m, array $c = []): void { logActivity($m, 'INFO',  $c); }
function logWarn (string $m, array $c = []): void { logActivity($m, 'WARN',  $c); }
function logError(string $m, array $c = []): void { logActivity($m, 'ERROR', $c); }
function logFatal(string $m, array $c = []): void { logActivity($m, 'FATAL', $c); }

// ==================== REQUEST TIMER ====================
// Call this once at the top of each entry point (index.php or bootstrap)
if (!isset($GLOBALS['__REQUEST_START'])) {
    $GLOBALS['__REQUEST_START'] = microtime(true);
}
// ==================== ERROR / EXCEPTION / SHUTDOWN BRIDGE ====================
// Must be included once per request (require_once is fine).
// Installs handlers that funnel PHP errors, uncaught exceptions,
// and fatal shutdown errors into logActivity().

if (!defined('LOG_ERROR_BRIDGE_INSTALLED')) {
    define('LOG_ERROR_BRIDGE_INSTALLED', true);

    // ---- Map PHP error constants to your log levels ----
    $GLOBALS['__LOG_ERR_LEVEL_MAP'] = [
        E_ERROR             => 'FATAL',
        E_PARSE             => 'FATAL',
        E_CORE_ERROR        => 'FATAL',
        E_COMPILE_ERROR     => 'FATAL',
        E_USER_ERROR        => 'FATAL',
        E_RECOVERABLE_ERROR => 'ERROR',
        E_WARNING           => 'WARN',
        E_USER_WARNING      => 'WARN',
        E_NOTICE            => 'INFO',
        E_USER_NOTICE       => 'INFO',
        E_DEPRECATED        => 'DEBUG',
        E_USER_DEPRECATED   => 'DEBUG',
        E_STRICT            => 'DEBUG',
    ];

    /**
     * set_error_handler callback.
     * Returning false lets PHP's default handler also run
     * (so nothing changes behaviorally — we only ADD logging).
     */
    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
        // Respect @-suppression
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $map   = $GLOBALS['__LOG_ERR_LEVEL_MAP'];
        $level = $map[$errno] ?? 'ERROR';
        $type  = log_error_name($errno);

        try {
            logActivity(
                "PHP {$type}: {$errstr}",
                $level,
                [
                    'errno'    => $errno,
                    'errfile'  => $errfile,
                    'errline'  => $errline,
                    'backtrace' => log_short_backtrace(6),
                ]
            );
        } catch (Throwable $_) { /* never crash inside the handler */ }

        return false; // let PHP continue its normal handling
    });

    /**
     * set_exception_handler callback — catches uncaught Throwable.
     */
    set_exception_handler(function (Throwable $e): void {
        try {
            logActivity(
                'UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage(),
                'FATAL',
                [
                    'exception' => get_class($e),
                    'code'      => $e->getCode(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => log_short_backtrace(15, $e->getTrace()),
                ]
            );
        } catch (Throwable $_) { /* ignore */ }

        // Emit a safe JSON error response for API endpoints, or plain text
        $isJson = (
            (defined('LOG_FORMAT') && LOG_FORMAT === 'json')
            || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'json') !== false)
            || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'json') !== false)
            || preg_match('#/(api|backend|ajax)/#', $_SERVER['REQUEST_URI'] ?? '')
        );

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . ($isJson ? 'application/json' : 'text/plain'));
        }

        echo $isJson
            ? json_encode([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again later.',
                'request_id' => getOrCreateRequestId(),
            ])
            : 'An unexpected error occurred.';
    });

    /**
     * register_shutdown_function — catches the fatals PHP never lets
     * set_error_handler see (E_ERROR, E_PARSE, E_COMPILE_ERROR, OOM, timeouts).
     */
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if (!$err) return;

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) return;

        $map   = $GLOBALS['__LOG_ERR_LEVEL_MAP'];
        $level = $map[$err['type']] ?? 'FATAL';
        $type  = log_error_name($err['type']);

        try {
            logActivity(
                "FATAL {$type}: {$err['message']}",
                $level,
                [
                    'type'    => $err['type'],
                    'file'    => $err['file'],
                    'line'    => $err['line'],
                ]
            );
        } catch (Throwable $_) { /* ignore */ }

        // If the app already sent a response (headers_sent), don't
        // clobber it — but if NOT, emit a clean error body so the
        // frontend doesn't get "Unexpected end of JSON input".
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'A fatal error occurred. Please try again later.',
                'request_id' => getOrCreateRequestId(),
            ]);
        }
    });

    // ---- Helpers used by the bridge ----

    function log_error_name(int $errno): string
    {
        static $names = [
            E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE', E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];
        return $names[$errno] ?? ('E_UNKNOWN(' . $errno . ')');
    }

    /**
     * Compact backtrace: [{file, line, fn}...] — no arguments
     * (args can be huge and leak secrets).
     */
    function log_short_backtrace(int $limit = 10, ?array $trace = null): array
    {
        $trace = $trace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
        $out   = [];
        foreach ($trace as $t) {
            $out[] = [
                'file' => isset($t['file']) ? basename($t['file']) : null,
                'line' => $t['line'] ?? null,
                'fn'   => (isset($t['class']) ? $t['class'] . ($t['type'] ?? '::') : '')
                        . ($t['function'] ?? ''),
            ];
        }
        return $out;
    }
}