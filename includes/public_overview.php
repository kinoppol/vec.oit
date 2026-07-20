<?php
/**
 * public_overview.php — Public landing page: overview of every registered school.
 * Included from public.php when no ?slug is given. Expects $yearCode in scope.
 */

$years = all_years();
// Validate requested year, fall back to active
$fy = null;
foreach ($years as $y) {
    if ($y['year_code'] === $yearCode) { $fy = $y; break; }
}
if (!$fy) { $fy = active_year(); $yearCode = $fy['year_code']; }

$schools = db()->query('SELECT * FROM schools WHERE status = "active" ORDER BY name')->fetchAll();

$cards = [];
$sumTotal = $sumDone = $sumEv = $sumPct = 0;
foreach ($schools as $s) {
    $st = dashboard_stats((int)$s['id'], $yearCode);
    $cards[] = ['school' => $s, 'stats' => $st];
    $sumTotal += $st['total'];
    $sumDone  += $st['done'];
    $sumEv    += $st['ev_cnt'];
    $sumPct   += $st['pct'];
}
$n       = count($cards);
$avgPct  = $n > 0 ? round($sumPct / $n) : 0;

// Sort cards by completion desc for a clear "who leads on transparency" read
usort($cards, fn($a, $b) => $b['stats']['pct'] <=> $a['stats']['pct']);

/** Small SVG progress ring for a card */
function ov_ring(int $pct): string
{
    $r = 26; $c = 2 * M_PI * $r;
    $dash = round($c * $pct / 100, 2);
    $off  = round($c / 4, 2);
    $col  = $pct >= 80 ? '#2F7A57' : ($pct >= 40 ? '#A8701A' : '#B03A2E');
    return '<svg class="ov-ring" viewBox="0 0 64 64">'
        . '<circle cx="32" cy="32" r="' . $r . '" fill="none" stroke="#ECE4DC" stroke-width="6"/>'
        . '<circle cx="32" cy="32" r="' . $r . '" fill="none" stroke="' . $col . '" stroke-width="6"'
        . ' stroke-dasharray="' . $dash . ' ' . round($c, 2) . '" stroke-dashoffset="' . $off . '"'
        . ' stroke-linecap="round" transform="rotate(-90 32 32)"/>'
        . '<text x="32" y="36" text-anchor="middle" font-size="15" font-weight="800" fill="#221C18">' . $pct . '%</text>'
        . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>การเปิดเผยข้อมูลสาธารณะ (OIT) · ภาพรวมสถานศึกษา</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/public.css') ?>">
</head>
<body>

<header class="ov-hero">
  <div class="ov-hero-glow" aria-hidden="true"></div>
  <div class="ov-hero-inner">
    <div class="ov-hero-top">
      <img src="<?= APP_URL ?>/assets/ovec-logo.jpg" alt="สอศ." class="ov-hero-logo">
      <span class="ov-hero-org">สำนักงานคณะกรรมการการอาชีวศึกษา</span>
    </div>
    <h1 class="ov-hero-title">การเปิดเผยข้อมูลสาธารณะ<span> (OIT)</span></h1>
    <p class="ov-hero-tagline">โปร่งใส · ตรวจสอบได้ · เปิดเผยต่อสาธารณะ</p>
    <p class="ov-hero-desc">
      ภาพรวมการเปิดเผยข้อมูลตามแบบวัดการเปิดเผยข้อมูลสาธารณะ (OIT)
      ของสถานศึกษาในสังกัดที่เข้าร่วมระบบ ประจำ<?= e($fy['label']) ?>
    </p>

    <form method="get" class="ov-year-form">
      <label for="ovYear">ปีงบประมาณ</label>
      <select id="ovYear" name="year" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
        <option value="<?= e($y['year_code']) ?>" <?= $y['year_code'] === $yearCode ? 'selected' : '' ?>>
          <?= e($y['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</header>

<div class="pub-wrap">

  <!-- AGGREGATE STATS -->
  <div class="ov-metrics">
    <div class="ov-metric">
      <div class="ov-metric-n"><?= $n ?></div>
      <div class="ov-metric-l">สถานศึกษาที่เข้าร่วม</div>
    </div>
    <div class="ov-metric ov-metric-accent">
      <div class="ov-metric-n"><?= $avgPct ?><span>%</span></div>
      <div class="ov-metric-l">ค่าเฉลี่ยการเปิดเผย</div>
    </div>
    <div class="ov-metric">
      <div class="ov-metric-n"><?= number_format($sumDone) ?></div>
      <div class="ov-metric-l">ตัวชี้วัดที่เผยแพร่แล้ว</div>
    </div>
    <div class="ov-metric">
      <div class="ov-metric-n"><?= number_format($sumEv) ?></div>
      <div class="ov-metric-l">หลักฐานที่เปิดเผย</div>
    </div>
  </div>

  <!-- SCHOOL GRID -->
  <?php if ($n === 0): ?>
    <div class="ov-empty">ยังไม่มีสถานศึกษาที่เปิดเผยข้อมูลในปีงบประมาณนี้</div>
  <?php else: ?>
  <div class="ov-grid">
    <?php foreach ($cards as $card):
      $s = $card['school']; $st = $card['stats'];
      $link = APP_URL . '/public.php?slug=' . rawurlencode($s['slug']) . '&year=' . rawurlencode($yearCode);
    ?>
    <a class="ov-card" href="<?= e($link) ?>">
      <div class="ov-card-top">
        <img src="<?= e(school_emblem_url($s)) ?>" alt="" class="ov-card-emblem">
        <?= ov_ring((int)$st['pct']) ?>
      </div>
      <div class="ov-card-name"><?= e($s['name']) ?></div>
      <div class="ov-card-bar">
        <span style="width: <?= (int)$st['pct'] ?>%"></span>
      </div>
      <div class="ov-card-stats">
        <span><b><?= $st['done'] ?></b>/<?= $st['total'] ?> ตัวชี้วัด</span>
        <span><b><?= $st['ev_cnt'] ?></b> หลักฐาน</span>
      </div>
      <div class="ov-card-cta">
        ดูรายละเอียด
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<footer class="pub-footer">
  <div>ข้อมูล ณ วันที่ <?= thai_date(date('Y-m-d')) ?> · จัดทำโดยระบบเปิดเผยข้อมูลสาธารณะ (OIT) อาชีวศึกษา</div>
</footer>
</body>
</html>
