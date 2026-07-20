<?php
// Variables expected: $ind (indicator row), $evidences (array), $schoolId, $yearCode
// This file is included by api.php's indicator_detail action
// It outputs raw HTML for the AJAX detail panel
?>
<div class="detail-panel" data-ind-id="<?= e($ind['id']) ?>">
  <div class="detail-head">
    <div class="detail-code-wrap">
      <span class="detail-code"><?= e($ind['code']) ?></span>
      <h2 class="detail-title"><?= e($ind['title']) ?></h2>
    </div>
    <?= status_chip($ind['status']) ?>
  </div>

  <?php if (!empty($ind['criteria'])): ?>
  <div class="detail-criteria">
    <div class="detail-criteria-hdr">เกณฑ์การพิจารณา</div>
    <div class="detail-criteria-body"><?= nl2br(e($ind['criteria'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- STATUS UPDATE -->
  <div class="detail-section">
    <div class="detail-section-hdr">อัปเดตสถานะ</div>
    <div class="status-btn-row">
      <button class="status-btn <?= $ind['status']==='pending'?'active':'' ?>"
              data-status="pending" onclick="updateStatus(<?= $ind['id'] ?>, 'pending')">
        <?= status_icon('pending') ?> ยังไม่ดำเนินการ
      </button>
      <button class="status-btn status-prog <?= $ind['status']==='inprogress'?'active':'' ?>"
              data-status="inprogress" onclick="updateStatus(<?= $ind['id'] ?>, 'inprogress')">
        <?= status_icon('inprogress') ?> กำลังดำเนินการ
      </button>
      <button class="status-btn status-done <?= $ind['status']==='done'?'active':'' ?>"
              data-status="done" onclick="updateStatus(<?= $ind['id'] ?>, 'done')">
        <?= status_icon('done') ?> เผยแพร่แล้ว
      </button>
    </div>
    <?php if (!empty($ind['status_note'])): ?>
    <div class="status-note-display"><?= e($ind['status_note']) ?></div>
    <?php endif; ?>
  </div>

  <!-- RESPONSIBLE USER -->
  <div class="detail-section">
    <div class="detail-section-hdr">ผู้รับผิดชอบ</div>
    <?php if (!empty($canAssign)): ?>
    <select class="form-input assignee-select" onchange="assignIndicator(<?= (int)$ind['id'] ?>, this.value)">
      <option value="0">— ยังไม่มอบหมาย —</option>
      <?php foreach (($schoolUsers ?? []) as $su): ?>
      <option value="<?= (int)$su['id'] ?>" <?= (int)($ind['assigned_user_id'] ?? 0) === (int)$su['id'] ? 'selected' : '' ?>>
        <?= e($su['full_name']) ?><?= $su['role'] === 'schooladmin' ? ' (ผู้ดูแล)' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <div class="assignee-display">
      <?php if (!empty($ind['assignee_name'])): ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?= e($ind['assignee_name']) ?>
      <?php else: ?>
        <span class="assignee-none">ยังไม่มีการมอบหมายผู้รับผิดชอบ</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- EVIDENCE LIST -->
  <div class="detail-section" id="evSection<?= $ind['id'] ?>">
    <div class="detail-section-hdr">
      หลักฐาน (<?= count($evidences) ?>)
      <button class="btn btn-primary btn-sm" onclick="openEvModal(<?= $ind['id'] ?>)">+ เพิ่ม</button>
    </div>
    <div class="ev-list" id="evList<?= $ind['id'] ?>" data-ind="<?= $ind['id'] ?>">
      <?php if (empty($evidences)): ?>
      <div class="ev-empty">ยังไม่มีหลักฐาน</div>
      <?php else: ?>
      <?php foreach ($evidences as $ev):
        $isImg = ($ev['type'] === 'image') || ($ev['file_path'] && in_array(strtolower(pathinfo($ev['file_path'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']));
        $fileUrl = $ev['file_path'] ? APP_URL . '/uploads/' . rawurlencode($ev['file_path']) : null;
      ?>
      <div class="ev-item" id="ev<?= $ev['id'] ?>" draggable="true" data-ev-id="<?= $ev['id'] ?>">
        <div class="ev-drag" title="ลากเพื่อจัดลำดับ">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
        </div>
        <?php if ($isImg && $fileUrl): ?>
        <a href="<?= $fileUrl ?>" target="_blank" class="ev-thumb"><img src="<?= $fileUrl ?>" alt="<?= e($ev['title']) ?>" loading="lazy"></a>
        <?php else: ?>
        <div class="ev-icon">
          <?php if ($ev['url']): ?>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          <?php else: ?>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5"/></svg>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="ev-body">
          <?php if ($ev['url']): ?>
          <a href="<?= e($ev['url']) ?>" target="_blank" rel="noopener" class="ev-name"><?= e($ev['title']) ?></a>
          <?php elseif ($fileUrl): ?>
          <a href="<?= $fileUrl ?>" target="_blank" class="ev-name"><?= e($ev['title']) ?></a>
          <?php else: ?>
          <span class="ev-name"><?= e($ev['title']) ?></span>
          <?php endif; ?>
          <?php if ($ev['note']): ?>
          <div class="ev-note"><?= e($ev['note']) ?></div>
          <?php endif; ?>
          <div class="ev-date"><?= thai_datetime($ev['created_at']) ?></div>
        </div>
        <div class="ev-actions">
          <button class="icon-btn ev-edit" title="แก้ไขหลักฐาน"
                  onclick="openEvEdit(<?= e(json_encode([
                      'id'        => (int)$ev['id'],
                      'ind_id'    => (int)$ind['id'],
                      'title'     => $ev['title'],
                      'url'       => $ev['url'],
                      'file_path' => $ev['file_path'],
                      'note'      => $ev['note'],
                  ], JSON_UNESCAPED_UNICODE)) ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <button class="icon-btn icon-btn-danger ev-del" title="ลบหลักฐาน"
                  onclick="deleteEvidence(<?= $ev['id'] ?>, <?= $ind['id'] ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
