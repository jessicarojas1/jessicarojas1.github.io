"""Gunicorn configuration: Uvicorn workers for the FastAPI app."""
from __future__ import annotations

import multiprocessing
import os

# Bind / workers.
# Honour an explicit GUNICORN_BIND, otherwise bind the platform-provided PORT
# (Render/Heroku/Cloud Run set this) and fall back to 8000 for local/compose.
bind = os.getenv("GUNICORN_BIND") or f"0.0.0.0:{os.getenv('PORT', '8000')}"
# Honour WEB_CONCURRENCY; otherwise size to the CPU but cap the default so small
# instances (e.g. a 512 MB free tier that reports many host CPUs) don't OOM.
_default_workers = min(multiprocessing.cpu_count() * 2 + 1, 4)
workers = int(os.getenv("WEB_CONCURRENCY", _default_workers))
worker_class = "uvicorn.workers.UvicornWorker"
threads = int(os.getenv("GUNICORN_THREADS", "1"))

# Timeouts / recycling
timeout = int(os.getenv("GUNICORN_TIMEOUT", "60"))
graceful_timeout = 30
keepalive = 5
max_requests = int(os.getenv("GUNICORN_MAX_REQUESTS", "1000"))
max_requests_jitter = 100

# Logging (stdout/stderr -> container logs)
accesslog = "-"
errorlog = "-"
loglevel = os.getenv("LOG_LEVEL", "info").lower()
forwarded_allow_ips = os.getenv("FORWARDED_ALLOW_IPS", "*")
