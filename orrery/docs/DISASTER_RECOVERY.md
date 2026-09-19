# ORRERY — Disaster Recovery

> Discovery-phase. Targets and runbook below apply from Phase 1, when the
> database and integrations exist. The discovery microsite itself is stateless
> and redeployable from source.

## What holds state

| State | Location | Recovery source |
|-------|----------|-----------------|
| Portal config, content, metadata, audit | PostgreSQL | DB backups (PITR) |
| Documents | SharePoint Online (SoR) | M365 native retention/backup |
| Identity | Entra ID (SoR) | M365/Entra |
| Application code/config | Git repo + container registry | Rebuild from source |

The portal deliberately holds **no authoritative document bytes** — document DR
is inherited from M365.

## Targets (proposed; confirm with the business)

- **RPO ≤ 24h** (portal database), **RTO ≤ 4h**.
- Tighten per program/contract if required.

## Backups

- PostgreSQL: automated daily backups + point-in-time recovery (WAL).
- Container images: retained in the registry, tagged per release.
- Configuration: in source control (secrets excluded).

## Restore runbook

1. Provision database instance in the authorized boundary.
2. Restore latest PostgreSQL backup (or PITR to target timestamp).
3. Deploy the matching container image (same release tag).
4. Set environment variables/secrets from the secret manager.
5. Run `psql "$DATABASE_URL" -f database/schema.sql` only for a fresh build (idempotent).
6. Verify `/health` returns `ok`; verify SSO login; verify a document opens from SharePoint.
7. Confirm audit logging is writing; announce restoration.

## Verification cadence

- Restore drill at least **quarterly**; record RPO/RTO actuals.
- Validate that document access via Graph still resolves post-restore.

## High availability

- Stateless app: run ≥2 replicas behind a load balancer.
- PostgreSQL: managed HA / read replica per boundary capability.
