# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────────────
# CMMC 2.0 Level 2 Compliance Agent — container image
# Flask web GUI + Claude-powered agentic tool use (NIST 800-171, 110 practices)
# Python 3.11 (matches .python-version). Non-root, healthcheck on /api/dashboard.
# ─────────────────────────────────────────────────────────────────────────────

# ── Stage 1: build wheels ────────────────────────────────────────────────────
FROM python:3.11.9-slim AS builder

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /build
COPY requirements.txt .
# Pre-build wheels so the runtime stage stays slim and offline-installable.
RUN pip wheel --wheel-dir /wheels -r requirements.txt

# ── Stage 2: runtime ─────────────────────────────────────────────────────────
FROM python:3.11.9-slim AS runtime

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PORT=5050

# curl is used by the HEALTHCHECK below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Non-root runtime user.
RUN useradd --create-home --uid 10001 appuser

WORKDIR /app

# Install dependencies from pre-built wheels.
COPY --from=builder /wheels /wheels
COPY requirements.txt .
RUN pip install --no-index --find-links=/wheels -r requirements.txt \
    && rm -rf /wheels

# Application code.
COPY agent.py server.py ./
COPY .python-version ./

# status.json / settings.json are written at runtime; ensure the app dir is writable.
RUN chown -R appuser:appuser /app
USER appuser

EXPOSE 5050

# /healthz is a lightweight liveness probe (no scoring compute, no API key).
# /api/dashboard also works as a probe and additionally exercises scoring.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/healthz" || exit 1

# Production WSGI server (gunicorn) serving the Flask app object `server:app`.
# WEB_CONCURRENCY defaults to 1 — the JSON state files have a single writer.
# For local/dev use `python server.py` (Flask dev server) instead.
CMD ["sh", "-c", "gunicorn --bind 0.0.0.0:${PORT:-5050} --workers ${WEB_CONCURRENCY:-1} --timeout 120 server:app"]
