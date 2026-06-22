# Centralized Immutable Audit Logging — Design

**Status:** Design + applyable infrastructure (the Terraform module exists at [`platform/audit-sink/`](platform/audit-sink/))
**Driver:** Enterprise Security Review §25 — "Per-app audit exists (aegis, paladin, sentinel), but there is **no centralized, tamper-evident, time-synced audit aggregation** across apps — required for NIST AU family / CMMC AU / HIPAA §164.312(b)."
**Companion:** [`SECURITY_BASELINE.md`](SECURITY_BASELINE.md), [`platform/audit-sink/README.md`](platform/audit-sink/README.md)

---

## 1. Problem & goal

Each flagship app already keeps a good per-app audit trail — but in **isolated, mutable, per-app databases**. An attacker (or a malicious DBA) who compromises an app can alter or truncate its own audit table, and there is no cross-app correlation for incident response.

**Goal:** a single, **write-once (WORM), KMS-encrypted, retained** audit store that every app ships to, with least-privilege append-only access, legal-hold support, and a SIEM feed — without rewriting each app's existing audit logic.

**Non-goal:** replacing per-app audit logic. Apps keep their local trail (for in-app activity views); they additionally **mirror** each event to the central sink.

---

## 2. Current per-app audit state (the inputs)

All three flagship apps already produce structured, hash-chained audit records. The central sink consumes them as-is.

| App | Table / model | Tamper-evidence | Write path | Reference |
|---|---|---|---|---|
| **aegis** | `activity_log` (user_id, action, entity_type, entity_id, changes, ip_address, user_agent, created_at, **log_hash**) | HMAC-SHA256 **hash chain** serialized via `pg_advisory_lock(hashtext('aegis_audit_chain'))` | `Auth::appendAuditLog()` / `log()` / `logSystem()` | `aegis/src/Auth.php:490-553`; chain key `aegis/src/Security.php:252-258`; migration `database/migrations/001_enterprise_phase1.sql` |
| **paladin** | `activity_log` (same shape, **log_hash**) | SHA-256 hash chain (prev_hash + action + user + time) | `Auth::logLogin()` / `logChange()` / `logAction()` | `paladin/src/Auth.php:391-561`; activity feed `paladin/src/Activity.php:44-107` |
| **sentinel-qms** | `AuditLog` (actor_id, actor_email, action, entity_type, entity_id, before/after JSON, ip_address, request_id, created_at) | Append-only; secret fields redacted (`hashed_password`, `password`, `signed_hash`) | `audit.record()` joins the caller's DB transaction (`db.flush()`) + structured log emit + best-effort webhook | `sentinel-qms/backend/app/core/audit.py:54-118`; model `app/models/user.py:72-96` |

Key observation: aegis/paladin produce an **HMAC/SHA-256 hash chain** in the `log_hash` column, and sentinel already supports a **best-effort outbound webhook** on every `audit.record()`. The central sink leans on both: the hash chain proves intra-app integrity; S3 Object Lock proves the central copy was never altered.

---

## 3. Architecture

```
 ┌─────────────┐   ┌─────────────┐   ┌─────────────────┐
 │   aegis     │   │  paladin    │   │  sentinel-qms   │     (and future apps)
 │ activity_log│   │ activity_log│   │   AuditLog      │
 └──────┬──────┘   └──────┬──────┘   └────────┬────────┘
        │ ship each event (JSON, with log_hash / request_id)
        ▼                 ▼                    ▼
   ┌──────────────────────────────────────────────────────┐
   │ CloudWatch Logs:  /audit/<prefix>/{aegis,paladin,...} │  hot/queryable tier (SSE-KMS, retained)
   └───────────────────────────┬──────────────────────────┘
                               │ subscription filter (forward ALL)
                               ▼
                   ┌───────────────────────┐
                   │  Kinesis Firehose     │ (SSE-KMS, GZIP, dated prefixes)
                   └───────────┬───────────┘
                               ▼
        ┌──────────────────────────────────────────────┐
        │ S3 (Object Lock COMPLIANCE, versioned, SSE-KMS│  immutable archive (WORM)
        │  bucket policy DENIES all delete/lock-weaken) │
        └──────────────────────┬───────────────────────┘
                               │ (optional) S3 notification / Firehose tee
                               ▼
                        ┌──────────────┐
                        │     SIEM     │  (Splunk / OpenSearch / Sentinel)
                        └──────────────┘
```

The infrastructure for the CloudWatch → Firehose → locked-S3 path is the [`platform/audit-sink`](platform/audit-sink/) Terraform module. Two tiers by design:

- **Hot tier — CloudWatch Logs:** queryable, alertable, the SIEM feed. Retention `log_retention_days` (default 400).
- **Cold tier — S3 Object Lock COMPLIANCE:** the legally authoritative, write-once archive. Retention `object_lock_retention_days` (default 3 years). Not deletable by anyone, including root, until retention expires.

---

## 4. How each app ships its audit log

The principle: **change the audit sink, not the audit logic.** Each app already funnels every event through one or two methods — add a single emit there.

### 4.1 aegis & paladin (PHP)

Both centralize writes in `Auth::appendAuditLog()` (aegis) / `Auth::logChange()`/`logAction()` (paladin). Add one append-only CloudWatch emit at the end of those methods, sending the exact row that was just inserted (including `log_hash`), so the central copy carries the intra-app chain proof:

- Use the AWS SDK for PHP `CloudWatchLogsClient::putLogEvents` against group `/audit/<prefix>/aegis` (resp. `/paladin`), stream = instance/host id.
- Credentials = the task/instance role granted `module.audit_sink.writer_policy_arn` (append-only; no S3 access).
- Emit is **best-effort + buffered**: a CloudWatch failure must never block the user transaction (the local `activity_log` row is already the source of truth). Failures are themselves logged locally.
- Time sync: rows carry the DB `created_at`; hosts run NTP/chrony (NIST AU-8). CloudWatch also stamps ingestion time.

### 4.2 sentinel-qms (FastAPI)

sentinel already has the cleanest hook: `audit.record()` (`backend/app/core/audit.py:54-118`) does a structured log emit and a best-effort webhook on every event. Two equivalent options:

1. **Structured-log route (preferred on ECS/EKS):** the container's stdout/structured logger is already wired to a CloudWatch log group; point that group at `/audit/<prefix>/sentinel-qms` (or add a subscription from the app log group to the same Firehose). Zero app code change.
2. **Webhook route:** point the existing audit webhook at a small Lambda/endpoint that does `PutLogEvents`. Reuses the redaction already applied at `audit.py:18`.

### 4.3 New apps

New back-end apps adopt the same contract: funnel every security-relevant event through one audit function, then emit append-only to `/audit/<prefix>/<app>`. The IAM writer policy (`writer_policy_arn`) is attached to the app's runtime role. This is now part of [`SECURITY_BASELINE.md`](SECURITY_BASELINE.md) §13 acceptance.

### 4.4 Canonical event shape (normalized at ingest or in the emitter)

```json
{
  "ts": "2026-06-22T18:00:00Z",        // app event time (created_at)
  "app": "aegis",                       // source app
  "actor_id": 42, "actor_email": null,  // null for system/anonymous
  "action": "risk.accept",              // granular module.action
  "entity_type": "risks", "entity_id": "1187",
  "ip": "203.0.113.7", "request_id": "…",
  "changes": { … },                     // redacted; secrets stripped
  "log_hash": "…",                      // aegis/paladin intra-app chain proof
  "tenant_id": 1                        // when the app is tenant-scoped (see TENANT_ISOLATION.md)
}
```

---

## 5. Tamper-evidence — defense in depth

1. **Intra-app:** aegis/paladin hash chain (`log_hash`) — any altered/removed local row breaks the chain.
2. **In transit:** TLS-only (bucket policy `DenyInsecureTransport`); CloudWatch/Firehose use SSE-KMS.
3. **At rest, central:** S3 **Object Lock COMPLIANCE** — objects cannot be overwritten or deleted (even by root) until retention expires; bucket policy additionally `Deny`s `DeleteObject*`, `PutObjectRetention`, `BypassGovernanceRetention`, `PutBucketVersioning`, `PutLifecycleConfiguration`, `DeleteBucket*` for all principals.
4. **Access separation:** writers can only `CreateLogStream`+`PutLogEvents` (never touch S3); a separate **MFA-gated legal-hold role** is the only principal allowed to toggle Object Lock legal holds — separation of duties.

This satisfies NIST AU-9 (protection of audit information) end to end, not just at one layer.

---

## 6. Retention & legal hold

- **Default retention:** `object_lock_retention_days = 1095` (3 years) — tune to the longest applicable framework (CUI/CMMC commonly ≥ 3 yr; some HIPAA contexts 6 yr).
- **Hot-tier retention:** `log_retention_days = 400` in CloudWatch; older events live only in the immutable S3 archive (rehydrate to the SIEM on demand).
- **Legal hold:** for litigation/investigation, the dedicated `legal_hold_role` (MFA-required) sets an S3 Object Lock **legal hold** on the relevant objects — this holds them indefinitely, independent of the retention clock, until the hold is released. Kept distinct from writers and operators.
- **Decommissioning:** `force_destroy = false` + delete-deny policy mean a `terraform destroy` will not wipe the archive. Removal requires retention expiry or a documented, approved exception.

---

## 7. SIEM integration path

- **Primary:** CloudWatch Logs is the live feed. Stream `/audit/<prefix>/*` to the SIEM via (a) an additional Firehose consumer to OpenSearch/Splunk HEC, or (b) the SIEM's native CloudWatch Logs ingestion, or (c) a Logs subscription to the SIEM's collector Lambda.
- **Correlation:** the normalized event shape (`app`, `actor_*`, `action`, `request_id`, `tenant_id`) gives cross-app correlation — e.g. follow one `request_id`/actor across aegis + sentinel during an incident.
- **Detections (examples):** spikes in `login_failed` per actor/IP, `platform.tenant_switch` events (aegis cross-tenant admin), privilege-grant actions, audit-chain gaps, off-hours `*.delete`/`*.publish`.
- **Replay/forensics:** the S3 archive is the authoritative source for re-ingestion; objects are GZIP JSON under `audit/yyyy/MM/dd/` and decrypt with the sink CMK (read-only access for investigators).

---

## 8. Rollout

1. **Apply infra** — deploy `platform/audit-sink` per environment (`name_prefix` per env), in the same partition as the apps (`aws`/`aws-us-gov`).
2. **Attach writer policy** — add `writer_policy_arn` to each app's task/instance role.
3. **Wire emitters** — aegis/paladin: one `putLogEvents` at the end of the existing audit method; sentinel: point its log group / webhook at the sink (no app change for option 1).
4. **Verify** — confirm events land in CloudWatch and in S3 (dated prefix), and that a delete attempt on a locked object is denied.
5. **Connect SIEM** — subscribe the SIEM to `/audit/<prefix>/*`; build the detections above.
6. **Document retention/legal-hold** runbook and the legal-hold role assumption procedure.

---

## 9. Status

| Item | Status |
|---|---|
| Locked-S3 + CloudWatch + Firehose + IAM infrastructure | **Ready to apply** (`platform/audit-sink`, `terraform fmt` clean) |
| aegis/paladin per-app emit hook | Design — one-line emit in existing audit method (small, per-app PR) |
| sentinel-qms shipping (log-group route) | Design — wiring only, no app code change |
| SIEM subscription + detections | Design — depends on chosen SIEM |
