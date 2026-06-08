"""Create a CAPA pre-filled from an originating quality record (NCR, complaint,
audit finding, …) so the corrective-action loop can be opened in one click.
"""

from __future__ import annotations

from sqlalchemy.orm import Session

from app.models.capa import Capa, CapaStatus, CapaType
from app.services import numbering


def create_linked_capa(
    db: Session,
    actor_id: int,
    *,
    title: str,
    problem: str,
    supplier_id: int | None = None,
) -> Capa:
    """Create an OPEN corrective CAPA from a source record and flush it.

    The caller is responsible for setting the back-reference (e.g.
    ``ncr.capa_id = capa.id``) and committing.
    """
    capa = Capa(
        capa_number=numbering.next_number(db, Capa, "capa_number", "CAPA"),
        title=title[:512],
        capa_type=CapaType.CORRECTIVE,
        status=CapaStatus.OPEN,
        d2_problem_description=problem or title,
        supplier_id=supplier_id,
        created_by=actor_id,
        updated_by=actor_id,
    )
    db.add(capa)
    db.flush()
    return capa
