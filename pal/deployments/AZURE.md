# PAL on Microsoft Azure (Commercial and Azure Government)

Reference for hosting PAL in Azure. **Azure hosting has not been selected** (the production target is on-prem Linux, [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md)), and PAL is deliberately not built on SharePoint/M365. Use this guide only if that decision changes. Entra ID is used for sign-in and read-only group sync regardless of where PAL runs (decision D18).

Two shapes are described:

- **Azure Container Apps (ACA)** + Azure Database for PostgreSQL Flexible Server + Key Vault + Azure Container Registry. Detailed below.
- **AKS**: use [KUBERNETES.md](KUBERNETES.md) with Microsoft Entra Workload ID for the secret operator (External Secrets Operator or the Key Vault Secrets Store CSI driver). Azure-specific notes are in §4 and §6.

Scope: PAL **Phase 1 foundation**. All state is in PostgreSQL; no document storage, malware scanner or preview converter yet (Phase 2+). PAL makes no LLM calls and calls no Azure resource APIs itself.

Nothing in this guide has been deployed or tested in Azure. Service-specific behaviour marked *verify* must be confirmed in your subscription.

---

## 1. Deployment architecture

| Component | Azure service | Notes |
|---|---|---|
| `pal-web` | Container App, args `["web"]`, ingress target port 8080 | 1+ replicas; stateless (sessions in PostgreSQL) |
| `pal-worker` | Container App, args `["worker"]`, **no ingress** | min = max = **1** replica (scheduler; the PostgreSQL advisory lock makes extras safe but wasteful). Exits hourly by design (`PAL_WORKER_TIME_LIMIT`); ACA restarts it |
| `pal-migrate` | Container Apps **Job**, trigger Manual, args `["migrate"]` | Run before each rollout |
| Database | Azure Database for PostgreSQL Flexible Server, PostgreSQL 17 (16+ supported) | Private access (VNet integration); TLS enforced |
| Secrets | Key Vault (RBAC permission model) | Referenced from ACA secrets via a user-assigned managed identity |
| Images | Azure Container Registry | Pulled with the same managed identity |
| Logs | Log Analytics workspace attached to the ACA environment | Microsoft Sentinel enabled on that workspace |
| Identity provider | Microsoft Entra ID (commercial or Government cloud) | OIDC + Microsoft Graph (read-only) |

## 2. Topology

```
                     Users
                       | HTTPS
        +--------------v---------------+
        | ACA environment ingress      |  TLS (managed or custom cert), Host preserved
        | (VNet-integrated)            |
        +--------------+---------------+
                       | :8080
   +-------------------v--+   +------------------+   +--------------------+
   | Container App        |   | Container App    |   | Container Apps Job |
   | pal-web (1..n)       |   | pal-worker (1)   |   | pal-migrate        |
   +----------+-----------+   +--------+---------+   +---------+----------+
              |  user-assigned managed identity "id-pal"        |
              |    -> Key Vault (secrets)  -> ACR (AcrPull)     |
              v                          v                      v
   +----------------------------------------------------------------------+
   | Azure Database for PostgreSQL Flexible Server (private endpoint/VNet) |
   +----------------------------------------------------------------------+
   web + worker egress HTTPS 443 -> Entra ID + Microsoft Graph
   stdout/stderr -> Log Analytics workspace -> Microsoft Sentinel
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Subscription | Commercial or Azure Government; permission to create the resources above and role assignments |
| Region | ACA, PostgreSQL Flexible Server and Key Vault available in the region (**verify ACA availability in your Azure Government region**) |
| Network | VNet with a subnet for the ACA environment and a delegated subnet / private endpoint for PostgreSQL; egress to Entra and Graph (NAT gateway or firewall rules for the authority and Graph hosts) |
| Tools | Azure CLI with the `containerapp` extension; `psql` on a host with VNet access (to run `roles.sql`) |
| Image | `pal` image built from `pal/Dockerfile` and pushed to ACR (e.g. `palacr.azurecr.io/pal:1.0.0`) |
| Entra ID | App registration as in [SINGLE_LINUX_SERVER.md §4.2](SINGLE_LINUX_SERVER.md#42-entra-id-app-registration-shared-by-all-deployment-guides), including the **mandatory** enterprise-application setting *Assignment required = Yes* with only the PAL users group assigned. Every account that can sign in becomes a Reader, B2B guests included |
| DNS / TLS | Custom domain (e.g. `pal.example.internal`) and certificate bound to the ACA environment |

## 4. Identity and credentials

### 4.1 Managed identity (what it covers)

Create one **user-assigned managed identity** `id-pal`, attach it to `pal-web`, `pal-worker` and `pal-migrate`, and grant only:

| Scope | Role | Why |
|---|---|---|
| Container registry | `AcrPull` | Pull the image |
| Key Vault (or individual secrets) | `Key Vault Secrets User` | Resolve ACA Key Vault secret references |

PAL's own code does not use the managed identity. On AKS, grant the same Key Vault role to the secret operator's workload identity, not to PAL pods.

### 4.2 What still needs a credential (verified in code)

| Credential | Why managed identity cannot replace it | Storage |
|---|---|---|
| Entra app certificate + key | PAL authenticates to Entra with a client secret **or** a certificate file pair (`private_key_jwt`). It does not support managed identity / workload identity federation as the app credential | Key Vault **certificate** created with content type `application/x-pem-file`; referenced as an ACA secret and mounted as a file |
| `pal_app` / `pal_owner` passwords | PAL connects with a static password in `DATABASE_URL`; Entra authentication for PostgreSQL (token passwords) is not supported | Key Vault secrets holding the full `DATABASE_URL` / `DATABASE_URL_MIGRATIONS` |
| `APP_SECRET` | Application secret | Key Vault secret |

A Key Vault PEM certificate referenced as a secret yields one PEM file containing both the private key and the certificate. PHP's OpenSSL functions read the matching block from a combined PEM, so `OIDC_CLIENT_CERT_PATH` and `OIDC_CLIENT_KEY_PATH` can point to the **same file**. This is expected to work but has not been tested; verify sign-in in a non-production environment. The key must not be password-protected.

### 4.3 Database roles

Flexible Server's administrator is not a superuser. From a host with VNet access:

```sh
export ADMIN_URL="postgresql://<admin>@pal-pg.postgres.database.azure.com:5432/pal?sslmode=require"
psql "$ADMIN_URL" -c 'CREATE ROLE pal_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT' \
                  -c 'GRANT pal_owner TO CURRENT_USER'      # so the admin can transfer ownership (verify for your PG version)
psql "$ADMIN_URL" -v ON_ERROR_STOP=1 -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal \
     -f deploy/sql/roles.sql
psql "$ADMIN_URL" -c '\password pal_owner' -c '\password pal_app'
```

(`roles.sql` skips creating a role that already exists.) Re-run `roles.sql` after every migration.

## 5. Environment variables

Configure on the Container Apps (secrets as `secretref:`). Full descriptions: [SINGLE_LINUX_SERVER.md §5](SINGLE_LINUX_SERVER.md#5-environment-variables).

### 5.1 Values that differ between Azure Commercial and Azure Government

| Variable / setting | Azure Commercial | Azure Government (verify for your cloud) | Purpose |
|---|---|---|---|
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | `https://login.microsoftonline.us` | Entra authority. Follows the **tenant's** cloud: a GCC (moderate) tenant uses the commercial value even when PAL runs in Azure Government |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | `https://graph.microsoft.us/v1.0` (GCC High), `https://dod-graph.microsoft.us/v1.0` (DoD) | Graph; token scope = origin + `/.default` |
| `DATABASE_URL` host | `pal-pg.postgres.database.azure.com` | `pal-pg.postgres.database.usgovcloudapi.net` | Flexible Server FQDN |
| Key Vault reference | `https://pal-kv.vault.azure.net/secrets/<name>` | `https://pal-kv.vault.usgovcloudapi.net/secrets/<name>` | ACA secret `keyvaultref:` |
| Registry | `palacr.azurecr.io` | `palacr.azurecr.us` | Image location |
| CLI cloud | `az cloud set --name AzureCloud` | `az cloud set --name AzureUSGovernment` | Tooling |

### 5.2 All PAL variables

| Variable | Example | Purpose | Apps |
|---|---|---|---|
| `APP_SECRET` | `secretref:app-secret` | Kernel secret; required by web, worker, migrate (entrypoint exits 64 if missing) | all |
| `DATABASE_URL` | `secretref:database-url` → `postgresql://pal_app:<pw>@<host>:5432/pal?serverVersion=17&charset=utf8` | Runtime connection (app role) | all |
| `DATABASE_URL_MIGRATIONS` | `secretref:database-url-migrations` (owner role) | Owner connection | migrate job only |
| `PAL_APP_DB_ROLE` | `pal_app` | Revoke `audit_event` writes after migrating | migrate job |
| `PGSSLMODE` | `verify-full` (or `require`) | libpq TLS mode, applied to both PAL DB connections (DBAL + session handler). Not a PAL variable | all |
| `PGSSLROOTCERT` | `/run/secrets/pal/db-ca` | CA bundle for `verify-full` (mount from a secret). Not a PAL variable | all |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Queue in PostgreSQL | web, worker |
| `PAL_BASE_URL` | `https://pal.example.internal` | Public origin; redirect URI = + `/auth/callback` (required by web) | web, worker |
| `DEFAULT_URI` | `https://pal.example.internal` | Router default URI for CLI/worker | all |
| `TRUSTED_HOSTS` | `^pal\.example\.internal$` | Accepted Host regex (required by web); add the ACA default FQDN only if you use it | web |
| `TRUSTED_PROXIES` | `10.10.0.0/23` | The ACA environment's infrastructure subnet CIDR (where the ingress proxies run), not `private_ranges`. Only these may set `X-Forwarded-*`. See §6 on `X-Forwarded-For` handling | web |
| `OIDC_AUTHORITY` | see §5.1 | Entra authority | web, worker |
| `OIDC_TENANT_ID` | `<tenant-guid>` | Tenant | web, worker |
| `OIDC_CLIENT_ID` | `<client-guid>` | App registration | web, worker |
| `OIDC_CLIENT_CERT_PATH` | `/run/secrets/pal/oidc-pem` | Certificate (combined PEM from Key Vault) | web, worker |
| `OIDC_CLIENT_KEY_PATH` | `/run/secrets/pal/oidc-pem` | Private key (same combined PEM; verify) | web, worker |
| `OIDC_CLIENT_SECRET` | *(unset)* | Only one credential may be configured | — |
| `GRAPH_BASE_URL` | see §5.1 | Graph | web, worker |
| `PAL_BOOTSTRAP_ADMIN_OIDS` | `<user-object-guid>` | Temporary System Administrators; remove after mapping | web |
| `PAL_DEFAULT_TIMEZONE` | `America/Denver` | Org timezone | web, worker |
| `PAL_SESSION_IDLE_SECONDS` / `PAL_SESSION_ABSOLUTE_SECONDS` | `3600` / `43200` | Session lifetimes | web |
| `PAL_AUDIT_ANCHOR_DIR` | `/mnt/pal-anchor` | Audit anchor directory (Azure Files mount on the worker) | worker |
| `LOG_LEVEL` | `info` | Log level | all |
| `PAL_WORKER_TIME_LIMIT` / `PAL_WORKER_MEMORY_LIMIT` | `3600` / `256M` | Worker recycling | worker |

Image defaults to keep: `APP_ENV=prod`, `APP_DEBUG=0`, `PAL_SESSION_COOKIE_NAME=__Host-pal_session`, `PAL_SESSION_COOKIE_SECURE=1`.

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Web ingress | external (or internal + Application Gateway/Front Door), target port `8080`, transport HTTP, insecure connections disabled | TLS at the ACA ingress |
| Web probes | liveness `GET /health/live`, readiness `GET /health/ready`, port 8080 | Readiness fails (503) on DB loss or unapplied migrations |
| Worker | no ingress, no HTTP probes, `minReplicas: 1`, `maxReplicas: 1` | Scheduler |
| Migrate job | `replicaTimeout: 600`, `replicaRetryLimit: 0`, trigger Manual | One-shot |
| Secret volume | ACA secrets mounted at `/run/secrets/pal` (select only `oidc-pem` and `db-ca`) | Certificate files; verify they are readable by uid 10001 |
| `X-Forwarded-For` | The ACA ingress (Envoy) is expected to append the connecting address (verify). With `TRUSTED_PROXIES` limited to the infrastructure subnet, Symfony takes the right-most untrusted address, so client-supplied entries to its left are ignored | Verify with the spoof test in §7. If you put Application Gateway/Front Door in front, add its addresses to `TRUSTED_PROXIES` deliberately and confirm that hop does not trust client-supplied values |
| Read-only root filesystem | Not configurable in ACA | The image runs non-root; Compose/Kubernetes hardening (`read_only`) is not reproducible here |
| PostgreSQL | `require_secure_transport=ON` (default), private access, HA zone-redundant if RTO requires | TLS + availability |
| Backups | Flexible Server automated backups with PITR (retention 7–35 days) | Meets the provisional RPO 15 min (decision D10, unconfirmed) via PITR; verify restore time against RTO 4 h |

Example (fragment) of the web app's `template` and secrets, for `az containerapp create --yaml`:

```yaml
properties:
  configuration:
    activeRevisionsMode: Single
    ingress:
      external: true
      targetPort: 8080
      transport: http
      allowInsecure: false
    registries:
      - server: palacr.azurecr.io
        identity: /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.ManagedIdentity/userAssignedIdentities/id-pal
    secrets:
      - name: app-secret
        keyVaultUrl: https://pal-kv.vault.azure.net/secrets/pal-app-secret
        identity: /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.ManagedIdentity/userAssignedIdentities/id-pal
      - name: database-url
        keyVaultUrl: https://pal-kv.vault.azure.net/secrets/pal-database-url
        identity: /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.ManagedIdentity/userAssignedIdentities/id-pal
      - name: oidc-pem
        keyVaultUrl: https://pal-kv.vault.azure.net/secrets/pal-oidc     # PEM certificate's secret
        identity: /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.ManagedIdentity/userAssignedIdentities/id-pal
  template:
    containers:
      - name: web
        image: palacr.azurecr.io/pal:1.0.0
        args: ["web"]
        env:
          - { name: APP_SECRET, secretRef: app-secret }
          - { name: DATABASE_URL, secretRef: database-url }
          - { name: PAL_BASE_URL, value: "https://pal.example.internal" }
          - { name: TRUSTED_HOSTS, value: '^pal\.example\.internal$' }
          - { name: TRUSTED_PROXIES, value: "10.10.0.0/23" }
          - { name: OIDC_CLIENT_CERT_PATH, value: /run/secrets/pal/oidc-pem }
          - { name: OIDC_CLIENT_KEY_PATH, value: /run/secrets/pal/oidc-pem }
          # ... remaining variables from §5.2
        probes:
          - { type: Liveness, httpGet: { path: /health/live, port: 8080 }, periodSeconds: 30 }
          - { type: Readiness, httpGet: { path: /health/ready, port: 8080 }, periodSeconds: 10 }
        volumeMounts:
          - { volumeName: pal-secrets, mountPath: /run/secrets/pal }
    scale: { minReplicas: 1, maxReplicas: 3 }
    volumes:
      - name: pal-secrets
        storageType: Secret
        secrets:
          - { secretRef: oidc-pem, path: oidc-pem }
```

Field names follow the ACA YAML schema as documented at the time of writing and have not been applied to a real environment; apply to a test environment first.

## 7. Verification

| # | Check | How | Expected |
|---|---|---|---|
| 1 | Migrations | `az containerapp job start -n pal-migrate -g <rg>`; check execution status and logs | Succeeded; log ends `audit trail revokes applied to role pal_app` |
| 2 | Liveness | `curl -fsS https://pal.example.internal/health/live` | `{"status":"ok"}` |
| 3 | Readiness | `curl -sS https://pal.example.internal/health/ready` | 200, `"database":"ok","migrations":"ok"` |
| 4 | Secrets resolved | `az containerapp exec -n pal-web -g <rg> --command "sh -c 'test -r \$OIDC_CLIENT_CERT_PATH && echo readable'"`; the app's revision shows no secret-reference errors | `readable` |
| 5 | DB TLS + roles | As admin: `SELECT a.usename, s.ssl FROM pg_stat_activity a JOIN pg_stat_ssl s USING (pid) WHERE a.datname='pal';` | only `pal_app` at runtime; `ssl = t` |
| 6 | Sign-in | Browse the site | Signed in, not on `/sign-in?auth_error=…`; an unassigned account is refused by Entra (AADSTS50105) |
| 7 | Directory sync | `az containerapp exec -n pal-worker -g <rg> --command "php /srv/pal/backend/bin/console pal:directory:sync"` (after a mapping exists, e.g. `pal:roles:map PAL_ADMINISTRATOR <group-object-id>`) | exits 0, `N members (+a / -r)` |
| 8 | Audit chain | `... --command "php /srv/pal/backend/bin/console pal:audit:verify"` | `Audit chain valid: …` |
| 9 | Client IP not spoofable | `curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.9' 'https://pal.example.internal/auth/callback?state=x&code=y'`; query the web app's console logs | Your real egress IP, not `203.0.113.9` and not an ingress address |
| 10 | Upload accepted + scanned, object written | — | **Not applicable until Phase 2 (document storage)** |

## 8. Day-2 operations

- **Upgrade:** push the new image → `az containerapp job update -n pal-migrate --image <new>` → `job start` and wait for success → re-run `roles.sql` (§4.3) → `az containerapp update -n pal-web --image <new>` and the same for `pal-worker`. New web revisions only receive traffic once readiness passes.
- **Backups / restore:** PITR restores to a **new** server; repoint the `database-url*` secrets, re-run `roles.sql` if needed, restart the apps, then run `pal:audit:verify` and compare with the latest anchor. Also back up Key Vault (soft delete + purge protection on) and the anchor share.
- **Audit anchoring:** mount an Azure Files share on `pal-worker` at `PAL_AUDIT_ANCHOR_DIR`, owned by a different team (separate storage account/RBAC); SMB mount options must make it writable for uid 10001 (verify). Azure Files has no write-once mode; for WORM, have that team copy the file into immutable Blob storage.
- **Logs / Sentinel:** console logs land in the environment's Log Analytics workspace; enable Sentinel on it and filter `channel == "pal_audit"` for the security stream. Alert on `Audit chain verification FAILED`. Do not use the legacy HTTP Data Collector API. Uptime: an availability test on `/health/ready`.
- **Secret rotation:** add a new version in Key Vault (references without a version pick up the latest after a refresh delay; verify the interval), then restart the revision (`az containerapp revision restart`) so environment variables are re-read. For the Entra certificate, create the new Key Vault certificate version, upload its public cert to the app registration alongside the old one, restart, verify sign-in and sync, then remove the old cert from Entra.
- **Failed jobs:** `php /srv/pal/backend/bin/console messenger:failed:show` via `az containerapp exec` on the worker.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Revision fails to start; log `pal: required environment variable X is not set`, exit **64** | Missing variable (web: `APP_SECRET`, `DATABASE_URL`, `PAL_BASE_URL`, `TRUSTED_HOSTS`; worker/job: `APP_SECRET`, `DATABASE_URL`) | Add it; check the secret reference resolved |
| Revision provisioning fails on secrets | Managed identity lacks `Key Vault Secrets User`, wrong vault URL, or Key Vault firewall blocks ACA | Fix the role assignment / network rules |
| `/health/ready` **503**, `"migrations":"fail"` | Migrate job not run | Start the job |
| 503 `"database":"fail"` | Private DNS/VNet, firewall, password, TLS (`PGSSLMODE`, CA path) | Check connectivity from the environment and the web log `Readiness check failed` |
| All requests **400** | Host not in `TRUSTED_HOSTS` (e.g. using the `*.azurecontainerapps.io` FQDN) | Use the custom domain or add the FQDN |
| `/sign-in?auth_error=sign_in_unavailable` | OIDC config (GUIDs, exactly one credential), PEM unreadable/not PEM (certificate created as PKCS#12), or egress to Entra blocked | Web log `Sign-in is unavailable` → `reason`; recreate the cert with content type PEM |
| `/sign-in?auth_error=sign_in_failed` | Redirect URI mismatch, cert not uploaded to the app, wrong cloud authority | Align app registration and §5.1 values |
| Everyone throttled (`too_many_attempts`, **429**); audit `client_ip` is an ingress address | `TRUSTED_PROXIES` does not include the infrastructure subnet | Set it to the subnet CIDR |
| `client_ip` shows values users chose | `TRUSTED_PROXIES` too broad (e.g. `private_ranges` with another proxy hop that trusts client headers) | Narrow it; re-run check 9 |
| Graph sync HTTP 403 | Application permissions/admin consent missing, or Graph URL for the wrong cloud | Grant consent; fix `GRAPH_BASE_URL` |
| HTTP **403** `self_grant_forbidden` on role mapping | Admin is in the group being mapped to `PAL_ADMINISTRATOR`/`PUBLISHER`/`AUTHOR` | Another administrator creates the mapping |
| Branding logo URL rejected | Only `https://` or raster `data:` URLs are accepted | Use `https://` |
| `roles.sql`: `must be able to SET ROLE "pal_owner"` | Admin not a member of `pal_owner` | `GRANT pal_owner TO <admin>` (§4.3) |

---

Related: [KUBERNETES.md](KUBERNETES.md) (AKS), [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md), [AWS.md](AWS.md), [AIRGAPPED.md](AIRGAPPED.md).
