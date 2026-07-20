<?php
require_role('schooladmin', 'centraladmin');

$msg = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Users of this school
$stmt = db()->prepare('SELECT * FROM users WHERE school_id = ? ORDER BY role DESC, full_name ASC');
$stmt->execute([$schoolId]);
$users = $stmt->fetchAll();

// Position master list (for the combobox suggestions)
$posStmt = db()->prepare('SELECT id, name FROM positions WHERE school_id = ? ORDER BY name');
$posStmt->execute([$schoolId]);
$positions = $posStmt->fetchAll();
?>
<div class="users-layout">

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

  <!-- ─── USERS TABLE ─── -->
  <div class="card users-card">
    <div class="card-header">
      <span>ผู้ใช้งาน (<?= count($users) ?> คน)</span>
      <div class="users-toolbar">
        <input type="search" id="userSearch" class="form-input users-search"
               placeholder="ค้นหาชื่อ / เลขบัตร / อีเมล…" autocomplete="off">
        <button class="btn btn-primary btn-sm" onclick="openAddUser()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
          เพิ่มผู้ใช้
        </button>
      </div>
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
            <th>ตำแหน่ง</th>
            <th>สถานะ</th>
            <th>วันที่สร้าง</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr class="user-row" data-search="<?= e(mb_strtolower(($u['full_name'] ?? '') . ' ' . ($u['nickname'] ?? '') . ' ' . ($u['national_id'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['position'] ?? ''))) ?>">
            <td>
              <?= e($u['full_name']) ?>
              <?php if (!empty($u['nickname'])): ?><span class="user-nick">(<?= e($u['nickname']) ?>)</span><?php endif; ?>
              <?php if (!empty($u['email'])): ?><div class="user-email"><?= e($u['email']) ?></div><?php endif; ?>
            </td>
            <td class="national-id"><?= e($u['national_id']) ?></td>
            <td><?= e(match($u['role']) {
              'schooladmin'  => 'ผู้ดูแลสถานศึกษา',
              'centraladmin' => 'ผู้ดูแลส่วนกลาง',
              default        => 'ผู้กรอกข้อมูล'
            }) ?></td>
            <td class="user-position" data-uid="<?= $u['id'] ?>">
              <span class="pos-text"><?php if (!empty($u['position'])): ?><?= e($u['position']) ?><?php else: ?><span class="pos-empty">— กำหนด</span><?php endif; ?></span>
              <button class="icon-btn pos-edit" title="แก้ไขตำแหน่ง"
                      onclick="editPosition(<?= $u['id'] ?>, <?= e(json_encode($u['position'] ?? '', JSON_UNESCAPED_UNICODE)) ?>, <?= e(json_encode($u['full_name'], JSON_UNESCAPED_UNICODE)) ?>)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
            </td>
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
      <div id="userNoResult" class="users-empty hidden">ไม่พบผู้ใช้ที่ตรงกับคำค้นหา</div>
    </div>
    <div class="users-pager" id="userPager"></div>
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

<!-- ─── EDIT POSITION MODAL ─── -->
<div class="modal-backdrop hidden" id="posModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">กำหนดตำแหน่ง</h2>
      <button class="modal-close" onclick="document.getElementById('posModal').classList.add('hidden')">✕</button>
    </div>
    <form id="posForm" class="modal-body">
      <input type="hidden" id="posUserId">
      <div class="alert alert-info" style="margin-bottom:16px">
        ตำแหน่งของ <strong id="posUserName"></strong> — เลือกจากรายการหรือพิมพ์ตำแหน่งใหม่ได้ (ค่านี้จะไม่ถูกล้างเมื่อโอน RMS ใหม่)
      </div>
      <div class="form-group">
        <label class="form-label">ตำแหน่ง</label>
        <input type="text" id="posInput" class="form-input" list="posDatalist" maxlength="150"
               placeholder="พิมพ์เพื่อค้นหา/เพิ่มใหม่ เช่น ครู, หัวหน้างานพัสดุ">
        <datalist id="posDatalist">
          <?php foreach ($positions as $p): ?><option value="<?= e($p['name']) ?>"></option><?php endforeach; ?>
        </datalist>
      </div>
      <div class="modal-footer" style="justify-content:space-between">
        <button type="button" class="btn btn-ghost btn-sm" onclick="openManagePos()">จัดการรายการตำแหน่ง</button>
        <div style="display:flex;gap:10px">
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('posModal').classList.add('hidden')">ยกเลิก</button>
          <button type="submit" class="btn btn-primary">บันทึก</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ─── MANAGE POSITIONS MODAL ─── -->
<div class="modal-backdrop hidden" id="managePosModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">จัดการรายการตำแหน่ง</h2>
      <button class="modal-close" onclick="document.getElementById('managePosModal').classList.add('hidden')">✕</button>
    </div>
    <div class="modal-body">
      <form id="addPosForm" class="rms-url-row" style="margin-bottom:14px">
        <input type="text" id="newPosInput" class="form-input" maxlength="150" placeholder="เพิ่มตำแหน่งใหม่…" required>
        <button type="submit" class="btn btn-primary">เพิ่ม</button>
      </form>
      <div id="posList" class="pos-list"></div>
    </div>
  </div>
</div>

<script>
const RMS_API_PATH = '<?= RMS_API_PATH ?>';

// ── Edit user position ──
function editPosition(userId, current, name) {
    document.getElementById('posUserId').value = userId;
    document.getElementById('posUserName').textContent = name;
    document.getElementById('posInput').value = current || '';
    document.getElementById('posModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('posInput').focus(), 50);
}
document.getElementById('posForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const uid = document.getElementById('posUserId').value;
    const pos = document.getElementById('posInput').value.trim();
    apiPost({ action:'update_user_position', user_id: uid, position: pos }).then(r => {
        if (!r.ok) { showToast(r.error, 'error'); return; }
        const cell = document.querySelector('.user-position[data-uid="' + uid + '"] .pos-text');
        if (cell) {
            if (pos) cell.textContent = pos;
            else cell.innerHTML = '<span class="pos-empty">— กำหนด</span>';
        }
        const row = document.querySelector('.user-position[data-uid="' + uid + '"]')?.closest('.user-row');
        if (row) row.dataset.search = (row.dataset.search + ' ' + pos.toLowerCase());
        document.getElementById('posModal').classList.add('hidden');
        showToast('บันทึกตำแหน่งแล้ว');
    });
});

// ── Manage positions (master list) ──
function openManagePos() {
    loadPosList();
    document.getElementById('managePosModal').classList.remove('hidden');
}
async function loadPosList() {
    const box = document.getElementById('posList');
    box.innerHTML = '<div class="pos-empty">กำลังโหลด…</div>';
    const r = await apiPost({ action:'list_positions' });
    if (!r.ok) { box.textContent = r.error; return; }
    const items = r.data || [];
    refreshPosDatalist(items);
    if (!items.length) { box.innerHTML = '<div class="pos-empty">ยังไม่มีตำแหน่งในระบบ</div>'; return; }
    box.innerHTML = items.map(p =>
        '<div class="pos-row" data-id="' + p.id + '">'
        + '<input class="form-input pos-name" value="' + escHtml(p.name) + '" maxlength="150">'
        + '<button type="button" class="btn btn-ghost btn-sm" onclick="savePos(' + p.id + ', this)">บันทึก</button>'
        + '<button type="button" class="icon-btn icon-btn-danger" title="ลบ" onclick="delPos(' + p.id + ')">'
        + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>'
        + '</button></div>').join('');
}
async function savePos(id, btn) {
    const name = btn.closest('.pos-row').querySelector('.pos-name').value.trim();
    if (!name) return;
    const r = await apiPost({ action:'rename_position', id, name });
    if (r.ok) { showToast('บันทึกแล้ว'); loadPosList(); } else showToast(r.error, 'error');
}
async function delPos(id) {
    if (!await uiConfirm('ลบตำแหน่งนี้ออกจากรายการ?', { title:'ลบตำแหน่ง', confirmLabel:'ลบ', danger:true })) return;
    const r = await apiPost({ action:'delete_position', id });
    if (r.ok) loadPosList(); else showToast(r.error, 'error');
}
document.getElementById('addPosForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const inp = document.getElementById('newPosInput');
    const name = inp.value.trim();
    if (!name) return;
    const r = await apiPost({ action:'add_position', name });
    if (r.ok) { inp.value = ''; loadPosList(); } else showToast(r.error, 'error');
});
function refreshPosDatalist(items) {
    const dl = document.getElementById('posDatalist');
    if (dl) dl.innerHTML = items.map(p => '<option value="' + escHtml(p.name) + '"></option>').join('');
}

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

// ── Users search + pagination (client-side) ──
(function () {
    const PER_PAGE = 20;
    const rows   = Array.prototype.slice.call(document.querySelectorAll('.user-row'));
    const search = document.getElementById('userSearch');
    const pager  = document.getElementById('userPager');
    const empty  = document.getElementById('userNoResult');
    if (!rows.length || !search) return;

    let filtered = rows;
    let page = 1;

    function render() {
        const pages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
        if (page > pages) page = pages;
        const start = (page - 1) * PER_PAGE, end = start + PER_PAGE;
        rows.forEach(r => { r.style.display = 'none'; });
        filtered.slice(start, end).forEach(r => { r.style.display = ''; });
        empty.classList.toggle('hidden', filtered.length !== 0);

        // pager
        pager.innerHTML = '';
        if (filtered.length > PER_PAGE) {
            const info = document.createElement('span');
            info.className = 'users-pager-info';
            info.textContent = (start + 1) + '–' + Math.min(end, filtered.length) + ' จาก ' + filtered.length + ' คน';
            pager.appendChild(info);

            const nav = document.createElement('div');
            nav.className = 'users-pager-nav';
            const mk = (label, target, disabled, active) => {
                const b = document.createElement('button');
                b.className = 'pager-btn' + (active ? ' active' : '');
                b.textContent = label; b.disabled = !!disabled;
                if (!disabled && !active) b.onclick = () => { page = target; render(); };
                return b;
            };
            nav.appendChild(mk('‹', page - 1, page === 1));
            // windowed page numbers
            const win = [];
            for (let p = 1; p <= pages; p++) {
                if (p === 1 || p === pages || Math.abs(p - page) <= 1) win.push(p);
                else if (win[win.length - 1] !== '…') win.push('…');
            }
            win.forEach(p => nav.appendChild(p === '…'
                ? Object.assign(document.createElement('span'), { className: 'pager-ellipsis', textContent: '…' })
                : mk(String(p), p, false, p === page)));
            nav.appendChild(mk('›', page + 1, page === pages));
            pager.appendChild(nav);
        }
    }

    let t;
    search.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(() => {
            const q = this.value.toLowerCase().trim();
            filtered = q ? rows.filter(r => (r.dataset.search || '').indexOf(q) !== -1) : rows;
            page = 1; render();
        }, 150);
    });
    render();
})();

function openAddUser()  { document.getElementById('addUserModal').classList.remove('hidden'); }
function closeAddUser() { document.getElementById('addUserModal').classList.add('hidden'); }

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
