<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

// Parse action from POST body or GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// indicator_detail — requires auth; school must match session
if ($action === 'indicator_detail') {
    $authUser = require_auth();
    $id       = (int)($_GET['id'] ?? 0);
    $schoolId = (int)($_GET['school'] ?? 0);
    $yearCode = trim($_GET['year'] ?? '');
    if (!$id || !$schoolId) json_err('Missing params');
    // Non-centraladmin can only view their own school
    if ($authUser['role'] !== 'centraladmin' && (int)$authUser['school_id'] !== $schoolId) {
        json_err('Forbidden', 403);
    }

    $stmt = db()->prepare('
        SELECT ind.*, COALESCE(sis.status,"pending") AS status, sis.note AS status_note,
               sis.assigned_user_id, au.full_name AS assignee_name, au.avatar AS assignee_avatar,
               sis.assigned_position_id, ap.name AS assignee_pos_name
        FROM indicators ind
        LEFT JOIN school_indicator_status sis ON sis.indicator_id = ind.id AND sis.school_id = :sid
        LEFT JOIN users au ON au.id = sis.assigned_user_id
        LEFT JOIN positions ap ON ap.id = sis.assigned_position_id
        WHERE ind.id = :id
    ');
    $stmt->execute([':id' => $id, ':sid' => $schoolId]);
    $ind = $stmt->fetch();
    if (!$ind) json_err('Not found', 404);

    // A data-entry user may only open indicators assigned to them
    if ($authUser['role'] === 'user' && !user_owns_indicator((int)$authUser['id'], $schoolId, $id)) {
        json_err('คุณไม่ได้รับมอบหมายตัวชี้วัดนี้', 403);
    }

    // Evidence with attacher name/avatar (attacher shown per file; accepted gate on publish)
    $evStmt = db()->prepare('
        SELECT e.*, cu.full_name AS creator_name, cu.avatar AS creator_avatar
        FROM evidences e LEFT JOIN users cu ON cu.id = e.created_by
        WHERE e.indicator_id = ? AND e.school_id = ? ORDER BY e.sort_order ASC, e.id ASC
    ');
    $evStmt->execute([$id, $schoolId]);
    $evidences = $evStmt->fetchAll();

    // Viewer capabilities on this indicator
    $panelRole     = $authUser['role'];
    $viewerId      = (int)$authUser['id'];
    $isSchoolAdmin = ($panelRole === 'schooladmin');
    $isResponsible = user_owns_indicator($viewerId, $schoolId, $id);
    $isAssistant   = is_indicator_assistant($viewerId, $schoolId, $id);
    $canManage     = $isResponsible || $isSchoolAdmin; // manage assistants/tasks, accept files
    $canAssign     = $isSchoolAdmin;                    // reassign the responsible party

    // Assistants on this indicator (a user OR a position)
    $aStmt = db()->prepare('
        SELECT ia.id, ia.user_id, ia.position_id, ia.status, ia.proposed_by,
               u.full_name, u.nickname, u.avatar,
               p.name AS pos_name,
               (SELECT COUNT(*) FROM user_positions up JOIN users hu ON hu.id = up.user_id
                WHERE up.position_id = ia.position_id AND hu.school_id = ia.school_id) AS pos_n
        FROM indicator_assistants ia
        LEFT JOIN users u ON u.id = ia.user_id
        LEFT JOIN positions p ON p.id = ia.position_id
        WHERE ia.school_id = ? AND ia.indicator_id = ?
        ORDER BY (ia.status = "approved") DESC, ia.position_id IS NOT NULL DESC, COALESCE(p.name, u.full_name)
    ');
    $aStmt->execute([$schoolId, $id]);
    $assistants = $aStmt->fetchAll();
    // Normalize a display "name" + type for each row
    foreach ($assistants as &$a) {
        $a['is_position'] = !empty($a['position_id']);
        $a['name'] = $a['is_position'] ? $a['pos_name'] : $a['full_name'];
    }
    unset($a);
    // Individual approved-assistant users (direct + members of approved position-assistants) for doc tasks
    $auStmt = db()->prepare('
        SELECT DISTINCT u.id, u.full_name, u.nickname, u.avatar
        FROM indicator_assistants ia
        JOIN user_positions up ON up.position_id = ia.position_id
        JOIN users u ON u.id = up.user_id AND u.school_id = ia.school_id AND u.status = "active"
        WHERE ia.school_id = ? AND ia.indicator_id = ? AND ia.status = "approved" AND ia.position_id IS NOT NULL
        UNION
        SELECT u.id, u.full_name, u.nickname, u.avatar
        FROM indicator_assistants ia JOIN users u ON u.id = ia.user_id
        WHERE ia.school_id = ? AND ia.indicator_id = ? AND ia.status = "approved" AND ia.user_id IS NOT NULL
        ORDER BY full_name
    ');
    $auStmt->execute([$schoolId, $id, $schoolId, $id]);
    $approvedAssistants = $auStmt->fetchAll();

    // Document tasks + their assignees
    $tStmt = db()->prepare('SELECT * FROM document_tasks WHERE school_id = ? AND indicator_id = ? ORDER BY id');
    $tStmt->execute([$schoolId, $id]);
    $docTasks = $tStmt->fetchAll();
    $taskAssignees = [];
    if ($docTasks) {
        $tids = array_map(fn($t) => (int)$t['id'], $docTasks);
        $ph   = implode(',', array_fill(0, count($tids), '?'));
        $taStmt = db()->prepare("
            SELECT dta.task_id, u.id, u.full_name, u.avatar
            FROM document_task_assignees dta JOIN users u ON u.id = dta.user_id
            WHERE dta.task_id IN ($ph) ORDER BY u.full_name
        ");
        $taStmt->execute($tids);
        foreach ($taStmt->fetchAll() as $r) { $taskAssignees[(int)$r['task_id']][] = $r; }
    }

    // Same-school users/positions for the pickers
    $schoolUsers = [];
    $schoolPositions = [];
    if ($canManage) {
        $uStmt = db()->prepare('SELECT id, full_name, nickname, position, role, avatar FROM users WHERE school_id = ? AND status = "active" ORDER BY role DESC, full_name');
        $uStmt->execute([$schoolId]);
        $schoolUsers = $uStmt->fetchAll();
    }
    if ($canManage) {
        // Own + central positions; holder count restricted to this school's users
        $pStmt = db()->prepare('
            SELECT p.id, p.name, (p.school_id IS NULL) AS central,
                   COUNT(hu.id) AS n
            FROM positions p
            LEFT JOIN user_positions up ON up.position_id = p.id
            LEFT JOIN users hu ON hu.id = up.user_id AND hu.school_id = ?
            WHERE p.school_id = ? OR p.school_id IS NULL
            GROUP BY p.id ORDER BY (p.school_id IS NULL) DESC, p.name
        ');
        $pStmt->execute([$schoolId, $schoolId]);
        $schoolPositions = $pStmt->fetchAll();
    }

    // Reference files attached to this criterion by a centraladmin (read-only for schools)
    $cfStmt = db()->prepare('SELECT id, title, file_path, type FROM indicator_files WHERE indicator_id = ? ORDER BY id');
    $cfStmt->execute([$id]);
    $criteriaFiles = $cfStmt->fetchAll();

    ob_start();
    include __DIR__ . '/includes/detail_panel.php';
    $html = ob_get_clean();
    json_ok(['html' => $html]);
}

// export_indicators — download the full indicator tree of a fiscal year as JSON
if ($action === 'export_indicators') {
    $authUser = require_auth();
    if ($authUser['role'] !== 'centraladmin') { http_response_code(403); exit('Forbidden'); }
    $yc = trim($_GET['fy'] ?? '');

    $fyStmt = db()->prepare('SELECT * FROM fiscal_years WHERE year_code = ?');
    $fyStmt->execute([$yc]);
    $fy = $fyStmt->fetch();
    if (!$fy) { http_response_code(404); exit('ไม่พบปีงบประมาณ'); }

    $rows = db()->prepare('
        SELECT sec.code AS sec_code, sec.title AS sec_title, sec.sort_order AS sec_so,
               sub.code AS sub_code, sub.title AS sub_title, sub.sort_order AS sub_so,
               ind.code AS ind_code, ind.title AS ind_title, ind.criteria, ind.sort_order AS ind_so
        FROM indicator_sections sec
        JOIN indicator_subsections sub ON sub.section_id = sec.id
        JOIN indicators ind ON ind.subsection_id = sub.id
        WHERE sec.fiscal_year_id = ?
        ORDER BY CAST(REGEXP_REPLACE(sec.code,"[^0-9]","") AS UNSIGNED), sec.sort_order,
                 CAST(REGEXP_REPLACE(sub.code,"[^0-9]","") AS UNSIGNED), sub.sort_order,
                 CAST(REGEXP_REPLACE(ind.code,"[^0-9]","") AS UNSIGNED), ind.sort_order
    ');
    $rows->execute([$fy['id']]);

    $tree = [];
    foreach ($rows->fetchAll() as $r) {
        $sc = $r['sec_code']; $uc = $r['sub_code'];
        $tree[$sc] ??= ['code' => $r['sec_code'], 'title' => $r['sec_title'], 'sort_order' => (int)$r['sec_so'], 'subsections' => []];
        $tree[$sc]['subsections'][$uc] ??= ['code' => $r['sub_code'], 'title' => $r['sub_title'], 'sort_order' => (int)$r['sub_so'], 'indicators' => []];
        $tree[$sc]['subsections'][$uc]['indicators'][] = [
            'code' => $r['ind_code'], 'title' => $r['ind_title'],
            'criteria' => $r['criteria'], 'sort_order' => (int)$r['ind_so'],
        ];
    }
    foreach ($tree as &$s) { $s['subsections'] = array_values($s['subsections']); } unset($s);

    $payload = [
        'meta' => [
            'app'         => 'OIT',
            'type'        => 'indicators',
            'version'     => 1,
            'year_code'   => $fy['year_code'],
            'label'       => $fy['label'],
            'exported_at' => date('c'),
        ],
        'sections' => array_values($tree),
    ];

    $fname = 'oit-indicators-' . $fy['year_code'] . '-' . date('Ymd') . '.json';
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// All other actions require auth
$user     = require_auth();
$role     = $user['role'];
$userId   = (int)$user['id'];
$schoolId = (int)($user['school_id'] ?? 0);

// CSRF check for all POST mutations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf($token)) json_err('CSRF token invalid', 403);
}

match ($action) {
    'update_status'    => updateStatus(),
    'assign_indicator' => assignIndicator(),
    'update_slug'      => updateSlug(),
    'add_evidence'     => addEvidence(),
    'edit_evidence'    => editEvidence(),
    'delete_evidence'  => deleteEvidence(),
    'accept_evidence'  => acceptEvidence(),
    'unaccept_evidence'=> unacceptEvidence(),
    'reorder_evidence' => reorderEvidence(),
    'add_assistant'    => addAssistant(),
    'approve_assistant'=> approveAssistant(),
    'remove_assistant' => removeAssistant(),
    'add_doc_task'     => addDocTask(),
    'edit_doc_task'    => editDocTask(),
    'delete_doc_task'  => deleteDocTask(),
    'upload_emblem'    => uploadEmblem(),
    'add_user'         => addUser(),
    'update_user_position' => updateUserPosition(),
    'list_positions'   => listPositions(),
    'add_position'     => addPosition(),
    'rename_position'  => renamePosition(),
    'delete_position'  => deletePosition(),
    'list_central_positions'   => listCentralPositions(),
    'add_central_position'     => addCentralPosition(),
    'rename_central_position'  => renameCentralPosition(),
    'delete_central_position'  => deleteCentralPosition(),
    'promote_position'         => promotePosition(),
    'update_rms_url'   => updateRmsUrl(),
    'rms_ping'         => rmsPing(),
    'import_rms_users' => importRmsUsers(),
    'reset_password'   => resetPassword(),
    'toggle_user'      => toggleUser(),
    'set_user_role'    => setUserRole(),
    'impersonate'      => impersonate(),
    'add_fiscal_year'  => addFiscalYear(),
    'set_active_year'  => setActiveYear(),
    'add_indicator'    => addIndicator(),
    'edit_indicator'   => editIndicator(),
    'delete_indicator' => deleteIndicator(),
    'edit_section'     => editSection(),
    'delete_section'   => deleteSection(),
    'edit_subsection'  => editSubsection(),
    'delete_subsection'=> deleteSubsection(),
    'import_indicators'=> importIndicators(),
    'add_criteria_file'   => addCriteriaFile(),
    'delete_criteria_file'=> deleteCriteriaFile(),
    'approve_school'   => approveSchool(),
    'set_school_status'=> setSchoolStatus(),
    'run_migrations'   => runMigrations(),
    default            => json_err('Unknown action', 400),
};

// ─────────────────────────────────────────────────────────────
function runMigrations(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    require_once __DIR__ . '/includes/migrations.php';
    $run = mig_run();
    if ($run['hadError']) {
        json_err(end($run['results'])['msg'] ?? 'Migration failed', 500);
    }
    json_ok([
        'results' => $run['results'],
        'ranAny'  => $run['ranAny'],
        'pending' => mig_pending_count(),
    ]);
}

// ─────────────────────────────────────────────────────────────
function updateStatus(): never {
    global $schoolId, $role;
    if (!in_array($role, ['user','schooladmin'])) json_err('Forbidden', 403);

    $indId  = (int)($_POST['indicator_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $note   = trim($_POST['note'] ?? '');
    if (!$indId || !in_array($status, ['pending','inprogress','done'])) json_err('Invalid data');

    db()->prepare('
        INSERT INTO school_indicator_status (school_id, indicator_id, status, note)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), updated_at = NOW()
    ')->execute([$schoolId, $indId, $status, $note]);

    json_ok();
}

function assignIndicator(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);

    $indId = (int)($_POST['indicator_id'] ?? 0);
    $type  = $_POST['target_type'] ?? 'none'; // user | position | none
    $tid   = (int)($_POST['target_id'] ?? 0);
    if (!$indId) json_err('Missing indicator');

    $uid = null; $pid = null;
    if ($type === 'user' && $tid > 0) {
        $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND school_id = ?');
        $chk->execute([$tid, $schoolId]);
        if (!$chk->fetch()) json_err('ผู้ใช้ไม่อยู่ในสถานศึกษานี้', 400);
        $uid = $tid;
    } elseif ($type === 'position' && $tid > 0) {
        // Own position or a central (school_id IS NULL) position
        $chk = db()->prepare('SELECT id FROM positions WHERE id = ? AND (school_id = ? OR school_id IS NULL)');
        $chk->execute([$tid, $schoolId]);
        if (!$chk->fetch()) json_err('ไม่พบตำแหน่งนี้', 400);
        $pid = $tid;
    }

    // Upsert (keeps existing status; sets exactly one of user/position, or clears both)
    db()->prepare('
        INSERT INTO school_indicator_status (school_id, indicator_id, assigned_user_id, assigned_position_id)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          assigned_user_id     = VALUES(assigned_user_id),
          assigned_position_id = VALUES(assigned_position_id),
          updated_at = NOW()
    ')->execute([$schoolId, $indId, $uid, $pid]);

    json_ok();
}

function updateSlug(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);

    $raw = trim($_POST['slug'] ?? '');
    // URL-safe: collapse whitespace to dashes, strip characters that break a URL path
    $slug = preg_replace('/\s+/u', '-', $raw);
    $slug = preg_replace('~[/\\\\?#&%<>"\'=+\s]+~u', '', $slug);
    $slug = trim($slug, '-');
    if ($slug === '' || mb_strlen($slug) > 120) json_err('slug ไม่ถูกต้อง (1–120 ตัวอักษร)');

    // Unique across other schools
    $chk = db()->prepare('SELECT id FROM schools WHERE slug = ? AND id <> ?');
    $chk->execute([$slug, $schoolId]);
    if ($chk->fetch()) json_err('slug นี้ถูกใช้แล้ว กรุณาเลือกใหม่');

    db()->prepare('UPDATE schools SET slug = ? WHERE id = ?')->execute([$slug, $schoolId]);
    $_SESSION['user']['school']['slug'] = $slug; // keep session in sync
    json_ok(['slug' => $slug]);
}

const EV_ALLOWED_EXT = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','webp'];
const EV_IMAGE_EXT   = ['jpg','jpeg','png','gif','webp'];

function ev_next_sort(int $schoolId, int $indId): int {
    $s = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM evidences WHERE school_id = ? AND indicator_id = ?');
    $s->execute([$schoolId, $indId]);
    return (int)$s->fetchColumn();
}

function addEvidence(): never {
    global $schoolId, $userId, $role;
    if (!in_array($role, ['user','schooladmin'])) json_err('Forbidden', 403);

    $indId    = (int)($_POST['indicator_id'] ?? 0);
    $taskId   = (int)($_POST['task_id'] ?? 0) ?: null;
    $name     = trim($_POST['name'] ?? '');
    $url      = trim($_POST['url'] ?? '');
    $note     = trim($_POST['note'] ?? '');
    $linkType = $_POST['link_type'] ?? 'url';
    if (!$indId) json_err('ข้อมูลไม่ครบ');

    // Must be the responsible, an approved assistant, or a task assignee
    if ($role !== 'schooladmin' && !user_can_access_indicator($userId, $schoolId, $indId)) {
        json_err('คุณไม่มีสิทธิ์แนบหลักฐานในตัวชี้วัดนี้', 403);
    }

    // Validate the task belongs to this indicator/school when given
    if ($taskId !== null) {
        $tchk = db()->prepare('SELECT id FROM document_tasks WHERE id = ? AND indicator_id = ? AND school_id = ?');
        $tchk->execute([$taskId, $indId, $schoolId]);
        if (!$tchk->fetch()) json_err('ไม่พบหัวข้อเอกสาร', 404);
    }

    // The responsible's / schooladmin's own files publish immediately; an
    // assistant's file waits for the responsible to accept it.
    $autoAccept = ($role === 'schooladmin' || user_owns_indicator($userId, $schoolId, $indId)) ? 1 : 0;
    $acceptedBy = $autoAccept ? $userId : null;
    $acceptedAt = $autoAccept ? date('Y-m-d H:i:s') : null;

    $sort = ev_next_sort($schoolId, $indId);
    $ins  = db()->prepare('
        INSERT INTO evidences (school_id, indicator_id, task_id, created_by, accepted, accepted_by, accepted_at, type, title, url, file_path, note, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $created = 0;

    if ($linkType === 'file' && !empty($_FILES['upload']['name'])) {
        // Normalize to arrays so a single or multiple selection both work
        $f     = $_FILES['upload'];
        $names = is_array($f['name'])     ? $f['name']     : [$f['name']];
        $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : [$f['tmp_name']];
        $sizes = is_array($f['size'])     ? $f['size']     : [$f['size']];
        $multi = count(array_filter($names, fn($n) => $n !== '')) > 1;

        foreach ($names as $i => $fn) {
            if ($fn === '' || empty($tmps[$i])) continue;
            if ($sizes[$i] > MAX_UPLOAD) json_err('ไฟล์ "' . $fn . '" ใหญ่เกิน 10 MB');
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (!in_array($ext, EV_ALLOWED_EXT)) json_err('ประเภทไฟล์ไม่อนุญาต: ' . $fn);
            $newName = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($tmps[$i], UPLOAD_DIR . '/' . $newName)) json_err('อัปโหลดไม่สำเร็จ');

            $base  = pathinfo($fn, PATHINFO_FILENAME);
            $title = $name !== '' ? ($multi ? $name . ' — ' . $base : $name) : $base;
            $type  = in_array($ext, EV_IMAGE_EXT) ? 'image' : 'file';
            $ins->execute([$schoolId, $indId, $taskId, $userId, $autoAccept, $acceptedBy, $acceptedAt, $type, $title, null, $newName, $note ?: null, $sort++]);
            $created++;
        }
        if ($created === 0) json_err('ไม่พบไฟล์ที่อัปโหลด');
    } else {
        if ($name === '') json_err('กรุณากรอกชื่อหลักฐาน');
        $ins->execute([$schoolId, $indId, $taskId, $userId, $autoAccept, $acceptedBy, $acceptedAt, 'link', $name, $url ?: null, null, $note ?: null, $sort]);
        $created = 1;
    }

    json_ok(['created' => $created]);
}

function reorderEvidence(): never {
    global $schoolId, $role;
    if (!in_array($role, ['user','schooladmin'])) json_err('Forbidden', 403);

    $indId = (int)($_POST['indicator_id'] ?? 0);
    $idArr = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
    if (!$indId || !$idArr) json_err('ข้อมูลไม่ครบ');

    // Verify every id belongs to this school + indicator
    $ph  = implode(',', array_fill(0, count($idArr), '?'));
    $chk = db()->prepare("SELECT id FROM evidences WHERE indicator_id = ? AND school_id = ? AND id IN ($ph)");
    $chk->execute(array_merge([$indId, $schoolId], $idArr));
    $valid = array_map('intval', array_column($chk->fetchAll(), 'id'));

    $upd = db()->prepare('UPDATE evidences SET sort_order = ? WHERE id = ? AND school_id = ?');
    $so  = 1;
    foreach ($idArr as $id) {
        if (in_array($id, $valid, true)) $upd->execute([$so++, $id, $schoolId]);
    }
    json_ok();
}

function editEvidence(): never {
    global $schoolId, $userId, $role;
    if (!in_array($role, ['user','schooladmin'])) json_err('Forbidden', 403);

    $evId     = (int)($_POST['evidence_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $url      = trim($_POST['url'] ?? '');
    $note     = trim($_POST['note'] ?? '');
    $linkType = $_POST['link_type'] ?? 'url';
    if (!$evId || !$name) json_err('กรุณากรอกชื่อหลักฐาน');

    // Ownership check (same rule as delete)
    $stmt = db()->prepare('SELECT * FROM evidences WHERE id = ? AND school_id = ?');
    $stmt->execute([$evId, $schoolId]);
    $ev = $stmt->fetch();
    if (!$ev) json_err('Not found', 404);
    if ((int)$ev['created_by'] !== $userId && $role !== 'schooladmin'
        && !user_owns_indicator($userId, $schoolId, (int)$ev['indicator_id'])) json_err('Forbidden', 403);

    $filePath = $ev['file_path'];
    $type     = 'link';

    if ($linkType === 'file') {
        // The file input is name="upload[]"; take the first selected file (edit = single)
        $f = $_FILES['upload'] ?? null;
        $upName = is_array($f['name'] ?? null) ? ($f['name'][0] ?? '') : ($f['name'] ?? '');
        $upTmp  = is_array($f['tmp_name'] ?? null) ? ($f['tmp_name'][0] ?? '') : ($f['tmp_name'] ?? '');
        $upSize = is_array($f['size'] ?? null) ? ($f['size'][0] ?? 0) : ($f['size'] ?? 0);
        // Replace file only if a new one is uploaded; otherwise keep the existing file
        if ($upName !== '' && $upTmp !== '') {
            if ($upSize > MAX_UPLOAD) json_err('ไฟล์ใหญ่เกิน 10 MB');
            $ext = strtolower(pathinfo($upName, PATHINFO_EXTENSION));
            if (!in_array($ext, EV_ALLOWED_EXT)) json_err('ประเภทไฟล์ไม่อนุญาต');
            $newName = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($upTmp, UPLOAD_DIR . '/' . $newName)) json_err('อัปโหลดไม่สำเร็จ');
            if ($ev['file_path'] && file_exists(UPLOAD_DIR . '/' . $ev['file_path'])) {
                unlink(UPLOAD_DIR . '/' . $ev['file_path']);
            }
            $filePath = $newName;
        }
        if (!$filePath) json_err('กรุณาเลือกไฟล์');
        $url  = null;
        // Preserve/detect image type from the stored file extension
        $curExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $type = in_array($curExt, EV_IMAGE_EXT) ? 'image' : 'file';
    } else {
        // URL mode — drop any previously attached file
        if ($ev['file_path'] && file_exists(UPLOAD_DIR . '/' . $ev['file_path'])) {
            unlink(UPLOAD_DIR . '/' . $ev['file_path']);
        }
        $filePath = null;
        $type     = 'link';
    }

    db()->prepare('UPDATE evidences SET type = ?, title = ?, url = ?, file_path = ?, note = ? WHERE id = ?')
        ->execute([$type, $name, $url ?: null, $filePath, $note ?: null, $evId]);
    json_ok();
}

function deleteEvidence(): never {
    global $schoolId, $userId, $role;
    $evId = (int)($_POST['evidence_id'] ?? 0);
    if (!$evId) json_err('Missing id');

    // Check ownership
    $stmt = db()->prepare('SELECT * FROM evidences WHERE id = ? AND school_id = ?');
    $stmt->execute([$evId, $schoolId]);
    $ev = $stmt->fetch();
    if (!$ev) json_err('Not found', 404);
    if ((int)$ev['created_by'] !== $userId && $role !== 'schooladmin'
        && !user_owns_indicator($userId, $schoolId, (int)$ev['indicator_id'])) json_err('Forbidden', 403);

    if ($ev['file_path'] && file_exists(UPLOAD_DIR . '/' . $ev['file_path'])) {
        unlink(UPLOAD_DIR . '/' . $ev['file_path']);
    }
    db()->prepare('DELETE FROM evidences WHERE id = ?')->execute([$evId]);
    json_ok();
}

/** Load an evidence in this school + confirm the caller may accept it (responsible or schooladmin). */
function ev_for_accept(int $evId): array {
    global $schoolId, $userId, $role;
    if (!$evId) json_err('Missing id');
    $stmt = db()->prepare('SELECT id, indicator_id FROM evidences WHERE id = ? AND school_id = ?');
    $stmt->execute([$evId, $schoolId]);
    $ev = $stmt->fetch();
    if (!$ev) json_err('Not found', 404);
    if ($role !== 'schooladmin' && !user_owns_indicator($userId, $schoolId, (int)$ev['indicator_id'])) {
        json_err('เฉพาะผู้รับผิดชอบหรือผู้ดูแลเท่านั้นที่ยอมรับหลักฐานได้', 403);
    }
    return $ev;
}

function acceptEvidence(): never {
    global $userId;
    $ev = ev_for_accept((int)($_POST['evidence_id'] ?? 0));
    db()->prepare('UPDATE evidences SET accepted = 1, accepted_by = ?, accepted_at = NOW() WHERE id = ?')
        ->execute([$userId, $ev['id']]);
    json_ok();
}

function unacceptEvidence(): never {
    $ev = ev_for_accept((int)($_POST['evidence_id'] ?? 0));
    db()->prepare('UPDATE evidences SET accepted = 0, accepted_by = NULL, accepted_at = NULL WHERE id = ?')
        ->execute([$ev['id']]);
    json_ok();
}

// ── Assistants ────────────────────────────────────────────────
function addAssistant(): never {
    global $schoolId, $userId, $role;
    $indId = (int)($_POST['indicator_id'] ?? 0);
    // Backward compatible: plain user_id, or target_type + target_id
    $type  = $_POST['target_type'] ?? 'user';
    $tid   = (int)($_POST['target_id'] ?? $_POST['user_id'] ?? 0);
    if (!$indId || !$tid) json_err('ข้อมูลไม่ครบ');

    // schooladmin adds an approved assistant directly; the responsible proposes one
    $isAdmin = ($role === 'schooladmin');
    $isResp  = user_owns_indicator($userId, $schoolId, $indId);
    if (!$isAdmin && !$isResp) json_err('Forbidden', 403);

    $status = $isAdmin ? 'approved' : 'proposed';

    if ($type === 'position') {
        $chk = db()->prepare('SELECT id FROM positions WHERE id = ? AND (school_id = ? OR school_id IS NULL)');
        $chk->execute([$tid, $schoolId]);
        if (!$chk->fetch()) json_err('ไม่พบตำแหน่งนี้', 404);
        db()->prepare('
            INSERT INTO indicator_assistants (school_id, indicator_id, user_id, position_id, status, proposed_by)
            VALUES (?, ?, NULL, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = IF(VALUES(status) = "approved", "approved", status)
        ')->execute([$schoolId, $indId, $tid, $status, $userId]);
    } else {
        $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND school_id = ? AND status = "active"');
        $chk->execute([$tid, $schoolId]);
        if (!$chk->fetch()) json_err('ไม่พบผู้ใช้ในสถานศึกษานี้', 404);
        db()->prepare('
            INSERT INTO indicator_assistants (school_id, indicator_id, user_id, position_id, status, proposed_by)
            VALUES (?, ?, ?, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE status = IF(VALUES(status) = "approved", "approved", status)
        ')->execute([$schoolId, $indId, $tid, $status, $userId]);
    }
    json_ok(['status' => $status]);
}

function approveAssistant(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    $upd = db()->prepare('UPDATE indicator_assistants SET status = "approved" WHERE id = ? AND school_id = ?');
    $upd->execute([$id, $schoolId]);
    json_ok();
}

function removeAssistant(): never {
    global $schoolId, $userId, $role;
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    $stmt = db()->prepare('SELECT id, indicator_id, proposed_by FROM indicator_assistants WHERE id = ? AND school_id = ?');
    $stmt->execute([$id, $schoolId]);
    $row = $stmt->fetch();
    if (!$row) json_err('Not found', 404);
    // schooladmin, the responsible, or the person who proposed may remove
    $allowed = ($role === 'schooladmin')
        || user_owns_indicator($userId, $schoolId, (int)$row['indicator_id'])
        || (int)$row['proposed_by'] === $userId;
    if (!$allowed) json_err('Forbidden', 403);
    db()->prepare('DELETE FROM indicator_assistants WHERE id = ?')->execute([$id]);
    json_ok();
}

// ── Document tasks (หัวข้อเอกสาร) ──────────────────────────────
/** Guard + parse for add/edit doc task. Returns [indId, title, desc, assignees[]]. */
function doc_task_input(int $indId): array {
    global $schoolId, $userId, $role;
    if ($role !== 'schooladmin' && !user_owns_indicator($userId, $schoolId, $indId)) json_err('Forbidden', 403);
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    if ($title === '') json_err('กรุณากรอกชื่อหัวข้อเอกสาร');
    if (mb_strlen($desc) < 10) json_err('คำอธิบายต้องมีอย่างน้อย 10 ตัวอักษร');

    $assignees = json_decode($_POST['assignees'] ?? '[]', true);
    $assignees = is_array($assignees) ? array_values(array_unique(array_map('intval', $assignees))) : [];
    // Assignees must be approved assistants of this indicator
    if ($assignees) {
        $ph  = implode(',', array_fill(0, count($assignees), '?'));
        $chk = db()->prepare("SELECT user_id FROM indicator_assistants
            WHERE school_id = ? AND indicator_id = ? AND status = 'approved' AND user_id IN ($ph)");
        $chk->execute(array_merge([$schoolId, $indId], $assignees));
        $valid = array_map('intval', array_column($chk->fetchAll(), 'user_id'));
        $assignees = array_values(array_intersect($assignees, $valid));
    }
    return [$title, $desc, $assignees];
}

function addDocTask(): never {
    global $schoolId, $userId;
    $indId = (int)($_POST['indicator_id'] ?? 0);
    if (!$indId) json_err('ข้อมูลไม่ครบ');
    [$title, $desc, $assignees] = doc_task_input($indId);

    db()->prepare('INSERT INTO document_tasks (school_id, indicator_id, title, description, created_by) VALUES (?,?,?,?,?)')
        ->execute([$schoolId, $indId, $title, $desc, $userId]);
    $taskId = (int)db()->lastInsertId();
    doc_task_set_assignees($taskId, $assignees);
    json_ok(['id' => $taskId]);
}

function editDocTask(): never {
    global $schoolId;
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    $stmt = db()->prepare('SELECT indicator_id FROM document_tasks WHERE id = ? AND school_id = ?');
    $stmt->execute([$id, $schoolId]);
    $row = $stmt->fetch();
    if (!$row) json_err('Not found', 404);
    [$title, $desc, $assignees] = doc_task_input((int)$row['indicator_id']);

    db()->prepare('UPDATE document_tasks SET title = ?, description = ? WHERE id = ?')
        ->execute([$title, $desc, $id]);
    doc_task_set_assignees($id, $assignees);
    json_ok();
}

function doc_task_set_assignees(int $taskId, array $userIds): void {
    db()->prepare('DELETE FROM document_task_assignees WHERE task_id = ?')->execute([$taskId]);
    if (!$userIds) return;
    $ins = db()->prepare('INSERT INTO document_task_assignees (task_id, user_id) VALUES (?, ?)');
    foreach ($userIds as $uid) $ins->execute([$taskId, $uid]);
}

function deleteDocTask(): never {
    global $schoolId, $userId, $role;
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    $stmt = db()->prepare('SELECT indicator_id FROM document_tasks WHERE id = ? AND school_id = ?');
    $stmt->execute([$id, $schoolId]);
    $row = $stmt->fetch();
    if (!$row) json_err('Not found', 404);
    if ($role !== 'schooladmin' && !user_owns_indicator($userId, $schoolId, (int)$row['indicator_id'])) json_err('Forbidden', 403);
    // Detach any evidence linked to this task (keep the files at indicator level)
    db()->prepare('UPDATE evidences SET task_id = NULL WHERE task_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM document_tasks WHERE id = ?')->execute([$id]);
    json_ok();
}

function uploadEmblem(): never {
    global $schoolId, $role;
    if (!in_array($role, ['schooladmin'])) json_err('Forbidden', 403);
    $sid = (int)($_POST['school_id'] ?? $schoolId);
    if ($sid !== $schoolId) json_err('Forbidden', 403);

    if (empty($_FILES['emblem']['tmp_name'])) json_err('ไม่พบไฟล์');
    $file = $_FILES['emblem'];
    if ($file['size'] > 2 * 1024 * 1024) json_err('ไฟล์ใหญ่เกิน 2 MB');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','svg'])) json_err('ประเภทไฟล์ไม่อนุญาต');
    $newName = 'emblem_' . $schoolId . '_' . time() . '.' . $ext;
    $dir = UPLOAD_DIR . '/emblems';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newName)) json_err('อัปโหลดไม่สำเร็จ');
    db()->prepare('UPDATE schools SET emblem_path = ? WHERE id = ?')->execute([$newName, $schoolId]);
    json_ok(['url' => APP_URL . '/uploads/emblems/' . $newName]);
}

function addUser(): never {
    global $schoolId, $role;
    if (!in_array($role, ['schooladmin','centraladmin'])) json_err('Forbidden', 403);

    $name      = trim($_POST['name'] ?? '');
    $nid       = trim($_POST['national_id'] ?? '');
    $userRole  = $_POST['role'] ?? 'user';
    $sid       = (int)($_POST['school_id'] ?? $schoolId);
    if (!$name || !$nid) json_err('กรุณากรอกข้อมูลให้ครบ');
    if (!preg_match('/^\d{13}$/', $nid)) json_err('เลขประจำตัวประชาชนต้องมี 13 หลัก');
    if (!in_array($userRole, ['user','schooladmin'])) $userRole = 'user';

    // Check duplicate
    $chk = db()->prepare('SELECT id FROM users WHERE national_id = ?');
    $chk->execute([$nid]);
    if ($chk->fetch()) json_err('มีผู้ใช้งานเลขประจำตัวนี้แล้ว');

    $pw = gen_password();
    db()->prepare('
        INSERT INTO users (school_id, national_id, password_hash, full_name, role, status, must_change_pw)
        VALUES (?, ?, ?, ?, ?, "active", 1)
    ')->execute([$sid, $nid, password_hash($pw, PASSWORD_BCRYPT), $name, $userRole]);

    json_ok(['password' => $pw]);
}

function resetPassword(): never {
    global $schoolId, $role;
    if (!in_array($role, ['schooladmin','centraladmin'])) json_err('Forbidden', 403);
    $uid = (int)($_POST['user_id'] ?? 0);

    // Verify user belongs to school
    $chk = db()->prepare('SELECT id, from_rms FROM users WHERE id = ? AND school_id = ?');
    $chk->execute([$uid, $schoolId]);
    $target = $chk->fetch();
    if (!$target && $role !== 'centraladmin') json_err('Forbidden', 403);
    if (!$target && $role === 'centraladmin') {
        $chk = db()->prepare('SELECT id, from_rms FROM users WHERE id = ?');
        $chk->execute([$uid]);
        $target = $chk->fetch();
    }
    if (!$target) json_err('ไม่พบผู้ใช้', 404);

    // RMS-imported accounts authenticate against the RMS; a local reset would be
    // overwritten on the next import. Tell the admin to change it at the RMS.
    if (!empty($target['from_rms'])) {
        json_ok(['rms' => true]);
    }

    $pw = gen_password();
    db()->prepare('UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE id = ?')
        ->execute([password_hash($pw, PASSWORD_BCRYPT), $uid]);

    json_ok(['password' => $pw]);
}

function toggleUser(): never {
    global $schoolId, $role;
    if (!in_array($role, ['schooladmin','centraladmin'])) json_err('Forbidden', 403);
    $uid    = (int)($_POST['user_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active','disabled']) ? $_POST['status'] : null;
    if (!$uid || !$status) json_err('Invalid');

    $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND school_id = ?');
    $chk->execute([$uid, $schoolId]);
    if (!$chk->fetch() && $role !== 'centraladmin') json_err('Forbidden', 403);

    db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $uid]);
    json_ok();
}

/**
 * Promote/demote a user between 'user' and 'schooladmin' within the same school.
 * A schooladmin may appoint co-administrators or step someone back down to a
 * data-entry user. centraladmin may do this for any school.
 */
function setUserRole(): never {
    global $schoolId, $userId, $role;
    if (!in_array($role, ['schooladmin','centraladmin'])) json_err('Forbidden', 403);

    $uid     = (int)($_POST['user_id'] ?? 0);
    $newRole = $_POST['role'] ?? '';
    if (!$uid || !in_array($newRole, ['user','schooladmin'], true)) json_err('ข้อมูลไม่ถูกต้อง');
    if ($uid === $userId) json_err('ไม่สามารถเปลี่ยนบทบาทของตนเองได้');

    // Load the target; must belong to the acting school (unless centraladmin)
    $chk = db()->prepare('SELECT id, school_id, role FROM users WHERE id = ?');
    $chk->execute([$uid]);
    $target = $chk->fetch();
    if (!$target) json_err('ไม่พบผู้ใช้', 404);
    if ($role !== 'centraladmin' && (int)$target['school_id'] !== $schoolId) json_err('Forbidden', 403);

    // Never touch a central admin account through this endpoint
    if ($target['role'] === 'centraladmin') json_err('ไม่สามารถเปลี่ยนบทบาทผู้ดูแลส่วนกลางได้', 403);

    if ($target['role'] === $newRole) json_ok(); // no-op

    // Guard: don't remove the last active schooladmin of a school
    if ($target['role'] === 'schooladmin' && $newRole === 'user') {
        $cnt = db()->prepare("
            SELECT COUNT(*) FROM users
            WHERE school_id = ? AND role = 'schooladmin' AND status = 'active' AND id <> ?
        ");
        $cnt->execute([(int)$target['school_id'], $uid]);
        if ((int)$cnt->fetchColumn() === 0) json_err('ต้องมีผู้ดูแลสถานศึกษาอย่างน้อย 1 คน');
    }

    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $uid]);
    json_ok();
}

/**
 * Impersonate a data-entry user of the same school. The acting schooladmin's
 * session is stashed in $_SESSION['impersonator'] and restored on logout.php.
 */
function impersonate(): never {
    global $schoolId, $userId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    if (!empty($_SESSION['impersonator'])) json_err('กำลังสวมสิทธิ์ผู้ใช้อื่นอยู่แล้ว', 409);

    $uid = (int)($_POST['user_id'] ?? 0);
    if (!$uid || $uid === $userId) json_err('ข้อมูลไม่ถูกต้อง');

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $t = $stmt->fetch();
    if (!$t) json_err('ไม่พบผู้ใช้', 404);
    if ((int)$t['school_id'] !== $schoolId) json_err('Forbidden', 403);
    if ($t['role'] !== 'user')        json_err('สวมสิทธิ์ได้เฉพาะบัญชีผู้กรอกข้อมูล', 403);
    if ($t['status'] !== 'active')    json_err('บัญชีนี้ไม่ได้เปิดใช้งาน', 403);

    // Stash the admin session, then become the target user (keep year/theme)
    $prev = $_SESSION['user'];
    $_SESSION['impersonator'] = $prev;

    $school = null;
    if ($t['school_id']) {
        $s = db()->prepare('SELECT * FROM schools WHERE id = ?');
        $s->execute([$t['school_id']]);
        $school = $s->fetch() ?: null;
    }
    $_SESSION['user'] = [
        'id'          => $t['id'],
        'name'        => $t['full_name'],
        'national_id' => $t['national_id'],
        'role'        => $t['role'],
        'school_id'   => $t['school_id'],
        'school'      => $school,
        'year_code'   => $prev['year_code'],
        'year_label'  => $prev['year_label'],
        'theme'       => $prev['theme'] ?? 'system',
    ];
    json_ok(['redirect' => APP_URL . '/app.php']);
}

/** Ensure a position name exists in the school's master list; return its id */
function ensure_position(int $schoolId, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    // Prefer an existing central position with this name so schools share it
    $c = db()->prepare('SELECT id FROM positions WHERE school_id IS NULL AND name = ?');
    $c->execute([$name]);
    if ($cid = $c->fetchColumn()) return (int)$cid;

    db()->prepare('INSERT IGNORE INTO positions (school_id, name) VALUES (?, ?)')->execute([$schoolId, $name]);
    $s = db()->prepare('SELECT id FROM positions WHERE school_id = ? AND name = ?');
    $s->execute([$schoolId, $name]);
    $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}

/** Recompute the ", "-joined users.position cache from the junction table */
function refresh_position_cache(int $schoolId): void {
    db()->prepare('
        UPDATE users u SET u.position = (
            SELECT GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ", ")
            FROM user_positions up JOIN positions p ON p.id = up.position_id
            WHERE up.user_id = u.id
        ) WHERE u.school_id = ?
    ')->execute([$schoolId]);
}

function updateUserPosition(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    $uid = (int)($_POST['user_id'] ?? 0);
    if (!$uid) json_err('Missing user');

    // Must belong to this school
    $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND school_id = ?');
    $chk->execute([$uid, $schoolId]);
    if (!$chk->fetch()) json_err('ไม่พบผู้ใช้ในสถานศึกษานี้', 404);

    // positions: JSON array or comma-separated list (supports multiple)
    $raw = $_POST['positions'] ?? '';
    $dec = json_decode($raw, true);
    $names = is_array($dec) ? $dec : explode(',', $raw);
    $names = array_values(array_unique(array_filter(
        array_map(fn($n) => trim((string)$n), $names),
        fn($n) => $n !== '' && mb_strlen($n) <= 150
    )));

    $ids = [];
    foreach ($names as $n) { if ($id = ensure_position($schoolId, $n)) $ids[] = $id; }

    db()->prepare('DELETE FROM user_positions WHERE user_id = ?')->execute([$uid]);
    $insUp = db()->prepare('INSERT IGNORE INTO user_positions (user_id, position_id) VALUES (?, ?)');
    foreach ($ids as $pid) $insUp->execute([$uid, $pid]);

    db()->prepare('UPDATE users SET position = ? WHERE id = ?')->execute([implode(', ', $names) ?: null, $uid]);
    json_ok(['positions' => $names, 'joined' => implode(', ', $names)]);
}

function listPositions(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    // Own positions (editable) + central positions (read-only, shared by all schools)
    $st = db()->prepare('
        SELECT id, name, (school_id IS NULL) AS central
        FROM positions WHERE school_id = ? OR school_id IS NULL
        ORDER BY (school_id IS NULL) DESC, name
    ');
    $st->execute([$schoolId]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'central' => (bool)$r['central']], $st->fetchAll());
    json_ok($rows);
}

function addPosition(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 150) json_err('ชื่อตำแหน่งไม่ถูกต้อง');
    ensure_position($schoolId, $name);
    json_ok();
}

function renamePosition(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id || $name === '' || mb_strlen($name) > 150) json_err('ข้อมูลไม่ถูกต้อง');

    $st = db()->prepare('SELECT name FROM positions WHERE id = ? AND school_id = ?');
    $st->execute([$id, $schoolId]);
    $old = $st->fetchColumn();
    if ($old === false) json_err('ไม่พบตำแหน่ง', 404);

    // Unique check
    $dup = db()->prepare('SELECT id FROM positions WHERE school_id = ? AND name = ? AND id <> ?');
    $dup->execute([$schoolId, $name, $id]);
    if ($dup->fetch()) json_err('มีชื่อตำแหน่งนี้อยู่แล้ว');

    db()->prepare('UPDATE positions SET name = ? WHERE id = ? AND school_id = ?')->execute([$name, $id, $schoolId]);
    refresh_position_cache($schoolId); // junction unchanged, but cached names need refresh
    json_ok();
}

function deletePosition(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    // Only own positions; a central position can only be removed by a centraladmin
    db()->prepare('DELETE FROM positions WHERE id = ? AND school_id = ?')->execute([$id, $schoolId]); // FK cascades junction
    refresh_position_cache($schoolId);
    json_ok();
}

/** Recompute the users.position cache for every school (after a central-position change). */
function refresh_position_cache_all(): void {
    db()->exec('
        UPDATE users u SET u.position = (
            SELECT GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ", ")
            FROM user_positions up JOIN positions p ON p.id = up.position_id
            WHERE up.user_id = u.id
        )
    ');
}

// ── Central positions (centraladmin) ──────────────────────────
function listCentralPositions(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    // Central positions with total holder count
    $c = db()->query('
        SELECT p.id, p.name, COUNT(up.user_id) AS n
        FROM positions p LEFT JOIN user_positions up ON up.position_id = p.id
        WHERE p.school_id IS NULL GROUP BY p.id ORDER BY p.name
    ')->fetchAll();
    // School positions (candidates to promote), grouped with their school name
    $s = db()->query('
        SELECT p.id, p.name, p.school_id, s.name AS school_name, COUNT(up.user_id) AS n
        FROM positions p
        JOIN schools s ON s.id = p.school_id
        LEFT JOIN user_positions up ON up.position_id = p.id
        WHERE p.school_id IS NOT NULL
        GROUP BY p.id ORDER BY p.name, s.name
    ')->fetchAll();
    json_ok(['central' => $c, 'school' => $s]);
}

function addCentralPosition(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 150) json_err('ชื่อตำแหน่งไม่ถูกต้อง');
    $dup = db()->prepare('SELECT id FROM positions WHERE school_id IS NULL AND name = ?');
    $dup->execute([$name]);
    if ($dup->fetch()) json_err('มีตำแหน่งกลางชื่อนี้อยู่แล้ว');
    db()->prepare('INSERT INTO positions (school_id, name) VALUES (NULL, ?)')->execute([$name]);
    json_ok(['id' => (int)db()->lastInsertId()]);
}

function renameCentralPosition(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id || $name === '' || mb_strlen($name) > 150) json_err('ข้อมูลไม่ถูกต้อง');
    $chk = db()->prepare('SELECT id FROM positions WHERE id = ? AND school_id IS NULL');
    $chk->execute([$id]);
    if (!$chk->fetch()) json_err('ไม่พบตำแหน่งกลาง', 404);
    $dup = db()->prepare('SELECT id FROM positions WHERE school_id IS NULL AND name = ? AND id <> ?');
    $dup->execute([$name, $id]);
    if ($dup->fetch()) json_err('มีตำแหน่งกลางชื่อนี้อยู่แล้ว');
    db()->prepare('UPDATE positions SET name = ? WHERE id = ?')->execute([$name, $id]);
    refresh_position_cache_all();
    json_ok();
}

function deleteCentralPosition(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    db()->prepare('DELETE FROM positions WHERE id = ? AND school_id IS NULL')->execute([$id]); // FK cascades junction
    refresh_position_cache_all();
    json_ok();
}

/**
 * Promote a school position to central. Absorbs every same-named school position
 * across all schools into one central row, repointing their holders and
 * indicator assignments so nothing is lost.
 */
function promotePosition(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');

    $row = db()->prepare('SELECT id, name, school_id FROM positions WHERE id = ?');
    $row->execute([$id]);
    $pos = $row->fetch();
    if (!$pos) json_err('ไม่พบตำแหน่ง', 404);
    if ($pos['school_id'] === null) json_ok(); // already central
    $name = $pos['name'];

    // Find or establish the central row for this name
    $c = db()->prepare('SELECT id FROM positions WHERE school_id IS NULL AND name = ?');
    $c->execute([$name]);
    $centralId = $c->fetchColumn();
    if (!$centralId) {
        db()->prepare('UPDATE positions SET school_id = NULL WHERE id = ?')->execute([$id]);
        $centralId = $id;
    }
    $centralId = (int)$centralId;

    // Absorb any remaining school positions with the same name
    $dups = db()->prepare('SELECT id, school_id FROM positions WHERE name = ? AND school_id IS NOT NULL');
    $dups->execute([$name]);
    $schools = [];
    $movePos = db()->prepare('INSERT IGNORE INTO user_positions (user_id, position_id) SELECT user_id, ? FROM user_positions WHERE position_id = ?');
    $dropUp  = db()->prepare('DELETE FROM user_positions WHERE position_id = ?');
    $moveSis = db()->prepare('UPDATE school_indicator_status SET assigned_position_id = ? WHERE assigned_position_id = ?');
    $dropPos = db()->prepare('DELETE FROM positions WHERE id = ?');
    foreach ($dups->fetchAll() as $d) {
        $movePos->execute([$centralId, (int)$d['id']]);
        $dropUp->execute([(int)$d['id']]);
        $moveSis->execute([$centralId, (int)$d['id']]);
        $dropPos->execute([(int)$d['id']]);
        $schools[(int)$d['school_id']] = true;
    }
    refresh_position_cache_all();
    json_ok(['central_id' => $centralId]);
}

function updateRmsUrl(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);

    $url = trim($_POST['rms_base_url'] ?? '');
    if ($url !== '') {
        if (!preg_match('~^https?://~i', $url)) json_err('URL ต้องขึ้นต้นด้วย http:// หรือ https://');
        $url = rtrim($url, '/');
        if (mb_strlen($url) > 300) json_err('URL ยาวเกินไป');
    }
    try {
        db()->prepare('UPDATE schools SET rms_base_url = ? WHERE id = ?')->execute([$url ?: null, $schoolId]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1054) json_err('ฐานข้อมูลยังไม่มีคอลัมน์ rms_base_url — กรุณารัน migrate.php ก่อน', 500);
        throw $e;
    }
    $_SESSION['user']['school']['rms_base_url'] = $url ?: null;
    json_ok(['rms_base_url' => $url]);
}

/** Fetch an external URL. Returns the body, or null on failure with $err set. */
function rms_fetch(string $url, ?string &$err = null, int $timeout = 20): ?string {
    $err = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_NOSIGNAL       => true,   // required so timeouts fire under php-fpm
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'OIT-RMS-Import/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) $err = 'เชื่อมต่อไม่สำเร็จ: ' . curl_error($ch);
        elseif ($code >= 400) $err = 'แหล่งข้อมูลตอบกลับสถานะ HTTP ' . $code;
        curl_close($ch);
        return $err === null ? $res : null;
    }
    if (!ini_get('allow_url_fopen')) {
        $err = 'เซิร์ฟเวอร์ไม่ได้เปิด cURL และ allow_url_fopen — ไม่สามารถเรียก URL ภายนอกได้ '
             . '(กรุณาเปิดส่วนขยาย php_curl หรือ allow_url_fopen ในการตั้งค่า PHP)';
        return null;
    }
    $ctx = stream_context_create([
        'http'  => ['timeout' => $timeout, 'ignore_errors' => true],
        'https' => ['timeout' => $timeout, 'ignore_errors' => true],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        $last = error_get_last();
        $err  = 'file_get_contents ล้มเหลว: ' . ($last['message'] ?? 'unknown');
        return null;
    }
    return $res;
}

/**
 * Download a profile picture from the RMS and store it under uploads/avatars/,
 * recording the filename on users.avatar. Silently no-ops on any failure so the
 * import keeps going and the user simply falls back to their initials.
 */
function rms_save_avatar(int $userId, string $url): void {
    $body = rms_fetch($url, $err, 8);
    if ($body === null || $body === '') return;
    if (strlen($body) > 5 * 1024 * 1024) return; // guard against oversized files

    // Validate it is actually an image and derive the extension from its type.
    $info = @getimagesizefromstring($body);
    if ($info === false) return;
    $ext = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => '',
    };
    if ($ext === '') return;

    $dir = UPLOAD_DIR . '/avatars';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $name = 'u' . $userId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $body) === false) return;

    // Remove any previous avatar file, then point the row at the new one.
    $prev = db()->prepare('SELECT avatar FROM users WHERE id = ?');
    $prev->execute([$userId]);
    $old = (string)$prev->fetchColumn();
    if ($old !== '' && is_file($dir . '/' . $old)) @unlink($dir . '/' . $old);

    db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$name, $userId]);
}

/** Diagnostic: quickly probe the RMS endpoint and report what came back */
function rmsPing(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    @set_time_limit(30);

    $base = trim((string)($_POST['rms_base_url'] ?? ''));
    if ($base === '') {
        $stmt = db()->prepare('SELECT rms_base_url FROM schools WHERE id = ?');
        $stmt->execute([$schoolId]);
        $base = trim((string)$stmt->fetchColumn());
    }
    $base = rtrim($base, '/');
    if ($base === '') json_err('ยังไม่ได้ระบุ URL แหล่งข้อมูล RMS');

    $endpoint = $base . RMS_API_PATH;
    $env = 'cURL=' . (function_exists('curl_init') ? 'มี' : 'ไม่มี')
         . ', allow_url_fopen=' . (ini_get('allow_url_fopen') ? 'เปิด' : 'ปิด');
    $t0 = microtime(true);
    $raw = rms_fetch($endpoint, $err, 8); // short probe
    $ms  = round((microtime(true) - $t0) * 1000);

    if ($raw === null) json_ok(['ok' => false, 'endpoint' => $endpoint, 'ms' => $ms, 'error' => $err, 'env' => $env]);

    $data = json_decode($raw, true);
    $count = null;
    if (is_array($data)) {
        if (isset($data[0]))            $count = count($data);
        elseif (isset($data['data']))   $count = is_array($data['data'])   ? count($data['data'])   : null;
        elseif (isset($data['people'])) $count = is_array($data['people']) ? count($data['people']) : null;
    }
    json_ok([
        'ok'       => true,
        'endpoint' => $endpoint,
        'ms'       => $ms,
        'bytes'    => strlen($raw),
        'is_json'  => $data !== null,
        'count'    => $count,
        'peek'     => mb_substr(trim(strip_tags($raw)), 0, 160),
    ]);
}

function rms_cache_file(int $schoolId, string $token): string {
    return sys_get_temp_dir() . '/oit_rms_' . $schoolId . '_' . $token . '.json';
}

/**
 * Two-phase import to stay under gateway/FPM timeouts with many users:
 *   phase=fetch  → download RMS data, filter people_exit==0, cache; return {token,total,skipped}
 *   phase=batch  → hash+upsert a small slice from the cache; return progress
 */
function importRmsUsers(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);
    @set_time_limit(120);

    $phase = $_POST['phase'] ?? 'fetch';

    if ($phase === 'batch') {
        $token  = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $file   = rms_cache_file($schoolId, $token);
        if ($token === '' || !is_file($file)) json_err('เซสชันการโอนหมดอายุ กรุณาเริ่มใหม่');

        $items = json_decode((string)file_get_contents($file), true) ?: [];
        $total = count($items);
        $slice = array_slice($items, $offset, 20); // 20 bcrypt hashes/request ≈ a few seconds

        try {
            // Protect admin accounts: never overwrite an existing schooladmin/centraladmin
            // (their password, name, email, school stay intact — only role="user" rows are updated)
            $ins = db()->prepare('
                INSERT INTO users (school_id, national_id, password_hash, full_name, nickname, email, role, status, must_change_pw, from_rms)
                VALUES (?, ?, ?, ?, ?, ?, "user", "active", 0, 1)
                ON DUPLICATE KEY UPDATE
                  full_name     = IF(role = "user", VALUES(full_name), full_name),
                  nickname      = IF(role = "user", VALUES(nickname), nickname),
                  email         = IF(role = "user", VALUES(email), email),
                  password_hash = IF(role = "user", VALUES(password_hash), password_hash),
                  school_id     = IF(role = "user", VALUES(school_id), school_id),
                  from_rms      = IF(role = "user", 1, from_rms)
            ');
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1054) json_err('ฐานข้อมูลยังไม่มีคอลัมน์ users.email/from_rms — กรุณารัน migrate.php ก่อน', 500);
            throw $e;
        }

        // Look up id + current avatar so we can attach a downloaded profile picture
        $look = db()->prepare('SELECT id, role, avatar FROM users WHERE national_id = ?');

        $new = 0; $upd = 0;
        foreach ($slice as $it) {
            $pass = (string)($it['pass'] ?? '');
            $hash = password_hash($pass !== '' ? $pass : gen_password(), PASSWORD_DEFAULT);
            $ins->execute([$schoolId, $it['nid'], $hash, $it['name'], ($it['nick'] ?? '') ?: null, $it['email'] ?: null]);
            $ins->rowCount() === 1 ? $new++ : $upd++;

            // Download a profile picture the first time we see one for this user.
            $picUrl = trim((string)($it['pic'] ?? ''));
            if ($picUrl !== '') {
                $look->execute([$it['nid']]);
                $row = $look->fetch();
                // Only for data-entry rows, and only if they don't already have one.
                if ($row && $row['role'] === 'user' && empty($row['avatar'])) {
                    rms_save_avatar((int)$row['id'], $picUrl);
                }
            }
        }
        $next = $offset + count($slice);
        $done = $next >= $total;
        if ($done) @unlink($file);
        json_ok(['new' => $new, 'updated' => $upd, 'next' => $next, 'total' => $total, 'done' => $done]);
    }

    // ── phase=fetch ──
    try {
        $stmt = db()->prepare('SELECT rms_base_url FROM schools WHERE id = ?');
        $stmt->execute([$schoolId]);
        $base = trim((string)$stmt->fetchColumn());
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1054) json_err('ฐานข้อมูลยังไม่มีคอลัมน์สำหรับ RMS — กรุณารัน migrate.php ก่อน', 500);
        throw $e;
    }
    if ($base === '') json_err('ยังไม่ได้ตั้งค่า URL แหล่งข้อมูล RMS ในเมนูตั้งค่า');

    $endpoint = rtrim($base, '/') . RMS_API_PATH;
    $raw = rms_fetch($endpoint, $fetchErr);
    if ($raw === null) json_err('เชื่อมต่อแหล่งข้อมูล RMS ไม่สำเร็จ — ' . ($fetchErr ?? ''), 502);

    $data = json_decode($raw, true);
    $people = null;
    if (is_array($data)) {
        if (isset($data[0]) && is_array($data[0]))            $people = $data;
        elseif (!empty($data['data'])   && is_array($data['data']))   $people = $data['data'];
        elseif (!empty($data['people']) && is_array($data['people'])) $people = $data['people'];
    }
    if ($people === null) {
        $peek = trim(mb_substr(strip_tags($raw), 0, 120));
        json_err('รูปแบบข้อมูลจาก RMS ไม่ถูกต้อง (ต้องเป็น JSON array ของผู้ใช้) — ได้รับ: ' . $peek);
    }

    // Filter to importable people (people_exit == 0) and keep only mapped fields
    $items = []; $skipped = 0;
    foreach ($people as $p) {
        if (!is_array($p) || (string)($p['people_exit'] ?? '1') !== '0') { $skipped++; continue; }
        $uid   = trim((string)($p['people_id'] ?? ''));
        $fname = trim(trim((string)($p['people_name'] ?? '')) . ' ' . trim((string)($p['people_surname'] ?? '')));
        if ($uid === '' || $fname === '') { $skipped++; continue; }
        // Nickname — accept a few likely RMS field names
        $nick = '';
        foreach (['people_nickname', 'people_nick', 'nickname', 'people_nickname_th'] as $k) {
            if (!empty($p[$k])) { $nick = trim((string)$p[$k]); break; }
        }
        // Profile picture: base_url + "/files/" + people_pic  →  full image URL.
        // If people_pic is already an absolute URL, use it verbatim.
        $pic = trim((string)($p['people_pic'] ?? ''));
        $picUrl = '';
        if ($pic !== '') {
            $picUrl = preg_match('#^https?://#i', $pic)
                ? $pic
                : rtrim($base, '/') . '/files/' . ltrim($pic, '/');
        }
        $items[] = [
            'nid'   => $uid,
            'name'  => $fname,
            'nick'  => $nick,
            'email' => trim((string)($p['people_email'] ?? '')),
            'pass'  => (string)($p['ath_pass'] ?? ''),
            'pic'   => $picUrl,
        ];
    }

    $token = bin2hex(random_bytes(8));
    if (@file_put_contents(rms_cache_file($schoolId, $token), json_encode($items)) === false) {
        json_err('ไม่สามารถเขียนไฟล์ชั่วคราวสำหรับการโอนได้', 500);
    }
    json_ok(['token' => $token, 'total' => count($items), 'skipped' => $skipped]);
}

function addFiscalYear(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $yc    = preg_replace('/\D/', '', $_POST['year_code'] ?? '');
    $label = trim($_POST['label'] ?? '');
    if (strlen($yc) !== 4 || !$label) json_err('ข้อมูลไม่ครบ');

    $chk = db()->prepare('SELECT id FROM fiscal_years WHERE year_code = ?');
    $chk->execute([$yc]);
    if ($chk->fetch()) json_err('มีปีงบประมาณนี้แล้ว');

    db()->prepare('INSERT INTO fiscal_years (year_code, label) VALUES (?,?)')->execute([$yc, $label]);
    json_ok(['id' => db()->lastInsertId()]);
}

function setActiveYear(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    db()->exec('UPDATE fiscal_years SET is_active = 0');
    db()->prepare('UPDATE fiscal_years SET is_active = 1 WHERE id = ?')->execute([$id]);
    json_ok();
}

function addIndicator(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $code    = trim($_POST['code'] ?? '');
    $title   = trim($_POST['title'] ?? '');
    $criteria = trim($_POST['criteria'] ?? '');
    $subId   = (int)($_POST['sub_id'] ?? 0);
    if (!$code || !$title || !$subId) json_err('ข้อมูลไม่ครบ');

    $maxSort = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM indicators WHERE subsection_id = ?');
    $maxSort->execute([$subId]);
    $sort = (int)$maxSort->fetchColumn();

    db()->prepare('INSERT INTO indicators (subsection_id, code, title, criteria, sort_order) VALUES (?,?,?,?,?)')
        ->execute([$subId, $code, $title, $criteria ?: null, $sort]);
    json_ok(['id' => db()->lastInsertId()]);
}

function editIndicator(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id      = (int)($_POST['id'] ?? 0);
    $code    = trim($_POST['code'] ?? '');
    $title   = trim($_POST['title'] ?? '');
    $criteria = trim($_POST['criteria'] ?? '');
    $subId   = (int)($_POST['sub_id'] ?? 0);
    if (!$id || !$code || !$title || !$subId) json_err('ข้อมูลไม่ครบ');

    db()->prepare('UPDATE indicators SET code=?, title=?, criteria=?, subsection_id=? WHERE id=?')
        ->execute([$code, $title, $criteria ?: null, $subId, $id]);
    json_ok();
}

function deleteIndicator(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    // FK cascades school_indicator_status, evidences, indicator_files, assistants, doc tasks
    db()->prepare('DELETE FROM indicators WHERE id = ?')->execute([$id]);
    json_ok();
}

function editSection(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id    = (int)($_POST['id'] ?? 0);
    $code  = trim($_POST['code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    if (!$id || $code === '' || $title === '') json_err('ข้อมูลไม่ครบ');
    db()->prepare('UPDATE indicator_sections SET code = ?, title = ? WHERE id = ?')->execute([$code, $title, $id]);
    json_ok();
}

function deleteSection(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    // FK cascades subsections → indicators → status/evidence/files
    db()->prepare('DELETE FROM indicator_sections WHERE id = ?')->execute([$id]);
    json_ok();
}

function editSubsection(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id    = (int)($_POST['id'] ?? 0);
    $code  = trim($_POST['code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    if (!$id || $code === '' || $title === '') json_err('ข้อมูลไม่ครบ');
    db()->prepare('UPDATE indicator_subsections SET code = ?, title = ? WHERE id = ?')->execute([$code, $title, $id]);
    json_ok();
}

function deleteSubsection(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    // FK cascades indicators → status/evidence/files
    db()->prepare('DELETE FROM indicator_subsections WHERE id = ?')->execute([$id]);
    json_ok();
}

const CRITERIA_ALLOWED_EXT = ['jpg','jpeg','png','gif','webp','pdf','doc','docx'];

/** Central admin attaches reference documents to a criterion (indicator). Supports many files. */
function addCriteriaFile(): never {
    global $role, $userId;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $indId = (int)($_POST['indicator_id'] ?? 0);
    if (!$indId) json_err('Missing indicator');

    $chk = db()->prepare('SELECT id FROM indicators WHERE id = ?');
    $chk->execute([$indId]);
    if (!$chk->fetch()) json_err('ไม่พบตัวชี้วัด', 404);

    if (empty($_FILES['files']['name'])) json_err('ไม่พบไฟล์ที่อัปโหลด');
    $f     = $_FILES['files'];
    $names = is_array($f['name'])     ? $f['name']     : [$f['name']];
    $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : [$f['tmp_name']];
    $sizes = is_array($f['size'])     ? $f['size']     : [$f['size']];

    $ins = db()->prepare('INSERT INTO indicator_files (indicator_id, title, file_path, type, uploaded_by) VALUES (?,?,?,?,?)');
    $created = 0;
    foreach ($names as $i => $fn) {
        if ($fn === '' || empty($tmps[$i])) continue;
        if ($sizes[$i] > MAX_UPLOAD) json_err('ไฟล์ "' . $fn . '" ใหญ่เกิน 10 MB');
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if (!in_array($ext, CRITERIA_ALLOWED_EXT)) json_err('ประเภทไฟล์ไม่อนุญาต: ' . $fn);
        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($tmps[$i], UPLOAD_DIR . '/' . $newName)) json_err('อัปโหลดไม่สำเร็จ');
        $type = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? 'image' : 'file';
        $ins->execute([$indId, pathinfo($fn, PATHINFO_FILENAME), $newName, $type, $userId]);
        $created++;
    }
    if ($created === 0) json_err('ไม่พบไฟล์ที่อัปโหลด');
    json_ok(['created' => $created]);
}

function deleteCriteriaFile(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    $stmt = db()->prepare('SELECT file_path FROM indicator_files WHERE id = ?');
    $stmt->execute([$id]);
    $fp = $stmt->fetchColumn();
    if ($fp === false) json_err('Not found', 404);
    if ($fp && file_exists(UPLOAD_DIR . '/' . $fp)) unlink(UPLOAD_DIR . '/' . $fp);
    db()->prepare('DELETE FROM indicator_files WHERE id = ?')->execute([$id]);
    json_ok();
}

function importIndicators(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);

    $yc = trim($_POST['year_code'] ?? '');
    if ($yc === '') json_err('ไม่ได้ระบุปีงบประมาณปลายทาง');

    // Read JSON from uploaded file or pasted text
    $raw = '';
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        if (($_FILES['file']['size'] ?? 0) > MAX_UPLOAD) json_err('ไฟล์ใหญ่เกินไป');
        $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
    } elseif (!empty($_POST['json'])) {
        $raw = (string)$_POST['json'];
    }
    if (trim($raw) === '') json_err('ไม่พบข้อมูลสำหรับนำเข้า');

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['sections']) || !is_array($data['sections'])) {
        json_err('รูปแบบไฟล์ไม่ถูกต้อง (ต้องมี sections)');
    }

    $fyStmt = db()->prepare('SELECT id FROM fiscal_years WHERE year_code = ?');
    $fyStmt->execute([$yc]);
    $fyId = $fyStmt->fetchColumn();
    if (!$fyId) json_err('ไม่พบปีงบประมาณปลายทาง');

    $cnt = ['sec_new' => 0, 'sub_new' => 0, 'ind_new' => 0, 'ind_upd' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Prepared lookups/inserts
        $findSec = $pdo->prepare('SELECT id FROM indicator_sections WHERE fiscal_year_id = ? AND code = ?');
        $insSec  = $pdo->prepare('INSERT INTO indicator_sections (fiscal_year_id, code, title, sort_order) VALUES (?,?,?,?)');
        $findSub = $pdo->prepare('SELECT id FROM indicator_subsections WHERE section_id = ? AND code = ?');
        $insSub  = $pdo->prepare('INSERT INTO indicator_subsections (section_id, code, title, sort_order) VALUES (?,?,?,?)');
        $findInd = $pdo->prepare('SELECT id FROM indicators WHERE subsection_id = ? AND code = ?');
        $insInd  = $pdo->prepare('INSERT INTO indicators (subsection_id, code, title, criteria, sort_order) VALUES (?,?,?,?,?)');
        $updInd  = $pdo->prepare('UPDATE indicators SET title = ?, criteria = ?, sort_order = ? WHERE id = ?');

        foreach ($data['sections'] as $si => $sec) {
            $secCode = trim((string)($sec['code'] ?? ''));
            $secTitle = trim((string)($sec['title'] ?? ''));
            if ($secCode === '' || $secTitle === '') continue;
            $secSort = (int)($sec['sort_order'] ?? ($si + 1));

            $findSec->execute([$fyId, $secCode]);
            $secId = $findSec->fetchColumn();
            if (!$secId) {
                $insSec->execute([$fyId, $secCode, $secTitle, $secSort]);
                $secId = $pdo->lastInsertId();
                $cnt['sec_new']++;
            }

            foreach (($sec['subsections'] ?? []) as $ui => $sub) {
                $subCode = trim((string)($sub['code'] ?? ''));
                $subTitle = trim((string)($sub['title'] ?? ''));
                if ($subCode === '' || $subTitle === '') continue;
                $subSort = (int)($sub['sort_order'] ?? ($ui + 1));

                $findSub->execute([$secId, $subCode]);
                $subId = $findSub->fetchColumn();
                if (!$subId) {
                    $insSub->execute([$secId, $subCode, $subTitle, $subSort]);
                    $subId = $pdo->lastInsertId();
                    $cnt['sub_new']++;
                }

                foreach (($sub['indicators'] ?? []) as $ii => $ind) {
                    $indCode = trim((string)($ind['code'] ?? ''));
                    $indTitle = trim((string)($ind['title'] ?? ''));
                    if ($indCode === '' || $indTitle === '') continue;
                    $indCrit = trim((string)($ind['criteria'] ?? '')) ?: null;
                    $indSort = (int)($ind['sort_order'] ?? ($ii + 1));

                    $findInd->execute([$subId, $indCode]);
                    $indId = $findInd->fetchColumn();
                    if ($indId) {
                        $updInd->execute([$indTitle, $indCrit, $indSort, $indId]);
                        $cnt['ind_upd']++;
                    } else {
                        $insInd->execute([$subId, $indCode, $indTitle, $indCrit, $indSort]);
                        $cnt['ind_new']++;
                    }
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        json_err('นำเข้าไม่สำเร็จ: ' . $ex->getMessage(), 500);
    }

    json_ok($cnt);
}

function approveSchool(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_err('Missing id');
    db()->prepare('UPDATE schools SET status = "active" WHERE id = ?')->execute([$id]);
    db()->prepare('UPDATE users SET status = "active" WHERE school_id = ? AND role = "schooladmin" AND status = "pending"')->execute([$id]);
    json_ok();
}

function setSchoolStatus(): never {
    global $role; if ($role !== 'centraladmin') json_err('Forbidden', 403);
    $id     = (int)($_POST['id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : null;
    if (!$id || !$status) json_err('Invalid');
    db()->prepare('UPDATE schools SET status = ? WHERE id = ?')->execute([$status, $id]);
    json_ok();
}
