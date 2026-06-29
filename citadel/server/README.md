# CITADEL — Deep-Scan Backend

A **Node.js 20 / Express** API service that runs **real open-source security
scanners** over uploaded code and returns the same report JSON the CITADEL SPA
already renders. This is the **production tier** referenced by
[`../README.md`](../README.md) and [`../ARCHITECTURE.md`](../ARCHITECTURE.md):
where the public demo analyzes files **entirely in the browser** with heuristic,
pattern-based engines, this backend shells out to industrial scanners for depth
suitable for an authorization decision.

> ⚠️ **Untrusted input.** Uploaded code is treated as hostile. The scanners only
> **read** files — nothing uploaded is ever executed, imported, built, or run.
> The container runs non-root with a read-only root filesystem and a single
> writable scratch directory.

---

## Demo vs. deep-scan: what's different

| | Client-side demo (`citadel/`) | Deep-scan backend (`citadel/server/`) |
|---|---|---|
| Where it runs | 100% in the browser (JSZip) | Node/Express container |
| Engines | Heuristic regex/entropy/AST-lite | Real scanners (below) + the heuristic engine |
| Data | Nothing leaves the browser | Files uploaded to the server's temp dir, scanned, discarded |
| Use | Triage, education, demos | Pre-ATO depth, CI gates, CUI-bearing review |

The deep-scan backend **does not replace** the heuristic engine — it **augments**
it. Heuristic findings and real-scanner findings are normalized to the same
finding shape and **merged** into one report.

### The real scanners and what each contributes

| Scanner | Type | Contribution to the report |
|---|---|---|
| **Semgrep** | Multi-language SAST | Data-flow-aware static analysis across many languages; the core SAST signal. |
| **Bandit** | Python SAST | Python-specific AST security checks (eval, weak crypto, shell injection, etc.). |
| **Trivy** | FS vuln + secret + misconfig | Dependency CVEs, hardcoded secrets, and IaC/Dockerfile misconfiguration. |
| **Syft** | SBOM | Generates the CycloneDX Software Bill of Materials from the uploaded tree. |
| **Grype** | Vulnerability matching | Matches the Syft SBOM / directory against vulnerability databases (CVEs). |
| **Gitleaks** | Secrets | Git-aware and content-based credential/secret detection. |
| **ClamAV** | Malware | Signature-based malware scan of every uploaded file. |

Missing scanners **degrade gracefully** — `server.js` runs whatever is installed
and omits the rest, so the API never hard-fails because one tool is absent.

---

## Architecture

```
  Browser SPA (citadel/index.html)
        │  multipart POST /api/scan  (zip or files, field "files")
        ▼
  Express server.js
        │  1. write upload to a per-request dir under $CITADEL_TMP (/tmp/citadel)
        │  2. extract archives (zip/jar/apk) into that scratch dir
        │  3. fan out scanners IN PARALLEL over the extracted tree:
        │        Semgrep · Bandit · Trivy · Syft → Grype · Gitleaks · ClamAV
        │  4. adapters (server/lib/) normalize each tool's native output into
        │     CITADEL's finding shape (ruleId, name, category, severity, cwe,
        │     confidence, file, line, snippet, remediation)
        │  5. MERGE with the heuristic engine's findings
        │  6. score, grade, and map to the 20+ compliance frameworks
        │  7. delete the scratch dir
        ▼
  Report JSON  ──►  the SPA renders it exactly as it renders a local scan
```

The **finding contract** is the one defined in
[`../ARCHITECTURE.md`](../ARCHITECTURE.md): each adapter emits
`{ ruleId, name, category, severity, cwe, confidence, file, line, snippet, remediation }`,
which means scoring, compliance mapping, charts, and exports work unchanged —
the SPA cannot tell a server finding from a browser finding.

> The Express app, `package.json`, and the adapter library under `server/lib/`
> are maintained separately. This document and the container files
> (`Dockerfile`, `.dockerignore`, `docker-compose.yml`) are the build &
> deployment runbook for that service.

---

## API reference

### `GET /api/health`

Liveness/readiness probe. Returns `200` with a small JSON body. Used by the
container `HEALTHCHECK` and by Render / Container Apps / ECS health checks.

```bash
curl -fsS http://localhost:8080/api/health
# {"status":"ok", ...}
```

### `POST /api/scan`

Multipart upload of code to scan. Field name: **`files`** (one archive or
multiple files). Returns the report JSON the SPA renders.

```bash
# Scan a zipped project
curl -sS -X POST http://localhost:8080/api/scan \
  -F "files=@./my-project.zip" \
  -o report.json

# Scan several loose files in one request
curl -sS -X POST http://localhost:8080/api/scan \
  -F "files=@./app.py" \
  -F "files=@./package.json" \
  -o report.json
```

The response is the same `report{}` shape produced by the client-side
orchestrator (`meta`, `languages`, `findings`, `sbom`, `binaries`, `quality`,
`deployment`, `scoring`, `posture`) — see `citadel/js/scanner.js`.

### Static SPA

The server also serves the CITADEL front-end from the bundled `citadel/`
directory, so opening **http://localhost:8080/** gives the full UI, which then
calls `/api/scan` instead of analyzing locally.

---

## Authentication & access control

When the deep-scan backend is present, the SPA authenticates against it with
**JWT sessions** and permission checks are enforced **server-side** — the
browser store is only a fallback for static hosting (GitHub Pages).

- **User store** — `lib/users.js`, a JSON file at `CITADEL_DATA_DIR` (default
  under `CITADEL_TMP`). Passwords are **scrypt**-hashed with per-user salts. On
  first run it seeds an admin and a JWT signing secret.
- **Sessions** — `lib/jwt.js` issues compact **HS256** JWTs (12 h expiry)
  signed with `CITADEL_JWT_SECRET`. The SPA sends `Authorization: Bearer <jwt>`.
- **Enforcement** — page-level permissions (mirroring the client's catalog) are
  checked on every protected route. `/api/scan` requires `analyze`,
  `/api/scan-url` requires `deepscan`, `/api/explain` requires `tab-aifix`;
  user-management and access-settings routes are admin-only. When the global
  *enforce* toggle is **off** (default), scans are open; flip it on in the Admin
  console once accounts are set up.

| Route | Method | Auth |
|---|---|---|
| `/api/health` · `/api/openapi.yaml` | GET | public — status + the full OpenAPI 3.0 contract |
| `/api/auth/login` · `/api/auth/refresh` | POST | public — access token + httpOnly refresh cookie |
| `/api/auth/mfa` `…/setup` `…/enable` `…/disable` `…/verify` | GET/POST | TOTP MFA self-service |
| `/api/auth/oidc/start` · `/api/auth/oidc/callback` | GET | OIDC SSO (Auth Code + PKCE) |
| `/api/auth/me` · `/api/auth/password` · `/api/auth/logout` | GET/POST | Bearer token |
| `/api/auth/settings` · `/api/branding` | GET / PATCH | GET any · PATCH admin |
| `/api/users` `…/:id` `…/:id/password` `…/:id/revoke-sessions` | GET/POST/PATCH/DELETE | admin |
| `/api/sessions` `…/:jti` · `/api/audit` · `/api/audit/verify` | GET/DELETE | admin |
| `/api/scan` · `/api/scan-url` · `/api/explain` | POST | permission-gated when enforce is on |
| `/api/scans` `…/:id` | GET/DELETE | durable history (owner-scoped) |
| `/api/dispositions` | GET / POST | shared finding triage by fingerprint (needs a DB) |

The machine-readable contract for all of the above is served at
**`GET /api/openapi.yaml`** (OpenAPI 3.0) and linked from the SPA's Docs menu.

**Auth-related environment variables**

| Variable | Default | Purpose |
|---|---|---|
| `CITADEL_DATA_DIR` | `$CITADEL_TMP/citadel` | Where `users.json` + the JWT secret persist (file mode). Point at a persistent disk. |
| `DATABASE_URL` | — | When set, users/sessions/revocations/audit/settings persist to **Postgres** (durable + shared across instances). Falls back to the file store when unset. Schema is created on boot; a manual reference lives at `citadel/database/schema.sql`. |
| `PGSSL` / `PGSSL_VERIFY` / `PGSSL_CA` | auto / off / — | TLS to Postgres. TLS auto-enables for managed providers; certificate **verification is opt-in** via `PGSSL_VERIFY=1` or by supplying the provider CA in `PGSSL_CA` (PEM string or path). Default is permissive (`rejectUnauthorized:false`) so managed chains keep working. |
| `REDIS_TLS_VERIFY` / `REDIS_TLS_CA` | off / — | Same opt-in TLS verification for a `rediss://` `REDIS_URL`. |
| `CITADEL_ALLOW_OPEN` | — | A fresh store now defaults to **enforcement ON** (secure-by-default). Set `1` to ship an **open** instance (no auth) and to silence the related startup warning — only for trusted/demo use. |
| `CITADEL_AIRGAP` / `CITADEL_NO_EGRESS` | — | Set `1` for the **air-gap / no-egress profile**: hard-disables AI remediation so scanned source code can never be transmitted to an external LLM. Required when reviewing CUI / ITAR / export-controlled / proprietary code. (`/api/health` reports `airgap:true`.) |
| `CITADEL_METRICS_TOKEN` | — | Bearer token required to read `GET /metrics`. When unset, `/metrics` is restricted to **loopback** (returns 404 otherwise) — no longer an anonymous recon surface. |
| `PGSSL_VERIFY` (default behavior changed) | secure | Postgres TLS certificate verification now **defaults ON**, except for known managed providers (Render/Supabase/RDS) which stay permissive with a warning. Set `0` to force off, `1` (or `PGSSL_CA`) to force on. |
| `CITADEL_JWT_SECRET` | random (or seeded into the store) | HS256 signing key. Set a stable one so sessions survive restarts. |
| `CITADEL_DATA_KEY` | — | 32-byte key (64 hex chars or base64) for **at-rest secret encryption** (AES-256-GCM). When set, TOTP seeds and the JWT signing key are sealed (`enc:v1:…`) before they touch the JSON store or Postgres, so a leaked store/backup doesn't directly yield 2FA seeds or a session-minting key. Unset = stored plaintext (legacy); reads transparently accept both, so enabling it migrates data lazily on next write. Inject from a secrets manager. |
| `CITADEL_FIPS` | — | Set `1` to run in **FIPS 140 mode**: requests the OpenSSL FIPS provider at boot and switches password hashing from scrypt (not FIPS-approved) to **PBKDF2-HMAC-SHA256** (SP 800-132). Only enforced on a FIPS-validated OpenSSL build; on a stock build it logs a warning and continues non-FIPS (check `fips.active` in `/api/health`). Password hashes are self-describing, so both KDFs verify and you can enable it without resetting accounts. |
| `CITADEL_PBKDF2_ITER` | `600000` | PBKDF2 iteration count for FIPS-mode password hashing (the value is embedded per-hash, so changing it doesn't break existing logins). |
| `CITADEL_MULTITENANT` | — | Set `1` (with `DATABASE_URL`) to enable **schema-per-tenant** multi-tenancy: each tenant gets its own Postgres schema (`citadel_t_<slug>`) holding a full copy of the tables, so tenants are physically isolated. See _Multi-tenancy_ below. Default off = single-tenant (unchanged). |
| `CITADEL_BASE_DOMAIN` | — | Apex domain for subdomain tenant resolution (`acme.<base>` → tenant `acme`). Tenants can also be selected per request via the `X-Citadel-Tenant` header or `?tenant=` param. |
| `CITADEL_SUPERADMIN_TOKEN` | — | Bearer token gating the operator-only tenant-provisioning endpoints (`GET`/`POST /api/tenants`). When unset those routes are loopback-only. They 404 entirely unless multi-tenancy is enabled. |
| `CITADEL_ACCESS_TTL` / `CITADEL_REFRESH_TTL` | `1800` / `2592000` | Access-token (30 m) and refresh-token (30 d) lifetimes, in seconds. |
| `CITADEL_ADMIN_EMAIL` / `CITADEL_ADMIN_PASSWORD` | `admin@citadel.local` / `citadel-admin` | First-boot admin. The default password is flagged **must-change**; change it on first login. |
| `CITADEL_AUDIT_SINK_URL` / `CITADEL_AUDIT_SINK_TOKEN` | — | Forward every audit event to an HTTP collector (Splunk HEC / SIEM webhook). The audit log is also **tamper-evident**: each event is hash-chained to the previous (`hash = SHA-256(prev + record)`), so altering or deleting any past record is detectable. `GET /api/audit/verify` (admin) re-walks the chain and reports the first break, if any. |
| `CITADEL_NOTIFY_URL` / `CITADEL_NOTIFY_TOKEN` / `CITADEL_NOTIFY_ON` | After each scan whose worst severity meets `CITADEL_NOTIFY_ON` (critical\|high\|medium\|low\|any, default critical), POST a Slack-compatible summary to the webhook. |
| `REDIS_URL` | — | When set, rate limiting + brute-force lockout use **Redis** (shared across instances for horizontal scaling). Falls back to an in-memory limiter when unset. |
| `CITADEL_SCAN_TIMEOUT_MS` | `30000` | Wall-clock deadline for the heuristic SAST pass. On `/api/scan` and `/api/scan-url` it runs in an **isolated worker thread** that is terminated if it exceeds this — a defense against catastrophic-backtracking (ReDoS) inputs. On timeout the scan still completes with the external-scanner findings and a `meta.warnings` note. |
| `CITADEL_SCAN_ISOLATION` | auto | `1` forces worker isolation everywhere, `0` disables it. **Auto (default):** the server isolates upload/URL scans only when the host has enough RAM — worker isolation loads a second copy of the engine and can OOM a small instance (a 512 MB free tier), which surfaces as a **502**. |
| `CITADEL_ISOLATION_MIN_MEM_MB` | `900` | Below this total RAM, auto mode runs the heuristic pass in-process instead of a worker, to avoid OOM on small instances. |
| `SCAN_CONCURRENCY` | `2` | How many external scanners run at once. Semgrep, Trivy, Grype, ClamAV and Checkov are each CPU/RAM-heavy; launching all 11 simultaneously can OOM-kill a small instance (surfacing as a **502**). The conservative default of `2` keeps peak memory low; set `1` on a 512 MB tier, or raise it on a big box for faster scans. |
| `CITADEL_MAX_TOTAL_BYTES` | `67108864` (64 MB) | Per-scan memory budget for loaded file content. Past this, files are still counted but not read into memory/scanned — bounds memory on very large repos. |

**Observability.** Logs are structured JSON lines; Prometheus metrics are at
`GET /metrics`. **Tracing** is optional OpenTelemetry: set
`OTEL_EXPORTER_OTLP_ENDPOINT` (export to Jaeger/Tempo/Datadog/Honeycomb) or
`CITADEL_TRACING=console` for local debug. The OTel packages are optional and
omitted from the default image — build the container with
`--build-arg CITADEL_WITH_TRACING=1` to include them.

**MFA (TOTP)** is self-service per user (Admin console → Settings → *Two-factor authentication*); no configuration required.

**SSO / OIDC** — set these to enable "Sign in with SSO" (works with Entra ID, Okta, Google, Auth0, Keycloak — any OIDC provider):

| Variable | Purpose |
|---|---|
| `OIDC_ISSUER` | Discovery base, e.g. `https://login.microsoftonline.com/<tenant>/v2.0` or `https://<org>.okta.com` |
| `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | Confidential client credentials from the IdP app registration |
| `OIDC_REDIRECT_URI` | `https://<your-app>/api/auth/oidc/callback` (register this in the IdP) |
| `OIDC_SCOPES` | Default `openid email profile` |
| `OIDC_ADMIN_EMAILS` | Comma list mapped to the **admin** role on first login |
| `OIDC_DEFAULT_ROLE` | Role for everyone else (default `viewer`) |
| `OIDC_ALLOWED_DOMAINS` | Comma list of permitted email domains (others are rejected) |
| `OIDC_POST_LOGIN` | Where to land after sign-in (default `/`) |

Flow: Authorization Code + PKCE; the `id_token` signature is verified against the provider JWKS (RS256/PS256), and users are just-in-time provisioned by email. Sessions issued via SSO use the same access/refresh tokens as password login.

> **Persistence.** The store writes to `CITADEL_DATA_DIR`. On a host with a
> persistent volume (a paid Render disk, an Azure/AWS volume), accounts and the
> JWT secret survive restarts. On an **ephemeral filesystem (e.g. Render's free
> tier)** there is no persistent disk: the store resets on every deploy, so the
> seeded admin and any users you create are recreated each time. Set a fixed
> `CITADEL_JWT_SECRET` in the dashboard to at least keep issued sessions valid
> across redeploys; durable accounts require a persistent volume.

```bash
# Log in and call a gated endpoint
TOKEN=$(curl -sS -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@citadel.local","password":"citadel-admin"}' | jq -r .token)

curl -sS -X POST http://localhost:8080/api/scan \
  -H "Authorization: Bearer $TOKEN" \
  -F "files=@./my-project.zip" -o report.json
```

---

## Multi-tenancy (schema-per-tenant)

Opt-in with `CITADEL_MULTITENANT=1` + `DATABASE_URL`. Each tenant gets its own
**Postgres schema** (`citadel_t_<slug>`) containing a full copy of the `citadel_*`
tables, so tenants are **physically isolated** — a query scoped to one tenant's
schema cannot read another's rows even if a `WHERE` clause is omitted. A
`citadel_tenants` registry in the public schema maps slug → schema.

- **Provisioning** is an operator action (not an in-app role): `POST /api/tenants`
  `{ "slug": "acme", "name": "Acme Corp" }` creates the schema and tables;
  `GET /api/tenants` lists them. Both are gated by `CITADEL_SUPERADMIN_TOKEN`
  (Bearer) or loopback, and 404 unless multi-tenancy is enabled.
- **Request routing** is wired into the live request path: a middleware resolves
  the tenant from the `X-Citadel-Tenant` header, a `?tenant=` param, or an
  `acme.<CITADEL_BASE_DOMAIN>` subdomain, then runs the **entire** request (auth
  decode included) inside that tenant's schema scope. Slugs are strictly validated
  and the derived schema name is quote-validated before it ever reaches SQL, so a
  tenant identifier cannot inject SQL or escape its schema. Internally,
  `db.runInTenant(schema, fn)` scopes `search_path` for the duration of each query
  and resets it on release, so a pooled connection can't leak one tenant's
  `search_path` to the next.
- **Per-tenant caches.** The auth/session in-memory caches (`lib/users.js`,
  `lib/sessions.js`) are keyed by the ambient tenant, and the middleware
  `ensureLoaded()`s a tenant's users + sessions before handlers run — so one
  tenant's cached users/sessions can never be served to another. Each tenant also
  carries its own JWT signing secret (in its own `citadel_settings`), so a token
  minted for tenant A fails signature verification under tenant B. Infra/operator
  routes (`/api/health`, `/api/tenants`) and the static SPA are tenant-agnostic.

> **Operational notes for production.** (1) Tenant resolution must be driven by a
> trustworthy signal — terminate the `X-Citadel-Tenant` header at your proxy (or
> rely on the subdomain) so clients can't select another tenant. (2) Provision
> each tenant (`POST /api/tenants`) before routing traffic to it; an unknown slug
> gets a 404. (3) The public schema still holds an inert default store (seeded at
> boot) and the tenant registry; no tenant request is ever served from it. The
> default single-tenant deployment is completely unaffected — with no ambient
> tenant scope, `db.query` and both caches behave exactly as before.

---

## Build & run locally

Everything is built from the **repository root** because the image copies both
the SPA (`citadel/`) and the backend (`citadel/server/`).

### With Docker directly

```bash
# from the repo root
docker build -f citadel/server/Dockerfile -t citadel-server .
docker run --rm -p 8080:8080 \
  --read-only \
  --tmpfs /tmp/citadel:size=2g \
  --cap-drop ALL \
  --security-opt no-new-privileges:true \
  citadel-server
```

### Published image — provenance & signature verification

Tagged releases publish a signed image to **GHCR** via
[`.github/workflows/release-image.yml`](../../.github/workflows/release-image.yml).
Each push attaches an **SPDX SBOM + SLSA build provenance** (BuildKit
attestations), a **CycloneDX SBOM** (cosign attestation), and a GitHub-native
build-provenance attestation, and the image digest is **signed keyless with
cosign** (Sigstore — no long-lived key; the signer identity is the release
workflow itself). Before running a pulled image, verify it:

```bash
IMG=ghcr.io/jessicarojas1/citadel-server   # pin to a @sha256:… digest in prod

# 1) Signature is valid AND was produced by this repo's release workflow:
cosign verify "$IMG" \
  --certificate-identity-regexp '^https://github.com/jessicarojas1/jessicarojas1.github.io/' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'

# 2) Inspect the attached SBOM / provenance attestations:
cosign download sbom "$IMG"            # or: cosign verify-attestation --type cyclonedx "$IMG" …
gh attestation verify oci://"$IMG" --owner jessicarojas1   # SLSA provenance
```

### With Docker Compose (recommended)

The compose file already wires up the read-only FS, tmpfs scratch, capability
drop, healthcheck, and the persistent ClamAV DB volume.

```bash
# from the repo root
docker compose -f citadel/server/docker-compose.yml up --build
```

Then open **http://localhost:8080/** and upload a project, or hit the API with
the `curl` examples above.

First run note: Trivy and Grype download their vulnerability databases on first
use, and ClamAV may refresh signatures — the first scan can be slow. See
**Operational notes**.

---

## Deploy to Render.com

CITADEL's deep-scan backend deploys as a **Docker Web Service** on Render.

**Render service settings**

| Setting | Value |
|---|---|
| Environment | Docker |
| Dockerfile path | `citadel/server/Dockerfile` |
| Docker build context | repository root (`.`) |
| Port | `8080` (Render injects `$PORT`; the server already honours it) |
| Health check path | `/api/health` |
| Instance type | **Standard or larger (≥ 1 GB RAM, ideally 2 GB+)** |

> ⚠️ **The free tier is almost certainly too small.** The bundled toolchain
> (Semgrep + Trivy + Grype + ClamAV) is CPU- and memory-heavy; ClamAV alone
> wants ~1 GB resident. Use a **paid instance** with at least 1 GB RAM (2 GB+
> recommended) or scans will OOM / time out.

Add a `render.yaml` blueprint at the repo root (or merge into the existing one):

```yaml
services:
  - type: web
    name: citadel-deep-scan
    runtime: docker
    dockerfilePath: citadel/server/Dockerfile
    dockerContext: .
    plan: standard          # NOT free — needs ≥1GB RAM (2GB+ recommended)
    healthCheckPath: /api/health
    envVars:
      - key: NODE_ENV
        value: production
      - key: PORT
        value: "8080"
      # Set a fixed value in the dashboard so JWT sessions survive redeploys.
      - key: CITADEL_JWT_SECRET
        sync: false
```

Render terminates TLS for you and routes to port 8080. The container's own
healthcheck and Render's `/api/health` probe are complementary.

> **Account persistence.** Render's filesystem is ephemeral, so the user store
> resets on every deploy/restart and the server logs a `cannot persist user
> store` warning. To keep accounts durable, attach a **persistent disk** (paid
> tiers) and point `CITADEL_DATA_DIR` at its mount path — e.g. a 1 GB disk at
> `/var/lib/citadel` with `CITADEL_DATA_DIR=/var/lib/citadel`. The container
> runs as numeric `USER 10001`, so Render chowns the disk mount to that UID with
> no extra setup. On the **free tier** (no disks), set a fixed
> `CITADEL_JWT_SECRET` so at least issued sessions survive redeploys.

---

## Government cloud

The **same image** is what the FedRAMP-High / IL4–IL5 Infrastructure-as-Code
under [`../deploy/`](../deploy/) deploys — there is no separate build:

- **Azure Government** → Azure Container Apps (Bicep, `../deploy/azure-gov/`)
- **AWS GovCloud (US)** → ECS Fargate (Terraform, `../deploy/aws-gov/`)

In those environments give the task **more CPU and RAM** than the local
defaults (the scanners are heavier under real workloads), and **uploads are
quarantined** in immutable, KMS-encrypted object storage per that IaC rather
than living only in the container's tmpfs. The shared hardening posture
(non-root, read-only root FS, dropped capabilities, vault-sourced secrets,
WAF-only ingress, image scan-on-push) is documented in
[`../deploy/README.md`](../deploy/README.md) and cross-walked to NIST SP 800-53
Rev 5 / CMMC 2.0.

---

## Operational notes

- **ClamAV signature DB.** The image seeds the DB at build time, but signatures
  age fast. Run `freshclam` on a schedule (cron/sidecar) or at startup, and keep
  the `/var/lib/clamav` volume persistent (compose already does this) so updates
  survive restarts. A stale or missing DB degrades gracefully — ClamAV is simply
  skipped.
- **Trivy / Grype vuln DBs.** Both download on first use. For air-gapped Gov
  deploys, pre-pull at deploy time (`trivy --download-db-only`, `grype db
  update`) and mount the DB cache, or mirror it in-boundary.
- **Scan timeouts.** Each scanner is run with a bounded timeout so a pathological
  input can't hang a request; a tool that exceeds its budget is dropped from the
  merged report rather than failing the whole scan.
- **Max upload size.** Uploads are capped (multipart limit) to bound disk/CPU.
  Tune it to your largest expected project; oversized uploads are rejected.
- **Untrusted code is never executed.** Scanners read source/artifacts only —
  no install, build, or run step touches uploaded code. Combined with non-root +
  read-only root FS + dropped capabilities + `no-new-privileges`, a malicious
  upload cannot escalate.
- **Scaling / workers.** Scans are CPU/RAM bound and bursty. Prefer horizontal
  scaling (more replicas) over one huge instance, and consider a job/worker
  queue so a long scan doesn't block the request thread. Set resource limits
  (see `docker-compose.yml`) so one scan can't OOM its neighbours.
- **Cost.** Because each instance must carry the full toolchain and ≥1 GB RAM,
  this tier costs meaningfully more than the static demo. Run the **free,
  client-side demo** for casual triage; reserve the deep-scan backend for work
  that needs real-scanner depth.

---

## Limitations & security considerations

- **Results depend on installed tools and DB freshness.** A skipped scanner or a
  stale CVE/signature DB means lower coverage — monitor that `freshclam` and the
  Trivy/Grype DB updates are succeeding.
- **No tool is complete.** SAST/secret/SBOM scanners produce false positives and
  false negatives; treat findings as triage input, not verdicts. For an ATO,
  have results reviewed by a qualified assessor.
- **Defense in depth on uploads.** Even though code is never executed, treat the
  scratch dir as a blast zone: keep it on `tmpfs`, never on a shared/persistent
  mount, and quarantine retained uploads (the Gov IaC does this with immutable,
  encrypted storage).
- **Resource exhaustion is the main DoS vector.** Enforce upload-size caps, scan
  timeouts, and per-container resource limits; rate-limit `/api/scan` at the
  edge/WAF in production.
- **Supply chain.** Pin scanner versions (the Dockerfile does) and pin the base
  image by digest in production; scan the resulting image on push (`RA-5`,
  `SI-2`) as the Gov IaC requires. Tagged releases are published **signed**
  (keyless cosign) with **SBOM + SLSA provenance** attestations — verify them
  before deploy (see _Published image_ above) so only attested artifacts run
  (`SR-3`, `SR-4`, `SR-11`; SSDF PS.3 / SLSA build provenance).

_Built by Jessica Rojas. Real scanners assist — verify findings before acting._
