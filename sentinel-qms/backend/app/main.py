"""FastAPI application factory for the Sentinel QMS API."""
from __future__ import annotations

import os

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from sqlalchemy import text
from starlette.responses import FileResponse

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

    static_dir = os.path.abspath(settings.STATIC_DIR)
    if settings.SERVE_FRONTEND and os.path.isdir(static_dir):
        _mount_spa(app, static_dir)
    else:
        @app.get("/", tags=["system"])
        def root() -> dict:
            return {
                "name": settings.PROJECT_NAME,
                "version": __version__,
                "docs": "/docs",
                "api": settings.API_V1_PREFIX,
            }

    return app


def _mount_spa(app: FastAPI, static_dir: str) -> None:
    """Serve the built React SPA from this process (single-service mode).

    Hashed assets are served from ``/assets``; every other non-API path falls
    back to ``index.html`` so client-side routes work on hard refresh.
    """
    assets_dir = os.path.join(static_dir, "assets")
    if os.path.isdir(assets_dir):
        app.mount("/assets", StaticFiles(directory=assets_dir), name="assets")
    index_file = os.path.join(static_dir, "index.html")

    @app.get("/{full_path:path}", include_in_schema=False)
    async def spa_fallback(full_path: str) -> FileResponse:
        # Let unmatched API routes return a normal JSON 404, not the SPA shell.
        if full_path.startswith("api/"):
            raise HTTPException(status_code=404, detail="Not Found")
        candidate = os.path.normpath(os.path.join(static_dir, full_path))
        if (
            full_path
            and candidate.startswith(static_dir)
            and os.path.isfile(candidate)
        ):
            return FileResponse(candidate)
        return FileResponse(index_file)


app = create_app()
