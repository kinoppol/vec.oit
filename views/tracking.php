<?php
require_role('schooladmin');

$tree = indicator_tree($schoolId, $yearCode);

// Flatten indicators and group by responsible party
$all = [];
foreach ($tree as $sec) {
    foreach ($sec['subs'] as $sub) {
        foreach ($sub['inds'] as $ind) {
            $ind['sec_code'] = $sec['code'];
            $all[] = $ind;
        }
    }
}

$groups = [];
foreach ($all as $ind) {
    $avatar = null;
    if (!empty($ind['assignee_pos_name'])) { $k = 'p|' . $ind['assignee_pos_name']; $label = $ind['assignee_pos_name']; $type = 'position'; }
    elseif (!empty($ind['assignee_name']))  { $k = 'u|' . $ind['assignee_name'];  $label = $ind['assignee_name'];  $type = 'user'; $avatar = $ind['assignee_avatar'] ?? null; }
    else { $k = 'none'; $label = 'ยังไม่มอบหมาย'; $type = 'none'; }

    $groups[$k] ??= ['label' => $label, 'type' => $type, 'avatar' => $avatar, 'inds' => [], 'done' => 0, 'prog' => 0, 'pend' => 0];
    $groups[$k]['inds'][] = $ind;
    if ($ind['status'] === 'done')            $groups[$k]['done']++;
    elseif ($ind['status'] === 'inprogress')  $groups[$k]['prog']++;
    else                                      $groups[$k]['pend']++;
}
// Map each position name → its active holders (for avatars + names in the header)
$posUsers = [];
$puStmt = db()->prepare('
    SELECT p.name AS pos_name, u.full_name, u.avatar
    FROM positions p
    JOIN user_positions up ON up.position_id = p.id
    JOIN users u ON u.id = up.user_id
    WHERE p.school_id = ? AND u.status = "active"
    ORDER BY p.name, u.full_name
');
$puStmt->execute([$schoolId]);
foreach ($puStmt->fetchAll() as $r) {
    $posUsers[$r['pos_name']][] = ['name' => $r['full_name'], 'avatar' => $r['avatar']];
}
foreach ($groups as &$g) {
    $g['users'] = $g['type'] === 'position' ? ($posUsers[$g['label']] ?? []) : [];
}
unset($g);

$groups = array_values($groups);
usort($groups, function ($a, $b) {
    if (($a['type'] === 'none') !== ($b['type'] === 'none')) return $a['type'] === 'none' ? 1 : -1;
    return strcmp($a['label'], $b['label']);
});

// Overall summary
$total = count($all);
$done  = array_sum(array_column($groups, 'done'));
$prog  = array_sum(array_column($groups, 'prog'));
$pend  = $total - $done - $prog;
$unassigned = 0;
foreach ($groups as $g) { if ($g['type'] === 'none') $unassigned = count($g['inds']); }
$assigned = $total - $unassigned;
$pct = $total ? round($done / $total * 100) : 0;
?>
<div class="track-layout">

  <!-- SUMMARY -->
  <div class="track-summary">
    <div class="track-metric">
      <div class="track-ring">
        <svg viewBox="0 0 120 120">
          <circle cx="60" cy="60" r="50" fill="none" stroke="var(--surface-3)" stroke-width="12"/>
          <circle cx="60" cy="60" r="50" fill="none" stroke="var(--primary)" stroke-width="12" stroke-linecap="round"
                  stroke-dasharray="<?= round(2*M_PI*50*$pct/100,2) ?> <?= round(2*M_PI*50,2) ?>"
                  stroke-dashoffset="<?= round(2*M_PI*50/4,2) ?>"/>
          <text x="60" y="58" text-anchor="middle" font-size="24" font-weight="800" fill="var(--text-primary)"><?= $pct ?>%</text>
          <text x="60" y="76" text-anchor="middle" font-size="10" fill="var(--text-tertiary)">เผยแพร่แล้ว</text>
        </svg>
      </div>
      <div class="track-stat-grid">
        <div class="track-stat"><span class="track-n"><?= $total ?></span><span class="track-l">ตัวชี้วัด</span></div>
        <div class="track-stat track-done"><span class="track-n"><?= $done ?></span><span class="track-l">เผยแพร่แล้ว</span></div>
        <div class="track-stat track-prog"><span class="track-n"><?= $prog ?></span><span class="track-l">กำลังดำเนินการ</span></div>
        <div class="track-stat"><span class="track-n"><?= $pend ?></span><span class="track-l">ยังไม่ดำเนินการ</span></div>
        <div class="track-stat"><span class="track-n"><?= $assigned ?></span><span class="track-l">มอบหมายแล้ว</span></div>
        <div class="track-stat <?= $unassigned ? 'track-warn' : '' ?>"><span class="track-n"><?= $unassigned ?></span><span class="track-l">ยังไม่มอบหมาย</span></div>
      </div>
    </div>
  </div>

  <!-- TOOLBAR -->
  <div class="track-toolbar">
    <input type="search" id="trackSearch" class="form-input" placeholder="ค้นหาตัวชี้วัด / ผู้รับผิดชอบ…" autocomplete="off">
    <div class="track-filter" id="trackFilter">
      <button class="track-fbtn active" data-f="all">ทั้งหมด</button>
      <button class="track-fbtn" data-f="pending">ยังไม่ดำเนินการ</button>
      <button class="track-fbtn" data-f="inprogress">กำลังดำเนินการ</button>
      <button class="track-fbtn" data-f="done">เผยแพร่แล้ว</button>
    </div>
  </div>

  <!-- GROUPS -->
  <div class="track-groups" id="trackGroups">
    <?php foreach ($groups as $g):
      $gt = count($g['inds']); $gpct = $gt ? round($g['done'] / $gt * 100) : 0;
    ?>
    <div class="track-group" data-label="<?= e(mb_strtolower($g['label'] . ' ' . implode(' ', array_column($g['users'] ?? [], 'name')))) ?>">
      <div class="track-group-hdr">
        <?php
          // Position groups render the actual holders' photos; a single holder
          // shows one avatar, several show a stacked cluster.
          $holders = $g['users'] ?? [];
        ?>
        <?php if ($g['type'] === 'position' && count($holders) > 1): ?>
        <span class="track-avatar-stack" title="<?= e(implode(', ', array_column($holders, 'name'))) ?>">
          <?php foreach (array_slice($holders, 0, 3) as $h): ?>
          <?= user_avatar_html(['full_name' => $h['name'], 'avatar' => $h['avatar']], 'avatar-sm track-stack-av') ?>
          <?php endforeach; ?>
          <?php if (count($holders) > 3): ?><span class="avatar avatar-sm track-stack-av track-stack-more">+<?= count($holders) - 3 ?></span><?php endif; ?>
        </span>
        <?php elseif ($g['type'] === 'position' && count($holders) === 1): ?>
        <?= user_avatar_html(['full_name' => $holders[0]['name'], 'avatar' => $holders[0]['avatar']], 'track-avatar-one') ?>
        <?php elseif (!empty($g['avatar'])): ?>
        <span class="track-avatar track-avatar-<?= e($g['type']) ?> track-avatar-img">
          <img src="<?= e(user_avatar_url(['avatar' => $g['avatar']])) ?>" alt="">
        </span>
        <?php else: ?>
        <span class="track-avatar track-avatar-<?= e($g['type']) ?>">
          <?php if ($g['type'] === 'position'): ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <?php elseif ($g['type'] === 'user'): ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <?php else: ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
          <?php endif; ?>
        </span>
        <?php endif; ?>
        <div class="track-group-title">
          <div class="track-group-name">
            <?= e($g['label']) ?>
            <?php if ($g['type'] === 'position' && $holders): ?><span class="track-holders">(<?= e(implode(', ', array_column($holders, 'name'))) ?>)</span><?php endif; ?>
            <?php if ($g['type'] === 'position'): ?><span class="track-typetag">ตำแหน่ง</span><?php endif; ?>
          </div>
          <div class="track-group-sub"><?= $gt ?> ตัวชี้วัด · เสร็จ <?= $g['done'] ?> · ดำเนินการ <?= $g['prog'] ?> · ค้าง <?= $g['pend'] ?></div>
        </div>
        <div class="track-group-pct"><?= $gpct ?>%</div>
      </div>
      <div class="track-bar"><span style="width:<?= $gpct ?>%"></span></div>
      <div class="track-inds">
        <?php foreach ($g['inds'] as $ind): ?>
        <a class="track-ind status-<?= e($ind['status']) ?>" data-status="<?= e($ind['status']) ?>"
           data-text="<?= e(mb_strtolower($ind['code'].' '.$ind['title'])) ?>"
           href="?view=evidence&indicator=<?= (int)$ind['id'] ?>">
          <span class="track-ind-code"><?= e($ind['code']) ?></span>
          <span class="track-ind-title"><?= e($ind['title']) ?></span>
          <?php if (!empty($ind['ev_count'])): ?><span class="track-ind-ev"><?= (int)$ind['ev_count'] ?> หลักฐาน</span><?php endif; ?>
          <?= status_chip($ind['status']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div id="trackEmpty" class="track-empty hidden">ไม่พบตัวชี้วัดที่ตรงกับเงื่อนไข</div>
</div>

<script>
(function () {
    const search = document.getElementById('trackSearch');
    const groups = Array.prototype.slice.call(document.querySelectorAll('.track-group'));
    let statusFilter = 'all';

    function apply() {
        const q = search.value.toLowerCase().trim();
        let anyVisible = false;
        groups.forEach(g => {
            const matchLabel = !q || (g.dataset.label.indexOf(q) !== -1);
            let shown = 0;
            g.querySelectorAll('.track-ind').forEach(ind => {
                const okStatus = statusFilter === 'all' || ind.dataset.status === statusFilter;
                const okText = matchLabel || (ind.dataset.text.indexOf(q) !== -1);
                const vis = okStatus && okText;
                ind.style.display = vis ? '' : 'none';
                if (vis) shown++;
            });
            g.style.display = shown ? '' : 'none';
            if (shown) anyVisible = true;
        });
        document.getElementById('trackEmpty').classList.toggle('hidden', anyVisible);
    }
    search.addEventListener('input', apply);
    document.getElementById('trackFilter').addEventListener('click', e => {
        const btn = e.target.closest('.track-fbtn');
        if (!btn) return;
        document.querySelectorAll('.track-fbtn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        statusFilter = btn.dataset.f;
        apply();
    });
})();
</script>
