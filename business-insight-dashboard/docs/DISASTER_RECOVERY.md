# Disaster Recovery — Business Insight Dashboard

> Canonical DR runbook.
> Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md)
> Operator guides: [../deployments/](../deployments/)

---

## 1. What Holds State

This application is **near-stateless by design**, which makes disaster recovery unusually simple.

| Asset | Kind | Recovery source | Notes |
|-------|------|-----------------|-------|
| Application code | Code of record | Git repo + container image | Rebuild/redeploy the image |
| Uploaded CSV data | **Ephemeral** | *Nothing to recover* | Processed in memory only; never written to disk or transmitted. Lost on session end by design. |
| `branding.json` | Persisted config | Backup / volume snapshot / config-as-code | The **only** server-side persisted state (`{logo, name, accent}`) |
| Streamlit session state | Transient | *Nothing to recover* | Per-session, in-memory |

**Key implication:** there is no database, no queue, and no user data at rest. A total loss of the running instance loses **nothing except in-flight sessions** — which are expected to be re-uploaded by users anyway. The only thing worth backing up is `branding.json`.

---

## 2. RPO / RTO Targets

| Asset | RPO (max data loss) | RTO (max downtime) | Rationale |
|-------|--------------------|--------------------|-----------|
| Application code | **~0** | Minutes | Immutable image in registry + git; redeploy |
| `branding.json` | **Minutes** (since last backup/commit) | Minutes | Small file; restore from snapshot or re-apply config-as-code |
| Uploaded data | **N/A** | N/A | Ephemeral by design — nothing to protect |

Because the app is near-stateless, **RTO is effectively "time to redeploy the image."** Set backup cadence for `branding.json` to match the acceptable RPO (e.g. snapshot hourly, or commit branding to a config repo so RPO = 0 for it).

---

## 3. Backups

`branding.json` is the sole backup target. Choose one (or more) strategy:

1. **Config-as-code (recommended).** Commit `branding.json` to a config repository / config store. RPO for branding becomes effectively 0, and restore is a redeploy. Best fit for multi-replica (avoids per-replica drift — see §6).
2. **Volume snapshot.** If `branding.json` lives on a persistent volume, snapshot the volume on the platform's schedule (EBS snapshot, Azure disk snapshot, PVC snapshot).
3. **File snapshot.** Periodically copy `branding.json` to object storage:

```bash
# example: back up branding.json to S3 (adjust bucket/path)
aws s3 cp /app/branding.json s3://my-backups/biz-insight/branding.json \
  --sse aws:kms
```

No other files require backup — the image and git repo already are the code of record.

---

## 4. Restore Runbook

Copy-pasteable. Assumes the container image is available in the registry.

```bash
# 1. Redeploy the application image (no migrations, no DB to restore).
#    Render:      trigger a redeploy of the latest known-good image.
#    Kubernetes:  kubectl rollout restart deployment/biz-insight-dashboard
#    ECS:         aws ecs update-service --force-new-deployment ...

# 2. Restore branding.json (skip if using config-as-code — it deploys with the image).
#    From object storage:
aws s3 cp s3://my-backups/biz-insight/branding.json /app/branding.json
#    Or from a volume snapshot: reattach/restore the volume, then verify the file exists.
#    If branding.json is missing entirely, the app degrades to built-in defaults — the UI still works.

# 3. Verify health.
curl -fsS http://<host>/_stcore/health   # expect: ok

# 4. Verify functional upload (smoke test).
#    Open the app in a browser, upload sample_data/sample_business.csv,
#    confirm the KPI row, charts, and insight cards render.

# 5. Verify branding.
#    Confirm the restored logo/name/accent appear in the header/sidebar.
#    (If branding.json was lost, re-enter values via Settings → Branding.)
```

Restore is complete once `/_stcore/health` returns `ok` and an upload renders KPIs, charts, and insights.

---

## 5. Verification Cadence (Restore Drills)

| Activity | Cadence |
|----------|---------|
| `branding.json` backup integrity check | Weekly (confirm snapshot/object exists and parses as JSON) |
| Full restore drill (redeploy from registry + restore branding + smoke test) | Quarterly |
| Health-probe alerting validation | Monthly (confirm probes fire on a killed pod) |

Log each drill (date, RTO observed, issues). Because state is minimal, drills are fast — there is no excuse to skip them.

---

## 6. High Availability

Run **multiple replicas behind a sticky load balancer** (WebSocket-aware, session affinity — see [DEPLOYMENT.md](DEPLOYMENT.md) §9.2).

> **Branding caveat:** `branding.json` is per-replica local state. With multiple replicas on independent disks, branding edits made on one replica **will not appear** on others, causing inconsistent UI per session. Mitigate with **one** of:
>
> - **Shared volume** for `branding.json` mounted read-write by all replicas (e.g. EFS / Azure Files / RWX PVC), **or**
> - **Config-as-code**: bake `branding.json` into the image / deploy it via config and make the Settings UI read-only in production.

The application otherwise scales horizontally without coordination (no DB, no shared session store beyond sticky routing). Losing a replica loses only that replica's in-flight sessions; the LB routes new sessions elsewhere.
