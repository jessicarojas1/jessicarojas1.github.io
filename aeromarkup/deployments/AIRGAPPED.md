# AeroMarkup — Air-Gapped Deployment Guide

Operator guide for installing and operating AeroMarkup in a **fully
disconnected / air-gapped** environment (no internet egress).

Related guides: [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) · [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](./KUBERNETES.md) · [AWS.md](./AWS.md) · [AZURE.md](./AZURE.md)

---

## 1. Deployment architecture

AeroMarkup is **air-gap-safe by design**. The application makes **NO outbound
network calls** at runtime:

- **No hosted AI.** The 3D viewer and markup engine are **self-contained WebGL**
  with no external calls — there is no dependency on any hosted AI/LLM service.
- **No CDN.** All static assets (`static/`) are served by the app itself; nothing
  is pulled from a public CDN at runtime.
- **No object storage.** Uploaded reference images and STL/OBJ 3D models are
  stored as **data URLs in Postgres columns** — there is no S3/blob egress.
- **State is local.** Durable state lives in **PostgreSQL 13+** (dedicated
  `aeromarkup` schema) plus each client's **IndexedDB**. Clients can even run
  **offline-only** (empty `DATABASE_URL`) with no server at all.

Consequently, the only air-gap work is **getting the bits inside** the enclave:
the container base image (`python:3.12-slim`), a Postgres image (or an existing
internal DB), the built AeroMarkup image, and Python wheels — then wiring secrets
without an external secret store. There is no runtime "phone-home" to suppress.

Runtime shape is identical to other targets: a single stateless gunicorn process
(`gunicorn server:app --bind 0.0.0.0:$PORT --workers 2 --timeout 120`, Python
3.12, non-root uid 10001, `EXPOSE 8080`, `HEALTHCHECK` on `/api/health`).

---

## 2. Topology

```mermaid
flowchart LR
    subgraph EXT[Connected build/staging host]
      PULL[Pull python:3.12-slim + postgres<br/>docker build aeromarkup<br/>pip download wheels]
      TAR[docker save -> *.tar<br/>wheels.tar.gz<br/>db/schema.sql]
    end
    TAR ==>|sneakernet / one-way transfer| GAP((air gap))
    subgraph ENC[Air-gapped enclave  no internet]
      REG[Private registry]
      APP[AeroMarkup container<br/>uid 10001 :8080]
      PG[(PostgreSQL 13+<br/>schema: aeromarkup)]
      SEC[Offline secrets<br/>Vault/sealed/EnvironmentFile]
      GAP --> REG --> APP
      SEC -.env.-> APP
      APP -->|DATABASE_URL| PG
    end
```

```
[ Connected build host ]                         [ AIR-GAPPED ENCLAVE ]
 pull base+pg images        ==sneakernet==>       private registry --> AeroMarkup :8080 (uid 10001)
 docker build aeromarkup      (image tar,                                   |  DATABASE_URL
 pip download wheels          wheels, schema)                               v
 docker save -> tar                                               PostgreSQL 13+ (schema=aeromarkup)
                                                   offline secrets (Vault / sealed / EnvironmentFile)
```

---

## 3. Prerequisites

**On the connected build/staging host** (has internet):

| Tool | Version | Purpose |
|------|---------|---------|
| Docker | 24+ | build image, `docker pull`, `docker save` |
| `pip` / Python | 3.12 | `pip download` wheels for offline install |
| Access to source | this repo | `server.py`, `db/`, `static/`, `requirements.txt`, `Dockerfile` |

**Inside the enclave** (no internet):

| Tool | Version | Purpose |
|------|---------|---------|
| Private container registry | any OCI (Harbor, Nexus, ECR-in-VPC, ACR) | serve mirrored + app images |
| Docker or containerd | 24+ / 1.7+ | `docker load` / run |
| PostgreSQL | 13+ | dedicated `aeromarkup` schema, shared-DB safe |
| `psql` client | 13+ | offline migrations / verification |
| Kubernetes (optional) | 1.26+ | if deploying per [KUBERNETES.md](./KUBERNETES.md) |
| Offline secrets mechanism | Vault (offline) / sealed-secrets / file EnvironmentFile | inject `DATABASE_URL`, `AEROMARKUP_SECRET` |

---

## 4. Identity & credentials (offline)

No cloud secret manager is reachable, so use an **in-enclave** mechanism.
Least privilege still applies: whatever reads the secret should read **only**
AeroMarkup's two values, and the Postgres role should be scoped to the
`aeromarkup` schema.

- **Offline HashiCorp Vault** (air-gap install) — store `aeromarkup/database_url`
  and `aeromarkup/app_secret`; app host uses a Vault token/agent to template an
  EnvironmentFile. Policy grants read on `aeromarkup/*` only.
- **Sealed Secrets** (k8s) — encrypt the Secret with the in-cluster controller
  key; commit the sealed blob, controller decrypts inside the cluster. No
  external store needed.
- **File-based `EnvironmentFile`** (single host / systemd) — root-owned
  `0600` file consumed by a unit:

  ```ini
  # /etc/aeromarkup/aeromarkup.env   (chmod 600, root:root)
  DATABASE_URL=postgres://amuser:CHANGE_ME@127.0.0.1:5432/aeromarkup
  AEROMARKUP_SECRET=REPLACE_WITH_>=32_CHAR_RANDOM_SECRET
  ENVIRONMENT=production
  AUTO_MIGRATE=1
  TRUSTED_PROXY_HOPS=1
  ```
  ```ini
  # /etc/systemd/system/aeromarkup.service  (excerpt)
  [Service]
  EnvironmentFile=/etc/aeromarkup/aeromarkup.env
  ```

**`AEROMARKUP_SECRET` must be ≥32 chars and identical across all instances**
(REQUIRED in prod when `DATABASE_URL` is set). Generate inside the enclave:
`openssl rand -hex 32`.

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgres://amuser:***@pg:5432/aeromarkup` | Postgres DSN. Empty ⇒ offline-only (client IndexedDB only, no server persistence). |
| `PORT` | `8080` | gunicorn bind port. |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` at boot (idempotent). Set `0` to migrate manually offline. |
| `ENVIRONMENT` | `production` | Non dev/local/test ⇒ prod hardening (secret required, secure cookies). |
| `AEROMARKUP_SECRET` | `<64 hex>` | Signs `am_session`/CSRF. ≥32 chars, REQUIRED in prod; identical across all instances. |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime (12h default). |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed-login lockout threshold (per-process in-memory). |
| `LOGIN_WINDOW_SECONDS` | `900` | Lockout sliding window. |
| `LOGIN_MAX_TRACKED` | `1000` | Max identities tracked by throttle. |
| `TRUSTED_PROXY_HOPS` | `1` | Trusted proxy hops in front (reverse proxy/ingress). `0` = none. |
| `OLLAMA_HOST` | `http://ollama:11434` | **Optional, not used today.** Only if operators later add self-hosted AI assist (see §8). |

---

## 6. Configuration references

| Config point | Value / location | Purpose |
|--------------|------------------|---------|
| Health / readiness | `GET /api/health` | `{"status":"ok","database":"connected\|configured\|offline","mode":...}`. Liveness + readiness + container `HEALTHCHECK`. |
| Listener | `0.0.0.0:8080` | gunicorn bind. |
| Non-root | uid `10001` | Image `appuser`; app writes no files (read-only rootfs viable). |
| DB schema | `aeromarkup` (`search_path=aeromarkup,public`) | Dedicated schema; shared-DB safe. |
| Schema file | `db/schema.sql` | Idempotent DDL; `AUTO_MIGRATE` or `psql -f`. |
| Seed data | `db/seed.sql` | Optional sample data. |
| Session / CSRF | cookie `am_session` (signed) + cookie `am_csrf` / header `X-CSRF-Token` | Auth + double-submit CSRF. |
| Bootstrap / login | `POST /api/auth/bootstrap`, `POST /api/auth/login` | First admin then sign-in; roles viewer/engineer/inspector/approver/admin. |
| Base image | `python:3.12-slim` | Mirror to private registry. |
| Requirements | `requirements.txt` | Vendor as offline wheels. |

---

## 7. Offline install procedure

### 7.1 Build & bundle (connected host)

```bash
# --- Mirror base images to a bundle (or push to your private registry) ---
docker pull python:3.12-slim
docker pull postgres:16            # if you also mirror the DB image

# --- Build the AeroMarkup image from source ---
docker build -t aeromarkup:1.0.0 .

# --- Vendor Python wheels for a fully offline pip install (image-independent) ---
mkdir wheels && pip download -r requirements.txt -d wheels
tar czf aeromarkup-wheels.tgz wheels

# --- Save images as tarballs for sneakernet ---
docker save aeromarkup:1.0.0 python:3.12-slim postgres:16 \
  -o aeromarkup-images.tar

# --- Include the idempotent schema for manual migration ---
cp db/schema.sql aeromarkup-schema.sql
sha256sum aeromarkup-images.tar aeromarkup-wheels.tgz aeromarkup-schema.sql > SHA256SUMS
```

Transfer `aeromarkup-images.tar`, `aeromarkup-wheels.tgz`,
`aeromarkup-schema.sql`, and `SHA256SUMS` across the gap (verify checksums after
transfer).

### 7.2 Load into the enclave registry

```bash
sha256sum -c SHA256SUMS
docker load -i aeromarkup-images.tar

# Re-tag and push to the private registry the enclave nodes can reach
docker tag aeromarkup:1.0.0 registry.internal:5000/aeromarkup:1.0.0
docker push registry.internal:5000/aeromarkup:1.0.0
```

For Kubernetes, point the Deployment `image:` at
`registry.internal:5000/aeromarkup:1.0.0` and follow [KUBERNETES.md](./KUBERNETES.md)
(same manifests; sealed-secrets for §4).

### 7.3 Offline pip (only if building/installing without the prebuilt image)

```bash
tar xzf aeromarkup-wheels.tgz
pip install --no-index --find-links=./wheels -r requirements.txt
```

### 7.4 Database + migration (offline)

```bash
# Create schema-owning role/db as your DBA standard requires, then apply schema.
# db/schema.sql is idempotent — safe to re-run.
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f aeromarkup-schema.sql
# Optional: seed sample data
# psql "$DATABASE_URL" -f db/seed.sql
```
With `AUTO_MIGRATE=1` (default) the app also applies `db/schema.sql` at boot, so
this manual step is optional but recommended when you want DDL run under a
separate elevated role.

### 7.5 Offline OS packages & CVE feeds

- Mirror OS packages to an **internal apt/yum mirror** (e.g. Nexus/Artifactory);
  point enclave hosts' repo config at it. Rebuild the base image internally when
  patching `python:3.12-slim`.
- Mirror **CVE / vulnerability feeds** (e.g. an offline Trivy DB or your scanner's
  offline database bundle) and transfer them on your patch cadence; scan the
  AeroMarkup image against the offline DB before promotion.

### 7.6 Run

```bash
# Single host (compose or docker run) — envFile holds the offline secrets
docker run -d --name aeromarkup -p 8080:8080 \
  --env-file /etc/aeromarkup/aeromarkup.env \
  registry.internal:5000/aeromarkup:1.0.0
```

---

## 8. Self-hosted LLM (Ollama) — OPTIONAL, NOT REQUIRED

**AeroMarkup uses no hosted AI and makes no AI calls today.** The 3D viewer and
markup engine are self-contained WebGL. **You do not need Ollama (or any LLM) to
run AeroMarkup** — this section exists only for operators who later add optional
AI-assist features in an air-gapped setting, where a **self-hosted** model
replaces any hosted AI API.

If/when such a feature is added, run Ollama entirely in-enclave and point the app
at it via `OLLAMA_HOST` (default pattern `http://ollama:11434`). No model or
binary should be fetched over the internet at runtime — pull models on the
connected host and transfer them in.

**Ollama deployment references (choose one):**

```bash
# Docker
docker run -d --name ollama -p 11434:11434 \
  -v ollama:/root/.ollama ollama/ollama:latest
# then, from a connected host, `ollama pull <model>` and copy the model blobs
# into the enclave's /root/.ollama volume (sneakernet), no runtime download.
```

```ini
# systemd (/etc/systemd/system/ollama.service excerpt)
[Service]
Environment=OLLAMA_HOST=0.0.0.0:11434
ExecStart=/usr/local/bin/ollama serve
```

```yaml
# Kubernetes (sketch)
apiVersion: apps/v1
kind: Deployment
metadata: { name: ollama, namespace: aeromarkup }
spec:
  replicas: 1
  selector: { matchLabels: { app: ollama } }
  template:
    metadata: { labels: { app: ollama } }
    spec:
      containers:
        - name: ollama
          image: registry.internal:5000/ollama:latest
          ports: [{ containerPort: 11434 }]
          volumeMounts: [{ name: models, mountPath: /root/.ollama }]
      volumes: [{ name: models, persistentVolumeClaim: { claimName: ollama-models } }]
```
App wiring pattern (only if AI-assist is implemented): set
`OLLAMA_HOST=http://ollama:11434` in the AeroMarkup env. **Until then, leave it
unset — the app is fully functional without it.**

---

## 9. Manual updates & troubleshooting

### Update bundles (offline)

1. On the connected host, build the new image and vendor wheels as in §7.1;
   `docker save` the new image and copy the **new** `db/schema.sql`.
2. Optionally produce a schema **diff** for review, but apply the full idempotent
   file — do not hand-edit DDL:
   ```bash
   diff -u old/db/schema.sql new/db/schema.sql   # review only
   ```
3. Transfer, `docker load`, push to the private registry.
4. Apply migrations offline (idempotent):
   ```bash
   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f db/schema.sql
   ```
5. Roll the app to the new tag (compose `up -d`, `docker run` replace, or
   `kubectl set image` per [KUBERNETES.md](./KUBERNETES.md)).

### Troubleshooting

| Symptom | Likely cause | Resolution |
|---------|--------------|------------|
| Container exits immediately | `AEROMARKUP_SECRET` missing/<32 chars in prod with `DATABASE_URL` set | Set a ≥32-char secret in the EnvironmentFile/sealed secret; logs show "missing or too weak". |
| `/api/health` shows `database:"configured"` or `offline`; writes 503 | DB unreachable / `DATABASE_URL` empty | Fix DSN + network to the in-enclave Postgres; `offline` = unset, `configured` = set but connect failed. |
| Image won't pull in enclave | Not mirrored / wrong registry tag | Confirm `docker load` succeeded and the tag matches `registry.internal:5000/aeromarkup:<tag>`. |
| `pip` tries to reach the internet | Missing offline flags | Use `pip install --no-index --find-links=./wheels`. |
| Migration errors | Wrong DB role / partial apply | Re-run `psql -f db/schema.sql` (idempotent) under a role with DDL on `aeromarkup`. |
| Write returns `csrf_failed` | Missing/stale `X-CSRF-Token` vs `am_csrf` cookie, or cookies stripped by proxy | Send header matching the cookie; use HTTPS so the Secure cookie is sent. |
| Sessions invalid across instances | `AEROMARKUP_SECRET` differs between processes | Use the **same** secret everywhere; restart after fixing. |
| Client IP wrong / lockout ineffective behind proxy | `TRUSTED_PROXY_HOPS` mismatch | Set to real hop count; enforce rate limits at the reverse proxy for multi-instance. |
| Scanner reports stale CVEs | CVE feed not refreshed | Refresh the offline vuln DB bundle on your patch cadence and re-scan before promotion. |
