"""Inspection endpoints: CRUD + FAI/AS9102 reports with characteristics."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    require_page,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.inspection import (
    FaiCharacteristic,
    FaiReport,
    Inspection,
    InspectionResult,
    InspectionType,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.inspection import (
    FaiReportCreate,
    FaiReportRead,
    InspectionCreate,
    InspectionList,
    InspectionRead,
    InspectionUpdate,
)
from app.services import numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)

router = APIRouter(prefix="/inspections", tags=["inspections"])

ENTITY = "inspection"


@router.get("", response_model=Page[InspectionList])
def list_inspections(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    inspection_type: InspectionType | None = Query(None),
    result: InspectionResult | None = Query(None),
    _: CurrentUser = Depends(require_page("inspections", "view")),
) -> Page[InspectionList]:
    stmt = base_select(Inspection)
    if inspection_type:
        stmt = stmt.where(Inspection.inspection_type == inspection_type)
    if result:
        stmt = stmt.where(Inspection.result == result)
    stmt = apply_sort(stmt, Inspection, sort)
    items, total = paginate(db, stmt, Inspection, pagination)
    return Page[InspectionList](items=items, **page_meta(total, pagination))


@router.post("", response_model=InspectionRead, status_code=status.HTTP_201_CREATED)
def create_inspection(
    body: InspectionCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("inspections", "edit")),
) -> Inspection:
    insp = Inspection(
        **body.model_dump(),
        inspection_number=numbering.next_number(
            db, Inspection, "inspection_number", "INSP"
        ),
        result=InspectionResult.PENDING,
        inspector_id=actor.id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(insp)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=insp.id,
        after=insp,
        **request_context(request),
    )
    db.commit()
    db.refresh(insp)
    return insp


@router.get("/{inspection_id}", response_model=InspectionRead)
def get_inspection(
    inspection_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("inspections", "view")),
) -> Inspection:
    return get_or_404(db, Inspection, inspection_id, name="Inspection")


@router.patch("/{inspection_id}", response_model=InspectionRead)
def update_inspection(
    inspection_id: int,
    body: InspectionUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("inspections", "edit")),
) -> Inspection:
    insp = get_or_404(db, Inspection, inspection_id, name="Inspection")
    before = audit.snapshot(insp)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(insp, key, value)
    insp.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=insp.id,
        before=before,
        after=insp,
        **request_context(request),
    )
    db.commit()
    db.refresh(insp)
    return insp


@router.post("/fai", response_model=FaiReportRead, status_code=status.HTTP_201_CREATED)
def create_fai_report(
    body: FaiReportCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("inspections", "edit")),
) -> FaiReport:
    """Create an AS9102 First Article Inspection report with balloon characteristics."""
    if body.inspection_id is not None and db.get(Inspection, body.inspection_id) is None:
        raise NotFoundError(f"Inspection {body.inspection_id} not found.")

    data = body.model_dump()
    characteristics = data.pop("characteristics", [])
    report = FaiReport(
        **data,
        fai_number=numbering.next_number(db, FaiReport, "fai_number", "FAI"),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(report)
    db.flush()

    all_pass = True
    for char in characteristics:
        result = char.get("result")
        if result and result.lower() == "fail":
            all_pass = False
        db.add(FaiCharacteristic(fai_report_id=report.id, **char))
    report.disposition = "complete" if characteristics and all_pass else "incomplete"

    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create_fai",
        entity_type="fai_report",
        entity_id=report.id,
        after={"fai_number": report.fai_number, "disposition": report.disposition},
        **request_context(request),
    )
    db.commit()
    db.refresh(report)
    return report


@router.get("/fai/{fai_id}", response_model=FaiReportRead)
def get_fai_report(
    fai_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("inspections", "view")),
) -> FaiReport:
    report = db.get(FaiReport, fai_id)
    if report is None:
        raise NotFoundError(f"FAI report {fai_id} not found.")
    return report
