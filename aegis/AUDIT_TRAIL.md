# AEGIS — Audit Trail

AEGIS maintains a **tamper-evident** audit trail in the `activity_log` table. Each
record is bound to the previous one through a SHA-256 **hash chain**, so any
insertion, deletion, or modification of a historical record breaks verification.

## What is recorded

`Auth::log($action, $entityType, $entityId, $changes)` records an actioned event
for the **authenticated user**; `Auth::logSystem(...)` records actions taken by
the platform itself (no user). Each row captures:

| Column | Meaning |
|--------|---------|
| `user_id` | Actor (NULL for system events) |
| `action` | Action verb (e.g. `risk.accept`, `policy.publish`, `login.failed`) |
| `entity_type` | Entity kind (e.g. `risk`, `policy`, `audit`) |
| `entity_id` | Entity primary key |
| `changes` | JSON before/after summary where applicable |
| `ip_address` | Client IP |
| `user_agent` | Client user agent (truncated to 500 chars) |
| `log_hash` | SHA-256 chain hash for this record |
| `created_at` | Timestamp (DB default) |

## Hash chain construction

For each new record (`Auth::appendAuditLog()` → `Auth::computeLogHash()`):

```
prev_hash = log_hash of the most recent existing row  (or "genesis" if none)
payload   = prev_hash | user_id | action | entity_type | entity_id | changes | ip
log_hash  = HMAC-SHA256(payload, AUDIT_HMAC_KEY)
```

**Keyed (not just hashed).** The chain uses an **HMAC** with a dedicated key
(`Security::auditKey()` ← `AUDIT_HMAC_KEY`, or a JWT_SECRET-derived fallback), so
an attacker who can *write* the database but cannot *read* the key **cannot
recompute and forge** the chain. Set `AUDIT_HMAC_KEY` in a secret store the
database role cannot read for true integrity separation.

**Serialized appends.** Writes take a PostgreSQL session advisory lock
(`pg_advisory_lock`) so two concurrent requests cannot read the same `prev_hash`
and fork the chain (which would also cause false-positive verification). The lock
is best-effort and always released.

**Uniform payload — every row is verifiable.** User, system, and failed-login
rows all hash the *same* column set the verifier reconstructs (`user_id` is empty
for system/failed rows; the failed-login email is captured in `changes`). Legacy
rows written before the keyed migration still verify — the verifier accepts the
keyed HMAC **or** the legacy unkeyed SHA-256 for each row.

Because every `log_hash` folds in the previous record's hash, the chain is
append-only and order-sensitive: altering or removing any record invalidates the
hash of **every** record after it.

> **WORM option:** for CUI / legal-hold deployments, make `activity_log`
> append-only at the database level (`database/roles.sql`) so even a compromised
> app cannot modify/delete history. Note that the admin "retention/prune" feature
> deletes old rows — restrict it to a separate maintenance role when WORM is on.

## Actions that should be audited

Create, update, delete/void, approve, reject, publish, **risk accept**, **audit
close**, export, import, login, **failed login**, MFA events, API-key create/
revoke, permission change, webhook events, AIAdvisor use, and admin setting
changes. When adding a new state-changing controller action, add a matching
`Auth::log(...)` call.

## Verifying integrity

`scripts/verify_audit_log.php` walks every row in insertion order, recomputes the
chain, and compares against the stored `log_hash` using `hash_equals()`.

```bash
php scripts/verify_audit_log.php          # human-readable report
php scripts/verify_audit_log.php --quiet  # exit code only (for cron)
```

Exit codes: `0` = chain intact, `1` = tampering/corruption (first bad record ID
printed), `2` = bootstrap/config error.

### Scheduled verification (cron)

```cron
# Verify audit-log integrity hourly; alert on non-zero exit.
0 * * * * php /var/www/aegis/scripts/verify_audit_log.php --quiet || \
  /usr/local/bin/notify-oncall "AEGIS audit log integrity check FAILED"
```

## Operational guidance

- Treat a verification failure as a **security incident**: the database may have
  been modified outside the application. Preserve a backup and investigate.
- Restrict direct database write access; the application is the only intended
  writer of `activity_log`.
- Include audit-log export in compliance evidence packages (actor, action,
  entity, timestamp, IP, before/after) when auditors request access records.
- The per-request correlation ID (`AEGIS_REQUEST_ID`, also in the `X-Request-Id`
  header and error logs) lets you correlate an audit entry with application logs.
