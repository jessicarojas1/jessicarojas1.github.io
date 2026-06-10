"""Network egress guard for server-side URL fetches (SSRF defense).

The app fetches a couple of admin-configured URLs server-side — the branding
logo embedded into PDFs and the Teams/Slack notification webhooks. Without a
check, an admin (or anyone who can set those URLs) could point them at internal
infrastructure or the cloud metadata endpoint (``169.254.169.254``) and use the
fetch as an SSRF / port-scan / metadata-exfil primitive.

:func:`is_public_http_url` resolves the host and refuses any URL whose resolved
addresses include a private, loopback, link-local, reserved, multicast or
unspecified IP. Callers should treat a ``False`` result as "do not fetch".
"""

from __future__ import annotations

import ipaddress
import socket
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


def is_public_http_url(url: str) -> bool:
    """True only if ``url`` is http(s) and every resolved IP is a public address.

    Fail-closed: a malformed URL, an unresolvable host, or any non-public
    resolved address returns ``False``.
    """
    try:
        parsed = urlparse(url)
    except ValueError:
        return False
    if parsed.scheme not in ("http", "https"):
        return False
    host = parsed.hostname
    if not host:
        return False
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    try:
        infos = socket.getaddrinfo(host, port, proto=socket.IPPROTO_TCP)
    except (socket.gaierror, UnicodeError, OSError):
        return False
    addrs = {info[4][0] for info in infos}
    if not addrs:
        return False
    return not any(_is_blocked_ip(a) for a in addrs)
