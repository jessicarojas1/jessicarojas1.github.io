"""Document control endpoints: CRUD + revisions + approval workflow + transitions."""

from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    get_current_user,
    pagination_params,
    require_page,
    require_perm,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError, WorkflowError
from app.core.iam import has_permission
from app.core.rbac import Role
from app.models.document import (
    Department,
    Document,
    DocumentAcknowledgement,
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
    AcknowledgementRead,
    AcknowledgeRequest,
    ApprovalDecision,
    ApprovalRead,
    DocumentCreate,
    DocumentList,
    DocumentRead,
    DocumentUpdate,
    PendingAcknowledgement,
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


def _join_and(items: list[str]) -> str:
    """Join labels for a human-readable sentence: 'a', 'a and b', 'a, b, and c'."""
    if len(items) == 1:
        return items[0]
    if len(items) == 2:
        return f"{items[0]} and {items[1]}"
    return ", ".join(items[:-1]) + f", and {items[-1]}"


def _assert_distinct_assignees(doc: Document) -> None:
    """Separation of duties (as in an enterprise PAL): the author, reviewer and
    approver of a controlled document must be three different people. Checked
    whenever assignees are set and re-checked at the gate out of Concept, so a
    later change can never quietly collapse the control."""
    for id_a, id_b, label_a, label_b in (
        (doc.author_id, doc.reviewer_id, "author", "reviewer"),
        (doc.author_id, doc.approver_id, "author", "approver"),
        (doc.reviewer_id, doc.approver_id, "reviewer", "approver"),
    ):
        if id_a is not None and id_b is not None and id_a == id_b:
            raise WorkflowError(
                f"Separation of duties: the {label_a} and {label_b} must be different people."
            )


# A Quality Manager or Admin may perform any workflow transition (override), so an
# operator is never locked out when assignees are absent or on leave.
_PRIVILEGED_ROLES = frozenset({Role.ADMIN.value, Role.QUALITY_MANAGER.value})


def _is_privileged(actor: CurrentUser) -> bool:
    return not _PRIVILEGED_ROLES.isdisjoint(actor.role_names)


def _stage_label(s: DocumentStatus) -> str:
    return s.value.replace("_", " ")


@router.get("", response_model=Page[DocumentList])
def list_documents(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: DocumentStatus | None = Query(None, alias="status"),
    doc_type: DocumentType | None = Query(None),
    department: Department | None = Query(None),
    search: str | None = Query(None),
    _: CurrentUser = Depends(require_page("documents", "view")),
) -> Page[DocumentList]:
    stmt = base_select(Document)
    if status_filter:
        stmt = stmt.where(Document.status == status_filter)
    if doc_type:
        stmt = stmt.where(Document.doc_type == doc_type)
    if department:
        stmt = stmt.where(Document.department == department)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Document.document_number.ilike(like) | Document.title.ilike(like))
    stmt = apply_sort(stmt, Document, sort)
    items, total = paginate(db, stmt, Document, pagination)
    return Page[DocumentList](items=items, **page_meta(total, pagination))


@router.post("", response_model=DocumentRead, status_code=status.HTTP_201_CREATED)
def create_document(
    body: DocumentCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("documents.create")),
) -> Document:
    doc = Document(
        **body.model_dump(),
        document_number=numbering.next_number(db, Document, "document_number", "DOC"),
        status=DocumentStatus.CONCEPT,
        created_by=actor.id,
        updated_by=actor.id,
    )
    _assert_distinct_assignees(doc)
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
    actor: CurrentUser = Depends(require_perm("documents.edit")),
) -> Document:
    doc = get_or_404(db, Document, doc_id, name="Document")
    if doc.status == DocumentStatus.OBSOLETE:
        raise WorkflowError("Obsolete documents cannot be edited.")
    before = audit.snapshot(doc)
    changes = body.model_dump(exclude_unset=True)
    for key, value in changes.items():
        setattr(doc, key, value)
    _assert_distinct_assignees(doc)
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
    actor: CurrentUser = Depends(require_perm("documents.edit")),
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
    elif action in ("revise", "reject"):
        target = DocumentStatus.WORK_IN_PROGRESS
    elif action == "advance":
        nxt = next_stage(current)
        if nxt is None:
            raise WorkflowError(f"Document cannot advance from '{current.value}'.")
        target = nxt

    if target is None:
        raise WorkflowError("A workflow 'action' or 'to_status' is required.")

    if current == DocumentStatus.OBSOLETE:
        raise WorkflowError("Obsolete documents are terminal and cannot transition.")

    reason = (body.reason or "").strip()

    # Validate the transition.
    if target == DocumentStatus.OBSOLETE:
        pass  # any active doc may be made obsolete
    elif action == "reject":
        # Send a document under review back to Work In Progress, with a reason.
        if current not in (DocumentStatus.PEER_REVIEW, DocumentStatus.QA_REVIEW):
            raise WorkflowError("Only a document in Peer Review or QA Review can be sent back.")
        if not reason:
            raise WorkflowError(
                "A reason is required to send a document back to Work In Progress."
            )
        target = DocumentStatus.WORK_IN_PROGRESS
    elif action == "revise" or (
        current == DocumentStatus.APPROVED and target == DocumentStatus.WORK_IN_PROGRESS
    ):
        if current != DocumentStatus.APPROVED:
            raise WorkflowError("Only an approved document can be revised.")
        target = DocumentStatus.WORK_IN_PROGRESS
    elif target == DocumentStatus.APPROVED:
        if current != DocumentStatus.QA_REVIEW:
            raise WorkflowError("A document must clear QA Review before it can be approved.")
    else:
        # Linear forward step only.
        if next_stage(current) != target:
            raise WorkflowError(f"Illegal transition: '{current.value}' -> '{target.value}'.")

    # Leaving Concept for Work In Progress requires the three workflow assignees to
    # be set. They are optional while the document is still a Concept.
    if current == DocumentStatus.CONCEPT and target == DocumentStatus.WORK_IN_PROGRESS:
        missing = [
            label
            for field, label in (
                ("author_id", "an author"),
                ("reviewer_id", "a reviewer"),
                ("approver_id", "an approver"),
            )
            if getattr(doc, field) is None
        ]
        if missing:
            raise WorkflowError(
                f"Assign {_join_and(missing)} before moving this document to Work In Progress."
            )
        _assert_distinct_assignees(doc)

    # Stage authority (enterprise PAL, option A): the assigned person for a review
    # transition must be the one to perform it; a Quality Manager or Admin may always
    # act. Keyed by (stage left, stage entered).
    privileged = _is_privileged(actor)
    stage_owner: dict[tuple[DocumentStatus, DocumentStatus], tuple[int | None, str]] = {
        (DocumentStatus.WORK_IN_PROGRESS, DocumentStatus.PEER_REVIEW): (doc.author_id, "author"),
        (DocumentStatus.PEER_REVIEW, DocumentStatus.QA_REVIEW): (doc.reviewer_id, "reviewer"),
        (DocumentStatus.QA_REVIEW, DocumentStatus.APPROVED): (doc.approver_id, "approver"),
    }
    owner = stage_owner.get((current, target))
    if owner is not None and not privileged:
        assignee_id, role_label = owner
        if assignee_id is None or assignee_id != actor.id:
            raise WorkflowError(
                f"Only the assigned {role_label} (or a Quality Manager) can move this document "
                f"from {_stage_label(current)} to {_stage_label(target)}."
            )

    # Sending a document back to WIP is the review stage's owner (reviewer / approver).
    if action == "reject" and not privileged:
        back_owner_id = doc.reviewer_id if current == DocumentStatus.PEER_REVIEW else doc.approver_id
        back_role = "reviewer" if current == DocumentStatus.PEER_REVIEW else "approver"
        if back_owner_id is None or back_owner_id != actor.id:
            raise WorkflowError(
                f"Only the assigned {back_role} (or a Quality Manager) can send this document back."
            )

    # Final approval additionally requires the approve permission.
    if target == DocumentStatus.APPROVED and not has_permission(db, actor, "documents.approve"):
        raise WorkflowError("Approving a document requires the documents.approve permission.")

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
        after=({**audit.snapshot(doc), "reject_reason": reason} if action == "reject" else doc),
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
    actor: CurrentUser = Depends(require_perm("documents.edit")),
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

    doc = db.get(Document, revision.document_id)
    # Enterprise PAL, option A: only the assigned approver (or a Quality Manager /
    # Admin) may finalize approval — checked before any e-signature is created.
    if not _is_privileged(actor) and (doc is None or doc.approver_id != actor.id):
        raise WorkflowError(
            "Only the assigned approver (or a Quality Manager) can decide this document's approval."
        )

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
    actor: CurrentUser = Depends(require_perm("documents.obsolete")),
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
    actor: CurrentUser = Depends(require_perm("documents.edit")),
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


# --------------------------------------------------------------------------- #
# Read-and-acknowledge workflow                                               #
# --------------------------------------------------------------------------- #
@router.get("/acknowledgements/pending", response_model=list[PendingAcknowledgement])
def my_pending_acknowledgements(
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("documents", "view")),
) -> list[PendingAcknowledgement]:
    """Approved, acknowledgement-required documents the current user hasn't yet
    acknowledged at their current revision."""
    docs = (
        db.execute(
            select(Document)
            .where(
                Document.acknowledgement_required.is_(True),
                Document.status == DocumentStatus.APPROVED,
                Document.is_deleted.is_(False),
            )
            .order_by(Document.document_number)
        )
        .scalars()
        .all()
    )
    # (document_id, revision) pairs this user has already acknowledged.
    my_acks = set(
        db.execute(
            select(DocumentAcknowledgement.document_id, DocumentAcknowledgement.revision).where(
                DocumentAcknowledgement.user_id == actor.id
            )
        ).all()
    )
    return [
        PendingAcknowledgement(
            document_id=d.id,
            document_number=d.document_number,
            title=d.title,
            current_revision=d.current_revision,
        )
        for d in docs
        if (d.id, d.current_revision or "") not in my_acks
    ]


@router.get("/{doc_id}/acknowledgements", response_model=list[AcknowledgementRead])
def list_acknowledgements(
    doc_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("documents", "view")),
) -> list[DocumentAcknowledgement]:
    """Who has acknowledged this document (compliance / awareness evidence)."""
    get_or_404(db, Document, doc_id, name="Document")
    stmt = (
        select(DocumentAcknowledgement)
        .where(DocumentAcknowledgement.document_id == doc_id)
        .order_by(DocumentAcknowledgement.acknowledged_at.desc())
    )
    return list(db.execute(stmt).scalars().all())


@router.post(
    "/{doc_id}/acknowledge",
    response_model=AcknowledgementRead,
    status_code=status.HTTP_201_CREATED,
)
def acknowledge_document(
    doc_id: int,
    body: AcknowledgeRequest,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> DocumentAcknowledgement:
    """Record the current user's read-and-acknowledge attestation for the current
    revision. Idempotent: re-acknowledging the same revision returns the existing
    record."""
    doc = get_or_404(db, Document, doc_id, name="Document")
    if doc.status != DocumentStatus.APPROVED:
        raise WorkflowError("Only approved documents can be acknowledged.")
    revision = doc.current_revision or ""
    existing = db.execute(
        select(DocumentAcknowledgement).where(
            DocumentAcknowledgement.document_id == doc_id,
            DocumentAcknowledgement.revision == revision,
            DocumentAcknowledgement.user_id == actor.id,
        )
    ).scalar_one_or_none()
    if existing is not None:
        return existing
    ack = DocumentAcknowledgement(
        document_id=doc_id,
        revision=revision,
        user_id=actor.id,
        user_name=actor.full_name,
        note=body.note,
    )
    db.add(ack)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="acknowledge",
        entity_type=ENTITY,
        entity_id=doc.id,
        after={"revision": revision},
        **request_context(request),
    )
    db.commit()
    db.refresh(ack)
    return ack
