# PALADIN — Known Limitations

An honest list of current constraints and the assumptions PALADIN makes, so
operators can plan around them. None of these prevent secure production use; they
are scope boundaries and operational notes.

## Background processing / scheduling

- **Cron-free, opportunistic sweeps.** Scheduled publishing, document
  auto-expiry and **webhook retries** run on common authenticated requests
  (e.g. dashboard load) rather than a dedicated worker. On a site with no
  traffic, these actions wait until the next request. For guaranteed timeliness,
  invoke the relevant entrypoints from a real cron/systemd timer (see
  `DEPLOYMENT.md`).
- **Webhook retry** uses a bounded exponential backoff (4 attempts, 60s → 5m →
  30m) and then gives up; there is no infinite queue or dead-letter replay UI
  beyond the deliveries log.

## Email / notifications

- Email is delivered via a configured SMTP transport; without one, messages
  remain **queued** in `mail_outbox` (inspectable in admin) rather than sent.
- Digest/reminder cadence depends on the sweep cadence above.

## Exports

- **Word export is HTML-based `.doc`** (Word-compatible HTML), not native binary
  **OOXML `.docx`**. It opens and prints correctly in Word/LibreOffice but is not
  a true `.docx` package.
- **PDF** is server-rendered from HTML; very complex CSS/layouts may differ from
  a browser print.

## Editor / content

- The editor is a lightweight WYSIWYG (contenteditable + sanitised HTML source).
  It is **not real-time collaborative** — there is no presence or operational-
  transform/CRDT co-editing. Concurrent edits are handled defensively rather
  than merged: autosave + draft recovery guard against lost work, every save is
  versioned, and **optimistic concurrency** detects when a page changed
  underneath you — the conflicting save is blocked with a warning (your text is
  preserved) instead of silently overwriting the other edit. True simultaneous
  co-editing of one page is still out of scope.
- **Broken-link detection** covers internal `/pages/N` and `/documents/N` links;
  external URLs are not crawled.

## Audit immutability

- The audit log is **hash-chained and append-only in application code**, making
  tampering **detectable**. It is not, by itself, write-once at the storage
  layer. For the strongest guarantees, also restrict the DB role to
  `INSERT`/`SELECT` on `activity_log` and ship to append-only/WORM storage or a
  SIEM (see `AUDIT_TRAIL.md`).

## Authorization scope

- Object-level access is enforced for pages (restrictions + space privacy),
  documents/processes (space privacy) and attachments. Document-level *per-record*
  restrictions beyond space privacy are not modelled; use private spaces to
  compartmentalise sensitive documents.

## Identity

- SAML/OIDC/SCIM are implemented against the common standards (signed/encrypted
  SAML assertions, OIDC Authorization Code + PKCE, SCIM 2.0 core). Provider-
  specific quirks may need configuration; test with your IdP before rollout.

## Search

- Full-text search uses PostgreSQL `ILIKE` matching with filters (space, label,
  type, owner, status). It is effective for typical wikis but is not a tuned
  ranked search engine for very large corpora.

## Scale

- The design targets team-to-enterprise document/knowledge workloads on a single
  PostgreSQL instance. Extremely large deployments may want read replicas,
  object storage for attachments (configurable), and a dedicated job runner.

## Not included

- Real-time co-editing, a visual macro-browser dialog with per-macro parameter
  forms, native `.docx`/`.xlsx` generation, and threaded inline-comment replies
  are not implemented. These are enhancements, not requirements for secure,
  compliant operation.
