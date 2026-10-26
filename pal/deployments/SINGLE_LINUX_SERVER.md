# PAL on a single Linux server (Docker Compose)

This is the **selected production target** for PAL (owner decision D2: on-premises Linux, Docker Compose). It is the most detailed guide in this folder; the other guides refer back to it for shared material (Entra app registration, database roles, environment variables).

Scope: PAL **Phase 1 foundation**. PAL currently stores everything in PostgreSQL. There is **no document storage, malware scanner or preview converter yet**; those arrive in Phase 2+. Steps that depend on them are marked *Not applicable until Phase 2*.

All hostnames, IDs and paths below are placeholders (`pal.example.internal`, `<tenant-guid>`). The repository is public: never put real values in files that are committed.

Files referenced (all under `pal/`):

| File | What it is |
|---|---|
| `Dockerfile` | One image, four roles (`web`, `worker`, `migrate`, `console`) |
| `deploy/docker-entrypoint.sh` | Validates required variables, then runs the role |
| `deploy/compose.yaml` | Compose project `pal`: `migrate`, `web`, `worker`, optional `db` (profile `bundled-db`) |
| `deploy/pal.env.example` | Runtime variable template; copy to `deploy/pal.env` |
| `deploy/sql/roles.sql` | Least-privilege database roles (owner + app) |
| `deploy/sql/init-roles.sh` | First-start hook for the bundled PostgreSQL container |
| `deploy/nginx/nginx.conf`, `deploy/php/*`, `deploy/supervisord.conf` | In-container web tier |

---

## 1. Deployment architecture

One Linux host runs Docker Engine with the Compose plugin. A reverse proxy on the host terminates TLS and forwards to the PAL `web` container, which is published on `127.0.0.1:8080` only.

| Component | Runs as | Purpose |
|---|---|---|
| Reverse proxy (host) | nginx / Apache / HAProxy (operator's choice) | TLS termination on 443, forwards to `127.0.0.1:8080`, sets `X-Forwarded-*` |
| `web` container | `pal` image, role `web`, uid 10001 | supervisord runs nginx (port 8080) + PHP-FPM (127.0.0.1:9000). Serves the SPA, `/api`, `/auth`, `/health` |
| `worker` container | `pal` image, role `worker`, uid 10001 | `messenger:consume async scheduler_default`. Runs the scheduler: directory sync every 15 minutes, audit-chain verification daily 02:15 UTC |
| `migrate` container | `pal` image, role `migrate`, one-shot | `doctrine:migrations:migrate`, then revokes `UPDATE, DELETE, TRUNCATE ON audit_event` from `PAL_APP_DB_ROLE` |
| `db` container (optional) | `postgres:17-alpine`, profile `bundled-db` | Bundled PostgreSQL. Use an external, DBA-managed PostgreSQL 16+ (17 recommended) where one exists |
| PostgreSQL | external or bundled | Holds **all** state: data, sessions, Messenger queue, audit trail |
| Microsoft Entra ID | external (internet) | OIDC sign-in and read-only Microsoft Graph group sync |

Container hardening already set in `deploy/compose.yaml` for `migrate`, `web` and `worker`: `read_only: true`, tmpfs on `/tmp`, `/srv/pal/backend/var/cache` and `/srv/pal/backend/var/log`, `cap_drop: [ALL]`, `no-new-privileges:true`, `restart: unless-stopped` (except `migrate`).

Start order is enforced by Compose: `migrate` waits for `db` to be healthy (when the bundled DB is used); `web` and `worker` start only after `migrate` exits successfully.

## 2. Topology

```
                        Users (browser)
                              |
                         HTTPS :443
                              |
+-----------------------------v-------------------------------------------+
| Linux host  pal.example.internal                                        |
|                                                                         |
|  Reverse proxy (TLS)  --- http ---> 127.0.0.1:8080                      |
|                                         |                               |
|  Docker network "pal_default"           |                               |
|  +--------------------------------------v--------+                      |
|  | web  (nginx :8080 -> PHP-FPM 127.0.0.1:9000)  |                      |
|  |   /           SPA (static)                    |                      |
|  |   /api /auth /health -> Symfony               |                      |
|  +-----------------------+-----------------------+                      |
|                          |                                              |
|  +-----------------------v--+     +--------------------------------+    |
|  | worker (Messenger +      |     | migrate (one-shot, before web/ |    |
|  | scheduler)               |     | worker start and on upgrade)   |    |
|  +-----------+--------------+     +---------------+----------------+    |
|              |   TCP 5432                         |                     |
|  +-----------v------------------------------------v-----------+         |
|  | PostgreSQL 17  (bundled "db" container, or external host)   |         |
|  | data, sessions, messenger_messages, audit_event             |         |
|  +-------------------------------------------------------------+         |
+--------------+----------------------------------------------------------+
               | HTTPS :443 egress (web + worker)
               v
   login.microsoftonline.com (OIDC)    graph.microsoft.com (Graph, read-only)
   [Azure Government: login.microsoftonline.us / graph.microsoft.us — verify for your cloud]

   Logs: container stdout/stderr (JSON) -> host log pipeline -> Microsoft Sentinel
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| OS | 64-bit Linux host supported by Docker Engine (x86_64 is what CI builds; arm64 is untested) |
| Docker | Docker Engine with the Compose v2 plugin. `deploy/compose.yaml` uses `depends_on.required` and `condition: service_completed_successfully`, which need a recent Compose v2 (verify with `docker compose version`) |
| CPU / RAM | Not yet measured. PHP-FPM is capped at 16 children × 256 MB `memory_limit` (`deploy/php/fpm-pool.conf`, `deploy/php/php.ini`); size RAM accordingly plus PostgreSQL |
| PostgreSQL | 16+ (17 recommended). External DBA-managed, or the bundled `db` service |
| DNS / TLS | A DNS name (e.g. `pal.example.internal`) and a certificate trusted by users' browsers |
| Egress | HTTPS 443 from the host (web + worker containers) to the Entra authority host and the Graph host. If egress goes through a proxy, see §6 |
| Time | NTP synchronised. ID tokens are validated with a 120-second leeway; clock skew breaks sign-in |
| Entra ID | Permission to create an app registration and to grant admin consent for Graph application permissions (decision D16) |
| Image | Either build on the host (`docker compose build`) or pull a CI-built image from an internal registry (`PAL_IMAGE`) |

## 4. Identity and credentials

There is no managed/workload identity on a standalone Linux host. Credentials are stored as **files readable only by root and uid 10001**, delivered through Compose `env_file` and Docker secrets.

### 4.1 What needs a credential

| Credential | Used by | Storage on this host |
|---|---|---|
| `APP_SECRET` | web, worker, migrate | `deploy/pal.env` (mode 0600) |
| Runtime DB password (`pal_app`) | web, worker (`DATABASE_URL`) | inside `DATABASE_URL` in `deploy/pal.env`; for the bundled DB also `deploy/secrets/db_app_password.txt` |
| Migration DB password (`pal_owner`) | migrate (`DATABASE_URL_MIGRATIONS`) | inside `DATABASE_URL_MIGRATIONS` in `deploy/pal.env`; for the bundled DB also `deploy/secrets/db_owner_password.txt` |
| DB admin password (`pal_admin`, bundled DB only) | administration only | `deploy/secrets/db_admin_password.txt` |
| Entra app credential | web (sign-in), worker (Graph sync) | **Certificate (recommended):** `deploy/secrets/oidc_cert.pem` + unencrypted `deploy/secrets/oidc_key.pem`, mounted by `deploy/compose.yaml` as Docker secrets `pal_oidc_cert` / `pal_oidc_key` at `/run/secrets/pal_oidc_cert` and `/run/secrets/pal_oidc_key`. **Client secret (development):** `OIDC_CLIENT_SECRET` in `deploy/pal.env` |

Limitation (verified in code): PAL reads `DATABASE_URL`, `APP_SECRET` and `OIDC_CLIENT_SECRET` only from environment variables. There is no `*_FILE` variant, so these values live in `deploy/pal.env`. Protect that file (`chmod 600`, owned by root) and keep it out of version control (`deploy/secrets/.gitignore` covers the secrets folder; `pal.env` must never be committed).

### 4.2 Entra ID app registration (shared by all deployment guides)

1. Entra admin center > App registrations > New registration. Single tenant.
2. Platform **Web**, redirect URI: `https://pal.example.internal/auth/callback` (always `PAL_BASE_URL` + `/auth/callback`).
   PAL also sends `post_logout_redirect_uri = PAL_BASE_URL + "/"` on sign-out. If Entra rejects it, add `https://pal.example.internal/` as a redirect URI too (verify in your tenant).
3. No implicit grant is needed. PAL uses the authorization code flow with scopes `openid profile email`.
4. API permissions > Microsoft Graph > **Application** permissions: `GroupMember.Read.All`, `User.Read.All`. Grant admin consent. These are read-only; PAL never writes to the directory.
5. Credential, production: Certificates & secrets > Certificates > upload the **public** certificate. Generate the pair on the host, from `pal/`, into the paths `deploy/compose.yaml` mounts:

   ```sh
   sudo openssl req -x509 -newkey rsa:3072 -sha256 -days 365 -nodes \
     -subj "/CN=pal-oidc-client" \
     -keyout deploy/secrets/oidc_key.pem -out deploy/secrets/oidc_cert.pem
   sudo chown root:10001 deploy/secrets/oidc_key.pem deploy/secrets/oidc_cert.pem
   sudo chmod 0440 deploy/secrets/oidc_key.pem deploy/secrets/oidc_cert.pem
   ```

   Without Swarm, Compose file-based secrets are bind mounts that keep the host file's owner and mode, so the files must be readable by uid/gid 10001 (as above), and `deploy/secrets/` must be traversable by that gid. Upload `deploy/secrets/oidc_cert.pem` (public part only) to Entra; never copy the key off the host except into the encrypted backup.

   PAL signs a `private_key_jwt` client assertion (RS256, `x5t` and `x5t#S256` headers). The private key **must be unencrypted PEM**: the code calls `openssl_pkey_get_private()` without a passphrase.
6. Credential, development only: a client secret in `OIDC_CLIENT_SECRET`.
7. Configure **exactly one** credential. If both `OIDC_CLIENT_SECRET` and the cert/key paths are set, or neither, PAL treats sign-in as not configured (`sign_in_unavailable`). Note that `pal.env.example` pre-fills `OIDC_CLIENT_CERT_PATH`/`OIDC_CLIENT_KEY_PATH`; **blank them if you use a secret.**
8. **Mandatory:** Enterprise applications > PAL > Properties > *Assignment required?* = **Yes**, then assign **only the PAL users group**. PAL provisions every account that completes sign-in as a **Reader** (just-in-time, no further check), **B2B guest accounts included**. Without assignment, anyone in the tenant (and any guest) can sign in and read published content.
9. Record the Directory (tenant) ID and Application (client) ID for `OIDC_TENANT_ID` / `OIDC_CLIENT_ID`.

Sovereign clouds: `OIDC_AUTHORITY` and `GRAPH_BASE_URL` are configurable. The Graph token scope is derived from the `GRAPH_BASE_URL` origin (`<origin>/.default`). Example values are in §5; verify them for your cloud.

### 4.3 Mounting the certificate into the containers

`deploy/compose.yaml` mounts the certificate and key into `web` and `worker` as Docker secrets:

| Docker secret | Host file | Path in container |
|---|---|---|
| `pal_oidc_cert` | `deploy/secrets/oidc_cert.pem` | `/run/secrets/pal_oidc_cert` |
| `pal_oidc_key` | `deploy/secrets/oidc_key.pem` | `/run/secrets/pal_oidc_key` |

Set in `deploy/pal.env`:

```
OIDC_CLIENT_CERT_PATH=/run/secrets/pal_oidc_cert
OIDC_CLIENT_KEY_PATH=/run/secrets/pal_oidc_key
OIDC_CLIENT_SECRET=
```

Compose refuses to start a service whose secret file is missing, so both files must exist even before the certificate is uploaded to Entra. If you use a client secret instead (development only), blank the two paths and keep empty placeholder files in place (verify with `docker compose config`).

All commands in this guide use a shell variable for the Compose files (run them from `pal/`):

```sh
export PAL_COMPOSE="-f deploy/compose.yaml"
# when you add a host-local override (e.g. the audit anchor mount, §8.3), append it:
# export PAL_COMPOSE="-f deploy/compose.yaml -f deploy/compose.override.yaml"
```

Because `-f` is passed explicitly, Compose does **not** auto-load `compose.override.yaml`; it must be named in `PAL_COMPOSE`.

### 4.4 Database roles (least privilege)

`deploy/sql/roles.sql` creates two roles (idempotent):

| Role | Connects as | Can | Cannot |
|---|---|---|---|
| owner (`pal_owner`) | `migrate` only, via `DATABASE_URL_MIGRATIONS` | own the database, schema and all tables; run migrations | — (it can alter tables, so it can disable the audit triggers: keep its credentials away from the running app) |
| app (`pal_app`) | `web`, `worker`, via `DATABASE_URL` | `SELECT, INSERT, UPDATE, DELETE` on tables, use sequences | create/alter/drop objects; `UPDATE/DELETE/TRUNCATE` on `audit_event`; write `doctrine_migration_versions` |

Both roles are `NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT`. `roles.sql` sets no passwords on purpose.

**Bundled DB:** `init-roles.sh` runs `roles.sql` on the first start of an empty data volume and sets passwords from the Docker secrets. Nothing else to do on first install.

**External PostgreSQL:** a DBA runs it before the first migration:

```sh
psql "postgresql://<dba>@db.example.internal:5432/pal" -v ON_ERROR_STOP=1 \
     -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal \
     -f deploy/sql/roles.sql
psql "postgresql://<dba>@db.example.internal:5432/pal" -c '\password pal_owner'
psql "postgresql://<dba>@db.example.internal:5432/pal" -c '\password pal_app'
```

`roles.sql` runs `ALTER DATABASE ... OWNER` and `ALTER SCHEMA public OWNER`. A true superuser can do this. On a managed or non-superuser admin account the admin must be able to `SET ROLE pal_owner`; if the script fails with a permission error, run `GRANT pal_owner TO <dba>;` first (verify against your PostgreSQL version and policy).

Re-run `roles.sql` **after every migration** (see §8.1). The `migrate` role already re-applies the `audit_event` revokes when `PAL_APP_DB_ROLE` is set, but only `roles.sql` revokes app-role writes on `doctrine_migration_versions` and fixes ownership of objects created outside the owner role.

Contrast with the Render reference deployment (`pal/render.yaml`): it uses a **single** database role and leaves `PAL_APP_DB_ROLE` unset, so the runtime role owns `audit_event`. There the audit trail is tamper-**evident** (hash chain plus anchor) but not tamper-**resistant**: the running app's role could disable the triggers. That is acceptable only for demos; production must use the two-role model above.

## 5. Environment variables

Set in `deploy/pal.env` (Compose `env_file`). Values not set fall back to the image: Dockerfile `ENV` defaults first, then the image's `.env` (a copy of `backend/.env.example`). Real environment variables always win.

`Req.` column: **E** = the entrypoint exits with code 64 if missing for that role; **S** = needed for sign-in / directory sync to work; blank = optional.

| Variable | Example | Purpose | Roles | Req. |
|---|---|---|---|---|
| `APP_SECRET` | output of `openssl rand -hex 32` | Symfony kernel secret (CSRF tokens and other signed values). Same value on web, worker, migrate | web, worker, migrate | E |
| `DATABASE_URL` | `postgresql://pal_app:<pw>@db:5432/pal?serverVersion=17&charset=utf8` | Runtime connection as the **app** role. Also used directly by the PostgreSQL session handler. `serverVersion` must match the server. URL-encode special characters in the password (hex passwords avoid this) | all | E |
| `DATABASE_URL_MIGRATIONS` | `postgresql://pal_owner:<pw>@db:5432/pal?serverVersion=17&charset=utf8` | Connection as the **owner** role. Only the `migrate` role uses it (replaces `DATABASE_URL` for that run). If unset, migrations run as `DATABASE_URL` | migrate | |
| `PAL_APP_DB_ROLE` | `pal_app` | After migrating, `migrate` runs `REVOKE UPDATE, DELETE, TRUNCATE ON audit_event FROM "<role>"`. Must be a lowercase PostgreSQL identifier or the entrypoint exits 64 | migrate | |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Durable queue in PostgreSQL. Tables come from migrations (`auto_setup=0`). Image default is the same value | web, worker | |
| `PAL_BASE_URL` | `https://pal.example.internal` | Public origin users reach. Builds the OIDC redirect URI (`/auth/callback`) and post-logout URI. Set it on worker too (it is part of the OIDC configuration check) | web, worker | E (web) |
| `TRUSTED_HOSTS` | `^pal\.example\.internal$` | Regex of accepted `Host` headers. Anything else gets HTTP 400 | web | E (web) |
| `TRUSTED_PROXIES` | `172.18.0.1` | Exact IP(s)/CIDR of the TLS proxy as seen by the container (here: the Docker bridge gateway, §6.3). Only these may set `X-Forwarded-For/-Proto/-Port`. Image default `private_ranges` is a convenience, **not** the production setting. The proxy must **overwrite** `X-Forwarded-For`, or client IPs (rate limits, `client_ip` in audit events) can be spoofed | web | |
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | Entra authority. Issuer = `<authority>/<tenant>/v2.0`; all discovered endpoints must be https on this host. Image default as shown | web, worker | S |
| `OIDC_TENANT_ID` | `<tenant-guid>` | Entra tenant GUID. Also checked against the ID token `tid` claim. Zero GUID = not configured | web, worker | S |
| `OIDC_CLIENT_ID` | `<client-guid>` | Application (client) ID. Checked against `aud` | web, worker | S |
| `OIDC_CLIENT_SECRET` | *(empty in production)* | Client secret (development). Mutually exclusive with cert/key | web, worker | S (one of) |
| `OIDC_CLIENT_CERT_PATH` | `/run/secrets/pal_oidc_cert` | PEM certificate for `private_key_jwt` (production); Docker secret `pal_oidc_cert` | web, worker | S (one of) |
| `OIDC_CLIENT_KEY_PATH` | `/run/secrets/pal_oidc_key` | Unencrypted PEM private key matching the certificate; Docker secret `pal_oidc_key` | web, worker | S (one of) |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | Microsoft Graph base URL; must be https. Token scope = `<origin>/.default`. Pagination is only followed on this origin. Image default as shown | web, worker | S |
| `PAL_BOOTSTRAP_ADMIN_OIDS` | `<user-object-guid>,<user-object-guid>` | Comma-separated Entra **user** object IDs granted System Administrator until group role mappings exist. Remove after mapping | web | |
| `PAL_DEFAULT_TIMEZONE` | `America/Denver` | Organisation default timezone (decision D6a) | web, worker | |
| `PAL_SESSION_COOKIE_NAME` | `__Host-pal_session` | Session cookie name. Image default `__Host-pal_session` (requires HTTPS) | web | |
| `PAL_SESSION_COOKIE_SECURE` | `1` | `Secure` flag on the session cookie. Image default `1` | web | |
| `PAL_SESSION_IDLE_SECONDS` | `3600` | Session idle timeout (also PHP session GC lifetime) | web | |
| `PAL_SESSION_ABSOLUTE_SECONDS` | `43200` | Absolute session lifetime from sign-in | web | |
| `PAL_AUDIT_ANCHOR_DIR` | `/srv/pal-anchor` | Directory where the verified audit chain head is appended (`audit-anchors.jsonl`). Used by the daily scheduled verification in **worker** and by `pal:audit:verify --anchor`. Empty = scheduled run skips anchoring; `--anchor` fails | worker | |
| `LOG_LEVEL` | `info` | Minimum Monolog level for the JSON stderr log in prod | all | |
| `DEFAULT_URI` | `https://pal.example.internal` | Symfony router default URI for URL generation outside HTTP requests (CLI/worker). Not in `pal.env.example`; the image default is `http://localhost:5173`. Set it to `PAL_BASE_URL` | all | |
| `PAL_WORKER_TIME_LIMIT` | `3600` | Seconds before the worker exits cleanly to reclaim memory (Compose restarts it) | worker | |
| `PAL_WORKER_MEMORY_LIMIT` | `256M` | Worker memory limit before clean exit | worker | |

Do not change `APP_ENV` (`prod`) or `APP_DEBUG` (`0`); they are set in the image.

### 5.1 Commercial vs Azure Government Entra (identity provider values)

The host is on-premises, so only the identity cloud differs. Which column applies depends on **where your Entra tenant lives**, not where PAL runs.

| Variable | Microsoft Entra (commercial / GCC) | Azure Government (GCC High / DoD) — verify for your cloud |
|---|---|---|
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | `https://login.microsoftonline.us` |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | `https://graph.microsoft.us/v1.0` (GCC High), `https://dod-graph.microsoft.us/v1.0` (DoD) |

## 6. Configuration references

### 6.1 Compose-level settings

| Variable / setting | Example | Purpose |
|---|---|---|
| `PAL_IMAGE` | `registry.example.internal/pal:1.0.0` | **Compose interpolation variable**, not a container variable: set it in the shell or in `deploy/.env` (the Compose project-directory `.env`), not in `pal.env`. Defaults to `pal:latest` |
| `--profile bundled-db` | — | Starts the bundled `db` service (`postgres:17-alpine`, volume `pal-db`) |
| `deploy/secrets/db_admin_password.txt` | 48 hex chars | Bundled DB superuser `pal_admin` password (Docker secret) |
| `deploy/secrets/db_owner_password.txt` | 48 hex chars | Password set on `pal_owner` by `init-roles.sh` |
| `deploy/secrets/db_app_password.txt` | 48 hex chars | Password set on `pal_app` by `init-roles.sh` |
| `deploy/secrets/oidc_cert.pem` | PEM certificate | Docker secret `pal_oidc_cert` → `/run/secrets/pal_oidc_cert` (web, worker) |
| `deploy/secrets/oidc_key.pem` | unencrypted PEM key, mode 0440, group 10001 | Docker secret `pal_oidc_key` → `/run/secrets/pal_oidc_key` (web, worker) |
| Published port | `127.0.0.1:8080:8080` | `web` listens on loopback only; the host reverse proxy is the only client |

### 6.2 Fixed in the image (change only by rebuilding)

| Setting | Value | Source |
|---|---|---|
| Container port | `8080` (nginx) | `deploy/nginx/nginx.conf` |
| Container user | `pal`, uid/gid `10001` | `Dockerfile` |
| PHP-FPM | `127.0.0.1:9000`, `pm=dynamic`, `max_children=16`, `max_requests=500` | `deploy/php/fpm-pool.conf` |
| Request body limit | nginx `client_max_body_size 2m`; PHP `post_max_size=2M`, `upload_max_filesize=1M` | `nginx.conf`, `php.ini` |
| PHP limits | `memory_limit=256M`, `max_execution_time=30`, opcache with `validate_timestamps=0` | `php.ini` |
| Rate limits | `/auth/login` and `/auth/callback`: 20/min per client IP (`auth_login` limiter); `/api/*`: 600/min per user (or per IP when anonymous) | `config/packages/framework.yaml` |
| Branding logo URL | `https://` only (plain `http://` rejected) or a raster `data:` image URL | Settings > Branding |
| Trusted forwarded headers | `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Port` (**not** `X-Forwarded-Host`) | `framework.yaml` |
| HSTS | Sent only when the request arrived with `X-Forwarded-Proto: https` | `nginx.conf` |
| Request ID | Upstream `X-Request-ID` matching `^[A-Za-z0-9-]{8,64}$` is propagated, otherwise nginx generates one | `nginx.conf` |
| Scheduler | Directory sync every 15 min; audit verify + anchor daily 02:15 UTC; PostgreSQL advisory lock `pal-schedule-default` | `src/Platform/Scheduler/PalSchedule.php` |
| Messenger retries | 5 retries, 2 s × 3 backoff up to 10 min; then the `failed` queue | `config/packages/messenger.yaml` |
| Logs | JSON on stderr (Monolog), nginx access log JSON on stdout; audit/security events on channel `pal_audit` | `monolog.yaml`, `nginx.conf` |

### 6.3 Reverse proxy (host)

Requirements that follow from the code:

- Forward the original `Host` header unchanged. PAL does not trust `X-Forwarded-Host`, and `TRUSTED_HOSTS` is checked against `Host`.
- Set `X-Forwarded-Proto https` (enables HSTS and makes Symfony see HTTPS).
- **Overwrite** `X-Forwarded-For` with the connecting client address (`$remote_addr`). Do **not** append (`$proxy_add_x_forwarded_for`): appending keeps whatever the client sent, and a client could then choose the IP PAL uses for rate limiting and records as `client_ip` in audit events. (If there is a second trusted proxy in front of this one, configure that chain deliberately and list every hop in `TRUSTED_PROXIES`.)
- Allow request bodies of at least 2 MB (PAL's own limit).

Example (nginx on the host):

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name pal.example.internal;

    ssl_certificate     /etc/pki/tls/certs/pal.example.internal.crt;
    ssl_certificate_key /etc/pki/tls/private/pal.example.internal.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 2m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $remote_addr;   # overwrite, never append
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port  443;
        proxy_set_header X-Request-ID      $request_id;
    }
}

server {
    listen 80;
    server_name pal.example.internal;
    return 301 https://$host$request_uri;
}
```

`TRUSTED_PROXIES`: connections from the host proxy reach the container from the Docker bridge gateway. The image default `private_ranges` would also trust every other container and host on a private network, so set the **exact** address in production:

```sh
docker network inspect pal_default --format '{{(index .IPAM.Config 0).Gateway}}'
# put that address (e.g. 172.18.0.1) in TRUSTED_PROXIES, recreate web, then verify client IPs (§7, check 11)
```

The network exists after the first `up`. Its subnet can change if the network is recreated; pin it in a Compose override (`networks.default.ipam.config`) if that is a concern.

### 6.4 Outbound HTTP proxy

PAL's outbound calls (Entra discovery/JWKS/token, Graph) use Symfony HttpClient, which honours the standard `HTTPS_PROXY` / `HTTP_PROXY` / `NO_PROXY` environment variables (these are platform variables, not PAL settings; verify in your environment). PHP-FPM runs with `clear_env = no`, so variables set on the container reach request handling. If you set a proxy, put the database host in `NO_PROXY`.

### 6.5 Database TLS (external PostgreSQL)

`DATABASE_URL` carries no TLS options in the examples. For an external database on another host, require TLS. Because PAL opens two connections from `DATABASE_URL` (Doctrine DBAL and the PDO session handler), the most reliable way to apply TLS to both is libpq's own environment variables, e.g. `PGSSLMODE=verify-full` and `PGSSLROOTCERT=/run/secrets/pal-db-ca/ca.pem` (mount the CA file like the OIDC cert). Verify both connections are encrypted (§7, step 6).

## 7. Verification

Run from `pal/`. Replace the host name with yours.

### 7.1 First install

```sh
# 1. Secrets for the bundled DB (skip for external PostgreSQL)
umask 077
for f in db_admin_password db_owner_password db_app_password; do
  openssl rand -hex 24 > deploy/secrets/$f.txt
done

# 2. Runtime configuration
cp deploy/pal.env.example deploy/pal.env && chmod 600 deploy/pal.env
#    Fill in APP_SECRET, PAL_BASE_URL, TRUSTED_HOSTS, TRUSTED_PROXIES, both DATABASE_URLs (use
#    the owner/app passwords from step 1), OIDC_*, PAL_BOOTSTRAP_ADMIN_OIDS, DEFAULT_URI.
#    The OIDC certificate and key must already be in deploy/secrets/ (§4.2 step 5).

# 3. Check the rendered configuration (catches env-file quoting problems, e.g. in TRUSTED_HOSTS)
docker compose $PAL_COMPOSE --profile bundled-db config | grep -E 'TRUSTED_HOSTS|PAL_BASE_URL|OIDC_CLIENT_(CERT|KEY)_PATH'

# 4. Build (or set PAL_IMAGE to a registry image) and start
docker compose $PAL_COMPOSE build
docker compose $PAL_COMPOSE --profile bundled-db up -d     # omit --profile for external PostgreSQL
docker compose $PAL_COMPOSE ps -a                           # migrate: Exited (0); web, worker: running
docker compose $PAL_COMPOSE logs migrate                    # "audit trail revokes applied to role pal_app"
```

### 7.2 Checks

| # | Check | Command | Expected |
|---|---|---|---|
| 1 | Liveness | `curl -fsS http://127.0.0.1:8080/health/live` | `{"status":"ok"}` |
| 2 | Readiness (DB + migrations current) | `curl -sS https://pal.example.internal/health/ready` | HTTP 200, `{"status":"ready","checks":{"database":"ok","migrations":"ok"}}` |
| 3 | Host header protection | `curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: evil.example' http://127.0.0.1:8080/health/live` | `400` |
| 4 | TLS + headers | `curl -sI https://pal.example.internal/` | `200`, `Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options: DENY` |
| 5 | Secrets resolved: certificate readable by uid 10001 | `docker compose $PAL_COMPOSE exec web sh -c 'test -r "$OIDC_CLIENT_CERT_PATH" && test -r "$OIDC_CLIENT_KEY_PATH" && echo readable'` | `readable` |
| 6 | Secrets resolved: DB roles in use (+ TLS for external DB) | As DB admin: `SELECT a.usename, a.application_name, s.ssl FROM pg_stat_activity a LEFT JOIN pg_stat_ssl s USING (pid) WHERE a.datname = 'pal';` | Only `pal_app` connections while running (`pal_owner` only during migrate); `ssl = t` for external DB |
| 7 | Sign-in | Browse `https://pal.example.internal/`, sign in with a bootstrap admin account | Lands in PAL signed in, not on `/sign-in?auth_error=…`. `GET /api/me` returns your user |
| 7a | Assignment enforced | Sign in with a tenant account that is **not** in the PAL users group | Entra refuses (AADSTS50105, user not assigned); no PAL user is created |
| 8 | Directory sync (Graph credentials + consent) | `docker compose $PAL_COMPOSE exec worker php /srv/pal/backend/bin/console pal:roles:map PAL_ADMINISTRATOR <group-object-id>` then `... pal:directory:sync` | `PAL_ADMINISTRATOR mapped to "<group name>" (N members).`; sync prints `<group-id>: N members (+a / -r)` and exits 0 |
| 9 | Audit chain | `docker compose $PAL_COMPOSE exec worker php /srv/pal/backend/bin/console pal:audit:verify` | `Audit chain valid: N events, head <hash>` |
| 10 | Audit trail append-only for runtime role | As DB admin: `SELECT has_table_privilege('pal_app','audit_event','UPDATE'), has_table_privilege('pal_app','audit_event','DELETE');` | `f`, `f` |
| 11 | Client IP seen correctly, not spoofable | `curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.9' 'https://pal.example.internal/auth/callback?state=x&code=y'` from a known workstation, then `docker compose $PAL_COMPOSE logs web` | The application log entry for that callback shows the workstation's real IP, **not** `203.0.113.9` and not the bridge gateway. (Junk callbacks without a valid pending sign-in go to the application log only, not the audit trail) |
| 12 | Worker scheduler running | `docker compose $PAL_COMPOSE logs worker \| grep -i 'directory sync'` (after ≤15 min) | `Scheduled directory sync finished` (or `skipped` if Graph is not configured) |
| 13 | Upload accepted + scanned, object written | — | **Not applicable until Phase 2 (document storage)** |

After group mappings exist and you have signed in through a mapped group, remove `PAL_BOOTSTRAP_ADMIN_OIDS` and recreate `web`.

Self-grant guard: in the admin UI/API, a signed-in user cannot map a directory group **they belong to** onto a document-authority role (`PAL_ADMINISTRATOR`, `PUBLISHER`, `AUTHOR`); PAL answers HTTP 403 `self_grant_forbidden`. Have a different administrator create that mapping. The CLI (`pal:roles:map`) is an operator action recorded in the audit trail as actor `cli:pal:roles:map`; restrict shell access to the host accordingly.

Role names accepted by `pal:roles:map`: `SYSTEM_ADMINISTRATOR`, `PAL_ADMINISTRATOR`, `PUBLISHER`, `AUTHOR` (case-insensitive; `READER` is implicit for every active user). `--remove` removes a mapping.

Note on `docker compose exec`: it bypasses the entrypoint and starts in `/srv/pal`, so call `php /srv/pal/backend/bin/console ...` with the full path. Alternatively `docker compose $PAL_COMPOSE run --rm --no-deps worker console <command>` goes through the entrypoint's `console` role.

## 8. Day-2 operations

### 8.1 Upgrades

Migrations are applied by the `migrate` role. Build/pull the new image first, then:

```sh
export PAL_IMAGE=registry.example.internal/pal:1.1.0
docker compose $PAL_COMPOSE pull web worker migrate          # if using a registry
# 1. Back up (§8.2) — migrations are forward-only in practice.
# 2. Apply migrations (also re-applies the audit_event revokes)
docker compose $PAL_COMPOSE run --rm migrate
# 3. Re-run roles.sql as DB admin (bundled DB shown; external: run psql as in §4.4)
docker compose $PAL_COMPOSE exec -T db psql -U pal_admin -d pal -v ON_ERROR_STOP=1 \
  -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal -f /sql/roles.sql
# 4. Roll web and worker onto the new image
docker compose $PAL_COMPOSE up -d web worker
curl -fsS https://pal.example.internal/health/ready
```

Rollback: if the release contained no migration, set `PAL_IMAGE` back and `up -d`. If it did, restore the pre-upgrade backup; Doctrine `down()` methods drop tables and lose data. Caution: readiness only checks for **unapplied** migrations, so an older image against a newer schema reports ready even though it may not work.

### 8.2 Backups

All state is in PostgreSQL. The provisional recovery targets are RPO 15 min / RTO 4 h (decision D10, not yet confirmed). A nightly logical dump alone does **not** meet a 15-minute RPO; use continuous WAL archiving (e.g. pgBackRest or your DBA's standard) for the external database. For the bundled DB, a logical dump:

```sh
docker compose $PAL_COMPOSE exec -T db pg_dump -U pal_admin -d pal -Fc > /backup/pal-$(date -u +%Y%m%dT%H%MZ).dump
```

Also back up, separately and encrypted: `deploy/pal.env`, `deploy/secrets/` (DB passwords, OIDC certificate and key), the reverse proxy certificate, and the audit anchor directory.

Restore (bundled DB, into an empty database):

```sh
docker compose $PAL_COMPOSE stop web worker
docker compose $PAL_COMPOSE exec -T db psql -U pal_admin -d postgres -c 'DROP DATABASE pal WITH (FORCE)' -c 'CREATE DATABASE pal'
docker compose $PAL_COMPOSE exec -T db psql -U pal_admin -d pal -v ON_ERROR_STOP=1 \
  -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal -f /sql/roles.sql
docker compose $PAL_COMPOSE exec -T db pg_restore -U pal_admin -d pal --no-owner < /backup/pal-<timestamp>.dump
docker compose $PAL_COMPOSE exec -T db psql -U pal_admin -d pal -v ON_ERROR_STOP=1 \
  -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal -f /sql/roles.sql   # fix ownership + grants
docker compose $PAL_COMPOSE up -d web worker
docker compose $PAL_COMPOSE exec worker php /srv/pal/backend/bin/console pal:audit:verify
```

`DROP DATABASE` is destructive: confirm the backup is readable (`pg_restore -l`) first. Restoring `audit_event` works because the triggers block only UPDATE/DELETE/TRUNCATE. Compare the verified head hash with the last line of `audit-anchors.jsonl` to confirm the restored trail matches what was anchored.

### 8.3 Audit anchoring

The worker appends the chain head daily if `PAL_AUDIT_ANCHOR_DIR` is set **and** that directory is mounted writable for uid 10001. `deploy/compose.yaml` mounts no such directory (the root filesystem is read-only), so add it in a host-local `deploy/compose.override.yaml` (not committed; add it to `PAL_COMPOSE`, §4.3), pointing at storage administered by another team (ideally write-once):

```yaml
# deploy/compose.override.yaml
services:
  worker:
    volumes:
      - /mnt/pal-anchor:/srv/pal-anchor
```

With `PAL_AUDIT_ANCHOR_DIR=/srv/pal-anchor`. If the variable is set but the directory is not writable, the scheduled job throws and Messenger retries it, then moves it to the `failed` queue.

### 8.4 Logs and SIEM forwarding

- All containers log JSON lines to stdout/stderr. Audit/security events carry `"channel":"pal_audit"`; every record has `extra.correlation_id`.
- Configure Docker log rotation, otherwise the default `json-file` driver grows without bound. Example `/etc/docker/daemon.json`: `{"log-driver":"json-file","log-opts":{"max-size":"50m","max-file":"10"}}` (applies to newly created containers).
- Forward to **Microsoft Sentinel** using what the SOC already runs (decision D17): the **Azure Monitor Agent** on an Azure Arc-enabled host with a Data Collection Rule (collecting syslog, or the JSON log files), or an existing **syslog/CEF forwarder** (e.g. Docker `syslog`/`journald` log driver into rsyslog). Do **not** use the legacy Log Analytics HTTP Data Collector API. Check the syslog message size limit; JSON audit lines can exceed 2 KB.
- Alert on: `Audit chain verification FAILED` (critical), repeated `USER_SIGN_IN_FAILED`, `Sign-in is unavailable`, worker restarts that do not recover.
- Sentinel is not uptime monitoring; point an uptime monitor at `/health/ready`.

### 8.5 Rotating secrets and certificates

| Secret | Procedure |
|---|---|
| Entra certificate | Generate a new pair (§4.2) under temporary names, upload the new public cert to the app registration **alongside** the old one, move the new files over `deploy/secrets/oidc_cert.pem` / `oidc_key.pem` (same owner/mode), then `docker compose $PAL_COMPOSE up -d --force-recreate web worker` (a file bind mount pins the old inode, so a plain `restart` may keep the old file). Verify sign-in and `pal:directory:sync`, then delete the old cert from Entra. Track the expiry date (365 days in the example). |
| Client secret (dev) | Create a new secret in Entra, update `OIDC_CLIENT_SECRET`, `docker compose $PAL_COMPOSE up -d web worker`, delete the old secret. |
| `pal_app` password | `ALTER ROLE pal_app PASSWORD '...'` (or `\password pal_app`), update `DATABASE_URL` (and `deploy/secrets/db_app_password.txt` for consistency), `up -d web worker`. Brief connection errors are expected between the two steps. |
| `pal_owner` password | Same, updating `DATABASE_URL_MIGRATIONS`. No running service uses it. |
| `APP_SECRET` | Replace on web, worker and migrate together; `up -d`. Expect in-flight forms/CSRF tokens to fail until users reload. |

`deploy/secrets/*.txt` are only read by `init-roles.sh` on the **first** start of an empty volume; changing them later does not change passwords.

### 8.6 Failed background jobs

```sh
docker compose $PAL_COMPOSE exec worker php /srv/pal/backend/bin/console messenger:failed:show
docker compose $PAL_COMPOSE exec worker php /srv/pal/backend/bin/console messenger:failed:retry
```

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Container exits immediately, log `pal: required environment variable X is not set`, exit code **64** | Missing required variable for that role (web: `APP_SECRET`, `DATABASE_URL`, `PAL_BASE_URL`, `TRUSTED_HOSTS`; worker/migrate: `APP_SECRET`, `DATABASE_URL`) | Set it in `deploy/pal.env`; check `docker compose config` |
| Exit 64 `PAL_APP_DB_ROLE must be a lowercase PostgreSQL identifier` | Invalid role name | Use e.g. `pal_app` |
| Exit 64 `unknown role` | Wrong command | Use `web`, `worker`, `migrate` or `console` |
| `web`/`worker` never start; `migrate` exited non-zero | Migration failed (DB unreachable, wrong owner password, permissions) | `docker compose $PAL_COMPOSE logs migrate`; fix; re-run `run --rm migrate` |
| `/health/ready` returns **503** with `"migrations":"fail"` | Migrations not applied | `docker compose $PAL_COMPOSE run --rm migrate` |
| `/health/ready` 503 with `"database":"fail"` | DB unreachable or wrong credentials | Check `DATABASE_URL`, DB health, `pg_hba.conf`; the web log has `Readiness check failed` with the exception |
| Any request returns **400** | `Host` header does not match `TRUSTED_HOSTS` (or the proxy rewrites Host) | Fix the regex (escape dots, anchor with `^…$`); make the proxy send `Host $host` |
| Browser lands on `/sign-in?auth_error=sign_in_unavailable` | OIDC not configured (`OIDC_TENANT_ID`/`OIDC_CLIENT_ID` not GUIDs or zero GUID; authority not https; **not exactly one** credential), certificate unreadable/unparseable, or Entra unreachable/untrusted endpoint | `docker compose $PAL_COMPOSE logs web \| grep -i 'sign-in'` shows `reason` (`not_configured`, `provider_unavailable`, `provider_untrusted`); fix config, egress or file permissions |
| `/sign-in?auth_error=sign_in_failed` | Token exchange or ID token validation failed (wrong secret/cert not uploaded, issuer/audience/tenant mismatch, clock skew) | Web log `Sign-in failed` with `reason`; check the app registration, the uploaded cert, NTP |
| `/sign-in?auth_error=sign_in_expired` | Sign-in round trip took too long or the session cookie was lost (e.g. `__Host-`/Secure cookie over plain HTTP) | Use HTTPS; check the proxy forwards cookies |
| `/sign-in?auth_error=account_disabled` | User deactivated in PAL (disabled in the directory) | Re-enable in Entra; next sync reactivates (verify) |
| `/sign-in?auth_error=too_many_attempts` or many users hit **429** together | Rate limits (`/auth/login`, `/auth/callback`: 20/min per IP) are keyed by client IP, and PAL sees every user as the proxy's IP | Set `TRUSTED_PROXIES` to the proxy's exact address; make the proxy set `X-Forwarded-For $remote_addr` |
| Audit `client_ip` / rate-limit keys show addresses users chose (e.g. values from a forged header) | Proxy **appends** to `X-Forwarded-For`, or `TRUSTED_PROXIES` is too broad | Overwrite the header at the proxy (`$remote_addr`); narrow `TRUSTED_PROXIES` to the proxy IP (§6.3); re-run check 11 |
| Admin gets HTTP **403** `self_grant_forbidden` when adding a role mapping | The admin is a member of the group being mapped to `PAL_ADMINISTRATOR`/`PUBLISHER`/`AUTHOR` (separation of duties) | Another administrator creates the mapping |
| Saving branding fails for a logo URL | Logo URL is `http://` (rejected) or a non-raster `data:` URL | Use an `https://` URL or upload a raster image (stored as a `data:` URL) |
| Unassigned users or guests can sign in | Enterprise app *Assignment required* is not Yes | Set it to Yes and assign only the PAL users group (§4.2 step 8) |
| Redirect after sign-in goes to `http://` or wrong host; no HSTS | Proxy does not send `X-Forwarded-Proto: https`, or `TRUSTED_PROXIES` excludes it | Fix the proxy headers / `TRUSTED_PROXIES` |
| Entra error `AADSTS50011` (redirect URI mismatch) | Registered redirect URI ≠ `PAL_BASE_URL/auth/callback` | Align the app registration with `PAL_BASE_URL` exactly (scheme, host, port) |
| `pal:directory:sync` says `Directory sync is not configured` | OIDC settings incomplete, or `GRAPH_BASE_URL` not https | Fix variables on the **worker** too |
| Sync fails `Graph request failed with HTTP 403` | Application permissions missing or admin consent not granted | Grant `GroupMember.Read.All` + `User.Read.All` (application) with admin consent |
| `Could not obtain a Graph access token: …` | Credential problem (same causes as sign-in) | As for `sign_in_unavailable` |
| `pal:audit:verify` reports `Audit chain BROKEN at event N` | The trail was altered or corrupted | Treat as a security incident: preserve the DB, compare with anchors, involve the security owner. Do not "repair" rows |
| `PAL_AUDIT_ANCHOR_DIR is not set to a writable directory` | Anchor dir not mounted or not writable by uid 10001 | Mount it (§8.3), `chown 10001` or adjust ACLs |
| `docker compose up` fails: secret file `oidc_cert.pem`/`oidc_key.pem` not found | Compose needs every referenced secret file | Create the files (§4.2 step 5), or empty placeholders when using a client secret |
| `sign_in_unavailable` with reason `not_configured` and log `client certificate or key is not readable` | Secret files not readable by uid 10001 (bind mount keeps host mode) | `chown root:10001`, `chmod 0440` on both files; recreate web/worker |
| `roles.sql` fails `must be able to SET ROLE "pal_owner"` / `must be owner of` | Admin is not superuser | `GRANT pal_owner TO <dba>;` then re-run |
| Disk fills on the host | Unrotated container logs | Configure Docker log rotation (§8.4) |

---

Related: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md), [KUBERNETES.md](KUBERNETES.md), [AIRGAPPED.md](AIRGAPPED.md), [AZURE.md](AZURE.md), [AWS.md](AWS.md).
