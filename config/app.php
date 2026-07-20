<?php
// Bootstrap — include first in every entry point

// ── Error handling ────────────────────────────────────────────
// Always log to a file so production 500s are diagnosable.
// Show errors on screen only when APP_DEBUG=1 (env) — never by default.
// Enable via env APP_DEBUG=1, or by creating an empty `.debug` file in the app root
$__appDebug = getenv('APP_DEBUG') === '1' || is_file(dirname(__DIR__) . '/.debug');
error_reporting(E_ALL);
ini_set('log_errors', '1');
$__logDir = dirname(__DIR__) . '/logs';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
if (is_dir($__logDir) && is_writable($__logDir)) {
    ini_set('error_log', $__logDir . '/app-error.log');
}
ini_set('display_errors', $__appDebug ? '1' : '0');

if (session_status() === PHP_SESSION_NONE) {
    session_name('oit_sess');
    session_set_cookie_params([
        'lifetime' => 86400,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('APP_NAME', 'ระบบเปิดเผยข้อมูลสาธารณะ (OIT)');
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('MAX_UPLOAD', 10 * 1024 * 1024); // 10 MB

// Derive base URL from server vars
(function () {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root   = str_replace('\\', '/', APP_ROOT);
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rel    = $docRoot ? str_replace($docRoot, '', $root) : '/vec.oit';
    define('APP_URL', rtrim($scheme . '://' . $host . $rel, '/'));
})();
