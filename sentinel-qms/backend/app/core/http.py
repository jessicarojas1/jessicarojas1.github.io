"""Small HTTP helpers shared across routers."""

from __future__ import annotations

from urllib.parse import quote

# Characters that must never reach a response header value (CR/LF would allow
# header/response splitting; other C0 controls are illegal in header values).
_HEADER_CTRL = {chr(c) for c in range(0x20)} | {chr(0x7F)}


def content_disposition_attachment(filename: str) -> str:
    """Build a safe ``Content-Disposition: attachment`` header value.

    Defends against header injection / response splitting and non-ASCII breakage
    by emitting both an RFC 6266/5987 ``filename*`` (UTF-8 percent-encoded) and an
    ASCII ``filename=`` fallback with quotes, backslashes, and control characters
    (incl. CR/LF) stripped. A blank result degrades to ``"download"``.
    """
    name = filename or "download"
    # ASCII fallback: drop control chars (incl. CR/LF), quotes and backslashes,
    # and any non-ASCII byte so the quoted-string token can never break parsing.
    ascii_fallback = "".join(
        ch
        for ch in name
        if ch not in _HEADER_CTRL and ch not in '"\\' and ord(ch) < 0x80
    ).strip()
    if not ascii_fallback:
        ascii_fallback = "download"
    # RFC 5987 extended value: UTF-8, percent-encoded, no characters needing quoting.
    encoded = quote(name, safe="")
    return f"attachment; filename=\"{ascii_fallback}\"; filename*=UTF-8''{encoded}"
