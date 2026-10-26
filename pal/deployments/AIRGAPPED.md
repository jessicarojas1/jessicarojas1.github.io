# PAL in an air-gapped or restricted-egress environment

How to run PAL where hosts have no general internet access. It builds on [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) (Docker Compose on one Linux host); the same approach applies to [KUBERNETES.md](KUBERNETES.md) with an internal registry.

Scope: PAL **Phase 1 foundation**. All state is in PostgreSQL; no document storage, malware scanner or preview converter yet (Phase 2+), so there are no virus-definition feeds to mirror yet.

**Read §4.1 first.** PAL's sign-in is Microsoft Entra ID. Two very different situations are covered:

| Situation | Entra reachable? | PAL status |
|---|---|---|
| **A. Restricted egress** (no general internet, but an allow-listed path to Entra/Graph through a proxy or firewall) | Yes, only those hosts | **Supported** with the configuration in this guide |
| **B. Fully disconnected enclave** (no path to any Microsoft cloud endpoint) | No | **Not supported by the current code** without changes (§4.1) |

**Self-hosted LLM / Ollama: not applicable — PAL makes no LLM calls.**

---

## 1. Deployment architecture

Same runtime as the single-server guide (`web`, `worker`, one-shot `migrate`, PostgreSQL, host reverse proxy). What changes:

| Concern | Connected deployment | Air-gapped / restricted |
|---|---|---|
| Container image | Built on the host or pulled from a registry | Built on a connected build host, transferred as a signed archive, loaded or pushed to an **internal registry** |
| Base images (`php:8.4-fpm-alpine`, `node:24-alpine`, `composer:2`, `postgres:17-alpine`) | Docker Hub | Internal registry mirror |
| npm / Composer / Alpine packages | Public registries during `docker build` | Only needed if you build inside the enclave: internal mirrors (§6.2) |
| Entra ID / Graph | Direct HTTPS | Situation A: allow-listed egress via proxy. Situation B: unavailable |
| Time | Public NTP | Internal NTP source (ID tokens allow 120 s skew) |
| CVE data for image scanning | Online | Transferred offline database (§8.4) |
| SIEM | Microsoft Sentinel | Situation A: Sentinel via the approved forwarder. Situation B: the enclave's own SIEM/syslog collector |

## 2. Topology

```
 CONNECTED SIDE                          |  ENCLAVE
                                         |
 Build host (CI)                         |  Transfer station (scan, verify checksum/signature)
  docker build -> pal:1.0.0              |        |
  docker save | gzip > pal-1.0.0.tar.gz  |        v
  sha256sum, cosign/gpg sign  ===media===>  Internal registry  registry.example.internal
  base images, Trivy DB  ========media===>        |
                                         |        v
                                         |  Linux host pal.example.internal
                                         |   reverse proxy :443 -> web :8080
                                         |   worker, migrate, PostgreSQL 17
                                         |   internal NTP, internal DNS, internal CA
                                         |        |
                                         |        |  Situation A only:
                                         |        +--> egress proxy (allow-list) --> login.microsoftonline.com
                                         |                                       --> graph.microsoft.com
                                         |  Situation B: no path out -> sign-in unavailable (see §4.1)
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Connected build host | Docker with Buildx, internet access to Docker Hub, npm registry, Packagist, Alpine mirrors; the PAL source at a reviewed commit |
| Transfer process | Approved media/cross-domain process; checksum and signature verification on the enclave side |
| Internal registry | OCI registry (e.g. Harbor, Nexus, Artifactory) reachable from the PAL host, with TLS from the internal CA |
| PAL host | As in [SINGLE_LINUX_SERVER.md §3](SINGLE_LINUX_SERVER.md#3-prerequisites); Docker trusts the internal CA |
| Time | Internal NTP |
| Identity (Situation A) | Egress proxy allowing HTTPS to the Entra authority host and the Graph host only; Entra app as in [SINGLE_LINUX_SERVER.md §4.2](SINGLE_LINUX_SERVER.md#42-entra-id-app-registration-shared-by-all-deployment-guides), including the **mandatory** *Assignment required = Yes* with only the PAL users group assigned (every account that can sign in becomes a Reader, B2B guests included) |

## 4. Identity and credentials

### 4.1 Identity provider reachability (limitation)

Sign-in **requires the browser and the PAL backend to reach the identity provider**. The browser is redirected to the authority's authorization endpoint, and the backend fetches the discovery document, signing keys (JWKS) and tokens from it. Directory sync calls Microsoft Graph. If Entra is unreachable, `/auth/login` redirects to `/sign-in?auth_error=sign_in_unavailable` (log reason `provider_unavailable`) and directory sync fails.

**Situation A (restricted egress)** works: allow-list the authority host (e.g. `login.microsoftonline.com`, or `login.microsoftonline.us` for an Azure Government tenant) and the Graph host (e.g. `graph.microsoft.com` / `graph.microsoft.us`), route PAL's outbound HTTPS through the proxy (§6.3), and allow users' browsers to reach the authority host.

**Situation B (fully disconnected)** needs an identity provider **inside** the enclave. PAL speaks standard OIDC (authorization code flow, discovery, JWKS, RS256 ID tokens), but the code is currently Entra-specific in ways that block other providers. Verified in `src/Identity/Oidc/*` and `src/Identity/Directory/*`:

| Code behaviour | Effect on a non-Entra IdP (e.g. Keycloak, AD FS) |
|---|---|
| Issuer is computed as `<OIDC_AUTHORITY>/<OIDC_TENANT_ID>/v2.0` and discovery as `<issuer>/.well-known/openid-configuration` | The IdP's issuer URL must match this exact shape |
| `OIDC_TENANT_ID` and `OIDC_CLIENT_ID` must be GUIDs | Non-GUID client IDs are rejected as "not configured" |
| ID token must contain a `tid` claim equal to `OIDC_TENANT_ID` and an `oid` claim that is a GUID | Most IdPs emit neither; sign-in fails (`id_token_invalid`) unless the IdP is configured with custom claim mappers to emit them |
| Discovered endpoints must be https on the authority host | Usually satisfiable |
| Group sync uses the Microsoft Graph API (`/groups`, `transitiveMembers`, `transitiveMemberOf`) | No equivalent for other directories: no group-based role mappings, no disabled-account detection. `pal:roles:map` cannot resolve groups |
| Client authentication: client secret or `private_key_jwt` with `x5t` headers | Supported by most IdPs |

Consequence: running PAL in a fully disconnected enclave requires **code changes** (a configurable issuer/claim mapping and a directory adapter for the enclave's directory, e.g. LDAP/AD). Configuring an IdP to imitate Entra's issuer path and claims might get sign-in working, but it is untested and unsupported, and group sync would still not work (only `PAL_BOOTSTRAP_ADMIN_OIDS` would grant roles). Treat Situation B as **not supported** until that work is planned and recorded in `OPEN_ITEMS.md`.

### 4.2 Credentials

Same as the single-server guide: certificate credential for the Entra app (`deploy/secrets/oidc_cert.pem` / `oidc_key.pem`, mounted by `deploy/compose.yaml` as Docker secrets `pal_oidc_cert` / `pal_oidc_key` at `/run/secrets/pal_oidc_cert` and `/run/secrets/pal_oidc_key`), DB passwords in `deploy/pal.env` (mode 0600) and `deploy/secrets/`. No managed identity exists in an enclave. Generate keys and passwords **inside** the enclave; only the public certificate leaves it (uploaded to Entra by an administrator, Situation A). Secrets never travel on transfer media.

### 4.3 Database roles

Unchanged: `deploy/sql/roles.sql` with an owner role (migrations) and an app role (runtime), re-run after every migration ([SINGLE_LINUX_SERVER.md §4.4](SINGLE_LINUX_SERVER.md#44-database-roles-least-privilege)).

## 5. Environment variables

All variables from [SINGLE_LINUX_SERVER.md §5](SINGLE_LINUX_SERVER.md#5-environment-variables) apply. Air-gap specific values:

| Variable | Example | Purpose |
|---|---|---|
| `PAL_IMAGE` (Compose interpolation) | `registry.example.internal/pal@sha256:<digest>` | Run the transferred image by digest |
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` (or `.us` for an Azure Government tenant) | Situation A: must be reachable via the proxy |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` (or `https://graph.microsoft.us/v1.0`) | Situation A: must be reachable via the proxy |
| `OIDC_CLIENT_CERT_PATH` / `OIDC_CLIENT_KEY_PATH` | `/run/secrets/pal_oidc_cert` / `/run/secrets/pal_oidc_key` | Certificate credential from Docker secrets |
| `TRUSTED_PROXIES` | `172.18.0.1` | Exact address of the TLS proxy as seen by the container; the proxy must overwrite `X-Forwarded-For` (see the single-server guide §6.3) |
| `HTTPS_PROXY` | `http://proxy.example.internal:3128` | Platform variable (not a PAL setting): outbound proxy for Entra/Graph calls. Symfony HttpClient honours it (verify) |
| `NO_PROXY` | `db,localhost,127.0.0.1,.example.internal` | Platform variable: keep database and internal traffic off the proxy |
| `PGSSLMODE` / `PGSSLROOTCERT` | `verify-full` / `/run/secrets/pal-db-ca/ca.pem` | libpq TLS to an external PostgreSQL using the internal CA (mount the CA file) |

## 6. Configuration references

### 6.1 Offline image transfer (recommended path)

Build where the internet is available, transfer the finished image. Nothing is downloaded inside the enclave.

```sh
# Connected build host, from pal/
docker build -t pal:1.0.0 .
docker pull postgres:17-alpine                       # only if using the bundled DB
docker save pal:1.0.0 postgres:17-alpine | gzip > pal-1.0.0-images.tar.gz
sha256sum pal-1.0.0-images.tar.gz > pal-1.0.0-images.tar.gz.sha256
# sign per your process, e.g. gpg --detach-sign pal-1.0.0-images.tar.gz
# also copy the deploy/ folder (compose.yaml, pal.env.example, sql/, secrets/.gitignore) at the same commit

# Enclave side
sha256sum -c pal-1.0.0-images.tar.gz.sha256
gunzip -c pal-1.0.0-images.tar.gz | docker load
# either run it locally (PAL_IMAGE=pal:1.0.0) or push to the internal registry:
docker tag pal:1.0.0 registry.example.internal/pal:1.0.0
docker push registry.example.internal/pal:1.0.0
docker tag postgres:17-alpine registry.example.internal/postgres:17-alpine
docker push registry.example.internal/postgres:17-alpine
```

`deploy/compose.yaml` references `postgres:17-alpine` by its Docker Hub name. If the host cannot resolve Docker Hub, either keep the loaded image under that exact tag (`docker load` does this) or change the image in a host-local Compose override to the internal registry name. Compose also has a `build:` section for PAL; do not run `docker compose build` in the enclave; set `PAL_IMAGE` and use `docker compose up --no-build`.

Scan the archive on the transfer station before loading (§8.4).

### 6.2 Building inside the enclave (only if policy requires)

The Dockerfile downloads at build time:

| Stage | Downloads | Offline option |
|---|---|---|
| Base images | `php:8.4-fpm-alpine`, `node:24-alpine`, `composer:2` | Override with build args `PHP_IMAGE`, `NODE_IMAGE`, `COMPOSER_IMAGE` (declared in the Dockerfile) pointing at the internal registry |
| `runtime` | Alpine packages via `apk add` (`postgresql-dev`, `icu-dev`, build deps, `nginx`, `supervisor`, `tini`, …) | No build arg for the Alpine repository. Needs an internal Alpine mirror reachable as the default repositories (DNS/proxy), or a Dockerfile change |
| `frontend` | `npm ci` from the public npm registry (versions and integrity hashes pinned by `frontend/package-lock.json`) | Internal npm mirror (Verdaccio/Nexus/Artifactory) via the `HTTP_PROXY`/`HTTPS_PROXY` predefined build args, or a Dockerfile change to set the registry |
| `backend` | `composer install` from Packagist (pinned by `backend/composer.lock`) | Internal Composer repository (Satis/Nexus/Artifactory) or a proxying mirror; a Dockerfile change is needed to point Composer at it |

```sh
docker build \
  --build-arg PHP_IMAGE=registry.example.internal/php:8.4-fpm-alpine \
  --build-arg NODE_IMAGE=registry.example.internal/node:24-alpine \
  --build-arg COMPOSER_IMAGE=registry.example.internal/composer:2 \
  --build-arg HTTPS_PROXY=http://mirror-proxy.example.internal:3128 \
  -t pal:1.0.0 .
```

Building the frontend and backend **outside Docker** (e.g. for development in the enclave):

```sh
# Connected side: populate caches from the lock files
cd pal/frontend && npm ci --cache ./.npm-offline-cache
cd ../backend && composer install --no-dev --prefer-dist   # vendor/ is the artifact
# Transfer frontend/.npm-offline-cache and backend/vendor (or a Composer mirror) with the source

# Enclave side
cd pal/frontend && npm ci --offline --cache ./.npm-offline-cache && npm run build
```

`composer audit` and `npm audit` need the public advisory services and do not work offline; run them on the connected side before transfer and keep the reports with the release.

### 6.3 Network allow-list (Situation A)

| From | To | Port | Why |
|---|---|---|---|
| Users' browsers | `pal.example.internal` | 443 | PAL |
| Users' browsers | Entra authority host (+ any MFA/Conditional Access endpoints your tenant requires) | 443 | Interactive sign-in |
| `web`, `worker` (via proxy) | Entra authority host | 443 | Discovery, JWKS, token endpoint |
| `web`, `worker` (via proxy) | Graph host | 443 | Read-only group sync, sign-in membership refresh |
| `web`, `worker`, `migrate` | PostgreSQL | 5432 | State |
| PAL host | internal registry, NTP, DNS, log collector | as configured | Operations |

No other egress is needed by PAL at runtime.

## 7. Verification

As in [SINGLE_LINUX_SERVER.md §7](SINGLE_LINUX_SERVER.md#7-verification), plus:

| # | Check | Command | Expected |
|---|---|---|---|
| 1 | Image provenance | `docker image inspect --format '{{index .RepoDigests 0}}' registry.example.internal/pal:1.0.0` | Digest matches the release record from the build host |
| 2 | No unexpected egress | Review the proxy/firewall log after a sign-in and a sync | Only the authority and Graph hosts |
| 3 | Liveness / readiness | `curl -fsS http://127.0.0.1:8080/health/live`; `curl -sS https://pal.example.internal/health/ready` | `ok`; `ready` |
| 4 | Secrets resolved | `docker compose -f deploy/compose.yaml exec web sh -c 'test -r "$OIDC_CLIENT_CERT_PATH" && test -r "$OIDC_CLIENT_KEY_PATH" && echo readable'` | `readable` |
| 5 | Sign-in (Situation A) | Browse PAL and sign in | Signed in; not on `/sign-in?auth_error=…` |
| 6 | Directory sync (Situation A) | `docker compose -f deploy/compose.yaml exec worker php /srv/pal/backend/bin/console pal:directory:sync` | exit 0 |
| 7 | Audit chain | `... exec worker php /srv/pal/backend/bin/console pal:audit:verify` | `Audit chain valid: …` |
| 8 | Time | `chronyc tracking` (or equivalent) on the host | Synchronised to the internal source |
| 9 | Upload accepted + scanned, object written | — | **Not applicable until Phase 2 (document storage)** |

## 8. Day-2 operations

### 8.1 Upgrades

1. Connected side: build the new tag at a reviewed commit, run CI (tests, audits, Trivy), `docker save`, checksum, sign.
2. Transfer, verify, scan, `docker load` / push to the internal registry.
3. Copy the matching `deploy/` folder; diff `compose.yaml`, `pal.env.example` and `sql/roles.sql` against the installed versions and apply changes to `deploy/pal.env` by hand.
4. Back up the database, then follow [SINGLE_LINUX_SERVER.md §8.1](SINGLE_LINUX_SERVER.md#81-upgrades): `run --rm migrate`, re-run `roles.sql`, `up -d --no-build web worker`.

### 8.2 Backups

As in the single-server guide; backup storage stays inside the enclave. Keep an offline copy of `deploy/pal.env`, `deploy/secrets/`, and the audit anchor directory under the enclave's key-management procedures.

### 8.3 Logs

Container stdout/stderr (JSON). Forward to the SIEM that serves the enclave: Microsoft Sentinel through the approved forwarder in Situation A (Azure Monitor Agent on an Arc-enabled host requires its own allow-listed endpoints; an existing syslog/CEF forwarder is often simpler), or the enclave's own syslog/SIEM in Situation B. Filter `"channel":"pal_audit"` for the security stream. Configure Docker log rotation.

### 8.4 Offline CVE feeds and scanning

- Scan images on the connected side in CI (Trivy is part of `.github/workflows/pal-ci.yml`) and again on the transfer station.
- To scan inside the enclave, transfer the scanner's vulnerability database regularly (for Trivy: download the DB on the connected side, copy the cache directory, and run with `--skip-db-update` / `--offline-scan`; verify flags for your Trivy version).
- Rebuild and re-transfer the image when base-image or dependency fixes are published; the enclave cannot patch packages in place.

### 8.5 Secret and certificate rotation

As in [SINGLE_LINUX_SERVER.md §8.5](SINGLE_LINUX_SERVER.md#85-rotating-secrets-and-certificates). New keys are generated inside the enclave; only the public certificate is handed to the Entra administrator. Track the certificate expiry: an expired certificate stops sign-in and directory sync together.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `docker compose up` tries to pull or build | `PAL_IMAGE` not set, or image not loaded | Set `PAL_IMAGE`; use `up --no-build`; check `docker images` |
| `pull access denied` for `postgres:17-alpine` | Image not loaded under that name and Docker Hub unreachable | `docker load` the archive, or override the image name to the internal registry |
| `x509: certificate signed by unknown authority` from the registry | Docker does not trust the internal CA | Install the CA under `/etc/docker/certs.d/registry.example.internal/ca.crt` |
| `/sign-in?auth_error=sign_in_unavailable`, log reason `provider_unavailable` | Backend cannot reach the authority (no proxy, proxy blocks, DNS) | Situation A: set `HTTPS_PROXY`/`NO_PROXY` on web and worker, allow the host. Situation B: see §4.1 (not supported) |
| Sign-in page of Entra never loads in the browser | Browsers cannot reach the authority host | Allow it for users (Situation A) |
| `/sign-in?auth_error=sign_in_failed` | Clock skew (no NTP), wrong credential, redirect URI mismatch | Fix internal NTP; check the app registration |
| Directory sync `Graph request failed` | Graph host not allow-listed | Allow it on the proxy |
| Container exits with code **64** | Missing required variable | See the log line naming it |
| `/health/ready` **503** `"migrations":"fail"` | Migrations not applied after loading a new image | `docker compose -f deploy/compose.yaml run --rm migrate` |
| All requests **400** | `Host` not matching `TRUSTED_HOSTS` | Fix the regex / proxy `Host` header |
| Rate limits hit by everyone, or `client_ip` values users chose | `TRUSTED_PROXIES` not the proxy's exact address, or the proxy appends `X-Forwarded-For` | Exact proxy IP in `TRUSTED_PROXIES`; `proxy_set_header X-Forwarded-For $remote_addr` |
| HTTP **403** `self_grant_forbidden` on role mapping | Admin belongs to the group being mapped to `PAL_ADMINISTRATOR`/`PUBLISHER`/`AUTHOR` | Another administrator creates the mapping |
| Branding logo from an internal `http://` server rejected | Only `https://` or raster `data:` URLs are accepted | Serve it over HTTPS (internal CA) or upload the image (stored as a `data:` URL) |
| `npm ci --offline` fails `ENOTCACHED` | Cache built from a different lock file | Rebuild the cache on the connected side from the same commit |

---

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md), [KUBERNETES.md](KUBERNETES.md), [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md).
