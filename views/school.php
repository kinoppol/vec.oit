<?php
require_role('schooladmin');
?>
<div class="users-layout">

  <!-- ─── SCHOOL INFO + EMBLEM ─── -->
  <div class="card school-info-card">
    <div class="card-header">ข้อมูลสถานศึกษา</div>
    <div class="school-info-body">
      <div class="emblem-section">
        <img src="<?= e(school_emblem_url($school ?? [])) ?>" alt="ตราสถานศึกษา" class="emblem-preview" id="emblemPreview">
        <form id="emblemForm" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_emblem">
          <input type="hidden" name="school_id" value="<?= $schoolId ?>">
          <label class="btn btn-ghost btn-sm emblem-upload-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            อัปโหลดตราสถาน
            <input type="file" name="emblem" accept=".jpg,.jpeg,.png,.webp,.svg" style="display:none" onchange="submitEmblem(this)">
          </label>
        </form>
      </div>
      <div class="school-details">
        <div class="school-name-big"><?= e($school['name'] ?? '—') ?></div>
        <div class="school-meta-row">
          <span>รหัสสถานศึกษา: <?= e($school['code'] ?? '—') ?></span>
          <span>สังกัด: <?= e($school['province'] ?? '—') ?></span>
        </div>
        <?php if (!empty($school['slug'])): ?>
        <div class="public-link-row">
          <span>ลิงก์สาธารณะ:</span>
          <a href="<?= APP_URL ?>/public.php?slug=<?= e($school['slug']) ?>&year=<?= e($yearCode) ?>" target="_blank" class="public-link-text" id="publicLinkText">
            <?= APP_URL ?>/public.php?slug=<?= e($school['slug']) ?>&year=<?= e($yearCode) ?>
          </a>
          <button class="btn btn-ghost btn-xs" onclick="copyPublicLink()" title="คัดลอกลิงก์">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          </button>
          <button class="btn btn-ghost btn-xs" onclick="openEditSlug()" title="แก้ไข slug">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ─── EDIT SLUG MODAL ─── -->
<div class="modal-backdrop hidden" id="slugModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">แก้ไขลิงก์สาธารณะ (slug)</h2>
      <button class="modal-close" onclick="document.getElementById('slugModal').classList.add('hidden')">✕</button>
    </div>
    <form id="slugForm" class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_slug">
      <div class="alert alert-info" style="margin-bottom:16px">
        slug คือส่วนท้าย URL ของหน้าสาธารณะ ใช้ตัวอักษร/ตัวเลข/ขีด (-) หลีกเลี่ยงเว้นวรรคและอักขระพิเศษ
      </div>
      <div class="form-group">
        <label class="form-label">slug <span class="req">*</span></label>
        <input type="text" name="slug" id="slugInput" class="form-input" required maxlength="120"
               value="<?= e($school['slug'] ?? '') ?>">
        <div class="form-hint">ตัวอย่าง: <?= APP_URL ?>/public.php?slug=<b id="slugPreview"><?= e($school['slug'] ?? '') ?></b></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('slugModal').classList.add('hidden')">ยกเลิก</button>
        <button type="submit" class="btn btn-primary">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<script>
function submitEmblem(input) {
    const form = document.getElementById('emblemForm');
    const fd = new FormData(form);
    fd.set('csrf_token', CSRF_TOKEN);
    fetch(APP_URL + '/api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                document.getElementById('emblemPreview').src = r.data.url + '?t=' + Date.now();
                const sl = document.getElementById('sidebarLogo');
                if (sl) sl.src = r.data.url + '?t=' + Date.now();
                showToast('อัปโหลดตราสถานเรียบร้อย');
            } else { showToast(r.error, 'error'); }
        });
}

function copyPublicLink() {
    const link = document.getElementById('publicLinkText')?.textContent?.trim();
    if (link) navigator.clipboard.writeText(link).then(() => showToast('คัดลอกลิงก์แล้ว'));
}

// ── Edit slug ──
function openEditSlug() { document.getElementById('slugModal')?.classList.remove('hidden'); }
(function () {
    const form = document.getElementById('slugForm');
    if (!form) return;
    const input = document.getElementById('slugInput');
    const prev  = document.getElementById('slugPreview');
    input.addEventListener('input', () => { prev.textContent = input.value.trim(); });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        fetch(APP_URL + '/api.php', { method:'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(r => { r.ok ? location.reload() : showToast(r.error, 'error'); });
    });
})();
</script>
