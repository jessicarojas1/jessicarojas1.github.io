"""Document control endpoints: CRUD + revisions + approval workflow + transitions."""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Depends, Query, Request, status
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
from app.core.exceptions import NotFoundError, WorkflowError
from app.models.document import (
    Document,
    DocumentApproval,
    DocumentRevision,
    DocumentStatus,
    DocumentType,
    next_stage,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.document import (
    CONTENT_FIELDS,
    ApprovalDecision,
    ApprovalRead,
    DocumentCreate,
    DocumentList,
    DocumentRead,
    DocumentUpdate,
    RevisionCreate,
    RevisionRead,
    TransitionRequest,
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
    _: CurrentUser = Depends(require_page("documents", "view")),
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
    actor: CurrentUser = Depends(require_page("documents", "edit")),
) -> Document:
    doc = Document(
        **body.model_dump(),
        document_number=numbering.next_number(db, Document, "document_number", "DOC"),
        status=DocumentStatus.CONCEPT,
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
    _: CurrentUser = Depends(require_page("documents", "view")),
) -> Document:
    return get_or_404(db, Document, doc_id, name="Document")


@router.patch("/{doc_id}", response_model=DocumentRead)
def update_document(
    doc_id: int,
    body: DocumentUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("documents", "edit")),
) -> Document:
    doc = get_or_404(db, Document, doc_id, name="Document")
    if doc.status == DocumentStatus.OBSOLETE:
        raise WorkflowError("Obsolete documents cannot be edited.")
    before = audit.snapshot(doc)
    changes = body.model_dump(exclude_unset=True)
    for key, value in changes.items():
        setattr(doc, key, value)
    # Editing the content of an APPROVED document invalidates the approval and
    # sends it back to Work In Progress for re-review.
    reverted = False
    if doc.status == DocumentStatus.APPROVED and CONTENT_FIELDS.intersection(changes):
        doc.status = DocumentStatus.WORK_IN_PROGRESS
        doc.approved_by = None
        reverted = True
    doc.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update_reverted_to_wip" if reverted else "update",
        entity_type=ENTITY,
        entity_id=doc.id,
        before=before,
        after=doc,
        **request_context(request),
    )
    db.commit()
    db.refresh(doc)
    return doc


@router.post("/{doc_id}/transition", response_model=DocumentRead)
def transition_document(
    doc_id: int,
    body: TransitionRequest,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("documents", "edit")),
) -> Document:
    """Move a document through its approval workflow.

    Legal linear path: concept -> work_in_progress -> peer_review -> qa_review
    -> approved.  Actions:
      * ``advance``  — move to the next stage on the linear path.
      * ``approve``  — set status APPROVED, record approver + last_review_date.
      * ``revise``   — return an APPROVED doc to WORK_IN_PROGRESS, clear approver.
      * ``obsolete`` — terminal: set status OBSOLETE.
    Or pass ``to_status`` for an explicit (still validated) target.
    """
    doc = get_or_404(db, Document, doc_id, name="Document")
    before = audit.snapshot(doc)
    current = doc.status

    # Resolve the requested target status from action or explicit to_status.
    action = body.action
    target: DocumentStatus | None = body.to_status

    if action == "obsolete":
        target = DocumentStatus.OBSOLETE
    elif action == "approve":
        target = DocumentStatus.APPROVED
    elif action == "revise":
        target = DocumentStatus.WORK_IN_PROGRESS
    elif action == "advance":
        nxt = next_stage(current)
        if nxt is None:
            raise WorkflowError(
                f"Document cannot advance from '{current.value}'."
            )
        target = nxt

    if target is None:
        raise WorkflowError("A workflow 'action' or 'to_status' is required.")

    if current == DocumentStatus.OBSOLETE:
        raise WorkflowError("Obsolete documents are terminal and cannot transition.")

    # Validate the transition.
    if target == DocumentStatus.OBSOLETE:
        pass  # any active doc may be made obsolete
    elif action == "revise" or (
        current == DocumentStatus.APPROVED and target == DocumentStatus.WORK_IN_PROGRESS
    ):
        if current != DocumentStatus.APPROVED:
            raise WorkflowError("Only an approved document can be revised.")
        target = DocumentStatus.WORK_IN_PROGRESS
    elif target == DocumentStatus.APPROVED:
        if current != DocumentStatus.QA_REVIEW:
            raise WorkflowError(
                "A document must clear QA Review before it can be approved."
            )
    else:
        # Linear forward step only.
        if next_stage(current) != target:
            raise WorkflowError(
                f"Illegal transition: '{current.value}' -> '{target.value}'."
            )

    doc.status = target
    if target == DocumentStatus.APPROVED:
        doc.approved_by = actor.id
        doc.last_review_date = date.today()
    elif target == DocumentStatus.WORK_IN_PROGRESS:
        doc.approved_by = None
    elif target == DocumentStatus.OBSOLETE:
        for rev in doc.revisions:
            if rev.status == DocumentStatus.APPROVED:
                rev.status = DocumentStatus.OBSOLETE
    doc.updated_by = actor.id

    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action=f"transition:{action or target.value}",
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
    actor: CurrentUser = Depends(require_page("documents", "edit")),
) -> DocumentRevision:
    doc = get_or_404(db, Document, doc_id, name="Document")
    revision = DocumentRevision(
        document_id=doc.id,
        revision=body.revision,
        change_summary=body.change_summary,
        attachment_id=body.attachment_id,
        status=DocumentStatus.PEER_REVIEW,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(revision)
    doc.status = DocumentStatus.PEER_REVIEW
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


@router.post(
    "/revisions/{revision_id}/approve",
    response_model=ApprovalRead,
    dependencies=[Depends(require_perm("documents.approve"))],
)
def approve_revision(
    revision_id: int,
    body: ApprovalDecision,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("documents", "edit")),
) -> DocumentApproval:
    """Approve/reject a revision with an e-signature; makes it effective on approval."""
    revision = db.get(DocumentRevision, revision_id)
    if revision is None:
        raise NotFoundError(f"Revision {revision_id} not found.")
    if revision.status in (DocumentStatus.APPROVED, DocumentStatus.OBSOLETE):
        raise WorkflowError("This revision has already been finalized.")

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
        revision.status = DocumentStatus.APPROVED
        revision.effective_date = body.effective_date
        doc.status = DocumentStatus.APPROVED
        doc.current_revision = revision.revision
        doc.effective_date = body.effective_date
        doc.approved_by = actor.id
        doc.last_review_date = date.today()
        # Supersede the prior approved revision.
        for other in doc.revisions:
            if other.id != revision.id and other.status == DocumentStatus.APPROVED:
                other.status = DocumentStatus.OBSOLETE
    else:
        revision.status = DocumentStatus.WORK_IN_PROGRESS
        doc.status = DocumentStatus.WORK_IN_PROGRESS
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
    actor: CurrentUser = Depends(require_page("documents", "edit")),
) -> Document:
    doc = get_or_404(db, Document, doc_id, name="Document")
    doc.status = DocumentStatus.OBSOLETE
    doc.updated_by = actor.id
    for rev in doc.revisions:
        if rev.status == DocumentStatus.APPROVED:
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
    actor: CurrentUser = Depends(require_page("documents", "edit")),
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
