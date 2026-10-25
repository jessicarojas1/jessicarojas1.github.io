# ADR-0010: Group-based access control with a directory adapter

- **Status:** Proposed (owner decision D5, 2026-10-25: "there needs to be group access")
- **Date:** 2026-10-25

## Decision
- **Principals** are users and groups. Groups are synchronized from the organisation's directory through a `DirectoryAdapter`. **Selected: Entra ID via Microsoft Graph, read-only** (owner D18, 2026-10-25: Entra for sign-in and group sync only). The adapter interface stays, so an LDAP/AD adapter could be added later without touching the authorization code. Sync is limited to groups referenced by PAL grants or listed by an admin, not the whole directory.
- **Sync, not token claims.** Group membership is synchronized on a schedule and refreshed at login. PAL does not rely on the `groups` claim in the ID token: Entra omits it for users in more than 200 groups (the "overage" case), which would silently break access. Group references use the directory's immutable object ID, never the display name.
- **Grants:** `access_grant(scope_type CATEGORY|DOCUMENT, scope_id, principal_type USER|GROUP, principal_id, role READER|AUTHOR|REVIEWER|APPROVER|OWNER, valid_from, valid_to, granted_by)`.
  - Documents inherit grants from their category, and a document can add its own grants on top.
  - **Allow-only, no explicit deny.** Deny rules make "who can see this?" hard to answer and to audit. Restriction is done by marking a document or category "restricted", which drops the all-employee default read access.
- **Effective access** is materialized into `document_access(document_id, principal_id, role)` and kept current on grant, category-move or membership change. Search and list queries join the user's principal set (the user plus their groups) inside SQL.
- **Workflow participants** may be groups ("any member of Document Control Approvers"). Group members are resolved to individual tasks when the workflow starts, so later membership changes don't alter an approval already in progress.
- **Timeliness:** removal from a group takes effect at the next sync (target ≤ 15 minutes) or at the user's next login, whichever comes first. A user disabled in the directory is blocked at login and their sessions are revoked at the next sync.

## Consequences
- Requires Entra admin consent for **read-only** Graph application permissions (`GroupMember.Read.All`; `User.Read.All` to detect disabled accounts; least-privilege set confirmed in Phase 1) (D16). Authenticate with a certificate, not a client secret.
- Grants and membership changes are audited, which keeps "who could read this document on date X?" answerable.
