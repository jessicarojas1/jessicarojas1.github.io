# PALADIN — Audit Trail

PALADIN records security-relevant, administrative and regulated actions in a
**tamper-evident, hash-chained** append-only log. This supports ISO 27001 /
SOC 2 evidence and FDA 21 CFR Part 11 §11.10(e) ("use of secure, computer-
generated, time-stamped audit trails").

## Storage

Table `activity_log` (`database/schema.sql`):

| Column | Meaning |
|--------|---------|
| `id` | monotonic `BIGSERIAL` (ordering) |
| `user_id` | actor (null for system/unauthenticated events) |
| `action` | event name, e.g. `approve_document`, `esignature`, `mfa_rate_limited` |
| `entity_type`, `entity_id` | the object acted on |
| `changes` | JSON detail (before/after or context) |
| `ip_address` | proxy-aware client IP (`Security::clientIp`) |
| `user_agent` | request user agent |
| `log_hash` | SHA-256 chain value (see below) |
| `created_at` | server timestamp |

## Tamper-evidence (hash chaining)

Each entry's `log_hash` is computed in `Auth::log()` as:

```
log_hash = sha256( prevHash | user_id | action | entity_type | entity_id | changes | ip )
```

where `prevHash` is the `log_hash` of the immediately preceding row (or the
literal `genesis` for the first entry). Because each hash incorporates the
previous one, **any insertion, deletion or modification of a historical row
breaks the chain** from that point forward and is detectable by recomputation.

> Integrity verification: walk the table in `id` order, recompute each
> `log_hash` from the stored fields + the prior hash, and confirm it matches the
> stored value. The first mismatch identifies the tampered row.

## What is audited

- **Authentication**: login success/failure, logout, session events,
  `mfa_rate_limited` lockouts.
- **IAM / administration**: user/role/permission changes, webhook create/delete/
  test, settings changes, SAML/OIDC/SCIM provisioning.
- **Controlled documents**: create, edit, version, `document_transition`
  (from → to), review/approve/reject, export (`export_document_register`, …).
- **Electronic signatures**: `esignature` (with decision, **meaning statement**,
  signer, timestamp, IP, user-agent and signature hash) and `esignature_failed`.
- **Acknowledgement campaigns**: creation, exports.
- **Content**: page/document create, duplicate, restriction changes, attachment
  download/delete, review-date extension.

## Immutability guidance

- The application only ever **inserts** into `activity_log`; there is no update or
  delete path in code.
- For production, additionally enforce immutability at the database/role level:
  grant the application role `INSERT`/`SELECT` only on `activity_log` (no
  `UPDATE`/`DELETE`), and ship the log to append-only/WORM storage or a SIEM.
- Electronic-signature records on approval steps (`signature_name`,
  `signed_at`, `signature_hash`) are written once and never updated by the
  application.

## Retention

- Retention is configurable (`src/Retention.php`, Administration → Retention).
  For regulated records, set retention to meet the longest applicable
  requirement and prefer archival over deletion. The hash chain should be
  preserved across archival boundaries.
