"""Application configuration loaded from environment variables."""

from __future__ import annotations

from functools import lru_cache
from typing import Literal

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


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
    JWT_SECRET: str = "insecure-development-secret-change-me-please-32+chars"
    JWT_ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 30
    REFRESH_TOKEN_EXPIRE_DAYS: int = 7

    # OIDC / federal SSO (stub)
    OIDC_ISSUER: str = ""
    OIDC_CLIENT_ID: str = ""
    OIDC_CLIENT_SECRET: str = ""

    # Bootstrap admin
    ADMIN_EMAIL: str = "admin@sentinel-qms.local"
    ADMIN_PASSWORD: str = "ChangeMe!Admin123"
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

    @field_validator("CORS_ORIGINS", mode="before")
    @classmethod
    def _split_cors(cls, v: object) -> object:
        if isinstance(v, str):
            return [o.strip() for o in v.split(",") if o.strip()]
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


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
