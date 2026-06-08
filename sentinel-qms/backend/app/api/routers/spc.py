"""Key Characteristics & SPC endpoints (process capability + control data).

Reads require inspection:read, writes inspection:write.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, Role, require_permission
from app.models.spc import KeyCharacteristic, Measurement
from app.models.user import User
from app.schemas.auth import CurrentUser
from app.schemas.spc import (
    KcCreate,
    KcList,
    KcRead,
    KcUpdate,
    MeasurementCreate,
    MeasurementRead,
)
from app.services import notifications, numbering
from app.services.spc import capability, detect_violations, new_violations

router = APIRouter(prefix="/key-characteristics", tags=["spc"])

_READ = require_permission(Permission.INSPECTION_READ)
_WRITE = require_permission(Permission.INSPECTION_WRITE)


def _cap(kc: KeyCharacteristic) -> dict:
    return capability([m.value for m in kc.measurements], kc.usl, kc.lsl)


def _owner_name(db: Session, owner_id: int | None) -> str | None:
    if owner_id is None:
        return None
    owner = db.get(User, owner_id)
    return owner.full_name if owner is not None else None


def _measurement_values(db: Session, kc_id: int) -> list[float]:
    return list(
        db.execute(
            select(Measurement.value)
            .where(Measurement.kc_id == kc_id)
            .order_by(Measurement.id)
        ).scalars()
    )


def _notify_violations(db: Session, kc: KeyCharacteristic, flagged: list[dict]) -> None:
    """Notify the KC owner (or quality team, if unowned) of new SPC violations."""
    descriptions = "; ".join(sorted({v["description"] for v in flagged}))
    title = f"SPC violation: {kc.kc_number} {kc.part_number}"
    body = f"{kc.characteristic} — {descriptions}"
    if kc.owner_id is not None:
        notifications.notify_user(
            db,
            user_id=kc.owner_id,
            title=title,
            body=body,
            category="spc",
            entity_type="key_characteristic",
            entity_id=kc.id,
        )
    else:
        notifications.notify_roles(
            db,
            roles=[Role.QUALITY_MANAGER, Role.QUALITY_ENGINEER],
            title=title,
            body=body,
            category="spc",
            entity_type="key_characteristic",
            entity_id=kc.id,
        )


def _to_list(kc: KeyCharacteristic, db: Session | None = None) -> dict:
    return {
        "id": kc.id,
        "kc_number": kc.kc_number,
        "part_number": kc.part_number,
        "characteristic": kc.characteristic,
        "nominal": kc.nominal,
        "usl": kc.usl,
        "lsl": kc.lsl,
        "unit": kc.unit,
        "kc_class": kc.kc_class,
        "owner_id": kc.owner_id,
        "owner_name": _owner_name(db, kc.owner_id) if db is not None else None,
        "capability": _cap(kc),
    }


def _to_read(kc: KeyCharacteristic, db: Session) -> dict:
    return {
        **_to_list(kc, db),
        "notes": kc.notes,
        "measurements": list(kc.measurements),
        "violations": detect_violations([m.value for m in kc.measurements]),
    }


def _get(db: Session, kc_id: int) -> KeyCharacteristic:
    kc = db.get(KeyCharacteristic, kc_id)
    if kc is None or kc.is_deleted:
        raise NotFoundError(f"Key characteristic {kc_id} not found.")
    return kc


@router.get("", response_model=list[KcList])
def list_kcs(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    rows = (
        db.execute(
            select(KeyCharacteristic)
            .options(selectinload(KeyCharacteristic.measurements))
            .where(KeyCharacteristic.is_deleted.is_(False))
            .order_by(KeyCharacteristic.id.desc())
        )
        .scalars()
        .all()
    )
    return [_to_list(kc, db) for kc in rows]


@router.post("", response_model=KcRead, status_code=status.HTTP_201_CREATED)
def create_kc(
    body: KcCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    kc = KeyCharacteristic(
        **body.model_dump(),
        kc_number=numbering.next_number(db, KeyCharacteristic, "kc_number", "KC"),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(kc)
    db.commit()
    db.refresh(kc)
    return _to_read(kc, db)


@router.get("/{kc_id}", response_model=KcRead)
def get_kc(
    kc_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get(db, kc_id), db)


@router.patch("/{kc_id}", response_model=KcRead)
def update_kc(
    kc_id: int,
    body: KcUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    kc = _get(db, kc_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(kc, key, value)
    kc.updated_by = actor.id
    db.commit()
    db.refresh(kc)
    return _to_read(kc, db)


@router.delete("/{kc_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_kc(
    kc_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get(db, kc_id).soft_delete(actor.id)
    db.commit()


@router.post(
    "/{kc_id}/measurements", response_model=MeasurementRead, status_code=status.HTTP_201_CREATED
)
def add_measurement(
    kc_id: int,
    body: MeasurementCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> Measurement:
    kc = _get(db, kc_id)
    before = detect_violations(_measurement_values(db, kc_id))
    m = Measurement(kc_id=kc_id, **body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(m)
    db.commit()
    db.refresh(m)

    # A new measurement can flag the point itself or shift the limits enough to
    # flag a historical point — notify the owner (or quality team) either way.
    flagged = new_violations(before, detect_violations(_measurement_values(db, kc_id)))
    if flagged:
        _notify_violations(db, kc, flagged)
        db.commit()
    return m


@router.delete("/measurements/{measurement_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_measurement(
    measurement_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> None:
    m = db.get(Measurement, measurement_id)
    if m is None:
        raise NotFoundError(f"Measurement {measurement_id} not found.")
    db.delete(m)
    db.commit()
