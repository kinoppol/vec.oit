# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**ระบบเปิดเผยข้อมูลสาธารณะ (OIT)** — an ITA (Integrity and Transparency Assessment) indicator management platform for Thai vocational colleges under OVEC (สำนักงานคณะกรรมการการอาชีวศึกษา). Schools fill in evidence for 33 OIT indicators per fiscal year; a public read-only view displays results.

## Setup

```bash
# 1. Import the database (XAMPP)
mysql -u root < database/schema.sql

# 2. Serve from XAMPP — place repo under C:\xampp\htdocs\vec.oit\
# App auto-detects APP_URL from DOCUMENT_ROOT. No .env needed for local dev.

# 3. Visit http://localhost/vec.oit/
```

**DB credentials** resolve in this order (`config/database.php`): environment vars (`DB_HOST/DB_NAME/DB_USER/DB_PASS`) → `config/db.config.php` (a gitignored `return [...]` file **written by install.php**) → defaults (`root`, no password). On a real host that isn't `root`, the install wizard persists the entered credentials here; without it the app falls back to `root` and fails to connect.

**HTTPS behind a reverse proxy:** `APP_URL` is derived in `config/app.php` and detects HTTPS via `HTTPS`, `X-Forwarded-Proto`, `X-Forwarded-SSL`, or port 443. If this detection is wrong, `APP_URL` becomes `http://…` on an `https` page and every AJAX `fetch()` to `api.php` is blocked as mixed content (silent failures).

**Alternative setup paths** (preferred over raw `schema.sql` for a fresh or partial DB):
- **Install wizard** — visit `http://localhost/vec.oit/install.php` for a one-time guided setup; it writes `.installed` (gitignored) as a completion flag.
- **Migration runner** — `migrate.php` runs every `database/migrations/*.sql` in filename order, skipping any already recorded in the `schema_migrations` table. Idempotent: "already exists / duplicate key" errors are treated as skips, so it is safe against a partially-imported DB.
  ```bash
  C:/xampp/php/php.exe migrate.php          # run pending migrations
  C:/xampp/php/php.exe migrate.php status   # show applied/pending without running
  # or via web: http://localhost/vec.oit/migrate.php
  ```
  Migrations `009`–`011` seed base data, indicators, and demo status/evidence. Add new schema changes as the next numbered `NNN_*.sql` file rather than editing `schema.sql`.

**Syntax check a PHP file:**
```bash
C:/xampp/php/php.exe -l path/to/file.php
```

## Seed accounts (password: `password`)

| national_id     | role          |
|-----------------|---------------|
| `0000000000001` | centraladmin  |
| `1100700123456` | schooladmin   |
| `1100700234567` | user          |

## Architecture

**PHP MPA + targeted AJAX.** No framework — plain PHP 8+, PDO, sessions.

```
Entry points          Routing
─────────────         ───────────────────────────────────────────
index.php         →   redirects logged-in → app.php, else → auth.php
auth.php          →   ?mode=login|register|changepw
app.php           →   ?view=dashboard|evidence|tracking|users|school   (schooladmin/user)
                      ?view=criteria|schools|positions|migration       (centraladmin)
public.php        →   ?slug=<school-slug>&year=<year_code>  (no auth; empty slug → all-schools overview)
api.php           →   ?action=<name>  (JSON; POST mutations need CSRF)
```

**Bootstrap chain** (required in every entry point):
```php
require_once __DIR__ . '/config/app.php';      // session, constants (APP_URL, APP_ROOT, UPLOAD_DIR)
require_once __DIR__ . '/config/database.php'; // db() PDO singleton
require_once __DIR__ . '/includes/functions.php'; // all helpers
```

**View rendering:** `app.php` includes `views/{view}.php` directly — views are PHP partials with full access to `$user`, `$schoolId`, `$yearCode`, `$role` from the shell.

**AJAX pattern:** `api.php` handles all mutations. The `indicator_detail` action (GET) renders `includes/detail_panel.php` via `ob_start()` and returns the HTML as JSON `data.html`. JS inserts it into `#indDetail`.

## Database schema (8 tables)

```
fiscal_years
  └── indicator_sections  (fiscal_year_id)
        └── indicator_subsections  (section_id)
              └── indicators  (subsection_id)
                    └── school_indicator_status  (school_id, indicator_id)
                    └── evidences  (school_id, indicator_id)
schools
  └── users  (school_id)
```

Key column names to remember (these have caused past mismatches):
- `users.full_name` (not `name`)
- `users.status` ENUM: `'active'`, `'disabled'`, `'pending'`
- `schools.status` ENUM: `'active'`, `'inactive'`, `'pending'`
- `evidences.title` (not `name`), `evidences.type` (NOT NULL, default `'link'`)
- `school_indicator_status.note` — exists (text field for optional status annotation)
- `users.avatar` — filename under `uploads/avatars/` (downloaded from RMS `people_pic`); NULL → UI falls back to initials via `user_avatar_html()`
- `users.from_rms` TINYINT — 1 if imported from an external RMS. `reset_password` returns `{rms:true}` (no local reset) for these, directing the admin to change the password at the RMS instead

**Central positions** (migration 022): `positions.school_id` is nullable — `school_id IS NULL` marks a **ตำแหน่งกลาง** usable by every school and editable only by a centraladmin (view `positions.php`, actions `*_central_position` + `promote_position`). Promoting a school position absorbs every same-named school position into one central row (repointing `user_positions` and assignments). All position pickers/queries select `school_id = ? OR school_id IS NULL`; `ensure_position()` prefers an existing central row so schools share names. Central-name uniqueness is enforced in app code (the `UNIQUE(school_id,name)` key ignores NULLs).

**Team workflow tables** (migration 021): `indicator_assistants` (per school+indicator helpers; `status` `proposed`→schooladmin approves→`approved`), `document_tasks` (หัวข้อเอกสาร; description ≥10 chars), `document_task_assignees` (many assignees per task). `evidences.task_id` links a file to a document task (NULL = indicator-level); `evidences.accepted`/`accepted_by`/`accepted_at` gate publishing — an assistant's file uploads as `accepted=0`, the responsible/schooladmin's own file auto-accepts, and **public.php shows only `accepted=1`**. Access to an indicator = `user_can_access_indicator()` (responsible via `user_owns_indicator()`, approved assistant via `is_indicator_assistant()`, or task assignee); `indicator_tree()`/`dashboard_stats()` include all three when filtered by `$assigneeUserId`.

**Positions & multi-assignment** (migrations 016–019): `positions` is the per-school (or central) master list; `user_positions` is the many-to-many junction (a user can hold several positions). `users.position` is a denormalized `", "`-joined **cache** of a user's positions — keep it in sync via `refresh_position_cache($schoolId)` after any rename/delete, and it is what search/autocomplete read. `ensure_position()` inserts-if-missing and returns the id (so typing a new title in the picker adds it). An indicator's responsible party is **either** a user (`school_indicator_status.assigned_user_id`) **or** a position (`assigned_position_id`) — mutually exclusive; assigning a position means every current holder is responsible and it auto-follows membership changes. The assignee picker (`initAssignee()` in `app.js`, data embedded as a `<script type="application/json">` in `detail_panel.php`) is an autocomplete over both users and positions.

**RMS user import** (migration 014): schools pull users from an external RMS. Origin is stored per school in `schools.rms_base_url` (admin-editable in the `users` view); the path is the hardcoded `RMS_API_PATH` constant. Import is **two-phase** to survive gateway/FPM timeouts when bcrypt-hashing many users: `phase=fetch` downloads + filters (`people_exit==0` only) + caches to a temp file returning a token; `phase=batch` hashes/upserts ~20 rows per request while JS loops. `rms_fetch()` sets `CURLOPT_NOSIGNAL` (curl timeouts otherwise don't fire under php-fpm) and falls back to `file_get_contents` (needs `allow_url_fopen`). Mapping: `people_id`→`national_id`, `people_name`+`people_surname`→`full_name`, `ath_pass`→hashed password, plus `people_email`/nickname/`people_pic`(→avatar). The upsert **never overwrites existing schooladmin/centraladmin rows** (`… = IF(role="user", VALUES(…), …)`) and does not touch `created_at` or `position`, so admins keep their password and manual positions survive re-imports.

## Roles and access

| Role            | Views accessible                                  |
|-----------------|---------------------------------------------------|
| `user`          | dashboard, evidence                               |
| `schooladmin`   | dashboard, evidence, tracking, users, school      |
| `centraladmin`  | criteria, schools, positions, migration           |

`school` = school info / emblem / slug / RMS import settings. `tracking` = assignment summary + progress, grouped by responsible person/position. `app.php` validates `?view` against a per-role `$allowedViews` allowlist (falls back to the first allowed view).

`require_role('schooladmin', 'centraladmin')` in view partials enforces this. `require_auth()` is called at the top of `app.php` and `api.php`.

**api.php role guards — never use `require_role()` inside api functions.** `require_role()` calls `redirect()` on failure, sending an HTML response instead of JSON. Since api.php functions are called via `fetch()` + FormData (which does not set `Accept: application/json`, making `is_ajax()` unreliable), always use a direct guard with the globals set by the api.php preamble:

```php
function someAdminAction(): never {
    global $role;
    if ($role !== 'centraladmin') json_err('Forbidden', 403);
    // ...
}
// Multi-role: if (!in_array($role, ['user','schooladmin'])) json_err('Forbidden', 403);
```

## Key helpers (`includes/functions.php`)

- `indicator_tree(int $schoolId, string $yearCode): array` — nested sections→subsections→indicators with status and evidence count per indicator for one school+year
- `dashboard_stats(int $schoolId, string $yearCode): array` — returns `total/done/prog/pending/ev_cnt/pct`
- `status_chip(string $status): string` — returns HTML `<span class="chip …">`
- `e($v)` — `htmlspecialchars` wrapper; use on every untrusted output
- `verify_csrf()` / `csrf_field()` — CSRF protection; all POST mutations in `api.php` check this
- `gen_slug()` / `gen_password()` — registration helpers
- `asset(string $rel): string` — cache-busted URL for CSS/JS (see CSS theming)
- `user_avatar_html($u, $cls)` / `user_avatar_url($u)` — avatar `<img>` or initials fallback
- `ensure_position()` / `refresh_position_cache()` — see Positions & multi-assignment

**CSRF token in api.php** is read from `$_POST['csrf_token']` (JS `apiPost()` prepends it to FormData) or the `X-CSRF-Token` request header via `$_SERVER['HTTP_X_CSRF_TOKEN']`. Do not use `apache_request_headers()` — it is unreliable under XAMPP's CGI mode.

**`auth.php` JOIN**: the changepw query uses explicit column aliases to avoid id collision when joining users and schools:
```sql
SELECT u.id AS uid, u.full_name, u.national_id, u.role, u.school_id,
       s.id AS sid, s.name AS school_name ...
FROM users u LEFT JOIN schools s ON s.id = u.school_id WHERE u.id=?
```
Never use `SELECT u.*, s.*` on a users+schools join — `s.id` overwrites `u.id` in the result array.

## CSS theming

CSS variables are defined on `:root` (light) and overridden by `[data-theme="dark"]`. The `[data-theme="system"]` variant delegates to `@media (prefers-color-scheme: dark)`. The `data-theme` attribute lives on `<html>` and is set server-side from `$_SESSION['user']['theme']`. Core palette: `--primary: #7A1E28` (maroon), `--bg: #F4F1ED` (warm beige).

**Cache-busting:** link CSS/JS via the `asset('/assets/…')` helper, which appends `?v=<filemtime>` so deploys always serve fresh files. Adding a raw `<link>`/`<script>` without it means browsers keep stale assets after a change.

**Never `display:flex` a `<td>`.** It drops the cell out of the table's column model (overflowing/misaligned rows). Put the flex on an inner wrapper, or use `text-align`/`white-space` on the cell.

**Auth page CSS is scoped to `.auth-body`.** The `<body>` on `auth.php` has class `auth-body`. Button overrides and form layout rules for auth live under `.auth-body` selectors in `app.css` — do not redefine `.btn-primary` globally or it will bleed into the app shell.

**Evidence view full-height layout.** The evidence view uses a two-pane flex layout (tree left, detail panel right) that must fill the viewport without a double scrollbar. `app.php` adds `main-content--full` class when `$view === 'evidence'`, which sets `overflow: hidden; padding: 0` so the inner `evidence-layout` flex container can control scrolling itself.

## File upload

Uploaded evidence files go to `uploads/` (flat, random hex filenames). School emblems go to `uploads/emblems/`. The `uploads/.htaccess` blocks PHP execution. `MAX_UPLOAD` = 10 MB (defined in `config/app.php`).

## Design reference

`project/OIT.dc.html` is the original Claude Design prototype (DCLogic reactive framework). The PHP implementation uses server-side rendering instead. When matching visual design, read the CSS variables and inline styles from that file — they are the source of truth for spacing, colors, and typography.
