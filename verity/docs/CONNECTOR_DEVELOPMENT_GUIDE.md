# Verity — Connector Development Guide

## 1. Why this exists as its own document

Until this build pass, every connector type in `connector.connector_type`
was a catalog/configuration entry only — no code actually synchronized
anything from a source system. That changed with the **CSV import**
connector (`app/Support/CsvImport.php`), the first real sync execution
engine in this app. This document captures the pattern it established, so
the next real connector (most likely Entra ID GCC High — see
`docs/GCC_HIGH_INTEGRATION.md`) follows the same shape rather than
reinventing one.

## 2. The one rule that matters more than any other

**A connector's `capability_manifest` must never claim a capability its
code doesn't actually exercise.** `Connectors::defaultManifest()` starts
every key in `Connectors::CAPABILITY_KEYS` at `false` and only flips one
to `true` for a connector type that has earned it. This is not a
formality — the Enterprise Access Matrix, the Application Catalog, and
(eventually) certification campaigns all read the manifest to tell an
operator what they can actually trust a connector to have done. A
connector that claims `disable_accounts` but never disables anything
would be worse than one that honestly claims nothing: it would make an
operator believe a risk is being managed when it isn't.

Concretely, this means: before writing a connector's sync code, decide
*exactly* which of the twelve capability keys it will exercise, write
`defaultManifest()`'s case for it first, and then make the sync code do
no more and no less than that manifest claims. `CsvImport` is scoped to
`discover_accounts` + `read_entitlement_assignments` only — see §3.

## 3. The CSV import connector as the reference implementation

`app/Support/CsvImport.php`'s `CsvImport::run(int $connectorId, string
$csvContents, ?int $actorId)` is the full implementation. Its shape:

1. **Validate the connector** — confirm it exists and is the expected
   `connector_type` before doing anything else.
2. **Open a `connector_sync_job` row** (`status = 'running'`) immediately,
   so a sync that crashes mid-way still leaves a record rather than
   silently vanishing.
3. **Parse and validate row-by-row**, collecting failures rather than
   aborting the whole run on the first bad row — a CSV an admin exported
   by hand will have typos; "stop at the first error" would make every
   real-world file need multiple round-trips to fully import. Cap the
   total rows processed (`CsvImport::MAX_DATA_ROWS`) so a mistakenly huge
   file can't run unbounded.
4. **Write with `INSERT ... ON CONFLICT`, never a separate
   "does this exist?" check-then-insert.** Every table this engine writes
   to — `system_account`, `entitlement`, `entitlement_assignment` — has a
   UNIQUE constraint keyed exactly on what makes a row the "same" one
   across repeated syncs; the upsert's `ON CONFLICT` target is that
   constraint. This is what makes re-running the same file idempotent
   (point 11 of the root `CLAUDE.md`) without a separate reconciliation
   pass.
5. **Only add/update what the manifest claims, never remove or disable
   what it doesn't.** `csv_import` doesn't claim `disable_accounts` or
   `revoke_access`, so this engine never disables an account or deletes
   an `entitlement_assignment` just because a later file omits a row that
   an earlier one included. If a connector type is ever built that
   *should* reconcile deletions (a full "this file is the complete truth"
   mode), that is a new, explicitly manifested capability decided
   up front — not a quiet addition to an existing one.
6. **Close the `connector_sync_job` row** with final counts
   (`imported_accounts`, `imported_entitlements`, `failure_count`,
   `error_summary`) and a terminal `status` —
   `succeeded` (zero failures), `partial` (some rows succeeded, some
   didn't), or `failed` (nothing usable came out of the file, e.g. a
   missing required column). Update `connector.connection_health` to
   match, and write one `connector.sync` audit event with the same
   summary.

## 4. Handling untrusted input from a connector (file upload or API)

`ApplicationsController::syncCsv()` is the HTTP-facing half — read it
alongside `CsvImport::run()` for the full picture. Rules that apply to
**any** future connector that accepts a file or calls an external API,
not just this one:

- **Never trust a client-supplied MIME type or filename.** The upload's
  reported `type` is advisory only; this app checks the file extension
  (`.csv`) as a coarse allowlist and otherwise treats the content as
  nothing more than a string to parse — it is never `include`d, `eval`'d,
  or used to construct a filesystem path from client input.
  `is_uploaded_file()` confirms the tmp file genuinely came through PHP's
  own upload mechanism before it's touched.
- **Never persist the raw upload.** `syncCsv()` reads the file into a PHP
  string from its randomized `tmp_name` and discards it; nothing is
  copied to a permanent, web-reachable path. If a future connector type
  ever needs to retain the source file (e.g. for a later reprocessing
  or audit-replay feature), that is a deliberate, separate decision —
  store it outside the web root with a randomized name, never the
  client's original filename, consistent with the root `CLAUDE.md`'s
  file-upload checklist.
- **Cap size and row count before doing any real work**
  (`CsvImport::MAX_FILE_BYTES`, `CsvImport::MAX_DATA_ROWS`) — an
  unbounded upload or an unbounded parse loop is a resource-exhaustion
  risk regardless of how trusted the uploader is.
- **Parameterized SQL only**, exactly like every other module — a
  connector is not an exception to this just because its data originated
  outside the app.
- **Gate the sync action behind the same permission that gates connector
  configuration** (`connector.manage` today) rather than inventing a new
  granular permission per connector type, unless a real need to separate
  "can configure" from "can trigger a sync" shows up in practice.
- **Re-verify anything security- or audit-relevant server-side, never
  take a client's word for it.** See `docs/SECURITY.md`'s note on
  `Accounts::suggestMatch()`/`link_method` for the general pattern this
  app already follows elsewhere.

## 5. What would change for a live API-based connector (e.g. Entra GCC High)

CSV import's source of truth is a file the caller already has; an API
connector's source of truth is a remote service the connector must
authenticate to and call. The sync-engine shape above (open a job,
process, upsert idempotently, respect the manifest, close the job) still
applies, but additionally:

- Credentials come from `Config`/environment variables or a secrets
  manager reference stored in `connector.credential_reference` — never a
  literal secret in that column or anywhere in the database.
- A failed or partial API call is a `connector_sync_job.status = 'failed'`
  /`'partial'` outcome with `error_summary` describing *what* failed
  (timeout, auth rejected, rate limited) — not a generic "sync failed"
  with no diagnostic value, mirroring how `CsvImport` names the specific
  validation failure per row rather than a blanket error.
- GCC High specifically: `graph.microsoft.us`/`login.microsoftonline.us`
  only, never the commercial endpoints — see `Config`'s GCC High
  accessors and `docs/GCC_HIGH_INTEGRATION.md`.
- Network calls need their own timeout/fail-open-or-fail-closed decision,
  the same way `Auth::isPasswordBreached()` fails open on a breach-check
  API outage — except a connector sync almost certainly should **fail
  closed** (report the sync as failed) rather than silently proceed with
  stale or absent data, since a sync is the *source* of truth for an
  access-governance record, not an optional enhancement to one. Decide
  this explicitly for the connector being built; don't assume either
  default.

## 6. Verification status

CSV import is implemented and tested: `tests/db_test.php`'s
"`CsvImport::run` — the first real connector sync engine" group covers a
mixed valid/invalid-row import, idempotent re-import (no duplicate
accounts, entitlements from an earlier import are not removed by a later
one that omits them), and the missing-required-header failure path.
Verified live end-to-end through the real HTTP upload route (not just the
underlying function): a successful partial import, the wrong-extension
rejection, the invalid-connector rejection, and the permission boundary
(a role without `connector.manage` gets `403`) were each exercised
directly against the running application.
