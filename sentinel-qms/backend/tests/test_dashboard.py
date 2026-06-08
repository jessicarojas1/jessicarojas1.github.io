"""Dashboard KPI aggregation tests."""

from __future__ import annotations


def test_dashboard_summary_shape(client, seeded, auth_headers):
    headers = auth_headers("manager")
    resp = client.get("/api/v1/dashboard/summary", headers=headers)
    assert resp.status_code == 200, resp.text
    body = resp.json()
    # Matches the DashboardSummary contract consumed by the frontend dashboard.
    for key in (
        "kpis",
        "ncr_trend",
        "capa_aging",
        "calibration_status",
        "supplier_performance",
        "findings_by_clause",
    ):
        assert key in body
    for kpi_key in ("open_ncrs", "open_capas", "overdue_capas", "open_audits", "open_complaints"):
        assert kpi_key in body["kpis"]


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


def test_executive_dashboard_shape(client, seeded, auth_headers):
    headers = auth_headers("manager")
    resp = client.get("/api/v1/dashboard/executive", headers=headers)
    assert resp.status_code == 200, resp.text
    body = resp.json()
    for key in (
        "generated_at",
        "kpis",
        "coq_trend",
        "coq_current",
        "clause_heatmap",
        "compliance_calendar",
        "counterfeit",
        "standards_coverage",
        "fod",
    ):
        assert key in body
    assert isinstance(body["kpis"], list) and len(body["kpis"]) >= 1
    assert set(body["counterfeit"]) == {"suspect_parts", "open_alerts"}
    assert isinstance(body["standards_coverage"], list)
    assert set(body["fod"]) == {"open_events", "trend"}
    kpi0 = body["kpis"][0]
    for k in ("key", "label", "value", "target", "direction", "status"):
        assert k in kpi0
    assert set(body["coq_current"]) >= {
        "prevention",
        "appraisal",
        "internal_failure",
        "external_failure",
        "total",
    }
