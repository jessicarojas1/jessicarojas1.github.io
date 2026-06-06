# CLAUDE.md — AEGIS GRC Project Rules

## Permanent Workflow Rules

### 1. Security & Compliance Analysis (Every Feature)
After completing any significant feature, fix, or module addition, spawn a security audit agent covering:
- **CSP compliance**: no inline event handlers (`onclick=`, `onchange=`, `onsubmit=`, `oninput=`); all `<script>` tags have `nonce="<?= Security::nonce() ?>"`
- **XSS**: all user output wrapped in `Security::h()`; `json_encode` in script contexts uses `JSON_HEX_TAG | JSON_HEX_AMP`
- **CSRF**: every POST form has `Security::csrfField()`; every POST controller method calls `Security::validateCsrf()`
- **SQL injection**: parameterized queries only — no string concatenation of user input into SQL
- **Open redirects**: validate `HTTP_REFERER` and any redirect targets against a strict path regex before use
- **Sensitive data**: no hardcoded credentials; secrets from env vars or Secrets Manager
- **File uploads**: MIME type validation, extension allowlist, randomized stored filenames
- **Auth coverage**: every protected route calls `Auth::requireAuth()` or `Auth::requirePermission()`

### 2. UI Consistency Check (Every Feature)
After completing any UI work, spawn a UI audit agent covering:
- **No inline event handlers**: use `data-click`, `data-submit`, `data-show-modal`, `data-close-modal`, `data-confirm-click` attributes handled by app.js
- **Filter buttons**: all list pages use `<button class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#ID"><i class="bi bi-funnel-fill"></i> Filters`
- **Modal patterns**: open via `data-show-modal`, close via `data-close-modal` + `<i class="bi bi-x-lg"></i>` — no `addEventListener` wiring
- **Empty states**: use `class="empty-state-sm"` inside `<td class="empty-row">` — no inline padding styles
- **Page headers**: every full view has `<div class="page-header"><h1 class="page-title">`
- **Breadcrumbs**: every view sets `$breadcrumbs = [...]`
- **Dark mode**: no hardcoded hex colors in inline styles — use CSS custom properties (`var(--danger)`, `var(--success)`, `var(--warning)`, `var(--card-bg)`, etc.)
- **Submit buttons**: no hardcoded `disabled` attribute on submit buttons

### 3. Database Schema File (Every Project)
Every project that uses a database **must** maintain a `database/schema.sql` file that:
- Is a **complete, idempotent** SQL script covering all tables, indexes, and seed data
- Can be run against a fresh database to produce a fully functional schema
- Uses `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`, and `INSERT ... ON CONFLICT DO NOTHING` — never plain `CREATE` without `IF NOT EXISTS`
- Is organized with section comments grouping tables by module
- Includes a header comment explaining it is a manual-setup reference and pointing to the authoritative installer (e.g. `install.php`)
- Is updated whenever a new migration is added — the schema file must always reflect the current state of all migrations combined

### 4. These Checks Are Part of the Roadmap
Every project roadmap must include:
- [ ] Security & compliance audit (CSP, XSS, CSRF, SQLi, auth, uploads, redirects)
- [ ] UI consistency check (handlers, modals, filters, empty states, dark mode, breadcrumbs)
- [ ] `database/schema.sql` updated to reflect all current migrations
- [ ] Fix all findings before marking the milestone complete

## User & Permission Management UI Standard

When asked to build any user management, roles, or permissions UI, always deliver this level of depth:

- **Two-pane IAM layout**: scrollable user list (left, ~300px) + permission editor (right, fills remaining space)
- **User list**: live search filtering by name, user cards with avatar initial, name, department, role badge, click-to-select with active state
- **Permission editor**: per-module accordions (first 3 open by default), each module shows colored icon + label + `N/total` granted count badge + Grant All / Clear All batch buttons
- **Granular module × action permissions**: not just read/write/edit — specific actions per module (e.g. `risk.accept`, `risk.review`, `kri.record`, `vendor.contracts`, `bcp.exercise`, `policy.publish`, `audit.close`)
- **Visual distinction**: green dot = role default, orange dot = explicit grant, gray = denied — never just a binary checkbox grid
- **AJAX save**: POST via `fetch()`, return `{"ok":true,"csrf":"<rotated-token>"}` on success so client rotates CSRF in-memory; show toast notifications (success/error)
- **Dirty tracking**: "Unsaved changes" indicator on save buttons; disable save during in-flight request
- **Expand All / Collapse All** toolbar controls + live total permissions count
- **Role defaults vs explicit grants**: always separate — role defaults are inherited from the role, explicit grants are stored in DB and override
- **Backward-compat aliases**: old coarse strings (e.g. `module.write`) must map to arrays of granular strings via an `$aliases` property so existing code keeps working
- **Controller coverage**: every controller method must call `Auth::requirePermission('module.action')` with the specific granular string
- **View coverage**: every `Auth::can()` call in views must use the specific granular string

## Settings & Branding Standard (Every App / Project)

Every app or project — new or existing, moving forward — **must** include a
**Settings** area that contains a **Branding** section where the user can:

- **Upload/enter a logo via URL** (a `logoUrl` text field — paste an image URL)
- Set an **organization / product name** (text)
- Set a **primary accent color** (color picker, applied via a CSS custom property)

Requirements:
- Branding applies app-wide: the logo renders in the header/navbar, the org name
  shows in the header and document `<title>`, and the accent color overrides the
  app's primary CSS custom property.
- Persist branding wherever the app stores settings: server-side settings when a
  backend exists (shared), and `localStorage` for static/front-end-only hosting
  (per-browser). Backend value wins when both are present.
- Provide sensible empty-state defaults (the app's built-in logo/name/accent) so
  an unset branding never breaks the UI.
- A bad/broken logo URL must degrade gracefully (fall back to the default mark).
- Follow all existing rules: no inline event handlers, escape user-supplied
  strings on output, and never hardcode colors that should be the accent var.

## Other Permanent Rules

- **No inline event handlers** — CSP compliance required at all times
- **Always push to production (main branch)** after completing work
- **Always spawn multiple agents in parallel** for independent subtasks
- **Every section with file upload** must include a field reference key below it
- **`Database::update()`** automatically appends `updated_at = NOW()` — never include `updated_at` in data arrays passed to it
- **Never commit `.env`** — only `.env.example` with placeholder values
- **`database/schema.sql` must be kept current** — update it whenever a migration is added; it must always represent the full, combined schema across all migrations
