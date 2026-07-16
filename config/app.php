<?php
// Bootstrap — include first in every entry point

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
