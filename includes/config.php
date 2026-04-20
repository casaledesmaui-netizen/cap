<?php
// config.php — Loaded first on every page.

(function() {
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            if (!array_key_exists(trim($k), $_ENV)) {
                $_ENV[trim($k)] = trim($v);
                putenv(trim($k) . '=' . trim($v));
            }
        }
    }
})();

// Dynamic BASE_URL
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'];
    $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $knownSubs = ['/modules/', '/includes/', '/assets/', '/api/', '/database/', '/logs/'];
    $root = $script;
    foreach ($knownSubs as $sub) {
        $pos = strpos($root, $sub);
        if ($pos !== false) { $root = substr($root, 0, $pos); break; }
    }
    if (substr($root, -4) === '.php') $root = rtrim(dirname($root), '/');
    $root = rtrim($root, '/') . '/';
    define('BASE_URL', $scheme . '://' . $host . $root);
} else {
    define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? 'http://localhost/cap/', '/') . '/');
}
define('APP_NAME',        $_ENV['APP_NAME']       ?? 'Dental Clinic Management System');
define('APP_DEBUG',       ($_ENV['APP_DEBUG']      ?? 'false') === 'true');
define('SESSION_LIFETIME', 28800);

date_default_timezone_set('Asia/Manila');

$_debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
ini_set('display_errors',         $_debug ? 1 : 0);
ini_set('display_startup_errors', $_debug ? 1 : 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}

// Debug helper — shows full error details when APP_DEBUG=true
function _show_debug_error(string $title, string $message, string $file = '', int $line = 0, string $trace = ''): void {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<div style="background:#fff;color:#000;padding:30px;font-family:monospace;max-width:900px;margin:40px auto;border:2px solid red;border-radius:8px;">';
    echo '<h2 style="color:red;margin-top:0;">⚠ ' . htmlspecialchars($title) . '</h2>';
    echo '<p style="font-size:1.1em;">' . htmlspecialchars($message) . '</p>';
    if ($file) echo '<p><b>File:</b> ' . htmlspecialchars($file) . ' &nbsp; <b>Line:</b> ' . $line . '</p>';
    if ($trace) echo '<pre style="background:#f1f5f9;padding:16px;border-radius:6px;overflow:auto;font-size:0.85em;">' . htmlspecialchars($trace) . '</pre>';
    echo '</div></body></html>';
}

// Catch unhandled exceptions
set_exception_handler(function ($e) use ($_debug) {
    error_log('[EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } elseif ($_debug) {
        _show_debug_error('Exception', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    } else {
        include dirname(__DIR__) . '/error.php';
    }
    exit();
});

// Catch fatal errors
register_shutdown_function(function () use ($_debug) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            http_response_code(500);
            if ($_debug) {
                _show_debug_error('Fatal Error', $e['message'], $e['file'], $e['line']);
            } else {
                include dirname(__DIR__) . '/error.php';
            }
        }
    }
});

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
