"""Structured JSON logging configuration."""
from __future__ import annotations

import logging
import sys
from typing import Any

from pythonjsonlogger import jsonlogger

from app.core.config import settings


class _ContextFilter(logging.Filter):
    """Attach a default request_id when none is present on the record."""

    def filter(self, record: logging.LogRecord) -> bool:  # noqa: D401
        if not hasattr(record, "request_id"):
            record.request_id = "-"
        return True


def configure_logging() -> None:
    """Install a JSON formatter on the root logger (idempotent)."""
    root = logging.getLogger()
    root.setLevel(settings.LOG_LEVEL.upper())

    # Avoid duplicate handlers on reload / repeated calls.
    for h in list(root.handlers):
        root.removeHandler(h)

    handler = logging.StreamHandler(sys.stdout)
    formatter = jsonlogger.JsonFormatter(
        "%(asctime)s %(levelname)s %(name)s %(request_id)s %(message)s",
        rename_fields={"asctime": "timestamp", "levelname": "level", "name": "logger"},
        json_ensure_ascii=False,
    )
    handler.setFormatter(formatter)
    handler.addFilter(_ContextFilter())
    root.addHandler(handler)

    # Tame noisy third-party loggers.
    for noisy in ("uvicorn.access", "sqlalchemy.engine"):
        logging.getLogger(noisy).setLevel(logging.WARNING)


def get_logger(name: str, **context: Any) -> logging.LoggerAdapter:
    """Return a LoggerAdapter that injects static context into every record."""
    return logging.LoggerAdapter(logging.getLogger(name), context)
