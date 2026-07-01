# Disaster Recovery — CMMC 2.0 Level 2 Compliance Agent

The app carries almost no state, which makes DR simple: back up two small JSON files,
keep the API key in a secret store, and redeploy the container image. This document
defines what holds state, RPO/RTO targets, backup and restore procedures, verification
cadence, and HA considerations.

---

## 1. What Holds State

| Item | Where | Sensitivity | Notes |
|------|-------|-------------|-------|
| `status.json` | Local FS / mounted volume in app dir | **Business-critical** | Control implementation status — the important data. May contain notes referencing evidence locations. |
| `settings.json` | Local FS / mounted volume in app dir | Low | Branding (appName, logoUrl, accent). Logo may be an inline `data:` URL. |
| `ANTHROPIC_API_KEY` | Platform secret store (Secrets Manager / Key Vault / K8s Secret) | **Secret** | Sole secret. Not stored in the JSON files. |
| Config (env) | Env vars (`ANTHROPIC_API_KEY`, `PORT`) | — | Reproduced from deployment config, not backed up. |

**There is no database and no object storage.** The `CONTROLS` catalog (110 practices)
is code, not state — it ships inside `agent.py` and is restored with the image.

---

## 2. RPO / RTO Targets

Because state is tiny JSON and the app is re-creatable from its image:

| Metric | Target | Rationale |
|--------|--------|-----------|
| **RPO** | ≈ last backup of `status.json` | Only `status.json`/`settings.json` change at runtime. RPO equals your backup interval. |
| **RTO** | Minutes | Redeploy the container image + restore the JSON files + set `ANTHROPIC_API_KEY`. |

To tighten RPO, back up `status.json` on write or on a short cron (see below). The data
is small enough that frequent backups are cheap.

---

## 3. Backups

**What to back up:** `status.json` and `settings.json` (and, implicitly, the secret in
the secret store — managed by the platform, not by this app).

**How:** copy the two files, or snapshot the mounted volume.

```bash
# File-level backup (run from the app dir or against the volume mount)
tar -czf cmmc-agent-state-$(date +%Y%m%d-%H%M%S).tar.gz status.json settings.json

# Copy to encrypted object storage / backup target (example)
aws s3 cp cmmc-agent-state-*.tar.gz s3://YOUR-BACKUP-BUCKET/cmmc-agent/ --sse aws:kms
```

| Aspect | Guidance |
|--------|----------|
| **What** | `status.json` (critical), `settings.json` (nice-to-have) |
| **How** | File copy or volume snapshot |
| **Where** | Encrypted backup store separate from the running host |
| **Retention** | Keep enough history to recover from silent corruption (e.g. daily for 30 days + weekly for a quarter — tune to policy) |
| **Encryption** | Encrypt at rest (KMS / SSE); the files may contain notes referencing evidence |

---

## 4. Restore Runbook

Copy-pasteable. Assumes the container image is available and a target host/cluster is
ready.

```bash
# 1. Redeploy the application image (example — adapt to your platform).
docker run -d --name cmmc-agent \
  -p 5050:5050 \
  -v /srv/cmmc-agent-state:/app \
  -e ANTHROPIC_API_KEY="$ANTHROPIC_API_KEY" \
  YOUR-REGISTRY/cmmc-agent:TAG

# 2. Restore the state files into the app dir / mounted volume.
#    (Extract your backup, then place the files where the app reads them.)
tar -xzf cmmc-agent-state-YYYYMMDD-HHMMSS.tar.gz -C /srv/cmmc-agent-state/
#    -> /srv/cmmc-agent-state/status.json
#    -> /srv/cmmc-agent-state/settings.json

# 3. Ensure the secret is present (from your secret manager).
export ANTHROPIC_API_KEY="sk-ant-..."   # or injected by the platform

# 4. Verify the restore.
curl -s http://localhost:5050/api/dashboard | python -m json.tool
```

**Verification (step 4 detail):** `GET /api/dashboard` requires no API key and computes
scores from `status.json`. Confirm the returned `overall_score_pct` and per-domain
counts match the expected pre-incident values. Then load `GET /` in a browser and
confirm branding (`settings.json`) is applied.

---

## 5. Verification Cadence

- Run a **restore drill** on a regular schedule (e.g. quarterly): restore the latest
  backup into a scratch environment and verify.
- **Verify the score matches:** after restore, compare `overall_score_pct` and
  per-domain `implemented`/`partial`/`total` from `/api/dashboard` against the last
  known-good values.
- Confirm `settings.json` branding renders and a broken/empty logo degrades gracefully
  to the default mark.

---

## 6. High Availability

- **Today the app is a single synchronous Flask process.** It is stateless *per
  request* — the only shared mutable state is the two JSON files — so any instance can
  be re-created from the image.
- **Multiple replicas require a shared RWX volume** for `status.json` /
  `settings.json`. Without shared storage, each replica writes its own copy and you get
  **divergent state** (different scores per replica). This is the key HA caveat.
- Recommended patterns:
  - **Single instance + fast redeploy** (simplest; RTO in minutes).
  - **Replicas on a shared RWX volume** (e.g. NFS / EFS / Azure Files) if you need
    concurrent availability — but beware concurrent-write races on the JSON files.
- Because the catalog and code ship in the image, rebuilding a node is just: pull image
  → mount/restore state → set secret → start.

---

## See Also

- [`ARCHITECTURE.md`](ARCHITECTURE.md)
- [`DEPLOYMENT.md`](DEPLOYMENT.md)
- [`SECURITY.md`](SECURITY.md)
- Deployment guides: [`../deployments/`](../deployments/)
