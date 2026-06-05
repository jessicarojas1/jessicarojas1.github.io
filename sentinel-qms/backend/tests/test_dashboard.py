"""Dashboard KPI aggregation tests."""
from __future__ import annotations


def test_dashboard_summary_shape(client, seeded, auth_headers):
    headers = auth_headers("manager")
    resp = client.get("/api/v1/dashboard/summary", headers=headers)
    assert resp.status_code == 200, resp.text
    body = resp.json()
    for key in (
        "nonconformances",
        "capa",
        "calibration",
        "audit_findings",
        "suppliers",
        "complaints",
        "generated_at",
    ):
        assert key in body


def test_open_ncr_count_reflects_data(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    client.post(
        "/api/v1/nonconformances",
        json={"title": "a", "description": "b", "severity": "critical"},
        headers=eng,
    )
    resp = client.get("/api/v1/dashboard/nonconformances", headers=eng)
    assert resp.status_code == 200
    body = resp.json()
    assert body["open_total"] >= 1
    assert body["critical_open"] >= 1


def test_calibration_kpi(client, seeded, auth_headers):
    headers = auth_headers("manager")
    resp = client.get("/api/v1/dashboard/calibration", headers=headers)
    assert resp.status_code == 200
    assert "overdue" in resp.json()
