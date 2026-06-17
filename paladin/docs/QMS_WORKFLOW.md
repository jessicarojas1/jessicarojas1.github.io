# PALADIN — QMS Workflows

How controlled documents move through their lifecycle, how approvals are routed,
and how electronic signatures and the audit trail make the process defensible in
ISO 9001 / ISO 13485 / FDA 21 CFR Part 11 contexts.

## 1. Controlled-document lifecycle

States and allowed transitions (`DocumentController::TRANSITIONS`):

```
draft ── in_review ── approved ── published(EFFECTIVE)
  │          │  │                    │
  │          │  └── rejected          ├── superseded
  │          └────────── (back to draft for revision)
  │                                   ├── retired
  └── archived                        ├── expired   ← also automatic
                                      └── archived / obsolete

superseded → retired | archived | draft
retired    → archived
expired    → draft | archived
```

- **draft** → in_review → **approved** → **published**. `published` is the
  *Effective* state of a controlled document.
- From **published** a document may be **superseded** (replaced by a newer
  revision), **retired** (withdrawn from use), or **expired** (past its
  validity).
- **Auto-expiry**: `Scheduler::runExpiredDocuments()` moves any effective
  document whose `expiration_date` has passed to **expired**, logs
  `document_auto_expired`, and dispatches the `document.expired` webhook. The
  sweep runs opportunistically (cron-free) and is idempotent.

Every transition is permission-checked (`document.edit`, plus
`document.publish`/`document.approve` to reach approved/published), recorded in
the audit trail as `document_transition` (from → to), and — for
approved/published/archived/expired/superseded/retired — dispatched as a webhook
event.

### Review & expiry management

- Documents carry `review_date` and `expiration_date`. Owners receive **email
  reminders** (`src/Reminders.php`) as these approach.
- The **Expiring & Overdue** report (`/reports/expiring`) lists items due within
  a 90-day horizon; reviewers can **extend (snooze)** a review date inline.
- The **Compliance Metrics** report (`/reports/compliance`) shows documents by
  status, review compliance, and approval throughput.

## 2. Approval workflows

Approvals route a request (a document, page, or process) through one or more
steps. Modes (`workflow_templates.approval_mode`):

| Mode | Behaviour |
|------|-----------|
| **single** | one approver (ad-hoc) |
| **sequential** | steps in order; each must approve before the next opens |
| **parallel** | all current steps are actionable at once |
| **consensus** | all approvers must approve |

Each step targets a specific **user** or a **role**. The request tracks
`current_step`; `ApprovalController::actionableStep()` decides whose decision is
pending. Decisions are **approve**, **reject**, or **return for revision**, each
written to `approval_history` and the audit log, and may sync the linked
document's lifecycle (e.g. approve → publish).

## 3. Electronic signatures (21 CFR Part 11)

When the `require_esignature` setting is enabled, an approve/reject decision
requires the signer to:

1. **type their full name exactly** as on their account, and
2. **re-authenticate with their password** at the moment of signing
   (`ApprovalController::decide`).

The signature record binds **signer identity, decision, meaning statement**
("I am approving/rejecting this record."), **timestamp, IP and user-agent** into
a SHA-256 `signature_hash`, and writes an immutable `esignature` entry to the
hash-chained audit trail. A failed re-authentication is rejected and audited
(`esignature_failed`). See `SECURITY.md` §8 and `AUDIT_TRAIL.md`.

## 4. Acknowledgement campaigns

For "read-and-understand" drives, an **acknowledgement campaign** targets a
published, ack-required document revision at an audience (everyone / a role / a
space's members) with a due date. Targets are resolved at launch for a stable
denominator; a target completes when they acknowledge the document revision.
Progress, reminders and a **completion CSV** (compliance evidence) are available
on the campaign page (`/campaigns`).

## 5. CAPA-style task tracking

Tasks (standalone and page-derived **action items**) carry assignee, priority,
due date and status. They surface on the assignee's **My Work** cockpit
(`/my-work`) and the **Calendar**, giving corrective/preventive actions a
trackable home with due-date visibility.

## 6. Evidence & reporting

- **Document register** CSV (`/documents/export`) — the controlled-document
  index with codes, revisions, statuses, owners and dates.
- **Acknowledgement coverage**, **approval backlog**, **content health** and
  **compliance metrics** reports under `/reports`.
- All CSV exports are protected against formula injection (`Csv::put`).
