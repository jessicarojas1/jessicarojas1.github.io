# PAL — Deployment Guide

How to build, configure, migrate and run PAL. This guide covers what is common to every target; the per-target operator guides in [`../deployments/`](../deployments/) add the topology, identity and verification steps for each environment.

> **Scope.** Through Phase 2: PAL now needs a **writable blob volume** and a **ClamAV daemon** in addition to PostgreSQL. There is no converter or SMTP relay to deploy yet (*Planned — Phases 4–6*). When those land, this guide and the per-target guides must be updated in the same change.

Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)

---

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Build the image](#3-build-the-image)
4. [Configuration and secrets](#4-configuration-and-secrets)
5. [Database roles and migrations](#5-database-roles-and-migrations)
6. [The worker / background process](#6-the-worker--background-process)
7. [First sign-in and role mapping](#7-first-sign-in-and-role-mapping)
8. [Verification](#8-verification)
9. [Ollama configuration](#9-ollama-configuration)
10. [GPU acceleration](#10-gpu-acceleration)
11. [Production checklist](#11-production-checklist)
12. [Render (demo / evaluation)](#12-render-demo--evaluation)

---

## 1. Deployment models

| Model | Guide | Status |
|---|---|---|
| Local development (PHP + Node on the workstation, PostgreSQL) | [LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) | Supported |
| **Single Linux server, Docker Compose** (`deploy/compose.yaml`) | [SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) | **Selected production target (decision D2)** |
| Kubernetes | [KUBERNETES.md](../deployments/KUBERNETES.md) | Reference only. ADR-0001 does not recommend Kubernetes at PAL's scale. |
| Microsoft Azure (Commercial / Government) | [AZURE.md](../deployments/AZURE.md) | Reference only. Not selected (D0/D2). |
| AWS (Commercial / GovCloud) | [AWS.md](../deployments/AWS.md) | Reference only. Not selected. |
| Air-gapped | [AIRGAPPED.md](../deployments/AIRGAPPED.md) | Reference. Entra sign-in and group sync require outbound access to Entra ID and Microsoft Graph; local accounts work without it. ClamAV signatures need an offline mirror. |
| Render (`pal/render.yaml`) | This guide, [§12](#12-render-demo--evaluation) | Demo/evaluation only, not yet deployed. Not referenced by the repository's root `render.yaml`; deploy deliberately via New → Blueprint with path `pal/render.yaml`. Do not load real documents. |

All models run the **same image** with different environment variables. The image has four roles: `web`, `worker`, `migrate`, `console` (see [ARCHITECTURE.md §9](ARCHITECTURE.md#9-deployment-topology)).

## 2. Prerequisites

| Requirement | Detail |
|---|---|
| Container runtime | Docker Engine with Compose v2 (for `deploy/compose.yaml`), or any OCI runtime/orchestrator |
| PostgreSQL | 17 for production (16+ works with `database/schema.sql`). A superuser/DBA account to create roles once. Extensions `btree_gist` and `pg_trgm` must be available; migration 2 creates them as the owner role (trusted extensions). Managed services may need them allow-listed. |
| Blob storage | A volume or NAS share mounted at `/srv/pal/data` on **web**, writable by uid 10001. Shared by all web instances. Sized for your documents; files are never deleted by the app. |
| ClamAV | A `clamd` daemon reachable from web on TCP 3310 (`clamav/clamav:1.5.4` in Compose and Render). About 1.5 GB of RAM for signatures, more while reloading (not measured for PAL; see OPEN_ITEMS.md). The first start downloads signatures (several minutes). Uploads are refused without it. |
| Reverse proxy | TLS termination in front of the web container (nginx, HAProxy, a load balancer). It must send `X-Forwarded-Proto` / `X-Forwarded-For` and be inside `TRUSTED_PROXIES`, and allow request bodies up to 52 MB and at least 120 s on `/api/uploads`. |
| DNS + certificate | A hostname for PAL; its exact origin goes into `PAL_BASE_URL` and the Entra redirect URI. |
| Entra ID app registration (only for SSO) | Redirect URI `<PAL_BASE_URL>/auth/callback`; front-channel logout / post-logout redirect `<PAL_BASE_URL>/`; delegated scopes `openid profile email`; a client **certificate** (production) or secret (development). For group sync: Microsoft Graph **application** permissions `GroupMember.Read.All` and `User.Read.All` with admin consent (decision D16, still open). **Mandatory:** on the Enterprise application, set **Assignment required = Yes** and assign only the intended users/groups. PAL makes every account that can sign in a Reader, so without this every account in the tenant, **guests included**, gets Reader access. |
| Outbound HTTPS (only for SSO and, unless mirrored, ClamAV signatures) | From web and worker to `login.microsoftonline.com` (or your `OIDC_AUTHORITY` host) and `graph.microsoft.com` (or your `GRAPH_BASE_URL` host). |
| Log collector | The host's collector forwarding container stdout/stderr to Microsoft Sentinel ([ADR-0011](adr/ADR-0011-logging-to-microsoft-sentinel.md); path undecided, D17). |
| Build tooling (only if building from source) | Docker BuildKit. Local development: PHP 8.4 with `pdo_pgsql`, `intl`, `openssl`; Composer 2; Node.js ≥ 22 (CI uses 24). |

## 3. Build the image

The build context is `pal/`:

```bash
docker build -t pal:latest pal/
```

or, with Compose (context `..` relative to `deploy/`):

```bash
docker compose -f pal/deploy/compose.yaml build
```

The multi-stage build compiles the SPA (`node:24-alpine`, `npm ci && npm run build`), installs production Composer dependencies with an authoritative class map, warms the Symfony prod cache, and produces a runtime on `php:8.4-fpm-alpine` with `pdo_pgsql`, `intl` and `opcache`. The final image runs as UID/GID 10001 (`pal`), application code is owned by root (read-only to the app user), and only `var/cache`, `var/log` and `/srv/pal/data` (created for the blob store, owned by uid 10001) are writable. PHP allows `upload_max_filesize` 50M and `post_max_size` 52M; nginx allows 52 MB bodies on `/api/uploads` only and 2 MB elsewhere. Base images are pinned by tag; pin them by digest in your registry mirror.

CI (`.github/workflows/pal-ci.yml`) builds the same image, smoke-tests it (refuses to start without config, `/health/live` answers, runs as UID 10001) and scans it with Trivy (HIGH/CRITICAL, fixed-only, fails the build).

## 4. Configuration and secrets

Templates: `pal/deploy/pal.env.example` (Compose) and `pal/backend/.env.example` (development). Never commit a filled-in copy; `deploy/secrets/` and `.env*` (except `.env.example`) are git-ignored and excluded from the image build.

Every variable below was verified against `%env(...)%` references in `config/`, `#[Autowire(env: ...)]` in `src/`, `deploy/docker-entrypoint.sh`, the `Dockerfile` and `compose.yaml`.

### 4.1 Required

| Variable | Example | Purpose |
|---|---|---|
| `APP_SECRET` | output of `openssl rand -hex 32` | Symfony kernel secret; signs CSRF tokens and other framework HMACs. **Secret.** Same value on web and worker. |
| `DATABASE_URL` | `postgresql://pal_app:CHANGE_ME@db:5432/pal?serverVersion=17&charset=utf8` | Runtime DB connection (least-privilege app role). Also used by the PDO session handler. **Secret** (contains password). |
| `PAL_BASE_URL` | `https://pal.example.internal` | Public origin; builds the OIDC redirect URI (`/auth/callback`) and post-logout URI. Must match the Entra registration exactly. Required by the `web` role. |
| `TRUSTED_HOSTS` | `^pal\.example\.internal$` | Regex allowlist for the `Host` header (Host-header injection protection). Required by the `web` role. |
| `PAL_CLAMD_ADDRESS` | `clamd:3310` or `tcp://clamd:3310` | Required for uploads, not for start-up. ClamAV daemon address. Empty or unreachable means every upload is refused (503 `scanner_unavailable`). |

For Entra sign-in and directory sync (optional; without them the sign-in page shows Entra as "Not connected" and people use local accounts):

| Variable | Example | Purpose |
|---|---|---|
| `OIDC_TENANT_ID` | `00000000-0000-0000-0000-000000000000` (placeholder) | Entra tenant GUID. The all-zero GUID is rejected as "not configured". |
| `OIDC_CLIENT_ID` | `00000000-0000-0000-0000-000000000000` (placeholder) | Application (client) GUID. |
| `OIDC_CLIENT_SECRET` **or** `OIDC_CLIENT_CERT_PATH` + `OIDC_CLIENT_KEY_PATH` | `/run/secrets/pal_oidc_cert`, `/run/secrets/pal_oidc_key` | Exactly one credential must be set. Certificate (private_key_jwt, RFC 7523) is recommended for production; the secret is for development. **Secret.** |
| `OIDC_CLIENT_KEY_PASSPHRASE` | _(empty)_ | Optional passphrase when `OIDC_CLIENT_KEY_PATH` is an **encrypted** private key; empty for an unencrypted key. **Secret** — prefer `OIDC_CLIENT_KEY_PASSPHRASE_FILE`. |

Until the OIDC variables are valid, `/auth/login` redirects with `auth_error=sign_in_unavailable` and the role-mapping API returns 503. Health checks and local accounts work without them.

### 4.2 Optional / defaults

| Variable | Example / default | Purpose |
|---|---|---|
| `APP_ENV` | `prod` (image) / `dev` (`.env.example`) | Symfony environment. |
| `APP_DEBUG` | `0` (image) | Never enable in production. |
| `DATABASE_URL_MIGRATIONS` | `postgresql://pal_owner:CHANGE_ME@db:5432/pal?serverVersion=17&charset=utf8` | Used by the `migrate` role instead of `DATABASE_URL` (schema-owner role). Keep it out of web/worker environments in production. **Secret.** |
| `PAL_APP_DB_ROLE` | `pal_app` | When set, `migrate` re-applies `REVOKE UPDATE, DELETE, TRUNCATE ON audit_event` to this role after migrating. Must be a lowercase PostgreSQL identifier. |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Durable queue in PostgreSQL. Tables come from migrations (`auto_setup=0`). |
| `TRUSTED_PROXIES` | `private_ranges` (image) / `127.0.0.1` (`.env.example`) | IPs/CIDRs allowed to set `X-Forwarded-For/Proto/Port`. In production set the **exact** reverse-proxy IP(s), and configure the proxy to **overwrite** (not append to) `X-Forwarded-For`. The `private_ranges` default lets any internal host that reaches the container spoof its client IP (rate-limit keys, audited IPs). See §11.2. |
| `PAL_SESSION_COOKIE_NAME` | `__Host-pal_session` (image) / `pal_session` (dev) | Session cookie name. The `__Host-` prefix requires `Secure`. |
| `PAL_SESSION_COOKIE_SECURE` | `1` (image) / `0` (dev) | `Secure` cookie flag. Must be `1` behind TLS. |
| `PAL_SESSION_IDLE_SECONDS` | `3600` | Idle timeout (session `gc_maxlifetime`). |
| `PAL_SESSION_ABSOLUTE_SECONDS` | `43200` | Absolute session lifetime, regardless of activity. |
| `PAL_DEFAULT_TIMEZONE` | `America/Denver` | **Fallback only**: used when the `org_setting` row `default_timezone` is absent. The migration seeds that row with `America/Denver`, so changing this variable after migration has no effect, and there is no API to change the row yet (see §11.3). |
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | Entra authority. Must be `https`. All discovered endpoints must be on this host. |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | Microsoft Graph base URL. Pagination links outside this origin are refused. |
| `PAL_BOOTSTRAP_ADMIN_OIDS` | comma-separated Entra object IDs | Grants System Administrator before any group mapping exists. **Remove after mappings are configured.** |
| `PAL_BLOB_DIR` | `/srv/pal/data/blobs` (image) / `var/blobs` (`.env.example`) | Root of the content-addressed blob store. Created if possible; `/health/ready` reports `"storage":"fail"` when missing or not writable. Needed on **web**. |
| `PAL_MAX_UPLOAD_BYTES` | `52428800` (app default, `backend/.env.example`) / `26214400` (`deploy/pal.env.example`, `render.yaml`) | Largest accepted file. Keep at or below clamd's `StreamMaxLength` (ClamAV default 25 MiB, hence the 26214400 override) or those scans fail closed, and at or below PHP's `upload_max_filesize` (50M). Exposed to the UI as `maxUploadBytes` by `GET /api/reference`. |
| `PAL_UPLOAD_DAILY_BYTES` | `524288000` (default) / `209715200` (`render.yaml`) | Per-user limit on clean uploaded bytes in any 24 hours (429 `upload_quota_exceeded`). |
| `PAL_ALLOW_UNSCANNED_UPLOADS` | `0` | Explicit, off-by-default opt-out of malware scanning. `1` accepts uploads without scanning when `PAL_CLAMD_ADDRESS` is empty, **in any environment**. In production each such upload is logged as an error and recorded with scan engine `none (unscanned)`. Leave `0` and run ClamAV wherever real documents are stored; set `1` only for a scanner-less demo (see §12.3). |
| `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` | `admin` | Read by the `migrate` role. With the password below, creates the first local administrator if no local account exists. Remove after first sign-in. |
| `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` | 12–128 characters, one-off value | Temporary: must be changed at first sign-in. A value that fails the policy makes `migrate` fail. **Secret.** Remove after first sign-in. |
| `PAL_TEMP_PASSWORD_MAX_AGE_HOURS` | `0` | Hours a temporary (must-change) password stays valid after it is issued; after that, sign-in is refused with `403 password_expired` until an administrator resets it. `0` (default) disables expiry, so an upgrade never starts locking anyone out. Keep a recovery path for the sole/bootstrap admin: re-running `pal:users:bootstrap-admin` or another admin's reset issues a fresh temporary password and restarts the clock. |
| `PAL_AUDIT_ANCHOR_DIR` | `/var/lib/pal-anchors` | Directory where the audit chain head is appended (`audit-anchors.jsonl`). Should be write-once storage owned by another team. Empty = anchoring off. Requires a writable mount in the worker container (see §6). |
| `LOG_LEVEL` | `info` | Minimum Monolog level for the JSON stderr handler. |
| `DEFAULT_URI` | `http://localhost:5173` | Base URI for URLs generated outside HTTP requests (Symfony router). |
| `PAL_WORKER_TIME_LIMIT` | `3600` | Seconds before a worker exits for a clean restart. |
| `PAL_WORKER_MEMORY_LIMIT` | `256M` | Memory ceiling before a worker exits. |
| `PAL_IMAGE` | `pal:latest` | Compose only: image reference for web/worker/migrate. |
| `PAL_BACKEND_URL` | `http://127.0.0.1:8000` | Frontend dev server only (`vite.config.ts`): where Vite proxies `/api`, `/auth`, `/health`. |

### 4.3 Secrets handling

- Generate `APP_SECRET` with `openssl rand -hex 32`; use the same value on all web and worker instances.
- Prefer **files mounted as Docker secrets** for the OIDC certificate and key (`OIDC_CLIENT_CERT_PATH`, `OIDC_CLIENT_KEY_PATH` point to them). The bundled PostgreSQL reads its passwords from `deploy/secrets/db_*_password.txt` via Docker secrets.
- `deploy/compose.yaml` mounts `deploy/secrets/oidc_cert.pem` and `oidc_key.pem` as `/run/secrets/pal_oidc_cert` and `/run/secrets/pal_oidc_key`. Compose refuses to start if the files are missing, so keep empty placeholders when not using a certificate. See [SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md).
- `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` needs to reach only the `migrate` step, and should be deleted after first sign-in ([SECURITY.md §2.7](SECURITY.md#27-local-accounts-identitylocal-securitylocalpasswordauthenticator)).
- `DATABASE_URL` and `APP_SECRET` are read from environment variables; PAL has no built-in integration with Vault, Key Vault or Secrets Manager. Inject them from your secret store at container start, **or** mount them as files and point `<NAME>_FILE` at the path — the entrypoint supports `APP_SECRET_FILE`, `DATABASE_URL_FILE`, `DATABASE_URL_MIGRATIONS_FILE`, `OIDC_CLIENT_SECRET_FILE`, `OIDC_CLIENT_KEY_PASSPHRASE_FILE` and `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD_FILE` (reads the file, strips a trailing newline; setting both the variable and its `_FILE` is an error). This keeps secrets off the process environment with Docker/Kubernetes secrets and Vault/CSI mounts.
- Rotation procedures are in [SECURITY.md §9](SECURITY.md#9-secrets-rotation).

### 4.4 Database TLS

PAL does not enforce TLS to PostgreSQL; enable it whenever the database is on another host. Both database connections — Doctrine (application queries) **and** the PostgreSQL session handler — read the same `DATABASE_URL`, so configuring it once covers both; migrations use `DATABASE_URL_MIGRATIONS`, so set it there too. There is no separate in-app TLS switch: PAL relies on libpq, driven either by DSN query parameters or by `PGSSL*` environment variables.

Recommended (verifies the server certificate against a CA **and** checks the hostname):

```
# On the DSNs (URL-encode any special characters in the path):
DATABASE_URL="postgresql://pal_app:***@db.internal:5432/pal?serverVersion=17&charset=utf8&sslmode=verify-full&sslrootcert=/run/secrets/pg_ca.crt"
DATABASE_URL_MIGRATIONS="postgresql://pal_owner:***@db.internal:5432/pal?serverVersion=17&charset=utf8&sslmode=verify-full&sslrootcert=/run/secrets/pg_ca.crt"

# …or, equivalently, as environment variables that libpq reads for every connection:
PGSSLMODE=verify-full
PGSSLROOTCERT=/run/secrets/pg_ca.crt
```

For mutual TLS add `sslcert` / `sslkey` (or `PGSSLCERT` / `PGSSLKEY`). Mount the CA (and any client cert/key) read-only as secrets; the `serverVersion` and `charset` parameters must stay. `sslmode=require` encrypts but does **not** authenticate the server, so a MITM is still possible — prefer `verify-full`.

Verify end to end from a running container (both the app role and the migrate role):

```
docker run --rm --env-file prod.env pal:latest console dbal:run-sql \
  "SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()"
```

`ssl = t` confirms the application connection is encrypted. The session handler uses the same `DATABASE_URL`, so it inherits the setting; confirm sign-in still works after enabling TLS. This has not been exercised against a TLS-enabled server in this project — run the check above on staging before go-live.

## 5. Database roles and migrations

### 5.1 Roles (once, then after every migration)

PAL uses two PostgreSQL roles (`deploy/sql/roles.sql`):

- **owner role** (`pal_owner`): owns the schema; used **only** by the `migrate` job via `DATABASE_URL_MIGRATIONS`. It can alter tables, which means it can disable the audit triggers, so keep its credentials away from the running app.
- **app role** (`pal_app`): used by web and worker via `DATABASE_URL`. Data access only; no DDL; no `UPDATE`, `DELETE` or `TRUNCATE` on `audit_event`; no writes to `doctrine_migration_versions`.

Run as a DBA, before the first migration and again after every migration:

```bash
psql "$ADMIN_URL" -v ON_ERROR_STOP=1 \
     -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal \
     -f pal/deploy/sql/roles.sql
```

The script is idempotent and deliberately sets no passwords; set them with `\password pal_owner` / `\password pal_app` (or your platform's identity integration) so they never land in a file or shell history. With the bundled database (`--profile bundled-db`), `deploy/sql/init-roles.sh` runs this on first start and sets passwords from Docker secrets.

### 5.2 Apply migrations

Doctrine Migrations is the **authoritative installer**. `database/schema.sql` is a manual-setup reference, kept equal to the migrations by `tests/Integration/SchemaParityTest`.

Container (recommended):

```bash
docker run --rm --env-file pal/deploy/pal.env pal:latest migrate
```

With Compose, the `migrate` service runs automatically before `web` and `worker` on `up`; to run it on its own:

```bash
docker compose -f pal/deploy/compose.yaml run --rm migrate
```

From source (`pal/backend`):

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

The container role runs `doctrine:migrations:migrate --no-interaction --allow-no-migration` with `DATABASE_URL_MIGRATIONS` if set, then `pal:users:bootstrap-admin` (a no-op unless the bootstrap variables are set and no local account exists), then, if `PAL_APP_DB_ROLE` is set, revokes `UPDATE, DELETE, TRUNCATE` on `audit_event` from that role. **Still re-run `roles.sql` after each migration** so new tables are owned by the owner role and granted to the app role.

Check status:

```bash
docker run --rm --env-file pal/deploy/pal.env pal:latest console doctrine:migrations:status
```

`/health/ready` returns 503 with `"migrations":"fail"` while any migration is pending (the code is ahead of the DB). It also returns 503 with `"schema_version":"fail"` when the DB is ahead of the code — it has executed a migration this image does not carry — so an old replica lingering during a rolling deploy is taken out of rotation until it is replaced by the newer image. (Deleting an already-applied migration file from the codebase would also trip this; keep migration history immutable.) Current migrations: `Version20261026000001` (foundation), `…000002` (documents, revisions, uploads, workflow, publications; extensions `btree_gist`, `pg_trgm`), `…000003` (local accounts, explicit user roles).

### 5.3 Rollback

Do **not** run down-migrations against a database with real data. `Version20261026000001::down()` drops every table, **including `audit_event`**. Roll back by restoring a backup taken before the migration ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)) and redeploying the previous image tag. Take a backup before every migration.

### 5.4 Render

See also [§12](#12-render-demo--evaluation). `render.yaml` runs `/usr/local/bin/pal-entrypoint migrate` as `preDeployCommand` with the single Render-managed database user. There is no owner/app split and `PAL_APP_DB_ROLE` is not set, so the app role is the table owner on Render; only the audit triggers protect `audit_event` there. The audit trail there is **tamper-evident** (hash chain, verification) but **not tamper-resistant**: the connecting role owns the table and can disable the triggers. The same applies to any deployment that uses a single database role. Acceptable for a demo, not for production.

## 6. The worker / background process

```bash
docker run --env-file pal/deploy/pal.env pal:latest worker
```

The worker runs `php bin/console messenger:consume async scheduler_default --time-limit=$PAL_WORKER_TIME_LIMIT --memory-limit=$PAL_WORKER_MEMORY_LIMIT -vv` and exits on either limit; the orchestrator (`restart: unless-stopped` in Compose) restarts it.

| Job | Schedule | Effect |
|---|---|---|
| `SyncDirectory` | every 15 minutes | Syncs membership of all tracked directory groups from Graph; closes (never deletes) ended memberships; deactivates users disabled in the directory; audited. Skipped if Graph is not configured. |
| `VerifyAuditChain(anchor: true)` | daily, 02:15 UTC | Recomputes the whole audit hash chain. If valid: appends the head to `PAL_AUDIT_ANCHOR_DIR` (when configured) and records `AUDIT_CHAIN_VERIFIED`. If broken: logs `critical` and writes nothing to the audit table. |

Operational notes:

- Run **at least one** worker. Running more is safe: the schedule takes the PostgreSQL advisory lock `pal-schedule-default`, so jobs do not double-fire.
- The `async` queue is configured (retries: 5, exponential backoff from 2 s, capped at 10 min; failures go to the `failed` queue). One message is routed to it: `SyncDirectoryGroup`, dispatched when a non-document-authority role mapping is added so a large group's initial membership pull runs off-request (I8); it is consumed by the same `messenger:consume async` worker. The worker needs neither the blob volume nor ClamAV. Inspect failures with `docker run --rm --env-file … pal:latest console messenger:failed:show`.
- The scheduler's "catch up missed runs" state uses the Symfony app cache, which in the container is the tmpfs `var/cache`. It is lost on every worker restart (hourly by default), so a run missed while the worker was down may not be replayed. Low impact today (the next run happens on schedule); revisit before Phase 5 publication sweeps.
- **Anchor mount:** containers run with a read-only root filesystem. To use `PAL_AUDIT_ANCHOR_DIR`, mount a writable directory (ideally on write-once storage) into the **worker** container at that path, owned by or writable for UID 10001. `compose.yaml` does not do this by default.
- Manual runs: `docker run --rm --env-file … pal:latest console pal:directory:sync` and `… console pal:audit:verify [--anchor]`.
- **Revocation latency:** removing a user from a mapped Entra group, or disabling the account, takes effect in PAL after the next directory sync (up to 15 minutes) or, for users outside tracked groups, when their session reaches the idle/absolute limit. Run `pal:directory:sync` manually for urgent revocations, or `TRUNCATE sessions` to sign everyone out ([SECURITY.md §9](SECURITY.md#9-secrets-rotation)).

## 7. First sign-in and role mapping

**With a local administrator** (no Entra needed):

1. Set `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` and `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` for the `migrate` step and run it. Its log says `Created local administrator "<name>"`.
2. Sign in on the sign-in page. PAL forces a new password (`/change-password`); every other API call answers 403 `password_change_required` until then.
3. Delete both variables. The account holds `SYSTEM_ADMINISTRATOR` and `PAL_ADMINISTRATOR`, so one person can confer document authority until D20 is decided: create separate accounts under **Administration › Users** and split these roles after bootstrap.
4. If locked out (5 failures, 15 minutes): `docker run --rm --env-file pal/deploy/pal.env pal:latest console pal:users:unlock <username>`.

Conferring `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR`, or resetting the password of an account holding one, requires the caller to hold `PAL_ADMINISTRATOR` (403 `role_escalation_forbidden`). The last active System Administrator cannot be removed (409 `last_administrator`).

**With Entra ID:**

1. Set `PAL_BOOTSTRAP_ADMIN_OIDS` to your Entra object ID and restart web.
2. Sign in. You hold System Administrator (plus the implicit Reader).
3. Map groups to roles in **Admin → Role mappings** (`/admin/roles`), or from the CLI:
   ```bash
   docker run --rm --env-file pal/deploy/pal.env pal:latest console pal:roles:map SYSTEM_ADMINISTRATOR <group-object-id>
   ```
   Mappable roles: `SYSTEM_ADMINISTRATOR`, `PAL_ADMINISTRATOR`, `PUBLISHER`, `AUTHOR` (Reader is implicit). Add `--remove` to unmap.
   Through the API/UI, you cannot map a group you are a member of to `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR` (403 `self_grant_forbidden`); another administrator who is not in that group must do it. Adding a mapping for a very large group syncs its members synchronously and may time out; the mapping is still saved and the next 15-minute sync completes the membership.
4. Remove `PAL_BOOTSTRAP_ADMIN_OIDS` and restart.

## 8. Verification

| Check | Command / expectation |
|---|---|
| Liveness | `curl -fsS https://<host>/health/live` → `{"status":"ok"}` |
| Readiness | `curl -fsS https://<host>/health/ready` → `{"status":"ready","checks":{"database":"ok","migrations":"ok","schema_version":"ok","storage":"ok"}}` (503 otherwise) |
| Host allowlist | A request with a non-matching `Host` header is rejected by Symfony |
| Unauthenticated API | `curl -s -o /dev/null -w '%{http_code}' https://<host>/api/me` → `401` |
| Security headers | SPA response carries `Content-Security-Policy` with `script-src 'self'`; `/api` responses carry `default-src 'none'`, `Cache-Control: no-store, private`, `X-Request-Id`; `Strict-Transport-Security` present over HTTPS |
| Local admin + forced change | Bootstrap admin signs in, is sent to `/change-password`, and reaches the dashboard after changing it; audit has `USER_SIGNED_IN` (`method: password`) and `USER_PASSWORD_CHANGED` |
| Sign-in (Entra) | Browser sign-in completes and lands on the dashboard; `GET /api/me` returns roles and `csrfToken`; audit has `USER_SIGNED_IN`. A failed sign-in lands on `/sign-in?auth_error=<code>` |
| Assignment required | An account not assigned to the Entra enterprise application is refused by Entra before reaching PAL |
| Directory sync | `console pal:directory:sync` succeeds; mapped group shows a member count and `syncedAt` |
| Audit chain | `console pal:audit:verify` → `Audit chain valid: N events, head <hash>` |
| Logs | JSON lines on stderr with `correlation_id`; `pal_audit` channel records present at the collector |

| Upload accepted + scanned | Upload a small PDF on a draft. `SELECT status, reason FROM upload ORDER BY created_at DESC LIMIT 1` → `CLEAN`; `SELECT scan_engine FROM stored_file ORDER BY created_at DESC LIMIT 1` → `clamd` |
| Object written | The file exists at `$PAL_BLOB_DIR/sha256/ab/cd/<hash>` with mode `0440` |
| Malware refused | Upload the standard EICAR test file as `eicar.txt` → 422 `malware_detected`; `upload.status = INFECTED`; audit `UPLOAD_MALWARE_DETECTED`; no `stored_file` row |
| Scanner failure is closed | With clamd stopped, an upload → 503 `scanner_unavailable`; `upload.status = ERROR` |
| Indexed | Publish the document; searching its title in the library finds it |

## 9. Ollama configuration

**Not applicable.** PAL makes no LLM or AI-inference calls and has no Ollama or other model-serving configuration. Nothing needs to be deployed for this.

## 10. GPU acceleration

**Not applicable.** PAL does not use a GPU. No GPU drivers, device mounts or runtime flags are needed.

## 11. Production checklist

### 11.1 Secrets & identity
- [ ] `APP_SECRET` generated with `openssl rand -hex 32`, stored in the secret store, identical on web and worker.
- [ ] Secrets delivered as files where supported: `APP_SECRET_FILE`, `DATABASE_URL_FILE`, `DATABASE_URL_MIGRATIONS_FILE`, `OIDC_CLIENT_SECRET_FILE`, `OIDC_CLIENT_KEY_PASSPHRASE_FILE`, `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD_FILE` keep the value off the process environment (Docker/K8s secrets, Vault/CSI). Do not set both a variable and its `_FILE` (the entrypoint errors).
- [ ] Entra uses a **certificate** credential (`OIDC_CLIENT_CERT_PATH` + `OIDC_CLIENT_KEY_PATH`), not a client secret; files mounted read-only as secrets.
- [ ] Graph permissions limited to read-only `GroupMember.Read.All` + `User.Read.All` (application), admin-consented; nothing else granted (no `Mail.Send`, no write scopes).
- [ ] Separate DB roles: web/worker use the app role; only `migrate` has `DATABASE_URL_MIGRATIONS`; `PAL_APP_DB_ROLE` set; `roles.sql` re-run after every migration.
- [ ] `PAL_BOOTSTRAP_ADMIN_OIDS` emptied once group mappings exist.
- [ ] `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` / `_PASSWORD` deleted after first sign-in; temporary password changed.
- [ ] Decide `PAL_TEMP_PASSWORD_MAX_AGE_HOURS` (temporary-password expiry; `0` = off). If enabled, confirm the sole/bootstrap-admin recovery path (`pal:users:bootstrap-admin` re-run, or another admin's reset) before relying on it.
- [ ] Decision taken on local accounts in production (ADR-0014): keep to break-glass where Entra exists; they have no MFA. `SYSTEM_ADMINISTRATOR` and `PAL_ADMINISTRATOR` held by different people.
- [ ] `PAL_ALLOW_UNSCANNED_UPLOADS=0` and `PAL_CLAMD_ADDRESS` set.
- [ ] Entra enterprise application has **Assignment required = Yes** with only intended users/groups assigned (otherwise every tenant account, guests included, becomes a Reader).
- [ ] Review who is mapped to `SYSTEM_ADMINISTRATOR`: that role can map groups it is **not** a member of to any role, including document roles; self-grants are blocked but collusion between administrators is only detected through audit (see [SECURITY.md §4.3](SECURITY.md#43-separation-of-duties)).
- [ ] No filled-in `pal.env`, `.env.local` or secret file committed (CI gitleaks job green).

### 11.2 Transport & exposure
- [ ] TLS terminates at the reverse proxy; the proxy sends `X-Forwarded-Proto: https` so nginx emits HSTS.
- [ ] Web container reachable only from the proxy (Compose binds `127.0.0.1:8080`).
- [ ] `TRUSTED_PROXIES` set to the exact reverse-proxy IP(s), not `private_ranges`, and the proxy **overwrites** `X-Forwarded-For` with the real client address. With `private_ranges`, any host on a private network that can reach port 8080 can spoof its client IP (affects rate-limit keys and client IPs recorded in logs and audit events).
- [ ] Upstream proxy strips or overwrites client-supplied `X-Request-Id` if correlation IDs must be trustworthy (PAL accepts a well-formed client value as the correlation ID).
- [ ] `TRUSTED_HOSTS` matches only the production hostname.
- [ ] Proxy allows 52 MB bodies and a 120 s timeout on `/api/uploads`.
- [ ] clamd's port 3310 reachable from web only (it has no authentication); never published.
- [ ] `PAL_SESSION_COOKIE_SECURE=1` and `PAL_SESSION_COOKIE_NAME=__Host-pal_session` (image defaults; do not override).
- [ ] Egress limited to the Entra authority, Graph and the log collector.

### 11.3 Hardening
- [ ] Containers run read-only, `cap_drop: [ALL]`, `no-new-privileges` (as in `compose.yaml`).
- [ ] Base images pinned by digest in your registry; Trivy scan clean for HIGH/CRITICAL.
- [ ] `APP_ENV=prod`, `APP_DEBUG=0`.
- [ ] Blob storage: writable by uid 10001 only, encrypted, deletion denied to the app user where the storage supports it.
- [ ] `PAL_MAX_UPLOAD_BYTES` ≤ clamd `StreamMaxLength`; `PAL_UPLOAD_DAILY_BYTES` sized against the volume.
- [ ] PostgreSQL not exposed beyond the app host(s); TLS to the database if it is on another host — `sslmode=verify-full` + `sslrootcert`, verified with the `pg_stat_ssl` query in [§4.4](#44-database-tls) (not configured by default).
- [ ] `PAL_DEFAULT_TIMEZONE` understood as fallback only; the effective default is the `org_setting.default_timezone` row (`America/Denver`).
- [ ] OS, Docker and PostgreSQL patching owned by a named team.

### 11.4 Resilience & operations
- [ ] Backups with WAL archiving configured and a restore drill completed ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)); provisional RPO 15 min / RTO 4 h confirmed by IT (D10).
- [ ] **Blob store backed up together with the database** (database first, blobs after; blob snapshot interval ≤ RPO), and the consistency check rehearsed ([DISASTER_RECOVERY.md §4](DISASTER_RECOVERY.md#4-restore-runbook) step 8a).
- [ ] ClamAV signatures updating (or offline mirror refreshed); RAM sized; alert on `ClamAV is unreachable`, `UPLOAD_MALWARE_DETECTED` and repeated 503 `scanner_unavailable`.
- [ ] Disk-space alert on the blob volume (files are never reclaimed).
- [ ] Backup taken before every migration; previous image tag retained for rollback.
- [ ] `PAL_AUDIT_ANCHOR_DIR` mounted into the worker on write-once storage owned by another team; anchors copied off-host.
- [ ] Log forwarding to Sentinel live; alert on `Audit chain verification FAILED` (critical) and repeated `USER_SIGN_IN_FAILED`.
- [ ] Uptime monitor polling `/health/ready` (target still undecided, D17).
- [ ] At least one worker running; monitor that `AUDIT_CHAIN_VERIFIED` appears daily and directory sync shows recent `syncedAt`.

## 12. Render (demo / evaluation)

`pal/render.yaml` deploys the full Phase 2 stack for demos. PAL's production target remains on-premises Linux (D2; [decision log #22](DECISION_LOG.md)). **This Blueprint has not been deployed yet** ([OPEN_ITEMS.md](../OPEN_ITEMS.md)). Never load real organisational documents into it.

| Resource | Type / plan | Notes |
|---|---|---|
| `pal-web` | Docker web service, `0.5c-512mb` | `dockerCommand: /usr/local/bin/pal-entrypoint web`; health check `/health/ready`; `preDeployCommand: /usr/local/bin/pal-entrypoint migrate`; disk `pal-blobs`, 1 GB, at `/srv/pal/data` |
| `pal-worker` | Docker background worker, `0.5c-512mb` | `dockerCommand: /usr/local/bin/pal-entrypoint worker` |
| `pal-clamav` | Private service, image `docker.io/clamav/clamav:1.5.4`, `2c-4g` | Reachable only from inside the Render account; `PAL_CLAMD_ADDRESS` comes from its `hostport` |
| `pal-db` | PostgreSQL 17, `0.1c-256mb` | Single database role |

Every service is on a paid plan. Check [current prices](https://render.com/pricing) first. The build filter is `pal/**`, and `dockerfilePath` / `dockerContext` are relative to the repository root.

### 12.1 Steps

1. Render dashboard: **New > Blueprint**, connect this repository.
2. **Blueprint Path:** `pal/render.yaml`.
3. Fill in the `sync: false` variables:

   | Variable | Service | Example | Purpose |
   |---|---|---|---|
   | `PAL_BASE_URL` | `pal-web` | `https://<your-service>.onrender.com` | Public origin, no trailing slash |
   | `DEFAULT_URI` | `pal-web`, `pal-worker` | same value | URLs generated outside requests |
   | `TRUSTED_HOSTS` | `pal-web` | `^<your-service>\.onrender\.com$` | Host allowlist |
   | `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` | `pal-web` | a name that is hard to guess | First local administrator. Anyone who knows a username can trigger its lockout |
   | `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` | `pal-web` | 12+ characters, one-off | Temporary; forced change at first sign-in |
   | `OIDC_TENANT_ID`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `PAL_BOOTSTRAP_ADMIN_OIDS` | `pal-web` (`OIDC_*` also `pal-worker`) | leave empty | Only to connect Entra ID. Otherwise it shows as "Not connected" |

4. Apply. The pre-deploy step migrates the database and creates the administrator. If the password fails the policy, the deploy fails with `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD rejected`.
5. Check `https://<your-service>.onrender.com/health/ready` → `{"status":"ready","checks":{"database":"ok","migrations":"ok","schema_version":"ok","storage":"ok"}}`.
6. **First sign-in:** username and password on the sign-in page, then choose a new password when prompted.
7. **Delete** `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` and `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` from `pal-web`.
8. Test an upload. ClamAV's first start downloads signatures for several minutes; until it is up, uploads answer 503 `scanner_unavailable`.

### 12.2 Limitations of this topology

| Limitation | Effect |
|---|---|
| **One database role** | The runtime role owns the tables and `PAL_APP_DB_ROLE` cannot be used. The audit trail is tamper-evident (hash chain, `pal:audit:verify`), **not** tamper-resistant. See §5.4 |
| **Disk ownership unverified** | If the mount is not writable by uid 10001, `/health/ready` reports `"storage":"fail"` and the deploy does not go live |
| **Single web instance** | A service with a persistent disk runs one instance and loses zero-downtime deploys (per `render.yaml`) |
| **25 MiB upload cap** | `PAL_MAX_UPLOAD_BYTES=26214400`, ClamAV's default `StreamMaxLength` |
| **1 GB disk, 200 MiB per user per day** | `PAL_UPLOAD_DAILY_BYTES=209715200`. Files are never reclaimed |
| **ClamAV memory** | About 1.5 GB of signatures, more while reloading, hence `2c-4g` (per `render.yaml`; not measured) |
| **Forwarded headers** | `TRUSTED_PROXIES=private_ranges`, since Render's proxy addresses are not fixed; client IPs are less trustworthy than with an exact proxy address |
| **Backups** | Not configured by the Blueprint. Check Render's database and disk backup features before relying on the demo's data |

### 12.3 Single Web Service on a shared database (free plan)

The topology chosen for the portfolio demo. Created by hand (**New > Web Service**), without the Blueprint: one free PAL Web Service, one paid private ClamAV service, and an existing shared PostgreSQL database with one schema per app. All three must be in the **same region and workspace**: free web services can send private network traffic to data stores and paid services in the same region, but cannot receive it.

**Malware scanning — pick one:**

- **With ClamAV (recommended):** **New > Private Service > Existing Image**, image `docker.io/clamav/clamav:1.5.4`, name `pal-clamav`, a plan with **about 4 GB of memory** (512 MB will OOM on first start while it loads ~1.5 GB of signatures). Its internal address appears as `pal-clamav:<port>` only once it is healthy (first start downloads signatures, several minutes); copy that for `PAL_CLAMD_ADDRESS`, and set `PAL_ALLOW_UNSCANNED_UPLOADS=0`.
- **Without ClamAV (scanner-less demo — cheapest):** skip the ClamAV service entirely. Leave `PAL_CLAMD_ADDRESS` **unset** and set `PAL_ALLOW_UNSCANNED_UPLOADS=1`. Uploads are then accepted **without malware scanning** (logged as an error, recorded as `none (unscanned)`). The web service boots and runs on just a Web Service + a database. **Never load real documents in this mode.**

**PAL Web Service:**

| Setting | Value |
|---|---|
| Language / runtime | Docker |
| Branch | `main` |
| Dockerfile path | `pal/Dockerfile` |
| Docker build context | `pal` |
| Docker command | `/usr/local/bin/pal-entrypoint web` |
| Health check path | `/health/ready` |
| Build filter (included paths) | `pal/**` |
| Instance type | Free |

| Variable | Example | Purpose |
|---|---|---|
| `PORT` | `8080` | Port nginx listens on in the image |
| `APP_SECRET` | use Render's **Generate** | Symfony secret |
| `DATABASE_URL` | internal URL of the shared database | Enter as a secret; never commit it |
| `DB_SCHEMA` | `pal` | Keeps PAL's objects out of `public`; sets `search_path=pal,public` through `PGOPTIONS` |
| `PAL_MIGRATE_ON_START` | `1` | Runs migrations and the admin bootstrap at start, because this topology has no separate migrate step |
| `PAL_BASE_URL`, `DEFAULT_URI` | `https://<your-service>.onrender.com` | Public origin, no trailing slash |
| `TRUSTED_HOSTS` | `^<your-service>\.onrender\.com$` | Host allowlist |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Message transport |
| `PAL_CLAMD_ADDRESS` | `pal-clamav:3310` (ClamAV) / **unset** (scanner-less) | Malware scanner address. Omit it for the scanner-less variant |
| `PAL_ALLOW_UNSCANNED_UPLOADS` | `0` (ClamAV) / `1` (scanner-less) | `1` accepts uploads with no scanner; demo only |
| `PAL_MAX_UPLOAD_BYTES` | `26214400` | 25 MiB, ClamAV's default stream limit |
| `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME`, `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` | hard to guess; 12+ characters | First administrator. Delete both after first sign-in |

The database role needs `CREATE` on the shared database, for the schema and for the `btree_gist` and `pg_trgm` extensions.

**Limits of this topology:**
- **Uploaded files do not last.** Free web services have no persistent disk, and the filesystem is wiped on every spin-down (15 idle minutes), restart or redeploy. The database keeps the records, so PAL then shows "no longer in storage", downloads answer 410 `file_unavailable`, and release is refused (409 `approval_integrity_failed`). The approve-and-publish flow only works within one uninterrupted session.
- **No worker.** The scheduled audit-chain check and directory sync do not run.
- **No shell** on free instances, so `pal:users:unlock` is unavailable; a lockout clears after 15 minutes.
- **Shared role.** Every app using the same database role can read and change PAL's schema. The audit trail stays tamper-evident only. Never use this topology for real documents.
- A free Render Postgres database itself expires after 30 days. Check the shared database's plan.
