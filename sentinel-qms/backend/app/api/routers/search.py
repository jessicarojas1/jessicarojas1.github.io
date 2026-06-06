"""Global cross-module search endpoint."""
from __future__ import annotations

from dataclasses import dataclass

from fastapi import APIRouter, Depends, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core.database import get_db
from app.core.rbac import Permission, permissions_for_roles
from app.models.audit_mgmt import Audit
from app.models.calibration import Equipment
from app.models.capa import Capa
from app.models.change import ChangeOrder
from app.models.complaint import Complaint
from app.models.document import Document
from app.models.inspection import Inspection
from app.models.nonconformance import Nonconformance
from app.models.risk import Risk
from app.models.supplier import Supplier
from app.schemas.auth import CurrentUser
from app.schemas.search import SearchHit, SearchResults

router = APIRouter(prefix="/search", tags=["search"])


@dataclass(frozen=True)
class _Target:
    type: str
    model: type
    number_col: str
    title_col: str
    url_template: str
    permission: Permission


# Each searchable module: which columns identify the record + the SPA route +
# the read permission required to surface it. Mirrors the list endpoints.
_TARGETS: list[_Target] = [
    _Target("nonconformance", Nonconformance, "ncr_number", "title",
            "/nonconformances/{id}", Permission.NCR_READ),
    _Target("capa", Capa, "capa_number", "title", "/capa/{id}", Permission.CAPA_READ),
    _Target("document", Document, "document_number", "title",
            "/documents/{id}", Permission.DOCUMENT_READ),
    _Target("supplier", Supplier, "supplier_code", "name",
            "/suppliers/{id}", Permission.SUPPLIER_READ),
    _Target("audit", Audit, "audit_number", "title", "/audits/{id}", Permission.AUDIT_READ),
    _Target("complaint", Complaint, "complaint_number", "title",
            "/complaints/{id}", Permission.COMPLAINT_READ),
    _Target("risk", Risk, "risk_number", "title", "/risks/{id}", Permission.RISK_READ),
    _Target("change", ChangeOrder, "change_number", "title",
            "/changes/{id}", Permission.CHANGE_READ),
    _Target("inspection", Inspection, "inspection_number", "inspection_type",
            "/inspections/{id}", Permission.INSPECTION_READ),
    _Target("equipment", Equipment, "asset_tag", "name",
            "/calibration/{id}", Permission.CALIBRATION_READ),
]


def _as_text(value: object) -> str:
    if value is None:
        return ""
    return value.value if hasattr(value, "value") else str(value)


@router.get("", response_model=SearchResults)
def global_search(
    q: str = Query("", description="Free-text query (matches number + title/name)"),
    limit: int = Query(20, ge=1, le=100),
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(get_current_user),
) -> SearchResults:
    term = q.strip()
    if not term:
        return SearchResults(results=[])

    granted = permissions_for_roles(user.role_names)
    like = f"%{term}%"
    hits: list[SearchHit] = []

    for t in _TARGETS:
        if len(hits) >= limit:
            break
        if t.permission not in granted:
            continue
        number_col = getattr(t.model, t.number_col)
        title_col = getattr(t.model, t.title_col)
        stmt = select(t.model).where(number_col.ilike(like) | title_col.ilike(like))
        if hasattr(t.model, "is_deleted"):
            stmt = stmt.where(t.model.is_deleted.is_(False))
        stmt = stmt.order_by(t.model.id.desc()).limit(limit - len(hits))
        for row in db.execute(stmt).scalars().all():
            hits.append(
                SearchHit(
                    type=t.type,
                    id=row.id,
                    number=_as_text(getattr(row, t.number_col)),
                    title=_as_text(getattr(row, t.title_col)),
                    url=t.url_template.format(id=row.id),
                )
            )
            if len(hits) >= limit:
                break

    return SearchResults(results=hits[:limit])
