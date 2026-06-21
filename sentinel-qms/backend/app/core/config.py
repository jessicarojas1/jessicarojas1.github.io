"""Application configuration loaded from environment variables."""

from __future__ import annotations

from functools import lru_cache
from typing import Literal

from pydantic import field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

_INSECURE_JWT_DEFAULT = "insecure-development-secret-change-me-please-32+chars"


class Settings(BaseSettings):
    """Strongly-typed settings sourced from env vars / .env file."""

    model_config = SettingsConfigDict(
        env_file=".env", env_file_encoding="utf-8", case_sensitive=False, extra="ignore"
    )

    # Runtime
    ENVIRONMENT: Literal["development", "production"] = "development"
    LOG_LEVEL: str = "INFO"
    APP_VERSION: str = "1.0.0"
    PROJECT_NAME: str = "Sentinel QMS API"
    API_V1_PREFIX: str = "/api/v1"

    # Single-service mode: serve the built React SPA from this API process
    # (e.g. one Render web service). STATIC_DIR holds the Vite build output.
    SERVE_FRONTEND: bool = False
    STATIC_DIR: str = "static"

    # CORS
    CORS_ORIGINS: list[str] = ["http://localhost:3000"]

    # Database
    DATABASE_URL: str = "postgresql+psycopg://sentinel:change_me@localhost:5432/sentinel_qms"
    # Dedicated PostgreSQL schema to isolate all Sentinel tables. Defaults to a
    # private schema because deployments commonly share a database with other
    # apps; set to "public" to use the default schema. Ignored on SQLite (tests).
    DB_SCHEMA: str = "sentinel_qms"

    # JWT
    JWT_SECRET: str = _INSECURE_JWT_DEFAULT
    JWT_ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 30
    REFRESH_TOKEN_EXPIRE_DAYS: int = 7

    # Login throttling: block once this many failures (by email or IP) land
    # within the rolling window, to blunt password brute-force.
    LOGIN_MAX_FAILURES: int = 10
    LOGIN_FAILURE_WINDOW_MINUTES: int = 15

    # Self-service password-reset token lifetime (minutes).
    PASSWORD_RESET_TTL_MINUTES: int = 60

    # Global API rate limiting (in-process fixed-window per client principal/IP).
    # Generous defaults so normal interactive use is never throttled; tightens
    # abusive/runaway programmatic traffic. Disable per-deployment if a fronting
    # gateway/WAF already enforces limits.
    RATE_LIMIT_ENABLED: bool = True
    RATE_LIMIT_PER_MINUTE: int = 300
    RATE_LIMIT_WINDOW_SECONDS: int = 60
    # Optional shared store so the rate limit is enforced ACROSS workers/replicas
    # (fixed-window INCR+EXPIRE). Empty = per-process in-memory limiter (the
    # historical behavior); set to e.g. redis://host:6379/0 in scaled deploys.
    REDIS_URL: str = ""
    # Trust the client IP from X-Forwarded-For (rate limiting / logging). Enable
    # ONLY when the app sits behind a trusted proxy/LB that sets the header;
    # otherwise a direct client could spoof it. Render/ALB/Nginx → set true.
    TRUST_PROXY_HEADERS: bool = False

    # Outbound webhooks: emit signed lifecycle events to registered endpoints.
    # Enqueue is atomic with the change; dispatch is backgrounded with retries.
    WEBHOOKS_ENABLED: bool = True

    # OIDC / federated SSO. When OIDC_ISSUER is set, /auth/oidc/exchange accepts
    # an IdP ID token, verifies it against the issuer's JWKS, and (optionally)
    # provisions the user just-in-time. Empty issuer = SSO disabled (fails closed).
    OIDC_ISSUER: str = ""
    OIDC_CLIENT_ID: str = ""
    OIDC_CLIENT_SECRET: str = ""
    # Explicit JWKS URI; if blank it is discovered from the issuer's
    # /.well-known/openid-configuration document.
    OIDC_JWKS_URI: str = ""
    # Create a local account on first successful SSO login.
    OIDC_AUTO_PROVISION: bool = True
    # Comma-separated email-domain allowlist for SSO (empty = allow any domain).
    OIDC_ALLOWED_DOMAINS: list[str] = []
    # ID-token claim carrying the user's groups (for role mapping).
    OIDC_GROUP_CLAIM: str = "groups"
    # JSON object mapping an IdP group name -> a local role name, e.g.
    # {"qms-admins": "Admin", "engineers": "Quality Engineer"}.
    OIDC_GROUP_ROLE_MAP: dict[str, str] = {}
    # Role granted to a provisioned user when no group maps to a role.
    OIDC_DEFAULT_ROLE: str = "Read-Only"
    # Space-separated OAuth scopes requested in the authorization-code flow.
    OIDC_SCOPES: str = "openid email profile"

    # SAML 2.0 SP-initiated SSO. Enabled when an IdP SSO URL + signing cert + SP
    # entity id are configured. Domain allowlist / group→role map / default role /
    # auto-provision are shared with the OIDC settings above (one federation policy).
    SAML_IDP_ENTITY_ID: str = ""
    SAML_IDP_SSO_URL: str = ""
    # PEM-encoded X.509 certificate the IdP signs assertions with.
    SAML_IDP_CERT: str = ""
    SAML_SP_ENTITY_ID: str = ""
    # Assertion Consumer Service URL; derived from APP_BASE_URL when blank.
    SAML_SP_ACS_URL: str = ""
    # SAML attribute names carrying the user's email / display name / groups.
    SAML_EMAIL_ATTRIBUTE: str = ""  # blank → fall back to the Subject NameID
    SAML_NAME_ATTRIBUTE: str = "displayName"
    SAML_GROUP_ATTRIBUTE: str = "groups"

    # CAC / PIV (mutual-TLS) sign-in. The app trusts a client certificate only
    # when it sits behind a reverse proxy that terminates mTLS and forwards the
    # cert — so this requires BOTH this flag and TRUST_PROXY_HEADERS. Header names
    # follow the common nginx ssl_client_* convention.
    CLIENT_CERT_PROXY_AUTH: bool = False
    CLIENT_CERT_VERIFY_HEADER: str = "X-SSL-Client-Verify"  # expect "SUCCESS"/"0"
    CLIENT_CERT_PEM_HEADER: str = "X-SSL-Client-Cert"  # URL-encoded PEM

    # Bootstrap admin — credentials come ONLY from the environment (e.g. the
    # Render dashboard). No baked-in defaults, so no secret ever ships in the
    # repo; when unset, admin bootstrap is simply skipped.
    ADMIN_EMAIL: str | None = None
    ADMIN_PASSWORD: str | None = None
    ADMIN_AUTO_CREATE: bool = True

    # Storage
    STORAGE_BACKEND: Literal["s3", "azure_blob", "local"] = "local"
    LOCAL_STORAGE_DIR: str = "./var/uploads"
    S3_BUCKET: str = ""
    S3_REGION: str = "us-gov-west-1"
    # Custom S3 endpoint (e.g. MinIO for local dev). Empty = default AWS endpoint.
    S3_ENDPOINT_URL: str = ""
    AZURE_STORAGE_CONNECTION_STRING: str = ""
    AZURE_STORAGE_CONTAINER: str = "sentinel-qms"

    # Uploads
    MAX_UPLOAD_BYTES: int = 52_428_800

    # Outbound notifications (all optional — empty disables that channel).
    # Microsoft Teams incoming webhook (Connector / Workflow URL).
    TEAMS_WEBHOOK_URL: str = ""
    # Slack incoming webhook URL.
    SLACK_WEBHOOK_URL: str = ""
    # SMTP email dispatch.
    SMTP_HOST: str = ""
    SMTP_PORT: int = 587
    SMTP_USERNAME: str = ""
    SMTP_PASSWORD: str = ""
    SMTP_FROM: str = ""
    SMTP_USE_TLS: bool = True

    # Background scheduler (in-process): runs the SLA escalation sweep and the
    # scheduled report digest. Disabled automatically under the test-suite. Set
    # RUN_SCHEDULER=false to turn it off (e.g. when running a dedicated worker).
    RUN_SCHEDULER: bool = True
    # How often the scheduler wakes, in seconds (default 15 minutes). Each tick
    # runs the SLA sweep (idempotent) and checks whether a digest is due.
    SCHEDULER_INTERVAL_SECONDS: int = 900
    # Public base URL used to build deep links in outbound notifications/digests.
    APP_BASE_URL: str = ""

    @field_validator("CORS_ORIGINS", "OIDC_ALLOWED_DOMAINS", mode="before")
    @classmethod
    def _split_cors(cls, v: object) -> object:
        if isinstance(v, str):
            return [o.strip().lower() for o in v.split(",") if o.strip()]
        return v

    @field_validator("OIDC_GROUP_ROLE_MAP", mode="before")
    @classmethod
    def _parse_group_role_map(cls, v: object) -> object:
        # Accept a JSON object from the environment; ignore blanks/garbage.
        if isinstance(v, str):
            v = v.strip()
            if not v:
                return {}
            import json

            try:
                parsed = json.loads(v)
            except ValueError as exc:
                raise ValueError("OIDC_GROUP_ROLE_MAP must be valid JSON") from exc
            if not isinstance(parsed, dict):
                raise ValueError("OIDC_GROUP_ROLE_MAP must be a JSON object")
            return parsed
        return v

    @field_validator("DB_SCHEMA", mode="before")
    @classmethod
    def _safe_schema(cls, v: object) -> object:
        # Only a plain identifier is allowed (it is interpolated into DDL/SET).
        if isinstance(v, str):
            v = v.strip()
            if v and not v.replace("_", "").isalnum():
                raise ValueError("DB_SCHEMA must be alphanumeric/underscore only")
        return v

    @field_validator("DATABASE_URL", mode="before")
    @classmethod
    def _normalize_db_url(cls, v: object) -> object:
        # Managed Postgres providers (e.g. Render, Heroku) hand out URLs with the
        # bare ``postgres://`` / ``postgresql://`` scheme. SQLAlchemy 2.x needs an
        # explicit driver, so pin it to psycopg (v3) which is what we ship.
        if isinstance(v, str):
            if v.startswith("postgres://"):
                return "postgresql+psycopg://" + v[len("postgres://") :]
            if v.startswith("postgresql://"):
                return "postgresql+psycopg://" + v[len("postgresql://") :]
        return v

    @property
    def is_production(self) -> bool:
        return self.ENVIRONMENT == "production"

    @model_validator(mode="after")
    def _guard_production_secrets(self) -> Settings:
        """Refuse to boot in production with an insecure/short JWT secret."""
        if self.ENVIRONMENT == "production" and (
            self.JWT_SECRET == _INSECURE_JWT_DEFAULT or len(self.JWT_SECRET) < 32
        ):
            raise ValueError(
                "JWT_SECRET must be set to a strong value (>=32 chars) when "
                "ENVIRONMENT=production; refusing to start with the insecure default."
            )
        return self


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
