"""TOTP MFA: code algorithm, enroll/activate/disable, and login enforcement."""

from __future__ import annotations

from app.services import mfa


# --------------------------------------------------------------------------- #
# TOTP algorithm                                                              #
# --------------------------------------------------------------------------- #
def test_totp_roundtrip_and_window():
    secret = mfa.generate_secret()
    at = 1_700_000_000
    code = mfa.totp(secret, at=at)
    assert len(code) == 6 and code.isdigit()
    assert mfa.verify(secret, code, at=at)
    # Accepts one step of clock skew either side, rejects far-off codes.
    assert mfa.verify(secret, mfa.totp(secret, at=at - 30), at=at)
    assert not mfa.verify(secret, mfa.totp(secret, at=at - 600), at=at)
    assert not mfa.verify(secret, "000000", at=at) or code == "000000"


def test_provisioning_uri_format():
    uri = mfa.provisioning_uri("ABC234", "qe@test.local")
    assert uri.startswith("otpauth://totp/")
    assert "secret=ABC234" in uri
    assert "issuer=Sentinel%20QMS" in uri


# --------------------------------------------------------------------------- #
# Enrollment + login enforcement                                              #
# --------------------------------------------------------------------------- #
def _auth(client, role_email="qe@test.local", pwd="EngPass123!"):
    r = client.post("/api/v1/auth/login", json={"username": role_email, "password": pwd})
    assert r.status_code == 200, r.text
    return {"Authorization": f"Bearer {r.json()['access_token']}"}


def test_enroll_activate_then_login_requires_otp(client, seeded):
    h = _auth(client)
    assert client.get("/api/v1/auth/mfa/status", headers=h).json() == {"enabled": False}

    enroll = client.post("/api/v1/auth/mfa/enroll", headers=h)
    assert enroll.status_code == 200
    secret = enroll.json()["secret"]
    assert enroll.json()["otpauth_uri"].startswith("otpauth://")

    # Activation requires a valid code.
    bad = client.post("/api/v1/auth/mfa/activate", json={"code": "000000"}, headers=h)
    assert bad.status_code in (401, 422)
    act = client.post("/api/v1/auth/mfa/activate", json={"code": mfa.totp(secret)}, headers=h)
    assert act.status_code == 200 and act.json() == {"enabled": True}

    # Login now demands the second factor.
    no_otp = client.post(
        "/api/v1/auth/login", json={"username": "qe@test.local", "password": "EngPass123!"}
    )
    assert no_otp.status_code == 401
    wrong = client.post(
        "/api/v1/auth/login",
        json={"username": "qe@test.local", "password": "EngPass123!", "otp": "123456"},
    )
    assert wrong.status_code == 401
    ok = client.post(
        "/api/v1/auth/login",
        json={"username": "qe@test.local", "password": "EngPass123!", "otp": mfa.totp(secret)},
    )
    assert ok.status_code == 200


def test_disable_mfa_removes_requirement(client, seeded):
    h = _auth(client)
    secret = client.post("/api/v1/auth/mfa/enroll", headers=h).json()["secret"]
    client.post("/api/v1/auth/mfa/activate", json={"code": mfa.totp(secret)}, headers=h)

    # Wrong code cannot disable.
    assert (
        client.post("/api/v1/auth/mfa/disable", json={"code": "000000"}, headers=h).status_code
        == 401
    )
    # Correct code disables.
    off = client.post("/api/v1/auth/mfa/disable", json={"code": mfa.totp(secret)}, headers=h)
    assert off.status_code == 200 and off.json() == {"enabled": False}

    # Login no longer needs an OTP.
    assert (
        client.post(
            "/api/v1/auth/login", json={"username": "qe@test.local", "password": "EngPass123!"}
        ).status_code
        == 200
    )
