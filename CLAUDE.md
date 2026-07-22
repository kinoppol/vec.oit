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

Override DB credentials via environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

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
app.php           →   ?view=dashboard|evidence|users|criteria|schools
public.php        →   ?slug=<school-slug>&year=<year_code>  (no auth)
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

## Roles and access

| Role            | Views accessible                         |
|-----------------|------------------------------------------|
| `user`          | dashboard, evidence                      |
| `schooladmin`   | dashboard, evidence, users               |
| `centraladmin`  | criteria, schools (no dashboard/evidence)|

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

**Auth page CSS is scoped to `.auth-body`.** The `<body>` on `auth.php` has class `auth-body`. Button overrides and form layout rules for auth live under `.auth-body` selectors in `app.css` — do not redefine `.btn-primary` globally or it will bleed into the app shell.

**Evidence view full-height layout.** The evidence view uses a two-pane flex layout (tree left, detail panel right) that must fill the viewport without a double scrollbar. `app.php` adds `main-content--full` class when `$view === 'evidence'`, which sets `overflow: hidden; padding: 0` so the inner `evidence-layout` flex container can control scrolling itself.

## File upload

Uploaded evidence files go to `uploads/` (flat, random hex filenames). School emblems go to `uploads/emblems/`. The `uploads/.htaccess` blocks PHP execution. `MAX_UPLOAD` = 10 MB (defined in `config/app.php`).

## Design reference

`project/OIT.dc.html` is the original Claude Design prototype (DCLogic reactive framework). The PHP implementation uses server-side rendering instead. When matching visual design, read the CSS variables and inline styles from that file — they are the source of truth for spacing, colors, and typography.
