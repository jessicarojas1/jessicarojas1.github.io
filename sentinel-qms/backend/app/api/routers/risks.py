"""Risk register endpoints: CRUD with automatic RPN computation."""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Query, Request, UploadFile, status
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    require_page,
    require_perm,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.models.risk import Risk, RiskCategory, RiskStatus
from app.schemas.auth import CurrentUser
from app.schemas.common import ImportResult, Page
from app.schemas.risk import RiskCreate, RiskList, RiskRead, RiskUpdate
from app.services import csv_import, numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)

router = APIRouter(prefix="/risks", tags=["risks"])

ENTITY = "risk"

# Importable columns: required + common optional fields from RiskCreate.
_IMPORT_COLUMNS = [
    "title",
    "category",
    "description",
    "severity",
    "likelihood",
    "detectability",
    "treatment_strategy",
    "treatment_plan",
    "review_date",
]
_IMPORT_EXAMPLE = [
    "Single-source supplier dependency",
    "supply_chain",
    "Critical component sourced from one supplier with no qualified alternate.",
    "8",
    "5",
    "4",
    "mitigate",
    "Qualify a second supplier within two quarters.",
    "2026-12-31",
]


def _rpn(severity: int | None, likelihood: int | None, detectability: int | None) -> int | None:
    if None in (severity, likelihood, detectability):
        return None
    return severity * likelihood * detectability


@router.get("", response_model=Page[RiskList])
def list_risks(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: RiskStatus | None = Query(None, alias="status"),
    category: RiskCategory | None = Query(None),
    min_rpn: int | None = Query(None, ge=1),
    _: CurrentUser = Depends(require_page("risks", "view")),
) -> Page[RiskList]:
    stmt = base_select(Risk)
    if status_filter:
        stmt = stmt.where(Risk.status == status_filter)
    if category:
        stmt = stmt.where(Risk.category == category)
    if min_rpn:
        stmt = stmt.where(Risk.rpn >= min_rpn)
    stmt = apply_sort(stmt, Risk, sort, default_col="rpn")
    items, total = paginate(db, stmt, Risk, pagination)
    return Page[RiskList](items=items, **page_meta(total, pagination))


@router.post("", response_model=RiskRead, status_code=status.HTTP_201_CREATED)
def create_risk(
    body: RiskCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("risks.create")),
) -> Risk:
    risk = Risk(
        **body.model_dump(),
        risk_number=numbering.next_number(db, Risk, "risk_number", "RISK"),
        status=RiskStatus.IDENTIFIED,
        created_by=actor.id,
        updated_by=actor.id,
    )
    risk.rpn = _rpn(risk.severity, risk.likelihood, risk.detectability) or 1
    db.add(risk)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=risk.id,
        after=risk,
        **request_context(request),
    )
    db.commit()
    db.refresh(risk)
    return risk


# ── Bulk CSV import ───────────────────────────────────────────────────────────
# Declared before /{risk_id} so the literal paths are not shadowed.


@router.get("/import/template")
def risk_import_template(
    _: CurrentUser = Depends(require_page("risks", "view")),
):
    return csv_import.template_response(
        "risks_import_template.csv", _IMPORT_COLUMNS, _IMPORT_EXAMPLE
    )


@router.post("/import", response_model=ImportResult)
def risk_import(
    request: Request,
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("risks.create")),
) -> ImportResult:
    def build_and_insert(row: dict[str, str]) -> None:
        data = {col: csv_import.clean(row.get(col)) for col in _IMPORT_COLUMNS}
        body = RiskCreate(**{k: v for k, v in data.items() if v is not None})
        risk = Risk(
            **body.model_dump(),
            risk_number=numbering.next_number(db, Risk, "risk_number", "RISK"),
            status=RiskStatus.IDENTIFIED,
            created_by=actor.id,
            updated_by=actor.id,
        )
        risk.rpn = _rpn(risk.severity, risk.likelihood, risk.detectability) or 1
        db.add(risk)
        db.flush()

    result = csv_import.import_rows(file, build_and_insert)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="import",
        entity_type=ENTITY,
        entity_id=None,
        after={"created": result.created, "failed": result.failed},
        **request_context(request),
    )
    db.commit()
    return result


@router.get("/{risk_id}", response_model=RiskRead)
def get_risk(
    risk_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("risks", "view")),
) -> Risk:
    return get_or_404(db, Risk, risk_id, name="Risk")


@router.patch("/{risk_id}", response_model=RiskRead)
def update_risk(
    risk_id: int,
    body: RiskUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("risks.edit")),
) -> Risk:
    risk = get_or_404(db, Risk, risk_id, name="Risk")
    before = audit.snapshot(risk)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(risk, key, value)

    # Recompute initial and residual RPN from current factors.
    risk.rpn = _rpn(risk.severity, risk.likelihood, risk.detectability) or risk.rpn
    risk.residual_rpn = _rpn(
        risk.residual_severity, risk.residual_likelihood, risk.residual_detectability
    )
    risk.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=risk.id,
        before=before,
        after=risk,
        **request_context(request),
    )
    db.commit()
    db.refresh(risk)
    return risk


@router.delete("/{risk_id}", response_model=RiskRead)
def soft_delete_risk(
    risk_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("risks.edit")),
) -> Risk:
    risk = get_or_404(db, Risk, risk_id, name="Risk")
    risk.soft_delete(actor.id)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="soft_delete",
        entity_type=ENTITY,
        entity_id=risk.id,
        **request_context(request),
    )
    db.commit()
    db.refresh(risk)
    return risk
