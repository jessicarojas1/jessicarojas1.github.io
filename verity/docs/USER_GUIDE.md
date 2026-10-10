# Verity — User Guide

How to use Verity day to day. This describes exactly what exists in the
running application today (Phases 1–3) — nothing aspirational. For what's
not built yet (certification campaigns, approval workflows, live connector
sync, Entra SSO), see [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md). For the
developer/operator docs, see [`ARCHITECTURE.md`](ARCHITECTURE.md),
[`DEPLOYMENT.md`](DEPLOYMENT.md), and [`SECURITY.md`](SECURITY.md).

## Contents

1. [Signing in](#signing-in)
2. [Navigation and what you can see](#navigation-and-what-you-can-see)
3. [Dashboard](#dashboard)
4. [My Account](#my-account)
5. [Identity Directory](#identity-directory)
6. [Enterprise Access Matrix](#enterprise-access-matrix)
7. [Unmatched Accounts](#unmatched-accounts)
8. [Application Catalog](#application-catalog)
9. [Dynamic Fields](#dynamic-fields)
10. [Access & Security (Admin IAM)](#access--security-admin-iam)
11. [Settings & Branding](#settings--branding)
12. [Audit History](#audit-history)
13. [Role quick reference](#role-quick-reference)

## Signing in

Go to your Verity URL and sign in with your email and password at
`/auth/login`. There is no "Sign in with Microsoft" button today —
Entra ID GCC High single sign-on is planned but not implemented yet (see
[`GCC_HIGH_INTEGRATION.md`](GCC_HIGH_INTEGRATION.md)); local email/password
is the only way in.

If you don't have an account, ask an administrator to create one for you
(see [Access & Security](#access--security-admin-iam) below) — there is no
public self-registration page.

## Navigation and what you can see

The top navigation bar only shows the modules you actually have permission
for — if you don't see "Access & Security" or "Settings," you don't have
that permission, not that the feature is broken. Your name, top right,
always links to [My Account](#my-account) regardless of role.

## Dashboard

Your landing page after signing in. Every number is a live count from the
database — not a mockup:

- **Total / Active Identities**, **Connected Applications**, **Discovered
  Accounts**, **Entitlement Assignments**
- **Privileged Accounts**, **Unmatched Accounts**, **Disabled Accounts**
- **Terminated, Still Enabled** and **Expired Temporary Access** — these two
  are risk indicators worth checking regularly; a non-zero count here means
  something needs attention
- Three breakdown tables: accounts by application, identities by department,
  and connector health

A note at the bottom explains that campaign/remediation/risk KPIs aren't
shown — those modules don't exist yet, and showing a zero for them would
misleadingly suggest "nothing outstanding" rather than "not built."

## My Account

Click your name (top right) to get here. Shows your profile (name, email,
roles, status) and lets you change your own password — available to every
signed-in user regardless of role.

To change your password you need your **current** password first. Enter it,
then your new password (at least 12 characters — length matters more than
mixing in symbols), confirm it, and submit. Your session is refreshed
automatically; you stay signed in.

Role and permission changes aren't self-service — ask an administrator.

## Identity Directory

*(Requires "Identities" to appear in your nav — supervisors see only their
own reporting chain here, everyone else with access sees the full
directory.)*

A list of every person Verity knows about — employees, contractors, guests,
service identities. Filter by search term, department, employment status,
or identity type.

Click a name to open their profile:

- **Profile** — employee ID, email, department, manager (click through to
  theirs), location, employment status, start date
- **Direct Reports** — anyone who reports to this person
- **System Accounts** — every account this person has across every
  connected application, with a link to each account's detail page. This is
  the "one person → many accounts" view — the core promise of an identity
  governance platform

Click **View in Access Matrix** to jump straight to this person's access,
pre-filtered.

## Enterprise Access Matrix

*(Requires "Access Matrix" in your nav.)* This is the flagship screen —
who has access to what, across every connected application, in one table.

**Views** (shown as tabs — you'll only see the ones you have permission
for):

| View | Shows |
|---|---|
| Enterprise | Everything, enterprise-wide |
| Supervisor | Only people in your own reporting chain |
| Privileged Access | Only privileged entitlements/accounts, across the whole enterprise |
| Exception | Orphaned (unmatched) accounts, disabled-but-still-entitled accounts, and expired temporary access — the things worth investigating |

Two more views exist but aren't reachable as tabs — **Person** and
**Application** views open when you click "View in Access Matrix" from an
identity's or application's detail page.

**Filters**: free-text search (matches person, account, entitlement, or
application name), department, account status, assignment type (direct,
inherited, privileged, temporary, external). Combine filters and submit.

**Saved views**: click **Save this view** to name and store your current
filter combination for next time. Check "Share organization-wide" to make
it visible to everyone instead of just you (requires the
`savedview.manage.shared` permission).

**Export**: **Export CSV (current view)** downloads exactly what's on
screen — same filters, same view, including any custom Dynamic Fields
columns — capped at 5,000 rows. It's permission-checked the same way the
screen itself is; you can't export more than you can see.

The table paginates server-side (50 rows at a time) — even at enterprise
scale, your browser never has to hold the full inventory in memory.

## Unmatched Accounts

*(Requires "Unmatched Accounts" in your nav.)* Accounts discovered in a
connected application with no linked person — orphaned accounts,
essentially. Filter by application or search term.

Click **Link / Review** on an account to open its detail page. From there:

- **To link it**: type a few letters of the person's name, email, or
  employee ID in "Link to identity," click the matching result, optionally
  add a note, and click **Link Account**.
- **To unlink** an already-linked account: open it, optionally give a
  reason, and click **Unlink Identity** (you'll be asked to confirm).

Every link and unlink is recorded in that account's **Correlation
History**, with who did it and when — correlation here is always manual and
always audited; Verity never auto-merges accounts based on a name or email
match alone.

## Application Catalog

*(Requires "Applications" in your nav.)* The list of every connected
system — name, classification, system owner, account/entitlement/connector
counts.

Click an application to see its ownership details (system/technical/
business owner), its connectors (type, capabilities, health, last sync),
and its entitlements (name, type, privileged flag, risk level).

If you have `application.manage`, you can **Add Application** from the list
page, and **Add Connector** from an application's detail page. A connector's
**Capabilities** column shows exactly what it's confirmed to support — if
nothing is listed, that connector hasn't demonstrated any automated
capability (this is deliberately honest, not a bug: see
[`GCC_HIGH_INTEGRATION.md`](GCC_HIGH_INTEGRATION.md) for why the "Microsoft
Entra ID (GCC High)" connector type is explicitly a mock today, with no live
synchronization).

## Dynamic Fields

*(Requires `dynamicfield.manage`.)* Custom attributes you can attach to
accounts, entitlement assignments, or people — without any code change.
Examples already in the seed data: a COMS-specific "GERP Role" field.

To add one: give it a key (machine-readable, e.g. `coms_gerp_role`), a
label (human-readable), a type (text, number, date, single/multi-select,
etc.), what it applies to (account/entitlement assignment/person), and
whether it's global or scoped to one application. Active fields
automatically appear as extra columns in the Access Matrix and its CSV
export.

**Retiring** a field (with confirmation) never deletes its historical
values — it's flagged inactive and simply stops accepting new values or
showing up as a column. Nothing is ever silently lost.

## Access & Security (Admin IAM)

*(Requires `iam.view`; management actions require `iam.manage`.)* Platform
users, roles, and permissions, in one two-pane console.

**Creating a user** (`iam.manage` only): click **Invite User**, fill in
email, display name, an initial password (type one or click **Generate**
for a cryptographically random one — there's no email delivery in this
build, so you'll need to share it with the new user directly), optionally
link them to an existing identity, and check their role(s). They can sign
in immediately.

**Managing an existing user**: click their name in the left-hand list. You
get:

- **User Details** — edit display name, email, linked identity, and status
  (active/invited/disabled). Click **Save Details** for the first three,
  **Update Status** for status changes (asks for confirmation). You cannot
  change your own status — that's blocked on purpose, so there's always at
  least one way to regain access without touching the database directly.
- **Reset Password** — type a new password or click **Generate**, then
  **Set Password**. Same "share it yourself" caveat as account creation.
- **Roles** — check/uncheck role memberships.
- **Permissions** — below the roles, every module is broken into its
  granular permissions. Each one shows its current state as a colored dot
  and a button you can click to cycle through **role** (inherited default)
  → **grant** (explicit override on) → **deny** (explicit override off,
  always wins even over an admin's wildcard role) → back to role. Click
  **Save changes** when done — nothing is saved until you do.

**One thing worth knowing**: if you change someone's permissions or
password while they're already signed in, it doesn't affect their *current*
session — only future sign-ins. There's no "force sign-out" button yet (see
[`../OPEN_ITEMS.md`](../OPEN_ITEMS.md)).

## Settings & Branding

*(Requires `settings.manage`.)* Set your organization's display name, logo
(paste a URL, or upload a file — it's converted to the right format and
stored, no file server involved), and accent color (with a live preview as
you pick). Click **Save Branding**. Takes effect immediately, everywhere,
including the sign-in page.

## Audit History

*(Requires `audit.view`.)* Every material action in Verity — sign-ins,
permission changes, account links/unlinks, application/connector edits,
dynamic field changes, settings changes, CSV exports, and every denied
permission check — shows up here, append-only. Nothing is ever edited or
deleted; a correction is always a new row, never a changed one. Filter by
typing part of an action name (e.g. `account.link`).

## Role quick reference

| Role | Can generally do |
|---|---|
| **Enterprise Administrator** | Everything, by default (the wildcard role) — individual permissions can still be explicitly denied to override this |
| **Security / Compliance Admin** | Broad read access, governance configuration (applications, connectors, dynamic fields, settings), account correlation, user/permission management — a strong admin role without the unconditional wildcard |
| **Supervisor** | Their own reporting chain only — identities, accounts, entitlements, and the Supervisor Access Matrix view, scoped automatically |
| **System Owner** | Applications where they're recorded as the system owner — that application's access matrix view and entitlement visibility |
| **Auditor** | Enterprise-wide read access to identities, accounts, entitlements, the matrix, and the audit log — no write access anywhere |

An administrator can also layer explicit grants or denials on top of any
role for an individual user — see [Access & Security](#access--security-admin-iam)
above.
