# syntax=docker/dockerfile:1
# ─────────────────────────────────────────────────────────────────────────────
# Sentinel QMS — single-service image.
#
# One container where FastAPI serves BOTH the REST API (/api/v1) and the built
# React SPA (same origin, so no CORS). Ideal for a single Render web service.
#
# Build context must be the project root (this directory) so it can see both
# frontend/ and backend/.
# ─────────────────────────────────────────────────────────────────────────────

# ---- Stage 1: build the React SPA ----
FROM node:20-alpine AS frontend
WORKDIR /fe
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci || npm install
COPY frontend/ ./
# Same-origin: the SPA calls /api/v1 on its own host.
ENV VITE_API_BASE_URL=/api/v1
RUN npm run build        # -> /fe/dist

# ---- Stage 2: build backend wheels ----
FROM python:3.12-slim AS builder
ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONDONTWRITEBYTECODE=1
WORKDIR /build
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential \
    && rm -rf /var/lib/apt/lists/*
COPY backend/pyproject.toml backend/README.md ./
COPY backend/app ./app
RUN pip install --upgrade pip build \
    && pip wheel --wheel-dir /wheels .

# ---- Stage 3: runtime ----
FROM python:3.12-slim AS runtime
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    ENVIRONMENT=production \
    PORT=8000 \
    SERVE_FRONTEND=1 \
    STATIC_DIR=/app/static

RUN groupadd --gid 1000 sentinel \
    && useradd --uid 1000 --gid sentinel --create-home --shell /usr/sbin/nologin sentinel

WORKDIR /app

COPY --from=builder /wheels /wheels
RUN pip install --no-cache-dir --no-index --find-links=/wheels sentinel-qms-api \
    && rm -rf /wheels

COPY --chown=sentinel:sentinel backend/app ./app
COPY --chown=sentinel:sentinel backend/alembic ./alembic
COPY --chown=sentinel:sentinel backend/alembic.ini backend/gunicorn.conf.py ./
COPY --chown=sentinel:sentinel --chmod=0755 backend/docker-entrypoint.sh ./docker-entrypoint.sh
COPY --chown=sentinel:sentinel --from=frontend /fe/dist ./static

RUN mkdir -p /app/var/uploads && chown -R sentinel:sentinel /app/var /app/static

USER sentinel
EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD python -c "import os,urllib.request,sys; \
sys.exit(0) if urllib.request.urlopen('http://127.0.0.1:%s/health' % os.getenv('PORT','8000'), timeout=3).status==200 else sys.exit(1)"

# Entrypoint runs migrations + seed, then launches gunicorn (binds $PORT).
ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["gunicorn", "app.main:app", "-c", "gunicorn.conf.py"]
