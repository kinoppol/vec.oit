<?php
/**
 * install.php — One-time database setup for ระบบ OIT
 * ⚠ DELETE THIS FILE after installation is complete.
 */

define('SCHEMA_FILE', __DIR__ . '/database/schema.sql');
define('INSTALL_LOCK', __DIR__ . '/.installed');

// Already installed?
if (file_exists(INSTALL_LOCK)) {
    $locked = true;
}

// ── Requirements check ────────────────────────────────────────
$checks = [
    'PHP 8.0+'           => ['pass' => version_compare(PHP_VERSION, '8.0.0', '>='), 'found' => 'PHP ' . PHP_VERSION],
    'PDO extension'      => ['pass' => extension_loaded('pdo'),      'found' => extension_loaded('pdo')      ? 'โหลดแล้ว' : 'ไม่พบ'],
    'pdo_mysql driver'   => ['pass' => extension_loaded('pdo_mysql'), 'found' => extension_loaded('pdo_mysql') ? 'โหลดแล้ว' : 'ไม่พบ'],
    'mbstring extension' => ['pass' => extension_loaded('mbstring'), 'found' => extension_loaded('mbstring') ? 'โหลดแล้ว' : 'ไม่พบ'],
    'schema.sql'         => ['pass' => file_exists(SCHEMA_FILE),     'found' => file_exists(SCHEMA_FILE)     ? 'พบไฟล์' : 'ไม่พบไฟล์'],
    'uploads/ เขียนได้'  => ['pass' => is_writable(__DIR__ . '/uploads'), 'found' => is_writable(__DIR__ . '/uploads') ? 'เขียนได้' : 'ไม่มีสิทธิ์'],
];
$reqOk = !in_array(false, array_column($checks, 'pass'), true);

// ── State ─────────────────────────────────────────────────────
$step    = 'form';   // form | done
$errors  = [];
$results = [];

// ── POST: run installation ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reqOk && empty($locked)) {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? 'vec_oit');
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    $seed = !empty($_POST['seed_data']);

    if (!$host || !$name) {
        $errors[] = 'กรุณากรอก Host และชื่อฐานข้อมูล';
    } else {
        try {
            // 1. Connect without specifying a database
            $pdo = new PDO(
                "mysql:host={$host};charset=utf8mb4",
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $results[] = ['ok',   "เชื่อมต่อ MySQL/MariaDB ที่ {$host} สำเร็จ"];

            // 2. Create DB if needed
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");
            $results[] = ['ok', "สร้างหรือตรวจสอบฐานข้อมูล `{$name}` เรียบร้อย"];

            // 3. Parse and run schema
            $sql   = file_get_contents(SCHEMA_FILE);
            $stmts = parseSql($sql);

            $okCnt = $skipCnt = $warnCnt = 0;
            foreach ($stmts as $stmt) {
                // Skip CREATE DATABASE / USE — already handled above
                if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s)/i', $stmt)) continue;
                // Skip seed inserts when user opted out
                if (!$seed && preg_match('/^\s*INSERT\s+INTO\s+`?(schools|users|fiscal_years|indicator_sections|indicator_subsections|indicators|school_indicator_status|evidences)`?/i', $stmt)) {
                    $skipCnt++;
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                    $okCnt++;
                } catch (PDOException $e) {
                    $code = (int)$e->getCode();
                    if ($code === 1062 || $code === 1061 || $code === 1060) {
                        // Duplicate key / index / column — data already exists, safe to skip
                        $skipCnt++;
                    } else {
                        $warnCnt++;
                        $results[] = ['warn', 'SQL warning: ' . htmlspecialchars(substr($e->getMessage(), 0, 160))];
                    }
                }
            }
            $results[] = ['ok',
                "รันคำสั่ง SQL: {$okCnt} สำเร็จ" .
                ($skipCnt ? ", {$skipCnt} ข้าม (มีอยู่แล้ว)" : '') .
                ($warnCnt ? ", {$warnCnt} คำเตือน" : '')
            ];

            // 4. Write lock file
            file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s'));
            $step = 'done';

        } catch (PDOException $e) {
            $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── SQL parser: split on semicolons, skip comment lines ──────
function parseSql(string $sql): array
{
    $stmts   = [];
    $current = '';
    foreach (explode("\n", $sql) as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '--')) continue;
        $current .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            if ($s = trim($current)) $stmts[] = $s;
            $current = '';
        }
    }
    if ($s = trim($current)) $stmts[] = $s;
    return $stmts;
}

// Derive app URL for the "go to app" link
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host_h = $_SERVER['HTTP_HOST'] ?? 'localhost';
$root   = str_replace('\\', '/', __DIR__);
$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$rel    = $docRoot ? str_replace($docRoot, '', $root) : '/vec.oit';
$appUrl = rtrim($scheme . '://' . $host_h . $rel, '/');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ติดตั้งระบบ OIT — ระบบเปิดเผยข้อมูลสาธารณะ</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Sarabun', -apple-system, sans-serif; font-size: 15px; line-height: 1.6; background: #F4F1ED; color: #221C18; min-height: 100%; }
a { color: #7A1E28; }

/* ── Header ── */
.inst-header {
  background: linear-gradient(135deg, #5C141C, #7A1E28 65%, #3d0d13);
  color: #fff; padding: 22px 32px;
  display: flex; align-items: center; gap: 16px;
  box-shadow: 0 2px 12px rgba(0,0,0,.2);
}
.inst-header-logo {
  width: 48px; height: 48px; border-radius: 50%;
  background: #fff; overflow: hidden; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.inst-header-logo img { width: 100%; height: 100%; object-fit: contain; }
.inst-header-title { font-size: 20px; font-weight: 800; }
.inst-header-sub   { font-size: 13px; opacity: .8; margin-top: 2px; }
.inst-header-badge {
  margin-left: auto; padding: 4px 14px; border-radius: 20px;
  background: rgba(255,255,255,.15); font-size: 13px; font-weight: 700;
}

/* ── Layout ── */
.inst-wrap { max-width: 720px; margin: 40px auto; padding: 0 20px 60px; }

/* ── Card ── */
.card {
  background: #fff; border: 1px solid #E7E0D9;
  border-radius: 16px; margin-bottom: 20px;
  box-shadow: 0 4px 16px rgba(74,30,24,.07);
}
.card-header {
  display: flex; align-items: center; gap: 10px;
  padding: 16px 22px; border-bottom: 1px solid #E7E0D9;
  font-size: 15px; font-weight: 800; color: #221C18;
}
.card-body { padding: 22px; }

/* ── Step badge ── */
.step-num {
  width: 26px; height: 26px; border-radius: 50%;
  background: #7A1E28; color: #fff;
  font-size: 13px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

/* ── Check list ── */
.check-list { display: flex; flex-direction: column; gap: 8px; }
.check-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 9px 14px; border-radius: 9px;
  border: 1px solid #E7E0D9; font-size: 14px;
}
.check-row.pass { background: #E5F0EA; border-color: #b6dfc9; }
.check-row.fail { background: #F6E3E1; border-color: #e8b5b1; }
.check-name { color: #221C18; font-weight: 600; }
.check-found { font-size: 13px; }
.check-row.pass .check-found { color: #2F7A57; }
.check-row.fail .check-found { color: #B23A3A; font-weight: 700; }
.check-icon { font-size: 17px; flex-shrink: 0; }

/* ── Form ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 13.5px; font-weight: 700; color: #221C18; }
.form-hint  { font-size: 12px; color: #9E938A; }
.form-input {
  padding: 11px 13px; border: 1px solid #D6CBC2;
  border-radius: 9px; background: #FAFAF9;
  color: #221C18; font-family: inherit; font-size: 14.5px;
  transition: border-color .15s;
}
.form-input:focus { outline: none; border-color: #7A1E28; background: #fff; }
.form-check { display: flex; align-items: center; gap: 9px; cursor: pointer; font-size: 14px; color: #221C18; }
.form-check input { width: 16px; height: 16px; accent-color: #7A1E28; cursor: pointer; }

/* ── Buttons ── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 11px 22px; border-radius: 10px;
  font-size: 15px; font-weight: 700; font-family: inherit;
  border: none; cursor: pointer; transition: background .15s;
  text-decoration: none;
}
.btn-primary { background: #7A1E28; color: #fff; }
.btn-primary:hover { background: #5C141C; }
.btn-success { background: #2F7A57; color: #fff; }
.btn-success:hover { background: #256446; }
.btn-ghost  { background: transparent; color: #6E645C; border: 1px solid #D6CBC2; }
.btn-ghost:hover { background: #F4F1ED; }

/* ── Alert/errors ── */
.alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
.alert-err  { background: #F6E3E1; border: 1px solid #e8b5b1; color: #B23A3A; }
.alert-warn { background: #F5ECD7; border: 1px solid #e0c88a; color: #A8701A; }
.alert-info { background: #F4F1ED; border: 1px solid #E7E0D9; color: #6E645C; }

/* ── Results ── */
.result-list { display: flex; flex-direction: column; gap: 8px; }
.result-row {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 10px 14px; border-radius: 9px; font-size: 13.5px;
}
.result-row.ok   { background: #E5F0EA; color: #1d5c3d; }
.result-row.warn { background: #F5ECD7; color: #7A5010; }

/* ── Success panel ── */
.success-hero { text-align: center; padding: 10px 0 24px; }
.success-icon {
  width: 70px; height: 70px; border-radius: 50%;
  background: #E5F0EA; color: #2F7A57;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px; font-size: 32px;
}
.success-title { font-size: 22px; font-weight: 800; color: #221C18; margin-bottom: 6px; }
.success-sub   { font-size: 14px; color: #6E645C; }

/* ── Accounts table ── */
.accounts-table { width: 100%; border-collapse: collapse; font-size: 13.5px; margin-top: 14px; }
.accounts-table th {
  text-align: left; padding: 8px 12px;
  background: #F4F1ED; color: #9E938A;
  font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
  border-bottom: 1px solid #E7E0D9;
}
.accounts-table td { padding: 9px 12px; border-bottom: 1px solid #E7E0D9; color: #221C18; }
.accounts-table tr:last-child td { border-bottom: none; }
.mono { font-family: monospace; font-size: 13px; letter-spacing: .5px; }
.role-chip {
  display: inline-block; padding: 2px 9px; border-radius: 20px;
  font-size: 12px; font-weight: 700;
}
.role-ca  { background: #F3E5E3; color: #7A1E28; }
.role-sa  { background: #E5F0EA; color: #2F7A57; }
.role-usr { background: #F4F1ED; color: #6E645C; }

/* ── Warn banner ── */
.warn-banner {
  background: #FFF8E6; border: 1.5px solid #F0C040;
  border-radius: 12px; padding: 14px 18px;
  display: flex; align-items: flex-start; gap: 12px;
  font-size: 13.5px; color: #7A5010;
}
.warn-icon { font-size: 20px; flex-shrink: 0; margin-top: -2px; }

/* ── Locked screen ── */
.locked-wrap { max-width: 500px; margin: 80px auto; padding: 0 20px; text-align: center; }
.locked-icon { font-size: 48px; margin-bottom: 14px; }
.locked-title { font-size: 20px; font-weight: 800; color: #221C18; margin-bottom: 8px; }
.locked-sub { font-size: 14px; color: #6E645C; margin-bottom: 24px; }
</style>
</head>
<body>

<!-- ─── Header ─── -->
<header class="inst-header">
  <div class="inst-header-logo">
    <img src="<?= $appUrl ?>/assets/ovec-logo.jpg" alt="OVEC" onerror="this.style.display='none'">
  </div>
  <div>
    <div class="inst-header-title">ระบบเปิดเผยข้อมูลสาธารณะ (OIT)</div>
    <div class="inst-header-sub">สำนักงานคณะกรรมการการอาชีวศึกษา</div>
  </div>
  <div class="inst-header-badge">ตัวติดตั้งระบบ</div>
</header>

<?php if (!empty($locked) && $step !== 'done'): ?>
<!-- ─── Locked ─── -->
<div class="locked-wrap">
  <div class="locked-icon">🔒</div>
  <div class="locked-title">ระบบได้รับการติดตั้งแล้ว</div>
  <div class="locked-sub">ตรวจพบไฟล์ <code>.installed</code> — ระบบถูกติดตั้งไปแล้วก่อนหน้านี้<br>หากต้องการติดตั้งใหม่ ให้ลบไฟล์ <code>.installed</code> ออกก่อน</div>
  <a href="<?= htmlspecialchars($appUrl) ?>" class="btn btn-primary">ไปยังหน้าหลักระบบ</a>
</div>

<?php else: ?>
<div class="inst-wrap">

  <?php if ($step === 'done'): ?>
  <!-- ═══════════ DONE ═══════════ -->
  <div class="card">
    <div class="card-body">
      <div class="success-hero">
        <div class="success-icon">✓</div>
        <div class="success-title">ติดตั้งสำเร็จ!</div>
        <div class="success-sub">ฐานข้อมูลพร้อมใช้งานแล้ว</div>
      </div>

      <div class="result-list" style="margin-bottom:24px">
        <?php foreach ($results as [$type, $msg]): ?>
        <div class="result-row <?= $type ?>">
          <span><?= $type === 'ok' ? '✓' : '⚠' ?></span>
          <span><?= $msg ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($_POST['seed_data'])): ?>
      <!-- Seed accounts table -->
      <div style="margin-bottom:22px">
        <div style="font-size:14px;font-weight:700;color:#221C18;margin-bottom:4px">บัญชีทดสอบ (รหัสผ่าน: <code>password</code>)</div>
        <table class="accounts-table">
          <thead>
            <tr><th>เลขประจำตัวประชาชน</th><th>ชื่อ</th><th>บทบาท</th></tr>
          </thead>
          <tbody>
            <tr>
              <td class="mono">0000000000001</td>
              <td>ผู้ดูแลส่วนกลาง สอศ.</td>
              <td><span class="role-chip role-ca">centraladmin</span></td>
            </tr>
            <tr>
              <td class="mono">1100700123456</td>
              <td>นายสมชาย ใจดี</td>
              <td><span class="role-chip role-sa">schooladmin</span></td>
            </tr>
            <tr>
              <td class="mono">1100700234567</td>
              <td>นางสาวสุดา รักเรียน</td>
              <td><span class="role-chip role-usr">user</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="warn-banner" style="margin-bottom:22px">
        <span class="warn-icon">⚠️</span>
        <div>
          <strong>กรุณาลบไฟล์ <code>install.php</code> ออกจากเซิร์ฟเวอร์ทันที</strong><br>
          การปล่อยให้ไฟล์นี้อยู่บนเซิร์ฟเวอร์ถือเป็นความเสี่ยงด้านความปลอดภัย
        </div>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="<?= htmlspecialchars($appUrl) ?>" class="btn btn-success">
          &#8594; เข้าสู่ระบบ OIT
        </a>
        <a href="<?= htmlspecialchars($appUrl) ?>/auth.php" class="btn btn-ghost">
          หน้า Login
        </a>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- ═══════════ FORM ═══════════ -->

  <!-- Step 1: Requirements -->
  <div class="card">
    <div class="card-header">
      <span class="step-num">1</span>
      ตรวจสอบความต้องการของระบบ
    </div>
    <div class="card-body">
      <div class="check-list">
        <?php foreach ($checks as $label => $chk): ?>
        <div class="check-row <?= $chk['pass'] ? 'pass' : 'fail' ?>">
          <span class="check-name"><?= htmlspecialchars($label) ?></span>
          <span class="check-found"><?= htmlspecialchars($chk['found']) ?></span>
          <span class="check-icon"><?= $chk['pass'] ? '✓' : '✗' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$reqOk): ?>
      <div class="alert alert-err" style="margin-top:16px">
        กรุณาแก้ไขรายการที่ไม่ผ่านด้านบนก่อนดำเนินการต่อ
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Step 2: Database -->
  <div class="card" <?= !$reqOk ? 'style="opacity:.55;pointer-events:none"' : '' ?>>
    <div class="card-header">
      <span class="step-num">2</span>
      ตั้งค่าฐานข้อมูล
    </div>
    <div class="card-body">
      <?php foreach ($errors as $err): ?>
      <div class="alert alert-err"><?= $err ?></div>
      <?php endforeach; ?>

      <div class="alert alert-info" style="margin-bottom:18px">
        สคริปต์จะสร้างฐานข้อมูลให้อัตโนมัติหากยังไม่มี และรันไฟล์ <code>database/schema.sql</code> เพื่อสร้างตาราง
      </div>

      <form method="post">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host"
                   class="form-input" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"
                   placeholder="localhost" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="db_name">ชื่อฐานข้อมูล</label>
            <input type="text" id="db_name" name="db_name"
                   class="form-input" value="<?= htmlspecialchars($_POST['db_name'] ?? 'vec_oit') ?>"
                   placeholder="vec_oit" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="db_user">ชื่อผู้ใช้ MySQL</label>
            <input type="text" id="db_user" name="db_user"
                   class="form-input" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>"
                   placeholder="root">
          </div>
          <div class="form-group">
            <label class="form-label" for="db_pass">รหัสผ่าน MySQL</label>
            <input type="password" id="db_pass" name="db_pass"
                   class="form-input" value=""
                   placeholder="(เว้นว่างถ้าไม่มี)">
            <span class="form-hint">XAMPP ค่าเริ่มต้นไม่มีรหัสผ่าน</span>
          </div>
          <div class="form-group full">
            <label class="form-check">
              <input type="checkbox" name="seed_data" value="1"
                     <?= !empty($_POST['seed_data']) || !isset($_POST['db_host']) ? 'checked' : '' ?>>
              เพิ่มข้อมูลทดสอบ (seed) — บัญชีผู้ใช้ตัวอย่าง, ตัวชี้วัด OIT 33 ข้อ, หลักฐานตัวอย่าง
            </label>
          </div>
        </div>
        <div style="margin-top:22px;display:flex;gap:12px;align-items:center">
          <button type="submit" class="btn btn-primary" <?= !$reqOk ? 'disabled' : '' ?>>
            &#9654; ติดตั้งฐานข้อมูล
          </button>
          <a href="<?= htmlspecialchars($appUrl) ?>" class="btn btn-ghost">ยกเลิก</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Info box -->
  <div class="warn-banner">
    <span class="warn-icon">ℹ️</span>
    <div style="font-size:13.5px">
      <strong>หลังติดตั้งเสร็จ</strong> — กรุณาลบหรือเปลี่ยนชื่อไฟล์ <code>install.php</code> ออกจากเซิร์ฟเวอร์ทันที
      เพื่อป้องกันการ reset ฐานข้อมูลโดยไม่ตั้งใจ
    </div>
  </div>

  <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
