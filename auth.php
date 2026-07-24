<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already logged in
if (!empty($_SESSION['user'])) {
    redirect('app.php');
}

$mode   = $_GET['mode'] ?? 'login';   // login | register | changepw
$errors = [];
$info   = [];

// ── POST handlers ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {

        // LOGIN
        if ($mode === 'login') {
            $cid = trim($_POST['national_id'] ?? '');
            $pw  = $_POST['password'] ?? '';
            if (!$cid || !$pw) {
                $errors[] = 'กรุณากรอกข้อมูลให้ครบ';
            } else {
                $stmt = db()->prepare('SELECT * FROM users WHERE national_id = ? LIMIT 1');
                $stmt->execute([$cid]);
                $u = $stmt->fetch();
                if (!$u || !password_verify($pw, $u['password_hash'])) {
                    $errors[] = 'เลขประจำตัวประชาชนหรือรหัสผ่านไม่ถูกต้อง';
                } elseif ($u['status'] === 'disabled') {
                    $errors[] = 'บัญชีนี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
                } else {
                    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
                        ->execute([$u['id']]);

                    if ($u['must_change_pw']) {
                        $_SESSION['change_pw_uid'] = $u['id'];
                        redirect('auth.php?mode=changepw');
                    }

                    $school = null;
                    if ($u['school_id']) {
                        $s = db()->prepare('SELECT * FROM schools WHERE id = ?');
                        $s->execute([$u['school_id']]);
                        $school = $s->fetch();
                    }
                    $fy = active_year();
                    $_SESSION['user'] = [
                        'id'         => $u['id'],
                        'name'       => $u['full_name'],
                        'national_id'=> $u['national_id'],
                        'role'       => $u['role'],
                        'school_id'  => $u['school_id'],
                        'school'     => $school,
                        'avatar'     => $u['avatar'] ?? null,
                        'year_code'  => $fy['year_code'],
                        'year_label' => $fy['label'],
                        'theme'      => $_SESSION['theme'] ?? 'system',
                    ];
                    redirect('app.php');
                }
            }
        }

        // REGISTER
        if ($mode === 'register') {
            $schoolName = trim($_POST['school_name'] ?? '');
            $province   = trim($_POST['province'] ?? '');
            $adminName  = trim($_POST['admin_name'] ?? '');
            $cid        = trim($_POST['national_id'] ?? '');
            $email      = trim($_POST['email'] ?? '');

            if (!$schoolName || !$adminName || !$cid) {
                $errors[] = 'กรุณากรอกข้อมูลให้ครบ';
            } elseif (!preg_match('/^\d{13}$/', $cid)) {
                $errors[] = 'เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก';
            } else {
                $check = db()->prepare('SELECT id FROM users WHERE national_id = ?');
                $check->execute([$cid]);
                if ($check->fetch()) {
                    $errors[] = 'มีบัญชีเลขประจำตัวนี้ในระบบแล้ว';
                } else {
                    $pdo  = db();
                    $slug = gen_slug($schoolName);
                    $pdo->prepare('INSERT INTO schools (name,province,slug,status) VALUES (?,?,?,?)')
                        ->execute([$schoolName, $province, $slug, 'pending']);
                    $schoolId = (int)$pdo->lastInsertId();
                    $genPw    = gen_password();
                    $hash     = password_hash($genPw, PASSWORD_BCRYPT);
                    $pdo->prepare('INSERT INTO users (school_id,national_id,password_hash,full_name,role,status,must_change_pw)
                                   VALUES (?,?,?,?,?,?,?)')
                        ->execute([$schoolId, $cid, $hash, $adminName, 'schooladmin', 'pending', 1]);

                    $info = ['school' => $schoolName, 'cid' => $cid, 'pw' => $genPw];
                    $mode = 'register_done';
                }
            }
        }

        // CHANGE PASSWORD
        if ($mode === 'changepw') {
            $uid = $_SESSION['change_pw_uid'] ?? 0;
            $np1 = $_POST['new_pw'] ?? '';
            $np2 = $_POST['confirm_pw'] ?? '';
            if (strlen($np1) < 8) {
                $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
            } elseif ($np1 !== $np2) {
                $errors[] = 'รหัสผ่านยืนยันไม่ตรงกัน';
            } elseif (!$uid) {
                $errors[] = 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
            } else {
                $hash = password_hash($np1, PASSWORD_BCRYPT);
                db()->prepare('UPDATE users SET password_hash=?, must_change_pw=0, status="active" WHERE id=?')
                    ->execute([$hash, $uid]);
                $stmt = db()->prepare('
                    SELECT u.id AS uid, u.full_name, u.national_id, u.role, u.school_id, u.avatar,
                           s.id AS sid, s.name AS school_name, s.province, s.slug, s.emblem_path, s.code, s.website
                    FROM users u LEFT JOIN schools s ON s.id = u.school_id WHERE u.id=?
                ');
                $stmt->execute([$uid]);
                $u = $stmt->fetch();
                $fy = active_year();
                $_SESSION['user'] = [
                    'id'         => $u['uid'],
                    'name'       => $u['full_name'],
                    'national_id'=> $u['national_id'],
                    'role'       => $u['role'],
                    'school_id'  => $u['school_id'],
                    'school'     => $u['school_id'] ? ['id'=>$u['sid'],'name'=>$u['school_name'],'province'=>$u['province'],'slug'=>$u['slug'],'emblem_path'=>$u['emblem_path'],'code'=>$u['code'],'website'=>$u['website']] : null,
                    'avatar'     => $u['avatar'] ?? null,
                    'year_code'  => $fy['year_code'],
                    'year_label' => $fy['label'],
                    'theme'      => 'system',
                ];
                unset($_SESSION['change_pw_uid']);
                redirect('app.php');
            }
        }
    }
}

$pageTitle = match($mode) {
    'register'      => 'ลงทะเบียนสถานศึกษา',
    'changepw'      => 'ตั้งรหัสผ่านใหม่',
    'register_done' => 'ส่งคำขอแล้ว',
    default         => 'เข้าสู่ระบบ',
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body class="auth-body" id="body">

<?php if ($mode === 'login'): ?>
<!-- ════ LOGIN ════ -->
<div class="auth-split">
  <div class="auth-panel">
    <div class="auth-panel-logo">
      <div class="logo-circle">
        <img src="<?= APP_URL ?>/assets/ovec-logo.jpg" alt="OVEC">
      </div>
      <div class="auth-panel-org">สำนักงานคณะกรรมการ<br>การอาชีวศึกษา</div>
    </div>
    <div class="auth-panel-hero">
      <div class="auth-panel-tag">OIT · OPEN DATA INTEGRITY</div>
      <h1>ระบบเปิดเผย<br>ข้อมูลสาธารณะ</h1>
      <p>แพลตฟอร์มกลางสำหรับสถานศึกษาอาชีวศึกษา จัดเก็บหลักฐานตัวชี้วัด OIT และเผยแพร่ต่อสาธารณะ เพื่อการประเมินคุณธรรมและความโปร่งใส (ITA)</p>
      <ul class="auth-panel-feats">
        <li>แนบลิงก์ ไฟล์ รูปภาพ และข้อความเป็นหลักฐาน</li>
        <li>รองรับการเปลี่ยนเกณฑ์ในแต่ละปีงบประมาณ</li>
        <li>เชื่อมโยงหน้าแสดงผลกับเว็บไซต์สถานศึกษา</li>
      </ul>
    </div>
    <div class="auth-panel-footer">© 2569 สำนักงานคณะกรรมการการอาชีวศึกษา กระทรวงศึกษาธิการ</div>
  </div>
  <div class="auth-form-wrap">
    <div class="auth-form-box">
      <div class="auth-sys-label"><?= APP_NAME ?></div>
      <h2>เข้าสู่ระบบ</h2>
      <p class="auth-sub">สำหรับเจ้าหน้าที่และผู้ดูแลระบบของสถานศึกษา</p>
      <?php foreach ($errors as $err): ?>
      <div class="alert-err"><?= e($err) ?></div>
      <?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>เลขประจำตัวประชาชน (ชื่อผู้ใช้)</label>
        <input type="text" name="national_id" inputmode="numeric" maxlength="13"
               value="<?= e($_POST['national_id'] ?? '') ?>"
               placeholder="กรอกเลข 13 หลัก" autocomplete="username">
        <label>รหัสผ่าน</label>
        <input type="password" name="password" placeholder="รหัสผ่าน" autocomplete="current-password">
        <button type="submit" class="btn-primary btn-full mt-5">เข้าสู่ระบบ</button>
      </form>
      <a href="<?= APP_URL ?>/public.php" class="btn-outline btn-full" style="margin-top:12px;justify-content:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14.5 14.5 0 0 1 0 18 14.5 14.5 0 0 1 0-18z"/></svg>
        ไปยังหน้าแสดงผลสาธารณะ
      </a>
      <div class="auth-hint">ชื่อผู้ใช้คือ <strong>เลขประจำตัวประชาชน</strong> · รหัสผ่านเริ่มต้นระบบจะสุ่มให้โดยอัตโนมัติ และต้องเปลี่ยนเมื่อเข้าใช้งานครั้งแรก</div>
      <div class="auth-switch">สถานศึกษายังไม่มีบัญชี? <a href="?mode=register">สมัครใช้งานระบบ</a></div>
    </div>
  </div>
</div>

<?php elseif ($mode === 'register'): ?>
<!-- ════ REGISTER ════ -->
<div class="auth-center">
  <div class="auth-card">
    <div class="auth-card-header">
      <div class="logo-sm"><img src="<?= APP_URL ?>/assets/ovec-logo.jpg" alt="OVEC"></div>
      <div><div class="auth-sys-label">สมัครใช้งานระบบ OIT</div><h2>ลงทะเบียนสถานศึกษา</h2></div>
    </div>
    <p class="auth-sub">กรอกข้อมูลเพื่อสมัครใช้งาน เมื่อผู้ดูแลส่วนกลางอนุมัติแล้ว บัญชีผู้ดูแลระบบสถานศึกษาจะถูกสร้างให้โดยอัตโนมัติ</p>
    <?php foreach ($errors as $err): ?>
    <div class="alert-err"><?= e($err) ?></div>
    <?php endforeach; ?>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <div class="fg-full"><label>ชื่อสถานศึกษา</label><input type="text" name="school_name" placeholder="เช่น วิทยาลัยเทคนิค..." value="<?= e($_POST['school_name'] ?? '') ?>"></div>
      <div class="fg-full"><label>จังหวัด</label><input type="text" name="province" placeholder="จังหวัด" value="<?= e($_POST['province'] ?? '') ?>"></div>
      <div class="fg-full"><label>ชื่อ-นามสกุล ผู้ดูแลระบบสถานศึกษา</label><input type="text" name="admin_name" placeholder="ชื่อ-นามสกุล" value="<?= e($_POST['admin_name'] ?? '') ?>"></div>
      <div><label>เลขประจำตัวประชาชน</label><input type="text" name="national_id" inputmode="numeric" maxlength="13" placeholder="13 หลัก" value="<?= e($_POST['national_id'] ?? '') ?>"></div>
      <div><label>อีเมล</label><input type="email" name="email" placeholder="อีเมลติดต่อ" value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div class="fg-full fg-actions">
        <a href="?mode=login" class="btn-outline">ย้อนกลับ</a>
        <button type="submit" class="btn-primary flex-1">ส่งคำขอสมัครใช้งาน</button>
      </div>
    </form>
  </div>
</div>

<?php elseif ($mode === 'register_done'): ?>
<!-- ════ REGISTER DONE ════ -->
<div class="auth-center">
  <div class="auth-card" style="text-align:center">
    <div class="success-icon">✓</div>
    <h3>ส่งคำขอเรียบร้อยแล้ว</h3>
    <p class="auth-sub">คำขอของ <strong><?= e($info['school']) ?></strong> อยู่ระหว่างรอผู้ดูแลส่วนกลางอนุมัติ<br>นี่คือข้อมูลบัญชีผู้ดูแลระบบสถานศึกษาเบื้องต้น</p>
    <div class="cred-box">
      <div class="cred-label">ชื่อผู้ใช้ (เลขประจำตัวประชาชน)</div>
      <div class="cred-val"><?= e($info['cid']) ?></div>
      <div class="cred-label" style="margin-top:12px">รหัสผ่านเริ่มต้น (สุ่มอัตโนมัติ)</div>
      <div class="cred-val maroon"><?= e($info['pw']) ?></div>
    </div>
    <a href="?mode=login" class="btn-primary" style="display:inline-block;margin-top:20px">กลับสู่หน้าเข้าสู่ระบบ</a>
  </div>
</div>

<?php elseif ($mode === 'changepw'): ?>
<!-- ════ CHANGE PASSWORD ════ -->
<div class="auth-center">
  <div class="auth-card">
    <div class="lock-icon">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
    </div>
    <h2>ตั้งรหัสผ่านใหม่</h2>
    <p class="auth-sub">เพื่อความปลอดภัย กรุณาเปลี่ยนรหัสผ่านที่ระบบสุ่มให้ ก่อนเริ่มใช้งานครั้งแรก</p>
    <?php foreach ($errors as $err): ?>
    <div class="alert-err"><?= e($err) ?></div>
    <?php endforeach; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label>รหัสผ่านใหม่</label>
      <input type="password" name="new_pw" placeholder="อย่างน้อย 8 ตัวอักษร" autocomplete="new-password">
      <label style="margin-top:15px">ยืนยันรหัสผ่านใหม่</label>
      <input type="password" name="confirm_pw" placeholder="กรอกอีกครั้ง" autocomplete="new-password">
      <button type="submit" class="btn-primary btn-full mt-5">บันทึกและเข้าสู่ระบบ</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Auto dark mode on auth pages
(function(){
  const t = localStorage.getItem('oit_theme') || 'system';
  const dark = t==='dark' || (t==='system' && window.matchMedia('(prefers-color-scheme:dark)').matches);
  if(dark) document.body.classList.add('dark');
})();
</script>
</body>
</html>
