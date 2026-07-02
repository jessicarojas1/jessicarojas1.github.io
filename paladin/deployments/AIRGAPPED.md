# PALADIN — Air-Gapped / Offline Deployment

Operator guide for installing and operating PALADIN in an isolated network with
**no internet access** (classified enclaves, disconnected sites, IL5/IL6-style
environments). Covers a private registry mirror, bundled images, offline secrets,
offline package/CVE feeds, manual update bundles, and **self-hosted LLM inference
via Ollama** replacing any hosted AI dependency.

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](KUBERNETES.md) · [../docs/SECURITY.md](../docs/SECURITY.md) · [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)

---

## 1. Deployment architecture

PALADIN is well-suited to air-gapped operation: it is a self-contained PHP 8.3 +
PostgreSQL app with **no required outbound calls**. All UI assets (Bootstrap,
icons, JS) are vendored under `public/`; there are no CDN dependencies. Outbound
paths that CAN exist are all optional and stay inside the enclave:

- **Storage**: `local` driver (mounted disk) or `s3` pointing at an **in-enclave
  MinIO** — no AWS/Azure required.
- **Email**: internal SMTP relay, or `MAIL_TRANSPORT=queued` (records to
  `mail_outbox`, inspect at `/admin/outbox`) with no relay at all.
- **SSO**: SAML/OIDC to an **on-prem IdP** (ADFS, Keycloak) inside the enclave.
- **AI features (optional)**: PALADIN's core does not call any hosted AI API. If
  AI-assisted features are enabled, point them at a **self-hosted Ollama**
  endpoint inside the enclave (see §5–6). No hosted model is contacted.

Images are pre-staged in a **private registry mirror**; PostgreSQL runs in-enclave
(container or managed appliance); background jobs run via internal cron.

## 2. Topology

```
        ┌──────────── air-gapped enclave (no egress) ─────────────┐
        │                                                          │
  users─┼─►  nginx TLS ─► paladin (php:8.3-apache)                 │
        │                    │  PDO       │ S3 SigV4               │
        │                    ▼            ▼                        │
        │              PostgreSQL 16   MinIO (in-enclave S3)       │
        │                    ▲            ▲                        │
        │   on-prem IdP ──SAML/OIDC──┘    │                        │
        │   internal SMTP relay (or queued outbox)                 │
        │                                                          │
        │   Ollama (self-hosted LLM, optional) ◄── AI features     │
        │   private registry mirror  ◄── image bundles            │
        └──────────────────────────────────────────────────────────┘
                    ▲ sneakernet / one-way transfer
        update bundles: images.tar, packages, CVE feeds, model blobs
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Private registry | Harbor/Nexus/`registry:2` reachable in-enclave |
| Container runtime | Docker 24+ or containerd; or Podman |
| PostgreSQL 16 | Container image bundled, or appliance |
| MinIO (optional) | If `STORAGE_DRIVER=s3` in-enclave |
| Ollama (optional) | Only if AI features are enabled; GPU optional |
| Transfer media | Approved one-way/sneakernet path for bundles |
| Offline feeds | OS package mirror + CVE feed (see §8) |

## 4. Identity & credentials

No cloud identity. Secrets are provisioned **offline** and never leave the enclave:

- Generate `JWT_SECRET` in-enclave:
  `php -r "echo bin2hex(random_bytes(32));"` (or `openssl rand -hex 32`).
- Store secrets in an in-enclave secret store (**Vault** in the enclave, or a
  `600`-perm `.env` owned by the deploy user) — never on transfer media in clear.
- SMTP/S3 secrets entered in **Admin → Settings** are **AES-256-GCM encrypted at
  rest** by PALADIN (`Security::encryptSetting`).
- SSO trust: exchange SAML/OIDC metadata with the on-prem IdP over the internal
  network; import via `/admin/saml/import`.

## 5. Environment variables

| Variable | Example | Purpose |
|---|---|---|
| `APP_URL` | `https://paladin.enclave.local` | Internal base URL |
| `JWT_SECRET` | *(offline-generated, ≥64 hex)* | Token signing (**required**) |
| `DATABASE_URL` | `postgres://paladin:pw@pg:5432/paladin` | In-enclave DB |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | *(offline)* | First-run admin |
| `APP_ENV` | `production` | Prod behavior |
| `STORAGE_DRIVER` | `local` or `s3` | Disk or in-enclave MinIO |
| `S3_ENDPOINT` | `https://minio.enclave.local:9000` | In-enclave S3 |
| `S3_REGION` | `us-east-1` | SigV4 label (arbitrary for MinIO) |
| `MAIL_TRANSPORT` | `queued` or `smtp` | Outbox vs internal relay |
| `SMTP_HOST` | `smtp.enclave.local` | Internal relay |
| `TRUSTED_PROXY_IPS` | nginx IP | Trust `X-Forwarded-Proto` |
| `PORT` | `80` | Apache listen port |
| `OLLAMA_HOST`* | `http://ollama.enclave.local:11434` | Self-hosted LLM base (only if AI features enabled) |

\* If AI features are wired in, point them at the in-enclave Ollama; there is no
hosted AI fallback and none is contacted.

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Registry mirror | `registry.enclave.local/paladin:<tag>` | Pull without internet |
| `s3_endpoint` (Admin → Settings) | `https://minio.enclave.local:9000` | In-enclave object store |
| Ollama model | `llama3.1:8b` (pre-pulled) | Local inference model |
| Ollama GPU | CUDA runtime in the Ollama container | Optional acceleration; CPU fallback |

### Self-hosted LLM via Ollama (offline)

On a connected staging host, pull the model and export it into a bundle:

```bash
docker pull ollama/ollama:latest
docker save ollama/ollama:latest -o ollama-image.tar
# start Ollama, pull model, then copy the model blobs:
ollama pull llama3.1:8b        # populates ~/.ollama/models
tar czf ollama-models.tgz -C ~/.ollama models
```

Inside the enclave:

```bash
docker load -i ollama-image.tar
docker run -d --name ollama -p 11434:11434 \
  -v /srv/ollama:/root/.ollama ollama/ollama:latest    # add --gpus all if CUDA present
tar xzf ollama-models.tgz -C /srv/ollama
curl -fsS http://localhost:11434/api/tags               # model present
# Point PALADIN's AI settings / OLLAMA_HOST at this endpoint. No hosted API is used.
```

**GPU acceleration**: run the Ollama container with `--gpus all` (NVIDIA
Container Toolkit) or the K8s NVIDIA device plugin; it degrades to CPU
automatically when no GPU is available.

## 7. Verification

```bash
# Health (no internet needed)
curl -fsS https://paladin.enclave.local/health    # {"status":"healthy","checks":{"database":"ok"}}
curl -fsS https://paladin.enclave.local/healthz    # {"status":"ok"}

# Install/migrations ran from bundled image
docker logs paladin 2>&1 | grep -E "Installation complete|Applied migration"

# Login via on-prem IdP: browse /login → SAML/OIDC → authenticated

# Secrets resolved: admin present
psql "$PGCONN" -c "SET search_path TO paladin; SELECT email FROM users WHERE role='admin';"

# Upload accepted + stored (local or in-enclave MinIO): attach a file, then
psql "$PGCONN" -c "SET search_path TO paladin; SELECT original_name, stored_path FROM attachments ORDER BY id DESC LIMIT 1;"
# local:
docker exec paladin ls -la uploads/attachments | tail
# MinIO:
mc ls local/paladin-uploads/uploads/attachments/ | tail

# Hash-chained audit row
psql "$PGCONN" -c "SET search_path TO paladin; SELECT action, log_hash IS NOT NULL AS chained FROM activity_log ORDER BY id DESC LIMIT 1;"

# Ollama (if AI enabled): local inference works, no egress
curl -fsS http://ollama.enclave.local:11434/api/tags
```

## 8. Day-2 operations

**Manual update bundles** — build on a connected host, transfer, load:

```bash
# Connected staging: capture app + deps
docker pull registry.example.com/paladin:<tag>
docker save registry.example.com/paladin:<tag> postgres:16-alpine minio/minio ollama/ollama \
  -o paladin-bundle-<tag>.tar
# + OS packages (createrepo/apt mirror), CVE feed snapshot, php extension debs

# In enclave: load & push to mirror, then redeploy
docker load -i paladin-bundle-<tag>.tar
docker tag registry.example.com/paladin:<tag> registry.enclave.local/paladin:<tag>
docker push registry.enclave.local/paladin:<tag>
docker compose up -d          # startup.sh reapplies pending migrations
```

| Task | How |
|---|---|
| Migrations | Automatic on container start (idempotent, tracked in `schema_migrations`) |
| Offline CVE scanning | Import Trivy/Grype DB snapshot to the enclave; scan images offline |
| OS patching | Internal package mirror (apt/yum) synced via bundles |
| Cron jobs | `docker exec paladin php cli/send_digests.php daily` + `send_review_reminders.php 14 7` |
| Backups | `pg_dump -n paladin` + `uploads/` tar (or MinIO mirror); encrypt; store on approved media |
| Secret rotation | Regenerate `JWT_SECRET` in-enclave → restart (invalidates sessions/tokens) |
| Model updates | New `ollama-models.tgz` bundle → extract into Ollama volume |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `ImagePullBackOff` / pull fails | Registry not mirrored | Load bundle, push to `registry.enclave.local` |
| App can't reach DB | PostgreSQL not started / DNS | Start `db`; verify in-enclave DNS/host entry |
| S3 uploads fail | MinIO endpoint/creds | Set `s3_endpoint` to MinIO, re-enter keys (encrypted at rest) |
| Mail never delivered | No relay + `queued` | Inspect `/admin/outbox`, or configure internal SMTP |
| AI feature errors | Ollama down / model missing | Start Ollama, `ollama pull`/extract model blobs |
| CVE scan out of date | Feed not refreshed | Import newer Trivy/Grype DB bundle |
| Assets fail to load | (Shouldn't) all assets are vendored | Confirm `public/vendor` shipped in image; no CDN expected |
