# Enterprise Security, Compliance & Architecture Review
**Repository:** `jessicarojas1/jessicarojas1.github.io`
**Review date:** 2026-06-18
**Reviewer role:** Principal Enterprise Security Architect / CISO / DevSecOps / Red Team
**Scope:** Entire repository (all sub-applications, infrastructure, CI/CD, supply chain)
**Posture assumption:** *"Everything is hostile until proven otherwise."*

---

## 1. Executive Summary

This repository is a **personal portfolio mono-repo** published via GitHub Pages (`*.github.io`). It contains a large static front-end portfolio at the root plus **~8 full back-end applications** of widely varying maturity, several C++ security CLIs, two Swift mobile apps, and production-grade Infrastructure-as-Code (Terraform/Bicep/Helm) targeting AWS GovCloud and Azure Government.

The review covered: **aegis** (PHP GRC platform), **paladin** (PHP knowledge/QMS platform with SSO/SCIM), **apex** (PHP API), **sentinel-qms** (FastAPI + React QMS), **citadel** (Node/Express security-scanner backend + SPA), **compliance-copilot** (Next.js + Supabase), **aeromarkup** & **business-insight-dashboard** (Python), the C++ tools, and all infra/CI.

**Headline finding:** there is a **bimodal maturity distribution**. The flagship apps — `aegis`, `sentinel-qms`, and `citadel` — are genuinely well-engineered (Argon2id/bcrypt hashing, libsodium AES-256-GCM, parameterized queries, strict CSP, non-root read-only containers, CMK-encrypted GovCloud IaC, full SAST/dep-scan CI). They would survive an external pen-test with only Medium/Low findings. By contrast, **`apex` and the public static site contain real, exploitable Critical/High issues** (a hardcoded production JWT secret, a default-PIN auth bypass enabled in production, a published admin credential), and **`paladin` has a systemic object-level authorization (IDOR) gap** that breaks private-space confidentiality.

No live cloud credentials, API keys, or real `.env` files are committed. The single most dangerous *secret-handling* issue is a **published admin password in client-side JavaScript** on the public site, and a **hardcoded JWT signing key** in `apex`.

**Production readiness:** The mature apps are *near* commercial-SaaS ready but **not** ready for CUI/CMMC/FedRAMP/HIPAA without the remediations below (centralized audit, tenant isolation, supply-chain provenance, gateway-level rate limiting). `apex` and the static "auth" demos must **not** be positioned as security controls.

---

## 2. Overall Security Score: **62 / 100**
Mature apps pull this up; `apex`, the static-site fake-auth, and the paladin IDOR pull it down. Weighted toward the worst exploitable issues.

## 3. Overall Compliance Score: **55 / 100**
Excellent IaC encryption and CI scanning in places; but no unified audit logging, no SBOM/provenance, inconsistent tenant isolation, and demo deployments shipping `ENVIRONMENT=development`.

## 4. Overall Architecture Score: **60 / 100**
Individual apps are reasonably layered; the mono-repo as a whole has no shared security baseline — each app re-implements auth/crypto/headers with divergent quality.

## 5. Overall Enterprise Readiness Score: **45 / 100**
sentinel-qms/citadel GovCloud IaC is the model; most other apps are demo-tier (free Render plans, in-memory rate limits, single-process state, no DR).

---

## 6. Risk Heat Map

| App / Area | Critical | High | Medium | Low | Maturity |
|---|---|---|---|---|---|
| **apex** (PHP API) | 1 | 3 | 2 | 2 | ⚠️ Low |
| **Static site** (root HTML/JS) | 0 | 1 | 2 | 3 | ⚠️ Demo |
| **paladin** (PHP) | 0 | 2 | 6 | 5 | ✅ Good (authz gap) |
| **compliance-copilot** (Next/Supabase) | 0 | 2 | 1 | 1 | ⚠️ Early |
| **aeromarkup** (Python/Azure) | 1 | 1 | 2 | 1 | ⚠️ Low |
| **sentinel-qms** (FastAPI/React) | 0 | 2 | 4 | 4 | ✅ Strong |
| **citadel** (Node + SPA) | 0 | 0 | 2 | 4 | ✅ Strong |
| **aegis** (PHP GRC) | 0 | 0 | 2 | 3 | ✅ Strong |
| **Infra / CI / Supply chain** | 0 | 5 | 6 | 6 | Mixed |
| **C++ tools / Swift / mobile** | 0 | 0 | 0 | 2 | ✅ Good |

---

## 7. Critical Findings

### C1 — Hardcoded production JWT secret + database credentials in `apex`
**Evidence:** `apex/docker-compose.yml:5-7,19` → `POSTGRES_USER/PASSWORD/DB = apex/apex/apex`, `JWT_SECRET: dev-secret-do-not-use-in-production`. Reinforced by `apex/src/Auth.php:150-156`, which silently falls back to `apex-dev-secret-please-override` when `JWT_SECRET` is unset — with **no production fail-fast**, even though `APP_ENV` is available (used at `Auth.php:109`).
**Why it exists:** Convenience dev defaults left in shippable artifacts; the secret loader prioritizes "always runs" over "fails closed."
**Exploitation:** An attacker who knows (or guesses) the signing key forges an HS256 JWT with `role=admin` and gains full authenticated access — classic broken authentication (OWASP A07, CWE-798/CWE-321). If the Postgres port is also exposed (see H7), the `apex/apex` credentials grant direct DB access.
**Business impact:** Complete authentication bypass and data compromise for any `apex` deployment.
**Fix:**
```php
private static function secret(): string {
    $s = getenv('JWT_SECRET') ?: '';
    if ($s === '' || strlen($s) < 32) {
        if ((getenv('APP_ENV') ?: 'development') === 'production') {
            throw new RuntimeException('JWT_SECRET missing/weak; refusing to start.');
        }
        $s = 'apex-dev-secret-please-override'; // dev only
    }
    return $s;
}
```
In `docker-compose.yml`, require the values: `JWT_SECRET: ${JWT_SECRET:?set me}` and `POSTGRES_PASSWORD: ${DB_PASS:?set me}` (paladin already uses this `:?` pattern correctly).

### C2 — Internet-exposed Azure Container App with no WAF/TLS enforcement (aeromarkup)
**Evidence:** `aeromarkup/deploy/azure-gov/containerapp.bicep:50` → `ingress: { external: true, transport: 'auto' }`, no `allowInsecure: false`, no Application Gateway/WAF, no CMK or private-network controls (unlike sibling stacks). `AUTO_MIGRATE=1` runs schema migrations automatically on a publicly reachable app.
**Exploitation:** Direct public exposure of the app with `transport:'auto'` allows cleartext HTTP; absence of WAF removes layer-7 filtering; auto-migration on boot is an availability/integrity risk if the image is ever swapped.
**Business impact:** Public attack surface with no edge protection in a *Government* deployment target.
**Fix:** Front with Azure Application Gateway + WAF_v2 or set `external: false`; set `allowInsecure: false`; gate `AUTO_MIGRATE` behind an explicit one-shot job, not app startup; add CMK + private endpoints to match the citadel/sentinel Azure stacks.

---

## 8. High Findings

**H1 — Default-PIN auth bypass enabled in production (apex).** `apex/render.yaml:18` and `apex/docker-compose.yml:21` set `APEX_ALLOW_DEFAULT_PINS="1"` with `APP_ENV=production`. This weakens/bypasses credential setup on the live deploy. *Fix:* never enable outside isolated dev; default to `0` and gate on a non-production env.

**H2 — Published admin credential in public client-side JS.** `users.js:31` defines `ROOT_PASSWORD = 'RootAdmin@2026!'` (username `root`, role `admin`); `roles.js:6` references the admin role. These files are served by GitHub Pages and readable via `view-source`. Even though the "auth" is client-side SHA-256 in `localStorage` (a non-authoritative demo), the bootstrap credential is real and reusable. *Fix:* remove the hardcoded credential entirely; force a first-run setup; never present client-side gating as a security control.

**H3 — Stored XSS via SVG bypass in paladin's HTML sanitizer.** `paladin/src/Security.php:58-125` allows `<svg>` through and does not filter `xlink:href`, or `<set>/<animate>` `to`/`values` attributes. Confirmed live bypasses: `<svg><a xlink:href="javascript:alert(1)">…`, `<svg><a><set attributeName="href" to="javascript:…"/>`, `<svg><a><animate attributeName="href" values="javascript:…"/>`. Sanitized bodies are stored and rendered **raw** across pages/documents/blog/spaces views (e.g. `views/pages/view.php:43`, `views/documents/view.php:52`, `views/blog/view.php:21`). Any user with create/edit (or direct API POST) stores script that runs for viewers who click. *Fix:* block `svg`/`math`/`foreignObject` outright, or strip namespaced `*:href` and `<set>/<animate>` `to/values/from/by` and reject `javascript:` in them.

**H4 — Systemic IDOR / private-space bypass in paladin.** Object-level access control (private-space membership) is enforced for *page view* but missing across many record types — `DocumentController` (view/update/transition/revise/checkout/delete/download/pdf/docx/exportAcks all gate only on a global `document.*` permission, never `SpaceAccess`), `BlogController` (update/delete/view/editForm), `SearchController` (documents/processes/tasks branches leak private content), and `PageController` **export** methods (`printView/word/docx/pdf` check only `PageAccess::canView`, not `SpaceAccess`). A user with a coarse global permission reads/exports/modifies content in private spaces they don't belong to (OWASP A01, CWE-639). The fix pattern already exists in-repo (`AttachmentController::canAccessParent`, `PageController::view`). *Fix:* apply `SpaceAccess::canView/canContribute/canManage` object checks to every record method.

**H5 — sentinel-qms rate limiter is per-process in-memory.** `backend/app/core/middleware.py:116-148` keeps buckets in a per-worker dict; with `WEB_CONCURRENCY≥2` and multiple k8s replicas the effective limit is `N×` configured and requests load-balance across workers. The login throttle is DB-backed (unaffected), but general API/PAT abuse is under-protected. *Fix:* back with Redis `INCR`+`EXPIRE` (shared atomic window) or enforce at the gateway/WAF.

**H6 — sentinel-qms JWT access + refresh tokens in `localStorage`, no revocation.** `frontend/src/lib/api.ts:17-28`. Any XSS exfiltrates a 7-day refresh token; `jti` is minted but never checked, and logout is stateless. *Fix:* refresh token in `HttpOnly; Secure; SameSite=Strict` cookie (+CSRF), access token in memory; implement `jti` denylist on logout.

**H7 — compliance-copilot `/api/ai/generate` is unauthenticated.** `app/api/ai/generate/route.ts:3-23` accepts a `prompt` from any caller and relays it to the Anthropic API using the server's `ANTHROPIC_API_KEY`, with no auth and no rate limit. *Exploitation:* an open AI relay → unbounded API spend, quota exhaustion, and arbitrary content generation billed to the owner (OWASP LLM Top-10 LLM10 *Unbounded Consumption*; CWE-770). *Fix:* require an authenticated Supabase session, add per-user rate limiting and a max-tokens cap.

**H8 — compliance-copilot RLS has no tenant scoping.** `supabase/schema.sql:77-99` enables RLS but every policy is `using (true)` / `with check (true)` and there is no `org_id`/tenant column. Every authenticated user can read **and modify** all `controls`, `evidence`, and `poam_items` and read all `app_settings`. For a multi-tenant compliance product this is broken access control (CWE-639/A01). *Fix:* add an `org_id` column + membership table and scope every policy: `using (org_id = auth.jwt() ->> 'org_id')`.

**H9 — No supply-chain provenance/signing on production image builds.** `aegis/.github/workflows/azure-deploy.yml:44-53`, `sentinel-qms/.github/workflows/cd-aws-govcloud.yml:51-59`, `cd-azure-gov.yml:68-78` build & push to ACR/ECR (GovCloud) with no cosign signature, SBOM attestation, or provenance — violating SLSA / EO 14028. `id-token: write` is already present. *Fix:* add `provenance: true`/`sbom: true` to `docker/build-push-action` or cosign + `actions/attest-build-provenance`.

**H10 — Mutable-ref CI action + plaintext-HTTP edge + over-broad IAM (infra cluster).**
- `sentinel-qms/.github/workflows/security-scan.yml:37` uses `bridgecrewio/checkov-action@master` (mutable branch) with `security-events: write` — any upstream commit runs immediately and could tamper SARIF.
- `sentinel-qms/infra/terraform/azure-gov/appgateway.tf:140` serves plaintext **HTTP** when the cert id is empty (default `:15`).
- `citadel/deploy/aws-gov/main.tf:475` grants ECR `BatchGetImage`/`GetDownloadUrlForLayer` on `Resource="*"`.
- `apex/docker-compose.yml:8-9` & `aeromarkup/docker-compose.yml:9-15` bind Postgres to `0.0.0.0:5432` with weak creds.
- `apex/public/.htaccess` sets **no** security headers and no TLS redirect.
*Fix:* SHA-pin all third-party actions; make the cert mandatory; scope ECR actions to the repo ARN; bind DBs to `127.0.0.1`; mirror `aegis/.htaccess` headers.

---

## 9. Medium Findings (consolidated)

- **sentinel-qms:** Content-Disposition header injection from unsanitized `original_filename` (`attachments.py:125`, `record_shares.py:138`) — RFC 5987-encode. Upload type validation trusts client `Content-Type` with no magic-byte sniffing (`attachments.py:36`, `storage.py:16`). SSRF guard is TOCTOU/DNS-rebinding susceptible (`net_guard.py` validates, `delivery.py:105` re-resolves) — pin the validated IP. Demo `render.yaml:43` ships `ENVIRONMENT=development` (drops HSTS, relaxes secret guard, enables seeded admin).
- **citadel (frontend):** CSP allows `style-src 'unsafe-inline'` with many interpolated inline styles (`index.html:5`, `report.js`) — defense-in-depth gap; move to CSS custom properties.
- **paladin:** `SpaceController` edit/update/delete gate only on global `space.edit` (no `guardManage`); `ReportController::expiring` lists private-space docs unfiltered; `ActivityController` `?space=` membership unverified; `SamlController::slo` RelayState lacks the local-path regex used elsewhere (open-redirect inconsistency); Task/Process update/delete IDOR on global perms.
- **Infra:** Public seeded-admin demo on Render (`sentinel-qms/render.yaml:43` + `ADMIN_AUTO_CREATE`); `workflow_dispatch`/composite-action input flows into `run:` shell (`cd-*.yml`, `citadel/action.yml`) — route via `env:`; ALB ingress default `0.0.0.0/0`; `paladin/docker-compose.yml` `${DB_PASS:-paladin}` weak default; CloudWatch KMS grant `Resource="*"` without EncryptionContext; missing headers/TLS in `aegis/docker/nginx.conf` & `paladin/.htaccess`; `:latest` tags in several compose files; aeromarkup Azure stack lacks CMK/private networking.

## 10. Low Findings (consolidated)

- sentinel-qms: PAT comparison is an indexed digest lookup (docs claim "constant-time" — not exploitable, but fix the comment); `RATE_LIMIT_PER_MINUTE` decoupled from window seconds; fixed-window 2× burst; XFF takes leftmost hop when `TRUST_PROXY_HEADERS` enabled.
- citadel: `escH()` (`app.js:29`) doesn't escape `'` (latent if reused in single-quoted attrs); AI `mdLite` Markdown renderer is escape-first (safe but fragile); access token in `localStorage`.
- paladin: admin custom CSS injected into `<style>` (CSS-injection residual); approval self-nomination (no separation-of-duties); SSO `default_role` allowlist includes `admin`.
- Infra: no encrypted remote TF state backend (commented out); container least-privilege (`read_only`, `no-new-privileges`, `cap_drop: ALL`, non-root) only in `citadel/server`; broad SG egress; ECS Exec enabled in prod; deprecated `X-XSS-Protection "1; mode=block"`; aegis/apex Dockerfile PID 1 runs as root; **all** actions tag-pinned (none SHA-pinned).
- Root `.gitignore` does not ignore `.env`/`*.pem`/`*.key` (only Python caches + C++ binaries) — add as a backstop.

## 11. Informational Findings / Positives

- **No real secrets committed.** All `.env*` are `.example` templates; scan hits are placeholders (`change_me`, `REPLACE_ME`), CI throwaways, the canonical AWS doc key `AKIAIOSFODNN7EXAMPLE`, the `citadel/benchmark/corpus/vuln/*` deliberate scanner fixtures, and `honeypot.html`'s intentional decoy.
- **aegis** is strong: Argon2id (`Security.php:128`), libsodium AES-256-GCM (`Security.php:202`), parameterized queries, `session_regenerate_id(true)` on login, `cookie_httponly/samesite=Strict/secure` (`index.php:58-66`), and the one `exec()` (`ComplianceController.php:782`) correctly uses `escapeshellarg`.
- **citadel/server** uses `execFile` (no shell) for all scanners and pins the resolved IP on git clone to prevent SSRF (`server.js:592`); JWT + MFA + login lockout + default-cred must-change flow.
- **C++ AES vault** is correct (AES-256-GCM, PBKDF2 100k, bounds-checked header parse at `cpp/aes-vault/aes_vault.cpp:241`); no unsafe `strcpy/sprintf/system` across the C++ tools.
- **Exemplary IaC to model everywhere:** sentinel-qms Terraform (CMK-encrypted RDS/S3/Storage, `publicly_accessible=false`, TLS enforced, Key Vault HSM + purge protection + private endpoints); citadel `deploy/azure-gov` (private ACR, double encryption, shared-key disabled) and its non-root read-only-FS container; `sentinel-qms/.github/workflows/security-scan.yml` (gitleaks + checkov + tfsec + pip-audit + npm audit + trivy + CodeQL).
- **paladin** is otherwise disciplined: consistent `Security::h()` escaping, `json_encode` with `JSON_HEX_TAG|JSON_HEX_AMP`, CSRF on all POST/AJAX, parameterized SQL, exemplary `AttachmentController`/`ProfileController`/`AdminController`.

---

## 12. Attack Surface Review
Internet-facing surfaces: GitHub Pages static site (no server execution — risk is leaked secrets/false-auth), and any deployed back-end (Render/AWS/Azure). **Shodan/exposure concerns:** apex/aeromarkup Postgres on `0.0.0.0:5432`; Azure App Gateway plaintext HTTP listener; ALB `0.0.0.0/0` defaults; citadel `/api/health` (intended) and sentinel `/health` returning `database.error` string (minor info disclosure). No Swagger/GraphQL introspection or metrics endpoints are exposed unauthenticated. OpenAPI specs exist (`citadel/server/openapi.yaml`, sentinel) but are served as static docs.

## 13. Red Team Findings (attacker narrative)
- **Initial access:** forge an `apex` admin JWT using the known dev secret (C1); or reuse the published `RootAdmin@2026!` (H2); or land a stored-XSS via paladin SVG (H3) and ride an admin session.
- **Privilege escalation:** apex default-PIN bypass (H1); paladin SSO `default_role=admin` misconfig; paladin IDOR to read other tenants' private content (H4).
- **Lateral / data exfil:** sentinel localStorage token theft after any XSS (H6); compliance-copilot cross-tenant read of all controls/evidence (H8); exposed DBs (H7-infra).
- **Resource abuse / cost:** unauthenticated AI relay (H7) for unbounded Anthropic spend.
- **Detection evasion:** mutable-ref CI action could tamper SARIF (H10); no centralized immutable audit log across apps.
- **Nation-state / insider:** lack of SLSA provenance (H9) enables image substitution; no separation-of-duties on paladin approvals.

## 14. Architecture Review
Mono-repo with **no shared security baseline** — each app reimplements auth, crypto, headers, and rate limiting at different quality levels. Recommend a shared "platform security" library/standard (the aegis `Security`/`Auth` classes and citadel container/IaC patterns are the reference). Centralize: session/token policy, output encoding, CSP, audit logging, and a single hardened base image.

## 15. Authentication Review
Strong: aegis (Argon2id + TOTP), sentinel (bcrypt + DB login throttle + MFA), citadel (JWT + MFA + lockout + must-change). Weak: apex (hardcoded/forgeable secret), static site (client-side fake auth). Standardize on Argon2id/bcrypt, short access + HttpOnly refresh, server-side revocation, and fail-closed secret loading.

## 16. Authorization Review
aegis (`requirePermission`) and citadel server-side checks are solid; sentinel RBAC is complete (page-level + granular `module:action` + record sharing). **paladin's object-level authz is the main gap (H4).** compliance-copilot RLS lacks tenant scoping (H8). Adopt deny-by-default object-level checks everywhere.

## 17. Session Management Review
aegis: `session_regenerate_id(true)`, httponly/secure/SameSite=Strict — exemplary. sentinel/citadel: JWT (stateless) — add `jti` revocation (H6). Static site: localStorage "sessions" are non-authoritative.

## 18. API Security Review
sentinel PAT design is strong (256-bit entropy, SHA-256 at rest, scope enforcement, fail-closed). Gaps: in-memory rate limiting (H5), unauthenticated AI relay (H7), Content-Disposition/upload-type handling (Medium). Add gateway rate limiting, auth on all routes, and magic-byte upload validation.

## 19. Database Review
Parameterized queries throughout aegis/paladin/sentinel (SQLAlchemy ORM; bound params). sentinel validates `DB_SCHEMA` against `[A-Za-z0-9_]` before DDL. Risks: exposed DB ports + weak creds (apex/aeromarkup), missing tenant scoping (compliance-copilot). GovCloud RDS is CMK-encrypted and private.

## 20. Infrastructure Review
Bimodal: sentinel/citadel IaC is exemplary (CMK, private endpoints, TLS, encrypted state intent); apex/aeromarkup are demo-grade (public DB, no WAF, plaintext fallback). See §8 H10 and §9.

## 21. Docker Review
citadel/server is the model (multi-stage, non-root uid, `read_only`, `cap_drop: ALL`, `no-new-privileges`, tmpfs noexec, resource limits). aegis/apex Dockerfiles keep PID 1 as root; several compose files use `:latest` and weak/hardcoded creds. Propagate citadel hardening everywhere.

## 22. Kubernetes Review
sentinel-qms k8s base/helm sets `runAsNonRoot`, `runAsUser`, `readOnlyRootFilesystem: true`, `allowPrivilegeEscalation: false` — good. Add NetworkPolicies (default-deny), PodSecurity admission (restricted), and resource quotas if not present.

## 23. Dependency Review
Node (citadel): express ^4.19, multer ^1.4.5-lts, adm-zip ^0.5.12, pg, ioredis, @anthropic-ai/sdk — reasonably current; `adm-zip` + uploads warrants zip-slip verification. Python (sentinel): FastAPI/SQLAlchemy 2.x/passlib/python-jose — current. CI runs `npm audit`/`pip-audit`/trivy in sentinel (but soft-fail). **Make dependency scans gating**, and add Dependabot/renovate across all apps.

## 24. Supply Chain Review
**Largest systemic gap.** No SBOM, no provenance, no image signing on prod builds (H9); actions tag-pinned not SHA-pinned; one mutable `@master` action (H10). Implement SLSA Level 2+: SHA-pin actions, generate+attest SBOMs, cosign images, enforce `id-token` provenance, and verify signatures at deploy.

## 25. Logging & Audit Review
Per-app audit exists (aegis, paladin AdminController logs, sentinel audit on auth/PAT). **No centralized, tamper-evident, time-synced audit aggregation** across apps — required for NIST AU family / CMMC AU / HIPAA §164.312(b). Ship logs to a write-once store (CloudWatch Logs with object-lock/immutability or SIEM).

## 26. AI Security Review
- **compliance-copilot:** unauthenticated AI relay (H7, LLM10 Unbounded Consumption); user-controlled prompt is the user's own (no direct injection vuln) but **add auth, rate limit, max-tokens, and output handling**.
- **citadel:** AI "explain & fix" output rendered via escape-first `mdLite` (safe; keep escaping invariant; never allow AI-supplied `href/src`). `ANTHROPIC_API_KEY` is server-side env only — good.
- General: validate/encode all model output, log AI calls, and never feed AI output into `eval`/HTML sinks.

## 27. Compliance Matrix (summary)

| Framework | Status | Key gaps |
|---|---|---|
| NIST 800-171 r2 | Partial | AU (central audit), AC (paladin/Supabase object-level authz), SI (supply chain), IA (apex secret) |
| NIST 800-53 Mod | Partial | AU-2/6/9, SC-7 (edge/WAF), CM-7, SR (supply chain), SA-11 |
| NIST SSDF | Partial | PS.2/PW.4/RV — provenance, signing, gating scans |
| CMMC 2.0 L2 | Not yet | AC.L2, AU.L2, SC.L2, SI.L2 gaps |
| ISO 27001:2022 | Partial | A.5.15 (access), A.8.16 (monitoring), A.8.28 (secure coding consistency), A.5.23 (cloud) |
| HIPAA Security Rule | Not yet | §164.312(a)(1) access, (b) audit, (e)(1) transmission (plaintext HTTP) |
| SOC 2 Type II | Partial | CC6 (logical access), CC7 (monitoring), CC8 (change/provenance) |
| OWASP ASVS L2 | Partial | V1/V4 (authz IDOR), V5 (XSS sanitizer), V7 (logging) |
| EO 14028 / SLSA | Gap | SBOM, provenance, signing |

## 28. NIST 800-171 Gaps
3.1.x access control — fix paladin IDOR (H4), Supabase tenant RLS (H8), apex bypass (H1). 3.3.x audit — centralize immutable logs. 3.4.x config — non-root containers, remove dev defaults. 3.5.x IA — fail-closed secrets (C1). 3.13.x SC — WAF/TLS at edge (C2/H10). 3.14.x SI — gating dep/SAST scans + provenance.

## 29. CMMC Gaps
L2 practices AC.L2-3.1.1/.2 (least privilege/object authz), AU.L2-3.3.1/.2 (central audit), IA.L2-3.5.10 (no hardcoded/weak secrets), SC.L2-3.13.8 (transmission confidentiality — plaintext HTTP), SI.L2-3.14.1 (flaw remediation/supply chain).

## 30. ISO 27001 Gaps
A.5.15/5.18 access management (IDOR/tenant), A.8.9 config mgmt (dev defaults), A.8.16 monitoring (central audit), A.8.24 cryptography (consistent secret mgmt), A.8.28 secure coding (sanitizer + helper consistency), A.5.23 cloud services (WAF/edge).

## 31. HIPAA Gaps
Access control §164.312(a) — object-level/tenant authz; Audit controls §164.312(b) — central immutable logs; Integrity §164.312(c); Transmission security §164.312(e) — eliminate plaintext HTTP listener (H10) and enforce HSTS in all envs.

## 32. AS9100 / ISO 9001 / ISO 42001 Gaps
Document control & traceability are well-served by paladin/sentinel-qms QMS features, but **change-management provenance** (signed builds, immutable audit) is required for AS9100D configuration control. ISO 42001 (AI management): add an AI use policy, logging, and human-oversight controls around the AI relays.

## 33. OWASP Findings
- A01 Broken Access Control — paladin IDOR (H4), Supabase RLS (H8).
- A02 Crypto Failures — apex hardcoded key (C1), plaintext HTTP (H10).
- A03 Injection — paladin SVG stored XSS (H3); no SQLi found.
- A05 Misconfiguration — apex default pins (H1), dev-mode demos, missing headers.
- A07 Auth Failures — apex (C1/H1), static fake auth (H2).
- A08 Integrity Failures — no provenance/signing (H9).
- A09 Logging Failures — no central audit (§25).
- API4/Unbounded & LLM10 — unauthenticated AI relay (H7).

## 34. MITRE Mapping
CWE-798 (hardcoded creds, C1/H2), CWE-321 (hardcoded key, C1), CWE-639 (IDOR, H4/H8), CWE-79 (XSS, H3), CWE-770 (resource exhaustion, H7), CWE-319 (cleartext transmission, H10), CWE-494 (download w/o integrity check, H9). ATT&CK: T1078 Valid Accounts (C1/H2), T1190 Exploit Public-Facing App (C2/H3), T1552 Unsecured Credentials, T1199 Trusted Relationship (supply chain).

## 35. Technical Debt
Divergent per-app auth/crypto/headers; two XSS escape helpers in citadel with different rules; demo defaults in shippable artifacts; non-SHA-pinned actions; soft-fail security scans; root `.gitignore` gaps.

## 36. Performance Improvements
Replace in-memory rate limiter with Redis (also fixes H5); add DB connection pooling limits; cache compliance reference data; paginate large list/search endpoints (paladin/aegis).

## 37. Scalability Improvements
Move all per-process state (rate limits, sessions) to shared stores; horizontal-scale behind a gateway; use managed Postgres with read replicas; CDN for static assets; the free Render plans cap real scale — promote sentinel/citadel GovCloud IaC patterns.

## 38. Enterprise Recommendations
1. Establish a shared platform-security library + base image; forbid per-app drift.
2. Centralized immutable audit logging + SIEM.
3. SLSA L2+ supply chain (SBOM, provenance, cosign, SHA-pin).
4. Gateway/WAF for all internet-facing apps; mandatory TLS + HSTS.
5. Tenant isolation model (org_id everywhere) before any multi-tenant SaaS.

## 39. Quick Wins (≤1 day each)
- Remove `RootAdmin@2026!` from `users.js`/`roles.js` (H2).
- apex: required-var secrets + prod fail-fast; set `APEX_ALLOW_DEFAULT_PINS=0` (C1/H1).
- Add auth + rate limit to `/api/ai/generate` (H7).
- SHA-pin `checkov-action@master` (H10).
- Add `.env`/`*.pem`/`*.key`/`secrets.*` to root `.gitignore`.
- Bind apex/aeromarkup Postgres to `127.0.0.1`.
- Block `<svg>` in paladin `sanitizeHtml()` (H3).

## 40. Long-Term Roadmap
Q1: kill Critical/High; shared security baseline; central audit. Q2: SLSA L2+, tenant isolation, gateway/WAF everywhere. Q3: FedRAMP/CMMC control mapping + evidence automation; DR/BCP; pen-test. Q4: ISO 27001 / SOC 2 readiness, ISO 42001 AI governance.

## 41. Prioritized Remediation Plan
| # | Finding | Sev | Effort | Priority |
|---|---|---|---|---|
| 1 | apex hardcoded JWT/DB secret + fail-fast (C1) | Critical | S | P0 |
| 2 | aeromarkup public app, no WAF/TLS (C2) | Critical | M | P0 |
| 3 | apex default-PIN bypass (H1) | High | S | P0 |
| 4 | Published admin cred in static JS (H2) | High | S | P0 |
| 5 | paladin SVG stored XSS (H3) | High | S | P1 |
| 6 | paladin IDOR / private-space bypass (H4) | High | M | P1 |
| 7 | sentinel in-memory rate limit (H5) | High | M | P1 |
| 8 | sentinel localStorage tokens / revocation (H6) | High | M | P1 |
| 9 | compliance-copilot unauth AI relay (H7) | High | S | P1 |
| 10 | compliance-copilot RLS tenant scoping (H8) | High | M | P1 |
| 11 | Supply-chain provenance/signing (H9) | High | M | P2 |
| 12 | CI mutable ref / edge HTTP / IAM / DB exposure (H10) | High | M | P2 |
| 13 | Medium cluster (§9) | Medium | M | P2 |
| 14 | Low/Info (§10–11) | Low | varies | P3 |

## 42. Production Readiness Assessment
- **aegis, citadel, sentinel-qms:** ready for commercial SaaS after Medium fixes; *near* CUI/CMMC-ready after central audit + supply-chain + (sentinel) gateway rate limiting.
- **paladin:** ready after the IDOR (H4) and SVG-XSS (H3) fixes.
- **apex:** **not production-ready** until C1/H1 fixed.
- **compliance-copilot:** **not multi-tenant-ready** until H7/H8 fixed.
- **aeromarkup, static site:** demo-tier; do not position as security-bearing.
- **FedRAMP/DoD IL4+:** none ready today; sentinel/citadel GovCloud IaC is the closest foundation.

## 43. Final Recommendation
**Conditional — do not deploy `apex`, `aeromarkup`, or the static "auth" demos to any environment handling sensitive data until the Critical/High items are remediated.** The flagship apps (`aegis`, `sentinel-qms`, `citadel`, and `paladin` after its authz fixes) demonstrate genuinely strong engineering and are a sound basis for an enterprise platform. The path to CMMC/FedRAMP/HIPAA runs through: (1) eliminating the Critical/High findings, (2) a shared security baseline to stop per-app drift, (3) centralized immutable audit, (4) SLSA supply-chain provenance, and (5) tenant isolation. Prioritize P0/P1 in the next sprint; re-test with an independent pen-test before any authorization boundary.

---
*Methodology: full-repository static review across all sub-applications, IaC, CI/CD, and supply chain, performed by parallel specialized audit agents plus direct verification. Findings include concrete `file:line` evidence; "could not verify" items are flagged as risks per a zero-trust posture. This report is advisory and does not itself constitute a formal certification assessment.*
