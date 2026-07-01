# Teacher Hub — Architecture

## What it is

**Teacher Hub** is a one-stop, **client-side** hub for a Utah 5th-grade classroom.
Everything runs in the browser and persists to `localStorage`. There is **no
backend, no database, no authentication, and no build step** — the files you edit
are the files the browser runs. It lives at `teacher/` inside the
`jessicarojas1.github.io` portfolio monorepo.

## Platform & stack

| Concern | Choice |
|---------|--------|
| Markup/logic | A single `teacher/index.html` (~196 KB) containing all tab sections and the entire app as inline `<script>` code |
| Branding | `teacher/branding.js` (separate module; logo/name/accent) |
| CSS framework | Bootstrap **5.3.3** (CSS + `bootstrap.bundle.min.js`) from jsDelivr |
| Icons | Bootstrap Icons **1.11.3** from jsDelivr |
| Shared theme | `../theme.css` (portfolio-wide, at repo root) |
| Favicon | `../favicon.ico` (repo root; currently an empty placeholder) |
| Persistence | Browser `localStorage` (per-browser, per-device) |
| Theming | `data-bs-theme` set from `localStorage['bsTheme']` (default **dark**) |
| Output | Printables via `window.print()`; Gradebook CSV via a `Blob` download |

There is **no SheetJS, no Chart.js**, no bundler, no package manager, and no
`node_modules`. Third-party code is exactly the two jsDelivr assets above.

## Design principles

- **Zero-backend / offline-first-ish.** No server round-trips for data; a teacher
  can use it on a single classroom device. (True offline still requires vendoring
  the CDN — see [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md).)
- **Single-file app.** All feature logic is inline in `index.html`; tabs are
  `.tab-pane` sections toggled by a `switchTab()` function rather than a router.
- **Data stays with the teacher.** Nothing is transmitted anywhere; student names,
  grades, behavior, and IEP notes never leave the browser. This is a privacy
  strength and a durability risk — see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)
  and [SECURITY.md](SECURITY.md).
- **Live branding.** `branding.js` applies logo/name/accent on load and on save.

## Component overview (feature tabs)

The navbar tab strip drives ten `.tab-pane` sections via
`switchTab('tab<Name>', this)`:

| Tab (`switchTab` id) | Purpose | Key `localStorage` keys |
|----------------------|---------|-------------------------|
| **Planner** (`tabPlanner`) | Lesson & unit plans | `teacher_plans`, `teacher_units` |
| **Activities** (`tabActivities`) | Ready-made classroom activities (expandable cards) | — (static content) |
| **Templates** (`tabTemplates`) | Printable templates (`window.print()`) | — |
| **Standards** (`tabStandards`) | Utah standards browser: ELA, Math, Science/SEEd, Social Studies, Utah History, Health/PE | `std_taught` (taught-marks) |
| **Gradebook** (`tabGradebook`) | Assignments + grades; **CSV export** (`gradebook.csv`) | `gb_assignments`, `gb_grades` |
| **Class Management** (`tabClassMgmt`) | Communication log, behavior, PBIS, seating chart, IEP notes, supply list | `comm_log`, `behavior_data`, `pbis_data`, `pbis_goal`, `seating_data`, `iep_notes`, `supply_list` |
| **Calendar** (`tabCalendar`) | Month calendar + events | `cal_events` |
| **Resources** (`tabResources`) | Morning meeting, word wall, comments, awards | `word_wall`, and static resources |
| **Progress** (`tabProgress`) | Reading levels, anecdotal notes, math fluency | `student_levels`, `anecdotal_notes`, `fluency_data`, `fluency_op` |
| **Tools** (`tabTools`) | Classroom tools (timer, noise meter, tally, dice, name picker, groups, spinner) | `tallies` |

Settings (gear modal) writes `teacher_settings` (teacher/school/grade + roster)
and `pbis_goal`. The Branding modal (palette button, injected by `branding.js`)
writes `teacher.branding.v1`.

## Monorepo placement & internal layout

```
jessicarojas1.github.io/            (portfolio root; GitHub Pages)
├── theme.css                       shared theme  ← teacher references ../theme.css
├── favicon.ico                     shared favicon ← ../favicon.ico (empty placeholder)
├── index.html                      portfolio home ← navbar brand links to ../
├── cmmc2/ cmmi/ isms/ aitool/ …    sibling static sites
└── teacher/                        ◄── THIS PROJECT
    ├── index.html                  single-file app (all tabs + inline app JS)
    ├── branding.js                 branding module (localStorage teacher.branding.v1)
    ├── Dockerfile                  nginx static image (adds headers/CSP)
    ├── render.yaml                 Render static-site blueprint
    ├── README.md  OPEN_ITEMS.md  CLAUDE.md
    ├── docs/       ARCHITECTURE · DEPLOYMENT · DISASTER_RECOVERY · SECURITY
    └── deployments/ LOCAL · SINGLE_LINUX · KUBERNETES · AWS · AZURE · AIRGAPPED
```

Unlike some siblings, Teacher Hub loads **only** `bootstrap.bundle.min.js` (CDN)
and `branding.js`. It does **not** load `../users.js`, `../roles.js`,
`../script.js`, `../analytics.js`, or `../siteSearch.js`. It still depends on the
parent `../theme.css` and `../favicon.ico`, and the navbar brand links to `../`
(portfolio home) — note this parent dependency for standalone deploys.

## Configuration model

There is **no config file and no environment configuration.** All configuration is
user-driven and stored client-side:

| What | Where set | Persisted key |
|------|-----------|---------------|
| Theme (dark/light) | navbar toggle | `bsTheme` |
| Branding (logo/name/accent) | Branding modal | `teacher.branding.v1` |
| Teacher/school/grade + roster | Settings modal | `teacher_settings` |
| PBIS goal | Settings modal | `pbis_goal` |
| Vendor versions | hard-coded `<link>`/`<script>` | Bootstrap `5.3.3`, Icons `1.11.3` |

`branding.js` sanitizes logo URLs to `http(s)://` or `data:image/...` only,
HTML-escapes user strings, applies the accent by overriding the `--bs-primary` CSS
custom property, and degrades gracefully to the default `JR` mark on a broken logo.

## Request & error contract

There is **no request/response contract** — no HTTP API, no routing beyond static
file paths, no response envelope, and no error codes. "Errors" are purely
client-side: a bad logo URL falls back to the default mark; malformed
`localStorage` JSON is caught and treated as empty (`load()` in `branding.js`); a
missing CDN asset breaks styling/icons but not the local files. HTTP status codes
are those of the static host (200 for the entry page; 404 for missing assets).

## Security model

- **No auth / no server** → no server-side attack surface, no credentials to leak.
- **All data client-side** → nothing exfiltrated by design; but data is
  unencrypted in `localStorage` on a possibly **shared classroom device** and is
  FERPA-relevant (student PII). See [SECURITY.md](SECURITY.md).
- **Current gaps (honest):** the page ships **no Content-Security-Policy** and uses
  **~109 inline `onclick`, 16 `onchange`, 3 `oninput`** handlers plus inline
  `<script>`/`<style>`, which conflicts with the repo's "no inline handlers / CSP"
  rule. SRI is present on the Bootstrap **CSS and JS** tags but **absent on the
  Bootstrap Icons CSS**. Remediation is tracked in [../OPEN_ITEMS.md](../OPEN_ITEMS.md).
- **Mitigation path:** add CSP + security headers at the edge/host now
  (see deployment guides), externalize handlers to `data-*` + `addEventListener`
  next, then drop `'unsafe-inline'`.

## Observability

No server, so no server metrics/traces/health endpoint. Observability is limited
to the **static host's access logs** (nginx/CloudFront/Front Door) and the
browser DevTools console/network. There is no application logging or telemetry —
the site intentionally sends no analytics.

## Deployment topology

The artifact is inert static files. Supported models (see
[DEPLOYMENT.md](DEPLOYMENT.md) and [../deployments/](../deployments/)):

- **Local** static server ([../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md))
- **Single Linux VM** nginx + TLS ([../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md))
- **Kubernetes** nginx static Deployment ([../deployments/KUBERNETES.md](../deployments/KUBERNETES.md))
- **AWS** S3 + CloudFront ([../deployments/AWS.md](../deployments/AWS.md))
- **Azure** SWA / Blob `$web` + Front Door ([../deployments/AZURE.md](../deployments/AZURE.md))
- **Air-gapped** vendored assets + internal nginx ([../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md))
- **Render** static site ([../render.yaml](../render.yaml))
- **GitHub Pages** (the portfolio's default hosting)
