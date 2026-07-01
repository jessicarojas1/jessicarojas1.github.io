# PALADIN — Open Items (Production-Readiness Register)

An honest register of what is done versus outstanding for a production PALADIN
deployment. Items are grouped by theme; each carries an **Impact** and a
**Suggested action**. None of the outstanding items block secure operation —
they are scope boundaries, hardening opportunities, and operational follow-ups.
This register is a living document: update it whenever a feature, migration, or
config change lands (see `CLAUDE.md`).

Legend: ✅ done · 🟡 partial / needs operator action · ⛔ not implemented (by design or backlog)

---

## Background processing & scheduling

- 🟡 **Opportunistic sweeps instead of a dedicated worker.** Scheduled
  publishing, document auto-expiry and webhook retries run on ordinary
  authenticated requests, not a background daemon.
  - *Impact:* on a low-traffic site, time-based actions are delayed until the
    next request.
  - *Action:* run `cli/send_digests.php` and `cli/send_review_reminders.php`
    (and, if needed, a request to trigger the `Scheduler`) from cron/systemd
    timers or Render cron. See `docs/DEPLOYMENT.md` and `deployments/`.
- 🟡 **Webhook delivery is best-effort.** Bounded exponential backoff (4
  attempts: 60s → 5m → 30m) then give-up; no dead-letter replay UI beyond the
  deliveries log.
  - *Impact:* a downstream outage longer than the backoff window drops events.
  - *Action:* monitor the deliveries log; add an external queue if guaranteed
    delivery is required.

## Email / notifications

- 🟡 **SMTP required for outbound mail.** Without a configured transport,
  messages queue in `mail_outbox` (visible in admin) rather than send.
  - *Impact:* digests, review reminders and share notifications are not
    delivered until SMTP is set.
  - *Action:* set `SMTP_*` env vars; verify with the digest CLI.

## Identity & SSO

- ✅ Form login, SAML 2.0 (signed/encrypted assertions), OIDC (Auth Code + PKCE),
  SCIM 2.0 core provisioning, PATs, MFA/recovery codes are implemented.
- 🟡 **IdP-specific tuning.** Provider quirks (claim mapping, signature algos)
  may need per-IdP configuration.
  - *Impact:* first-time SSO onboarding needs a test cycle.
  - *Action:* validate against your IdP in staging before rollout; document the
    working config.

## Data protection & storage

- ✅ Attachment storage supports `local` and `s3`; S3 secrets are encrypted at
  rest (AES-256-GCM) via `Security::encryptSetting`; presigned URLs + SigV4.
- 🟡 **Object storage recommended for multi-replica / HA.** Local disk is
  per-instance.
  - *Impact:* horizontal scaling with `local` storage splits attachments across
    replicas.
  - *Action:* set `STORAGE_DRIVER=s3` (or Azure Blob via S3-compatible gateway)
    for any multi-replica deployment. See `deployments/KUBERNETES.md`.

## Auditability

- ✅ `activity_log` is hash-chained and append-only in application code —
  tampering is **detectable**.
- 🟡 **Not storage-level WORM by default.**
  - *Impact:* a privileged DB user could alter rows (breaking the chain, but
    still a gap for the strictest requirements).
  - *Action:* restrict the app DB role to `INSERT`/`SELECT` on `activity_log`
    and ship to append-only/WORM storage or a SIEM. See `docs/AUDIT_TRAIL.md`.

## Authorization

- ✅ Object-level access for pages (restrictions + space privacy), documents/
  processes (space privacy) and attachments.
- ⛔ **No per-record document restrictions beyond space privacy.**
  - *Impact:* sensitive documents rely on private spaces for compartmentation.
  - *Action:* use private spaces; model per-record ACLs if a future requirement.

## Search & scale

- 🟡 **Search is PostgreSQL `ILIKE` + filters**, not a ranked engine.
  - *Impact:* fine for typical wikis; not tuned for very large corpora.
  - *Action:* add Postgres full-text (`tsvector`) or an external index at scale.
- 🟡 **Single-instance Postgres assumed.**
  - *Action:* add read replicas + object storage + a dedicated job runner for
    very large deployments. See `docs/DISASTER_RECOVERY.md` for HA.

## Editor / content

- ✅ Versioned saves, autosave/draft recovery, optimistic-concurrency conflict
  detection.
- ⛔ **No real-time co-editing (CRDT/OT), no presence.**
  - *Impact:* simultaneous single-page co-editing is out of scope; conflicts are
    blocked, not merged.
  - *Action:* backlog enhancement; not required for compliant operation.
- 🟡 **Exports:** native `.docx` for pages/controlled documents; whole-space
  export uses a legacy HTML-based `.doc`; PDF is server-rendered from HTML.
  - *Action:* validate export fidelity for your templates.

## Observability

- ✅ Health endpoints: `/health` (deep — DB + disk), `/healthz`, `/readyz`.
- 🟡 **No built-in metrics/trace exporter.**
  - *Impact:* relies on request logs + platform metrics.
  - *Action:* front with a reverse proxy that emits access metrics; scrape
    `/health`; forward logs to your SIEM.

## Deployment hygiene

- ✅ Dockerfile (non-root, healthcheck) and `render.yaml` present; `install.php`
  applies schema + migrations idempotently with retry.
- 🟡 **Set dedicated secrets in production** (`JWT_SECRET`, encryption keys,
  `SMTP_*`) via a secret manager, not env files.
  - *Action:* use AWS Secrets Manager / Azure Key Vault / CSI as shown in
    `deployments/AWS.md` / `deployments/AZURE.md` / `deployments/KUBERNETES.md`.
- 🟡 **FIPS mode** depends on the host crypto module.
  - *Action:* deploy on a FIPS-validated base/OpenSSL for FIPS environments; see
    `docs/SECURITY.md`.

---

_Keep this file current: when an item is resolved, move it to ✅ with the commit
that closed it; when a new gap is found, add it with impact + action._
