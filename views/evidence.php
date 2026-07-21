<?php
// Data-entry users see only indicators assigned to them
$assigneeFilter = $role === 'user' ? (int)$user['id'] : null;
$tree = indicator_tree($schoolId, $yearCode, $assigneeFilter);
$selectedId = isset($_GET['indicator']) ? (int)$_GET['indicator'] : 0;
?>
<div class="evidence-layout">

  <!-- ─── LEFT: Indicator Tree ─── -->
  <div class="ind-tree" id="indTree">
    <div class="tree-search">
      <input type="search" id="treeSearch" placeholder="ค้นหาตัวชี้วัด…" autocomplete="off">
    </div>
    <div class="tree-body" id="treeBody">
      <?php foreach ($tree as $sec): ?>
      <div class="tree-section">
        <div class="tree-sec-header" data-sec="<?= $sec['id'] ?>">
          <svg class="tree-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
          <span class="sec-code-small"><?= e($sec['code']) ?></span>
          <span><?= e($sec['title']) ?></span>
        </div>
        <div class="tree-subs" id="secBody<?= $sec['id'] ?>">
          <?php foreach ($sec['subs'] as $sub): ?>
          <div class="tree-sub-header">
            <span class="sub-code-small"><?= e($sub['code']) ?></span>
            <span><?= e($sub['title']) ?></span>
          </div>
          <?php foreach ($sub['inds'] as $ind): ?>
          <div class="tree-ind <?= $ind['id']===$selectedId?'active':'' ?> status-<?= e($ind['status']) ?>"
               data-id="<?= $ind['id'] ?>"
               data-code="<?= e($ind['code']) ?>"
               data-title="<?= e($ind['title']) ?>"
               onclick="loadIndicator(<?= $ind['id'] ?>)">
            <div class="tree-ind-row">
              <span class="ind-code-small"><?= e($ind['code']) ?></span>
              <span class="ind-title-small"><?= e($ind['title']) ?></span>
            </div>
            <div class="tree-ind-meta">
              <?= status_chip($ind['status']) ?>
              <?php if ($ind['ev_count']): ?>
              <span class="ev-badge"><?= $ind['ev_count'] ?> หลักฐาน</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($ind['assignee_pos_name'])): ?>
            <div class="tree-ind-assignee" title="ผู้รับผิดชอบ (ตำแหน่ง)">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <?= e($ind['assignee_pos_name']) ?>
            </div>
            <?php elseif (!empty($ind['assignee_name'])): ?>
            <div class="tree-ind-assignee" title="ผู้รับผิดชอบ">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <?= e($ind['assignee_name']) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ─── RIGHT: Detail Panel ─── -->
  <div class="ind-detail" id="indDetail">
    <?php if ($selectedId): ?>
    <div id="detailContent" hx-get="api.php?action=indicator_detail&id=<?= $selectedId ?>&school=<?= $schoolId ?>&year=<?= e($yearCode) ?>">
      <div class="loading-spinner">กำลังโหลด…</div>
    </div>
    <?php else: ?>
    <div class="detail-empty">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--text-tertiary)" stroke-width="1.2"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5M8 13h8M8 17h5"/></svg>
      <div class="detail-empty-text">เลือกตัวชี้วัดจากรายการด้านซ้าย</div>
      <div class="detail-empty-sub">เพื่อดูรายละเอียดและกรอกหลักฐาน</div>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ─── ADD EVIDENCE MODAL ─── -->
<div class="modal-backdrop hidden" id="evModal">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h2 class="modal-title" id="evModalTitle">เพิ่มหลักฐาน</h2>
      <button class="modal-close" onclick="closeEvModal()" aria-label="ปิด">✕</button>
    </div>
    <form id="evForm" class="modal-body" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="evAction" value="add_evidence">
      <input type="hidden" name="indicator_id" id="evIndId">
      <input type="hidden" name="evidence_id" id="evEvId">
      <input type="hidden" name="school_id" value="<?= $schoolId ?>">
      <input type="hidden" name="year_code" value="<?= e($yearCode) ?>">
      <div class="form-group">
        <label class="form-label">ชื่อหลักฐาน <span class="req">*</span></label>
        <input type="text" name="name" id="evName" class="form-input" required maxlength="255" placeholder="เช่น ประกาศแต่งตั้งคณะทำงาน">
      </div>
      <div class="form-group">
        <label class="form-label">ประเภทลิงก์</label>
        <div class="link-type-tabs">
          <label class="link-tab"><input type="radio" name="link_type" value="url" checked> URL</label>
          <label class="link-tab"><input type="radio" name="link_type" value="file"> ไฟล์</label>
        </div>
      </div>
      <div class="form-group" id="urlGroup">
        <label class="form-label">URL ลิงก์</label>
        <input type="url" name="url" id="evUrl" class="form-input" placeholder="https://…" maxlength="2000">
      </div>
      <div class="form-group hidden" id="fileGroup">
        <label class="form-label">อัปโหลดไฟล์/รูปภาพ (ไม่เกิน 10 MB ต่อไฟล์)</label>
        <input type="file" name="upload[]" id="evFileInput" class="form-input form-file" multiple
               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp">
        <div class="form-hint">เลือกได้หลายไฟล์พร้อมกัน — ระบบจะสร้างเป็นหลายรายการให้อัตโนมัติ</div>
        <div class="form-hint hidden" id="evCurrentFile"></div>
      </div>
      <div class="form-group">
        <label class="form-label">หมายเหตุ</label>
        <textarea name="note" id="evNote" class="form-input form-textarea" rows="3" placeholder="คำอธิบายเพิ่มเติม (ไม่จำเป็น)" maxlength="1000"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeEvModal()">ยกเลิก</button>
        <button type="submit" class="btn btn-primary" id="evSubmitBtn">บันทึกหลักฐาน</button>
      </div>
    </form>
  </div>
</div>

<script>
// Expose tree data for JS
window.treeData = <?= json_encode(array_map(fn($s) => [
    'id' => $s['id'],
    'subs' => array_map(fn($u) => [
        'id' => $u['id'],
        'inds' => array_map(fn($i) => ['id' => $i['id'], 'code' => $i['code'], 'title' => $i['title']], $u['inds'])
    ], $s['subs'])
], $tree), JSON_UNESCAPED_UNICODE) ?>;
window.selectedIndicatorId = <?= $selectedId ?: 'null' ?>;
</script>
