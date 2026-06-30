# CITADEL — Environment Variable Reference

All variables are optional unless noted. The backend is **secure by default**:
with nothing set it runs in a single-admin, in-memory, network-restricted mode.
Never commit real secrets — use `.env.example` with placeholders.

## Core

| Variable | Default | Purpose |
|---|---|---|
| `PORT` | `8080` | HTTP listen port |
| `NODE_ENV` | — | `production` enables prod hardening + secure defaults |
| `CITADEL_APP_DIR` | bundled SPA | Path to the static SPA to serve |
| `CITADEL_TMP` | OS tmp + `/citadel` | Scratch dir for uploads/extraction (use a non-persistent tmpfs) |
| `CITADEL_DATA_DIR` | — | Directory for the file-backed store (no-DB mode) |
| `LOG_LEVEL` | `info` | `debug` \| `info` \| `warn` \| `error` |
| `CITADEL_SERVICE_NAME` | `citadel` | Service name stamped in JSON logs |

## Authentication & sessions

| Variable | Default | Purpose |
|---|---|---|
| `CITADEL_JWT_SECRET` | random (in-memory) | HS256 signing secret; set to persist sessions across restarts |
| `CITADEL_ADMIN_EMAIL` / `CITADEL_ADMIN_PASSWORD` | seeded | Initial admin; a random password is generated in prod if unset |
| `CITADEL_ACCESS_TTL` / `CITADEL_REFRESH_TTL` / `CITADEL_MFA_TTL` | sensible defaults | Token lifetimes (access is short-lived) |
| `CITADEL_ALLOW_OPEN` | — | `1` acknowledges running with `enforce` off in production (silences the warning) |
| `CITADEL_PBKDF` / `CITADEL_FIPS` | scrypt / off | Password-hash KDF selection; FIPS mode forces PBKDF2-HMAC-SHA256 |
| `CITADEL_DATA_KEY` | — | 32-byte key (hex) to AES-256-GCM-seal secrets at rest (JWT secret, TOTP seeds) |
| `TRUST_PROXY_HOPS` | `0` | Number of trusted proxy hops for client-IP extraction (no XFF spoofing) |

## OIDC / SSO

| Variable | Purpose |
|---|---|
| `OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_REDIRECT_URI` | Auth-Code + PKCE login |
| `OIDC_SCOPES`, `OIDC_POST_LOGIN` | Requested scopes; post-login redirect |
| `OIDC_ADMIN_EMAILS` | Emails mapped to the `admin` role |
| `OIDC_DEFAULT_ROLE` | Role for everyone else (default `viewer`) |
| `OIDC_ALLOWED_DOMAINS` | Restrict sign-in to these email domains |

## Database & cache

| Variable | Default | Purpose |
|---|---|---|
| `DATABASE_URL` | — | Postgres connection string; enables shared persistence (else in-memory/file) |
| `PG_POOL_MAX` | driver default | Max Postgres pool connections |
| `PGSSL` / `PGSSL_VERIFY` / `PGSSL_CA` | managed-host aware | TLS for Postgres; set `PGSSL_VERIFY=1` / `PGSSL_CA` to fully verify |
| `REDIS_URL` | — | Shared rate-limit/lockout store across instances |
| `REDIS_TLS_VERIFY` / `REDIS_TLS_CA` | — | TLS verification for Redis |

## Scanning, uploads & limits

| Variable | Default | Purpose |
|---|---|---|
| `MAX_UPLOAD_BYTES` | 150 MB | Max single upload |
| `CITADEL_MAX_UNZIP_BYTES` | 500 MB | Max decompressed archive bytes (bomb cap) |
| `CITADEL_MAX_UNZIP_ENTRIES` | 50000 | Max archive entries (bomb cap) |
| `CITADEL_MAX_TOTAL_BYTES` | 64 MB | Per-scan in-memory content budget |
| `SCAN_CONCURRENCY` | `2` | How many external scanners run at once (memory cap; raise on big hosts) |
| `SCAN_TIMEOUT_MS` | 180000 | Per-external-scanner timeout |
| `CITADEL_SCAN_TIMEOUT_MS` | 30000 | Heuristic SAST pass deadline (ReDoS guard) |
| `CITADEL_SCAN_ISOLATION` / `CITADEL_ISOLATION_MIN_MEM_MB` | auto / 900 | Worker-thread isolation policy (avoids OOM→502 on small instances) |
| `CITADEL_ENABLE_CODEQL` | off | `1` enables the CodeQL adapter (heavy) |
| `CITADEL_SCAN_HISTORY_MAX` | — | Cap on retained scan history |
| `CITADEL_AIRGAP` / `CITADEL_NO_EGRESS` | — | Disable outbound network enrichments (offline/air-gapped) |

## AI remediation

| Variable | Purpose |
|---|---|
| `ANTHROPIC_API_KEY` | Enables the opt-in `/api/explain` AI remediation |
| `CITADEL_AI_MODEL` | Model id for AI remediation |

## Observability & notifications

| Variable | Purpose |
|---|---|
| `CITADEL_METRICS_TOKEN` | Bearer token guarding the Prometheus `/metrics` endpoint |
| `CITADEL_TRACING` / `OTEL_EXPORTER_OTLP_ENDPOINT` / `OTEL_SERVICE_NAME` | OpenTelemetry tracing |
| `CITADEL_AUDIT_SINK_URL` / `CITADEL_AUDIT_SINK_TOKEN` | Forward audit events to an external SIEM |
| `CITADEL_AUDIT_CAP` | In-memory audit ring size |
| `CITADEL_NOTIFY_URL` / `CITADEL_NOTIFY_TOKEN` / `CITADEL_NOTIFY_ON` | Post a Slack-compatible scan summary when severity ≥ threshold |

## Multi-tenancy

| Variable | Purpose |
|---|---|
| `CITADEL_MULTITENANT` | Enable schema-per-tenant isolation |
| `CITADEL_BASE_DOMAIN` | Base domain for tenant subdomains |
| `CITADEL_SUPERADMIN_TOKEN` | Token for tenant-provisioning routes |

> Defaults shown are the code defaults at the time of writing; confirm against
> `server/server.js` and `server/lib/*.js` for your version.
