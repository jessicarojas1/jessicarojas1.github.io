# Configuration Reference

Complete reference for every Sentinel QMS environment variable: meaning, default, and sensitivity. The
backend reads configuration via **pydantic-settings** (`app/core/config.py`); values come from environment
variables or a `.env` file (local only). In cloud deployments, sensitive values are injected from **AWS
Secrets Manager** / **Azure Key Vault** — never from committed files.

> **Sensitivity key:** **Secret** = must come from a secrets manager, never committed/logged ·
> **Config** = non-sensitive operational setting · **Sensitive-ish** = environment-specific, avoid leaking.

---

## 1. Backend (API) Variables

### Runtime

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `ENVIRONMENT` | `development` | Config | `development` \| `production`. Drives stricter prod behavior (docs disabled, verbose errors off). |
| `LOG_LEVEL` | `INFO` | Config | `DEBUG` \| `INFO` \| `WARNING` \| `ERROR`. |
| `APP_VERSION` | `1.0.0` | Config | Reported app version. |
| `PROJECT_NAME` | `Sentinel QMS API` | Config | Service name in OpenAPI/logs. |
| `API_V1_PREFIX` | `/api/v1` | Config | Base path for the REST API. |

### CORS / API

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `CORS_ORIGINS` | `http://localhost:3000` | Config | Comma-separated allowlist of SPA origins. Set to the production SPA origin(s); deny-by-default otherwise. |

### Database

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `DATABASE_URL` | `postgresql+psycopg://sentinel:change_me@localhost:5432/sentinel_qms` | **Secret** | PostgreSQL DSN. In prod use `sslmode=verify-full`. Source from Secrets Manager / Key Vault. |

### JWT / Auth

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `JWT_SECRET` | dev placeholder | **Secret** | Signing secret (≥32 chars). In prod prefer an asymmetric KMS/Key Vault key with RS256/ES256. |
| `JWT_ALGORITHM` | `HS256` | Config | Token algorithm. Use `RS256`/`ES256` with a managed key in prod. |
| `ACCESS_TOKEN_EXPIRE_MINUTES` | `30` | Config | Access-token lifetime. |
| `REFRESH_TOKEN_EXPIRE_DAYS` | `7` | Config | Refresh-token lifetime. |

### Rate limiting

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `RATE_LIMIT_ENABLED` | `true` | Config | Enable the in-process per-caller API rate limiter. Set `false` if a fronting gateway/WAF already limits. |
| `RATE_LIMIT_PER_MINUTE` | `300` | Config | Requests allowed per caller (credential or IP) per window. |
| `RATE_LIMIT_WINDOW_SECONDS` | `60` | Config | Fixed-window length in seconds. |
| `TRUST_PROXY_HEADERS` | `false` | Config | Honor `X-Forwarded-For` for the client IP (rate limiting). Enable **only** behind a trusted proxy/LB (Render/ALB/Nginx); leaving it off prevents IP spoofing on direct exposure. |
| `WEBHOOKS_ENABLED` | `true` | Config | Emit HMAC-signed lifecycle events to registered webhook endpoints. Enqueue is atomic with the change; delivery is backgrounded with retries. |
| `PASSWORD_RESET_TTL_MINUTES` | `60` | Config | Lifetime of a self-service password-reset link. |

### Federated SSO (OIDC / SAML / CAC-PIV)

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `OIDC_ISSUER` | `""` | Sensitive-ish | IdP issuer URL. Empty = federation disabled (fails closed). |
| `OIDC_CLIENT_ID` | `""` | Sensitive-ish | OIDC client identifier (also the expected token audience). |
| `OIDC_CLIENT_SECRET` | `""` | **Secret** | OIDC client secret. From secrets manager. |
| `OIDC_JWKS_URI` | `""` | Config | Explicit JWKS URL; discovered from the issuer when blank. |
| `OIDC_AUTO_PROVISION` | `true` | Config | Create a local account on first successful SSO login. |
| `OIDC_ALLOWED_DOMAINS` | `""` | Config | Comma-separated email-domain allowlist for SSO (empty = any). |
| `OIDC_GROUP_CLAIM` | `groups` | Config | ID-token claim carrying the user's groups. |
| `OIDC_GROUP_ROLE_MAP` | `{}` | Config | JSON object mapping IdP group → local role, e.g. `{"qms-admins":"Admin"}`. |
| `OIDC_DEFAULT_ROLE` | `Read-Only` | Config | Role assigned when no group maps to a role. |
| `OIDC_SCOPES` | `openid email profile` | Config | Space-separated scopes requested in the browser auth-code flow. |
| `SAML_IDP_ENTITY_ID` | `""` | Config | Expected IdP issuer (entityID); checked against the assertion when set. |
| `SAML_IDP_SSO_URL` | `""` | Config | IdP SingleSignOnService URL (HTTP-Redirect). Enables SAML when set with the cert + SP entity. |
| `SAML_IDP_CERT` | `""` | Sensitive-ish | PEM X.509 cert the IdP signs assertions with. |
| `SAML_SP_ENTITY_ID` | `""` | Config | This service's SAML entityID (assertion audience). |
| `SAML_SP_ACS_URL` | `""` | Config | Assertion Consumer Service URL; derived from `APP_BASE_URL` when blank. |
| `SAML_EMAIL_ATTRIBUTE` | `""` | Config | Assertion attribute holding email; blank → use the Subject NameID. |
| `SAML_NAME_ATTRIBUTE` | `displayName` | Config | Assertion attribute holding the display name. |
| `SAML_GROUP_ATTRIBUTE` | `groups` | Config | Assertion attribute holding groups (mapped via `OIDC_GROUP_ROLE_MAP`). |

### Bootstrap Admin (seed)

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `ADMIN_EMAIL` | `admin@sentinel-qms.local` | Sensitive-ish | Initial admin login. |
| `ADMIN_PASSWORD` | `ChangeMe!Admin123` | **Secret** | Initial admin password. Rotate immediately after first login. |
| `ADMIN_AUTO_CREATE` | `true` | Config | Auto-create the bootstrap admin. **Set `false` in production after initial setup.** |

### Storage

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `STORAGE_BACKEND` | `local` | Config | `s3` \| `azure_blob` \| `local`. Use `s3` in GovCloud, `azure_blob` in Azure Gov. |
| `LOCAL_STORAGE_DIR` | `./var/uploads` | Config | Local upload dir (dev only). |
| `S3_BUCKET` | `""` | Config | S3 bucket name (GovCloud). |
| `S3_REGION` | `us-gov-west-1` | Config | S3/AWS region. Keep in GovCloud for residency. |
| `S3_ENDPOINT_URL` | (unset) | Config | Override endpoint (used by MinIO locally). |
| `AZURE_STORAGE_CONNECTION_STRING` | `""` | **Secret** | Azure Blob connection string. From Key Vault. |
| `AZURE_STORAGE_CONTAINER` | `sentinel-qms` | Config | Azure Blob container name. |

### Uploads

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `MAX_UPLOAD_BYTES` | `52428800` (50 MiB) | Config | Maximum attachment size. Enforced server-side alongside MIME/extension allowlist. |

### Cloud credentials (storage SDK)

| Variable | Default | Sensitivity | Meaning |
|----------|---------|-------------|---------|
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | (unset) | **Secret** | Only for local MinIO. In cloud, use **IRSA** (no static keys). |
| `AWS_EC2_METADATA_DISABLED` | (unset) | Config | Set `true` locally to skip IMDS lookups. |

---

## 2. Local docker-compose Variables (`infra/.env`)

These configure the **local** stack only and must never be reused in any cloud environment.

| Variable | Default | Meaning |
|----------|---------|---------|
| `POSTGRES_USER` | `sentinel` | Local DB user. |
| `POSTGRES_PASSWORD` | `change_me_local_only` | Local DB password (**Secret**, local only). |
| `POSTGRES_DB` | `sentinel_qms` | Local database name. |
| `MINIO_ROOT_USER` | `minioadmin` | Local MinIO access key. |
| `MINIO_ROOT_PASSWORD` | `minioadmin_local_only` | Local MinIO secret key. |
| `VITE_API_BASE_URL` | `http://localhost:8000` | Frontend build-time API base URL. |

---

## 3. Frontend (build-time)

| Variable | Default | Meaning |
|----------|---------|---------|
| `VITE_API_BASE_URL` | `http://localhost:8000` | API base URL baked into the SPA at build time. Set to the production API origin. |

---

## 4. Production Configuration Guidance

| Topic | Recommendation |
|-------|----------------|
| Secrets source | Secrets Manager / Key Vault via External Secrets / CSI driver — never `.env` |
| `ENVIRONMENT` | `production` |
| `JWT_ALGORITHM` | `RS256`/`ES256` with a KMS/Key Vault-held key |
| `ADMIN_AUTO_CREATE` | `false` after bootstrap |
| `CORS_ORIGINS` | Exact production SPA origin(s) only |
| `STORAGE_BACKEND` | `s3` (GovCloud) / `azure_blob` (Azure Gov), region/in-region |
| `DATABASE_URL` | Include `sslmode=verify-full` |
| Cloud creds | Workload identity (IRSA / Azure Workload Identity), no static keys |

---

## 5. Security Notes

- **Never commit a populated `.env`.** Only `.env.example` with placeholders is committed (enforced by
  project rules and CI secret scanning).
- Secrets are **never logged**; structured logs redact tokens and credentials.
- Changing `JWT_SECRET`/signing key invalidates outstanding tokens (effective global logout) — schedule
  accordingly.
- Validate that `S3_REGION` / Azure region remain inside the GovCloud / Azure Gov boundary for data
  residency (ITAR/CUI).
