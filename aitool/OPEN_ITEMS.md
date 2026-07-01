# OPEN_ITEMS — AI Tool Evaluation Framework (`aitool`)

Honest production-readiness register for the static site. "Done" = verified in the
code today; "Outstanding" = gap, TODO, or hardening not yet applied. Grouped by theme;
each item lists impact + suggested action.

---

## 1. Content Security Policy & inline code

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⚠️ Outstanding | Inline `onclick="window.print()"` in 5 pages (`pol-ai-001`, `pro-ai-001`, `tmp-ai-001/002/003`) | Violates the repo "no inline event handlers" rule; forces `script-src 'unsafe-inline'` in CSP | Replace with a `data-print` button wired by a small script / parent `script.js` handler |
| ⚠️ Outstanding | Inline `<head>` theme-bootstrap `<script>` in every page | Same — blocks a strict script CSP | Externalize to a tiny `theme-init.js`, or add a CSP hash for the exact inline block |
| ✅ Done | `branding.js` uses no inline handlers (all `addEventListener`) | Compliant | — |
| ⚠️ Outstanding | No CSP is emitted by the site itself (only by the server config we ship) | Depends on the host to set headers | Ship CSP at every deploy target (done in `Dockerfile`/`nginx.conf`, `render.yaml`; replicate on S3/CF, Azure, k8s) |

## 2. Self-containment / shared assets

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⚠️ Outstanding | Pages reference parent-repo assets `../theme.css`, `../isms/isms.css`, `../users.js`, `../roles.js`, `../script.js`, `../favicon.ico` | `aitool/` is not self-contained; a standalone deploy 404s these | Deploy the whole repo, or vendor the shared files into `aitool/` (documented in `AIRGAPPED.md`) |
| ⚠️ Outstanding | Runtime dependency on `cdn.jsdelivr.net` for Bootstrap | Site breaks offline / if CDN unreachable | Vendor Bootstrap locally for air-gapped/high-availability (see `AIRGAPPED.md`) |

## 3. Dependency integrity & supply chain

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ Done | Bootstrap CSS + JS loaded with SRI `integrity=` + `crossorigin` | CDN tampering is detected | Keep hashes in sync on any version bump |
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
