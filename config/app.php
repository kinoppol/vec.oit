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
// Per-file upload ceiling in MB, used when app_settings has no override.
// A centraladmin changes the live value in the ตั้งค่าระบบ card; read it with
// max_upload_mb() / max_upload_bytes(), never MAX_UPLOAD_DEFAULT directly.
// It must stay under the server's upload_max_filesize (and a whole batch under
// post_max_size) — both are 100M in production.
define('MAX_UPLOAD_DEFAULT_MB', 50);
define('MAX_UPLOAD_CEILING_MB', 100);

// RMS external user-import endpoint. The origin (e.g. http://rms.rvc.ac.th) is stored
// per school in schools.rms_base_url; this path/query is fixed (hardcoded).
define('RMS_API_PATH', '/api_connection.php?app_name=nutty&data=people');

// Derive base URL from server vars
(function () {
    // Detect HTTPS even behind a reverse proxy that terminates SSL,
    // otherwise APP_URL becomes http:// and AJAX fetch is blocked as mixed content.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root   = str_replace('\\', '/', APP_ROOT);
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rel    = $docRoot ? str_replace($docRoot, '', $root) : '/vec.oit';
    define('APP_URL', rtrim($scheme . '://' . $host . $rel, '/'));
})();
