"""Document read-and-acknowledge workflow: attest, idempotency, pending, guards."""

from __future__ import annotations

from app.models.document import Document, DocumentStatus, DocumentType


def _make_doc(
    db_session, *, number="DOC-ACK-1", revision="A", status=DocumentStatus.APPROVED, required=True
) -> Document:
    doc = Document(
        document_number=number,
        title="Quality Policy",
        doc_type=DocumentType.POLICY,
        status=status,
        current_revision=revision,
        acknowledgement_required=required,
    )
    db_session.add(doc)
    db_session.commit()
    db_session.refresh(doc)
    return doc


def test_acknowledge_and_list(client, db_session, seeded, auth_headers):
    doc = _make_doc(db_session)
    h = auth_headers("engineer")
    resp = client.post(
        f"/api/v1/documents/{doc.id}/acknowledge", json={"note": "Read it"}, headers=h
    )
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["revision"] == "A"
    assert body["note"] == "Read it"

    listed = client.get(f"/api/v1/documents/{doc.id}/acknowledgements", headers=h).json()
    assert len(listed) == 1
    assert listed[0]["user_name"]


def test_acknowledge_is_idempotent_per_revision(client, db_session, seeded, auth_headers):
    doc = _make_doc(db_session)
    h = auth_headers("engineer")
    first = client.post(f"/api/v1/documents/{doc.id}/acknowledge", json={}, headers=h).json()
    second = client.post(f"/api/v1/documents/{doc.id}/acknowledge", json={}, headers=h).json()
    assert first["id"] == second["id"]
    listed = client.get(f"/api/v1/documents/{doc.id}/acknowledgements", headers=h).json()
    assert len(listed) == 1


def test_pending_then_cleared_after_ack(client, db_session, seeded, auth_headers):
    doc = _make_doc(db_session)
    h = auth_headers("engineer")
    pending = client.get("/api/v1/documents/acknowledgements/pending", headers=h).json()
    assert any(p["document_id"] == doc.id for p in pending)

    client.post(f"/api/v1/documents/{doc.id}/acknowledge", json={}, headers=h)
    pending2 = client.get("/api/v1/documents/acknowledgements/pending", headers=h).json()
    assert all(p["document_id"] != doc.id for p in pending2)


def test_new_revision_requires_fresh_acknowledgement(client, db_session, seeded, auth_headers):
    doc = _make_doc(db_session)
    h = auth_headers("engineer")
    client.post(f"/api/v1/documents/{doc.id}/acknowledge", json={}, headers=h)
    # Bump the revision: the prior acknowledgement no longer covers it.
    doc.current_revision = "B"
    db_session.commit()
    pending = client.get("/api/v1/documents/acknowledgements/pending", headers=h).json()
    assert any(p["document_id"] == doc.id and p["current_revision"] == "B" for p in pending)


def test_cannot_acknowledge_unapproved(client, db_session, seeded, auth_headers):
    doc = _make_doc(db_session, status=DocumentStatus.WORK_IN_PROGRESS)
    h = auth_headers("engineer")
    resp = client.post(f"/api/v1/documents/{doc.id}/acknowledge", json={}, headers=h)
    assert resp.status_code == 409
