# Air-Gapped Deployment — Sentinel QMS

> **Audience:** operators deploying Sentinel QMS into an isolated / classified /
> disconnected enclave with **no internet egress** (e.g. an on-prem SCIF, a
> disconnected GovCloud/Azure Gov region, or a standalone secure network).
> **CUI notice:** an air-gapped enclave is often the *highest*-assurance CUI
> boundary. All artifacts, secrets, and update bundles are transferred in via
> approved offline media/cross-domain processes only.

Sentinel QMS runs fully offline: it depends on **PostgreSQL 16**, an object
store (**local disk**, or S3-compatible **MinIO**, or on-prem blob), and the two
container images — none of which require internet at runtime. External SSO is
optional and can be disabled (local password + MFA works standalone).

Sibling guides: [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md) ·
[`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) · [`KUBERNETES.md`](KUBERNETES.md) ·
[`AWS.md`](AWS.md) · [`AZURE.md`](AZURE.md)

---

## 1. Deployment architecture

Everything is served from inside the enclave:

- **Private container registry** (Harbor / registry:2 / Nexus) holds the
  `sentinel-qms/backend`, `sentinel-qms/frontend` (or single-service) images plus
  base images (`postgres:16-alpine`, optional `minio`, optional `ollama`).
- **PostgreSQL 16** on a VM or in-cluster.
- **Object storage:** `local` disk (simplest), or MinIO for an S3 API on-prem.
- **Runtime:** the same docker-compose (single VM) or Kubernetes (Kustomize/Helm)
  shapes as the connected guides — only the image source and secret source change.
- **Offline secrets:** an on-prem secret manager (HashiCorp Vault, or SealedSecrets
  / a mounted encrypted file) — no cloud Secrets Manager / Key Vault.
- **Optional self-hosted LLM (Ollama):** the app ships **no hosted-AI dependency**
  today, so nothing is broken by the air gap. If/when AI-assisted features are
  enabled, run inference **entirely on-prem via Ollama** — never call a hosted
  API from the enclave.

---

## 2. Topology

```
   [ CONNECTED STAGING ]                     [ AIR-GAPPED ENCLAVE ]
   pull images, wheels,        approved       private registry (Harbor)
   npm/apt mirrors, CVE   ──►  offline    ──► ├─ sentinel-qms/backend
   feeds, ollama models        media/CDS      ├─ sentinel-qms/frontend
                                              ├─ postgres:16-alpine
                                              └─ ollama (optional)
                                                     │ docker/k8s pulls (in-enclave)
                                                     ▼
                              ┌────────────────────────────────────────┐
                              │ App VM / cluster                        │
                              │  backend FastAPI :8000  /health /api/v1 │
                              │  frontend SPA :8080                     │
                              │        │                │               │
                              │  psycopg│          uploads│  (opt) HTTP  │
                              │        ▼                ▼        ▼       │
                              │  postgres 16     MinIO/local   ollama    │
                              │  sentinel_qms    uploads       :11434    │
                              └────────────────────────────────────────┘
                              offline secrets: Vault / SealedSecret / file
                              offline logs: enclave SIEM (no egress)
```

---

## 3. Prerequisites

| Item | Notes |
|------|-------|
| Connected staging host | Same CPU arch as the enclave (e.g. `linux/amd64`); pulls artifacts. |
| Approved transfer path | Removable media / cross-domain solution per your ATO. |
| In-enclave registry | Harbor / `registry:2` / Nexus (TLS with the enclave CA). |
| Container runtime | Docker 24+ or Kubernetes 1.28+ inside the enclave. |
| PostgreSQL 16 | VM package or container image (bundled). |
| Object storage | local disk or MinIO (bundled). |
| (optional) GPU + drivers | only if running Ollama with acceleration. |

---

## 4. Identity & credentials

No cloud IAM inside the air gap. Use:

- **On-prem secret manager** (HashiCorp Vault) or **SealedSecrets** (K8s) or a
  root-owned encrypted env file (single VM). Store `DATABASE_URL`, `JWT_SECRET`,
  MinIO keys, and any OIDC secret there.
- **JWT secret:** generate inside the enclave — `openssl rand -base64 48` — never
  transported from a connected network.
- **SSO:** optional. If an enclave IdP (ADFS/Keycloak on the enclave) exists, set
  `OIDC_ISSUER` to its internal URL; otherwise leave SSO disabled (empty issuer
  fails closed) and use local accounts + **MFA** (TOTP, works offline) + optional
  **CAC/PIV** via an mTLS-terminating reverse proxy (`CLIENT_CERT_PROXY_AUTH=true`
  + `TRUST_PROXY_HEADERS=true`).
- **Storage:** MinIO uses local root creds (kept in the secret store); the app's
  S3 client points at the MinIO endpoint (`S3_ENDPOINT_URL`, path-style, no
  SSE-KMS — see `app/services/storage.py`).

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `ENVIRONMENT` | `production` | Hardens (JWT guard, HSTS). |
| `LOG_LEVEL` | `INFO` | Log level → enclave SIEM. |
| `DATABASE_URL` | `postgresql+psycopg://sentinel:***@postgres:5432/sentinel_qms?sslmode=require` | DB DSN (from offline secret store). |
| `DB_SCHEMA` | `sentinel_qms` | Dedicated schema. |
| `JWT_SECRET` | *(generated in-enclave, ≥ 32 chars)* | Token signing. |
| `STORAGE_BACKEND` | `s3` (MinIO) or `local` | Upload backend. |
| `S3_BUCKET` | `sentinel-qms-uploads` | MinIO bucket. |
| `S3_ENDPOINT_URL` | `http://minio:9000` | On-prem S3 endpoint (path-style, no KMS). |
| `S3_REGION` | `us-gov-west-1` | Arbitrary for MinIO. |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | *(MinIO creds from secret store)* | MinIO auth. |
| `AWS_EC2_METADATA_DISABLED` | `true` | No IMDS in the enclave. |
| `LOCAL_STORAGE_DIR` | `/var/lib/sentinel/uploads` | If `STORAGE_BACKEND=local` (persist + back up). |
| `CORS_ORIGINS` | `https://qms.enclave.local` | Allowed origins. |
| `OIDC_ISSUER` | *(blank, or enclave IdP URL)* | SSO optional; empty = disabled. |
| `TRUST_PROXY_HEADERS` | `true` | Behind an mTLS/TLS reverse proxy. |
| `AUTO_MIGRATE` | `1` (or `0` + Job) | Apply migrations offline. |
| `AUTO_SEED` | `1` (first boot) | Seed roles/reference data. |
| `ADMIN_AUTO_CREATE` | `false` | Create the first admin explicitly (`python -m app.reset_admin`). |
| `RUN_SCHEDULER` | `true` | SLA sweep + report digest (fully offline). |
| `SMTP_HOST` | *(enclave relay, optional)* | Notifications via the internal mail relay only. |

### Optional — self-hosted LLM (Ollama)

| Variable | Example | Purpose |
|----------|---------|---------|
| `OLLAMA_BASE_URL` | `http://ollama:11434` | Point any future AI feature at the on-prem Ollama endpoint (no hosted API). |
| `OLLAMA_MODEL` | `llama3.1:8b` | Locally pulled model, transferred in as a bundle. |

> These Ollama variables are **forward-looking**: the current release has no
> hosted-AI runtime dependency, so an air-gapped install needs no LLM to
> function. Provision Ollama only if you enable an optional AI-assisted feature;
> keep all inference in-enclave.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `MAX_UPLOAD_BYTES` | `52428800` | 50 MB upload cap. |
| `REDIS_URL` | `redis://redis:6379/0` | Cross-worker rate limiting if scaled (bundle the redis image). |
| `RATE_LIMIT_PER_MINUTE` | `300` | Per-principal budget. |
| `LOGIN_MAX_FAILURES` | `10` | Brute-force throttle (works offline). |

Bundle build (on the connected host, then transfer the tarballs):

```bash
# Images (match the enclave arch)
docker pull postgres:16-alpine
docker build -t sentinel-qms/backend:1.0.0  ./backend
docker build -t sentinel-qms/frontend:1.0.0 ./frontend
# optional: docker pull minio/minio:latest ; docker pull ollama/ollama:latest
docker save sentinel-qms/backend:1.0.0 sentinel-qms/frontend:1.0.0 \
            postgres:16-alpine minio/minio:latest \
  -o sentinel-qms-images-1.0.0.tar

# Offline package/CVE feeds for later patching (examples):
#  - Python wheels:  pip download -r backend/requirements.txt -d wheels/
#  - Trivy DB:       trivy image --download-db-only  (copy ~/.cache/trivy)
#  - OS packages:    apt-mirror / reposync snapshot
#  - Ollama model:   ollama pull llama3.1:8b  (copy ~/.ollama/models)
```

Load inside the enclave and push to the private registry:

```bash
docker load -i sentinel-qms-images-1.0.0.tar
docker tag sentinel-qms/backend:1.0.0 registry.enclave.local/sentinel-qms/backend:1.0.0
docker push registry.enclave.local/sentinel-qms/backend:1.0.0
# repeat for frontend / postgres / minio, then set image refs to registry.enclave.local/...
```

---

## 7. Verification

```bash
# 7.1 Health
curl -fsS https://qms.enclave.local/health                   # {"status":"ok"} 200

# 7.2 Secrets resolved + login — create the first admin first:
#   docker compose exec app python -m app.reset_admin   (or a k8s exec)
TOKEN=$(curl -fsS -X POST https://qms.enclave.local/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@enclave.local","password":"<pw>"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')

# 7.3 Upload accepted + scanned (magic-byte) + object written
printf '%%PDF-1.4\n%%EOF\n' > /tmp/t.pdf
curl -fsS -X POST https://qms.enclave.local/api/v1/attachments \
  -H "Authorization: Bearer $TOKEN" \
  -F entity_type=document -F entity_id=1 \
  -F 'file=@/tmp/t.pdf;type=application/pdf'                  # 201, stored_key=<uuid>.pdf
```

Confirm the DB rows (attachment + immutable audit trail):

```bash
psql "postgresql://sentinel@postgres:5432/sentinel_qms" -c \
  "SET search_path TO sentinel_qms; \
   SELECT id, stored_key, storage_backend FROM attachments ORDER BY id DESC LIMIT 1; \
   SELECT action, actor_email, created_at FROM audit_logs WHERE action='upload' ORDER BY id DESC LIMIT 1;"
```

Confirm the object written to storage:

```bash
ls -l /var/lib/sentinel/uploads                       # local backend
# or MinIO:
mc alias set enc http://minio:9000 <key> <secret> && mc ls enc/sentinel-qms-uploads
```

(Optional) confirm Ollama serves locally, if enabled:

```bash
curl -fsS http://ollama:11434/api/tags                # lists locally pulled models
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Update bundle | Build a new image + wheel + CVE-DB bundle on the connected host, transfer via approved media, `docker load` → push to the enclave registry. |
| App upgrade | Bump the image tag in compose/Helm; entrypoint runs `alembic upgrade head` (or run the migration Job first). |
| Migrations | `AUTO_MIGRATE=1` on boot, or run `alembic upgrade head` manually before flipping traffic. |
| Backups | `pg_dump -Fc sentinel_qms` on a schedule; back up `LOCAL_STORAGE_DIR` or the MinIO data dir; keep encrypted copies on approved media. |
| Restore | `pg_restore` the dump; restore the uploads dir/MinIO data. See `docs/DISASTER_RECOVERY.md`. |
| CVE scanning | Offline **Trivy** with a transferred DB snapshot against the enclave registry; patch by rebuilding bundles. |
| Secret rotation | Rotate `JWT_SECRET` / DB / MinIO creds in the offline secret store; restart the app (rotating `JWT_SECRET` invalidates live tokens). |
| Ollama models | Refresh models by transferring a new `~/.ollama/models` snapshot; no internet pulls in-enclave. |
| Logs | Ship structured JSON logs to the **enclave SIEM** only; verify no egress. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `ImagePullBackOff` / pull fails | Image not in the enclave registry or wrong ref | `docker load` the bundle, push to `registry.enclave.local`, fix image refs. |
| Backend tries to reach the internet | Cloud SDK / metadata probe | Use `STORAGE_BACKEND=local` or MinIO; set `AWS_EC2_METADATA_DISABLED=true`; leave `OIDC_ISSUER` blank. |
| `MIGRATION FAILED` | DB unreachable / wrong DSN | Verify `DATABASE_URL` and that Postgres is up in-enclave. |
| `refusing to start ... insecure default` | Weak `JWT_SECRET` | Generate a ≥ 32-char secret in-enclave. |
| Upload 400 "contents do not match" | Not a real allowed type | Server sniffs magic bytes — upload a genuine PDF/PNG/etc. |
| Arch mismatch on load | Bundle built for a different CPU arch | Rebuild with `--platform linux/amd64` (or the enclave arch) on the connected host. |
| Ollama not responding | Container down / model not transferred | Start the ollama container; confirm the model exists (`/api/tags`); verify `OLLAMA_BASE_URL`. |
| Registry TLS errors | Enclave CA not trusted | Install the enclave CA into the container runtime / node trust store. |
