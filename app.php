<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_auth();
$role = $user['role'];

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
    'centraladmin' => ['criteria', 'schools'],
    'schooladmin'  => ['dashboard', 'evidence', 'users'],
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
    'users'     => 'จัดการผู้ใช้',
    'criteria'  => 'เกณฑ์ & ปีงบประมาณ',
    'schools'   => 'สถานศึกษาที่สมัครใช้งาน',
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
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
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
      <?php endif; ?>
      <div class="nav-group-label" style="margin-top:18px">สาธารณะ</div>
      <a href="<?= e($publicLink) ?>" target="_blank" class="nav-item">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
        หน้าแสดงผลสาธารณะ
      </a>
    </nav>
    <div class="sidebar-user">
      <div class="user-avatar"><?= e($initial) ?></div>
      <div class="user-info">
        <div class="user-name"><?= e($user['name']) ?></div>
        <div class="user-role"><?= e($roleLabel) ?></div>
      </div>
      <a href="<?= APP_URL ?>/logout.php" title="ออกจากระบบ" class="logout-btn">
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

<script>
const APP_URL   = '<?= APP_URL ?>';
const CSRF_TOKEN = '<?= csrf_token() ?>';
const SCHOOL_ID = <?= $schoolId ?>;
const YEAR_CODE = '<?= e($yearCode) ?>';
const USER_ROLE = '<?= e($role) ?>';
</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
