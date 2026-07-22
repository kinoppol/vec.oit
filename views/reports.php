<?php
require_role('schooladmin');

$tree = indicator_tree($schoolId, $yearCode);

// Approved assistants per indicator (user or position)
$asstByInd = [];
$aStmt = db()->prepare('
    SELECT ia.indicator_id, ia.user_id, ia.position_id, u.full_name, p.name AS pos_name
    FROM indicator_assistants ia
    LEFT JOIN users u     ON u.id = ia.user_id
    LEFT JOIN positions p ON p.id = ia.position_id
    WHERE ia.school_id = ? AND ia.status = "approved"
');
$aStmt->execute([$schoolId]);
foreach ($aStmt->fetchAll() as $r) {
    $name = $r['position_id'] ? ('ตำแหน่ง: ' . $r['pos_name']) : $r['full_name'];
    if ($name !== null && $name !== '') $asstByInd[(int)$r['indicator_id']][] = $name;
}

// Flatten indicators (already numeric-ordered) with responsible + assistants resolved
$rows = [];
foreach ($tree as $sec) {
    foreach ($sec['subs'] as $sub) {
        foreach ($sub['inds'] as $ind) {
            $resp = !empty($ind['assignee_pos_name']) ? ('ตำแหน่ง: ' . $ind['assignee_pos_name'])
                  : (!empty($ind['assignee_name']) ? $ind['assignee_name'] : '');
            $rows[] = [
                'code'  => $ind['code'],
                'title' => $ind['title'],
                'resp'  => $resp,
                'asst'  => $asstByInd[(int)$ind['id']] ?? [],
            ];
        }
    }
}

// Report 2: group by responsible party (indicators + assistants under each)
$byResp = [];
foreach ($rows as $r) {
    $key = $r['resp'] !== '' ? $r['resp'] : '__none';
    $byResp[$key] ??= ['name' => $r['resp'] !== '' ? $r['resp'] : 'ยังไม่มอบหมาย', 'inds' => []];
    $byResp[$key]['inds'][] = $r;
}
$byResp = array_values($byResp);
usort($byResp, function ($a, $b) {
    $an = $a['name'] === 'ยังไม่มอบหมาย'; $bn = $b['name'] === 'ยังไม่มอบหมาย';
    if ($an !== $bn) return $an ? 1 : -1;
    return strcmp($a['name'], $b['name']);
});
?>
<div class="report-layout">

  <!-- Tabs + print -->
  <div class="report-toolbar no-print">
    <div class="report-tabs">
      <button class="report-tab active" data-r="r1" onclick="showReport('r1', this)">รายงานที่ 1 · การมอบหมายตามตัวชี้วัด</button>
      <button class="report-tab" data-r="r2" onclick="showReport('r2', this)">รายงานที่ 2 · ตัวชี้วัดตามผู้รับผิดชอบ</button>
    </div>
    <button class="btn btn-primary btn-sm" onclick="window.print()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
      พิมพ์
    </button>
  </div>

  <!-- ── REPORT 1: assignment by indicator ── -->
  <div class="report-doc" id="report-r1">
    <div class="report-head">
      <div class="report-title">รายงานการมอบหมายหน้าที่จำแนกตามหัวข้อตัวชี้วัด</div>
      <div class="report-sub"><?= e($school['name'] ?? '') ?> · <?= e($yearLabel) ?></div>
    </div>
    <table class="report-table">
      <thead>
        <tr>
          <th style="width:44%">ตัวชี้วัด</th>
          <th style="width:28%">ผู้รับผิดชอบหลัก</th>
          <th style="width:28%">ผู้ช่วยผู้รับผิดชอบ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="rep-code"><?= e($r['code']) ?></span> <?= e($r['title']) ?></td>
          <td><?= $r['resp'] !== '' ? e($r['resp']) : '<span class="rep-none">—</span>' ?></td>
          <td>
            <?php if ($r['asst']): ?>
              <?php foreach ($r['asst'] as $a): ?><div class="rep-asst"><?= e($a) ?></div><?php endforeach; ?>
            <?php else: ?><span class="rep-none">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ── REPORT 2: indicators by responsible ── -->
  <div class="report-doc hidden" id="report-r2">
    <div class="report-head">
      <div class="report-title">รายงานตัวชี้วัดในความรับผิดชอบของแต่ละคน</div>
      <div class="report-sub"><?= e($school['name'] ?? '') ?> · <?= e($yearLabel) ?></div>
    </div>
    <table class="report-table">
      <thead>
        <tr>
          <th style="width:26%">ผู้รับผิดชอบหลัก</th>
          <th style="width:30%">ผู้ช่วยผู้รับผิดชอบ</th>
          <th style="width:44%">ตัวชี้วัดที่รับผิดชอบ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($byResp as $g): $n = count($g['inds']); ?>
          <?php foreach ($g['inds'] as $i => $r): ?>
          <tr>
            <?php if ($i === 0): ?>
            <td rowspan="<?= $n ?>" class="rep-resp-cell">
              <?= $g['name'] === 'ยังไม่มอบหมาย' ? '<span class="rep-none">ยังไม่มอบหมาย</span>' : e($g['name']) ?>
              <div class="rep-count"><?= $n ?> ตัวชี้วัด</div>
            </td>
            <?php endif; ?>
            <td>
              <?php if ($r['asst']): ?>
                <?php foreach ($r['asst'] as $a): ?><div class="rep-asst"><?= e($a) ?></div><?php endforeach; ?>
              <?php else: ?><span class="rep-none">—</span><?php endif; ?>
            </td>
            <td><span class="rep-code"><?= e($r['code']) ?></span> <?= e($r['title']) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function showReport(which, btn) {
    document.getElementById('report-r1').classList.toggle('hidden', which !== 'r1');
    document.getElementById('report-r2').classList.toggle('hidden', which !== 'r2');
    document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
}
</script>
