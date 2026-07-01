# OPEN_ITEMS — AI Tool Evaluation Framework (`aitool`)

Honest production-readiness register for the static site. "Done" = verified in the
code today; "Outstanding" = gap, TODO, or hardening not yet applied. Grouped by theme;
each item lists impact + suggested action.

---

## 1. Content Security Policy & inline code

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ Done | Inline `onclick="window.print()"` in 5 pages (`pol-ai-001`, `pro-ai-001`, `tmp-ai-001/002/003`) | Was violating the repo "no inline event handlers" rule | **Fixed:** replaced with `data-print` + a delegated `addEventListener('click', … closest('[data-print]'))` handler in `theme-init.js`. Verified: `grep -c 'onclick=' aitool/*.html` = 0. |
| ✅ Done | Inline `<head>` theme-bootstrap `<script>` in every page | Was blocking a strict script CSP | **Fixed:** externalized to `theme-init.js` (render-blocking in `<head>`, so still pre-paint / no flash). The 4 per-page inline scripts (`tmp-ai-001/002/003`, `vendor-tracker`) were also externalized to sibling `*.js`. Verified: zero bare `<script>` blocks remain; `node --check` passes on all new JS. |
| ✅ Done | `branding.js` uses no inline handlers (all `addEventListener`) | Compliant | — |
| ✅ Done | No CSP is emitted by the site itself (only by the server config we ship) | Now defends hosts that don't set headers (e.g. GitHub Pages) | **Fixed:** every page now ships a `<meta http-equiv="Content-Security-Policy">` with **`script-src 'self' https://cdn.jsdelivr.net` (no `'unsafe-inline'`)**. `nginx.conf` + `render.yaml` edge CSPs were tightened to match (script `'unsafe-inline'` dropped; `style-src` keeps it for inline `style=`/branding accent). |

## 2. Self-containment / shared assets

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⚠️ Outstanding | Pages reference parent-repo assets `../theme.css`, `../isms/isms.css`, `../users.js`, `../roles.js`, `../script.js`, `../favicon.ico` | `aitool/` is not self-contained; a standalone deploy 404s these | Deploy the whole repo, or vendor the shared files into `aitool/` (documented in `AIRGAPPED.md`) |
| ⚠️ Outstanding | Runtime dependency on `cdn.jsdelivr.net` for Bootstrap | Site breaks offline / if CDN unreachable | Vendor Bootstrap locally for air-gapped/high-availability (see `AIRGAPPED.md`) |

## 3. Dependency integrity & supply chain

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ Done | Bootstrap CSS + JS loaded with SRI `integrity=` + `crossorigin` | CDN tampering is detected | **Corrected:** the Bootstrap **bundle JS** `integrity` was an invalid hash (browser would reject the bundle → dropdowns/modals/navbar broken). Recomputed the real `sha384` from the `bootstrap@5.3.3` npm tarball (`openssl dgst -sha384 -binary | openssl base64 -A` — CSS hash matched the tarball, confirming CDN parity) and pinned `sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz` on all 7 pages. CSS hash was already correct. Keep hashes in sync on any version bump. |
| ⚠️ Outstanding | Shared parent JS (`script.js`, `roles.js`, `users.js`) loaded without SRI (same-origin) | Lower risk (same origin) but no integrity pin | Consider SRI or a build that fingerprints local assets |
| ⚠️ Outstanding | No automated dependency/version monitoring | Missed Bootstrap security updates | Add a scheduled check / Dependabot-style reminder |

## 4. Authentication & access

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⚠️ By design | The "Login" modal + role badges are a **client-side RBAC demo** (`roles.js`/`users.js`); there is no server auth | Not a real access control boundary — anyone with the URL sees everything | If gating is required, front the site with an identity-aware proxy (oauth2-proxy, Azure Entra, Cloudflare Access) |
| ⚠️ Outstanding | No access logging of who viewed documents | No audit trail of consumption | Rely on the fronting proxy / CDN access logs |

## 5. Data & privacy

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ Done | All state (`bsTheme`, `aitool.branding.v1`, vendor-tracker data) stays in the browser `localStorage` | No PII leaves the browser; no server to breach | Document to users that tracker data is per-browser and not backed up |
| ✅ Done | Tracker/questionnaire "file uploads" use `FileReader`/labels client-side only; nothing is transmitted | No upload endpoint to secure | — |
| ⚠️ Outstanding | Branding logo can be a remote `http(s)` URL → an outbound image request | Minor beaconing/privacy leak of viewer IP to logo host | Prefer uploaded `data:` URLs (already supported); note in `SECURITY.md` |

## 6. CI/CD & operations

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⚠️ Outstanding | No CI pipeline (lint, link-check, header/CSP check, deploy) | Manual deploys, no gate | Add HTML-lint + link-check + OIDC deploy workflow; add badges to README |
| ⚠️ Outstanding | No automated header/CSP verification post-deploy | Drift between intended and served headers | Add a `curl -I` assertion step (see each `deployments/*` Verification) |
| ✅ Done | `Dockerfile` (non-root, healthcheck) + `render.yaml` present | Container + PaaS paths exist | — |

## 7. Accessibility & UX

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ Done | Skip link, ARIA labels, breadcrumbs, semantic landmarks present | Baseline a11y | Run an automated a11y audit (axe) in CI |
| ✅ Done | Dark/light theme via `data-bs-theme` + persisted `bsTheme` | Theme persists | — |

---

**Keeping this current:** update this register whenever a page, asset, or deploy target
changes — it is part of "done" alongside the security and UI audits (see `CLAUDE.md`).
