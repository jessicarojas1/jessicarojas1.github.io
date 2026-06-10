"""Per-record authorization on the record-satellite endpoints + summary reports.

A Customer (no module permissions) must not be able to read another record's
attachments, comments, e-signatures or audit trail, nor aggregate quality
summaries — only an authenticated user who can view the owning module may.
"""

from __future__ import annotations

import pytest

_SATELLITE_GETS = [
    ("/api/v1/attachments", {"entity_type": "nonconformance", "entity_id": "1"}),
    ("/api/v1/comments", {"entity_type": "nonconformance", "entity_id": "1"}),
    ("/api/v1/signatures", {"entity_type": "nonconformance", "entity_id": "1"}),
    ("/api/v1/audit-logs/record", {"entity_type": "nonconformance", "entity_id": "1"}),
]

_SUMMARY_REPORTS = [
    "/api/v1/reports/ncr-summary",
    "/api/v1/reports/capa-summary",
    "/api/v1/reports/supplier-scorecard",
    "/api/v1/reports/audit-summary",
]


@pytest.mark.parametrize("path,params", _SATELLITE_GETS)
def test_customer_cannot_read_record_satellites(client, seeded, auth_headers, path, params):
    resp = client.get(path, params=params, headers=auth_headers("customer"))
    assert resp.status_code == 403, f"{path} should be forbidden for a customer: {resp.text}"


@pytest.mark.parametrize("path,params", _SATELLITE_GETS)
def test_manager_can_read_record_satellites(client, seeded, auth_headers, path, params):
    resp = client.get(path, params=params, headers=auth_headers("manager"))
    assert resp.status_code == 200, f"{path} should be allowed for a manager: {resp.text}"


def test_unmapped_entity_type_is_fail_closed(client, seeded, auth_headers):
    # Even an authorized user is denied an entity_type that maps to no module.
    resp = client.get(
        "/api/v1/attachments",
        params={"entity_type": "totally_unknown", "entity_id": "1"},
        headers=auth_headers("manager"),
    )
    assert resp.status_code == 403


@pytest.mark.parametrize("path", _SUMMARY_REPORTS)
def test_customer_cannot_read_summary_reports(client, seeded, auth_headers, path):
    resp = client.get(path, headers=auth_headers("customer"))
    assert resp.status_code == 403, f"{path} should be forbidden for a customer: {resp.text}"


@pytest.mark.parametrize("path", _SUMMARY_REPORTS)
def test_manager_can_read_summary_reports(client, seeded, auth_headers, path):
    resp = client.get(path, headers=auth_headers("manager"))
    assert resp.status_code == 200, f"{path} should be allowed for a manager: {resp.text}"


def test_production_rejects_insecure_jwt_secret():
    from app.core.config import _INSECURE_JWT_DEFAULT, Settings

    with pytest.raises(ValueError, match="JWT_SECRET"):
        Settings(ENVIRONMENT="production", JWT_SECRET=_INSECURE_JWT_DEFAULT)
    with pytest.raises(ValueError, match="JWT_SECRET"):
        Settings(ENVIRONMENT="production", JWT_SECRET="too-short")
    # A strong secret in production is accepted.
    ok = Settings(ENVIRONMENT="production", JWT_SECRET="x" * 40)
    assert ok.is_production
