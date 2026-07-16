<?php
/**
 * migrate.php — Versioned database migration runner for ระบบ OIT
 *
 * Runs every *.sql file under database/migrations/ in filename order,
 * skipping those already recorded in the `schema_migrations` table.
 * Idempotent: "already exists / duplicate key" errors are treated as skips,
 * so it is safe to run against a partially-imported database.
 *
 * Usage:
 *   CLI:  php migrate.php            run pending migrations
 *         php migrate.php status     show applied / pending without running
 *   Web:  http://localhost/vec.oit/migrate.php
 *
 * DB credentials come from config/database.php (env-overridable).
 */

require_once __DIR__ . '/config/database.php';

const MIGRATIONS_DIR = __DIR__ . '/database/migrations';

$isCli    = (PHP_SAPI === 'cli');
$statusOnly = $isCli && (($argv[1] ?? '') === 'status');

// ── Output helpers ────────────────────────────────────────────
$lines = [];
function out(string $msg, string $type = 'info'): void
{
    global $isCli, $lines;
    if ($isCli) {
        $prefix = match ($type) {
            'ok'   => "  \033[32m✓\033[0m ",
            'skip' => "  \033[33m•\033[0m ",
            'err'  => "  \033[31m✗\033[0m ",
            'head' => "\n",
            default => '  ',
        };
        echo $prefix . $msg . "\n";
    } else {
        $lines[] = [$type, $msg];
    }
}

// ── Connect (create DB if missing) ────────────────────────────
try {
    // Connect without dbname first so we can create it if needed
    $rootDsn = sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET);
    $bootPdo = new PDO($rootDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $bootPdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = db();
} catch (PDOException $e) {
    out('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage(), 'err');
    render($lines, $isCli, false);
    exit(1);
}

// ── Ensure tracking table ─────────────────────────────────────
$pdo->exec('
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id`         INT UNSIGNED AUTO_INCREMENT,
        `migration`  VARCHAR(255) NOT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
');

// ── Discover migrations ───────────────────────────────────────
$files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
sort($files, SORT_STRING);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

if (!$files) {
    out('ไม่พบไฟล์ migration ใน database/migrations/', 'err');
    render($lines, $isCli, false);
    exit(1);
}

// ── Status mode ───────────────────────────────────────────────
if ($statusOnly) {
    out('สถานะ Migration', 'head');
    foreach ($files as $f) {
        $name = basename($f);
        isset($applied[$name])
            ? out($name . '  (applied)', 'ok')
            : out($name . '  (pending)', 'skip');
    }
    exit(0);
}

// ── Run pending ───────────────────────────────────────────────
$ranAny = false;
$hadError = false;
out('เริ่มรัน migration', 'head');

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        out($name . ' — ข้าม (รันแล้ว)', 'skip');
        continue;
    }

    $sql   = file_get_contents($file);
    $stmts = parse_sql($sql);

    $ok = $skip = 0;
    $fatal = null;
    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            // PDO getCode() returns the SQLSTATE string; the MySQL error number
            // lives in errorInfo[1]. Match on that.
            $mysqlErr = (int) ($e->errorInfo[1] ?? 0);
            // 1050 table exists, 1060 dup column, 1061 dup key, 1062 dup entry, 1826/1022 dup FK/key
            if (in_array($mysqlErr, [1050, 1060, 1061, 1062, 1022, 1826], true)) {
                $skip++;
            } else {
                $fatal = $e->getMessage();
                break;
            }
        }
    }

    if ($fatal !== null) {
        out($name . ' — ผิดพลาด: ' . $fatal, 'err');
        $hadError = true;
        break; // stop on first hard failure; migrations are ordered
    }

    // Record as applied
    $ins = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
    $ins->execute([$name]);
    $ranAny = true;

    $detail = "{$ok} statement" . ($skip ? ", {$skip} ข้าม (มีอยู่แล้ว)" : '');
    out($name . " — สำเร็จ ({$detail})", 'ok');
}

if (!$hadError && !$ranAny) {
    out('ฐานข้อมูลเป็นเวอร์ชันล่าสุดแล้ว ไม่มี migration ค้าง', 'ok');
}

render($lines, $isCli, !$hadError);
exit($hadError ? 1 : 0);


// ── SQL splitter: drop comment lines, split on trailing ';' ───
function parse_sql(string $sql): array
{
    $stmts = [];
    $buf   = '';
    foreach (explode("\n", $sql) as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '--')) continue;
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            if ($s = trim($buf)) $stmts[] = $s;
            $buf = '';
        }
    }
    if ($s = trim($buf)) $stmts[] = $s;
    return $stmts;
}

// ── Web output ────────────────────────────────────────────────
function render(array $lines, bool $isCli, bool $success): void
{
    if ($isCli) return;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root   = str_replace('\\', '/', __DIR__);
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $rel    = $docRoot ? str_replace($docRoot, '', $root) : '/vec.oit';
    $appUrl = rtrim($scheme . '://' . $host . $rel, '/');
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Migration — ระบบ OIT</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:'Sarabun',sans-serif;background:#F4F1ED;color:#221C18;padding:40px 20px;line-height:1.6}
      .wrap{max-width:640px;margin:0 auto}
      .head{background:linear-gradient(135deg,#5C141C,#7A1E28 70%);color:#fff;padding:20px 26px;border-radius:14px 14px 0 0}
      .head h1{font-size:19px;font-weight:800}
      .head p{font-size:13px;opacity:.82;margin-top:2px}
      .body{background:#fff;border:1px solid #E7E0D9;border-top:none;border-radius:0 0 14px 14px;padding:22px 26px}
      .row{display:flex;gap:9px;align-items:flex-start;padding:8px 12px;border-radius:8px;font-size:14px;margin-bottom:6px;font-family:monospace}
      .row.ok{background:#E5F0EA;color:#1d5c3d}
      .row.skip{background:#FAF7F3;color:#6E645C}
      .row.err{background:#F6E3E1;color:#B23A3A}
      .ico{flex-shrink:0;font-family:sans-serif}
      .btns{margin-top:22px;display:flex;gap:12px}
      .btn{display:inline-block;padding:11px 22px;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none}
      .btn-p{background:#7A1E28;color:#fff}
      .btn-g{background:transparent;color:#6E645C;border:1px solid #D6CBC2}
    </style>
    </head>
    <body>
    <div class="wrap">
      <div class="head">
        <h1>ตัวรัน Migration ฐานข้อมูล</h1>
        <p>ระบบเปิดเผยข้อมูลสาธารณะ (OIT)</p>
      </div>
      <div class="body">
        <?php foreach ($lines as [$type, $msg]):
          $cls = in_array($type, ['ok','skip','err']) ? $type : 'skip';
          $ico = $type === 'ok' ? '✓' : ($type === 'err' ? '✗' : '•');
        ?>
        <div class="row <?= $cls ?>"><span class="ico"><?= $ico ?></span><span><?= htmlspecialchars($msg) ?></span></div>
        <?php endforeach; ?>
        <div class="btns">
          <a href="<?= htmlspecialchars($appUrl) ?>" class="btn btn-p">ไปยังระบบ</a>
          <a href="migrate.php?status" class="btn btn-g" onclick="return false" style="pointer-events:none;opacity:.5">
            <?= $success ? 'เสร็จสมบูรณ์' : 'มีข้อผิดพลาด' ?>
          </a>
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
}
