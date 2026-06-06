"""Database engine, session factory, declarative Base, and FastAPI dependency."""
from __future__ import annotations

from collections.abc import Generator

from sqlalchemy import create_engine, event
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.core.config import settings

# When sharing a database with other apps, isolate every Sentinel table in a
# dedicated schema instead of ``public`` (set DB_SCHEMA). Empty = default.
DB_SCHEMA = settings.DB_SCHEMA.strip()


class Base(DeclarativeBase):
    """Declarative base for all ORM models."""


def _engine_kwargs() -> dict:
    url = settings.DATABASE_URL
    kwargs: dict = {"pool_pre_ping": True, "future": True}
    if url.startswith("sqlite"):
        # Used by the test-suite; allow cross-thread use of a single connection.
        kwargs["connect_args"] = {"check_same_thread": False}
    else:
        kwargs.update(pool_size=10, max_overflow=20)
    return kwargs


engine = create_engine(settings.DATABASE_URL, **_engine_kwargs())

# Route every connection to the dedicated schema (Postgres only). The schema is
# created by the migration/bootstrap step; here we just point sessions at it so
# all reads/writes resolve there, falling back to ``public`` for shared objects.
if DB_SCHEMA and not settings.DATABASE_URL.startswith("sqlite"):

    @event.listens_for(engine, "connect")
    def _set_search_path(dbapi_connection, connection_record):  # noqa: ANN001
        # Commit the SET immediately (autocommit) so it survives later
        # transaction rollbacks — the SQLAlchemy-recommended recipe.
        previous_autocommit = dbapi_connection.autocommit
        dbapi_connection.autocommit = True
        cursor = dbapi_connection.cursor()
        try:
            cursor.execute(f'SET SESSION search_path TO "{DB_SCHEMA}", public')
        finally:
            cursor.close()
        dbapi_connection.autocommit = previous_autocommit


SessionLocal = sessionmaker(bind=engine, autocommit=False, autoflush=False, expire_on_commit=False)


def get_db() -> Generator[Session, None, None]:
    """Yield a transactional database session, ensuring it is always closed."""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
