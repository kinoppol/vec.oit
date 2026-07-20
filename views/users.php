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
            <th>สถานะ</th>
            <th>วันที่สร้าง</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr class="user-row" data-search="<?= e(mb_strtolower(($u['full_name'] ?? '') . ' ' . ($u['nickname'] ?? '') . ' ' . ($u['national_id'] ?? '') . ' ' . ($u['email'] ?? ''))) ?>">
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

<script>
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
