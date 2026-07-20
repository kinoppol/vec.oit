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

  <!-- ─── RMS IMPORT SETTINGS ─── -->
  <div class="card rms-card">
    <div class="card-header">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;margin-right:6px"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9M8 17l4 4 4-4"/></svg>
      ตั้งค่าการโอนข้อมูลผู้ใช้จากระบบ RMS
    </div>
    <div class="card-body">
      <div class="alert alert-info" style="margin-bottom:16px">
        ระบุ URL แหล่งข้อมูล (origin) ของระบบ RMS เช่น <code>http://rms.rvc.ac.th</code>
        ระบบจะดึงข้อมูลผู้ใช้ที่ยังทำงานอยู่ (people_exit = 0) เข้าสู่สถานศึกษานี้อัตโนมัติ
        โดยใช้ <code>people_id</code> เป็นชื่อผู้ใช้ และ <code>ath_pass</code> เป็นรหัสผ่าน
      </div>
      <form id="rmsUrlForm" class="rms-url-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_rms_url">
        <input type="url" name="rms_base_url" id="rmsBaseUrl" class="form-input"
               placeholder="http://rms..." value="<?= e($school['rms_base_url'] ?? '') ?>" maxlength="300">
        <button type="submit" class="btn btn-ghost">บันทึก URL</button>
        <button type="button" class="btn btn-ghost" onclick="pingRms()">ทดสอบการเชื่อมต่อ</button>
        <button type="button" class="btn btn-primary" onclick="importRms()">โอนข้อมูลผู้ใช้</button>
      </form>
      <div class="form-hint" id="rmsEndpointHint"></div>
      <div class="form-hint" id="rmsPingResult" style="white-space:pre-wrap"></div>
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
const RMS_API_PATH = '<?= RMS_API_PATH ?>';

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

// ── RMS import settings ──
(function () {
    const form = document.getElementById('rmsUrlForm');
    if (!form) return;
    const input = document.getElementById('rmsBaseUrl');
    const hint  = document.getElementById('rmsEndpointHint');
    const refresh = () => {
        const base = input.value.trim().replace(/\/+$/, '');
        hint.textContent = base ? 'ปลายทางที่จะเรียก: ' + base + RMS_API_PATH : '';
    };
    input.addEventListener('input', refresh); refresh();
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        fetch(APP_URL + '/api.php', { method:'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(r => {
                if (r.ok) { showToast('บันทึก URL แล้ว'); setTimeout(() => location.reload(), 700); }
                else showToast(r.error, 'error');
            });
    });
})();

function saveRmsUrl() {
    return apiPost({ action:'update_rms_url', rms_base_url: document.getElementById('rmsBaseUrl').value.trim() });
}

async function pingRms() {
    const url = document.getElementById('rmsBaseUrl').value.trim();
    if (!url) { showToast('กรุณาระบุ URL ก่อน', 'error'); return; }
    const out = document.getElementById('rmsPingResult');
    out.textContent = 'กำลังทดสอบ…';
    const r = await apiPost({ action:'rms_ping', rms_base_url: url });
    if (!r.ok) { out.textContent = '❌ ' + r.error; return; }
    const d = r.data;
    if (!d.ok) { out.textContent = '❌ เชื่อมต่อไม่สำเร็จ (' + d.ms + 'ms)\n' + d.endpoint + '\n' + d.error + (d.env ? '\n[' + d.env + ']' : ''); return; }
    out.textContent = '✅ เชื่อมต่อสำเร็จ (' + d.ms + 'ms, ' + d.bytes + ' bytes)\n'
        + (d.is_json ? ('เป็น JSON' + (d.count !== null ? ' · พบ ' + d.count + ' รายการ' : ' · ไม่พบ array ผู้ใช้'))
                     : ('⚠ ไม่ใช่ JSON — ได้รับ: ' + d.peek));
}

async function importRms() {
    const url = document.getElementById('rmsBaseUrl').value.trim();
    if (!url) { showToast('กรุณาระบุ URL แหล่งข้อมูล RMS ก่อน', 'error'); return; }
    if (!await uiConfirm('ดึงและโอนข้อมูลผู้ใช้จากระบบ RMS เข้าสถานศึกษานี้?\nURL นี้จะถูกบันทึกไว้ใช้ในการโอนครั้งต่อไปด้วย\nผู้ใช้ที่มีอยู่แล้วจะถูกปรับปรุงข้อมูล (รหัสผ่านจาก RMS)',
        { title:'โอนข้อมูลผู้ใช้จาก RMS', confirmLabel:'โอนข้อมูล' })) return;

    const btn = document.querySelector('.rms-url-row .btn-primary');
    if (btn) btn.disabled = true;
    try {
        const saved = await saveRmsUrl();
        if (!saved.ok) { showToast(saved.error, 'error'); return; }

        showToast('กำลังดึงข้อมูลจาก RMS…');
        const f = await apiPost({ action:'import_rms_users', phase:'fetch' });
        if (!f.ok) { showToast(f.error, 'error'); return; }

        const total = f.data.total, token = f.data.token;
        if (total === 0) { showToast('ไม่พบผู้ใช้ที่ต้องโอน (people_exit=0) · ข้าม ' + f.data.skipped + ' รายการ'); return; }

        let offset = 0, newN = 0, updN = 0;
        while (true) {
            const b = await apiPost({ action:'import_rms_users', phase:'batch', token: token, offset: offset });
            if (!b.ok) { showToast(b.error, 'error'); return; }
            newN += b.data.new; updN += b.data.updated; offset = b.data.next;
            showToast('กำลังโอน… ' + offset + '/' + total);
            if (b.data.done) break;
        }
        showToast('โอนสำเร็จ: เพิ่มใหม่ ' + newN + ', ปรับปรุง ' + updN + ' (ข้าม ' + f.data.skipped + ')');
        setTimeout(() => location.reload(), 1400);
    } finally {
        if (btn) btn.disabled = false;
    }
}
</script>
