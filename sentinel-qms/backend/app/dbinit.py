"""Production-safe database initialization (reference data).

Run this AFTER applying the schema (``alembic upgrade head``):

    python -m app.dbinit

It seeds the RBAC roles/permissions that the application requires to function
(idempotent). When ``BOOTSTRAP_ADMIN`` is truthy it also ensures a single
administrator account from ``ADMIN_EMAIL`` / ``ADMIN_PASSWORD`` — use this once
per environment to obtain a first login. Unlike ``app.seed`` (the demo seeder,
which refuses to create admins in production), this is an explicit, opt-in
operator action and therefore works in any environment.

This module never loads demo records.
"""

from __future__ import annotations

import logging
import os

from sqlalchemy import select

from app.core.config import settings
from app.core.database import SessionLocal
from app.core.logging import configure_logging
from app.core.rbac import Role as RoleEnum
from app.core.security import hash_password
from app.models import Role, User  # noqa: F401 - ensures metadata is populated
from app.seed import seed_roles

logger = logging.getLogger("app.dbinit")


def _truthy(value: str | None) -> bool:
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def bootstrap_admin(db) -> None:
    """Create the bootstrap administrator if it does not already exist."""
    roles = {r.name: r for r in db.execute(select(Role)).scalars().all()}
    admin_role = roles.get(RoleEnum.ADMIN.value)
    if admin_role is None:
        raise RuntimeError("ADMIN role missing — run seed_roles first.")
    if not settings.ADMIN_EMAIL or not settings.ADMIN_PASSWORD:
        raise RuntimeError(
            "ADMIN_EMAIL / ADMIN_PASSWORD must be set in the environment to "
            "create the bootstrap admin."
        )

    email = settings.ADMIN_EMAIL.lower()
    existing = db.execute(select(User).where(User.email == email)).scalar_one_or_none()
    if existing is not None:
        logger.info("admin %s already exists; leaving as-is", email)
        return

    admin = User(
        email=email,
        full_name="System Administrator",
        hashed_password=hash_password(settings.ADMIN_PASSWORD),
        is_active=True,
    )
    admin.roles = [admin_role]
    db.add(admin)
    logger.info("created bootstrap admin %s", email)


def main() -> None:
    configure_logging()
    with SessionLocal() as db:
        seed_roles(db)
        if _truthy(os.getenv("BOOTSTRAP_ADMIN", "0")):
            bootstrap_admin(db)
        else:
            logger.info("BOOTSTRAP_ADMIN not set; skipping admin creation")
        db.commit()
    logger.info("dbinit complete (roles ensured; environment=%s)", settings.ENVIRONMENT)


if __name__ == "__main__":
    main()
