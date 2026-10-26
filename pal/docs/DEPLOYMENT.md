# PAL — Deployment Guide

How to build, configure, migrate and run PAL. This guide covers what is common to every target; the per-target operator guides in [`../deployments/`](../deployments/) add the topology, identity and verification steps for each environment.

> **Scope.** This describes the Phase 1 foundation as implemented. There is no document storage, malware scanner, converter or SMTP relay to deploy yet (*Planned — Phases 2–6*). When those land, this guide and the per-target guides must be updated in the same change.

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

---

## 1. Deployment models

| Model | Guide | Status |
|---|---|---|
| Local development (PHP + Node on the workstation, PostgreSQL) | [LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) | Supported |
| **Single Linux server, Docker Compose** (`deploy/compose.yaml`) | [SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) | **Selected production target (decision D2)** |
| Kubernetes | [KUBERNETES.md](../deployments/KUBERNETES.md) | Reference only. ADR-0001 does not recommend Kubernetes at PAL's scale. |
| Microsoft Azure (Commercial / Government) | [AZURE.md](../deployments/AZURE.md) | Reference only. Not selected (D0/D2). |
| AWS (Commercial / GovCloud) | [AWS.md](../deployments/AWS.md) | Reference only. Not selected. |
| Air-gapped | [AIRGAPPED.md](../deployments/AIRGAPPED.md) | Reference. Note that sign-in and group sync require outbound access to Entra ID and Microsoft Graph. |
| Render (`pal/render.yaml`) | This guide, §3 and §4 | Demo/evaluation only. Not referenced by the repository's root `render.yaml`; deploy deliberately via New → Blueprint with path `pal/render.yaml`. Do not load real documents. |

All models run the **same image** with different environment variables. The image has four roles: `web`, `worker`, `migrate`, `console` (see [ARCHITECTURE.md §9](ARCHITECTURE.md#9-deployment-topology)).

## 2. Prerequisites

| Requirement | Detail |
|---|---|
| Container runtime | Docker Engine with Compose v2 (for `deploy/compose.yaml`), or any OCI runtime/orchestrator |
| PostgreSQL | 17 for production (16+ works with `database/schema.sql`). A superuser/DBA account to create roles once. |
| Reverse proxy | TLS termination in front of the web container (nginx, HAProxy, a load balancer). It must send `X-Forwarded-Proto` / `X-Forwarded-For` and be inside `TRUSTED_PROXIES`. |
| DNS + certificate | A hostname for PAL; its exact origin goes into `PAL_BASE_URL` and the Entra redirect URI. |
| Entra ID app registration | Redirect URI `<PAL_BASE_URL>/auth/callback`; front-channel logout / post-logout redirect `<PAL_BASE_URL>/`; delegated scopes `openid profile email`; a client **certificate** (production) or secret (development). For group sync: Microsoft Graph **application** permissions `GroupMember.Read.All` and `User.Read.All` with admin consent (decision D16, still open). **Mandatory:** on the Enterprise application, set **Assignment required = Yes** and assign only the intended users/groups. PAL makes every account that can sign in a Reader, so without this every account in the tenant, **guests included**, gets Reader access. |
| Outbound HTTPS | From web and worker to `login.microsoftonline.com` (or your `OIDC_AUTHORITY` host) and `graph.microsoft.com` (or your `GRAPH_BASE_URL` host). |
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

The multi-stage build compiles the SPA (`node:24-alpine`, `npm ci && npm run build`), installs production Composer dependencies with an authoritative class map, warms the Symfony prod cache, and produces a runtime on `php:8.4-fpm-alpine` with `pdo_pgsql`, `intl` and `opcache`. The final image runs as UID/GID 10001 (`pal`), application code is owned by root (read-only to the app user), and only `var/cache` and `var/log` are writable. Base images are pinned by tag; pin them by digest in your registry mirror.

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
| `OIDC_TENANT_ID` | `00000000-0000-0000-0000-000000000000` (placeholder) | Entra tenant GUID. The all-zero GUID is rejected as "not configured". |
| `OIDC_CLIENT_ID` | `00000000-0000-0000-0000-000000000000` (placeholder) | Application (client) GUID. |
| `OIDC_CLIENT_SECRET` **or** `OIDC_CLIENT_CERT_PATH` + `OIDC_CLIENT_KEY_PATH` | `/run/secrets/pal_oidc_cert.pem`, `/run/secrets/pal_oidc_key.pem` | Exactly one credential must be set. Certificate (private_key_jwt, RFC 7523) is recommended for production; the secret is for development. **Secret.** |

Sign-in and directory sync stay disabled (sign-in redirects with `auth_error=sign_in_unavailable`; role-mapping API returns 503) until the OIDC variables are valid. Health checks work without them.

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
- **Gap:** `deploy/compose.yaml` does not declare or mount `pal_oidc_cert.pem` / `pal_oidc_key.pem` into the web and worker services, although `pal.env.example` points at `/run/secrets/...`. Add those secrets to the `x-pal` anchor (or use a client secret in a non-production install) before expecting sign-in to work. See [SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md).
- `DATABASE_URL` and `APP_SECRET` are read from environment variables; PAL has no built-in integration with Vault, Key Vault or Secrets Manager. Inject them from your secret store at container start.
- Rotation procedures are in [SECURITY.md §9](SECURITY.md#9-secrets-rotation).

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

The container role runs `doctrine:migrations:migrate --no-interaction --allow-no-migration` with `DATABASE_URL_MIGRATIONS` if set, then, if `PAL_APP_DB_ROLE` is set, revokes `UPDATE, DELETE, TRUNCATE` on `audit_event` from that role. **Still re-run `roles.sql` after each migration** so new tables are owned by the owner role and granted to the app role.

Check status:

```bash
docker run --rm --env-file pal/deploy/pal.env pal:latest console doctrine:migrations:status
```

`/health/ready` returns 503 with `"migrations":"fail"` while any migration is pending.

### 5.3 Rollback

Do **not** run down-migrations against a database with real data. `Version20261026000001::down()` drops every table, **including `audit_event`**. Roll back by restoring a backup taken before the migration ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)) and redeploying the previous image tag. Take a backup before every migration.

### 5.4 Render

`render.yaml` runs `/usr/local/bin/pal-entrypoint migrate` as `preDeployCommand` with the single Render-managed database user. There is no owner/app split and `PAL_APP_DB_ROLE` is not set, so the app role is the table owner on Render; only the audit triggers protect `audit_event` there. The audit trail there is **tamper-evident** (hash chain, verification) but **not tamper-resistant**: the connecting role owns the table and can disable the triggers. The same applies to any deployment that uses a single database role. Acceptable for a demo, not for production.

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
- The `async` queue is configured (retries: 5, exponential backoff from 2 s, capped at 10 min; failures go to the `failed` queue) but **no message is routed to it in Phase 1**. Inspect failures with `docker run --rm --env-file … pal:latest console messenger:failed:show`.
- The scheduler's "catch up missed runs" state uses the Symfony app cache, which in the container is the tmpfs `var/cache`. It is lost on every worker restart (hourly by default), so a run missed while the worker was down may not be replayed. Low impact for Phase 1 (the next run happens on schedule); revisit before Phase 5 publication sweeps.
- **Anchor mount:** containers run with a read-only root filesystem. To use `PAL_AUDIT_ANCHOR_DIR`, mount a writable directory (ideally on write-once storage) into the **worker** container at that path, owned by or writable for UID 10001. `compose.yaml` does not do this by default.
- Manual runs: `docker run --rm --env-file … pal:latest console pal:directory:sync` and `… console pal:audit:verify [--anchor]`.
- **Revocation latency:** removing a user from a mapped Entra group, or disabling the account, takes effect in PAL after the next directory sync (up to 15 minutes) or, for users outside tracked groups, when their session reaches the idle/absolute limit. Run `pal:directory:sync` manually for urgent revocations, or `TRUNCATE sessions` to sign everyone out ([SECURITY.md §9](SECURITY.md#9-secrets-rotation)).

## 7. First sign-in and role mapping

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
| Readiness | `curl -fsS https://<host>/health/ready` → `{"status":"ready","checks":{"database":"ok","migrations":"ok"}}` (503 otherwise) |
| Host allowlist | A request with a non-matching `Host` header is rejected by Symfony |
| Unauthenticated API | `curl -s -o /dev/null -w '%{http_code}' https://<host>/api/me` → `401` |
| Security headers | SPA response carries `Content-Security-Policy` with `script-src 'self'`; `/api` responses carry `default-src 'none'`, `Cache-Control: no-store, private`, `X-Request-Id`; `Strict-Transport-Security` present over HTTPS |
| Sign-in | Browser sign-in completes and lands on the dashboard; `GET /api/me` returns roles and `csrfToken`; audit has `USER_SIGNED_IN`. A failed sign-in lands on `/sign-in?auth_error=<code>` |
| Assignment required | An account not assigned to the Entra enterprise application is refused by Entra before reaching PAL |
| Directory sync | `console pal:directory:sync` succeeds; mapped group shows a member count and `syncedAt` |
| Audit chain | `console pal:audit:verify` → `Audit chain valid: N events, head <hash>` |
| Logs | JSON lines on stderr with `correlation_id`; `pal_audit` channel records present at the collector |

Document upload, indexing and malware-scan checks do not apply until Phase 2.

## 9. Ollama configuration

**Not applicable.** PAL makes no LLM or AI-inference calls and has no Ollama or other model-serving configuration. Nothing needs to be deployed for this.

## 10. GPU acceleration

**Not applicable.** PAL does not use a GPU. No GPU drivers, device mounts or runtime flags are needed.

## 11. Production checklist

### 11.1 Secrets & identity
- [ ] `APP_SECRET` generated with `openssl rand -hex 32`, stored in the secret store, identical on web and worker.
- [ ] Entra uses a **certificate** credential (`OIDC_CLIENT_CERT_PATH` + `OIDC_CLIENT_KEY_PATH`), not a client secret; files mounted read-only as secrets.
- [ ] Graph permissions limited to read-only `GroupMember.Read.All` + `User.Read.All` (application), admin-consented; nothing else granted (no `Mail.Send`, no write scopes).
- [ ] Separate DB roles: web/worker use the app role; only `migrate` has `DATABASE_URL_MIGRATIONS`; `PAL_APP_DB_ROLE` set; `roles.sql` re-run after every migration.
- [ ] `PAL_BOOTSTRAP_ADMIN_OIDS` emptied once group mappings exist.
- [ ] Entra enterprise application has **Assignment required = Yes** with only intended users/groups assigned (otherwise every tenant account, guests included, becomes a Reader).
- [ ] Review who is mapped to `SYSTEM_ADMINISTRATOR`: that role can map groups it is **not** a member of to any role, including document roles; self-grants are blocked but collusion between administrators is only detected through audit (see [SECURITY.md §4.3](SECURITY.md#43-separation-of-duties)).
- [ ] No filled-in `pal.env`, `.env.local` or secret file committed (CI gitleaks job green).

### 11.2 Transport & exposure
- [ ] TLS terminates at the reverse proxy; the proxy sends `X-Forwarded-Proto: https` so nginx emits HSTS.
- [ ] Web container reachable only from the proxy (Compose binds `127.0.0.1:8080`).
- [ ] `TRUSTED_PROXIES` set to the exact reverse-proxy IP(s), not `private_ranges`, and the proxy **overwrites** `X-Forwarded-For` with the real client address. With `private_ranges`, any host on a private network that can reach port 8080 can spoof its client IP (affects rate-limit keys and client IPs recorded in logs and audit events).
- [ ] Upstream proxy strips or overwrites client-supplied `X-Request-Id` if correlation IDs must be trustworthy (PAL accepts a well-formed client value as the correlation ID).
- [ ] `TRUSTED_HOSTS` matches only the production hostname.
- [ ] `PAL_SESSION_COOKIE_SECURE=1` and `PAL_SESSION_COOKIE_NAME=__Host-pal_session` (image defaults; do not override).
- [ ] Egress limited to the Entra authority, Graph and the log collector.

### 11.3 Hardening
- [ ] Containers run read-only, `cap_drop: [ALL]`, `no-new-privileges` (as in `compose.yaml`).
- [ ] Base images pinned by digest in your registry; Trivy scan clean for HIGH/CRITICAL.
- [ ] `APP_ENV=prod`, `APP_DEBUG=0`.
- [ ] PostgreSQL not exposed beyond the app host(s); TLS to the database if it is on another host (append `sslmode=require` or stricter to the DSN; not configured by default).
- [ ] `PAL_DEFAULT_TIMEZONE` understood as fallback only; the effective default is the `org_setting.default_timezone` row (`America/Denver`).
- [ ] OS, Docker and PostgreSQL patching owned by a named team.

### 11.4 Resilience & operations
- [ ] Backups with WAL archiving configured and a restore drill completed ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)); provisional RPO 15 min / RTO 4 h confirmed by IT (D10).
- [ ] Backup taken before every migration; previous image tag retained for rollback.
- [ ] `PAL_AUDIT_ANCHOR_DIR` mounted into the worker on write-once storage owned by another team; anchors copied off-host.
- [ ] Log forwarding to Sentinel live; alert on `Audit chain verification FAILED` (critical) and repeated `USER_SIGN_IN_FAILED`.
- [ ] Uptime monitor polling `/health/ready` (target still undecided, D17).
- [ ] At least one worker running; monitor that `AUDIT_CHAIN_VERIFIED` appears daily and directory sync shows recent `syncedAt`.
