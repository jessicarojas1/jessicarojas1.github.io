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

For each new record:

```
prev_hash = log_hash of the most recent existing row  (or "genesis" if none)
payload   = prev_hash | user_id | action | entity_type | entity_id | changes | ip
log_hash  = sha256(payload)
```

Because every `log_hash` folds in the previous record's hash, the chain is
append-only and order-sensitive: altering or removing any record invalidates the
hash of **every** record after it.

> Note: `logSystem()` includes a timestamp in its payload; `log()` does not.
> The verifier recomputes user events; legacy rows written before the chain was
> introduced carry `log_hash = 'genesis'` and are skipped.

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
