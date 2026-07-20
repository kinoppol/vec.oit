<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$slug     = trim($_GET['slug'] ?? '');
$yearCode = trim($_GET['year'] ?? '');

// Default to the active fiscal year when none is supplied
if ($yearCode === '') {
    $yearCode = active_year()['year_code'];
}

// ── OVERVIEW: no school selected → public landing of every registered school ──
if ($slug === '') {
    include __DIR__ . '/includes/public_overview.php';
    exit;
}

$stmt = db()->prepare('SELECT * FROM schools WHERE slug = ? AND status = "active"');
$stmt->execute([$slug]);
$school = $stmt->fetch();
if (!$school) {
    http_response_code(404);
    exit('ไม่พบสถานศึกษาหรือยังไม่เปิดใช้งาน');
}

$fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE year_code = ?');
$fyStmt->execute([$yearCode]);
$fy = $fyStmt->fetch();
if (!$fy) {
    http_response_code(404);
    exit('ไม่พบปีงบประมาณ');
}

$tree  = indicator_tree((int)$school['id'], $yearCode);
$stats = dashboard_stats((int)$school['id'], $yearCode);

// Flatten all indicators + their evidences
$allInds = [];
foreach ($tree as $sec) {
    foreach ($sec['subs'] as $sub) {
        foreach ($sub['inds'] as $ind) {
            // Load evidences
            $evStmt = db()->prepare('SELECT * FROM evidences WHERE indicator_id = ? AND school_id = ? ORDER BY created_at DESC');
            $evStmt->execute([$ind['id'], $school['id']]);
            $ind['evidences'] = $evStmt->fetchAll();
            $ind['sec_code']  = $sec['code'];
            $ind['sub_code']  = $sub['code'];
            $allInds[] = $ind;
        }
    }
}

$logoUrl = school_emblem_url($school);
$r = $stats['pct'];
$circ = 2 * M_PI * 50;
$pctDash = $circ * $r / 100;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OIT — <?= e($school['name']) ?> ปี <?= e($yearCode) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/public.css">
</head>
<body>

<header class="pub-header">
  <div class="pub-header-inner">
    <img src="<?= e($logoUrl) ?>" alt="ตรา" class="pub-logo">
    <div>
      <div class="pub-school-name"><?= e($school['name']) ?></div>
      <div class="pub-sub">การเปิดเผยข้อมูลสาธารณะ (OIT) · <?= e($fy['label']) ?></div>
    </div>
    <div class="pub-ovec">
      <img src="<?= APP_URL ?>/assets/ovec-logo.jpg" alt="สอศ." class="pub-ovec-logo">
      <span>สำนักงานคณะกรรมการ<br>การอาชีวศึกษา</span>
    </div>
  </div>
</header>

<div class="pub-wrap">
  <!-- SUMMARY STRIP -->
  <div class="pub-summary">
    <div class="pub-ring-wrap">
      <svg class="ring" viewBox="0 0 120 120">
        <circle cx="60" cy="60" r="50" fill="none" stroke="#e8e0da" stroke-width="12"/>
        <circle cx="60" cy="60" r="50" fill="none" stroke="#7A1E28" stroke-width="12"
                stroke-dasharray="<?= round($pctDash,2) ?> <?= round($circ,2) ?>"
                stroke-dashoffset="<?= round($circ/4,2) ?>"
                stroke-linecap="round"/>
        <text x="60" y="57" text-anchor="middle" font-size="22" font-weight="700" fill="#2A2520"><?= $r ?>%</text>
        <text x="60" y="73" text-anchor="middle" font-size="9" fill="#7E7267">เผยแพร่แล้ว</text>
      </svg>
    </div>
    <div class="pub-stat-row">
      <div class="pub-stat"><span class="pub-stat-n"><?= $stats['total'] ?></span><span class="pub-stat-l">ตัวชี้วัด</span></div>
      <div class="pub-stat pub-stat-done"><span class="pub-stat-n"><?= $stats['done'] ?></span><span class="pub-stat-l">เผยแพร่แล้ว</span></div>
      <div class="pub-stat pub-stat-prog"><span class="pub-stat-n"><?= $stats['prog'] ?></span><span class="pub-stat-l">ดำเนินการ</span></div>
      <div class="pub-stat"><span class="pub-stat-n"><?= $stats['ev_cnt'] ?></span><span class="pub-stat-l">หลักฐาน</span></div>
    </div>
  </div>

  <!-- INDICATOR LIST -->
  <div class="pub-ind-list">
    <?php
    $curSec = null; $curSub = null;
    foreach ($allInds as $ind):
      if ($ind['sec_code'] !== $curSec):
        if ($curSec !== null) echo '</div>'; // close prev section
        $curSec = $ind['sec_code'];
        echo '<div class="pub-section">';
        // Find section title
        foreach ($tree as $s) {
            if ($s['code'] === $curSec) { echo '<div class="pub-sec-hdr"><span class="pub-sec-code">' . e($s['code']) . '</span><span>' . e($s['title']) . '</span></div>'; break; }
        }
      endif;
    ?>
    <div class="pub-ind-card <?= $ind['status'] === 'done' ? 'pub-ind-done' : ($ind['status'] === 'inprogress' ? 'pub-ind-prog' : 'pub-ind-pend') ?>">
      <div class="pub-ind-hdr">
        <span class="pub-ind-code"><?= e($ind['code']) ?></span>
        <span class="pub-ind-title"><?= e($ind['title']) ?></span>
        <?= status_chip($ind['status']) ?>
      </div>
      <?php if (!empty($ind['evidences'])): ?>
      <div class="pub-ev-list">
        <?php foreach ($ind['evidences'] as $ev): ?>
        <div class="pub-ev-item">
          <?php if ($ev['url']): ?>
          <a href="<?= e($ev['url']) ?>" target="_blank" rel="noopener" class="pub-ev-link">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            <?= e($ev['title']) ?>
          </a>
          <?php elseif ($ev['file_path']): ?>
          <a href="<?= APP_URL ?>/uploads/<?= rawurlencode($ev['file_path']) ?>" target="_blank" class="pub-ev-link">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5"/></svg>
            <?= e($ev['title']) ?>
          </a>
          <?php else: ?>
          <span class="pub-ev-name"><?= e($ev['title']) ?></span>
          <?php endif; ?>
          <?php if ($ev['note']): ?>
          <span class="pub-ev-note"><?= e($ev['note']) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="pub-no-ev">ยังไม่มีหลักฐาน</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($curSec !== null) echo '</div>'; ?>
  </div>

</div>

<footer class="pub-footer">
  <div>ข้อมูล ณ วันที่ <?= thai_date(date('Y-m-d')) ?> · จัดทำโดยระบบ OIT อาชีวศึกษา</div>
</footer>
</body>
</html>
