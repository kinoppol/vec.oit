<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_auth();
$role = $user['role'];

// Pending-migration count drives the sidebar badge (centraladmin only)
$migPending = 0;
if ($role === 'centraladmin') {
    require_once __DIR__ . '/includes/migrations.php';
    try { $migPending = mig_pending_count(); } catch (Throwable $e) { $migPending = 0; }
}

// Impersonation state (schooladmin acting as one of their users)
$impersonating    = !empty($_SESSION['impersonator']);
$impersonatorName = $impersonating ? ($_SESSION['impersonator']['name'] ?? '') : '';

// Handle year switch
if (isset($_GET['year'])) {
    $_SESSION['user']['year_code']  = $_GET['year'];
    $fy = db()->prepare('SELECT label FROM fiscal_years WHERE year_code = ?');
    $fy->execute([$_GET['year']]);
    $r = $fy->fetch();
    $_SESSION['user']['year_label'] = $r['label'] ?? $_GET['year'];
    $user = $_SESSION['user'];
    redirect('app.php');
}

// Theme persistence
if (isset($_GET['theme'])) {
    $_SESSION['user']['theme'] = in_array($_GET['theme'], ['light','dark','system']) ? $_GET['theme'] : 'system';
    $user = $_SESSION['user'];
    redirect('app.php?view=' . ($_GET['view'] ?? ''));
}

$view = $_GET['view'] ?? match($role) {
    'centraladmin' => 'criteria',
    default        => 'dashboard',
};

// Validate view access
$allowedViews = match($role) {
    'centraladmin' => ['criteria', 'schools', 'positions', 'migration'],
    'schooladmin'  => ['dashboard', 'evidence', 'tracking', 'users', 'school'],
    default        => ['dashboard', 'evidence'],
};
if (!in_array($view, $allowedViews, true)) {
    $view = $allowedViews[0];
}

// Common data
$school    = $user['school'] ?? null;
$schoolId  = (int)($user['school_id'] ?? 0);
$yearCode  = $user['year_code'] ?? '2568';
$yearLabel = $user['year_label'] ?? 'ปีงบประมาณ พ.ศ. 2568';
$years     = all_years();
$theme     = $user['theme'] ?? 'system';

// Reload fresh school data (emblem may have changed)
if ($schoolId) {
    $sStmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
    $sStmt->execute([$schoolId]);
    $school = $sStmt->fetch() ?: $school;
    $_SESSION['user']['school'] = $school;
}

$pageTitle = match($view) {
    'dashboard' => 'ภาพรวมความคืบหน้า',
    'evidence'  => 'กรอกหลักฐานตัวชี้วัด',
    'tracking'  => 'สรุปการมอบหมาย & ติดตามการดำเนินงาน',
    'users'     => 'จัดการผู้ใช้',
    'school'    => 'ข้อมูลสถานศึกษา',
    'criteria'  => 'เกณฑ์ & ปีงบประมาณ',
    'schools'   => 'สถานศึกษาที่สมัครใช้งาน',
    'positions' => 'จัดการตำแหน่งกลาง',
    'migration' => 'ปรับปรุงฐานข้อมูล (Migration)',
    default     => '',
};

$initial = mb_substr($user['name'], 0, 1);
// Strip Thai prefixes for initial
$nameTrimmed = preg_replace('/^(นาย|นาง|นางสาว)/u', '', $user['name']);
$initial = mb_substr(trim($nameTrimmed), 0, 1) ?: mb_substr($user['name'], 0, 1);

$roleLabel = match($role) {
    'schooladmin'  => 'ผู้ดูแลระบบสถานศึกษา',
    'centraladmin' => 'ผู้ดูแลส่วนกลาง สอศ.',
    default        => 'ผู้กรอกข้อมูล',
};

$logoUrl = $school ? school_emblem_url($school) : APP_URL . '/assets/ovec-logo.jpg';
$publicLink = APP_URL . '/public.php?slug=' . ($school['slug'] ?? '') . '&year=' . $yearCode;
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>

<div class="app-shell">
  <!-- ─── SIDEBAR ─── -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="sidebar-logo">
        <img src="<?= e($logoUrl) ?>" alt="ตราสถานศึกษา" id="sidebarLogo">
      </div>
      <div class="sidebar-school">
        <div class="sidebar-school-name"><?= e($school['name'] ?? 'ระบบ OIT') ?></div>
        <div class="sidebar-school-sub">ระบบ OIT · อาชีวศึกษา</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group-label">เมนู</div>
      <?php if (in_array($role, ['user','schooladmin'])): ?>
      <a href="?view=dashboard" class="nav-item <?= $view==='dashboard'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        ภาพรวมความคืบหน้า
      </a>
      <a href="?view=evidence" class="nav-item <?= $view==='evidence'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5M8 13h8M8 17h5"/></svg>
        กรอกหลักฐานตัวชี้วัด
      </a>
      <?php endif; ?>
      <?php if ($role === 'schooladmin'): ?>
      <a href="?view=tracking" class="nav-item <?= $view==='tracking'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        สรุป & ติดตามงาน
      </a>
      <a href="?view=school" class="nav-item <?= $view==='school'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 10h.01M15 10h.01M9 13h.01M15 13h.01"/></svg>
        ข้อมูลสถานศึกษา
      </a>
      <a href="?view=users" class="nav-item <?= $view==='users'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 11.2a3 3 0 0 0 0-5.8M20 19c0-2-1.2-3.6-3-4.4"/></svg>
        จัดการผู้ใช้
      </a>
      <?php endif; ?>
      <?php if ($role === 'centraladmin'): ?>
      <a href="?view=criteria" class="nav-item <?= $view==='criteria'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2" fill="var(--surface)"/><circle cx="15" cy="12" r="2" fill="var(--surface)"/><circle cx="7" cy="18" r="2" fill="var(--surface)"/></svg>
        เกณฑ์ & ปีงบประมาณ
      </a>
      <a href="?view=schools" class="nav-item <?= $view==='schools'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 10h.01M15 10h.01M9 13h.01M15 13h.01"/></svg>
        สถานศึกษาที่สมัคร
      </a>
      <a href="?view=positions" class="nav-item <?= $view==='positions'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        จัดการตำแหน่งกลาง
      </a>
      <a href="?view=migration" class="nav-item <?= $view==='migration'?'active':'' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg>
        ปรับปรุงฐานข้อมูล
        <?php if ($migPending): ?><span class="nav-badge"><?= (int)$migPending ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <div class="nav-group-label" style="margin-top:18px">สาธารณะ</div>
      <a href="<?= e($publicLink) ?>" target="_blank" class="nav-item">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
        หน้าแสดงผลสาธารณะ
      </a>
    </nav>
    <div class="sidebar-user">
      <?php if ($avatarUrl = user_avatar_url($user)): ?>
      <div class="user-avatar user-avatar-img"><img src="<?= e($avatarUrl) ?>" alt=""></div>
      <?php else: ?>
      <div class="user-avatar"><?= e($initial) ?></div>
      <?php endif; ?>
      <div class="user-info">
        <div class="user-name"><?= e($user['name']) ?></div>
        <div class="user-role"><?= e($roleLabel) ?></div>
      </div>
      <a href="<?= APP_URL ?>/logout.php" title="<?= $impersonating ? 'กลับสู่บัญชีผู้ดูแล' : 'ออกจากระบบ' ?>" class="logout-btn">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      </a>
    </div>
  </aside>

  <!-- ─── MAIN ─── -->
  <div class="main-wrap">
    <header class="topbar">
      <div class="topbar-left">
        <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
        <span class="year-badge"><?= e($yearLabel) ?></span>
      </div>
      <div class="topbar-right">
        <div class="year-switcher">
          <?php foreach ($years as $y): ?>
          <a href="?year=<?= e($y['year_code']) ?>&view=<?= e($view) ?>"
             class="year-opt <?= $y['year_code']===$yearCode?'active':'' ?>">
            <?= e($y['year_code']) ?>
          </a>
          <?php endforeach; ?>
        </div>
        <div class="theme-switcher">
          <a href="?theme=light&view=<?= e($view) ?>" class="theme-opt <?= $theme==='light'?'active':'' ?>">สว่าง</a>
          <a href="?theme=dark&view=<?= e($view) ?>" class="theme-opt <?= $theme==='dark'?'active':'' ?>">มืด</a>
          <a href="?theme=system&view=<?= e($view) ?>" class="theme-opt <?= $theme==='system'?'active':'' ?>">ระบบ</a>
        </div>
      </div>
    </header>
    <?php if ($impersonating): ?>
    <div class="imp-banner">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3 3-2-2"/></svg>
      <span>กำลังใช้งานในนาม <strong><?= e($user['name']) ?></strong> · สวมสิทธิ์โดย <?= e($impersonatorName) ?></span>
      <a href="<?= APP_URL ?>/logout.php" class="imp-return">กลับสู่บัญชีผู้ดูแล</a>
    </div>
    <?php endif; ?>
    <div class="main-content <?= $view === 'evidence' ? 'main-content--full' : '' ?>" id="mainContent">
      <?php
      $viewFile = __DIR__ . '/views/' . $view . '.php';
      if (file_exists($viewFile)) {
          include $viewFile;
      }
      ?>
    </div>
  </div>
</div>

<div id="toast" class="toast hidden"></div>

<!-- Confirm modal -->
<div id="confirmOverlay" class="modal-overlay hidden" aria-hidden="true">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="modal-icon" id="confirmIcon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <div class="modal-title" id="confirmTitle">ยืนยันการทำรายการ</div>
    <div class="modal-msg" id="confirmMsg"></div>
    <div class="modal-actions">
      <button type="button" class="btn-modal btn-modal-ghost" id="confirmCancel">ยกเลิก</button>
      <button type="button" class="btn-modal btn-modal-primary" id="confirmOk">ยืนยัน</button>
    </div>
  </div>
</div>

<script>
const APP_URL   = '<?= APP_URL ?>';
const CSRF_TOKEN = '<?= csrf_token() ?>';
const SCHOOL_ID = <?= $schoolId ?>;
const YEAR_CODE = '<?= e($yearCode) ?>';
const USER_ROLE = '<?= e($role) ?>';
</script>
<script src="<?= asset('/assets/js/app.js') ?>"></script>
</body>
</html>
