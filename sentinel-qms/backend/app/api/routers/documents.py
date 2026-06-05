"""Document control endpoints: CRUD + revisions + approval workflow + effectivity."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError, WorkflowError
from app.core.rbac import Permission, require_permission
from app.models.document import (
    Document,
    DocumentApproval,
    DocumentRevision,
    DocumentStatus,
    DocumentType,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.document import (
    ApprovalDecision,
    ApprovalRead,
    DocumentCreate,
    DocumentList,
    DocumentRead,
    DocumentUpdate,
    RevisionCreate,
    RevisionRead,
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
from app.services.signatures import create_signature

router = APIRouter(prefix="/documents", tags=["documents"])

ENTITY = "document"


@router.get("", response_model=Page[DocumentList])
def list_documents(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: DocumentStatus | None = Query(None, alias="status"),
    doc_type: DocumentType | None = Query(None),
    search: str | None = Query(None),
    _: CurrentUser = Depends(require_permission(Permission.DOCUMENT_READ)),
) -> Page[DocumentList]:
    stmt = base_select(Document)
    if status_filter:
        stmt = stmt.where(Document.status == status_filter)
    if doc_type:
        stmt = stmt.where(Document.doc_type == doc_type)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(
            Document.document_number.ilike(like) | Document.title.ilike(like)
        )
    stmt = apply_sort(stmt, Document, sort)
    items, total = paginate(db, stmt, Document, pagination)
    return Page[DocumentList](items=items, **page_meta(total, pagination))


@router.post("", response_model=DocumentRead, status_code=status.HTTP_201_CREATED)
def create_document(
    body: DocumentCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.DOCUMENT_WRITE)),
) -> Document:
    doc = Document(
        **body.model_dump(),
        document_number=numbering.next_number(db, Document, "document_number", "DOC"),
        status=DocumentStatus.DRAFT,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(doc)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=doc.id,
        after=doc,
        **request_context(request),
    )
    db.commit()
    db.refresh(doc)
    return doc


@router.get("/{doc_id}", response_model=DocumentRead)
def get_document(
    doc_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DOCUMENT_READ)),
) -> Document:
    return get_or_404(db, Document, doc_id, name="Document")


@router.patch("/{doc_id}", response_model=DocumentRead)
def update_document(
    doc_id: int,
    body: DocumentUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.DOCUMENT_WRITE)),
) -> Document:
    doc = get_or_404(db, Document, doc_id, name="Document")
    if doc.status == DocumentStatus.OBSOLETE:
        raise WorkflowError("Obsolete documents cannot be edited.")
    before = audit.snapshot(doc)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(doc, key, value)
    doc.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=doc.id,
        before=before,
        after=doc,
        **request_context(request),
    )
    db.commit()
    db.refresh(doc)
    return doc


@router.post(
    "/{doc_id}/revisions", response_model=RevisionRead, status_code=status.HTTP_201_CREATED
)
def create_revision(
    doc_id: int,
    body: RevisionCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.DOCUMENT_WRITE)),
) -> DocumentRevision:
    doc = get_or_404(db, Document, doc_id, name="Document")
    revision = DocumentRevision(
        document_id=doc.id,
        revision=body.revision,
        change_summary=body.change_summary,
        attachment_id=body.attachment_id,
        status=DocumentStatus.IN_REVIEW,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(revision)
    doc.status = DocumentStatus.IN_REVIEW
    doc.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create_revision",
        entity_type=ENTITY,
        entity_id=doc.id,
        after={"revision": revision.revision},
        **request_context(request),
    )
    db.commit()
    db.refresh(revision)
    return revision


@router.post("/revisions/{revision_id}/approve", response_model=ApprovalRead)
def approve_revision(
    revision_id: int,
    body: ApprovalDecision,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.DOCUMENT_APPROVE)),
) -> DocumentApproval:
    """Approve/reject a revision with an e-signature; makes it effective on approval."""
    revision = db.get(DocumentRevision, revision_id)
    if revision is None:
        raise NotFoundError(f"Revision {revision_id} not found.")
    if revision.status not in (DocumentStatus.IN_REVIEW, DocumentStatus.DRAFT):
        raise WorkflowError("Only draft or in-review revisions can be approved.")

    sig = create_signature(
        db,
        actor=actor,
        entity_type="document_revision",
        entity_id=revision.id,
        payload=body.signature,
    )
    approval = DocumentApproval(
        revision_id=revision.id,
        approver_id=actor.id,
        approver_name=actor.full_name,
        decision=body.decision,
        comments=body.comments,
        signature_id=sig.id,
    )
    db.add(approval)

    doc = db.get(Document, revision.document_id)
    if body.decision == "approved":
        revision.status = DocumentStatus.EFFECTIVE
        revision.effective_date = body.effective_date
        # Supersede the prior effective revision.
        doc.status = DocumentStatus.EFFECTIVE
        doc.current_revision = revision.revision
        doc.effective_date = body.effective_date
        for other in doc.revisions:
            if other.id != revision.id and other.status == DocumentStatus.EFFECTIVE:
                other.status = DocumentStatus.OBSOLETE
    else:
        revision.status = DocumentStatus.DRAFT
        doc.status = DocumentStatus.DRAFT
    doc.updated_by = actor.id

    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="approve_revision",
        entity_type=ENTITY,
        entity_id=doc.id,
        after={
            "revision": revision.revision,
            "decision": body.decision,
            "signature_id": sig.id,
        },
        **request_context(request),
    )
    db.commit()
    db.refresh(approval)
    return approval


@router.post("/{doc_id}/obsolete", response_model=DocumentRead)
def obsolete_document(
    doc_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.DOCUMENT_APPROVE)),
) -> Document:
    doc = get_or_404(db, Document, doc_id, name="Document")
    doc.status = DocumentStatus.OBSOLETE
    doc.updated_by = actor.id
    for rev in doc.revisions:
        if rev.status == DocumentStatus.EFFECTIVE:
            rev.status = DocumentStatus.OBSOLETE
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="obsolete",
        entity_type=ENTITY,
        entity_id=doc.id,
        **request_context(request),
    )
    db.commit()
    db.refresh(doc)
    return doc


@router.delete("/{doc_id}", response_model=DocumentRead)
def soft_delete_document(
    doc_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.DOCUMENT_WRITE)),
) -> Document:
    doc = get_or_404(db, Document, doc_id, name="Document")
    doc.soft_delete(actor.id)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="soft_delete",
        entity_type=ENTITY,
        entity_id=doc.id,
        **request_context(request),
    )
    db.commit()
    db.refresh(doc)
    return doc
