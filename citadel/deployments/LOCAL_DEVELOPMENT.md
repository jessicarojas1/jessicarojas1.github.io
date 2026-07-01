# CITADEL — Local Development Deployment

**Audience:** developers running CITADEL on a laptop/workstation for feature work, adapter
development, and manual verification of the deep-scan backend.

CITADEL ships in two modes; this guide covers both and the fast path to a fully working
deep-scan backend with every real scanner installed.

- **Mode 1 — client-side SPA demo.** The analyzer (`citadel/index.html`) runs 100 % in the
  browser with `JSZip`. Nothing is uploaded; no server is required. Best for UI work and quick
  triage.
- **Mode 2 — deep-scan backend.** A Node 20 / Express API (`citadel/server/`) that shells out
  to **real** open-source scanners — Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV
  (plus Checkov, OSV-Scanner, Hadolint, opt-in CodeQL) — merges their findings with the
  heuristic engine, and serves the SPA. This is the tier you build/test in Docker.

Related guides: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES.md](KUBERNETES.md) · [AWS.md](AWS.md) · [AZURE.md](AZURE.md) ·
[AIRGAPPED.md](AIRGAPPED.md). Env reference: [`../docs/ENV.md`](../docs/ENV.md).

---

## 1. Deployment architecture

| Concern | Mode 1 (SPA demo) | Mode 2 (deep-scan backend) |
|---|---|---|
| Runtime | Any static web server / `file://` | Node 20 in a Docker container |
| Analysis | Heuristic engine in-browser (JSZip) + OSV.dev CVE lookups | Real scanners + heuristic engine, merged server-side |
| Upload path | Nothing leaves the browser | `POST /api/scan` (multipart, field `files`) into `$CITADEL_TMP` |
| Auth | Per-browser local store (fallback) | JWT (HS256) sessions, server-enforced RBAC |
| Health | n/a | `GET /api/health` |
| State | `localStorage` | File store (`CITADEL_DATA_DIR`) or Postgres (`DATABASE_URL`) |
| RAM | negligible | **≥ 2 GB** (ClamAV loads a ~1.4 GB signature DB) |

The backend image is built **from the repository root** because it copies both the SPA
(`citadel/`) and the backend (`citadel/server/`).

## 2. Topology

```
Mode 1 — SPA demo
  ┌─────────────┐    JSZip parse + OSV.dev CVE lookup (client-side)
  │  Browser    │──────────────────────────────────────────────► osv.dev (HTTPS)
  └─────────────┘

Mode 2 — deep-scan backend (docker compose)
  ┌─────────────┐   multipart POST /api/scan (field "files")   ┌────────────────────────────┐
  │  Browser    │ ───────────────────────────────────────────► │ citadel-server (Node 20)   │
  │  (SPA)      │ ◄─────────────────── report JSON ──────────── │  Express :8080             │
  └─────────────┘                                               │  ├─ /tmp/citadel (tmpfs)   │
                                                                │  │   extract + scan (RO FS)│
                                                                │  ├─ scanners: semgrep,     │
                                                                │  │   bandit, trivy, syft,  │
                                                                │  │   grype, gitleaks,      │
                                                                │  │   clamscan, checkov,…   │
                                                                │  └─ /var/lib/clamav (vol)  │
                                                                └────────────────────────────┘
                                        DATABASE_URL (optional) ─────► Postgres (file store if unset)
```

## 3. Prerequisites

| Tool | Version | Needed for |
|---|---|---|
| Python 3 **or** any static server | 3.8+ | Mode 1 (serve the SPA) |
| Docker Engine + Compose v2 | 24+ / v2 | Mode 2 (recommended path) |
| Node.js | ≥ 18 (image uses 20) | Mode 2 native (without Docker) |
| `curl`, `jq` | any | Verification |
| Free RAM | **≥ 2 GB** for the container | ClamAV signature DB + Semgrep/Trivy/Grype |

Native Mode 2 additionally needs the scanner binaries on `PATH` (`semgrep`, `bandit`, `trivy`,
`syft`, `grype`, `gitleaks`, `clamscan`, …). If a scanner is missing it is **skipped**
gracefully — Docker is strongly recommended because it bundles and pins all of them.

## 4. Identity & credentials

Local dev is single-admin and secure-by-default. Nothing is required to start.

- With **no** env set, the backend runs in single-admin, in-memory mode with a **random**
  admin password (printed to the log in production mode) or the seeded default in dev.
- Default first-boot admin (from [`../server/README.md`](../server/README.md)):
  `CITADEL_ADMIN_EMAIL=admin@citadel.local` / `CITADEL_ADMIN_PASSWORD=citadel-admin`
  (flagged **must-change** on first login).
- **Never commit secrets.** Use a local `.env` (git-ignored) with placeholders derived from
  `.env.example`. Prefer generating throwaway secrets: `openssl rand -hex 32`.

## 5. Environment variables

Everything is optional locally; sensible defaults apply. The most relevant for dev:

| Variable | Example | Purpose |
|---|---|---|
| `PORT` | `8080` | HTTP listen port |
| `NODE_ENV` | `development` | Leave unset/`development` locally; `production` enables hardening |
| `CITADEL_JWT_SECRET` | `$(openssl rand -hex 32)` | HS256 session signing key; set to persist sessions across restarts |
| `CITADEL_ADMIN_EMAIL` | `admin@citadel.local` | First-boot admin email |
| `CITADEL_ADMIN_PASSWORD` | `citadel-admin` | First-boot admin password (change on first login) |
| `CITADEL_TMP` | `/tmp/citadel` | Scratch dir for untrusted upload extraction (tmpfs recommended) |
| `CITADEL_DATA_DIR` | `$CITADEL_TMP/citadel` | File-backed store for users + JWT secret (no-DB mode) |
| `DATABASE_URL` | `postgres://citadel:pass@localhost:5432/citadel` | Optional Postgres for durable/shared state |
| `SCAN_CONCURRENCY` | `2` | External scanners run at once; lower to `1` on small laptops |
| `ANTHROPIC_API_KEY` | `sk-ant-…` | Optional — enables `/api/explain` AI "Explain & fix" |
| `CITADEL_AI_MODEL` | `claude-opus-4-8` | Model id for AI remediation |
| `LOG_LEVEL` | `debug` | Verbose local logs |

See [`../docs/ENV.md`](../docs/ENV.md) for the full catalog (OIDC, FIPS, multi-tenancy,
tracing, notifications, upload caps).

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| `MAX_UPLOAD_BYTES` | `157286400` (150 MB) | Max single upload |
| `CITADEL_MAX_UNZIP_BYTES` | `524288000` (500 MB) | Decompression-bomb cap (inflated bytes) |
| `CITADEL_MAX_UNZIP_ENTRIES` | `50000` | Archive entry-count cap |
| `SCAN_TIMEOUT_MS` | `180000` | Per-external-scanner timeout |
| `CITADEL_SCAN_TIMEOUT_MS` | `30000` | Heuristic SAST pass deadline (ReDoS guard) |
| `CITADEL_SCAN_ISOLATION` | `auto` | Worker-thread isolation; `0` disables (avoids OOM 502 on tiny hosts) |
| `CITADEL_AIRGAP` | `1` | Disable all outbound enrichment + AI (offline dev) |

---

## 7. Quick start

### Mode 1 — client-side SPA demo (no build)

```bash
# from the repo root
python3 -m http.server 8000
# open http://localhost:8000/citadel/  →  click "Load demo project"
```

### Mode 2 — deep-scan backend with Docker Compose (recommended)

The compose file wires the read-only root FS, tmpfs scratch, dropped capabilities, the
healthcheck, and the persistent ClamAV DB volume.

```bash
# from the repo root — build context is the repo root
docker compose -f citadel/server/docker-compose.yml up --build
# open http://localhost:8080/  — the SPA now shows a "Deep scan" toggle
```

> **First run is slow.** Trivy and Grype download their vulnerability DBs on first use, and
> ClamAV may refresh signatures. The `clamav-db` named volume persists the DB across restarts.

### Mode 2 — plain Docker

```bash
# from the repo root (note the trailing ".")
docker build -f citadel/server/Dockerfile -t citadel-server .
docker run --rm -p 8080:8080 \
  --read-only \
  --tmpfs /tmp/citadel:size=2g \
  --cap-drop ALL \
  --security-opt no-new-privileges:true \
  citadel-server
```

### Mode 2 — native (advanced)

```bash
cd citadel/server
npm ci
CITADEL_JWT_SECRET=$(openssl rand -hex 32) node server.js
# open http://localhost:8080/
```

Install the scanner binaries on `PATH` for full coverage; missing ones are skipped.

### CLI (same toolchain, no server)

```bash
node citadel/server/cli.js . --format sarif --output citadel-results.sarif --fail-on high
# or fully containerized:
docker run --rm -v "$PWD:/scan" -w /scan citadel-server \
  node /app/cli.js /scan --format json --output /scan/report.json --fail-on high
```

See [`../CI.md`](../CI.md) for CLI flags and exit-code gating.

---

## 8. Verification

Run these against the deep-scan backend on `:8080`.

**1. Health endpoint**

```bash
curl -fsS http://localhost:8080/api/health | jq
# expect: {"status":"ok", ...}  — also reports airgap/fips flags and scanner availability
```

**2. Login works (JWT issued)**

```bash
TOKEN=$(curl -sS -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@citadel.local","password":"citadel-admin"}' | jq -r .token)
echo "$TOKEN" | cut -c1-20   # a non-empty JWT prefix
```

**3. Secrets resolved**

```bash
# If you set CITADEL_JWT_SECRET, sessions survive a restart. Confirm the token still works:
curl -fsS http://localhost:8080/api/auth/me -H "Authorization: Bearer $TOKEN" | jq .email
```

**4. Upload accepted + SCANNED (findings returned)**

```bash
# zip a sample and scan it; confirm real findings come back
zip -r /tmp/sample.zip citadel/js >/dev/null
curl -sS -X POST http://localhost:8080/api/scan \
  -H "Authorization: Bearer $TOKEN" \
  -F "files=@/tmp/sample.zip" -o /tmp/report.json
jq '{grade:.scoring.grade, findings:(.findings|length), scanners:.meta}' /tmp/report.json
# findings length > 0 and the report has scoring/posture
```

**5. Report written / persisted**

```bash
# durable scan history (owner-scoped). With DATABASE_URL set these persist to Postgres.
curl -fsS http://localhost:8080/api/scans -H "Authorization: Bearer $TOKEN" | jq 'length'
```

If you set `DATABASE_URL`, confirm a row landed:

```bash
psql "$DATABASE_URL" -c "SELECT count(*) FROM citadel_scans;"
```

---

## 9. Day-2 operations (local)

- **Scanner signature / DB updates.** Keep the `clamav-db` volume and refresh periodically:
  ```bash
  docker compose -f citadel/server/docker-compose.yml exec citadel freshclam
  docker compose -f citadel/server/docker-compose.yml exec citadel trivy --download-db-only
  docker compose -f citadel/server/docker-compose.yml exec citadel grype db update
  ```
- **Bump scanner versions.** Edit the pinned `ARG …_VERSION` values in
  [`../server/Dockerfile`](../server/Dockerfile), rebuild, re-run the adapter tests.
- **Reset dev state.** Remove the `clamav-db` volume and the tmpfs to start clean:
  `docker compose -f citadel/server/docker-compose.yml down -v`.
- **Tests / lint.**
  ```bash
  cd citadel/server && npm test        # smoke + node --test suites
  npm run lint
  ```
- **Logs.** Structured JSON lines to stdout: `docker compose … logs -f citadel`.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Container returns **502** during a scan | OOM — worker isolation loaded a 2nd engine copy on a small host | Set `CITADEL_SCAN_ISOLATION=0` and `SCAN_CONCURRENCY=1`; give Docker ≥ 2 GB |
| First scan very slow / ClamAV missing findings | Trivy/Grype/ClamAV DBs downloading or not yet seeded | Wait for first-run DB pull; run `freshclam` / `trivy --download-db-only` / `grype db update` |
| No **Deep scan** toggle in the UI | SPA served statically, not by the backend | Open `http://localhost:8080/` (served by the container), not the static `:8000` site |
| `401`/`403` on `/api/scan` | Enforcement on and no/invalid token | Log in for a JWT and send `Authorization: Bearer`; or run open for dev |
| AI "Explain & fix" disabled | `ANTHROPIC_API_KEY` unset or `CITADEL_AIRGAP=1` | Set the key; ensure airgap is off. For offline, use Ollama — see [AIRGAPPED.md](AIRGAPPED.md) |
| `Upload rejected` (400) | Hit a decompression-bomb / size cap | Raise `MAX_UPLOAD_BYTES` / `CITADEL_MAX_UNZIP_BYTES` / `CITADEL_MAX_UNZIP_ENTRIES` for legit large inputs |
| Sessions reset every restart | `CITADEL_JWT_SECRET` unset (random per boot) | Set a fixed `CITADEL_JWT_SECRET` |
| A scanner always "missing" natively | Binary not on `PATH` | Install it, or use the Docker image which bundles all scanners |
