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

// Flatten all indicators + their evidences.
// Every indicator is listed publicly, but attached documents are shown only
// for indicators the school has marked "เผยแพร่แล้ว" (done). Indicators in any
// other status list with a "ยังไม่มีเอกสารเผยแพร่" note instead of their files.
$allInds = [];
foreach ($tree as $sec) {
    foreach ($sec['subs'] as $sub) {
        foreach ($sub['inds'] as $ind) {
            $published = ($ind['status'] ?? '') === 'done';
            if ($published) {
                // Only evidence the responsible has accepted is published
                $evStmt = db()->prepare('SELECT * FROM evidences WHERE indicator_id = ? AND school_id = ? AND accepted = 1 ORDER BY sort_order ASC, id ASC');
                $evStmt->execute([$ind['id'], $school['id']]);
                $ind['evidences'] = $evStmt->fetchAll();
            } else {
                $ind['evidences'] = [];
            }
            $ind['published'] = $published;
            $ind['sec_code']  = $sec['code'];
            $ind['sub_code']  = $sub['code'];
            $allInds[] = $ind;
        }
    }
}

$logoUrl = school_emblem_url($school);
$r = $stats['pct'];
$circ = 2 * M_PI * 50;
// Two stacked arcs: "เผยแพร่แล้ว" (done) then "กำลังดำเนินการ" (in progress).
$total    = max(1, (int)$stats['total']);
$doneDash = $circ * (int)$stats['done'] / $total;
$progDash = $circ * (int)$stats['prog'] / $total;

/** Render the evidence list HTML for one indicator (public, read-only) */
function render_pub_ev(array $ind): string
{
    if (empty($ind['published'])) return '<div class="pub-no-ev">ยังไม่มีเอกสารเผยแพร่</div>';
    if (empty($ind['evidences'])) return '<div class="pub-no-ev">ยังไม่มีหลักฐานที่เผยแพร่</div>';
    $linkIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
    $fileIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5"/></svg>';
    $out = '<div class="pub-ev-list">';
    foreach ($ind['evidences'] as $ev) {
        $isImg   = ($ev['type'] === 'image') || ($ev['file_path'] && in_array(strtolower(pathinfo($ev['file_path'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']));
        $fileUrl = $ev['file_path'] ? APP_URL . '/uploads/' . rawurlencode($ev['file_path']) : null;
        $out .= '<div class="pub-ev-item">';
        if ($isImg && $fileUrl) {
            $out .= '<a href="' . $fileUrl . '" target="_blank" class="pub-ev-thumb"><img src="' . $fileUrl . '" alt="' . e($ev['title']) . '" loading="lazy"></a>';
            $out .= '<a href="' . $fileUrl . '" target="_blank" class="pub-ev-link">' . e($ev['title']) . '</a>';
        } elseif (!empty($ev['url'])) {
            $out .= '<a href="' . e($ev['url']) . '" target="_blank" rel="noopener" class="pub-ev-link">' . $linkIcon . e($ev['title']) . '</a>';
        } elseif ($fileUrl) {
            $out .= '<a href="' . $fileUrl . '" target="_blank" class="pub-ev-link">' . $fileIcon . e($ev['title']) . '</a>';
        } else {
            $out .= '<span class="pub-ev-name">' . e($ev['title']) . '</span>';
        }
        if (!empty($ev['note'])) $out .= '<span class="pub-ev-note">' . e($ev['note']) . '</span>';
        $out .= '</div>';
    }
    return $out . '</div>';
}

// Section title lookup by code (for menu grouping)
$secTitle = [];
foreach ($tree as $s) { $secTitle[$s['code']] = $s['title']; }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OIT — <?= e($school['name']) ?> ปี <?= e($yearCode) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/public.css') ?>">
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
        <?php if ($progDash > 0): ?>
        <circle cx="60" cy="60" r="50" fill="none" stroke="#D9A441" stroke-width="12"
                stroke-dasharray="<?= round($progDash,2) ?> <?= round($circ,2) ?>"
                stroke-dashoffset="<?= round($circ/4 - $doneDash,2) ?>"/>
        <?php endif; ?>
        <?php if ($doneDash > 0): ?>
        <circle cx="60" cy="60" r="50" fill="none" stroke="#7A1E28" stroke-width="12"
                stroke-dasharray="<?= round($doneDash,2) ?> <?= round($circ,2) ?>"
                stroke-dashoffset="<?= round($circ/4,2) ?>"
                stroke-linecap="round"/>
        <?php endif; ?>
        <text x="60" y="57" text-anchor="middle" font-size="22" font-weight="700" fill="#2A2520"><?= $r ?>%</text>
        <text x="60" y="73" text-anchor="middle" font-size="9" fill="#7E7267">เผยแพร่แล้ว</text>
      </svg>
      <div class="pub-ring-legend">
        <span><i style="background:#7A1E28"></i>เผยแพร่แล้ว</span>
        <span><i style="background:#D9A441"></i>กำลังดำเนินการ</span>
      </div>
    </div>
    <div class="pub-stat-row">
      <div class="pub-stat">
        <div class="pub-stat-ic">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
        </div>
        <div><span class="pub-stat-n"><?= $stats['total'] ?></span><span class="pub-stat-l">ตัวชี้วัด</span></div>
      </div>
      <div class="pub-stat pub-stat-done">
        <div class="pub-stat-ic">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div><span class="pub-stat-n"><?= $stats['done'] ?></span><span class="pub-stat-l">เผยแพร่แล้ว</span></div>
      </div>
      <div class="pub-stat pub-stat-prog">
        <div class="pub-stat-ic">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg>
        </div>
        <div><span class="pub-stat-n"><?= $stats['prog'] ?></span><span class="pub-stat-l">ดำเนินการ</span></div>
      </div>
      <div class="pub-stat">
        <div class="pub-stat-ic">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        </div>
        <div><span class="pub-stat-n"><?= $stats['ev_cnt'] ?></span><span class="pub-stat-l">หลักฐาน</span></div>
      </div>
    </div>
  </div>

  <!-- TWO-PANE: indicator menu (left) + evidence detail (right) -->
  <div class="pub-panes">

    <!-- LEFT: indicator menu -->
    <aside class="pub-menu">
      <div class="pub-menu-search">
        <input type="search" id="pubSearch" placeholder="ค้นหาตัวชี้วัด…" autocomplete="off">
      </div>
      <div class="pub-menu-body">
        <?php $curSec = null; foreach ($allInds as $ind):
          if ($ind['sec_code'] !== $curSec):
            if ($curSec !== null) echo '</div>';
            $curSec = $ind['sec_code'];
            echo '<div class="pub-menu-sec">';
            echo '<div class="pub-menu-sec-hdr"><span class="pub-sec-code">' . e($curSec) . '</span><span>' . e($secTitle[$curSec] ?? '') . '</span></div>';
          endif;
          $evc = count($ind['evidences']);
        ?>
        <button type="button" class="pub-menu-item status-<?= e($ind['status']) ?>"
                data-target="ind<?= (int)$ind['id'] ?>" data-code="<?= e($ind['code']) ?>"
                data-title="<?= e($ind['title']) ?>">
          <span class="pub-mi-code"><?= e($ind['code']) ?></span>
          <span class="pub-mi-title"><?= e($ind['title']) ?></span>
          <?php if ($evc): ?><span class="pub-mi-badge"><?= $evc ?></span><?php endif; ?>
        </button>
        <?php endforeach; if ($curSec !== null) echo '</div>'; ?>
      </div>
    </aside>

    <!-- RIGHT: evidence detail -->
    <section class="pub-detail" id="pubDetail">
      <?php foreach ($allInds as $ind): ?>
      <article class="pub-detail-panel" id="ind<?= (int)$ind['id'] ?>" hidden>
        <div class="pub-detail-head">
          <span class="pub-detail-code"><?= e($ind['code']) ?></span>
          <?= status_chip($ind['status']) ?>
        </div>
        <h2 class="pub-detail-title"><?= e($ind['title']) ?></h2>
        <?php if (!empty($ind['criteria'])): ?>
        <details class="pub-detail-criteria">
          <summary class="pub-detail-criteria-hdr">
            เกณฑ์การพิจารณา
            <svg class="pub-criteria-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </summary>
          <div class="pub-detail-criteria-body"><?= nl2br(e($ind['criteria'])) ?></div>
        </details>
        <?php endif; ?>
        <div class="pub-detail-ev">
          <div class="pub-detail-ev-hdr">หลักฐานที่เผยแพร่<?= $ind['published'] ? ' (' . count($ind['evidences']) . ')' : '' ?></div>
          <?= render_pub_ev($ind) ?>
        </div>
      </article>
      <?php endforeach; ?>
    </section>

  </div>

</div>

<script>
(function () {
  var items  = Array.prototype.slice.call(document.querySelectorAll('.pub-menu-item'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.pub-detail-panel'));
  var detail = document.getElementById('pubDetail');
  if (!items.length) return;

  function show(target, scroll) {
    panels.forEach(function (p) { p.hidden = (p.id !== target); });
    items.forEach(function (b) { b.classList.toggle('active', b.dataset.target === target); });
    if (scroll && window.matchMedia('(max-width: 900px)').matches) {
      detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  items.forEach(function (b) {
    b.addEventListener('click', function () {
      show(b.dataset.target, true);
      history.replaceState(null, '', '#' + b.dataset.code);
    });
  });

  // Initial selection from URL hash or first item
  var hash = decodeURIComponent(location.hash.replace('#', ''));
  var start = hash ? items.filter(function (b) { return b.dataset.code === hash; })[0] : null;
  start = start || items[0];
  show(start.dataset.target, false);

  // Search filter
  var search = document.getElementById('pubSearch');
  search.addEventListener('input', function () {
    var q = this.value.toLowerCase().trim();
    items.forEach(function (b) {
      var hit = (b.dataset.code + ' ' + b.dataset.title).toLowerCase().indexOf(q) !== -1;
      b.style.display = hit ? '' : 'none';
    });
    document.querySelectorAll('.pub-menu-sec').forEach(function (sec) {
      var any = Array.prototype.some.call(sec.querySelectorAll('.pub-menu-item'), function (b) { return b.style.display !== 'none'; });
      sec.style.display = any ? '' : 'none';
    });
  });
})();
</script>

<footer class="pub-footer">
  <div>ข้อมูล ณ วันที่ <?= thai_date(date('Y-m-d')) ?> · จัดทำโดยระบบ OIT อาชีวศึกษา</div>
  <a class="pub-login-link" href="<?= APP_URL ?>/auth.php?mode=login">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
    เข้าสู่ระบบสำหรับเจ้าหน้าที่
  </a>
</footer>
</body>
</html>
