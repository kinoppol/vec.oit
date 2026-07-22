<?php
require_role('centraladmin');

// Load years with counts
$stmt = db()->query('
    SELECT fy.*, COUNT(DISTINCT ind.id) AS ind_cnt
    FROM fiscal_years fy
    LEFT JOIN indicator_sections sec ON sec.fiscal_year_id = fy.id
    LEFT JOIN indicator_subsections sub ON sub.section_id = sec.id
    LEFT JOIN indicators ind ON ind.subsection_id = sub.id
    GROUP BY fy.id ORDER BY fy.year_code DESC
');
$fyList = $stmt->fetchAll();

// Active year for tree display
$fyCode = $_GET['fy'] ?? ($fyList[0]['year_code'] ?? '2568');
$tree = [];
if ($fyCode) {
    $stmt2 = db()->prepare('
        SELECT sec.id AS sec_id, sec.code AS sec_code, sec.title AS sec_title, sec.sort_order AS so,
               sub.id AS sub_id, sub.code AS sub_code, sub.title AS sub_title,
               ind.id AS ind_id, ind.code AS ind_code, ind.title AS ind_title, ind.criteria
        FROM fiscal_years fy
        JOIN indicator_sections sec ON sec.fiscal_year_id = fy.id
        JOIN indicator_subsections sub ON sub.section_id = sec.id
        JOIN indicators ind ON ind.subsection_id = sub.id
        WHERE fy.year_code = ?
        ORDER BY CAST(REGEXP_REPLACE(sec.code,"[^0-9]","") AS UNSIGNED), sec.sort_order,
                 CAST(REGEXP_REPLACE(sub.code,"[^0-9]","") AS UNSIGNED), sub.sort_order,
                 CAST(REGEXP_REPLACE(ind.code,"[^0-9]","") AS UNSIGNED), ind.sort_order
    ');
    $stmt2->execute([$fyCode]);
    foreach ($stmt2->fetchAll() as $r) {
        $si = $r['sec_id']; $ui = $r['sub_id'];
        $tree[$si] ??= ['id' => $si, 'code' => $r['sec_code'], 'title' => $r['sec_title'], 'subs' => []];
        $tree[$si]['subs'][$ui] ??= ['id' => $ui, 'code' => $r['sub_code'], 'title' => $r['sub_title'], 'inds' => []];
        $tree[$si]['subs'][$ui]['inds'][] = $r;
    }
    foreach ($tree as &$s) $s['subs'] = array_values($s['subs']);
    unset($s);
    $tree = array_values($tree);
}

// Reference files attached to each indicator (criterion)
$critFiles = [];
$indIds = [];
foreach ($tree as $sec) foreach ($sec['subs'] as $sub) foreach ($sub['inds'] as $ind) $indIds[] = (int)$ind['ind_id'];
if ($indIds) {
    $ph = implode(',', array_fill(0, count($indIds), '?'));
    $fStmt = db()->prepare("SELECT * FROM indicator_files WHERE indicator_id IN ($ph) ORDER BY id");
    $fStmt->execute($indIds);
    foreach ($fStmt->fetchAll() as $r) $critFiles[(int)$r['indicator_id']][] = $r;
}
?>
<div class="criteria-layout">

  <!-- ─── FISCAL YEARS PANEL ─── -->
  <div class="card fy-card">
    <div class="card-header">
      ปีงบประมาณ
      <button class="btn btn-primary btn-sm" onclick="openAddFY()">+ เพิ่มปี</button>
    </div>
    <div class="fy-list">
      <?php foreach ($fyList as $fy): ?>
      <div class="fy-row <?= $fy['year_code']===$fyCode?'active':'' ?>">
        <a href="?view=criteria&fy=<?= e($fy['year_code']) ?>" class="fy-link">
          <span class="fy-year"><?= e($fy['year_code']) ?></span>
          <span class="fy-label"><?= e($fy['label']) ?></span>
          <span class="fy-cnt"><?= $fy['ind_cnt'] ?> ตัวชี้วัด</span>
        </a>
        <?php if ($fy['is_active']): ?>
        <span class="chip chip-done chip-xs">ใช้งาน</span>
        <?php else: ?>
        <button class="btn btn-ghost btn-xs" onclick="setActiveFY(<?= $fy['id'] ?>, '<?= e($fy['year_code']) ?>')">ตั้งเป็นปีใช้งาน</button>
        <?php endif; ?>
        <a class="icon-btn" title="ส่งออกตัวชี้วัดปีนี้" href="<?= APP_URL ?>/api.php?action=export_indicators&fy=<?= e($fy['year_code']) ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ─── INDICATOR TREE ─── -->
  <div class="card criteria-tree-card">
    <div class="card-header">
      ตัวชี้วัด OIT ปี <?= e($fyCode) ?>
      <div class="card-header-actions">
        <a class="btn btn-ghost btn-sm" href="<?= APP_URL ?>/api.php?action=export_indicators&fy=<?= e($fyCode) ?>">
          ⬇ ส่งออก
        </a>
        <button class="btn btn-ghost btn-sm" onclick="openImport()">⬆ นำเข้า</button>
        <button class="btn btn-primary btn-sm" onclick="openAddInd()">+ เพิ่มตัวชี้วัด</button>
      </div>
    </div>
    <div class="criteria-tree">
      <?php foreach ($tree as $sec): ?>
      <div class="crit-section">
        <div class="crit-sec-hdr">
          <span class="crit-code"><?= e($sec['code']) ?></span>
          <span><?= e($sec['title']) ?></span>
        </div>
        <?php foreach ($sec['subs'] as $sub): ?>
        <div class="crit-sub">
          <div class="crit-sub-hdr">
            <span class="crit-code crit-sub-code"><?= e($sub['code']) ?></span>
            <?= e($sub['title']) ?>
          </div>
          <?php foreach ($sub['inds'] as $ind): $iid = (int)$ind['ind_id']; $files = $critFiles[$iid] ?? []; ?>
          <div class="crit-ind">
            <div class="crit-ind-row">
              <span class="crit-ind-code"><?= e($ind['ind_code']) ?></span>
              <span class="crit-ind-title"><?= e($ind['ind_title']) ?></span>
              <label class="icon-btn crit-attach" title="แนบเอกสารประกอบเกณฑ์">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                <input type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx" style="display:none" onchange="uploadCritFiles(<?= $iid ?>, this)">
              </label>
              <button class="icon-btn" title="แก้ไข" onclick="editInd(<?= e(json_encode([
                  'id' => $ind['ind_id'],
                  'code' => $ind['ind_code'],
                  'title' => $ind['ind_title'],
                  'criteria' => $ind['criteria'],
                  'sub_id' => $ind['sub_id'],
              ])) ?>)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
            </div>
            <?php if ($files): ?>
            <div class="crit-files">
              <?php foreach ($files as $f):
                $url = APP_URL . '/uploads/' . rawurlencode($f['file_path']);
                $isImg = $f['type'] === 'image';
              ?>
              <span class="crit-file">
                <a href="<?= $url ?>" target="_blank" class="crit-file-link">
                  <?php if ($isImg): ?>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                  <?php else: ?>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5"/></svg>
                  <?php endif; ?>
                  <?= e($f['title']) ?>
                </a>
                <button class="crit-file-x" title="ลบไฟล์" onclick="deleteCritFile(<?= (int)$f['id'] ?>)">✕</button>
              </span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ─── ADD FISCAL YEAR MODAL ─── -->
<div class="modal-backdrop hidden" id="addFYModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">เพิ่มปีงบประมาณ</h2>
      <button class="modal-close" onclick="document.getElementById('addFYModal').classList.add('hidden')">✕</button>
    </div>
    <form id="addFYForm" class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_fiscal_year">
      <div class="form-group">
        <label class="form-label">รหัสปี (พ.ศ.) <span class="req">*</span></label>
        <input type="text" name="year_code" class="form-input" required pattern="\d{4}" placeholder="เช่น 2569" maxlength="4">
      </div>
      <div class="form-group">
        <label class="form-label">ป้ายชื่อ <span class="req">*</span></label>
        <input type="text" name="label" class="form-input" required maxlength="100" placeholder="เช่น ปีงบประมาณ พ.ศ. 2569">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addFYModal').classList.add('hidden')">ยกเลิก</button>
        <button type="submit" class="btn btn-primary">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<!-- ─── ADD/EDIT INDICATOR MODAL ─── -->
<div class="modal-backdrop hidden" id="indModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2 class="modal-title" id="indModalTitle">เพิ่มตัวชี้วัด</h2>
      <button class="modal-close" onclick="document.getElementById('indModal').classList.add('hidden')">✕</button>
    </div>
    <form id="indForm" class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="indAction" value="add_indicator">
      <input type="hidden" name="id" id="indIdField">
      <input type="hidden" name="year_code" value="<?= e($fyCode) ?>">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">รหัส <span class="req">*</span></label>
          <input type="text" name="code" id="indCode" class="form-input" required maxlength="10" placeholder="O1">
        </div>
        <div class="form-group">
          <label class="form-label">หมวดย่อย <span class="req">*</span></label>
          <select name="sub_id" id="indSubId" class="form-input" required>
            <?php
            foreach ($tree as $sec): foreach ($sec['subs'] as $sub):
            echo '<option value="' . $sub['id'] . '">' . e($sub['code']) . ' — ' . e($sub['title']) . '</option>';
            endforeach; endforeach;
            ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">ชื่อตัวชี้วัด <span class="req">*</span></label>
        <input type="text" name="title" id="indTitle" class="form-input" required maxlength="500">
      </div>
      <div class="form-group">
        <label class="form-label">เกณฑ์การพิจารณา</label>
        <textarea name="criteria" id="indCriteria" class="form-input form-textarea" rows="5" maxlength="2000"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('indModal').classList.add('hidden')">ยกเลิก</button>
        <button type="submit" class="btn btn-primary">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<!-- ─── IMPORT INDICATORS MODAL ─── -->
<div class="modal-backdrop hidden" id="importModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">นำเข้าตัวชี้วัด — ปี <?= e($fyCode) ?></h2>
      <button class="modal-close" onclick="document.getElementById('importModal').classList.add('hidden')">✕</button>
    </div>
    <form id="importForm" class="modal-body" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_indicators">
      <input type="hidden" name="year_code" value="<?= e($fyCode) ?>">
      <div class="alert alert-info" style="margin-bottom:16px">
        เลือกไฟล์ JSON ที่ส่งออกจากระบบ (ปีใดก็ได้) ระบบจะเพิ่มหมวด/หมวดย่อย/ตัวชี้วัดเข้าปี <b><?= e($fyCode) ?></b>
        โดยยึดตาม <b>รหัส (code)</b> — รายการที่มีรหัสซ้ำจะถูกปรับปรุงข้อมูล ไม่สร้างซ้ำ
      </div>
      <div class="form-group">
        <label class="form-label">ไฟล์ตัวชี้วัด (.json) <span class="req">*</span></label>
        <input type="file" name="file" id="importFile" class="form-input" accept="application/json,.json" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('importModal').classList.add('hidden')">ยกเลิก</button>
        <button type="submit" class="btn btn-primary" id="importSubmit">นำเข้า</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddFY() { document.getElementById('addFYModal').classList.remove('hidden'); }
function openImport() { document.getElementById('importModal').classList.remove('hidden'); }

// ── Criteria reference files ──
function uploadCritFiles(indId, input) {
    if (!input.files || !input.files.length) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', 'add_criteria_file');
    fd.append('indicator_id', indId);
    for (const f of input.files) fd.append('files[]', f);
    showToast('กำลังอัปโหลด…');
    fetch(APP_URL + '/api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (r.ok) { showToast('แนบเอกสาร ' + r.data.created + ' ไฟล์แล้ว'); setTimeout(() => location.reload(), 700); }
            else showToast(r.error, 'error');
        })
        .catch(() => showToast('เกิดข้อผิดพลาดในการอัปโหลด', 'error'));
}
async function deleteCritFile(id) {
    if (!await uiConfirm('ลบเอกสารประกอบเกณฑ์นี้?', { title:'ลบเอกสาร', confirmLabel:'ลบ', danger:true })) return;
    apiPost({ action:'delete_criteria_file', id }).then(r => {
        r.ok ? location.reload() : showToast(r.error, 'error');
    });
}

document.getElementById('importForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('importSubmit');
    btn.disabled = true; btn.textContent = 'กำลังนำเข้า...';
    fetch(APP_URL + '/api.php', { method:'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                const d = r.data;
                showToast('นำเข้าสำเร็จ: เพิ่มตัวชี้วัด ' + d.ind_new + ', ปรับปรุง ' + d.ind_upd + ' รายการ');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(r.error, 'error');
                btn.disabled = false; btn.textContent = 'นำเข้า';
            }
        })
        .catch(() => { showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error'); btn.disabled = false; btn.textContent = 'นำเข้า'; });
});

async function setActiveFY(id, code) {
    if (!await uiConfirm('ตั้งปีงบประมาณ ' + code + ' เป็นปีที่ใช้งานปัจจุบัน?',
        { title:'เปลี่ยนปีงบประมาณที่ใช้งาน', confirmLabel:'ตั้งเป็นปีปัจจุบัน' })) return;
    apiPost({ action:'set_active_year', id }).then(r => {
        r.ok ? location.reload() : showToast(r.error, 'error');
    });
}

function openAddInd() {
    document.getElementById('indModalTitle').textContent = 'เพิ่มตัวชี้วัด';
    document.getElementById('indAction').value = 'add_indicator';
    document.getElementById('indIdField').value = '';
    document.getElementById('indCode').value = '';
    document.getElementById('indTitle').value = '';
    document.getElementById('indCriteria').value = '';
    document.getElementById('indModal').classList.remove('hidden');
}

function editInd(data) {
    document.getElementById('indModalTitle').textContent = 'แก้ไขตัวชี้วัด';
    document.getElementById('indAction').value = 'edit_indicator';
    document.getElementById('indIdField').value = data.id;
    document.getElementById('indCode').value = data.code;
    document.getElementById('indTitle').value = data.title;
    document.getElementById('indCriteria').value = data.criteria || '';
    document.getElementById('indSubId').value = data.sub_id;
    document.getElementById('indModal').classList.remove('hidden');
}

document.getElementById('addFYForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fetch(APP_URL + '/api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(r => { r.ok ? location.reload() : showToast(r.error, 'error'); });
});

document.getElementById('indForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fetch(APP_URL + '/api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (r.ok) { document.getElementById('indModal').classList.add('hidden'); location.reload(); }
            else showToast(r.error, 'error');
        });
});
</script>
