"""AS9145 Part Submission Warrant (PSW) PDF export."""

from __future__ import annotations


def test_psw_pdf_downloads(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    pid = client.post(
        "/api/v1/apqp",
        json={"part_number": "PN-PSW", "part_name": "Flange", "customer": "Lockheed"},
        headers=mgr,
    ).json()["id"]
    resp = client.get(f"/api/v1/reports/apqp/{pid}/psw.pdf", headers=mgr)
    assert resp.status_code == 200, resp.text
    assert resp.headers["content-type"] == "application/pdf"
    assert resp.content[:4] == b"%PDF"


def test_psw_pdf_404_for_missing(client, seeded, auth_headers):
    resp = client.get("/api/v1/reports/apqp/999999/psw.pdf", headers=auth_headers("manager"))
    assert resp.status_code == 404
