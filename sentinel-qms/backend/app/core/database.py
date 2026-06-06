"""Database engine, session factory, declarative Base, and FastAPI dependency."""
from __future__ import annotations

from collections.abc import Generator

from sqlalchemy import MetaData, create_engine, event
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.core.config import settings

# When sharing a database with other apps, isolate every Sentinel table in a
# dedicated schema instead of ``public``. SQLite (tests) has no schemas, so we
# fall back to the default there. Binding the schema onto the MetaData makes
# every table/FK fully qualified, so create_all/drop_all can NEVER touch
# objects in ``public`` — critical on a shared database.
_IS_SQLITE = settings.DATABASE_URL.startswith("sqlite")
DB_SCHEMA = "" if _IS_SQLITE else settings.DB_SCHEMA.strip()


class Base(DeclarativeBase):
    """Declarative base; all tables live in the dedicated schema when configured."""

    metadata = MetaData(schema=DB_SCHEMA or None)


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

# Also point the session search_path at the schema so any raw/unqualified SQL
# resolves there (the ORM tables are already schema-qualified via the MetaData).
if DB_SCHEMA:

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
