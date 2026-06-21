"""TOTP (RFC 6238) multi-factor authentication — stdlib only, no dependency.

Generates a base32 shared secret, computes time-based one-time codes, verifies a
presented code within a small clock-skew window (constant-time), and builds the
``otpauth://`` provisioning URI authenticator apps consume (rendered as a QR by
the SPA).
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import os
import struct
import time
from urllib.parse import quote

DIGITS = 6
STEP = 30


def generate_secret() -> str:
    """Return a fresh base32 TOTP secret (no padding)."""
    return base64.b32encode(os.urandom(20)).decode("ascii").rstrip("=")


def _hotp(secret_b32: str, counter: int) -> str:
    # Re-pad to a multiple of 8 for the base32 decoder, accept lowercase input.
    padded = secret_b32.upper() + "=" * (-len(secret_b32) % 8)
    key = base64.b32decode(padded, casefold=True)
    msg = struct.pack(">Q", counter)
    digest = hmac.new(key, msg, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code_int = struct.unpack(">I", digest[offset : offset + 4])[0] & 0x7FFFFFFF
    return str(code_int % (10**DIGITS)).zfill(DIGITS)


def totp(secret_b32: str, *, at: float | None = None) -> str:
    """The current TOTP code for ``secret_b32``."""
    now = int(at if at is not None else time.time())
    return _hotp(secret_b32, now // STEP)


def verify(secret_b32: str, code: str, *, window: int = 1, at: float | None = None) -> bool:
    """True if ``code`` is valid within ±``window`` time steps (clock skew)."""
    if not secret_b32 or not code:
        return False
    code = code.strip().replace(" ", "")
    if not code.isdigit() or len(code) != DIGITS:
        return False
    now = int(at if at is not None else time.time())
    counter = now // STEP
    for drift in range(-window, window + 1):
        if hmac.compare_digest(_hotp(secret_b32, counter + drift), code):
            return True
    return False


def provisioning_uri(secret_b32: str, account_name: str, issuer: str = "Sentinel QMS") -> str:
    """Build the ``otpauth://totp/...`` URI for authenticator-app enrollment."""
    label = quote(f"{issuer}:{account_name}")
    params = (
        f"secret={secret_b32}&issuer={quote(issuer)}&algorithm=SHA1&digits={DIGITS}&period={STEP}"
    )
    return f"otpauth://totp/{label}?{params}"
