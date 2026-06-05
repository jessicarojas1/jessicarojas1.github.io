"""Calibration endpoints: equipment CRUD + record calibration + due/overdue query."""
from __future__ import annotations

from datetime import date, timedelta

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.rbac import Permission, require_permission
from app.models.calibration import (
    CalibrationRecord,
    CalibrationResult,
    Equipment,
    EquipmentStatus,
)
from app.schemas.auth import CurrentUser
from app.schemas.calibration import (
    CalibrationRecordCreate,
    CalibrationRecordRead,
    EquipmentCreate,
    EquipmentList,
    EquipmentRead,
    EquipmentUpdate,
)
from app.schemas.common import Page
from app.services import numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)

router = APIRouter(prefix="/calibration", tags=["calibration"])

ENTITY = "equipment"


@router.get("/equipment", response_model=Page[EquipmentList])
def list_equipment(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: EquipmentStatus | None = Query(None, alias="status"),
    location: str | None = Query(None),
    search: str | None = Query(None),
    _: CurrentUser = Depends(require_permission(Permission.CALIBRATION_READ)),
) -> Page[EquipmentList]:
    stmt = base_select(Equipment)
    if status_filter:
        stmt = stmt.where(Equipment.status == status_filter)
    if location:
        stmt = stmt.where(Equipment.location == location)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Equipment.name.ilike(like) | Equipment.asset_tag.ilike(like))
    stmt = apply_sort(stmt, Equipment, sort)
    items, total = paginate(db, stmt, Equipment, pagination)
    return Page[EquipmentList](items=items, **page_meta(total, pagination))


@router.get("/due", response_model=list[EquipmentList])
def due_or_overdue(
    within_days: int = Query(30, ge=0, le=365),
    overdue_only: bool = Query(False),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.CALIBRATION_READ)),
) -> list[Equipment]:
    """Equipment whose calibration is overdue or due within ``within_days``."""
    today = date.today()
    horizon = today + timedelta(days=within_days)
    stmt = base_select(Equipment).where(
        Equipment.status == EquipmentStatus.ACTIVE,
        Equipment.next_due_date.is_not(None),
    )
    if overdue_only:
        stmt = stmt.where(Equipment.next_due_date < today)
    else:
        stmt = stmt.where(Equipment.next_due_date <= horizon)
    stmt = stmt.order_by(Equipment.next_due_date.asc())
    return db.execute(stmt).scalars().all()


@router.post("/equipment", response_model=EquipmentRead, status_code=status.HTTP_201_CREATED)
def create_equipment(
    body: EquipmentCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.CALIBRATION_WRITE)),
) -> Equipment:
    data = body.model_dump()
    last_cal = data.get("last_calibration_date")
    equipment = Equipment(
        **data,
        asset_tag=numbering.next_number(db, Equipment, "asset_tag", "GAGE"),
        created_by=actor.id,
        updated_by=actor.id,
    )
    if last_cal:
        equipment.next_due_date = last_cal + timedelta(
            days=equipment.calibration_interval_days
        )
    db.add(equipment)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=equipment.id,
        after=equipment,
        **request_context(request),
    )
    db.commit()
    db.refresh(equipment)
    return equipment


@router.get("/equipment/{equipment_id}", response_model=EquipmentRead)
def get_equipment(
    equipment_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.CALIBRATION_READ)),
) -> Equipment:
    return get_or_404(db, Equipment, equipment_id, name="Equipment")


@router.patch("/equipment/{equipment_id}", response_model=EquipmentRead)
def update_equipment(
    equipment_id: int,
    body: EquipmentUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.CALIBRATION_WRITE)),
) -> Equipment:
    equipment = get_or_404(db, Equipment, equipment_id, name="Equipment")
    before = audit.snapshot(equipment)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(equipment, key, value)
    equipment.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=equipment.id,
        before=before,
        after=equipment,
        **request_context(request),
    )
    db.commit()
    db.refresh(equipment)
    return equipment


@router.post(
    "/equipment/{equipment_id}/records",
    response_model=CalibrationRecordRead,
    status_code=status.HTTP_201_CREATED,
)
def record_calibration(
    equipment_id: int,
    body: CalibrationRecordCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.CALIBRATION_WRITE)),
) -> CalibrationRecord:
    equipment = get_or_404(db, Equipment, equipment_id, name="Equipment")

    due = body.due_date or (
        body.calibration_date + timedelta(days=equipment.calibration_interval_days)
    )
    record = CalibrationRecord(
        equipment_id=equipment.id,
        calibration_date=body.calibration_date,
        due_date=due,
        result=body.result,
        certificate_number=body.certificate_number,
        performed_by=body.performed_by,
        calibration_vendor=body.calibration_vendor,
        standard_used=body.standard_used,
        as_found=body.as_found,
        as_left=body.as_left,
        uncertainty=body.uncertainty,
        notes=body.notes,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(record)

    # Roll the equipment's calibration state forward.
    equipment.last_calibration_date = body.calibration_date
    equipment.next_due_date = due
    if body.result == CalibrationResult.FAIL:
        equipment.status = EquipmentStatus.OUT_OF_SERVICE
    equipment.updated_by = actor.id

    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="record_calibration",
        entity_type=ENTITY,
        entity_id=equipment.id,
        after={
            "record_id": record.id,
            "result": record.result.value,
            "next_due_date": due.isoformat(),
        },
        **request_context(request),
    )
    db.commit()
    db.refresh(record)
    return record


@router.get("/equipment/{equipment_id}/records", response_model=list[CalibrationRecordRead])
def list_records(
    equipment_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.CALIBRATION_READ)),
) -> list[CalibrationRecord]:
    get_or_404(db, Equipment, equipment_id, name="Equipment")
    return (
        db.execute(
            select(CalibrationRecord)
            .where(CalibrationRecord.equipment_id == equipment_id)
            .order_by(CalibrationRecord.calibration_date.desc())
        )
        .scalars()
        .all()
    )
