<?php

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Asset URL with a cache-busting ?v=<mtime> so deploys always load fresh CSS/JS */
function asset(string $relPath): string
{
    $rel  = '/' . ltrim($relPath, '/');
    $full = APP_ROOT . $rel;
    $ver  = is_file($full) ? filemtime($full) : date('Ymd');
    return APP_URL . $rel . '?v=' . $ver;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return isset($_SESSION['csrf'], $token) && hash_equals($_SESSION['csrf'], $token);
}

function json_ok(mixed $data = null): never
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function is_ajax(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function redirect(string $path): never
{
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

function require_auth(): array
{
    if (empty($_SESSION['user'])) {
        is_ajax() ? json_err('Unauthorized', 401) : redirect('auth.php');
    }
    return $_SESSION['user'];
}

function require_role(string ...$roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        is_ajax() ? json_err('Forbidden', 403) : redirect('app.php');
    }
    return $user;
}

function gen_password(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 8; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pw . '#' . random_int(10, 99);
}

function gen_slug(string $name): string
{
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', trim($name));
    $slug = strtolower(trim($slug, '-')) ?: 'school';
    return substr($slug, 0, 80) . '-' . bin2hex(random_bytes(3));
}

function thai_date(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    $m  = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
                'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . (date('Y', $ts) + 543);
}

function thai_datetime(?string $dt): string
{
    if (!$dt || $dt === '—') return '—';
    return thai_date($dt) . ' ' . date('H:i', strtotime($dt));
}

/** Build nested indicator tree for a school + fiscal year */
function indicator_tree(int $schoolId, string $yearCode): array
{
    $stmt = db()->prepare('
        SELECT sec.id AS sec_id, sec.code AS sec_code, sec.title AS sec_title, sec.sort_order AS ss,
               sub.id AS sub_id, sub.code AS sub_code, sub.title AS sub_title, sub.sort_order AS us,
               ind.id AS ind_id, ind.code AS ind_code, ind.title AS ind_title,
               ind.criteria, ind.sort_order AS is_,
               COALESCE(sis.status,"pending") AS status,
               sis.assigned_user_id, au.full_name AS assignee_name
        FROM indicators ind
        JOIN indicator_subsections sub ON sub.id = ind.subsection_id
        JOIN indicator_sections sec    ON sec.id = sub.section_id
        JOIN fiscal_years fy           ON fy.id  = sec.fiscal_year_id
        LEFT JOIN school_indicator_status sis
               ON sis.indicator_id = ind.id AND sis.school_id = :sid
        LEFT JOIN users au ON au.id = sis.assigned_user_id
        WHERE fy.year_code = :yr
        ORDER BY sec.sort_order, sub.sort_order, ind.sort_order
    ');
    $stmt->execute([':sid' => $schoolId, ':yr' => $yearCode]);
    $rows = $stmt->fetchAll();

    // Evidence counts per indicator
    $ec = [];
    $stmt2 = db()->prepare('
        SELECT e.indicator_id, COUNT(*) cnt
        FROM evidences e
        JOIN indicators ind ON ind.id = e.indicator_id
        JOIN indicator_subsections sub ON sub.id = ind.subsection_id
        JOIN indicator_sections sec ON sec.id = sub.section_id
        JOIN fiscal_years fy ON fy.id = sec.fiscal_year_id
        WHERE e.school_id = :sid AND fy.year_code = :yr
        GROUP BY e.indicator_id
    ');
    $stmt2->execute([':sid' => $schoolId, ':yr' => $yearCode]);
    foreach ($stmt2->fetchAll() as $r) {
        $ec[$r['indicator_id']] = (int)$r['cnt'];
    }

    $tree = [];
    foreach ($rows as $r) {
        $si = $r['sec_id'];
        $ui = $r['sub_id'];
        $tree[$si] ??= ['id' => $si, 'code' => $r['sec_code'], 'title' => $r['sec_title'], 'subs' => []];
        $tree[$si]['subs'][$ui] ??= ['id' => $ui, 'code' => $r['sub_code'], 'title' => $r['sub_title'], 'inds' => []];
        $tree[$si]['subs'][$ui]['inds'][] = [
            'id'       => $r['ind_id'],
            'code'     => $r['ind_code'],
            'title'    => $r['ind_title'],
            'criteria' => $r['criteria'],
            'status'   => $r['status'],
            'ev_count' => $ec[$r['ind_id']] ?? 0,
            'assigned_user_id' => $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null,
            'assignee_name'    => $r['assignee_name'] ?? null,
        ];
    }
    foreach ($tree as &$s) {
        $s['subs'] = array_values($s['subs']);
    }
    return array_values($tree);
}

/** Dashboard stats for a school + year */
function dashboard_stats(int $schoolId, string $yearCode): array
{
    $stmt = db()->prepare('
        SELECT
            COUNT(ind.id) AS total,
            SUM(COALESCE(sis.status,"pending") = "done") AS done_n,
            SUM(COALESCE(sis.status,"pending") = "inprogress") AS prog_n
        FROM indicators ind
        JOIN indicator_subsections sub ON sub.id = ind.subsection_id
        JOIN indicator_sections sec    ON sec.id = sub.section_id
        JOIN fiscal_years fy           ON fy.id  = sec.fiscal_year_id
        LEFT JOIN school_indicator_status sis
               ON sis.indicator_id = ind.id AND sis.school_id = :sid
        WHERE fy.year_code = :yr
    ');
    $stmt->execute([':sid' => $schoolId, ':yr' => $yearCode]);
    $r = $stmt->fetch();

    $stmt2 = db()->prepare('
        SELECT COUNT(*) cnt
        FROM evidences e
        JOIN indicators ind ON ind.id = e.indicator_id
        JOIN indicator_subsections sub ON sub.id = ind.subsection_id
        JOIN indicator_sections sec ON sec.id = sub.section_id
        JOIN fiscal_years fy ON fy.id = sec.fiscal_year_id
        WHERE e.school_id = :sid AND fy.year_code = :yr
    ');
    $stmt2->execute([':sid' => $schoolId, ':yr' => $yearCode]);
    $evCnt = (int)$stmt2->fetchColumn();

    $total = (int)($r['total'] ?? 0);
    $done  = (int)($r['done_n'] ?? 0);
    $prog  = (int)($r['prog_n'] ?? 0);
    $pct   = $total > 0 ? round($done / $total * 100) : 0;

    return [
        'total'   => $total,
        'done'    => $done,
        'prog'    => $prog,
        'pending' => $total - $done - $prog,
        'ev_cnt'  => $evCnt,
        'pct'     => $pct,
    ];
}

/** Inline SVG icon for a status (12px, inherits currentColor) */
function status_icon(string $status): string
{
    $paths = match($status) {
        'done'       => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'inprogress' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
        default      => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
    };
    return '<svg class="chip-ic" width="13" height="13" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . $paths . '</svg>';
}

/** Status chip HTML */
function status_chip(string $status): string
{
    [$label, $cls] = match($status) {
        'done'       => ['เผยแพร่แล้ว', 'chip-done'],
        'inprogress' => ['กำลังดำเนินการ', 'chip-prog'],
        default      => ['ยังไม่ดำเนินการ', 'chip-pend'],
    };
    return '<span class="chip ' . $cls . '">' . status_icon($status) . $label . '</span>';
}

function active_year(): array
{
    $stmt = db()->query('SELECT * FROM fiscal_years WHERE is_active = 1 LIMIT 1');
    $y = $stmt->fetch();
    if (!$y) {
        $y = ['id' => 0, 'year_code' => '2568', 'label' => 'ปีงบประมาณ พ.ศ. 2568', 'is_active' => 1];
    }
    return $y;
}

function all_years(): array
{
    return db()->query('SELECT * FROM fiscal_years ORDER BY year_code DESC')->fetchAll();
}

function school_emblem_url(array $school): string
{
    if (!empty($school['emblem_path'])) {
        return APP_URL . '/uploads/emblems/' . rawurlencode($school['emblem_path']);
    }
    return APP_URL . '/assets/ovec-logo.jpg';
}
