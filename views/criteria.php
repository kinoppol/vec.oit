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
        ORDER BY sec.sort_order, sub.sort_order, ind.sort_order
    ');
    $stmt2->execute([$fyCode]);
    foreach ($stmt2->fetchAll() as $r) {
        $si = $r['sec_id']; $ui = $r['sub_id'];
        $tree[$si] ??= ['id' => $si, 'code' => $r['sec_code'], 'title' => $r['sec_title'], 'subs' => []];
        $tree[$si]['subs'][$ui] ??= ['id' => $ui, 'code' => $r['sub_code'], 'title' => $r['sub_title'], 'inds' => []];
        $tree[$si]['subs'][$ui]['inds'][] = $r;
    }
    foreach ($tree as &$s) $s['subs'] = array_values($s['subs']);
    $tree = array_values($tree);
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
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ─── INDICATOR TREE ─── -->
  <div class="card criteria-tree-card">
    <div class="card-header">
      ตัวชี้วัด OIT ปี <?= e($fyCode) ?>
      <button class="btn btn-primary btn-sm" onclick="openAddInd()">+ เพิ่มตัวชี้วัด</button>
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
          <?php foreach ($sub['inds'] as $ind): ?>
          <div class="crit-ind-row">
            <span class="crit-ind-code"><?= e($ind['ind_code']) ?></span>
            <span class="crit-ind-title"><?= e($ind['ind_title']) ?></span>
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

<script>
function openAddFY() { document.getElementById('addFYModal').classList.remove('hidden'); }

function setActiveFY(id, code) {
    if (!confirm('ตั้งปี ' + code + ' เป็นปีงบประมาณที่ใช้งาน?')) return;
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
