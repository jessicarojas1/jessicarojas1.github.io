"""Record shares — a "Shared with Me" inbox of read-only record pointers.

All endpoints require authentication. A share *delegates* the sharer's own read
access: only a user who can view a record's module may share it, and the share
then grants the named recipient read-only access to that single record (e.g. so
a Customer with no module access can view exactly what was shared with them).
Sharing never exposes anything publicly and never lets a user share a record
they could not themselves view.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Response, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core.database import get_db
from app.core.exceptions import NotFoundError, PermissionDeniedError, ValidationAppError
from app.core.permissions import effective_levels, level_at_least
from app.models.apqp import ApqpProject
from app.models.audit_mgmt import Audit
from app.models.capa import Capa
from app.models.complaint import Complaint
from app.models.nonconformance import Nonconformance
from app.models.record_share import RecordShare
from app.models.supplier import Supplier
from app.models.user import User
from app.schemas.auth import CurrentUser
from app.schemas.record_share import ShareCreate, ShareRead
from app.services import pdf

router = APIRouter(prefix="/shares", tags=["shares"])

# entity_type -> the page whose "view" level authorizes sharing that record.
# Sharing is a delegation of one's own read access, so a user may only share a
# record they can themselves view. Unmapped types are not shareable (fail-closed)
# — this is what keeps a limited/Customer account from self-issuing a share to
# exfiltrate a record it has no module access to.
_SHARE_VIEW_PAGE: dict[str, str] = {
    "nonconformance": "nonconformances",
    "capa": "capa",
    "document": "documents",
    "audit": "audits",
    "complaint": "complaints",
    "supplier": "suppliers",
    "risk": "risks",
    "change_order": "changes",
    "inspection": "inspections",
    "management_review": "mgmt_reviews",
    "apqp_project": "inspections",
}

# Shared records that can be rendered as a branded PDF for read-only viewing.
# (model, renderer, filename builder)
_PDF_RENDERERS = {
    "nonconformance": (Nonconformance, pdf.render_ncr_pdf, lambda o: o.ncr_number),
    "capa": (Capa, pdf.render_capa_pdf, lambda o: o.capa_number),
    "audit": (Audit, pdf.render_audit_pdf, lambda o: o.audit_number),
    "supplier": (Supplier, pdf.render_supplier_pdf, lambda o: o.supplier_code),
    "complaint": (Complaint, pdf.render_complaint_pdf, lambda o: o.complaint_number),
    "apqp_project": (ApqpProject, pdf.render_psw_pdf, lambda o: f"{o.project_number}-PSW"),
}


@router.get("/mine", response_model=list[ShareRead])
def list_my_shares(
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> list[RecordShare]:
    """Records shared with the current user, newest first."""
    stmt = (
        select(RecordShare)
        .where(RecordShare.shared_with_user_id == actor.id)
        .order_by(RecordShare.id.desc())
    )
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=ShareRead, status_code=status.HTTP_201_CREATED)
def create_share(
    body: ShareCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> RecordShare:
    # A share delegates the sharer's own read access — so the sharer must be able
    # to view the record's module. This is the authorization gate for the whole
    # feature: without it, any authenticated user (incl. a Customer with no module
    # access) could self-issue a share and read the record's PDF.
    page_key = _SHARE_VIEW_PAGE.get(body.entity_type)
    if page_key is None:
        raise ValidationAppError(f"Records of type '{body.entity_type}' cannot be shared.")
    levels = effective_levels(db, actor)
    if not level_at_least(levels.get(page_key, "none"), "view"):
        raise PermissionDeniedError("You can only share records you have permission to view.")

    recipient = db.get(User, body.shared_with_user_id)
    if recipient is None or not recipient.is_active:
        raise NotFoundError(f"User {body.shared_with_user_id} not found.")
    share = RecordShare(
        **body.model_dump(),
        shared_by_user_id=actor.id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(share)
    db.commit()
    db.refresh(share)
    return share


@router.get("/{share_id}/pdf")
def share_pdf(
    share_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> Response:
    """Download the shared record as a branded read-only PDF.

    Authorized solely by the share: only the recipient (or original sharer) may
    fetch it, and only for record types with a PDF renderer. This is what lets a
    limited/external account read a shared record without any module access.
    """
    share = db.get(RecordShare, share_id)
    if share is None or actor.id not in (share.shared_with_user_id, share.shared_by_user_id):
        raise NotFoundError(f"Share {share_id} not found.")
    spec = _PDF_RENDERERS.get(share.entity_type)
    if spec is None:
        raise NotFoundError("No PDF is available for this record type.")
    model, render, filename = spec
    obj = db.get(model, int(share.entity_id))
    if obj is None or getattr(obj, "is_deleted", False):
        raise NotFoundError("Shared record not found.")
    data = render(db, obj)
    return Response(
        content=data,
        media_type="application/pdf",
        headers={"Content-Disposition": f'attachment; filename="{filename(obj)}.pdf"'},
    )


@router.delete("/{share_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_share(
    share_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> None:
    """Recipient or the original sharer may remove a share."""
    share = db.get(RecordShare, share_id)
    if share is None or actor.id not in (share.shared_with_user_id, share.shared_by_user_id):
        raise NotFoundError(f"Share {share_id} not found.")
    db.delete(share)
    db.commit()
