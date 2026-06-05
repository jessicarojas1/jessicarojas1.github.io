# syntax=docker/dockerfile:1
# ── Multi-stage build for the Sentinel QMS API ──────────────────────────────

FROM python:3.12-slim AS builder

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /build

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential \
    && rm -rf /var/lib/apt/lists/*

# Build a wheelhouse so the runtime image stays slim and offline-installable.
COPY pyproject.toml README.md ./
COPY app ./app
RUN pip install --upgrade pip build \
    && pip wheel --wheel-dir /wheels .


FROM python:3.12-slim AS runtime

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    ENVIRONMENT=production \
    PORT=8000

# Non-root runtime user.
RUN groupadd --gid 1000 sentinel \
    && useradd --uid 1000 --gid sentinel --create-home --shell /usr/sbin/nologin sentinel

WORKDIR /app

COPY --from=builder /wheels /wheels
RUN pip install --no-cache-dir --no-index --find-links=/wheels sentinel-qms-api \
    && rm -rf /wheels

COPY --chown=sentinel:sentinel app ./app
COPY --chown=sentinel:sentinel alembic ./alembic
COPY --chown=sentinel:sentinel alembic.ini gunicorn.conf.py ./

RUN mkdir -p /app/var/uploads && chown -R sentinel:sentinel /app/var

USER sentinel

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD python -c "import urllib.request,sys; \
sys.exit(0) if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=3).status==200 else sys.exit(1)"

CMD ["gunicorn", "app.main:app", "-c", "gunicorn.conf.py"]
