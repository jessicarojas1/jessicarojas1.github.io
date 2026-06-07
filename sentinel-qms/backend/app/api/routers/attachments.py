"""Attachment endpoints: upload, download, and metadata listing."""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Form, Request, Response, UploadFile, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core import audit
from app.core.config import settings
from app.core.database import get_db
from app.core.exceptions import NotFoundError, ValidationAppError
from app.models.user import Attachment
from app.schemas.attachment import AttachmentRead, AttachmentUploadResult
from app.schemas.auth import CurrentUser
from app.services.crud import request_context
from app.services.storage import get_storage, is_allowed_content_type

router = APIRouter(prefix="/attachments", tags=["attachments"])

ENTITY = "attachment"


@router.post("", response_model=AttachmentUploadResult, status_code=status.HTTP_201_CREATED)
async def upload_attachment(
    request: Request,
    entity_type: str = Form(..., max_length=64),
    entity_id: str = Form(..., max_length=64),
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> AttachmentUploadResult:
    content_type = file.content_type or "application/octet-stream"
    if not is_allowed_content_type(content_type):
        raise ValidationAppError(f"Content type '{content_type}' is not permitted.")

    data = await file.read()
    if len(data) > settings.MAX_UPLOAD_BYTES:
        raise ValidationAppError(
            f"File exceeds the maximum allowed size of {settings.MAX_UPLOAD_BYTES} bytes."
        )
    if not data:
        raise ValidationAppError("Uploaded file is empty.")

    storage = get_storage()
    stored = storage.save(
        data, content_type=content_type, original_filename=file.filename or "upload"
    )

    attachment = Attachment(
        entity_type=entity_type,
        entity_id=entity_id,
        original_filename=file.filename or stored.key,
        stored_key=stored.key,
        content_type=stored.content_type,
        size_bytes=stored.size_bytes,
        checksum_sha256=stored.checksum_sha256,
        storage_backend=stored.backend,
        uploaded_by=actor.id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(attachment)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="upload",
        entity_type=ENTITY,
        entity_id=attachment.id,
        after={
            "filename": attachment.original_filename,
            "linked_to": f"{entity_type}:{entity_id}",
            "size_bytes": attachment.size_bytes,
        },
        **request_context(request),
    )
    db.commit()
    db.refresh(attachment)

    url = storage.presigned_url(stored.key)
    return AttachmentUploadResult(attachment=attachment, download_url=url)


@router.get("", response_model=list[AttachmentRead])
def list_attachments(
    entity_type: str,
    entity_id: str,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> list[Attachment]:
    return (
        db.execute(
            select(Attachment)
            .where(
                Attachment.entity_type == entity_type,
                Attachment.entity_id == entity_id,
            )
            .order_by(Attachment.id.desc())
        )
        .scalars()
        .all()
    )


@router.get("/{attachment_id}/download")
def download_attachment(
    attachment_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> Response:
    attachment = db.get(Attachment, attachment_id)
    if attachment is None:
        raise NotFoundError(f"Attachment {attachment_id} not found.")
    data = get_storage().load(attachment.stored_key)
    return Response(
        content=data,
        media_type=attachment.content_type,
        headers={"Content-Disposition": f'attachment; filename="{attachment.original_filename}"'},
    )
