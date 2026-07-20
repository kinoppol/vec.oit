<?php
require_role('centraladmin');

$stmt = db()->query('
    SELECT s.*, COUNT(u.id) AS user_cnt
    FROM schools s
    LEFT JOIN users u ON u.school_id = s.id
    GROUP BY s.id ORDER BY s.created_at DESC
');
$schools = $stmt->fetchAll();
$counts = [
    'total'    => count($schools),
    'active'   => count(array_filter($schools, fn($s) => $s['status'] === 'active')),
    'pending'  => count(array_filter($schools, fn($s) => $s['status'] === 'pending')),
    'inactive' => count(array_filter($schools, fn($s) => $s['status'] === 'inactive')),
];
?>
<div class="schools-layout">

  <!-- ─── SUMMARY CARDS ─── -->
  <div class="school-stats-row">
    <div class="card mini-stat">
      <div class="ms-num"><?= $counts['total'] ?></div>
      <div class="ms-lbl">สถานศึกษาทั้งหมด</div>
    </div>
    <div class="card mini-stat ms-active">
      <div class="ms-num"><?= $counts['active'] ?></div>
      <div class="ms-lbl">อนุมัติแล้ว</div>
    </div>
    <div class="card mini-stat ms-pending">
      <div class="ms-num"><?= $counts['pending'] ?></div>
      <div class="ms-lbl">รอการอนุมัติ</div>
    </div>
    <div class="card mini-stat ms-inactive">
      <div class="ms-num"><?= $counts['inactive'] ?></div>
      <div class="ms-lbl">ปิดใช้งาน</div>
    </div>
  </div>

  <!-- ─── SCHOOLS TABLE ─── -->
  <div class="card schools-table-card">
    <div class="card-header">
      รายการสถานศึกษา
      <input type="search" class="search-inline" id="schoolSearch" placeholder="ค้นหาชื่อ…">
    </div>
    <div class="table-wrap">
      <table class="data-table" id="schoolsTable">
        <thead>
          <tr>
            <th>ชื่อสถานศึกษา</th>
            <th>รหัส</th>
            <th>จังหวัด</th>
            <th>ผู้ใช้</th>
            <th>สถานะ</th>
            <th>วันที่สมัคร</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schools as $s): ?>
          <tr>
            <td>
              <div class="school-cell">
                <img src="<?= e(school_emblem_url($s)) ?>" alt="" class="school-thumb">
                <div>
                  <div class="school-name-sm"><?= e($s['name']) ?></div>
                  <?php if ($s['website']): ?>
                  <a href="<?= e($s['website']) ?>" target="_blank" class="school-web"><?= e($s['website']) ?></a>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= e($s['code'] ?? '—') ?></td>
            <td><?= e($s['province'] ?? '—') ?></td>
            <td><?= $s['user_cnt'] ?></td>
            <td>
              <?= match($s['status']) {
                'active'   => '<span class="chip chip-done">อนุมัติแล้ว</span>',
                'pending'  => '<span class="chip chip-pend">รอการอนุมัติ</span>',
                'inactive' => '<span class="chip">ปิดใช้งาน</span>',
                default    => e($s['status'])
              } ?>
            </td>
            <td><?= thai_date($s['created_at']) ?></td>
            <td class="actions">
              <?php if ($s['status'] === 'pending'): ?>
              <button class="btn btn-primary btn-xs" onclick="approveSchool(<?= $s['id'] ?>, <?= e(json_encode($s['name'])) ?>)">อนุมัติ</button>
              <?php endif; ?>
              <?php if ($s['status'] === 'active'): ?>
              <button class="btn btn-ghost btn-xs" onclick="setSchoolStatus(<?= $s['id'] ?>, 'inactive', <?= e(json_encode($s['name'])) ?>)">ปิดใช้งาน</button>
              <?php elseif ($s['status'] === 'inactive'): ?>
              <button class="btn btn-ghost btn-xs" onclick="setSchoolStatus(<?= $s['id'] ?>, 'active', <?= e(json_encode($s['name'])) ?>)">เปิดใช้งาน</button>
              <?php endif; ?>
              <a href="<?= APP_URL ?>/public.php?slug=<?= e($s['slug']) ?>&year=<?= e($yearCode) ?>" target="_blank" class="icon-btn" title="ดูหน้าสาธารณะ">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
async function approveSchool(id, name) {
    if (!await uiConfirm('อนุมัติสถานศึกษา "' + name + '" ให้เข้าใช้งานระบบ?',
        { title:'อนุมัติสถานศึกษา', confirmLabel:'อนุมัติ' })) return;
    apiPost({ action:'approve_school', id }).then(r => {
        r.ok ? location.reload() : showToast(r.error, 'error');
    });
}
async function setSchoolStatus(id, status, name) {
    const off = status !== 'active';
    const msg = off ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    if (!await uiConfirm(msg + 'สถานศึกษา "' + name + '"?',
        { title:msg + 'สถานศึกษา', confirmLabel:msg, danger:off })) return;
    apiPost({ action:'set_school_status', id, status }).then(r => {
        r.ok ? location.reload() : showToast(r.error, 'error');
    });
}

// Inline search filter
document.getElementById('schoolSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#schoolsTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
