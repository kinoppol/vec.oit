<?php
// Local credentials file written by install.php (gitignored). Env vars still win.
$__dbCfg = is_file(__DIR__ . '/db.config.php') ? (require __DIR__ . '/db.config.php') : [];

define('DB_HOST',    getenv('DB_HOST') ?: ($__dbCfg['host'] ?? 'localhost'));
define('DB_NAME',    getenv('DB_NAME') ?: ($__dbCfg['name'] ?? 'vec_oit'));
define('DB_USER',    getenv('DB_USER') ?: ($__dbCfg['user'] ?? 'root'));
define('DB_PASS',    getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($__dbCfg['pass'] ?? ''));
define('DB_CHARSET', 'utf8mb4');
unset($__dbCfg);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
