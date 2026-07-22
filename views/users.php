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

// Positions per user (many-to-many)
$upStmt = db()->prepare('
    SELECT up.user_id, p.name FROM user_positions up
    JOIN positions p ON p.id = up.position_id
    WHERE p.school_id = ? ORDER BY p.name
');
$upStmt->execute([$schoolId]);
$userPositions = [];
foreach ($upStmt->fetchAll() as $r) { $userPositions[(int)$r['user_id']][] = $r['name']; }
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
              <div class="user-name-cell">
                <?= user_avatar_html($u, 'avatar-sm') ?>
                <div class="user-name-text">
                  <span>
                    <?= e($u['full_name']) ?>
                    <?php if (!empty($u['nickname'])): ?><span class="user-nick">(<?= e($u['nickname']) ?>)</span><?php endif; ?>
                    <?php if (!empty($u['from_rms'])): ?><span class="rms-tag" title="โอนข้อมูลจากระบบ RMS">RMS</span><?php endif; ?>
                  </span>
                  <?php if (!empty($u['email'])): ?><div class="user-email"><?= e($u['email']) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td class="national-id"><?= e($u['national_id']) ?></td>
            <td><?= e(match($u['role']) {
              'schooladmin'  => 'ผู้ดูแลสถานศึกษา',
              'centraladmin' => 'ผู้ดูแลส่วนกลาง',
              default        => 'ผู้กรอกข้อมูล'
            }) ?></td>
            <?php $uPos = $userPositions[(int)$u['id']] ?? []; ?>
            <td class="user-position" data-uid="<?= $u['id'] ?>"
                data-pos="<?= e(json_encode(array_values($uPos), JSON_UNESCAPED_UNICODE)) ?>"
                data-name="<?= e($u['full_name']) ?>">
              <span class="pos-text"><?php if ($uPos): ?><?php foreach ($uPos as $pn): ?><span class="pos-chip"><?= e($pn) ?></span><?php endforeach; ?><?php else: ?><span class="pos-empty">— กำหนด</span><?php endif; ?></span>
              <button class="icon-btn pos-edit" title="แก้ไขตำแหน่ง" onclick="editPosition(<?= $u['id'] ?>)">
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
              <?php if ($u['role'] === 'user'): ?>
              <button class="btn btn-ghost btn-xs btn-appoint" title="แต่งตั้งเป็นผู้ดูแลสถานศึกษาร่วม"
                      onclick="setUserRole(<?= $u['id'] ?>, 'schooladmin', <?= e(json_encode($u['full_name'])) ?>)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                ตั้งเป็นผู้ดูแล
              </button>
              <?php elseif ($u['role'] === 'schooladmin'): ?>
              <button class="btn btn-ghost btn-xs btn-demote" title="ถอดจากผู้ดูแล เป็นผู้กรอกข้อมูล"
                      onclick="setUserRole(<?= $u['id'] ?>, 'user', <?= e(json_encode($u['full_name'])) ?>)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/></svg>
                ถอดผู้ดูแล
              </button>
              <?php endif; ?>
              <?php if ($role === 'schooladmin' && $u['role'] === 'user' && $u['status'] === 'active'): ?>
              <button class="btn btn-ghost btn-xs btn-impersonate" title="เข้าใช้งานระบบในนามผู้ใช้นี้"
                      onclick="impersonate(<?= $u['id'] ?>, <?= e(json_encode($u['full_name'])) ?>)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                สวมสิทธิ์
              </button>
              <?php endif; ?>
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
      <div id="resetPwSection">
        <p>รหัสผ่านชั่วคราวสำหรับ <strong id="resetName"></strong>:</p>
        <div class="pw-display" id="resetPwDisplay"></div>
        <p class="alert alert-info">กรุณาแจ้งรหัสผ่านนี้ให้ผู้ใช้ทราบ ระบบจะให้เปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบครั้งถัดไป</p>
      </div>
      <div id="resetRmsSection" class="hidden">
        <p>ผู้ใช้ <strong id="resetRmsName"></strong> โอนข้อมูลมาจากระบบ RMS</p>
        <p class="alert alert-warn">
          บัญชีนี้ยืนยันตัวตนด้วยรหัสผ่านจากระบบ RMS การรีเซ็ตที่นี่จะถูกเขียนทับเมื่อโอนข้อมูลครั้งถัดไป
          กรุณาแก้ไขรหัสผ่านที่ <strong>ระบบ RMS</strong> โดยตรง
          <span id="resetRmsLink"></span>
        </p>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="resetCopyBtn" onclick="copyResetPw()">คัดลอกรหัสผ่าน</button>
      <button class="btn btn-primary" onclick="document.getElementById('resetModal').classList.add('hidden')">ตกลง</button>
    </div>
  </div>
</div>

<!-- ─── RMS IMPORT PROGRESS MODAL ─── -->
<div class="modal-backdrop hidden" id="rmsProgressModal">
  <div class="modal rms-prog">
    <div class="rms-prog-body">
      <div class="rms-prog-icon" id="rmsProgIcon">
        <svg class="rms-spin" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9M8 17l4 4 4-4"/>
        </svg>
        <svg class="rms-check" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 6 9 17l-5-5"/>
        </svg>
      </div>
      <h2 class="rms-prog-title" id="rmsProgTitle">กำลังเชื่อมต่อระบบ RMS…</h2>
      <p class="rms-prog-sub" id="rmsProgSub">โปรดรอสักครู่ ระบบกำลังดึงข้อมูลผู้ใช้</p>
      <div class="rms-prog-track" id="rmsProgTrack">
        <span class="rms-prog-fill" id="rmsProgFill"></span>
      </div>
      <div class="rms-prog-meta">
        <span id="rmsProgCount"></span>
        <span id="rmsProgPct"></span>
      </div>
    </div>
    <div class="modal-footer rms-prog-footer hidden" id="rmsProgFooter">
      <button class="btn btn-primary" onclick="document.getElementById('rmsProgressModal').classList.add('hidden')">ปิด</button>
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
        ตำแหน่งของ <strong id="posUserName"></strong> — เพิ่มได้หลายตำแหน่ง เลือกจากรายการหรือพิมพ์ตำแหน่งใหม่ (ค่านี้จะไม่ถูกล้างเมื่อโอน RMS ใหม่)
      </div>
      <div class="form-group">
        <label class="form-label">ตำแหน่ง (เพิ่มได้หลายรายการ)</label>
        <div class="tag-field">
          <div id="posTags" class="tag-chips"></div>
          <div class="tag-add">
            <input type="text" id="posInput" class="form-input" list="posDatalist" maxlength="150"
                   placeholder="พิมพ์แล้วกด Enter เพื่อเพิ่ม เช่น ครู, หัวหน้างานพัสดุ">
            <button type="button" class="btn btn-ghost btn-sm" onclick="addPosTag()">เพิ่ม</button>
            <datalist id="posDatalist">
              <?php foreach ($positions as $p): ?><option value="<?= e($p['name']) ?>"></option><?php endforeach; ?>
            </datalist>
          </div>
        </div>
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
const RMS_BASE_URL = <?= json_encode(rtrim((string)($school['rms_base_url'] ?? ''), '/'), JSON_UNESCAPED_SLASHES) ?>;

// ── Edit user positions (multiple) ──
let posTags = [];
function renderPosTags() {
    document.getElementById('posTags').innerHTML = posTags.length
        ? posTags.map((t, i) => '<span class="tag-chip">' + escHtml(t)
            + '<button type="button" class="tag-x" onclick="removePosTag(' + i + ')">✕</button></span>').join('')
        : '<span class="tag-empty">ยังไม่มีตำแหน่ง</span>';
}
function addPosTag() {
    const inp = document.getElementById('posInput');
    const v = inp.value.trim();
    if (v && !posTags.includes(v)) posTags.push(v);
    inp.value = '';
    renderPosTags();
    inp.focus();
}
function removePosTag(i) { posTags.splice(i, 1); renderPosTags(); }

function editPosition(userId) {
    const cell = document.querySelector('.user-position[data-uid="' + userId + '"]');
    let cur = [];
    try { cur = JSON.parse(cell.dataset.pos || '[]'); } catch (e) {}
    posTags = Array.isArray(cur) ? cur.slice() : [];
    document.getElementById('posUserId').value = userId;
    document.getElementById('posUserName').textContent = cell.dataset.name || '';
    document.getElementById('posInput').value = '';
    renderPosTags();
    document.getElementById('posModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('posInput').focus(), 50);
}
document.getElementById('posInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); addPosTag(); }
});
document.getElementById('posForm').addEventListener('submit', function (e) {
    e.preventDefault();
    addPosTag(); // capture any text still in the input
    const uid = document.getElementById('posUserId').value;
    apiPost({ action:'update_user_position', user_id: uid, positions: JSON.stringify(posTags) }).then(r => {
        if (!r.ok) { showToast(r.error, 'error'); return; }
        const td = document.querySelector('.user-position[data-uid="' + uid + '"]');
        if (td) {
            td.dataset.pos = JSON.stringify(posTags);
            td.querySelector('.pos-text').innerHTML = posTags.length
                ? posTags.map(t => '<span class="pos-chip">' + escHtml(t) + '</span>').join('')
                : '<span class="pos-empty">— กำหนด</span>';
            const row = td.closest('.user-row');
            if (row) row.dataset.search = (row.dataset.search + ' ' + posTags.join(' ').toLowerCase());
        }
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

// ── RMS import progress modal helpers ──
const rmsProg = {
    modal:  () => document.getElementById('rmsProgressModal'),
    open() {
        this.modal().classList.remove('hidden');
        document.getElementById('rmsProgFooter').classList.add('hidden');
        this.icon('busy');
        this.indeterminate(true);
        document.getElementById('rmsProgCount').textContent = '';
        document.getElementById('rmsProgPct').textContent = '';
    },
    icon(state) {
        const el = document.getElementById('rmsProgIcon');
        el.classList.remove('is-busy', 'is-done', 'is-error');
        el.classList.add(state === 'done' ? 'is-done' : state === 'error' ? 'is-error' : 'is-busy');
    },
    set(title, sub) {
        document.getElementById('rmsProgTitle').textContent = title;
        if (sub !== undefined) document.getElementById('rmsProgSub').textContent = sub;
    },
    indeterminate(on) {
        const track = document.getElementById('rmsProgTrack');
        const fill  = document.getElementById('rmsProgFill');
        track.classList.toggle('indeterminate', !!on);
        if (on) fill.style.width = '';
    },
    progress(done, total) {
        const pct = total ? Math.round(done / total * 100) : 0;
        this.indeterminate(false);
        document.getElementById('rmsProgFill').style.width = pct + '%';
        document.getElementById('rmsProgCount').textContent = done + ' / ' + total + ' คน';
        document.getElementById('rmsProgPct').textContent = pct + '%';
    },
    finish(title, sub, isError) {
        this.icon(isError ? 'error' : 'done');
        this.indeterminate(false);
        if (!isError) document.getElementById('rmsProgFill').style.width = '100%';
        this.set(title, sub);
        document.getElementById('rmsProgFooter').classList.remove('hidden');
    }
};

async function importRms() {
    const url = document.getElementById('rmsBaseUrl').value.trim();
    if (!url) { showToast('กรุณาระบุ URL แหล่งข้อมูล RMS ก่อน', 'error'); return; }
    if (!await uiConfirm('ดึงและโอนข้อมูลผู้ใช้จากระบบ RMS เข้าสถานศึกษานี้?\nURL นี้จะถูกบันทึกไว้ใช้ในการโอนครั้งต่อไปด้วย\nผู้ใช้ที่มีอยู่แล้วจะถูกปรับปรุงข้อมูล (รหัสผ่านและรูปโปรไฟล์จาก RMS)',
        { title:'โอนข้อมูลผู้ใช้จาก RMS', confirmLabel:'โอนข้อมูล' })) return;

    const btn = document.querySelector('.rms-url-row .btn-primary');
    if (btn) btn.disabled = true;
    rmsProg.open();
    rmsProg.set('กำลังเชื่อมต่อระบบ RMS…', 'โปรดรอสักครู่ ระบบกำลังดึงข้อมูลผู้ใช้');
    try {
        const saved = await saveRmsUrl();
        if (!saved.ok) { rmsProg.finish('บันทึก URL ไม่สำเร็จ', saved.error, true); return; }

        const f = await apiPost({ action:'import_rms_users', phase:'fetch' });
        if (!f.ok) { rmsProg.finish('เชื่อมต่อ RMS ไม่สำเร็จ', f.error, true); return; }

        const total = f.data.total, token = f.data.token;
        if (total === 0) {
            rmsProg.finish('ไม่พบผู้ใช้ที่ต้องโอน', 'ไม่มีผู้ใช้ที่ยังทำงานอยู่ (people_exit=0) · ข้าม ' + f.data.skipped + ' รายการ');
            return;
        }

        rmsProg.set('กำลังโอนข้อมูลผู้ใช้…', 'กำลังบันทึกผู้ใช้และดาวน์โหลดรูปโปรไฟล์');
        rmsProg.progress(0, total);

        let offset = 0, newN = 0, updN = 0;
        while (true) {
            const b = await apiPost({ action:'import_rms_users', phase:'batch', token: token, offset: offset });
            if (!b.ok) { rmsProg.finish('เกิดข้อผิดพลาดระหว่างโอน', b.error, true); return; }
            newN += b.data.new; updN += b.data.updated; offset = b.data.next;
            rmsProg.progress(offset, total);
            if (b.data.done) break;
        }
        rmsProg.finish('โอนข้อมูลสำเร็จ',
            'เพิ่มใหม่ ' + newN + ' · ปรับปรุง ' + updN + ' · ข้าม ' + f.data.skipped + ' รายการ');
        setTimeout(() => location.reload(), 1800);
    } catch (e) {
        rmsProg.finish('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', true);
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
        if (!r.ok) { showToast(r.error, 'error'); return; }
        const pwSec  = document.getElementById('resetPwSection');
        const rmsSec = document.getElementById('resetRmsSection');
        const copyBtn = document.getElementById('resetCopyBtn');
        if (r.data && r.data.rms) {
            // RMS-imported user: no local reset — direct them to the RMS
            pwSec.classList.add('hidden');
            rmsSec.classList.remove('hidden');
            copyBtn.classList.add('hidden');
            document.getElementById('resetRmsName').textContent = name;
            document.getElementById('resetRmsLink').innerHTML = RMS_BASE_URL
                ? ' — <a href="' + escHtml(RMS_BASE_URL) + '" target="_blank" rel="noopener">' + escHtml(RMS_BASE_URL) + '</a>'
                : '';
        } else {
            pwSec.classList.remove('hidden');
            rmsSec.classList.add('hidden');
            copyBtn.classList.remove('hidden');
            document.getElementById('resetName').textContent = name;
            document.getElementById('resetPwDisplay').textContent = r.data.password;
        }
        document.getElementById('resetModal').classList.remove('hidden');
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

async function impersonate(userId, name) {
    if (!await uiConfirm('เข้าใช้งานระบบในนามของ ' + name + '?\nคุณจะเห็นและใช้งานระบบเสมือนเป็นผู้ใช้คนนี้ เมื่อออกจากระบบจะกลับสู่บัญชีผู้ดูแลโดยอัตโนมัติ',
        { title:'สวมสิทธิ์ผู้ใช้', confirmLabel:'สวมสิทธิ์' })) return;
    apiPost({ action:'impersonate', user_id: userId }).then(r => {
        if (r.ok) location.href = (r.data && r.data.redirect) ? r.data.redirect : (APP_URL + '/app.php');
        else showToast(r.error, 'error');
    });
}

async function setUserRole(userId, role, name) {
    const promote = role === 'schooladmin';
    const msg   = promote ? 'แต่งตั้งเป็นผู้ดูแลสถานศึกษา' : 'ถอดออกจากผู้ดูแล เป็นผู้กรอกข้อมูล';
    const detail = promote
        ? 'แต่งตั้ง ' + name + ' เป็นผู้ดูแลสถานศึกษาร่วม? ผู้ใช้จะจัดการผู้ใช้และตั้งค่าสถานศึกษาได้'
        : 'ถอด ' + name + ' ออกจากผู้ดูแลสถานศึกษา และเปลี่ยนเป็นผู้กรอกข้อมูล?';
    if (!await uiConfirm(detail, { title: msg, confirmLabel: promote ? 'แต่งตั้ง' : 'ถอดบทบาท', danger: !promote })) return;
    apiPost({ action:'set_user_role', user_id: userId, role }).then(r => {
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
