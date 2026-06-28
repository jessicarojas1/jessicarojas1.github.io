# AEGIS GRC — UI & Component Guide

This guide documents the front-end of the AEGIS GRC platform: the page shell, navigation, the design-token system, the reusable components every view is built from, the CSP-compliant event model, accessibility features, responsive behavior, and dark mode.

Everything here is derived from three source files:

| Concern | File |
| --- | --- |
| Page shell (sidebar, topbar, breadcrumbs, alert bell, theme toggle) | `views/layout.php` |
| Design tokens, components, dark mode, responsive | `public/css/app.css` |
| Event model (`data-*` delegation), modal a11y, alerts, mobile sidebar | `public/js/app.js` |

There is **no front-end framework and no build step**. The stack is server-rendered PHP that emits HTML, one global stylesheet, one global vanilla-JS file, plus locally-vendored Bootstrap 5.3.3 CSS, Bootstrap Icons and Chart.js (under `public/vendor/`, SRI-pinned — no CDN). The unused Bootstrap *JS* bundle was removed; only its CSS utility classes remain in use, and the bulk of the look and feel is the custom AEGIS design system in `app.css`.

---

## 1. How a Page Is Assembled

Every full-page view follows the same buffering pattern. The view sets a few variables, opens an output buffer, emits its body HTML, captures it into `$content`, and includes the shared layout (`views/risk/index.php:1-6`, `:309-310`):

```php
<?php
$pageTitle    = 'Risk Register';
$activeModule = 'risk';
$breadcrumbs  = [['Risk Register', null]];
ob_start();
?>
<!-- ... page body HTML ... -->
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
```

`views/layout.php` is the single HTML document. It wires up `<head>`, the sidebar, the topbar, the alert fly-out, and then prints the captured `$content` inside `<main class="page-content">` (`views/layout.php:335-337`):

```php
<main class="page-content">
  <?= $content ?? '' ?>
</main>
```

The contract every view honors:

| Variable | Purpose | Source reference |
| --- | --- | --- |
| `$pageTitle` | `<title>` and breadcrumb fallback | `layout.php:7`, `:282` |
| `$activeModule` | string key matched against each nav item to set the `active` class | `layout.php:65` and throughout the nav |
| `$breadcrumbs` | array of `[label, url-or-null]` pairs rendered in the topbar | `layout.php:272-284` |
| `$content` | the buffered page body | `layout.php:336` |

### `<head>` essentials

`layout.php:6-22` establishes:

- **Branding-aware title** — `<title><?= Security::h($pageTitle ?? $__brandName) ?> — <?= Security::h($__brandName) ?></title>`, where `$__brandName = Branding::name()`.
- **Cache-busted assets** — `app.css` and `app.js` are versioned by file `mtime` (`?v=<?= $__cssVer ?>`) so each deploy serves fresh assets instead of a stale CDN/browser copy (`layout.php:10-16`).
- **CSRF token meta tag** — `<meta name="csrf-token" content="<?= Security::generateCsrfToken() ?>">` (`layout.php:19`). `app.js` reads this for AJAX POSTs.
- **No-flash dark-mode bootstrap** — a tiny nonce'd inline script applies `data-theme="dark"` *before* paint when `localStorage['aegis-theme'] === 'dark'`, preventing a light-to-dark flicker (`layout.php:20`).
- **Live accent override** — `Branding::accentStyleTag()` emits a nonce'd `<style>` that overrides `--primary` / `--primary-dark` / `--primary-light` when the org has configured a custom accent (`layout.php:21`; `src/Branding.php:163-174`).

All three `<script>` tags at the bottom of the body carry `nonce="<?= Security::nonce() ?>"` (`layout.php:340-342`), which is required by the Content-Security-Policy.

---

## 2. The Page Shell

The body is a flexbox row: a fixed-width sidebar plus a flexing main column (`app.css:74-109`).

```
┌──────────┬─────────────────────────────────────────┐
│ .sidebar │ .topbar  (breadcrumbs · theme · bell)    │
│  (260px) ├─────────────────────────────────────────┤
│  brand   │ .page-content (scrolls)                  │
│  nav     │   ← $content rendered here               │
│  user    │                                          │
└──────────┴─────────────────────────────────────────┘
```

- `.sidebar` — `260px` (`--sidebar-w`), full-height, its own vertical scroll (`app.css:89-101`).
- `.main-content` — fills remaining width, `height:100vh`, `overflow:hidden` so only the inner content scrolls (`app.css:103-109`).
- `.topbar` — `64px` (`--topbar-h`), pinned (`app.css:111-122`).
- `.page-content` — the only vertically scrolling region; `padding:28px 28px 48px` (`app.css:124-129`).

### 2.1 Sidebar

Defined in `layout.php:28-261`.

**Brand mark (links home).** The brand is wrapped in `<a href="/" class="sidebar-brand">` (`layout.php:29`) — clicking the logo always returns to the dashboard, per the project rule. If branding supplies a logo (`Branding::logo()`), it renders an `<img data-logo-fallback>`; otherwise the built-in shield icon (`.brand-icon` with `bi-shield-fill-check`) shows (`layout.php:30-38`). A broken logo URL degrades gracefully — `app.js:6-16` listens for the image `error` event, hides the `<img>`, and reveals the `.brand-logo-fallback` shield.

**Accordion navigation.** The nav is grouped into collapsible sections (`.nav-acc`): Overview, Compliance, Operations, Risk, Analytics, GRC Tools, Resources, Administration, and Account. Each section is:

```html
<div class="nav-acc">
  <button type="button" class="nav-acc-header" data-acc="risk">
    <span>Risk</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
  </button>
  <div class="nav-acc-body" id="nav-acc-risk">
    <a href="/risk" class="nav-item active"><i class="bi bi-exclamation-triangle-fill"></i><span>Risk Register</span></a>
    <!-- ... -->
  </div>
</div>
```

- The header is a real `<button>` (keyboard-focusable, no inline handler). `app.js:591-596` binds every `.nav-acc-header[data-acc]` to `toggleAccordion(key)`.
- `toggleAccordion` (`app.js:323-331`) toggles the `open` class on header + body and **persists open/closed state per section in `sessionStorage`** (`aegisNavAcc`, `app.js:308-313`).
- On load (`app.js:333-353`), transitions are disabled for a frame, each section is opened if it was previously open *or* if it contains the `.active` item, then transitions are re-enabled — so the menu never animates on first paint and the section containing the current page auto-expands.
- Collapse/expand is animated via `max-height` (`0` → `1200px`) and the chevron rotates 180° (`app.css:219-235`).

**Active item.** Each `<a class="nav-item">` adds `active` when `$activeModule` matches its module key (e.g. `$activeModule==='risk'?'active':''`). The active style is a tinted background + left accent border (`app.css:256-260`).

**Module visibility.** The sidebar reads `settings` rows like `module_hide_<key>` once (`layout.php:46-56`) and a `moduleVisible()` helper hides any disabled module. Whole sections collapse out entirely when none of their modules are visible (e.g. `layout.php:94-95`). Administration is gated by `Auth::can('admin') || Auth::role() === 'admin'` (`layout.php:185`).

**Badges.** The Approvals item shows a live pending count in a `.nav-badge` pill computed from `approval_requests` (`layout.php:224-237`).

**Footer user card.** `.sidebar-footer` shows the avatar (first initial), name, role, and a **logout form** — a real POST `<form action="/logout">` carrying a CSRF token, with a `<button type="submit" aria-label="Log out">` (`layout.php:248-260`). No inline handler; it is a genuine form submission.

### 2.2 Topbar

`layout.php:266-304`.

- **Left** — the hamburger `.sidebar-toggle` (`aria-label="Open menu"`, mobile only) and the breadcrumb area.
- **Breadcrumbs** — rendered from `$breadcrumbs`. Each `[label, url]` pair becomes a `.breadcrumb-link` if a URL is given, otherwise the terminal `.breadcrumb-current`; a chevron `.breadcrumb-sep` separates them (`layout.php:272-284`). With no breadcrumbs set, it falls back to `$pageTitle`. All labels/URLs run through `Security::h()`.
- **Right** — the theme toggle, the alert bell, and a compact user chip (`.topbar-user`, avatar + first name).

### 2.3 Theme toggle

`<button id="themeToggle" class="theme-toggle" aria-label="Toggle dark mode">` with a `#themeIcon` (`layout.php:290-292`). Behavior in `app.js:600-626`:

- Reads/writes `localStorage['aegis-theme']` (`'light'` | `'dark'`).
- `applyTheme()` sets or removes `data-theme="dark"` on `<html>`, swaps the icon between `bi-moon-fill` and `bi-sun-fill`, and updates the button `title`.
- Combined with the no-flash bootstrap in `<head>`, the choice survives reloads with no flicker.

### 2.4 Alert bell & notifications fly-out

The bell (`layout.php:293-298`) is a `role="button"` element with `tabindex="0"`, `aria-label="Notifications"`, `aria-haspopup="true"`. It shows a `.alert-badge` count (capped at 99) when unread alerts exist; the icon switches between `bi-bell` and `bi-bell-fill`.

The fly-out panel `#alertPanel` (`layout.php:306-333`) slides in from the right (`.alert-panel` → `.alert-panel.open`, `app.css:379-393`) with a dimming `#alertOverlay`. Each alert renders a severity-colored icon (`.sev-critical` / `.sev-warning` / `.sev-info`), title, relative time, and a per-item **mark-as-read** button (`.mark-read-btn[data-alert-id]`). The empty state is the cheerful "All caught up!" block.

Wiring (`app.js:79-164`), all via `addEventListener` — no inline handlers:

- `toggleAlertPanel()` toggles `open` on the panel + overlay.
- The bell responds to **click and keyboard** (Enter / Space), since `role="button"` does not get free keyboard activation (`app.js:95-100`).
- Clicking the close button, the overlay, or anywhere outside the open panel closes it (`app.js:103-118`).
- `markAlertRead(id)` POSTs to `/alerts/{id}/read` with the CSRF token from the meta tag, then marks the item read and decrements the badge in place (`app.js:120-140`).

### 2.5 Flash messages

Server-rendered flash banners use `.alert-box` (e.g. `.alert-box.success`, `.alert-box.error`) and **auto-dismiss after 6 seconds** with a fade-out, then remove themselves from the DOM (`app.js:148-155`).

---

## 3. Design-Token System

The design system is built on CSS custom properties declared on `:root` (`app.css:5-69`). Components reference tokens (`var(--primary)`, `var(--card-bg)`, …) so theming and re-branding happen by swapping variables, never by editing components. **Never hardcode a hex value that should be a token** — this is a hard project rule and is what makes dark mode and live branding work.

### Brand & semantic colors

| Token | Light value | Meaning |
| --- | --- | --- |
| `--primary` | `#16a34a` | brand green (overridable by Branding) |
| `--primary-dark` / `--primary-light` | `#15803d` / `#4ade80` | hover / accent variants |
| `--secondary` | `#52525b` | neutral |
| `--success` | `#059669` | positive |
| `--warning` | `#d97706` | caution |
| `--danger` | `#dc2626` | error / destructive |
| `--info` | `#0284c7` | informational |
| `--orange` / `--purple` / `--indigo` | `#f97316` / `#8b5cf6` / `#6366f1` | extended palette |

### Surfaces, borders, text

| Token | Light value | Use |
| --- | --- | --- |
| `--bg` | `#f0f2f4` | app canvas |
| `--card-bg` | `#ffffff` | cards, panels, inputs |
| `--bg-subtle` / `--bg-secondary` / `--surface-alt` | `#e8ebee` / `#f6f8fa` / `#f9fafb` | table heads, alt rows |
| `--border` / `--border-light` | `#d0d7de` / `#e8ebee` | dividers |
| `--success-subtle` / `--warning-subtle` / `--danger-subtle` / `--info-subtle` | pale tints | status backgrounds |
| `--text` / `--text-muted` / `--text-light` | `#0d1117` / `#57606a` / `#8b949e` | body / secondary / tertiary |

### Layout, shape, motion

| Token | Value |
| --- | --- |
| `--sidebar-w` / `--topbar-h` | `260px` / `64px` |
| `--radius` / `--radius-sm` / `--radius-lg` | `12px` / `8px` / `16px` |
| `--shadow` / `--shadow-md` / `--shadow-lg` | layered soft shadows |
| `--transition` | `0.2s ease` |

`color-scheme: light` is forced on `:root` (`app.css:68`) so browsers don't auto-restyle form controls in dark-OS environments; the app drives dark mode itself via `data-theme`.

The terminal/code tokens (`--terminal-bg`, `--code-green-*`, `app.css:50-56`) are intentionally **always dark** regardless of theme, for code/console display surfaces.

---

## 4. The CSP-Compliant Event Model

**There are no inline event handlers anywhere** — no `onclick`, `onchange`, `oninput`, `onsubmit`. This is mandated by the strict Content-Security-Policy (inline handlers would require `'unsafe-inline'`). Instead, views declare intent with `data-*` attributes and a single delegated listener block in `app.js:427-597` interprets them. This is the most important thing to understand before writing any view markup.

### The delegation contract

`app.js:403-426` documents the full attribute vocabulary. The handlers are global document listeners (one each for `click`, `change`, `input`, `submit`), so they work for markup added at any time without re-binding.

| Attribute | Effect | Handler |
| --- | --- | --- |
| `data-click="fnName"` | calls `window.fnName(el)` with `this=el` (mirrors `onclick="fn(this)"`) | `app.js:537-539` |
| `data-click` + `data-arg="v"` | calls `window.fnName("v")` | `app.js:437-439` |
| `data-click` + `data-args='[1,2]'` | JSON-parsed args: `window.fnName(1,2)` | `app.js:435-436` |
| `data-print` | `window.print()` | `app.js:460` |
| `data-confirm-click="msg"` | `confirm()` guard before the click proceeds | `app.js:463-466` |
| `data-show-modal="id"` | opens a modal via `showModal`/`openModal` | `app.js:477-485` |
| `data-close-modal="id"` | closes a modal (`closeModal` or `pkgCloseModal`) | `app.js:469-475` |
| `data-toggle-class="cls"` (`data-target="#id"`) | toggles a class on the target (or self) | `app.js:518-523` |
| `data-add-class` / `data-remove-class` | add/remove a class on the target | `app.js:524-535` |
| `data-toggle-visible="id"` | flips `display:none/block` on `#id` | `app.js:488-493` |
| `data-toggle-sibling="cls"` | toggles a class on the next sibling | `app.js:496-501` |
| `data-expand="id"` | toggles `#id` and rewrites the button label (show/hide assumptions) | `app.js:504-515` |
| `data-change="fnName"` (+ `data-input-val`) | on `change`; with `data-input-val` passes `this.value` | `app.js:546-553` |
| `data-autosubmit` | submits the element's form on `change` | `app.js:545` |
| `data-input="fnName"` (+ `data-input-val`) | on `input`; with `data-input-val` passes `this.value` | `app.js:565-569` |
| `data-value-display="id"` | on `input`, mirrors `this.value` into `#id` textContent (e.g. range sliders) | `app.js:560-564` |
| `data-confirm="msg"` | `confirm()` guard on form submit | `app.js:575-577` |
| `data-submit="fnName"` | custom submit handler: `fn(event, ...data-args)`, default prevented | `app.js:579-587` |

The function resolver supports dotted names (`a.b.c`) walked from `window` (`app.js:428-430`), so handlers can live in namespaced objects. If a named handler doesn't exist, the call is silently a no-op.

### Usage example (from a real view)

A destructive action with a confirm guard and a function call needs zero JavaScript in the view:

```html
<button data-print class="btn btn-ghost"><i class="bi bi-printer"></i> Print / Export</button>
```
(`views/risk/index.php:13`)

Modal open/close buttons (`views/admin/api_keys.php:22`, `:100`, `:130`):

```html
<button class="btn btn-primary" data-show-modal="createKeyModal">New API Key</button>
...
<button class="um-close" data-close-modal="createKeyModal"><i class="bi bi-x-lg"></i></button>
```

### Rule for view authors

When you need interactivity, **always** reach for a `data-*` attribute first. If you need a custom function, attach it to `window` (or a `window` namespace) and reference it by name. Never write `onclick=`. Never add a `<script>` without a `nonce="<?= Security::nonce() ?>"`.

---

## 5. Reusable Components

Each component below is a documented CSS contract in `app.css` plus, where relevant, behavior in `app.js`. Class names are stable; reuse them rather than inventing new styles.

### 5.1 Page header & title

Every full view opens with a page header (`app.css:570-580`):

```html
<div class="page-header">
  <div>
    <h1 class="page-title">Risk Register</h1>
    <p class="page-subtitle">Track, assess, and treat organizational risks</p>
  </div>
  <div class="page-actions">
    <a href="/risk/create" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</a>
  </div>
</div>
```
(`views/risk/index.php:8-18`)

`.page-header` is a space-between flex row that stacks on mobile; `.page-title` is 24px/800; `.page-actions` holds the right-aligned button cluster.

### 5.2 Breadcrumbs

Set `$breadcrumbs` in the view as `[label, url]` pairs (the last entry's URL is `null` to mark the current page); the topbar renders them (§2.2). Example: `$breadcrumbs = [['Risk Register', null]];` (`views/risk/index.php:4`).

### 5.3 Cards

`.card` → optional `.card-header` (with `.card-title`, icon auto-colored `--primary`) → `.card-body` (use `.card-body.p0` for full-bleed tables) (`app.css:450-477`). Layout helpers `.col-span-2` / `.col-span-3` span dashboard-grid columns.

### 5.4 Buttons

`.btn` base plus a variant (`app.css:484-513`):

- Variants: `.btn-primary`, `.btn-danger`, `.btn-success`, `.btn-ghost` (outlined neutral).
- Sizes: `.btn-sm`, `.btn-lg`, `.btn-full` (100% width, centered).
- Icons are inline Bootstrap Icons (`<i class="bi bi-...">`).
- **Never hardcode `disabled` on a submit button** (project rule) — disable in JS only during an in-flight request.

### 5.5 Tables

Two interchangeable table classes share one visual language: `.table` (`app.css:660-681`) and `.data-table` (`app.css:687-712`). Headers are uppercase, letter-spaced, on `--bg-subtle`; rows hover-highlight; the last row drops its bottom border.

Row/empty modifiers:

- `.row-danger td` — tinted red row.
- `.row-muted td` — 60% opacity.
- `.empty-row td` — centered, padded empty cell (host for an empty state).

Always set `scope` on header cells for accessibility (`views/admin/custom_fields.php:105-111`):

```html
<th scope="col">Label</th>
```

On narrow screens, table wrappers scroll horizontally and `.data-table` keeps a `600px` min-width (`app.css:1766-1768`).

### 5.6 Badges

Pill labels via `.badge` + a status modifier (`app.css:717-738`). Modifiers map both to colors and to domain statuses, so you can use the status name directly: `.badge-compliant`, `.badge-published`, `.badge-in_progress`, `.badge-overdue`, `.badge-open`, `.badge-closed`, `.badge-draft`, etc. `.badge-lg` enlarges; `.badge-gold` is the bordered highlight pill.

Risk severity has its own scale (`app.css:743-757`): `.risk-badge` + `.risk-critical` / `.risk-high` / `.risk-medium` / `.risk-low` (bold, uppercase, bordered), and `.risk-badge-lg`.

### 5.7 Stat cards

KPI tiles: `.stats-grid` (auto-fit, `minmax(220px,1fr)`) of `.stat-card`s, each with `.stat-icon`, `.stat-value` (28px/800), `.stat-label`, and an optional `.stat-progress` bar (`app.css:585-620`). A compact pill variant exists as `.stats-row` of `.stat-mini` (`app.css:622-641`).

### 5.8 Filter popovers

The standard list-page filter control. There are **two coexisting patterns**, both CSP-safe:

**(a) The project-standard toggle** (per `CLAUDE.md`) opens the popover via `data-toggle-class` (handled generically in `app.js:518-523`) — `views/vendor/index.php:68-75`:

```html
<div class="filter-popover-wrap">
  <button type="button" class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#vendorFilterPopover">
    <i class="bi bi-funnel-fill"></i> Filters
    <?php if ($_filterCount > 0): ?><span class="filter-active-count"><?= $_filterCount ?></span><?php endif; ?>
  </button>
  <div id="vendorFilterPopover" class="filter-popover">
    <form method="GET" action="/vendor">
      <div class="filter-popover-grid single-col"> ... </div>
    </form>
  </div>
</div>
```

**(b) The dedicated toggle** uses `data-filter-toggle` on the `.filter-btn`, handled by a purpose-built block (`app.js:658-682`) that additionally **closes any other open popover** and closes on outside click. Use this when you want exclusive single-popover behavior.

Styles (`app.css:954-1046`): `.filter-btn` is a rounded pill (funnel icon in amber), `.active` tints it with the brand color; `.filter-popover` is an absolutely-positioned card shown by `.open`; `.filter-popover-grid` is a 2-column field grid (`.single-col` for one column). `.filter-active-count` / `.filter-count` render the applied-filter badge.

### 5.9 Modals

Two modal systems exist. **Prefer the `.um-*` ("AEGIS dialog") system for new work** — it avoids a conflict with Bootstrap's `.modal{display:none}` rule (`app.css:1495-1505`).

**`.um-*` dialog** (`app.css:1499-1505`, real markup in `views/admin/api_keys.php:98-131`):

```html
<div class="um-overlay" id="createKeyModal" style="display:none">
  <div class="um-dialog">          <!-- or .um-dialog-lg -->
    <div class="um-header">
      <h3><i class="bi bi-key-fill"></i> New API Key</h3>
      <button class="um-close" data-close-modal="createKeyModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <!-- form ... -->
      <button type="button" class="btn btn-ghost" data-close-modal="createKeyModal">Cancel</button>
    </div>
  </div>
</div>
```

Open it from anywhere with `data-show-modal="createKeyModal"`; close with `data-close-modal="createKeyModal"` or by the close icon. Both go through `app.js`.

**Legacy `.modal-overlay` / `.modal`** (`app.css:1487-1493`) is the older Bootstrap-style structure (`.modal-header`, `.modal-body`); still supported by the same JS helpers.

**JS modal helpers** (`app.js:224-267`):

- `window.showModal(id)` sets the overlay to `display:flex`, records the open time, and applies an **iOS ghost-click guard** — pointer events on the overlay are suppressed for 400ms after open (the inner dialog stays interactive) so a tap that opened the modal can't immediately "click through" and close it (`app.js:226-241`).
- `window.closeModal(id)` hides it and restores focus.
- `openModal` aliases `showModal` if a page hasn't defined its own (`app.js:247`).
- Data triggers `data-modal-open` / `data-modal-close` are also supported (`app.js:249-256`), in addition to the `data-show-modal` / `data-close-modal` covered above.
- **Escape** closes any open modal (`app.js:259-267`).
- **Backdrop click** on the `.modal-overlay` / `.um-overlay` element itself closes it — but only if the modal was opened more than 350ms ago (the same ghost-click guard) (`app.js:451-457`).

### 5.10 Empty states

Two sizes (`app.css:1477-1483`):

- `.empty-state` — full-page: `.empty-icon` (48px), `<h3>`, `<p>`, usually a call-to-action button.
- `.empty-state-sm` — compact, for inside a `.empty-row` table cell (the project standard). Real usage: `views/admin/custom_fields.php:143`. Use this rather than inline padding.

### 5.11 Forms

`.form-group` (label + control), `.form-row` (horizontal field group, stacks on mobile), `.form-label` (uppercase; add `.required` for a red asterisk), `.form-control` (inputs/selects/textareas with a brand-colored focus ring), `.form-hint`, `.form-actions` (`app.css:519-565`). `.input-group` + `.input-addon` build joined input/button controls.

### 5.12 File drop zones

`.file-drop` elements support drag-and-drop (`app.js:643-656`): dragging adds `.drag-over`; dropping assigns the file to the linked `<input>` (via the zone's `for` attribute) and calls `window.showFileChange` to display the file name and recolor the zone (`app.js:629-642`). Per project rule, every upload field must include a field-reference key beneath it.

### 5.13 Permission matrix

The IAM grid (`app.css:1567-1577`, behavior `app.js:285-302`): `.perm-table` with sticky first/last columns; checkboxes (`.perm-checkbox`) mark their row `.perm-row-modified` on change; per-column `.perm-col-all` buttons select/clear an entire module column. This backs the two-pane permissions editor described in the project's IAM standard.

---

## 6. Accessibility

The UI implements several a11y features centrally so individual views inherit them.

### Global focus-visible indicator

A keyboard-only focus ring (`app.css:204-217`) applies a 2px `--primary` outline to buttons, links, `[role="button"]`, nav items, dropdown items, filter buttons, and anything with `[tabindex]`. Because it uses `:focus-visible`, mouse/touch users never see the outline — accessibility with no visual cost for pointer users. Nav accordion headers get their own focus-visible treatment (`app.css:200-202`).

### Accessible modals (role=dialog + focus trap)

`app.js:166-222` upgrades **every** modal automatically when opened, without editing the ~25 individual modal markups:

- `_applyDialogA11y()` (`app.js:179-196`) sets `role="dialog"` and `aria-modal="true"` on the dialog, derives `aria-labelledby` from the first heading (or `aria-label="Dialog"` as a fallback), and labels unlabeled close buttons with `aria-label="Close"`.
- `_onModalOpen()` (`app.js:197-206`) remembers the previously-focused element and moves focus into the dialog's first focusable control.
- **Tab is trapped** within the top-most open modal — Shift+Tab on the first element wraps to the last and vice-versa (`app.js:212-222`).
- `_onModalClose()` (`app.js:207-210`) **restores focus** to the element that opened the modal.

`_modalFocusables()` (`app.js:174-178`) computes the focusable set (visible links, enabled buttons/inputs/selects/textareas, positive-tabindex elements).

### ARIA in the shell

- Alert bell: `role="button"`, `tabindex="0"`, `aria-label`, `aria-haspopup`, with explicit Enter/Space keyboard handling (`layout.php:293`; `app.js:95-100`).
- Hamburger toggle: `aria-label="Open menu"` (`layout.php:268`).
- Theme toggle & logout: `aria-label`s; decorative icons carry `aria-hidden="true"` (`layout.php:257`, `:290-291`).
- Tables: header cells use `scope="col"` / `scope="row"`.

---

## 7. Responsive Behavior

The layout adapts at several breakpoints (`app.css` media queries at `:652`, `:1548`, `:1738`, `:1790`).

- **≤1100px** — dashboard grid collapses to one column; `.col-span-2` spans a single column (`app.css:652-655`).
- **≤900px** — body switches to a column flow; the sidebar becomes a fixed off-canvas drawer (`left:-280px`, slides to `0` with `.open`) (`app.css:1548-1553`).
- **≤768px** — the off-canvas sidebar drawer, the hamburger appears, page headers stack and their buttons go full-width, tables scroll horizontally (`min-width:600px`), form rows stack, KPI grids go 2-up, charts go full width (`app.css:1738-1788`).
- **≤480px** — KPI grids go 1-up; the topbar user's name is hidden (`app.css:1790-1793`).

### Mobile sidebar drawer

`app.js:355-401` manages the off-canvas drawer:

- A dimming `.sidebar-overlay` is created and appended to `<body>`; clicking it closes the drawer.
- `window.toggleSidebar()` opens/closes by toggling `.open` on `#sidebar` and the overlay.
- The hamburger `.sidebar-toggle` is wired directly (the script sits at the end of `<body>`, so the button is already in the DOM) and stops propagation so the document-level outside-click logic doesn't immediately re-close it (`app.js:384-390`).
- **Sidebar scroll position is persisted** across navigations in `sessionStorage['sidebarScroll']` (`app.js:392-400`).

(There is also a separate `#sidebarBackdrop`/`.sidebar-backdrop` element in the layout, `layout.php:262`; the JS-created `.sidebar-overlay` is the active backdrop.)

---

## 8. Dark Mode

Dark mode is driven entirely by the platform (not OS preference) by setting `data-theme="dark"` on `<html>`.

### How it activates

1. **No-flash bootstrap** in `<head>` applies the attribute before first paint from `localStorage` (`layout.php:20`).
2. The **theme toggle** flips and persists it at runtime (`app.js:600-626`).

### How it restyles

The dark theme is overwhelmingly a **token swap**: `html[data-theme="dark"] { ... }` redefines the same custom properties with a GitHub-style dark palette — `--bg:#010409`, `--card-bg:#0d1117`, `--text:#e6edf3`, a brighter `--primary:#3fb950`, vivid semantic colors for contrast, darker shadows, and dark sidebar tones (`app.css:1864-1906`). Because every component references tokens, most of the UI re-themes for free.

It also overrides Bootstrap's own variables (`--bs-body-bg`, `--bs-card-bg`, `--bs-modal-bg`, `--bs-table-*`, `--bs-link-color`, …) so Bootstrap widgets follow suit (`app.css:1907-1929`), plus targeted rules for Bootstrap components and AEGIS pieces that need explicit treatment (tables, dropdowns, modals, list groups, pagination, tabs, utility classes, the `.um-*` dialogs, code blocks, etc.) (`app.css:1945-2065`+).

### Author rule

Use tokens, never raw hex, in component styles and inline styles — `var(--danger)`, `var(--success)`, `var(--warning)`, `var(--card-bg)`, etc. Hardcoded colors break dark mode and are flagged by the UI audit. The handful of intentionally-always-dark surfaces (terminal/code blocks) use their dedicated tokens.

---

## 9. Quick Reference — Do / Don't

| Do | Don't |
| --- | --- |
| Use `data-click`, `data-submit`, `data-show-modal`, `data-close-modal`, `data-confirm-click`, `data-toggle-class` | Write `onclick=` / `onchange=` / `oninput=` / `onsubmit=` |
| Add `nonce="<?= Security::nonce() ?>"` to every `<script>` | Emit an un-nonced inline script |
| Wrap output in `Security::h()` | Echo raw user data into markup |
| Reference `var(--token)` for colors | Hardcode hex values that should be the accent |
| Set `$pageTitle`, `$activeModule`, `$breadcrumbs`; use `.page-header`/`.page-title` | Ship a view without a header or breadcrumbs |
| Use `.um-*` dialogs for new modals | Add a modal without `role=dialog`/focus handling (the JS adds it, but rely on it) |
| Use `.empty-state-sm` inside `.empty-row` | Use inline padding for empty states |
| Use `.filter-btn` + `.filter-popover-wrap` for list filters | Build a bespoke filter widget |
| Link the brand mark to home | Leave the logo non-clickable |
