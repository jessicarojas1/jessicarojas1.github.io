"""Network egress guard for server-side URL fetches (SSRF defense).

The app fetches a couple of admin-configured URLs server-side — the branding
logo embedded into PDFs and the Teams/Slack notification webhooks. Without a
check, an admin (or anyone who can set those URLs) could point them at internal
infrastructure or the cloud metadata endpoint (``169.254.169.254``) and use the
fetch as an SSRF / port-scan / metadata-exfil primitive.

:func:`is_public_http_url` resolves the host and refuses any URL whose resolved
addresses include a private, loopback, link-local, reserved, multicast or
unspecified IP. Callers should treat a ``False`` result as "do not fetch".

:func:`resolve_public_url` performs the SAME validation but resolves the host
exactly ONCE and returns the validated IP(s). Callers must then connect to one
of the returned IPs directly (pinning) instead of re-resolving the hostname, so
a DNS-rebinding answer cannot swap a public IP for an internal one between the
check and the request (TOCTOU).
"""

from __future__ import annotations

import ipaddress
import socket
from dataclasses import dataclass
from urllib.parse import urlparse


def _is_blocked_ip(addr: str) -> bool:
    try:
        ip = ipaddress.ip_address(addr.split("%", 1)[0])
    except ValueError:
        return True  # unparseable => treat as unsafe
    return (
        ip.is_private
        or ip.is_loopback
        or ip.is_link_local
        or ip.is_reserved
        or ip.is_multicast
        or ip.is_unspecified
    )


@dataclass(frozen=True)
class ResolvedTarget:
    """A validated fetch target with its host resolved exactly once.

    ``ips`` holds every validated (public) address the host resolved to; the
    caller pins the connection to one of these (``ip``) and sends ``host`` as the
    HTTP Host header / TLS SNI, so no second DNS lookup happens.
    """

    scheme: str
    host: str
    port: int
    ips: tuple[str, ...]

    @property
    def ip(self) -> str:
        return self.ips[0]


def resolve_public_url(url: str) -> ResolvedTarget | None:
    """Validate ``url`` and return its resolved public IP(s), or ``None``.

    Resolves the host ONCE here. Fail-closed: a malformed URL, an unresolvable
    host, or ANY non-public resolved address returns ``None``. Callers must
    connect to one of ``ResolvedTarget.ips`` directly (not re-resolve the host)
    to be safe against DNS rebinding.
    """
    try:
        parsed = urlparse(url)
    except ValueError:
        return None
    if parsed.scheme not in ("http", "https"):
        return None
    host = parsed.hostname
    if not host:
        return None
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    try:
        infos = socket.getaddrinfo(host, port, proto=socket.IPPROTO_TCP)
    except (socket.gaierror, UnicodeError, OSError):
        return None
    addrs = tuple({info[4][0] for info in infos})
    if not addrs or any(_is_blocked_ip(a) for a in addrs):
        return None
    return ResolvedTarget(scheme=parsed.scheme, host=host, port=port, ips=addrs)


def is_public_http_url(url: str) -> bool:
    """True only if ``url`` is http(s) and every resolved IP is a public address.

    Fail-closed: a malformed URL, an unresolvable host, or any non-public
    resolved address returns ``False``. Note: this re-resolves on a later fetch,
    so for an actual outbound request prefer :func:`resolve_public_url` and pin
    the connection to the returned IP (rebinding-safe).
    """
    return resolve_public_url(url) is not None
