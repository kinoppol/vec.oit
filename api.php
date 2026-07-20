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
               sis.assigned_user_id, au.full_name AS assignee_name
        FROM indicators ind
        LEFT JOIN school_indicator_status sis ON sis.indicator_id = ind.id AND sis.school_id = :sid
        LEFT JOIN users au ON au.id = sis.assigned_user_id
        WHERE ind.id = :id
    ');
    $stmt->execute([':id' => $id, ':sid' => $schoolId]);
    $ind = $stmt->fetch();
    if (!$ind) json_err('Not found', 404);

    $evStmt = db()->prepare('SELECT * FROM evidences WHERE indicator_id = ? AND school_id = ? ORDER BY sort_order ASC, id ASC');
    $evStmt->execute([$id, $schoolId]);
    $evidences = $evStmt->fetchAll();

    // Assignment: only a schooladmin may (re)assign; load same-school users for the picker
    $panelRole = $authUser['role'];
    $canAssign = ($panelRole === 'schooladmin');
    $schoolUsers = [];
    if ($canAssign) {
        $uStmt = db()->prepare('SELECT id, full_name, role FROM users WHERE school_id = ? AND status = "active" ORDER BY role DESC, full_name');
        $uStmt->execute([$schoolId]);
        $schoolUsers = $uStmt->fetchAll();
    }

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
        ORDER BY sec.sort_order, sub.sort_order, ind.sort_order
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
    'reorder_evidence' => reorderEvidence(),
    'upload_emblem'    => uploadEmblem(),
    'add_user'         => addUser(),
    'update_rms_url'   => updateRmsUrl(),
    'rms_ping'         => rmsPing(),
    'import_rms_users' => importRmsUsers(),
    'reset_password'   => resetPassword(),
    'toggle_user'      => toggleUser(),
    'add_fiscal_year'  => addFiscalYear(),
    'set_active_year'  => setActiveYear(),
    'add_indicator'    => addIndicator(),
    'edit_indicator'   => editIndicator(),
    'import_indicators'=> importIndicators(),
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

function assignIndicator(): never {
    global $schoolId, $role;
    if ($role !== 'schooladmin') json_err('Forbidden', 403);

    $indId  = (int)($_POST['indicator_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0); // 0 = unassign
    if (!$indId) json_err('Missing indicator');

    $assignee = null;
    if ($userId > 0) {
        // The assignee must belong to this school
        $chk = db()->prepare('SELECT id FROM users WHERE id = ? AND school_id = ?');
        $chk->execute([$userId, $schoolId]);
        if (!$chk->fetch()) json_err('ผู้ใช้ไม่อยู่ในสถานศึกษานี้', 400);
        $assignee = $userId;
    }

    // Upsert the status row (keeps existing status; only sets the assignee)
    db()->prepare('
        INSERT INTO school_indicator_status (school_id, indicator_id, assigned_user_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE assigned_user_id = VALUES(assigned_user_id), updated_at = NOW()
    ')->execute([$schoolId, $indId, $assignee]);

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
    $name     = trim($_POST['name'] ?? '');
    $url      = trim($_POST['url'] ?? '');
    $note     = trim($_POST['note'] ?? '');
    $linkType = $_POST['link_type'] ?? 'url';
    if (!$indId) json_err('ข้อมูลไม่ครบ');

    $sort = ev_next_sort($schoolId, $indId);
    $ins  = db()->prepare('
        INSERT INTO evidences (school_id, indicator_id, created_by, type, title, url, file_path, note, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $ins->execute([$schoolId, $indId, $userId, $type, $title, null, $newName, $note ?: null, $sort++]);
            $created++;
        }
        if ($created === 0) json_err('ไม่พบไฟล์ที่อัปโหลด');
    } else {
        if ($name === '') json_err('กรุณากรอกชื่อหลักฐาน');
        $ins->execute([$schoolId, $indId, $userId, 'link', $name, $url ?: null, null, $note ?: null, $sort]);
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
    if ((int)$ev['created_by'] !== $userId && $role !== 'schooladmin') json_err('Forbidden', 403);

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
                INSERT INTO users (school_id, national_id, password_hash, full_name, nickname, email, role, status, must_change_pw)
                VALUES (?, ?, ?, ?, ?, ?, "user", "active", 0)
                ON DUPLICATE KEY UPDATE
                  full_name     = IF(role = "user", VALUES(full_name), full_name),
                  nickname      = IF(role = "user", VALUES(nickname), nickname),
                  email         = IF(role = "user", VALUES(email), email),
                  password_hash = IF(role = "user", VALUES(password_hash), password_hash),
                  school_id     = IF(role = "user", VALUES(school_id), school_id)
            ');
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1054) json_err('ฐานข้อมูลยังไม่มีคอลัมน์ users.email — กรุณารัน migrate.php ก่อน', 500);
            throw $e;
        }

        $new = 0; $upd = 0;
        foreach ($slice as $it) {
            $pass = (string)($it['pass'] ?? '');
            $hash = password_hash($pass !== '' ? $pass : gen_password(), PASSWORD_DEFAULT);
            $ins->execute([$schoolId, $it['nid'], $hash, $it['name'], ($it['nick'] ?? '') ?: null, $it['email'] ?: null]);
            $ins->rowCount() === 1 ? $new++ : $upd++;
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
        $items[] = [
            'nid'   => $uid,
            'name'  => $fname,
            'nick'  => $nick,
            'email' => trim((string)($p['people_email'] ?? '')),
            'pass'  => (string)($p['ath_pass'] ?? ''),
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
