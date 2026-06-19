"""In-process background scheduler.

Runs two periodic jobs on a single daemon thread:

* the SLA escalation sweep (:func:`app.services.sla.run_sla_sweep`), and
* the scheduled report digest (:func:`app.services.report_digest.maybe_send_scheduled`).

Both jobs are idempotent and claim their work atomically in the database, so it
is safe to run this thread in every web worker — duplicate work is suppressed at
the DB layer. The scheduler is started from the FastAPI lifespan and is disabled
automatically under the test-suite (``RUN_SCHEDULER=false``).
"""

from __future__ import annotations

import logging
import threading

from app.core.config import settings
from app.core.database import SessionLocal
from app.services import report_digest, sla, webhooks

logger = logging.getLogger("app.scheduler")

_thread: threading.Thread | None = None
_stop = threading.Event()


def run_tick() -> None:
    """Execute one scheduler pass: SLA sweep + (if due) report digest."""
    with SessionLocal() as db:
        try:
            summary = sla.run_sla_sweep(db)
            fired = sum(v for k, v in summary.items() if isinstance(v, int))
            if fired:
                logger.info("sla sweep fired %s escalation(s): %s", fired, summary)
        except Exception:  # noqa: BLE001 — never let one job kill the loop
            logger.exception("sla sweep failed")
            db.rollback()
        try:
            result = report_digest.maybe_send_scheduled(db)
            if result.get("sent"):
                logger.info("report digest sent to %s recipient(s)", result["sent"])
        except Exception:  # noqa: BLE001
            logger.exception("report digest failed")
            db.rollback()
        try:
            wh = webhooks.dispatch_due(db)
            if wh.get("attempted"):
                logger.info("webhook dispatch: %s", wh)
        except Exception:  # noqa: BLE001
            logger.exception("webhook dispatch failed")
            db.rollback()


def _loop(interval: int) -> None:
    logger.info("scheduler started (interval=%ss)", interval)
    # Wait one interval before the first run so startup stays fast and migrations
    # have settled; ``wait`` returns True only when stop is signalled.
    while not _stop.wait(interval):
        run_tick()
    logger.info("scheduler stopped")


def start() -> bool:
    """Start the background scheduler thread once. Returns True if it started."""
    global _thread
    if not settings.RUN_SCHEDULER:
        logger.info("scheduler disabled (RUN_SCHEDULER=false)")
        return False
    if _thread is not None and _thread.is_alive():
        return False
    _stop.clear()
    interval = max(int(settings.SCHEDULER_INTERVAL_SECONDS), 30)
    _thread = threading.Thread(
        target=_loop, args=(interval,), name="sentinel-scheduler", daemon=True
    )
    _thread.start()
    return True


def stop() -> None:
    """Signal the scheduler thread to stop (best-effort, used on shutdown)."""
    _stop.set()
