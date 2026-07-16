<?php
// $schoolId, $yearCode, $user already set in app.php
$stats = dashboard_stats($schoolId, $yearCode);
$tree  = indicator_tree($schoolId, $yearCode);

// Pending indicators list (top 6)
$pending = [];
foreach ($tree as $sec) {
    foreach ($sec['subs'] as $sub) {
        foreach ($sub['inds'] as $ind) {
            if ($ind['status'] !== 'done') {
                $pending[] = $ind;
            }
        }
    }
}
$topPending = array_slice($pending, 0, 6);
?>
<div class="dashboard-grid">

  <!-- ─── RING + STATS ─── -->
  <div class="card dash-hero">
    <div class="ring-wrap">
      <svg class="ring" viewBox="0 0 120 120">
        <?php
        $r = 50; $cx = 60; $cy = 60;
        $circ = 2 * M_PI * $r;
        $pctDash = ($circ * $stats['pct'] / 100);
        ?>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="var(--ring-bg)" stroke-width="12"/>
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="var(--primary)" stroke-width="12"
                stroke-dasharray="<?= round($pctDash,2) ?> <?= round($circ,2) ?>"
                stroke-dashoffset="<?= round($circ / 4, 2) ?>"
                stroke-linecap="round"/>
        <text x="60" y="57" text-anchor="middle" font-size="22" font-weight="700" fill="var(--text-primary)"><?= $stats['pct'] ?>%</text>
        <text x="60" y="73" text-anchor="middle" font-size="9" fill="var(--text-secondary)">เผยแพร่แล้ว</text>
      </svg>
    </div>
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-num"><?= $stats['total'] ?></div>
        <div class="stat-lbl">ตัวชี้วัดทั้งหมด</div>
      </div>
      <div class="stat-card done">
        <div class="stat-num"><?= $stats['done'] ?></div>
        <div class="stat-lbl">เผยแพร่แล้ว</div>
      </div>
      <div class="stat-card prog">
        <div class="stat-num"><?= $stats['prog'] ?></div>
        <div class="stat-lbl">กำลังดำเนินการ</div>
      </div>
      <div class="stat-card pend">
        <div class="stat-num"><?= $stats['pending'] ?></div>
        <div class="stat-lbl">ยังไม่ดำเนินการ</div>
      </div>
      <div class="stat-card ev">
        <div class="stat-num"><?= $stats['ev_cnt'] ?></div>
        <div class="stat-lbl">หลักฐานทั้งหมด</div>
      </div>
    </div>
  </div>

  <!-- ─── SECTION PROGRESS ─── -->
  <div class="card section-progress-card">
    <div class="card-header">ความคืบหน้าตามหมวด</div>
    <?php foreach ($tree as $sec):
      $total = 0; $done = 0;
      foreach ($sec['subs'] as $sub) {
          foreach ($sub['inds'] as $ind) {
              $total++;
              if ($ind['status'] === 'done') $done++;
          }
      }
      $pct = $total > 0 ? round($done / $total * 100) : 0;
    ?>
    <div class="sec-bar-row">
      <div class="sec-bar-label">
        <span class="sec-code"><?= e($sec['code']) ?></span>
        <span class="sec-title"><?= e($sec['title']) ?></span>
      </div>
      <div class="sec-bar-track">
        <div class="sec-bar-fill" style="width:<?= $pct ?>%"></div>
      </div>
      <div class="sec-bar-pct"><?= $done ?>/<?= $total ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ─── SUBSECTION DETAIL ─── -->
  <div class="card subsec-card">
    <div class="card-header">สถานะรายหมวดย่อย</div>
    <div class="subsec-list">
      <?php foreach ($tree as $sec): foreach ($sec['subs'] as $sub):
        $tot = count($sub['inds']);
        $dn  = count(array_filter($sub['inds'], fn($i) => $i['status'] === 'done'));
        $pp  = count(array_filter($sub['inds'], fn($i) => $i['status'] === 'inprogress'));
        $pe  = $tot - $dn - $pp;
        $pct = $tot > 0 ? round($dn / $tot * 100) : 0;
      ?>
      <div class="subsec-row">
        <div class="subsec-info">
          <span class="subsec-code"><?= e($sub['code']) ?></span>
          <span class="subsec-name"><?= e($sub['title']) ?></span>
        </div>
        <div class="subsec-chips">
          <?php if ($dn): ?><span class="chip chip-done"><?= $dn ?></span><?php endif; ?>
          <?php if ($pp): ?><span class="chip chip-prog"><?= $pp ?></span><?php endif; ?>
          <?php if ($pe): ?><span class="chip chip-pend"><?= $pe ?></span><?php endif; ?>
        </div>
        <div class="subsec-pct"><?= $pct ?>%</div>
      </div>
      <?php endforeach; endforeach; ?>
    </div>
  </div>

  <!-- ─── INCOMPLETE LIST ─── -->
  <?php if (!empty($topPending)): ?>
  <div class="card pending-card">
    <div class="card-header">ตัวชี้วัดที่ยังไม่เสร็จ
      <a href="?view=evidence" class="card-link">ดูทั้งหมด →</a>
    </div>
    <div class="pending-list">
      <?php foreach ($topPending as $ind): ?>
      <div class="pending-row">
        <div class="pending-code"><?= e($ind['code']) ?></div>
        <div class="pending-title"><?= e($ind['title']) ?></div>
        <?= status_chip($ind['status']) ?>
        <a href="?view=evidence&indicator=<?= $ind['id'] ?>" class="pending-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
