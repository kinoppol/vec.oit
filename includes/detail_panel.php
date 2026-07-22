<?php
// Variables expected: $ind, $evidences, $schoolId, $yearCode, plus capability
// flags and team data from api.php's indicator_detail action:
//   $canManage, $isResponsible, $isSchoolAdmin, $isAssistant, $canAssign, $viewerId
//   $assistants, $approvedAssistants, $docTasks, $taskAssignees, $schoolUsers
$canManage     = $canManage     ?? false;
$isResponsible = $isResponsible ?? false;
$isSchoolAdmin = $isSchoolAdmin ?? false;
$isAssistant   = $isAssistant   ?? false;
$viewerId      = $viewerId      ?? 0;
$assistants    = $assistants    ?? [];
$approvedAssistants = $approvedAssistants ?? [];
$docTasks      = $docTasks      ?? [];
$taskAssignees = $taskAssignees ?? [];
$schoolUsers   = $schoolUsers   ?? [];

// Split evidence into task-linked (shown under its document task) and free (indicator-level)
$freeEv = [];
$taskEv = [];
foreach ($evidences as $ev) {
    if (!empty($ev['task_id'])) $taskEv[(int)$ev['task_id']][] = $ev;
    else $freeEv[] = $ev;
}

// One evidence row — reused by the free list and each document task
$renderEv = function (array $ev, bool $draggable = true) use ($ind, $canManage, $viewerId) {
    $isImg   = ($ev['type'] === 'image') || ($ev['file_path'] && in_array(strtolower(pathinfo($ev['file_path'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']));
    $fileUrl = $ev['file_path'] ? APP_URL . '/uploads/' . rawurlencode($ev['file_path']) : null;
    $accepted = (int)($ev['accepted'] ?? 1) === 1;
    $mine     = (int)($ev['created_by'] ?? 0) === $viewerId;
    ?>
    <div class="ev-item<?= $accepted ? '' : ' ev-item-wait' ?>" id="ev<?= $ev['id'] ?>" <?= $draggable ? 'draggable="true"' : '' ?> data-ev-id="<?= $ev['id'] ?>">
      <?php if ($draggable): ?>
      <div class="ev-drag" title="ลากเพื่อจัดลำดับ">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
      </div>
      <?php endif; ?>
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
        <div class="ev-name-row">
          <?php if ($ev['url']): ?>
          <a href="<?= e($ev['url']) ?>" target="_blank" rel="noopener" class="ev-name"><?= e($ev['title']) ?></a>
          <?php elseif ($fileUrl): ?>
          <a href="<?= $fileUrl ?>" target="_blank" class="ev-name"><?= e($ev['title']) ?></a>
          <?php else: ?>
          <span class="ev-name"><?= e($ev['title']) ?></span>
          <?php endif; ?>
          <span class="ev-badge <?= $accepted ? 'ev-ok' : 'ev-wait' ?>"><?= $accepted ? 'เผยแพร่ได้' : 'รอตรวจรับ' ?></span>
        </div>
        <?php if ($ev['note']): ?><div class="ev-note"><?= e($ev['note']) ?></div><?php endif; ?>
        <div class="ev-meta">
          <?php if (!empty($ev['creator_name'])): ?>
          <span class="ev-attacher"><?= user_avatar_html(['full_name' => $ev['creator_name'], 'avatar' => $ev['creator_avatar'] ?? null], 'avatar-xs') ?><?= e($ev['creator_name']) ?></span>
          <?php endif; ?>
          <span class="ev-date"><?= thai_datetime($ev['created_at']) ?></span>
        </div>
      </div>
      <div class="ev-actions">
        <?php if ($canManage): ?>
        <?php if ($accepted): ?>
        <button class="btn btn-ghost btn-xs ev-unaccept" title="ยกเลิกการยอมรับ (ถอนจากการเผยแพร่)" onclick="unacceptEvidence(<?= $ev['id'] ?>)">ยกเลิกรับ</button>
        <?php else: ?>
        <button class="btn btn-primary btn-xs ev-accept" title="ยอมรับให้เผยแพร่ได้" onclick="acceptEvidence(<?= $ev['id'] ?>)">ยอมรับ</button>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($mine || $canManage): ?>
        <button class="icon-btn ev-edit" title="แก้ไขหลักฐาน"
                onclick="openEvEdit(<?= e(json_encode(['id'=>(int)$ev['id'],'ind_id'=>(int)$ind['id'],'title'=>$ev['title'],'url'=>$ev['url'],'file_path'=>$ev['file_path'],'note'=>$ev['note']], JSON_UNESCAPED_UNICODE)) ?>)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="icon-btn icon-btn-danger ev-del" title="ลบหลักฐาน" onclick="deleteEvidence(<?= $ev['id'] ?>, <?= $ind['id'] ?>)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
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
    <?php
      // Current assignment display value + type
      if (!empty($ind['assigned_position_id'])) { $curVal = 'ตำแหน่ง: ' . ($ind['assignee_pos_name'] ?? ''); $curType = 'position'; $curId = (int)$ind['assigned_position_id']; }
      elseif (!empty($ind['assigned_user_id']))  { $curVal = $ind['assignee_name'] ?? ''; $curType = 'user'; $curId = (int)$ind['assigned_user_id']; }
      else { $curVal = ''; $curType = 'none'; $curId = 0; }
    ?>
    <?php if (!empty($canAssign)): ?>
    <div class="assignee-ac" data-ind="<?= (int)$ind['id'] ?>">
      <input type="text" class="form-input assignee-input" autocomplete="off"
             placeholder="พิมพ์ชื่อ / ชื่อเล่น / นามสกุล / ตำแหน่ง…" value="<?= e($curVal) ?>">
      <button type="button" class="assignee-clear" title="ล้างการมอบหมาย" <?= $curType === 'none' ? 'style="display:none"' : '' ?>>✕</button>
      <div class="assignee-menu hidden"></div>
    </div>
    <script type="application/json" class="assignee-data"><?= json_encode([
        'users' => array_map(fn($su) => [
            'id'    => (int)$su['id'],
            'name'  => $su['full_name'],
            'nick'  => $su['nickname'] ?? '',
            'pos'   => $su['position'] ?? '',
            'admin' => $su['role'] === 'schooladmin',
            'pic'   => user_avatar_url($su),
        ], $schoolUsers ?? []),
        'positions' => array_map(fn($p) => [
            'id' => (int)$p['id'], 'name' => $p['name'], 'n' => (int)$p['n'],
        ], $schoolPositions ?? []),
        'current' => ['type' => $curType, 'name' => $curVal],
    ], JSON_UNESCAPED_UNICODE) ?></script>
    <?php else: ?>
    <div class="assignee-display">
      <?php if ($curType === 'position'): ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        ตำแหน่ง: <?= e($ind['assignee_pos_name'] ?? '') ?>
      <?php elseif ($curType === 'user'): ?>
        <?php if (!empty($ind['assignee_avatar'])): ?>
        <span class="avatar avatar-xs avatar-img"><img src="<?= e(user_avatar_url(['avatar' => $ind['assignee_avatar']])) ?>" alt=""></span>
        <?php else: ?>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php endif; ?>
        <?= e($ind['assignee_name']) ?>
      <?php else: ?>
        <span class="assignee-none">ยังไม่มีการมอบหมายผู้รับผิดชอบ</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ASSISTANTS -->
  <div class="detail-section" id="asstSection<?= $ind['id'] ?>">
    <div class="detail-section-hdr">
      ผู้ช่วยผู้รับผิดชอบ (<?= count($assistants) ?>)
      <?php if ($canManage): ?>
      <button class="btn btn-ghost btn-sm" onclick="openAssistantPicker(<?= $ind['id'] ?>)">+ <?= $isSchoolAdmin ? 'เพิ่มผู้ช่วย' : 'เสนอผู้ช่วย' ?></button>
      <?php endif; ?>
    </div>
    <?php if (empty($assistants)): ?>
    <div class="asst-empty">ยังไม่มีผู้ช่วยผู้รับผิดชอบ</div>
    <?php else: ?>
    <div class="asst-list">
      <?php foreach ($assistants as $a): ?>
      <div class="asst-item">
        <?= user_avatar_html(['full_name' => $a['full_name'], 'avatar' => $a['avatar']], 'avatar-sm') ?>
        <div class="asst-info">
          <div class="asst-name"><?= e($a['full_name']) ?><?php if (!empty($a['nickname'])): ?> <span class="user-nick">(<?= e($a['nickname']) ?>)</span><?php endif; ?></div>
          <?php if ($a['status'] === 'approved'): ?><span class="chip chip-done">อนุมัติแล้ว</span><?php else: ?><span class="chip chip-prog">รออนุมัติ</span><?php endif; ?>
        </div>
        <div class="asst-actions">
          <?php if ($a['status'] === 'proposed' && $isSchoolAdmin): ?>
          <button class="btn btn-primary btn-xs" onclick="approveAssistant(<?= $a['id'] ?>)">อนุมัติ</button>
          <?php endif; ?>
          <?php if ($canManage): ?>
          <button class="icon-btn icon-btn-danger" title="นำผู้ช่วยออก" onclick="removeAssistant(<?= $a['id'] ?>, <?= e(json_encode($a['full_name'])) ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- DOCUMENT TASKS -->
  <div class="detail-section" id="taskSection<?= $ind['id'] ?>">
    <div class="detail-section-hdr">
      หัวข้อเอกสาร (<?= count($docTasks) ?>)
      <?php if ($canManage && $approvedAssistants): ?>
      <button class="btn btn-ghost btn-sm" onclick="openDocTaskModal(<?= $ind['id'] ?>)">+ เพิ่มหัวข้อ</button>
      <?php endif; ?>
    </div>
    <?php if ($canManage && empty($approvedAssistants)): ?>
    <div class="asst-empty">มีผู้ช่วยที่อนุมัติแล้วก่อน จึงจะมอบหมายหัวข้อเอกสารได้</div>
    <?php elseif (empty($docTasks)): ?>
    <div class="asst-empty">ยังไม่มีหัวข้อเอกสาร</div>
    <?php endif; ?>
    <?php foreach ($docTasks as $t):
      $tas = $taskAssignees[(int)$t['id']] ?? [];
      $tev = $taskEv[(int)$t['id']] ?? [];
      $isAsgn = in_array($viewerId, array_map(fn($u) => (int)$u['id'], $tas), true);
    ?>
    <div class="task-card">
      <div class="task-hdr">
        <div class="task-title"><?= e($t['title']) ?></div>
        <?php if ($canManage): ?>
        <div class="task-actions">
          <button class="icon-btn" title="แก้ไขหัวข้อ" onclick="openDocTaskModal(<?= $ind['id'] ?>, <?= e(json_encode(['id'=>(int)$t['id'],'title'=>$t['title'],'description'=>$t['description'],'assignees'=>array_map(fn($u)=>(int)$u['id'],$tas)], JSON_UNESCAPED_UNICODE)) ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <button class="icon-btn icon-btn-danger" title="ลบหัวข้อ" onclick="deleteDocTask(<?= $t['id'] ?>, <?= e(json_encode($t['title'])) ?>)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          </button>
        </div>
        <?php endif; ?>
      </div>
      <div class="task-desc"><?= nl2br(e($t['description'])) ?></div>
      <?php if ($tas): ?>
      <div class="task-assignees">
        <span class="task-asgn-label">ผู้รับมอบ:</span>
        <?php foreach ($tas as $u): ?>
        <span class="task-asgn"><?= user_avatar_html(['full_name' => $u['full_name'], 'avatar' => $u['avatar']], 'avatar-xs') ?><?= e($u['full_name']) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="task-ev ev-list">
        <?php if (empty($tev)): ?><div class="ev-empty">ยังไม่มีไฟล์แนบ</div>
        <?php else: foreach ($tev as $ev) { $renderEv($ev, false); } endif; ?>
      </div>
      <?php if ($canManage || $isAsgn): ?>
      <button class="btn btn-ghost btn-xs task-attach" onclick="openEvModal(<?= $ind['id'] ?>, <?= $t['id'] ?>)">+ แนบไฟล์ในหัวข้อนี้</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- EVIDENCE (free / indicator-level) -->
  <div class="detail-section" id="evSection<?= $ind['id'] ?>">
    <div class="detail-section-hdr">
      หลักฐานทั่วไป (<?= count($freeEv) ?>)
      <button class="btn btn-primary btn-sm" onclick="openEvModal(<?= $ind['id'] ?>)">+ เพิ่ม</button>
    </div>
    <div class="ev-list" id="evList<?= $ind['id'] ?>" data-ind="<?= $ind['id'] ?>">
      <?php if (empty($freeEv)): ?>
      <div class="ev-empty">ยังไม่มีหลักฐาน</div>
      <?php else: foreach ($freeEv as $ev) { $renderEv($ev, true); } endif; ?>
    </div>
  </div>

  <script type="application/json" class="team-data"><?= json_encode([
      'ind'            => (int)$ind['id'],
      'proposeOnly'    => !$isSchoolAdmin,
      'schoolUsers'    => array_map(fn($u) => ['id'=>(int)$u['id'],'name'=>$u['full_name'],'nick'=>$u['nickname']??'','pic'=>user_avatar_url($u)], $schoolUsers),
      'assistants'     => array_map(fn($a) => ['id'=>(int)$a['user_id'],'name'=>$a['full_name'],'pic'=>user_avatar_url($a)], $approvedAssistants),
  ], JSON_UNESCAPED_UNICODE) ?></script>
</div>
