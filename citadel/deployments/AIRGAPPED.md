# CITADEL — Air-Gapped / No-Egress Deployment

**Audience:** operators deploying CITADEL into a network with **no outbound internet** (classified
enclaves, CUI/ITAR/export-controlled review, disconnected labs). The central challenge for CITADEL
specifically is that its deep-scan value comes from **real scanner binaries + their signature/CVE
databases** — and those normally update over the internet. This guide makes CITADEL fully
functional offline: private image registry, **bundled scanner DBs** (Trivy, ClamAV, Grype/OSV,
Semgrep rules), and **Ollama** replacing the hosted Anthropic API for AI "Explain & fix".

CITADEL runs the same container — Node 20 / Express on **:8080**, `GET /api/health`, non-root UID
10001, read-only root FS, **≥ 2 GB RAM** (ClamAV ~1.4 GB signature DB).

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](KUBERNETES.md) ·
[AWS.md](AWS.md) (GovCloud) · [AZURE.md](AZURE.md) (Azure Gov). Env:
[`../docs/ENV.md`](../docs/ENV.md).

---

## 1. Deployment architecture

- **The app makes no outbound calls by default when air-gap mode is on.** Set **`CITADEL_AIRGAP=1`**
  (or `CITADEL_NO_EGRESS=1`) — this **hard-disables AI remediation to the hosted API** so scanned
  source can never be transmitted to an external LLM, and disables OSV.dev / update-check
  enrichments. `/api/health` reports `airgap:true`.
- **Everything else stays local:** the scanners run entirely offline against local DBs; users,
  sessions, scans, audit, and settings persist to an in-boundary Postgres (or the file store).
- **AI remediation goes to a self-hosted Ollama** endpoint inside the enclave instead of
  Anthropic (see §7).
- **Images and scanner DBs are staged in from a connected "transfer" host** and mirrored to an
  in-boundary registry / object store; the enclave never touches the public internet.

Scanner offline behavior is already baked in: the adapters set
`SEMGREP_SEND_METRICS=off`, `TRIVY_DISABLE_VEX_NOTICE=true`, `GRYPE_CHECK_FOR_APP_UPDATE=false`,
`SYFT_CHECK_FOR_APP_UPDATE=false`, and `GIT_TERMINAL_PROMPT=0`. Missing scanners **degrade
gracefully** (skipped, reported `available:false` in `/api/health`).

## 2. Topology

```
  ┌───────────────── connected side (transfer host) ─────────────────┐
  │  docker pull citadel-server + scanner DB bundles                  │
  │  trivy --download-db-only · grype db update · freshclam           │
  │  semgrep rules bundle · OSV offline DB · Ollama model .gguf        │
  │  → save to media / one-way diode                                  │
  └───────────────────────────┬──────────────────────────────────────┘
                              ▼   (sneakernet / data diode)
  ┌───────────────── air-gapped enclave (no internet) ───────────────┐
  │  Private registry (Harbor/Nexus/ECR-gov) ── citadel-server image  │
  │                                                                   │
  │  ┌──────────────────────────────┐   DB volumes mounted in:       │
  │  │ citadel-server :8080          │   /var/lib/clamav  (ClamAV)    │
  │  │  CITADEL_AIRGAP=1             │   TRIVY cache dir  (Trivy DB)  │
  │  │  /api/health airgap:true      │   GRYPE_DB_CACHE_DIR (Grype)  │
  │  │  scanners: local DBs only     │   semgrep rules dir           │
  │  └──────┬───────────────┬────────┘                               │
  │  Postgres (in-boundary) │  OLLAMA_HOST → Ollama (local LLM)      │
  │                         │  /api/explain uses local model         │
  └─────────────────────────┴─────────────────────────────────────┘
```

## 3. Prerequisites

| Requirement | Note |
|---|---|
| Connected **transfer host** | Same OS/arch as the enclave; used to pull images + DBs |
| In-boundary **registry** | Harbor / Nexus / Artifactory / ECR-gov to hold the image |
| In-boundary **object store / share** | To hold scanner DB bundles and the Ollama model |
| Transfer mechanism | Removable media, data diode, or approved one-way path |
| Enclave host / cluster | ≥ 2 vCPU, **≥ 4 GB RAM** (more if co-hosting Ollama) |
| Ollama | For AI "Explain & fix" (optional but recommended offline) |

## 4. Identity & credentials

- **No hosted secret manager** — inject secrets from the enclave's approved store (Vault,
  HSM-backed KMS, sealed K8s Secrets) or a locked-down `.env`/Docker/K8s Secret.
- Generate secrets locally: `openssl rand -hex 32` (`CITADEL_JWT_SECRET`),
  `openssl rand -base64 48` (`CITADEL_SUPERADMIN_TOKEN`).
- Set a **stable `CITADEL_JWT_SECRET`** and, for defense-in-depth, `CITADEL_DATA_KEY` (32-byte
  hex) to AES-256-GCM-seal at-rest secrets (JWT signing key, TOTP seeds) so a leaked
  store/backup doesn't yield session-minting material.
- Enable **`CITADEL_FIPS=1`** on a FIPS-validated OpenSSL build (switches password hashing to
  PBKDF2-HMAC-SHA256). Confirm via `fips.active` in `/api/health`.
- SSO: point OIDC at the enclave IdP (`OIDC_ISSUER`) if SSO is required; otherwise use local
  accounts + TOTP MFA (self-service, no external dependency).

## 5. Environment variables

| Variable | Example | Purpose |
|---|---|---|
| `CITADEL_AIRGAP` | `1` | **Air-gap profile** — disables hosted AI + OSV/update enrichments; `/api/health` `airgap:true` |
| `CITADEL_NO_EGRESS` | `1` | Synonym for the above |
| `NODE_ENV` | `production` | Prod hardening |
| `PORT` | `8080` | Listen port |
| `CITADEL_JWT_SECRET` | `$(openssl rand -hex 32)` | Stable session signing key |
| `CITADEL_DATA_KEY` | 64 hex chars | AES-256-GCM at-rest secret sealing |
| `CITADEL_FIPS` | `1` | FIPS 140 mode (PBKDF2 hashing) |
| `DATABASE_URL` | `postgres://citadel:…@pg.enclave:5432/citadel?sslmode=verify-full` | In-boundary Postgres |
| `CITADEL_TMP` | `/tmp/citadel` | Untrusted-upload scratch (keep on tmpfs) |
| **AI (Ollama)** | | |
| `ANTHROPIC_API_KEY` | *(unset in airgap)* | Leave unset — airgap hard-disables hosted AI regardless |
| `OLLAMA_HOST` | `http://ollama.enclave:11434` | Local LLM endpoint (see §7) |
| `CITADEL_AI_MODEL` | `qwen2.5-coder:7b` | Local model id for remediation |
| **Scanner DB locations** | | |
| `TRIVY_CACHE_DIR` | `/opt/citadel/trivy` | Pre-seeded Trivy vuln DB cache |
| `GRYPE_DB_CACHE_DIR` | `/opt/citadel/grype` | Pre-seeded Grype vuln DB |
| ClamAV DB dir | `/var/lib/clamav` (mounted) | Pre-seeded ClamAV signatures |

> The Trivy/Grype scanners honor their standard cache env vars; if unset they default to the
> tool's cache and will *try* to update — which fails offline. Point them at pre-seeded dirs and
> run the scanners in DB-skip mode where supported (e.g. Trivy `--skip-db-update`,
> `--offline-scan`; Grype `GRYPE_DB_AUTO_UPDATE=false`).

## 6. Staging the image and scanner DBs (the critical offline step)

On the **connected transfer host** (matching arch, e.g. `linux/amd64`):

```bash
# 1) The CITADEL image (bundles all scanner binaries, pinned & signed)
docker pull ghcr.io/jessicarojas1/citadel-server:v1.0.0
# Verify signature + provenance BEFORE transfer (see server/README "Published image"):
cosign verify ghcr.io/jessicarojas1/citadel-server:v1.0.0 \
  --certificate-identity-regexp '^https://github.com/jessicarojas1/jessicarojas1.github.io/' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'
docker save ghcr.io/jessicarojas1/citadel-server:v1.0.0 -o citadel-server.tar

# 2) Scanner databases — pull fresh, then package the on-disk caches
#    Run inside the image so versions match exactly:
docker run --rm -v "$PWD/db:/db" ghcr.io/jessicarojas1/citadel-server:v1.0.0 bash -lc '
  freshclam --datadir=/db/clamav || true                    # ClamAV signatures (main/daily/bytecode.cvd)
  TRIVY_CACHE_DIR=/db/trivy   trivy --download-db-only       # Trivy vuln DB (trivy.db, metadata.json)
  GRYPE_DB_CACHE_DIR=/db/grype grype db update               # Grype vuln DB
  osv-scanner --version                                      # OSV offline DB (stage the OSV zip if used)
  semgrep --version                                          # bundle a Semgrep rules pack for --config
'
tar -czf citadel-scanner-dbs.tgz db/

# 3) Ollama model for AI remediation (see §7)
ollama pull qwen2.5-coder:7b     # then export the model blob from ~/.ollama/models
```

Transfer `citadel-server.tar`, `citadel-scanner-dbs.tgz`, and the Ollama model into the enclave.
Inside the enclave:

```bash
docker load -i citadel-server.tar
docker tag ghcr.io/jessicarojas1/citadel-server:v1.0.0 registry.enclave/citadel-server:v1.0.0
docker push registry.enclave/citadel-server:v1.0.0
tar -xzf citadel-scanner-dbs.tgz -C /opt/citadel        # → /opt/citadel/{clamav,trivy,grype}
```

Run CITADEL with the DBs mounted and airgap on:

```bash
docker run -d -p 8080:8080 \
  --read-only --tmpfs /tmp/citadel:size=2g \
  --cap-drop ALL --security-opt no-new-privileges:true \
  -e CITADEL_AIRGAP=1 \
  -e CITADEL_JWT_SECRET="$(openssl rand -hex 32)" \
  -e DATABASE_URL="postgres://citadel:PASS@pg.enclave:5432/citadel?sslmode=verify-full" \
  -e TRIVY_CACHE_DIR=/opt/citadel/trivy \
  -e GRYPE_DB_CACHE_DIR=/opt/citadel/grype -e GRYPE_DB_AUTO_UPDATE=false \
  -e OLLAMA_HOST="http://ollama.enclave:11434" -e CITADEL_AI_MODEL="qwen2.5-coder:7b" \
  -v /opt/citadel/clamav:/var/lib/clamav \
  -v /opt/citadel/trivy:/opt/citadel/trivy \
  -v /opt/citadel/grype:/opt/citadel/grype \
  registry.enclave/citadel-server:v1.0.0
```

On **Kubernetes**, mount the DB bundles from a shared **PVC** (or an init container that copies
them from an in-boundary object store) instead of the disk-backed `emptyDir`, and set the same
env — see [KUBERNETES.md](KUBERNETES.md).

## 7. Ollama — self-hosted AI "Explain & fix" (replacing Anthropic)

Air-gap mode hard-disables the hosted Anthropic path so code never leaves the enclave. To keep
inline **Explain & fix**, run **Ollama** in-boundary and point CITADEL at it:

```bash
# In the enclave (GPU optional; CPU works for small models):
ollama serve                                   # listens on :11434
ollama create qwen2.5-coder:7b -f Modelfile    # from the staged model blob
```

Wire CITADEL: `OLLAMA_HOST=http://ollama.enclave:11434`, `CITADEL_AI_MODEL=qwen2.5-coder:7b`.
Because `CITADEL_AIRGAP=1`, the app never calls the external Anthropic API; the remediation
prompt (finding name/severity/CWE/location/snippet) is sent only to the local Ollama endpoint.
The CITADEL SPA's copy-the-prompt workflow (**AI Fix Prompt** tab) also works with any local
assistant and requires no network.

> If AI remediation is **not permitted** for the data being reviewed, simply omit Ollama and
> `ANTHROPIC_API_KEY`. Everything except inline explanations still works; use the copy-prompt tab.

## 8. Verification

```bash
# 1. Health confirms airgap + local scanners
curl -fsS http://localhost:8080/api/health | jq '{ok,airgap,fips,scanners:[.scanners[]|{tool,available}]}'
# expect airgap:true and available:true for clamav, trivy, grype, syft, semgrep, gitleaks, bandit

# 2. Login (JWT) + at-rest secrets sealed
TOKEN=$(curl -sS -X POST http://localhost:8080/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@citadel.local","password":"<seeded>"}' | jq -r .token)
curl -fsS http://localhost:8080/api/auth/me -H "Authorization: Bearer $TOKEN" | jq .email

# 3. Upload accepted + SCANNED offline (CVE + malware findings from LOCAL DBs)
zip -r /tmp/s.zip citadel/js >/dev/null
curl -sS -X POST http://localhost:8080/api/scan -H "Authorization: Bearer $TOKEN" \
  -F "files=@/tmp/s.zip" -o /tmp/report.json
jq '{grade:.scoring.grade, findings:(.findings|length)}' /tmp/report.json   # findings > 0, no egress

# 4. AI "Explain & fix" via Ollama (optional)
curl -sS -X POST http://localhost:8080/api/explain -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"finding":{"name":"SQL injection","severity":"high","cwe":"CWE-89"}}' | jq .text

# 5. Report persisted in-boundary
curl -fsS http://localhost:8080/api/scans -H "Authorization: Bearer $TOKEN" | jq 'length'
psql "$DATABASE_URL" -c "SELECT count(*) FROM citadel_scans;"
```

**No-egress proof:** run with an egress-deny firewall/NetworkPolicy and confirm scans still
return CVE/malware findings (proves DBs are local) and that `/api/explain` reaches only the
in-boundary Ollama host.

## 9. Day-2 operations (update bundles)

The offline lifecycle is **periodic re-staging of scanner DBs** — this is the single most
important recurring task, since stale DBs silently reduce coverage.

- **Build an update bundle on a cadence** (weekly/biweekly) on the transfer host: re-run the
  §6 step to refresh ClamAV (`freshclam`), Trivy (`trivy --download-db-only`), Grype
  (`grype db update`), the OSV DB, and Semgrep rules; repackage and transfer in.
- **Apply in the enclave** by replacing the mounted DB dirs (or rolling a rebuilt image that
  bakes fresh DBs) — then restart/redeploy. On K8s, update the PVC/init-container source and
  roll the Deployment.
- **Monitor freshness.** Watch `/api/health` scanner `available` flags and the DB dates; alert if
  a bundle is older than your policy window. Missing/old DBs degrade gracefully but weaken
  results — treat it as a finding-coverage risk.
- **Image / model updates** follow the same save→verify(cosign)→transfer→load→push flow. Verify
  signatures/provenance on the connected side before transfer (SR-3/SR-4/SR-11).
- **Backups.** Back up the in-boundary Postgres (users, sessions, scans, audit, settings,
  dispositions) and the DB-bundle staging store; keep them encrypted.
- **DB migrations.** None — schema created on boot (idempotent
  [`../database/schema.sql`](../database/schema.sql)).
- **Audit off-box.** Point `CITADEL_AUDIT_SINK_URL` at an in-boundary SIEM; the audit log is
  hash-chained (verify via `GET /api/audit/verify`).

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `/api/health` `airgap:false` | `CITADEL_AIRGAP` not set | Set `CITADEL_AIRGAP=1` (or `CITADEL_NO_EGRESS=1`) |
| Trivy/Grype scanner `available:false` or empty CVEs | DB not staged / cache dir not set | Mount pre-seeded DBs; set `TRIVY_CACHE_DIR`/`GRYPE_DB_CACHE_DIR`; `GRYPE_DB_AUTO_UPDATE=false` |
| Scan hangs then times out | A scanner tried to update over the (blocked) network | Set the offline cache env vars and DB-skip flags; confirm egress is fully denied so it fails fast |
| ClamAV finds nothing / errors | Signature DB missing or stale in `/var/lib/clamav` | Stage `main/daily/bytecode.cvd` via `freshclam` bundle; mount the volume |
| AI "Explain & fix" errors | Ollama unreachable or model missing | Verify `OLLAMA_HOST`, `ollama list` shows `CITADEL_AI_MODEL`; or omit AI and use the copy-prompt tab |
| Any outbound call attempted | Something still expects egress | Keep `CITADEL_AIRGAP=1`; audit env for stray endpoints; enforce egress-deny at the network |
| `502`/OOM on scans | RAM tight (esp. if co-hosting Ollama) | Separate Ollama onto its own host/GPU; give CITADEL ≥ 2 GB; `SCAN_CONCURRENCY=1` |
| Sessions reset on restart | `CITADEL_JWT_SECRET` unset/rotated | Set a stable value; store sealed with `CITADEL_DATA_KEY` |
| Image won't run (unsigned/untrusted) | Skipped cosign verification | Verify signature + provenance on the connected side before transfer |
