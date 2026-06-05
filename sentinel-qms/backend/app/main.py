"""FastAPI application factory for the Sentinel QMS API."""
from __future__ import annotations

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from sqlalchemy import text

from app import __version__
from app.api import api_router
from app.core.config import settings
from app.core.database import engine
from app.core.exceptions import register_exception_handlers
from app.core.logging import configure_logging
from app.core.middleware import RequestContextMiddleware, SecurityHeadersMiddleware

OPENAPI_DESCRIPTION = """
Sentinel QMS — Enterprise Quality Management System API.

Standards-aligned (AS9100D / ISO 9001 / 21 CFR Part 11) quality management for
aerospace, manufacturing, and U.S. Department of Defense programs. Deployable to
AWS GovCloud and Azure Government.

Modules: Document Control, Nonconformance (NCR/MRB), CAPA (8D), Audits,
Supplier Quality (SCAR/ASL), Calibration, Training & Competency, Engineering
Change, Risk, Inspection & First Article (AS9102), Management Review, and
Customer Complaints (RMA).
"""


def create_app() -> FastAPI:
    configure_logging()

    app = FastAPI(
        title=settings.PROJECT_NAME,
        version=__version__,
        description=OPENAPI_DESCRIPTION,
        openapi_url=f"{settings.API_V1_PREFIX}/openapi.json",
        docs_url="/docs",
        redoc_url="/redoc",
        contact={"name": "Sentinel QMS Team"},
        license_info={"name": "Proprietary"},
    )

    # Middleware (executed bottom-up: security headers wrap, context is outermost).
    app.add_middleware(SecurityHeadersMiddleware)
    app.add_middleware(RequestContextMiddleware)
    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.CORS_ORIGINS,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
        expose_headers=["X-Request-ID"],
    )

    register_exception_handlers(app)

    app.include_router(api_router, prefix=settings.API_V1_PREFIX)

    @app.get("/health", tags=["system"])
    def health() -> dict:
        """Liveness/readiness probe: reports DB connectivity and version."""
        db_ok = True
        db_error: str | None = None
        try:
            with engine.connect() as conn:
                conn.execute(text("SELECT 1"))
        except Exception as exc:  # noqa: BLE001
            db_ok = False
            db_error = str(exc)
        status_str = "ok" if db_ok else "degraded"
        payload = {
            "status": status_str,
            "version": __version__,
            "environment": settings.ENVIRONMENT,
            "database": {"connected": db_ok},
        }
        if db_error:
            payload["database"]["error"] = db_error
        return payload

    @app.get("/", tags=["system"])
    def root() -> dict:
        return {
            "name": settings.PROJECT_NAME,
            "version": __version__,
            "docs": "/docs",
            "api": settings.API_V1_PREFIX,
        }

    return app


app = create_app()
