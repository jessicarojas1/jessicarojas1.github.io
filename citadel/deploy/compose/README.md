# CITADEL — Production Docker Compose Stack

A hardened, single-host stack that puts CITADEL in front of real users with TLS,
a private PostgreSQL database, and a least-privilege application container.

```
Internet ──443/80──▶ proxy (nginx, TLS) ──▶ citadel (Node 20 API) ──▶ postgres
                     │ only public surface    │ private network        │ private, no Internet
```

| Service    | Image                 | Published | Notes                                            |
|------------|-----------------------|-----------|--------------------------------------------------|
| `proxy`    | `nginx:1.27-alpine`   | 80, 443   | TLS termination, security headers, reverse proxy |
| `citadel`  | built / `IMAGE_REF`   | none      | Read-only root FS, non-root 10001, all caps dropped |
| `postgres` | `postgres:16-alpine`  | none      | Named volume, `pg_isready` health, internal-only network |

Only the proxy publishes ports. `citadel` (8080) and `postgres` (5432) are
reachable **only** on the internal Docker networks.

---

## 1. Prerequisites

- Docker Engine 24+ with the Compose v2 plugin (`docker compose version`).
- This stack builds the app image from the **repository root** context
  (`context: ../../..`), because `citadel/server/Dockerfile` copies both
  `citadel/` (the SPA) and `citadel/server/` (the backend). You therefore need
  the full repo checked out, not just this folder.

---

## 2. Bring it up

Run everything below from **this directory** (`citadel/deploy/compose/`).

```bash
# 1. Create your secrets file from the template and fill in REAL values.
cp .env.example .env
$EDITOR .env                       # see "Secrets" below

# 2. Provide TLS certs (self-signed for dev; real certs for prod — see "TLS").
mkdir -p certs
openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
  -keyout certs/privkey.pem -out certs/fullchain.pem \
  -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost"

# 3. Validate the rendered configuration (no containers started).
docker compose config

# 4. Build the app image and start the stack in the background.
docker compose up -d --build

# 5. Watch health come up.
docker compose ps
docker compose logs -f citadel
```

Then browse to `https://localhost/` (accept the self-signed cert warning in dev).
The app health endpoint is proxied at `https://localhost/api/health`; the proxy's
own liveness is `https://localhost/healthz`.

---

## 3. Secrets

All credentials live in `.env`, which is **git-ignored** (see `.gitignore`).
**Never commit a real `.env`** — only `.env.example` with placeholders.

Generate strong values:

```bash
openssl rand -base64 48     # CITADEL_JWT_SECRET, CITADEL_SUPERADMIN_TOKEN
openssl rand -base64 32     # CITADEL_METRICS_TOKEN
openssl rand -base64 24     # POSTGRES_PASSWORD, CITADEL_ADMIN_PASSWORD
```

Required before first boot:

- `POSTGRES_PASSWORD` — the stack refuses to start without it.
- `CITADEL_JWT_SECRET` — signs session JWTs.
- `CITADEL_ADMIN_EMAIL` / `CITADEL_ADMIN_PASSWORD` — bootstrap admin account.

The app's `DATABASE_URL` is **composed automatically** in `docker-compose.yml`
from `POSTGRES_USER` / `POSTGRES_PASSWORD` / `POSTGRES_DB` and points at the
internal `postgres` service. Override `DATABASE_URL` in `.env` only if you point
CITADEL at an external/managed database instead of the bundled one.

> **Docker secrets (optional, more hardened):** for Swarm or to keep secrets out
> of the container environment entirely, replace the `env_file`/`environment`
> values with a top-level `secrets:` block and `*_FILE` env vars (e.g.
> `POSTGRES_PASSWORD_FILE=/run/secrets/pg_password`). The current stack uses an
> env-file for single-host simplicity; neither approach bakes secrets into the image.

---

## 4. TLS certificates

Certs are mounted **read-only** from `./certs` into the proxy at
`/etc/nginx/certs`:

- `fullchain.pem` — server cert + intermediate chain
- `privkey.pem` — private key (**git-ignored — never commit**)

**Development:** the self-signed `openssl` command in step 2 above.

**Production — Let's Encrypt (certbot):** issue certs on the host and mount the
live directory, e.g.:

```bash
certbot certonly --standalone -d citadel.example.com
# then point ./certs at /etc/letsencrypt/live/citadel.example.com/ (symlink/copy
# fullchain.pem + privkey.pem), or add a certbot sidecar + the ACME challenge
# location already present in nginx/citadel.conf (/.well-known/acme-challenge/).
```

**Production — AWS ACM / corporate CA:** export the cert + chain as
`fullchain.pem` and the key as `privkey.pem` into `./certs`.

After renewing certs, reload the proxy without downtime:

```bash
docker compose exec proxy nginx -s reload
```

---

## 5. Enabling multi-tenancy

Multi-tenancy is **off by default**. To enable tenant sub-domain routing:

1. In `.env`:
   ```ini
   CITADEL_MULTITENANT=1
   CITADEL_BASE_DOMAIN=citadel.example.com
   CITADEL_SUPERADMIN_TOKEN=<openssl rand -base64 48>
   ```
   Tenant `acme` is then served at `acme.citadel.example.com`.
2. DNS: point a wildcard `*.citadel.example.com` record at this host.
3. TLS: supply a **wildcard certificate** (`*.citadel.example.com`) in `./certs`
   so every tenant sub-domain is covered. The proxy `server_name _;` already
   accepts any host; only the cert needs to match.
4. Recreate the app: `docker compose up -d citadel`.

---

## 6. Scaling notes

This is a **single-host** stack. To grow:

- **Vertical:** raise the `citadel` `deploy.resources.limits` (CPU/memory).
  Deep scanners (Semgrep/Trivy/Grype/ClamAV) are CPU/RAM heavy.
- **Horizontal (app):** the app is stateless apart from Postgres + the tmpfs
  scratch dir, so you can run more replicas behind the proxy:
  ```bash
  docker compose up -d --scale citadel=3
  ```
  Then change the nginx `upstream citadel_app` to load-balance the replicas
  (Docker's embedded DNS round-robins the `citadel` service name; nginx with
  `resolver 127.0.0.11` + a variable `proxy_pass` will pick up all replicas).
- **Database:** for real scale, move off the in-stack Postgres to a managed,
  backed-up service and set `DATABASE_URL` + `PGSSL=1` / `PGSSLMODE=require`.
- **Beyond one host:** use the Kubernetes Helm chart under
  `deploy/kubernetes/citadel` or the cloud IaC under `deploy/aws-gov` /
  `deploy/azure-gov`.

---

## 7. Operations

```bash
docker compose ps                       # status + health
docker compose logs -f citadel          # app logs
docker compose exec proxy nginx -t      # validate proxy config
docker compose pull                     # pull newer base images (proxy/postgres)
docker compose up -d --build            # rebuild + roll the app
```

**Backups (Postgres data lives on the `pgdata` named volume):**

```bash
docker compose exec -T postgres \
  pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" | gzip > citadel-$(date +%F).sql.gz
```

---

## 8. Teardown

```bash
docker compose down                     # stop & remove containers + networks
docker compose down --rmi local         # also remove the locally built image
docker compose down -v                  # ALSO delete named volumes (DESTROYS the DB!)
```

> `-v` removes the `pgdata` volume and your database with it. Take a backup
> first (section 7).

---

## 9. Hardening summary

- App container: `read_only: true`, `cap_drop: [ALL]`,
  `security_opt: [no-new-privileges:true]`, non-root `user: 10001:10001`.
- Writable paths limited to RAM-backed tmpfs (`/tmp/citadel`, `/var/lib/clamav`,
  `/tmp`) — untrusted uploads never touch persistent disk.
- Database on an `internal: true` network with **no outbound Internet route**;
  never published to the host.
- Only the proxy is exposed; it enforces TLS 1.2+, modern ciphers, HSTS and a
  full security-header set. The app's own nonce-based CSP is preserved (the
  proxy does not override it).
- No secrets in the image or in version control — everything comes from the
  git-ignored `.env` (or Docker secrets).
