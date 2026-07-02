# syntax=docker/dockerfile:1
# ─────────────────────────────────────────────────────────────────────────────
# Business Insight Dashboard — Streamlit container
# Python 3.11 (matches .python-version = 3.11.9), non-root, healthchecked.
# ─────────────────────────────────────────────────────────────────────────────
FROM python:3.11-slim AS base

# Streamlit / runtime environment
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    STREAMLIT_SERVER_PORT=8501 \
    STREAMLIT_SERVER_ADDRESS=0.0.0.0 \
    STREAMLIT_SERVER_HEADLESS=true \
    STREAMLIT_SERVER_ENABLE_XSRF_PROTECTION=true \
    STREAMLIT_BROWSER_GATHER_USAGE_STATS=false

# curl is used by the HEALTHCHECK below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install Python dependencies first for better layer caching.
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Copy the application source.
COPY . .

# Run as a non-root user. Give it ownership so branding.json can be written
# next to app.py. For an immutable/read-only filesystem, mount a writable
# volume at /app and/or point branding storage at a writable path.
RUN useradd --create-home --uid 10001 app \
    && chown -R app:app /app
USER app

EXPOSE 8501

# Streamlit core health endpoint returns "ok" when the server is ready.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost:8501/_stcore/health || exit 1

# $PORT (Render/PaaS) overrides 8501 when set; default to 8501 otherwise.
CMD ["sh", "-c", "streamlit run app.py --server.port ${PORT:-8501} --server.address 0.0.0.0 --server.headless true"]
