<?php
// Central-admin only: view migration status and run pending migrations.
if ($role !== 'centraladmin') { redirect('app.php'); }

require_once __DIR__ . '/../includes/migrations.php';

$status  = mig_status();
$pending = array_filter($status, fn ($m) => !$m['applied']);
$applied = array_filter($status, fn ($m) => $m['applied']);
$nPend   = count($pending);
$nApp    = count($applied);
?>
<div class="mig-layout">

  <!-- STATE CARD -->
  <div class="card mig-state <?= $nPend ? 'mig-state-warn' : 'mig-state-ok' ?>">
    <div class="mig-state-icon">
      <?php if ($nPend): ?>
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <?php else: ?>
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <?php endif; ?>
    </div>
    <div class="mig-state-text">
      <div class="mig-state-title">
        <?= $nPend
          ? 'มี migration ค้างอยู่ ' . $nPend . ' รายการ'
          : 'ฐานข้อมูลเป็นเวอร์ชันล่าสุดแล้ว' ?>
      </div>
      <div class="mig-state-sub">
        รันแล้ว <?= $nApp ?> จากทั้งหมด <?= count($status) ?> รายการ
        <?= $nPend ? '· กด “รัน migration” เพื่ออัปเดตโครงสร้างฐานข้อมูลให้ตรงกับโค้ดเวอร์ชันปัจจุบัน' : '' ?>
      </div>
    </div>
    <button id="migRun" class="btn-primary" <?= $nPend ? '' : 'disabled' ?>>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      รัน migration
    </button>
  </div>

  <!-- LIVE RESULT (hidden until run) -->
  <div id="migResult" class="card mig-result hidden">
    <div class="card-header"><span class="card-title">ผลการรัน</span></div>
    <div id="migResultRows" class="mig-rows"></div>
  </div>

  <!-- MIGRATION LIST -->
  <div class="card mig-list-card">
    <div class="card-header">
      <span class="card-title">รายการ migration</span>
      <span class="mig-count"><?= $nApp ?> / <?= count($status) ?></span>
    </div>
    <div class="mig-rows">
      <?php foreach ($status as $m): ?>
      <div class="mig-row <?= $m['applied'] ? 'is-applied' : 'is-pending' ?>">
        <span class="mig-dot"></span>
        <span class="mig-name"><?= e($m['name']) ?></span>
        <?php if ($m['applied']): ?>
          <span class="mig-when"><?= e($m['applied_at'] ?? '') ?></span>
          <span class="mig-tag mig-tag-ok">รันแล้ว</span>
        <?php else: ?>
          <span class="mig-when"></span>
          <span class="mig-tag mig-tag-pend">ค้าง</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <p class="mig-note">
    Migration จะทำงานตามลำดับหมายเลขไฟล์ และข้ามรายการที่รันแล้วโดยอัตโนมัติ ปลอดภัยต่อการกดซ้ำ
    เพิ่มการเปลี่ยนแปลงโครงสร้างใหม่เป็นไฟล์ <code>database/migrations/NNN_*.sql</code> ลำดับถัดไป
  </p>
</div>

<script>
(function () {
    const btn  = document.getElementById('migRun');
    const box  = document.getElementById('migResult');
    const rows = document.getElementById('migResultRows');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        const orig = btn.innerHTML;
        btn.textContent = 'กำลังรัน…';
        box.classList.remove('hidden');
        rows.innerHTML = '';

        try {
            const r = await apiPost({ action: 'run_migrations' });
            if (!r.ok) {
                rows.innerHTML = '<div class="mig-row is-err"><span class="mig-dot"></span><span class="mig-name">' +
                    escapeHtml(r.error || 'เกิดข้อผิดพลาด') + '</span></div>';
                showToast(r.error || 'เกิดข้อผิดพลาด', 'error');
                btn.disabled = false;
                btn.innerHTML = orig;
                return;
            }
            (r.data.results || []).forEach(function (res) {
                const cls = res.type === 'ok' ? 'is-applied' : (res.type === 'err' ? 'is-err' : 'is-skip');
                const div = document.createElement('div');
                div.className = 'mig-row ' + cls;
                div.innerHTML = '<span class="mig-dot"></span><span class="mig-name">' + escapeHtml(res.msg) + '</span>';
                rows.appendChild(div);
            });
            showToast(r.data.ranAny ? 'รัน migration สำเร็จ' : 'ไม่มี migration ค้าง');
            // Reload so the sidebar badge and the list reflect the new state
            setTimeout(function () { location.reload(); }, 900);
        } catch (e) {
            showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
})();
</script>
