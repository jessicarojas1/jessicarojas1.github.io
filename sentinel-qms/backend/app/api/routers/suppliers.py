"""Supplier endpoints: CRUD + SCAR + ASL + ratings/scorecard."""
from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal

from fastapi import APIRouter, Depends, File, Query, Request, UploadFile, status
from sqlalchemy import select
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
from app.core.exceptions import NotFoundError
from app.models.supplier import (
    ApprovedSupplierListEntry,
    ScarStatus,
    Supplier,
    SupplierRating,
    SupplierScar,
    SupplierStatus,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import ImportResult, Page
from app.schemas.supplier import (
    AslEntryCreate,
    AslEntryRead,
    RatingCreate,
    RatingRead,
    ScarCreate,
    ScarRead,
    ScarUpdate,
    SupplierCreate,
    SupplierList,
    SupplierRead,
    SupplierUpdate,
)
from app.services import csv_import, numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)

router = APIRouter(prefix="/suppliers", tags=["suppliers"])

ENTITY = "supplier"

# Importable columns: required + common optional fields from SupplierCreate.
_IMPORT_COLUMNS = [
    "name",
    "status",
    "cage_code",
    "duns_number",
    "certification",
    "cert_expiry",
    "contact_name",
    "contact_email",
    "country",
    "notes",
]
_IMPORT_EXAMPLE = [
    "Acme Aerospace Inc.",
    "approved",
    "1A2B3",
    "123456789",
    "AS9100D",
    "2027-12-31",
    "Jane Doe",
    "jane@acme.example",
    "USA",
    "Primary fastener supplier",
]


def _grade_for(score: float) -> str:
    if score >= 95:
        return "A"
    if score >= 85:
        return "B"
    if score >= 70:
        return "C"
    return "D"


@router.get("", response_model=Page[SupplierList])
def list_suppliers(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: SupplierStatus | None = Query(None, alias="status"),
    search: str | None = Query(None),
    _: CurrentUser = Depends(require_page("suppliers", "view")),
) -> Page[SupplierList]:
    stmt = base_select(Supplier)
    if status_filter:
        stmt = stmt.where(Supplier.status == status_filter)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(
            Supplier.name.ilike(like) | Supplier.supplier_code.ilike(like)
        )
    stmt = apply_sort(stmt, Supplier, sort)
    items, total = paginate(db, stmt, Supplier, pagination)
    return Page[SupplierList](items=items, **page_meta(total, pagination))


@router.post("", response_model=SupplierRead, status_code=status.HTTP_201_CREATED)
def create_supplier(
    body: SupplierCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.create")),
) -> Supplier:
    supplier = Supplier(
        **body.model_dump(),
        supplier_code=numbering.next_number(db, Supplier, "supplier_code", "SUP"),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(supplier)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=supplier.id,
        after=supplier,
        **request_context(request),
    )
    db.commit()
    db.refresh(supplier)
    return supplier


# ── Bulk CSV import ───────────────────────────────────────────────────────────
# Declared before /{supplier_id} so the literal paths are not shadowed.


@router.get("/import/template")
def supplier_import_template(
    _: CurrentUser = Depends(require_page("suppliers", "view")),
):
    return csv_import.template_response(
        "suppliers_import_template.csv", _IMPORT_COLUMNS, _IMPORT_EXAMPLE
    )


@router.post("/import", response_model=ImportResult)
def supplier_import(
    request: Request,
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.create")),
) -> ImportResult:
    def build_and_insert(row: dict[str, str]) -> None:
        data = {col: csv_import.clean(row.get(col)) for col in _IMPORT_COLUMNS}
        body = SupplierCreate(**{k: v for k, v in data.items() if v is not None})
        supplier = Supplier(
            **body.model_dump(),
            supplier_code=numbering.next_number(db, Supplier, "supplier_code", "SUP"),
            created_by=actor.id,
            updated_by=actor.id,
        )
        db.add(supplier)
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


@router.get("/{supplier_id}", response_model=SupplierRead)
def get_supplier(
    supplier_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("suppliers", "view")),
) -> Supplier:
    return get_or_404(db, Supplier, supplier_id, name="Supplier")


@router.patch("/{supplier_id}", response_model=SupplierRead)
def update_supplier(
    supplier_id: int,
    body: SupplierUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.edit")),
) -> Supplier:
    supplier = get_or_404(db, Supplier, supplier_id, name="Supplier")
    before = audit.snapshot(supplier)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(supplier, key, value)
    supplier.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=supplier.id,
        before=before,
        after=supplier,
        **request_context(request),
    )
    db.commit()
    db.refresh(supplier)
    return supplier


@router.delete("/{supplier_id}", response_model=SupplierRead)
def soft_delete_supplier(
    supplier_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.edit")),
) -> Supplier:
    supplier = get_or_404(db, Supplier, supplier_id, name="Supplier")
    supplier.soft_delete(actor.id)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="soft_delete",
        entity_type=ENTITY,
        entity_id=supplier.id,
        **request_context(request),
    )
    db.commit()
    db.refresh(supplier)
    return supplier


# ── SCAR ────────────────────────────────────────────────────────────────────


@router.get("/{supplier_id}/scars", response_model=list[ScarRead])
def list_scars(
    supplier_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("suppliers", "view")),
) -> list[SupplierScar]:
    get_or_404(db, Supplier, supplier_id, name="Supplier")
    return (
        db.execute(
            select(SupplierScar)
            .where(SupplierScar.supplier_id == supplier_id)
            .order_by(SupplierScar.id.desc())
        )
        .scalars()
        .all()
    )


@router.post(
    "/{supplier_id}/scars", response_model=ScarRead, status_code=status.HTTP_201_CREATED
)
def create_scar(
    supplier_id: int,
    body: ScarCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.scar")),
) -> SupplierScar:
    get_or_404(db, Supplier, supplier_id, name="Supplier")
    scar = SupplierScar(
        supplier_id=supplier_id,
        **body.model_dump(),
        scar_number=numbering.next_number(db, SupplierScar, "scar_number", "SCAR"),
        status=ScarStatus.ISSUED,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(scar)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create_scar",
        entity_type="supplier_scar",
        entity_id=scar.id,
        after=scar,
        **request_context(request),
    )
    db.commit()
    db.refresh(scar)
    return scar


@router.patch("/scars/{scar_id}", response_model=ScarRead)
def update_scar(
    scar_id: int,
    body: ScarUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.scar")),
) -> SupplierScar:
    scar = db.get(SupplierScar, scar_id)
    if scar is None:
        raise NotFoundError(f"SCAR {scar_id} not found.")
    before = audit.snapshot(scar)
    data = body.model_dump(exclude_unset=True)
    if data.get("status") == ScarStatus.CLOSED and scar.closed_at is None:
        scar.closed_at = datetime.now(timezone.utc)
    for key, value in data.items():
        setattr(scar, key, value)
    scar.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update_scar",
        entity_type="supplier_scar",
        entity_id=scar.id,
        before=before,
        after=scar,
        **request_context(request),
    )
    db.commit()
    db.refresh(scar)
    return scar


# ── Approved Supplier List ──────────────────────────────────────────────────


@router.post(
    "/{supplier_id}/asl", response_model=AslEntryRead, status_code=status.HTTP_201_CREATED
)
def add_asl_entry(
    supplier_id: int,
    body: AslEntryCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.edit")),
) -> ApprovedSupplierListEntry:
    get_or_404(db, Supplier, supplier_id, name="Supplier")
    entry = ApprovedSupplierListEntry(
        supplier_id=supplier_id,
        **body.model_dump(),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(entry)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="add_asl",
        entity_type="asl_entry",
        entity_id=entry.id,
        after=entry,
        **request_context(request),
    )
    db.commit()
    db.refresh(entry)
    return entry


@router.get("/{supplier_id}/asl", response_model=list[AslEntryRead])
def list_asl_entries(
    supplier_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("suppliers", "view")),
) -> list[ApprovedSupplierListEntry]:
    get_or_404(db, Supplier, supplier_id, name="Supplier")
    return (
        db.execute(
            select(ApprovedSupplierListEntry).where(
                ApprovedSupplierListEntry.supplier_id == supplier_id
            )
        )
        .scalars()
        .all()
    )


# ── Ratings / scorecard ─────────────────────────────────────────────────────


@router.post(
    "/{supplier_id}/ratings", response_model=RatingRead, status_code=status.HTTP_201_CREATED
)
def add_rating(
    supplier_id: int,
    body: RatingCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("suppliers.rate")),
) -> SupplierRating:
    get_or_404(db, Supplier, supplier_id, name="Supplier")

    # Composite = 60% quality + 40% on-time delivery (weights are a policy choice).
    composite: Decimal | None = None
    grade: str | None = None
    if body.quality_score is not None and body.on_time_delivery is not None:
        composite = (
            Decimal("0.6") * body.quality_score + Decimal("0.4") * body.on_time_delivery
        ).quantize(Decimal("0.01"))
        grade = _grade_for(float(composite))

    rating = SupplierRating(
        supplier_id=supplier_id,
        period=body.period,
        quality_score=body.quality_score,
        on_time_delivery=body.on_time_delivery,
        ppm_defects=body.ppm_defects,
        composite_score=composite,
        grade=grade,
        notes=body.notes,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(rating)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="add_rating",
        entity_type="supplier_rating",
        entity_id=rating.id,
        after=rating,
        **request_context(request),
    )
    db.commit()
    db.refresh(rating)
    return rating


@router.get("/{supplier_id}/ratings", response_model=list[RatingRead])
def list_ratings(
    supplier_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("suppliers", "view")),
) -> list[SupplierRating]:
    get_or_404(db, Supplier, supplier_id, name="Supplier")
    return (
        db.execute(
            select(SupplierRating)
            .where(SupplierRating.supplier_id == supplier_id)
            .order_by(SupplierRating.id.desc())
        )
        .scalars()
        .all()
    )
