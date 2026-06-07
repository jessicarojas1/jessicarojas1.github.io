"""Auto record numbering, e.g. NCR-2026-0001, CAPA-2026-0001 — unique per type/year."""

from __future__ import annotations

from datetime import UTC, datetime

from sqlalchemy import func, select
from sqlalchemy.orm import Session

# Map model class -> (prefix, number column attribute name).
_SEQUENCE_CONFIG: dict[str, str] = {}


def _current_year() -> int:
    return datetime.now(UTC).year


def next_number(db: Session, model, number_attr: str, prefix: str, *, width: int = 4) -> str:
    """Generate the next sequential number for ``model`` within the current year.

    Pattern: ``{PREFIX}-{YEAR}-{NNNN}``.  Computed by counting the max existing
    suffix for that prefix/year so it remains correct after deletes are avoided
    (controlled records are soft-deleted, not removed).
    """
    year = _current_year()
    like = f"{prefix}-{year}-%"
    col = getattr(model, number_attr)

    # Pull existing numbers for this prefix/year and find the max numeric suffix.
    rows = db.execute(select(col).where(col.like(like))).scalars().all()
    max_seq = 0
    for value in rows:
        try:
            seq = int(str(value).rsplit("-", 1)[-1])
        except (ValueError, IndexError):
            continue
        max_seq = max(max_seq, seq)

    return f"{prefix}-{year}-{max_seq + 1:0{width}d}"


def count_for_year(db: Session, model, number_attr: str, prefix: str) -> int:
    year = _current_year()
    col = getattr(model, number_attr)
    return int(
        db.execute(
            select(func.count()).select_from(model).where(col.like(f"{prefix}-{year}-%"))
        ).scalar_one()
    )
