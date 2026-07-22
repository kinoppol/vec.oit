<?php
/**
 * includes/migrations.php — shared database-migration engine.
 *
 * Single source of truth for discovering, statusing and running the
 * versioned *.sql files under database/migrations/. Consumed by:
 *   - migrate.php (CLI + standalone web runner)
 *   - api.php     (action=run_migrations, centraladmin only)
 *   - views/migration.php (status table for centraladmin)
 *
 * Requires config/database.php (db()) to be loaded by the caller.
 */

const MIG_DIR = __DIR__ . '/../database/migrations';

// MySQL error numbers that mean "already applied" — treated as skips so a
// migration is safe to re-run against a partially-imported database.
// 1050 table exists, 1060 dup column, 1061 dup key, 1062 dup entry,
// 1022 dup key, 1826 dup FK.
const MIG_DUP_ERRORS = [1050, 1060, 1061, 1062, 1022, 1826];

/** Ensure the tracking table exists (idempotent). */
function mig_ensure_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id`         INT UNSIGNED AUTO_INCREMENT,
            `migration`  VARCHAR(255) NOT NULL,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

/** All migration files, sorted by filename (= run order). */
function mig_files(): array
{
    $files = glob(MIG_DIR . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

/** Map of applied migration name => applied_at timestamp. */
function mig_applied(PDO $pdo): array
{
    mig_ensure_table($pdo);
    $rows = $pdo->query('SELECT migration, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows ?: [];
}

/**
 * Status of every migration.
 * @return array<int, array{name:string, applied:bool, applied_at:?string}>
 */
function mig_status(): array
{
    $pdo     = db();
    $applied = mig_applied($pdo);
    $out     = [];
    foreach (mig_files() as $f) {
        $name = basename($f);
        $out[] = [
            'name'       => $name,
            'applied'    => isset($applied[$name]),
            'applied_at' => $applied[$name] ?? null,
        ];
    }
    return $out;
}

/** Count of pending (not-yet-applied) migrations. */
function mig_pending_count(): int
{
    return count(array_filter(mig_status(), fn ($m) => !$m['applied']));
}

/** Split a .sql file into statements: drop comment lines, split on trailing ';'. */
function mig_parse_sql(string $sql): array
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

/**
 * Run all pending migrations in order. Stops on the first hard failure.
 *
 * @return array{
 *   results: array<int, array{name:string, type:string, msg:string}>,
 *   ranAny: bool, hadError: bool
 * }
 * type is one of: ok | skip | err
 */
function mig_run(): array
{
    $pdo     = db();
    $applied = mig_applied($pdo);
    $results = [];
    $ranAny  = false;
    $hadError = false;

    foreach (mig_files() as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            $results[] = ['name' => $name, 'type' => 'skip', 'msg' => $name . ' — ข้าม (รันแล้ว)'];
            continue;
        }

        $stmts = mig_parse_sql((string) file_get_contents($file));
        $ok = $skip = 0;
        $fatal = null;

        foreach ($stmts as $stmt) {
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (PDOException $e) {
                // MySQL error number lives in errorInfo[1] (getCode() is SQLSTATE).
                $mysqlErr = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($mysqlErr, MIG_DUP_ERRORS, true)) {
                    $skip++;
                } else {
                    $fatal = $e->getMessage();
                    break;
                }
            }
        }

        if ($fatal !== null) {
            $results[] = ['name' => $name, 'type' => 'err', 'msg' => $name . ' — ผิดพลาด: ' . $fatal];
            $hadError = true;
            break; // migrations are ordered; stop on first hard failure
        }

        $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$name]);
        $ranAny = true;

        $detail = "{$ok} statement" . ($skip ? ", {$skip} ข้าม (มีอยู่แล้ว)" : '');
        $results[] = ['name' => $name, 'type' => 'ok', 'msg' => "{$name} — สำเร็จ ({$detail})"];
    }

    if (!$hadError && !$ranAny) {
        $results[] = ['name' => '', 'type' => 'ok', 'msg' => 'ฐานข้อมูลเป็นเวอร์ชันล่าสุดแล้ว ไม่มี migration ค้าง'];
    }

    return ['results' => $results, 'ranAny' => $ranAny, 'hadError' => $hadError];
}
