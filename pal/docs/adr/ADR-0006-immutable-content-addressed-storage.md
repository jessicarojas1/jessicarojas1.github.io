# ADR-0006: Immutable, content-addressed blob storage behind an abstraction

- **Status:** Proposed
- **Date:** 2026-10-25

## Decision
Files are stored once under their SHA-256 (`sha256/ab/cd/<hash>`), write-once, behind `IBlobStore` with filesystem/NAS, Azure Blob (managed identity) and S3 implementations. Uploads land in quarantine and are promoted only after type detection and a clean malware scan. The application identity cannot delete or overwrite. Disposition is a separate, privileged and audited path. Users never see storage keys or paths.

## Consequences
- Approval integrity is checked by re-hashing. Rollback and duplicate uploads cost no extra storage.
- Deletion for retention needs reference counting across revisions. This is a Phase 5 design item.
- On-prem MinIO is not the default because of 2025 changes to its community distribution. Re-evaluate if S3 semantics are required on-prem.
