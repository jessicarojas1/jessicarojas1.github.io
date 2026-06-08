"""Customer & contract register endpoints with requirement flow-down.

Reads require supplier:read, writes supplier:write (external-party data is
managed alongside suppliers by supplier-quality).
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.core.exceptions import ConflictError, NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.customer import Contract, ContractRequirement, Customer
from app.schemas.auth import CurrentUser
from app.schemas.customer import (
    ContractCreate,
    ContractList,
    ContractRead,
    ContractUpdate,
    CustomerCreate,
    CustomerRead,
    CustomerUpdate,
    RequirementCreate,
    RequirementRead,
    RequirementUpdate,
)

router = APIRouter(prefix="/customers", tags=["customers"])

_READ = require_permission(Permission.SUPPLIER_READ)
_WRITE = require_permission(Permission.SUPPLIER_WRITE)


def _customer_dict(c: Customer) -> dict:
    return {
        "id": c.id,
        "code": c.code,
        "name": c.name,
        "cage_code": c.cage_code,
        "country": c.country,
        "contact_name": c.contact_name,
        "contact_email": c.contact_email,
        "status": c.status,
        "notes": c.notes,
        "contract_count": sum(1 for k in c.contracts if not k.is_deleted),
    }


# ---- Customers ----
@router.get("", response_model=list[CustomerRead])
def list_customers(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    rows = (
        db.execute(
            select(Customer)
            .options(selectinload(Customer.contracts))
            .where(Customer.is_deleted.is_(False))
            .order_by(Customer.code)
        )
        .scalars()
        .all()
    )
    return [_customer_dict(c) for c in rows]


@router.post("", response_model=CustomerRead, status_code=status.HTTP_201_CREATED)
def create_customer(
    body: CustomerCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    if db.execute(select(Customer).where(Customer.code == body.code)).scalar_one_or_none():
        raise ConflictError(f"A customer with code '{body.code}' already exists.")
    c = Customer(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(c)
    db.commit()
    db.refresh(c)
    return _customer_dict(c)


def _get_customer(db: Session, customer_id: int) -> Customer:
    c = db.get(Customer, customer_id)
    if c is None or c.is_deleted:
        raise NotFoundError(f"Customer {customer_id} not found.")
    return c


@router.patch("/{customer_id}", response_model=CustomerRead)
def update_customer(
    customer_id: int,
    body: CustomerUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    c = _get_customer(db, customer_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(c, key, value)
    c.updated_by = actor.id
    db.commit()
    db.refresh(c)
    return _customer_dict(c)


@router.delete("/{customer_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_customer(
    customer_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get_customer(db, customer_id).soft_delete(actor.id)
    db.commit()


# ---- Contracts ----
def _contract_read(c: Contract) -> dict:
    return {
        "id": c.id,
        "contract_number": c.contract_number,
        "customer_id": c.customer_id,
        "title": c.title,
        "dpas_rating": c.dpas_rating,
        "itar_controlled": c.itar_controlled,
        "status": c.status,
        "start_date": c.start_date,
        "end_date": c.end_date,
        "dfars_clauses": c.dfars_clauses,
        "value": float(c.value) if c.value is not None else None,
        "notes": c.notes,
        "requirements": list(c.requirements),
    }


@router.get("/contracts", response_model=list[ContractList])
def list_contracts(
    db: Session = Depends(get_db),
    customer_id: int | None = Query(None),
    _: CurrentUser = Depends(_READ),
) -> list[Contract]:
    stmt = select(Contract).where(Contract.is_deleted.is_(False))
    if customer_id is not None:
        stmt = stmt.where(Contract.customer_id == customer_id)
    stmt = stmt.order_by(Contract.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("/contracts", response_model=ContractRead, status_code=status.HTTP_201_CREATED)
def create_contract(
    body: ContractCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    _get_customer(db, body.customer_id)
    if db.execute(
        select(Contract).where(Contract.contract_number == body.contract_number)
    ).scalar_one_or_none():
        raise ConflictError(f"Contract '{body.contract_number}' already exists.")
    c = Contract(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(c)
    db.commit()
    db.refresh(c)
    return _contract_read(c)


def _get_contract(db: Session, contract_id: int) -> Contract:
    c = db.get(Contract, contract_id)
    if c is None or c.is_deleted:
        raise NotFoundError(f"Contract {contract_id} not found.")
    return c


@router.get("/contracts/{contract_id}", response_model=ContractRead)
def get_contract(
    contract_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _contract_read(_get_contract(db, contract_id))


@router.patch("/contracts/{contract_id}", response_model=ContractRead)
def update_contract(
    contract_id: int,
    body: ContractUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    c = _get_contract(db, contract_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(c, key, value)
    c.updated_by = actor.id
    db.commit()
    db.refresh(c)
    return _contract_read(c)


@router.delete("/contracts/{contract_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_contract(
    contract_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get_contract(db, contract_id).soft_delete(actor.id)
    db.commit()


# ---- Flow-down requirements ----
@router.post(
    "/contracts/{contract_id}/requirements",
    response_model=RequirementRead,
    status_code=status.HTTP_201_CREATED,
)
def add_requirement(
    contract_id: int,
    body: RequirementCreate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> ContractRequirement:
    _get_contract(db, contract_id)
    req = ContractRequirement(contract_id=contract_id, **body.model_dump())
    db.add(req)
    db.commit()
    db.refresh(req)
    return req


@router.patch("/requirements/{requirement_id}", response_model=RequirementRead)
def update_requirement(
    requirement_id: int,
    body: RequirementUpdate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> ContractRequirement:
    req = db.get(ContractRequirement, requirement_id)
    if req is None:
        raise NotFoundError(f"Requirement {requirement_id} not found.")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(req, key, value)
    db.commit()
    db.refresh(req)
    return req


@router.delete("/requirements/{requirement_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_requirement(
    requirement_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> None:
    req = db.get(ContractRequirement, requirement_id)
    if req is None:
        raise NotFoundError(f"Requirement {requirement_id} not found.")
    db.delete(req)
    db.commit()
