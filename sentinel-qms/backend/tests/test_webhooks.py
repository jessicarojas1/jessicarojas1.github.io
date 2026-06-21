"""Outbound webhooks: signing, enqueue-on-audit, dispatch/retry, and admin API."""

from __future__ import annotations

import hashlib
import hmac

import pytest

from app.core import audit
from app.models.webhook import Webhook, WebhookDelivery
from app.services import webhooks as svc


# --------------------------------------------------------------------------- #
# Signing                                                                      #
# --------------------------------------------------------------------------- #
def test_sign_payload_matches_hmac_sha256():
    body = b'{"event":"x"}'
    sig = svc.sign_payload("s3cr3t", body)
    expected = "sha256=" + hmac.new(b"s3cr3t", body, hashlib.sha256).hexdigest()
    assert sig == expected


# --------------------------------------------------------------------------- #
# Enqueue (transactional, subscription-filtered)                               #
# --------------------------------------------------------------------------- #
def _make_hook(db, *, events, active=True, url="https://hooks.example.com/x"):
    hook = Webhook(name="h", url=url, secret="sek", event_types=events, active=active)
    db.add(hook)
    db.flush()
    return hook


def test_enqueue_only_for_subscribers(db_session):
    _make_hook(db_session, events=["nonconformance.disposition"])
    _make_hook(db_session, events=["capa.close"])
    _make_hook(db_session, events=["*"])
    db_session.commit()

    queued = svc.enqueue_event(
        db_session, event_type="nonconformance.disposition", payload={"a": 1}
    )
    db_session.commit()
    # The matching specific hook + the wildcard hook = 2.
    assert queued == 2
    rows = db_session.query(WebhookDelivery).all()
    assert len(rows) == 2
    assert {r.status for r in rows} == {"pending"}


def test_inactive_hook_not_enqueued(db_session):
    _make_hook(db_session, events=["*"], active=False)
    db_session.commit()
    assert svc.enqueue_event(db_session, event_type="x.y", payload={}) == 0


def test_audit_record_emits_event(db_session):
    _make_hook(db_session, events=["*"])
    db_session.commit()
    audit.record(
        db_session,
        actor_id=1,
        actor_email="a@b.c",
        action="disposition",
        entity_type="nonconformance",
        entity_id=42,
        after={"status": "closed"},
    )
    db_session.commit()
    rows = db_session.query(WebhookDelivery).all()
    assert len(rows) == 1
    assert rows[0].event_type == "nonconformance.disposition"
    assert rows[0].payload["entity_id"] == "42"


# --------------------------------------------------------------------------- #
# Dispatch + retry                                                             #
# --------------------------------------------------------------------------- #
@pytest.fixture()
def _no_ssrf_guard(monkeypatch):
    monkeypatch.setattr(svc, "is_public_http_url", lambda url: True)


def test_dispatch_success_signs_payload(db_session, monkeypatch, _no_ssrf_guard):
    captured = {}

    def fake_post(url, body, headers):
        captured["url"] = url
        captured["body"] = body
        captured["headers"] = headers
        return 200, "ok"

    monkeypatch.setattr(svc, "_post", fake_post)
    hook = _make_hook(db_session, events=["*"])
    db_session.commit()
    svc.enqueue_event(db_session, event_type="x.created", payload={"n": 1})
    db_session.commit()

    summary = svc.dispatch_due(db_session)
    assert summary == {"attempted": 1, "succeeded": 1, "failed": 0}

    row = db_session.query(WebhookDelivery).one()
    assert row.status == "success"
    assert row.delivered_at is not None
    assert row.next_attempt_at is None
    # The signature header must verify against the exact body sent.
    expected = svc.sign_payload(hook.secret, captured["body"])
    assert captured["headers"][svc.SIGNATURE_HEADER] == expected
    assert captured["headers"]["X-Sentinel-Event"] == "x.created"


def test_dispatch_failure_schedules_retry_then_dies(db_session, monkeypatch, _no_ssrf_guard):
    monkeypatch.setattr(svc, "_post", lambda url, body, headers: (500, "HTTP 500"))
    _make_hook(db_session, events=["*"])
    db_session.commit()
    svc.enqueue_event(db_session, event_type="x.created", payload={})
    db_session.commit()

    # First attempt fails → scheduled for retry.
    svc.dispatch_due(db_session)
    row = db_session.query(WebhookDelivery).one()
    assert row.status == "failed"
    assert row.attempts == 1
    assert row.last_status_code == 500
    assert row.next_attempt_at is not None

    # Exhaust the remaining attempts; force each retry to be due, stop at death.
    while row.status == "failed":
        row.next_attempt_at = row.created_at  # make due
        db_session.commit()
        svc.dispatch_due(db_session)
        db_session.refresh(row)
    assert row.status == "dead"
    assert row.attempts == svc.MAX_ATTEMPTS
    assert row.next_attempt_at is None


def test_ssrf_blocked_url_marked_dead(db_session, monkeypatch):
    monkeypatch.setattr(svc, "is_public_http_url", lambda url: False)
    monkeypatch.setattr(svc, "_post", lambda url, body, headers: (200, "ok"))
    _make_hook(db_session, events=["*"], url="http://169.254.169.254/latest")
    db_session.commit()
    svc.enqueue_event(db_session, event_type="x.created", payload={})
    db_session.commit()
    svc.dispatch_due(db_session)
    row = db_session.query(WebhookDelivery).one()
    assert row.status == "dead"
    assert "public address" in (row.last_error or "")


# --------------------------------------------------------------------------- #
# Admin API + authorization                                                    #
# --------------------------------------------------------------------------- #
def test_non_admin_cannot_manage_webhooks(client, auth_headers):
    r = client.get("/api/v1/webhooks", headers=auth_headers("engineer"))
    assert r.status_code == 403


def test_admin_create_returns_secret_once(client, auth_headers, monkeypatch):
    import app.api.routers.webhooks as router_mod

    monkeypatch.setattr(router_mod, "is_public_http_url", lambda url: True)
    r = client.post(
        "/api/v1/webhooks",
        json={"name": "CI", "url": "https://hooks.example.com/x", "event_types": ["*"]},
        headers=auth_headers("admin"),
    )
    assert r.status_code == 201, r.text
    data = r.json()
    assert data["secret"]  # returned once
    assert data["event_types"] == ["*"]

    listed = client.get("/api/v1/webhooks", headers=auth_headers("admin")).json()
    assert len(listed) == 1
    assert "secret" not in listed[0]  # never exposed again
