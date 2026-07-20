<?php
require_role('schooladmin', 'centraladmin');

$msg = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Users of this school
$stmt = db()->prepare('SELECT * FROM users WHERE school_id = ? ORDER BY role DESC, full_name ASC');
$stmt->execute([$schoolId]);
$users = $stmt->fetchAll();
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
          <?php if ($role === 'schooladmin'): ?>
          <button class="btn btn-ghost btn-xs" onclick="openEditSlug()" title="แก้ไข slug">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($role === 'schooladmin'): ?>
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
               placeholder="http://rms.rvc.ac.th" value="<?= e($school['rms_base_url'] ?? '') ?>" maxlength="300">
        <button type="submit" class="btn btn-ghost">บันทึก URL</button>
        <button type="button" class="btn btn-primary" onclick="importRms()">โอนข้อมูลผู้ใช้</button>
      </form>
      <div class="form-hint" id="rmsEndpointHint"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ─── USERS TABLE ─── -->
  <div class="card users-card">
    <div class="card-header">
      ผู้ใช้งาน (<?= count($users) ?> คน)
      <button class="btn btn-primary btn-sm" onclick="openAddUser()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        เพิ่มผู้ใช้
      </button>
    </div>
    <?php if ($msg): ?>
    <div class="flash-msg flash-<?= e($msg['type']) ?>"><?= e($msg['text']) ?></div>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ชื่อ-นามสกุล</th>
            <th>เลขประจำตัวประชาชน</th>
            <th>บทบาท</th>
            <th>สถานะ</th>
            <th>วันที่สร้าง</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <?= e($u['full_name']) ?>
              <?php if (!empty($u['email'])): ?><div class="user-email"><?= e($u['email']) ?></div><?php endif; ?>
            </td>
            <td class="national-id"><?= e($u['national_id']) ?></td>
            <td><?= e(match($u['role']) {
              'schooladmin'  => 'ผู้ดูแลสถานศึกษา',
              'centraladmin' => 'ผู้ดูแลส่วนกลาง',
              default        => 'ผู้กรอกข้อมูล'
            }) ?></td>
            <td>
              <?php if ($u['status'] === 'pending'): ?>
              <span class="chip chip-pend">รออนุมัติ</span>
              <?php elseif ($u['must_change_pw']): ?>
              <span class="chip chip-prog">รอเปลี่ยนรหัส</span>
              <?php elseif ($u['status'] === 'active'): ?>
              <span class="chip chip-done">ใช้งานได้</span>
              <?php elseif ($u['status'] === 'disabled'): ?>
              <span class="chip">ปิดใช้งาน</span>
              <?php else: ?>
              <span class="chip chip-pend"><?= e($u['status']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= thai_date($u['created_at']) ?></td>
            <td class="actions">
              <?php if ($u['id'] !== (int)$user['id']): ?>
              <button class="icon-btn" title="รีเซ็ตรหัสผ่าน"
                      onclick="resetPassword(<?= $u['id'] ?>, <?= e(json_encode($u['full_name'])) ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
              </button>
              <?php if ($u['status'] === 'active'): ?>
              <button class="icon-btn icon-btn-danger" title="ปิดใช้งาน"
                      onclick="toggleUser(<?= $u['id'] ?>, 'disabled', <?= e(json_encode($u['full_name'])) ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>
              </button>
              <?php else: ?>
              <button class="icon-btn icon-btn-ok" title="เปิดใช้งาน"
                      onclick="toggleUser(<?= $u['id'] ?>, 'active', <?= e(json_encode($u['full_name'])) ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
              </button>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ─── ADD USER MODAL ─── -->
<div class="modal-backdrop hidden" id="addUserModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">เพิ่มผู้ใช้งาน</h2>
      <button class="modal-close" onclick="closeAddUser()">✕</button>
    </div>
    <form id="addUserForm" class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_user">
      <input type="hidden" name="school_id" value="<?= $schoolId ?>">
      <div class="form-group">
        <label class="form-label">ชื่อ-นามสกุล <span class="req">*</span></label>
        <input type="text" name="name" class="form-input" required maxlength="255">
      </div>
      <div class="form-group">
        <label class="form-label">เลขประจำตัวประชาชน 13 หลัก <span class="req">*</span></label>
        <input type="text" name="national_id" class="form-input" required pattern="\d{13}" maxlength="13" inputmode="numeric">
      </div>
      <div class="form-group">
        <label class="form-label">บทบาท</label>
        <select name="role" class="form-input">
          <option value="user">ผู้กรอกข้อมูล</option>
          <option value="schooladmin">ผู้ดูแลสถานศึกษา</option>
        </select>
      </div>
      <div class="alert alert-info">
        ระบบจะสร้างรหัสผ่านชั่วคราวให้อัตโนมัติ — ผู้ใช้ต้องเปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบครั้งแรก
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAddUser()">ยกเลิก</button>
        <button type="submit" class="btn btn-primary">สร้างผู้ใช้</button>
      </div>
    </form>
  </div>
</div>

<!-- ─── RESET PASSWORD RESULT MODAL ─── -->
<div class="modal-backdrop hidden" id="resetModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">รีเซ็ตรหัสผ่าน</h2>
      <button class="modal-close" onclick="document.getElementById('resetModal').classList.add('hidden')">✕</button>
    </div>
    <div class="modal-body">
      <p>รหัสผ่านชั่วคราวสำหรับ <strong id="resetName"></strong>:</p>
      <div class="pw-display" id="resetPwDisplay"></div>
      <p class="alert alert-info">กรุณาแจ้งรหัสผ่านนี้ให้ผู้ใช้ทราบ ระบบจะให้เปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบครั้งถัดไป</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="copyResetPw()">คัดลอกรหัสผ่าน</button>
      <button class="btn btn-primary" onclick="document.getElementById('resetModal').classList.add('hidden')">ตกลง</button>
    </div>
  </div>
</div>

<?php if ($role === 'schooladmin'): ?>
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
<?php endif; ?>

<script>
const RMS_API_PATH = '<?= RMS_API_PATH ?>';

function openAddUser()  { document.getElementById('addUserModal').classList.remove('hidden'); }
function closeAddUser() { document.getElementById('addUserModal').classList.add('hidden'); }

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
            .then(r => showToast(r.ok ? 'บันทึก URL แล้ว' : r.error, r.ok ? 'ok' : 'error'));
    });
})();

async function importRms() {
    if (!await uiConfirm('ดึงและโอนข้อมูลผู้ใช้จากระบบ RMS เข้าสถานศึกษานี้?\nผู้ใช้ที่มีอยู่แล้วจะถูกปรับปรุงข้อมูล (รหัสผ่านจาก RMS)',
        { title:'โอนข้อมูลผู้ใช้จาก RMS', confirmLabel:'โอนข้อมูล' })) return;
    showToast('กำลังดึงข้อมูลจาก RMS…');
    apiPost({ action:'import_rms_users' }).then(r => {
        if (r.ok) {
            const d = r.data;
            showToast('โอนสำเร็จ: เพิ่มใหม่ ' + d.new + ', ปรับปรุง ' + d.updated + ', ข้าม ' + d.skipped + ' รายการ');
            setTimeout(() => location.reload(), 1200);
        } else { showToast(r.error, 'error'); }
    });
}

function openEditSlug() { document.getElementById('slugModal')?.classList.remove('hidden'); }
(function () {
    const form = document.getElementById('slugForm');
    if (!form) return;
    const input = document.getElementById('slugInput');
    const prev  = document.getElementById('slugPreview');
    input.addEventListener('input', () => { prev.textContent = input.value.trim(); });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch(APP_URL + '/api.php', { method:'POST', body: fd })
            .then(r => r.json())
            .then(r => { r.ok ? location.reload() : showToast(r.error, 'error'); });
    });
})();

async function resetPassword(userId, name) {
    if (!await uiConfirm('รีเซ็ตรหัสผ่านของ ' + name + ' เป็นรหัสใหม่?',
        { title:'รีเซ็ตรหัสผ่าน', confirmLabel:'รีเซ็ต' })) return;
    apiPost({ action:'reset_password', user_id: userId }).then(r => {
        if (r.ok) {
            document.getElementById('resetName').textContent = name;
            document.getElementById('resetPwDisplay').textContent = r.data.password;
            document.getElementById('resetModal').classList.remove('hidden');
        } else { showToast(r.error, 'error'); }
    });
}

async function toggleUser(userId, status, name) {
    const off = status !== 'active';
    const msg = off ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    if (!await uiConfirm(msg + 'บัญชีของ ' + name + '?',
        { title:msg + 'บัญชีผู้ใช้', confirmLabel:msg, danger:off })) return;
    apiPost({ action:'toggle_user', user_id: userId, status }).then(r => {
        r.ok ? location.reload() : showToast(r.error, 'error');
    });
}

function copyResetPw() {
    const pw = document.getElementById('resetPwDisplay').textContent;
    navigator.clipboard.writeText(pw).then(() => showToast('คัดลอกรหัสผ่านแล้ว'));
}

function copyPublicLink() {
    const link = document.getElementById('publicLinkText')?.textContent?.trim();
    if (link) navigator.clipboard.writeText(link).then(() => showToast('คัดลอกลิงก์แล้ว'));
}

function submitEmblem(input) {
    const form = document.getElementById('emblemForm');
    const fd = new FormData(form);
    fd.set('csrf_token', CSRF_TOKEN);
    fetch(APP_URL + '/api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                document.getElementById('emblemPreview').src = r.data.url + '?t=' + Date.now();
                document.getElementById('sidebarLogo').src   = r.data.url + '?t=' + Date.now();
                showToast('อัปโหลดตราสถานเรียบร้อย');
            } else { showToast(r.error, 'error'); }
        });
}

document.getElementById('addUserForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fetch(APP_URL + '/api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (r.ok) { closeAddUser(); location.reload(); }
            else { showToast(r.error, 'error'); }
        });
});
</script>
