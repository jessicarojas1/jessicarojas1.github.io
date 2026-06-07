"""Multi-channel outbound notification delivery (Email + Teams + Slack).

Stdlib-only transports (``smtplib`` / ``email`` / ``urllib.request``) with a hard
5s timeout per call. Every sender is best-effort and returns ``(ok, detail)``
without raising, so a misconfigured or unreachable channel can never break the
calling request.

Effective configuration is resolved from the :class:`OrgSettings` singleton
(admin-editable) with env fallbacks for the webhook URLs; SMTP host/credentials
are read from :mod:`app.core.config` (env only — secrets are not admin-editable).
"""

from __future__ import annotations

import json
import logging
import smtplib
import threading
import urllib.request
from dataclasses import dataclass
from email.message import EmailMessage

from sqlalchemy.orm import Session

from app.core.config import settings

logger = logging.getLogger("app.notifications")

_HTTP_TIMEOUT = 5  # seconds — keep dispatch from blocking the request


@dataclass(frozen=True)
class ChannelConfig:
    """Resolved, plain-data delivery configuration.

    Carries no ORM objects or DB session so it is safe to hand to a background
    thread. Built once per dispatch by :func:`resolve_channels`.
    """

    email_enabled: bool
    smtp_host: str
    smtp_port: int
    smtp_username: str
    smtp_password: str
    smtp_from: str
    smtp_use_tls: bool
    teams_webhook_url: str
    slack_webhook_url: str

    @property
    def email_ready(self) -> bool:
        """True when email delivery is enabled and an SMTP host is configured."""
        return bool(self.email_enabled and self.smtp_host)


def resolve_channels(db: Session) -> ChannelConfig:
    """Resolve the effective :class:`ChannelConfig` from DB settings + env.

    OrgSettings (admin-editable) controls the email toggle and supplies the
    Teams/Slack webhook URLs, falling back to env config when blank. SMTP
    host/credentials always come from env (secrets).
    """
    # Imported lazily to avoid an import cycle at module load time.
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1)
    if org is None:
        org = db.query(OrgSettings).order_by(OrgSettings.id.asc()).first()

    email_enabled = bool(getattr(org, "notifications_email_enabled", False))
    teams_url = (
        getattr(org, "teams_webhook_url", None) or ""
    ).strip() or settings.TEAMS_WEBHOOK_URL
    slack_url = (
        getattr(org, "slack_webhook_url", None) or ""
    ).strip() or settings.SLACK_WEBHOOK_URL

    return ChannelConfig(
        email_enabled=email_enabled,
        smtp_host=settings.SMTP_HOST,
        smtp_port=settings.SMTP_PORT,
        smtp_username=settings.SMTP_USERNAME,
        smtp_password=settings.SMTP_PASSWORD,
        smtp_from=settings.SMTP_FROM,
        smtp_use_tls=settings.SMTP_USE_TLS,
        teams_webhook_url=teams_url,
        slack_webhook_url=slack_url,
    )


def _post_json(url: str, payload: dict) -> tuple[bool, str]:
    """POST a JSON body to ``url`` (stdlib). Returns (ok, detail), never raises."""
    try:
        data = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        urllib.request.urlopen(req, timeout=_HTTP_TIMEOUT)  # noqa: S310
        return True, "sent"
    except Exception as exc:  # noqa: BLE001 — best-effort; never raise to the caller
        logger.warning("webhook_dispatch_failed", exc_info=True)
        return False, str(exc) or exc.__class__.__name__


def send_email(
    cfg: ChannelConfig,
    to_email: str,
    title: str,
    body: str | None,
    link: str | None,
    attachments: list[tuple[bytes, str, str]] | None = None,
) -> tuple[bool, str]:
    """Send a plaintext email via stdlib smtplib. Returns (ok, detail).

    ``attachments`` is an optional list of ``(data, filename, mime_type)`` tuples
    (e.g. a generated PDF); ``mime_type`` is ``"maintype/subtype"``.
    """
    if not cfg.smtp_host:
        return False, "SMTP not configured"
    if not to_email:
        return False, "No recipient email address"
    try:
        msg = EmailMessage()
        msg["Subject"] = title
        msg["From"] = cfg.smtp_from or cfg.smtp_username or "noreply@sentinel-qms.local"
        msg["To"] = to_email
        text = body or ""
        if link:
            text = f"{text}\n\n{link}".strip()
        msg.set_content(text or title)
        for data, filename, mime in attachments or []:
            maintype, _, subtype = mime.partition("/")
            msg.add_attachment(
                data,
                maintype=maintype or "application",
                subtype=subtype or "octet-stream",
                filename=filename,
            )

        with smtplib.SMTP(cfg.smtp_host, cfg.smtp_port, timeout=_HTTP_TIMEOUT) as smtp:
            if cfg.smtp_use_tls:
                smtp.starttls()
            if cfg.smtp_username:
                smtp.login(cfg.smtp_username, cfg.smtp_password)
            smtp.send_message(msg)
        return True, f"sent to {to_email}"
    except Exception as exc:  # noqa: BLE001 — best-effort; never raise to the caller
        logger.warning("email_dispatch_failed", exc_info=True)
        return False, str(exc) or exc.__class__.__name__


def send_teams(
    cfg: ChannelConfig,
    title: str,
    body: str | None,
    link: str | None,
) -> tuple[bool, str]:
    """POST a MessageCard to a Teams incoming webhook. Returns (ok, detail)."""
    if not cfg.teams_webhook_url:
        return False, "No Teams webhook configured"
    card: dict = {
        "@type": "MessageCard",
        "@context": "http://schema.org/extensions",
        "summary": title,
        "title": title,
        "text": body or "",
    }
    if link:
        card["potentialAction"] = [
            {
                "@type": "OpenUri",
                "name": "Open in Sentinel QMS",
                "targets": [{"os": "default", "uri": link}],
            }
        ]
    return _post_json(cfg.teams_webhook_url, card)


def send_slack(
    cfg: ChannelConfig,
    title: str,
    body: str | None,
    link: str | None,
) -> tuple[bool, str]:
    """POST a simple message to a Slack incoming webhook. Returns (ok, detail)."""
    if not cfg.slack_webhook_url:
        return False, "No Slack webhook configured"
    parts = [f"*{title}*"]
    if body:
        parts.append(body)
    if link:
        parts.append(link)
    return _post_json(cfg.slack_webhook_url, {"text": "\n".join(parts)})


def _dispatch_all(
    recipient_email: str | None,
    title: str,
    body: str | None,
    link: str | None,
    cfg: ChannelConfig,
) -> None:
    """Send to every enabled channel. Runs inline or inside a worker thread."""
    if cfg.email_ready and recipient_email:
        ok, detail = send_email(cfg, recipient_email, title, body, link)
        if not ok:
            logger.warning("email channel failed: %s", detail)
    if cfg.teams_webhook_url:
        ok, detail = send_teams(cfg, title, body, link)
        if not ok:
            logger.warning("teams channel failed: %s", detail)
    if cfg.slack_webhook_url:
        ok, detail = send_slack(cfg, title, body, link)
        if not ok:
            logger.warning("slack channel failed: %s", detail)


def dispatch_notification(
    *,
    recipient_email: str | None,
    title: str,
    body: str | None,
    link: str | None,
    cfg: ChannelConfig,
    in_background: bool = True,
) -> None:
    """Fan a notification out to every enabled channel.

    Email is sent only when ``cfg.email_ready`` and a ``recipient_email`` is
    present; Teams/Slack are sent when their webhook URL is configured.

    When ``in_background`` (the default) the sends run in a daemon thread so the
    request is never blocked. Only plain data (``cfg`` is a frozen dataclass with
    no ORM/session references) crosses the thread boundary.
    """
    if in_background:
        thread = threading.Thread(
            target=_dispatch_all,
            args=(recipient_email, title, body, link, cfg),
            daemon=True,
        )
        thread.start()
        return
    _dispatch_all(recipient_email, title, body, link, cfg)
