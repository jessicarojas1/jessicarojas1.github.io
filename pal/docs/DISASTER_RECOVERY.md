# PAL — Disaster Recovery

What PAL stores, how to back it up, and how to restore it with its audit trail provably intact.

> **Scope.** Through Phase 2. Application state is in **two places that must be recovered together**: PostgreSQL and the immutable blob store (`PAL_BLOB_DIR`).

Related: [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · single-host specifics in [SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md)

---

## Contents

1. [Recovery targets](#1-recovery-targets)
2. [What holds state](#2-what-holds-state)
3. [Backups](#3-backups)
4. [Restore runbook](#4-restore-runbook)
5. [Verification cadence](#5-verification-cadence)
6. [High availability](#6-high-availability)

---

## 1. Recovery targets

| Target | Value | Status |
|---|---|---|
| **RPO** (maximum data loss) | **15 minutes** | **Provisional** (owner decision D10). Needs confirmation by the IT / business owner before Phase 7. |
| **RTO** (maximum time to restore service) | **4 hours** | **Provisional** (D10). |

Plain language: after a server failure, at most the last 15 minutes of changes could be lost, and PAL should be back within half a working day. A 15-minute RPO **cannot** be met by nightly dumps alone; it needs continuous WAL archiving (§3). Tightening these targets later mostly means adding a standby database (§6), not redesigning PAL.

## 2. What holds state

### 2.1 PostgreSQL

| Store | Contents | Backed up by | Loss impact |
|---|---|---|---|
| PostgreSQL `app_user` | Users keyed by Entra `(tid, oid)` or a local username, display name, email, timezone preference, active flag | DB backup | Entra users re-provision on next sign-in, but timezone preferences and deactivation history are lost; local users are lost; **revisions and audit reference these rows** |
| PostgreSQL `local_credential`, `user_role` | Password hashes, lockout state, explicit roles | DB backup | Local accounts cannot sign in; explicit roles must be re-entered |
| PostgreSQL `document`, `revision`, `revision_file`, `document_grant`, `workflow_*`, `approval_decision`, `publication`, `org_unit`, `document_type`, `numbering_counter` | Document records, approvals, publications, reference data | DB backup | **Document control record lost**; cannot be regenerated |
| PostgreSQL `stored_file`, `upload` | SHA-256 and metadata of every stored file; every upload attempt | DB backup | Blobs become unreachable; upload evidence lost |
| PostgreSQL `directory_group`, `group_membership` | Tracked groups and membership history (closed with `valid_to`, never deleted) | DB backup | Current memberships re-sync from Graph within 15 min; **membership history is not recoverable** from Graph |
| PostgreSQL `global_role_mapping` | Group → role mappings | DB backup | Access configuration lost; must be re-entered |
| PostgreSQL `org_setting` | Branding, default timezone | DB backup | Branding reverts to defaults |
| PostgreSQL `audit_event` | Append-only, hash-chained audit trail (system of record) | DB backup + external anchors (§2.2) + forwarded copy in Microsoft Sentinel | **Compliance evidence lost**; cannot be regenerated |
| PostgreSQL `sessions` | Server-side HTTP sessions | DB backup (no value in restoring; see runbook step 9) | Users sign in again |
| PostgreSQL `messenger_messages` | Durable queue (`async`, `failed`) | DB backup | Nothing is routed to `async` yet; scheduled jobs recur on their own |
| PostgreSQL `doctrine_migration_versions` | Applied migrations | DB backup | Required for migrations to resume correctly |

### 2.2 Blob store and other state outside the database

| Item | Where | Back up how |
|---|---|---|
| **Document files (blobs)** | `$PAL_BLOB_DIR/sha256/ab/cd/<hash>` (image default `/srv/pal/data/blobs`; Compose volume `pal-blobs`, or a NAS bind mount). Referenced by `stored_file.sha256`. | **Required, together with the database** (§3.1). Loss means approved documents cannot be downloaded or released, and they cannot be regenerated. |
| ClamAV signatures | Compose volume `pal-clamav` | Not needed: re-downloaded by `freshclam` (or re-copied from the offline mirror). Uploads fail closed until clamd is back. |
| Audit anchors | `$PAL_AUDIT_ANCHOR_DIR/audit-anchors.jsonl` (one JSON line per daily verification: `anchored_at`, `last_event_id`, `head_hash`, `events`) | Must live on **write-once storage owned by another team**, with its own backup. Never on the same host or backup set as the database: its purpose is to detect a database rewrite. Optional; anchoring is off when the variable is empty. |
| Configuration | `deploy/pal.env` (or orchestrator config) | Secret store / configuration management. Contains secrets; never commit. |
| Secrets | `APP_SECRET`, DB passwords, Entra certificate + key (or client secret). Bootstrap admin values should already be deleted. | Secret store. The Entra certificate can be re-issued (see [SECURITY.md §9](SECURITY.md#9-secrets-rotation)). |
| Container image | Registry tag of the deployed release | Retain previous tags; the image is rebuildable from Git but a rebuild picks up newer base images. |
| Entra app registration | Entra ID tenant | Owned by the Entra administrators; document its settings (redirect URIs, permissions). |
| Logs | Microsoft Sentinel | Retention per SOC policy. The `pal_audit` stream is a second copy of every audit event and can show which events were lost between a recovery point and the failure. |

Other than the blob volume, containers write only to tmpfs (`var/cache`, `var/log`, `/tmp`), which holds no data worth restoring. In-flight upload temp files in `/tmp` are lost with the container; those uploads simply have to be repeated.

### 2.3 Why the two must be consistent

The database references blobs by SHA-256 ([ADR-0006](adr/ADR-0006-immutable-content-addressed-storage.md)). A restore is consistent only if the blob store contains every blob the restored database references.

- **Extra blobs are harmless** (content-addressed, unreferenced). `UploadService` writes the blob before the database row for this reason.
- **Missing blobs are not:** downloads of those files fail and release refuses to proceed. A **corrupt** blob is caught at release by re-hashing (409 `approval_integrity_failed`) but not on download.

## 3. Backups

PAL ships no backup tooling; backups are an operator responsibility. Recommended for the on-premises target, consistent with [Proposal §14.2](PHASE0_ARCHITECTURE_PROPOSAL.md#142-option-a-on-premises-linux-selected-d2):

| Layer | Recommendation |
|---|---|
| Tool | **pgBackRest** (or an equivalent that supports PITR, e.g. Barman, or your platform's managed backups) |
| WAL archiving | Continuous (`archive_mode = on`, `archive_command` via pgBackRest). Set `archive_timeout` well under the RPO (for example `60s`) so a quiet database still ships WAL segments. |
| Base backups | Full weekly, differential or incremental daily |
| Repository | Off the database host; a second copy off-site. Enable repository encryption. |
| Retention | At least two full-backup cycles plus the WAL needed for PITR across them; align with records-retention requirements (unknown, see [Proposal §2.1 A11](PHASE0_ARCHITECTURE_PROPOSAL.md#21-ambiguities-need-a-ruling-mostly-low-cost-to-resolve)) |
| Logical dump (secondary) | `pg_dump -Fc` nightly as a portable copy (e.g. for restoring into a different major version). It is not sufficient for the 15-minute RPO on its own. |
| Before migrations | Take an on-demand backup before every `migrate` (down-migrations drop the audit table; see [DEPLOYMENT.md §5.3](DEPLOYMENT.md#53-rollback)). |
| Anchors | Backed up by the team that owns the write-once store, separately from the database. |

### 3.1 Blob store

| Layer | Recommendation |
|---|---|
| Order | **Database backup first, blob snapshot after it.** Blobs are never overwritten or deleted by the app, so the later snapshot is a superset of what the earlier database references |
| Point-in-time recovery | Blobs must be snapshotted at least as often as the RPO, or replicated. A PITR target later than the newest blob snapshot can reference files that were not captured (§4 step 8a finds them). For a 15-minute RPO, use NAS/storage snapshots or continuous replication, not a nightly copy |
| Method | Storage snapshots of the NAS share (recommended), or an incremental copy that preserves owner and mode: `rsync -a --ignore-existing`. Write-once files make incremental copies cheap and safe to run while PAL is up; ignore `.incoming-*` temp files |
| Named volume (Compose default) | `docker run --rm -v pal_pal-blobs:/data:ro -v /backup:/backup <utility-image> tar -C /data -czf /backup/pal-blobs-<timestamp>.tar.gz .` (the volume's name is prefixed by the Compose project `pal`). Prefer a NAS bind mount in production |
| Repository | Off the application host, a second copy off-site, encrypted |
| Retention | Not shorter than the database backups it must pair with |

**Bundled database caveat.** `deploy/compose.yaml --profile bundled-db` keeps data in the Docker volume `pal-db` on the app host. Copying that volume while PostgreSQL runs is not a valid backup, and it puts the database and the app on one failure domain. For production, run PostgreSQL on its own host (as the proposal recommends) with pgBackRest there.

## 4. Restore runbook

Assumes the single-host Compose deployment with an **external** PostgreSQL 17 managed by pgBackRest, stanza `pal`, owner role `pal_owner`, app role `pal_app`, database `pal`. Adjust names to your installation. Commands run from the repository checkout that holds `pal/deploy/pal.env`. Record the time of every step; the totals are your achieved RPO/RTO.

1. **Declare the incident and stop writers.** Stop web and worker so nothing writes during or after the restore.
   ```bash
   docker compose -f pal/deploy/compose.yaml stop web worker
   ```

2. **Preserve evidence.** Before touching the database, record the latest external anchors and, if the old database is still readable, its chain head. Keep these outside the database host.
   ```bash
   tail -n 5 "$PAL_AUDIT_ANCHOR_DIR/audit-anchors.jsonl" | tee /root/pal-dr-anchors-before.txt
   psql "$ADMIN_URL" -At -c "SELECT id, hash FROM audit_event ORDER BY id DESC LIMIT 1" | tee /root/pal-dr-head-before.txt
   ```
   The second command fails if the database is gone; that is expected.

3. **Choose the recovery target.** Latest consistent state (default) or a point in time just before a destructive event (for example an accidental bulk change). Note it in UTC.

4. **Restore PostgreSQL** on the database host (PostgreSQL stopped, data directory present or empty).
   Latest state:
   ```bash
   sudo -u postgres pgbackrest --stanza=pal --delta restore
   ```
   Point in time:
   ```bash
   sudo -u postgres pgbackrest --stanza=pal --delta \
        --type=time --target="2026-10-25 14:00:00+00" --target-action=promote restore
   ```
   Then start PostgreSQL with your service manager and wait for recovery to finish:
   ```bash
   psql "$ADMIN_URL" -At -c "SELECT pg_is_in_recovery()"     # must print: f
   ```
   For a logical-dump restore into a fresh cluster instead: create the database, run step 5 first, then `pg_restore --no-owner --role=pal_owner -d "$ADMIN_URL_PAL" pal.dump`.

4a. **Restore the blob store** (skip if the storage survived). Use the newest snapshot taken **at or after** the database recovery point, into `PAL_BLOB_DIR`'s storage, preserving ownership (uid 10001) and modes:
   ```bash
   rsync -a --ignore-existing /backup/pal-blobs/ /mnt/pal-blobs/blobs/
   chown -R 10001:10001 /mnt/pal-blobs/blobs
   ```
   Newer files than the database recovery point are harmless; do not delete them (they would be needed if you later choose a later recovery point). Order matters only in one direction: PAL must not accept traffic until both are back (step 1 stopped it, step 10 starts it).

5. **Re-apply roles and grants** (idempotent; required after a logical restore, harmless after a physical one):
   ```bash
   psql "$ADMIN_URL" -v ON_ERROR_STOP=1 \
        -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal \
        -f pal/deploy/sql/roles.sql
   ```
   If the cluster is new, set the role passwords with `\password` to match the secret store.

6. **Bring the schema current** (no-op if the backup already has every migration; required if you restored a backup older than the deployed image):
   ```bash
   docker compose -f pal/deploy/compose.yaml run --rm migrate
   ```
   If you are rolling back a failed release, deploy the previous image tag (`PAL_IMAGE=...`) before this step instead.

7. **Verify the audit chain** in the restored database:
   ```bash
   docker compose -f pal/deploy/compose.yaml run --rm --no-deps migrate console pal:audit:verify
   ```
   Expected: `Audit chain valid: N events, head <hash>`. If it prints `Audit chain BROKEN at event <id>`, **stop**: treat it as an integrity incident, keep PAL offline, preserve the restored database and the backup set, and escalate to the security owner.

8. **Compare with the latest external anchor.** `pal:audit:verify` proves internal consistency only; the anchor proves the chain was not rewritten as a whole. Find the restored maximum event ID:
   ```bash
   psql "$ADMIN_URL" -At -c "SELECT max(id) FROM audit_event"
   ```
   Take the newest anchor line whose `last_event_id` is **less than or equal to** that maximum, and check that the restored row has the anchored hash:
   ```bash
   psql "$ADMIN_URL" -At -c "SELECT hash FROM audit_event WHERE id = <last_event_id>"
   # must equal the anchor's head_hash
   ```
   - **Match:** the restored trail is consistent with independent evidence.
   - **Mismatch:** stop, as in step 7.
   - **Anchors newer than the restored maximum:** expected after a point-in-time restore or when the last WAL was lost. Events after the recovery point are gone from the database. Record the range (`max(id)+1` to the newer anchor's `last_event_id`) in the incident report, and use the `pal_audit` records in Sentinel for that window to document what was lost.

8a. **Check blob consistency.** Run PAL's verifier, which checks every `stored_file` row exists in the blob store and still matches its SHA-256:
   ```bash
   docker compose -f pal/deploy/compose.yaml run --rm --no-deps migrate console pal:storage:verify
   ```
   Expected: `Storage verified: N file(s) present and intact`. It exits non-zero and lists the offending hashes under **Missing** / **Corrupt** otherwise — handle them as below. If the database and blob storage are on a host without PHP/PAL, use this equivalent bash instead:
   ```bash
   BLOB_ROOT=/mnt/pal-blobs/blobs
   psql "$ADMIN_URL" -At -c "SELECT sha256 FROM stored_file" | while read -r h; do
     f="$BLOB_ROOT/sha256/${h:0:2}/${h:2:2}/$h"
     if [ ! -f "$f" ]; then echo "MISSING $h"
     elif [ "$(sha256sum "$f" | cut -d' ' -f1)" != "$h" ]; then echo "CORRUPT $h"; fi
   done
   ```
   Expected: no output. Which documents are affected by a listed hash:
   ```bash
   psql "$ADMIN_URL" -c "SELECT d.document_number, r.major, r.minor, r.state, rf.original_filename FROM revision_file rf JOIN revision r ON r.id = rf.revision_id JOIN document d ON d.id = r.document_id WHERE rf.sha256 = '<hash>'"
   ```
   - **Missing:** find it in an older or newer blob snapshot (the hash proves it is the right file). If unrecoverable, record it in the incident report; the audit trail still shows what was approved.
   - **Corrupt:** treat as an integrity incident, as in step 7. Restore that file from another snapshot and re-check.

9. **Invalidate restored sessions** (recommended). Sessions in the backup belong to the past; clearing them forces everyone to sign in again and removes any session that existed at backup time.
   ```bash
   psql "$ADMIN_URL" -c "TRUNCATE sessions"
   ```

10. **Start PAL and check health.**
    ```bash
    docker compose -f pal/deploy/compose.yaml up -d web worker
    curl -fsS https://<pal-host>/health/ready
    ```
    Expected: `{"status":"ready","checks":{"database":"ok","migrations":"ok","schema_version":"ok","storage":"ok"}}`.

11. **Functional checks.** Sign in; confirm roles in `GET /api/me`; open Admin → Role mappings; **download a published document** and upload a test file (confirms blobs and clamd); run a directory sync so memberships reflect the directory now:
    ```bash
    docker compose -f pal/deploy/compose.yaml run --rm --no-deps migrate console pal:directory:sync
    ```

12. **Re-anchor.** Write a fresh anchor so the post-restore head is independently recorded. The one-off container needs the anchor directory mounted at `PAL_AUDIT_ANCHOR_DIR` (the default Compose file does not mount it); otherwise wait for the 02:15 UTC scheduled run on a worker that has the mount.
    ```bash
    docker compose -f pal/deploy/compose.yaml run --rm --no-deps \
        -v /path/to/anchor-share:"$PAL_AUDIT_ANCHOR_DIR" migrate console pal:audit:verify --anchor
    ```

13. **Close out.** Record achieved RPO (failure time minus recovery point) and RTO (declaration to step 10), the audit-gap range if any, and lessons learned. Take a fresh full backup.

## 5. Verification cadence

| Frequency | Activity | Evidence |
|---|---|---|
| Continuous | Alert when the newest archived WAL is older than the RPO, or the last successful backup is older than its schedule | Monitoring alert history |
| Daily | Scheduled `VerifyAuditChain` produces `AUDIT_CHAIN_VERIFIED`; alert on the `critical` "Audit chain verification FAILED" log | Audit table, Sentinel |
| Daily | `pgbackrest --stanza=pal check` (archiving works end to end) | Job log |
| At least every RPO interval | Blob snapshot or replication job succeeded | Job log, monitoring alert |
| Monthly | `pgbackrest --stanza=pal verify` (backup set integrity); confirm anchors are being appended and copied off-host | Job log, anchor file |
| **Quarterly** | **Full restore drill** to an isolated host, database **and blobs**: steps 4–8a and 10 of the runbook, timed against RPO/RTO ([Proposal §14.2](PHASE0_ARCHITECTURE_PROPOSAL.md#142-option-a-on-premises-linux-selected-d2)) | Drill report with times and verify/anchor output |
| Every release with a migration | Backup before `migrate`; spot-restore it in staging | Release checklist |
| Phase 7 | DR drill meeting RPO ≤ 15 min and RTO ≤ 4 h is a production-readiness criterion ([Proposal §17](PHASE0_ARCHITECTURE_PROPOSAL.md#17-production-readiness-acceptance-criteria)) | Phase 7 evidence pack |

The Phase 1 exit criterion "restore of empty DB from backup demonstrated" ([Proposal §16](PHASE0_ARCHITECTURE_PROPOSAL.md#16-phased-delivery-plan)) has no recorded evidence in this repository yet.

## 6. High availability

PAL's design target is about 300 users initially and 1,000+ later ([ADR-0001](adr/ADR-0001-modular-monolith.md)). The default single-host Compose deployment is **not highly available**: the host, and the database if bundled, are single points of failure, and recovery relies on §4 within the RTO.

| Component | HA option | Notes |
|---|---|---|
| Web | Run two or more `web` containers (on one or more hosts) behind the reverse proxy / load balancer | Sessions are in PostgreSQL, so no sticky sessions are needed. **All instances must mount the same blob storage.** The rate limiters and OIDC metadata cache are per container (filesystem cache), so limits are per instance. |
| Blob store | Redundant NAS with snapshots and replication | Single point of failure if on a local Docker volume. Atomic rename and write-once naming make shared writes safe by design, but this has not been tested on NFS. If it is down, `/health/ready` fails `storage` only when the mount is missing or read-only; a hung mount may not be detected. |
| ClamAV | Restart policy; a second `clamd` behind a TCP load balancer if uploads are critical | Uploads fail closed while it is down; reading and downloading are unaffected. |
| Worker | Run two or more `worker` containers | Safe: the scheduler takes a PostgreSQL advisory lock, so jobs fire once. |
| PostgreSQL | Streaming-replication standby on a second host, promoted manually or by a failover manager (e.g. Patroni) | The main lever for a tighter RPO/RTO (D10). With synchronous replication the RPO approaches zero at some write-latency cost. Point clients at a virtual IP or DNS name that follows the primary. |
| Audit anchors | Write-once share with its own redundancy, owned by another team | Unavailability blocks anchoring only, not PAL. |
| Entra ID / Graph | External dependency | If Entra is unreachable, existing sessions keep working; new sign-ins fail with `sign_in_unavailable`. If Graph is unreachable, sign-in still succeeds with the last synced memberships. |
| Reverse proxy | Pair with keepalived/VRRP or use an existing load balancer | Outside PAL. |

Kubernetes is not recommended at this scale ([ADR-0001](adr/ADR-0001-modular-monolith.md)); see [KUBERNETES.md](../deployments/KUBERNETES.md) if it is mandated anyway.
