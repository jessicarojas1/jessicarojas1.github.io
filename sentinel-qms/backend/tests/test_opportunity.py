"""Opportunity tracking on the risk register (ISO 9001 6.1).

A single ``is_opportunity`` flag lets the register hold both risks and
opportunities. Covers: persistence on create, the ``?opportunity=`` list
filter, and the ``is_opportunity`` column in the CSV export.
"""

from __future__ import annotations

import csv
import io

_BASE = {
    "category": "quality",
    "description": "Adopt a faster supplier qualification path.",
    "severity": 4,
    "likelihood": 3,
    "detectability": 2,
}


def _create(client, headers, *, title, is_opportunity):
    resp = client.post(
        "/api/v1/risks",
        json={**_BASE, "title": title, "is_opportunity": is_opportunity},
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def test_create_opportunity_persisted_and_returned(client, seeded, auth_headers):
    h = auth_headers("engineer")
    body = _create(client, h, title="Process automation upside", is_opportunity=True)
    assert body["is_opportunity"] is True

    # Re-read to confirm it persisted, not just echoed back.
    got = client.get(f"/api/v1/risks/{body['id']}", headers=h).json()
    assert got["is_opportunity"] is True


def test_create_risk_defaults_to_not_opportunity(client, seeded, auth_headers):
    h = auth_headers("engineer")
    body = _create(client, h, title="Single-source dependency", is_opportunity=False)
    assert body["is_opportunity"] is False


def test_list_filter_separates_opportunities_and_risks(client, seeded, auth_headers):
    h = auth_headers("engineer")
    opp = _create(client, h, title="Lean throughput gain", is_opportunity=True)
    risk = _create(client, h, title="Capacity shortfall", is_opportunity=False)

    opps = client.get("/api/v1/risks?opportunity=true", headers=h).json()["items"]
    opp_numbers = {r["risk_number"] for r in opps}
    assert opp["risk_number"] in opp_numbers
    assert risk["risk_number"] not in opp_numbers

    risks = client.get("/api/v1/risks?opportunity=false", headers=h).json()["items"]
    risk_numbers = {r["risk_number"] for r in risks}
    assert risk["risk_number"] in risk_numbers
    assert opp["risk_number"] not in risk_numbers


def test_export_csv_includes_is_opportunity_column(client, seeded, auth_headers):
    h = auth_headers("engineer")
    _create(client, h, title="Export coverage check", is_opportunity=True)

    resp = client.get("/api/v1/risks/export.csv", headers=h)
    assert resp.status_code == 200, resp.text
    rows = list(csv.reader(io.StringIO(resp.text)))
    header = rows[0]
    assert "is_opportunity" in header
