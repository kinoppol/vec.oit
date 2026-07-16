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
        SELECT ind.*, COALESCE(sis.status,"pending") AS status, sis.note AS status_note
        FROM indicators ind
        LEFT JOIN school_indicator_status sis ON sis.indicator_id = ind.id AND sis.school_id = :sid
        WHERE ind.id = :id
    ');
    $stmt->execute([':id' => $id, ':sid' => $schoolId]);
    $ind = $stmt->fetch();
    if (!$ind) json_err('Not found', 404);

    $evStmt = db()->prepare('SELECT * FROM evidences WHERE indicator_id = ? AND school_id = ? ORDER BY created_at DESC');
    $evStmt->execute([$id, $schoolId]);
    $evidences = $evStmt->fetchAll();

    ob_start();
    include __DIR__ . '/includes/detail_panel.php';
    $html = ob_get_clean();
    json_ok(['html' => $html]);
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
    'add_evidence'     => addEvidence(),
    'delete_evidence'  => deleteEvidence(),
    'upload_emblem'    => uploadEmblem(),
    'add_user'         => addUser(),
    'reset_password'   => resetPassword(),
    'toggle_user'      => toggleUser(),
    'add_fiscal_year'  => addFiscalYear(),
    'set_active_year'  => setActiveYear(),
    'add_indicator'    => addIndicator(),
    'edit_indicator'   => editIndicator(),
    'approve_school'   => approveSchool(),
    'set_school_status'=> setSchoolStatus(),
    default            => json_err('Unknown action', 400),
};

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

function addEvidence(): never {
    global $schoolId, $userId, $role;
    if (!in_array($role, ['user','schooladmin'])) json_err('Forbidden', 403);

    $indId    = (int)($_POST['indicator_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $url      = trim($_POST['url'] ?? '');
    $note     = trim($_POST['note'] ?? '');
    $linkType = $_POST['link_type'] ?? 'url';
    if (!$indId || !$name) json_err('กรุณากรอกชื่อหลักฐาน');

    $filePath = null;
    if ($linkType === 'file' && !empty($_FILES['upload']['tmp_name'])) {
        $file = $_FILES['upload'];
        if ($file['size'] > MAX_UPLOAD) json_err('ไฟล์ใหญ่เกิน 10 MB');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) json_err('ประเภทไฟล์ไม่อนุญาต');
        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = UPLOAD_DIR . '/' . $newName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) json_err('อัปโหลดไม่สำเร็จ');
        $filePath = $newName;
        $url = null;
    }

    db()->prepare('
        INSERT INTO evidences (school_id, indicator_id, created_by, type, title, url, file_path, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$schoolId, $indId, $userId, $linkType === 'file' ? 'file' : 'link', $name, $url ?: null, $filePath, $note ?: null]);

    json_ok(['id' => db()->lastInsertId()]);
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
    if ((int)$ev['created_by'] !== $userId && $role !== 'schooladmin') json_err('Forbidden', 403);

    if ($ev['file_path'] && file_exists(UPLOAD_DIR . '/' . $ev['file_path'])) {
        unlink(UPLOAD_DIR . '/' . $ev['file_path']);
    }
    db()->prepare('DELETE FROM evidences WHERE id = ?')->execute([$evId]);
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
    $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND school_id = ?');
    $chk->execute([$uid, $schoolId]);
    if (!$chk->fetch() && $role !== 'centraladmin') json_err('Forbidden', 403);

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
