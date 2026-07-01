"""Additive department filter on the documents list endpoint.

The department filter is an additive reporting/list convenience; it narrows the
result set but does not change authorization. This test seeds documents in two
departments and asserts the ``department`` query parameter narrows results.
"""

from __future__ import annotations

from app.models.document import Department, Document, DocumentStatus, DocumentType

DOCUMENTS = "/api/v1/documents"


def _make_doc(db_session, *, number: str, department: Department) -> Document:
    doc = Document(
        document_number=number,
        title=f"Procedure {number}",
        doc_type=DocumentType.PROCEDURE,
        status=DocumentStatus.APPROVED,
        department=department,
    )
    db_session.add(doc)
    db_session.commit()
    db_session.refresh(doc)
    return doc


def test_department_filter_narrows_documents(client, db_session, seeded, auth_headers):
    _make_doc(db_session, number="DOC-QUAL-1", department=Department.QUAL)
    _make_doc(db_session, number="DOC-OPS-1", department=Department.OPS)
    h = auth_headers("engineer")

    # No filter: both documents are returned.
    all_docs = client.get(DOCUMENTS, headers=h).json()
    numbers = {item["document_number"] for item in all_docs["items"]}
    assert {"DOC-QUAL-1", "DOC-OPS-1"} <= numbers

    # Filtered by QUAL: only the QUAL document is returned.
    resp = client.get(DOCUMENTS, params={"department": "qual"}, headers=h)
    assert resp.status_code == 200, resp.text
    filtered = resp.json()
    filtered_numbers = {item["document_number"] for item in filtered["items"]}
    assert "DOC-QUAL-1" in filtered_numbers
    assert "DOC-OPS-1" not in filtered_numbers
