# Verity — User Guide

How to use Verity day to day. This describes exactly what exists in the
running application today — nothing aspirational. For what's not built yet
(risk scoring, separation-of-duties, MFA, Entra SSO), see
[`../OPEN_ITEMS.md`](../OPEN_ITEMS.md). For the developer/operator docs,
see [`ARCHITECTURE.md`](ARCHITECTURE.md), [`DEPLOYMENT.md`](DEPLOYMENT.md),
and [`SECURITY.md`](SECURITY.md).

## Contents

1. [Signing in](#signing-in)
2. [Navigation and what you can see](#navigation-and-what-you-can-see)
3. [Dashboard](#dashboard)
4. [My Account](#my-account)
5. [Identity Directory](#identity-directory)
6. [Enterprise Access Matrix](#enterprise-access-matrix)
7. [Unmatched Accounts](#unmatched-accounts)
8. [Application Catalog](#application-catalog)
9. [Certification Campaigns](#certification-campaigns)
10. [My Reviews](#my-reviews)
11. [Remediation Tasks](#remediation-tasks)
12. [Access Requests](#access-requests)
13. [Dynamic Fields](#dynamic-fields)
14. [Access & Security (Admin IAM)](#access--security-admin-iam)
15. [Settings & Branding](#settings--branding)
16. [Audit History](#audit-history)
17. [Role quick reference](#role-quick-reference)

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
- **Active Campaigns** and **Pending Campaign Reviews** — how many
  certification campaigns are currently running, and how many of their
  items are still awaiting a reviewer's decision
- **Open Remediation Tasks** — access findings flagged for action and not
  yet resolved or dismissed
- **Pending Access Requests** — requests awaiting an owner's or admin's
  decision
- Three breakdown tables: accounts by application, identities by department,
  and connector health

A note at the bottom explains that risk-intelligence and
separation-of-duties KPIs aren't shown — those modules don't exist yet,
and showing a zero for them would misleadingly suggest "nothing
outstanding" rather than "not built."

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

The list itself may show a **Suggested Match** badge next to an account —
Verity found exactly one person whose employee ID or email exactly matches
(or, at medium confidence, whose email's name-part matches) the account's
own identifiers. This is a suggestion only: nothing is ever linked until a
person clicks to confirm it.

Click **Link / Review** on an account to open its detail page. From there:

- **If a suggested match appears**, review the name and the reason given,
  then click **Accept Suggested Match** (with an optional note) to link it.
- **To link it to someone else, or when there's no suggestion**: type a few
  letters of the person's name, email, or employee ID under "Or search
  manually," click the matching result, optionally add a note, and click
  **Link Account**.
- **To unlink** an already-linked account: open it, optionally give a
  reason, and click **Unlink Identity** (you'll be asked to confirm).

Every link and unlink is recorded in that account's **Correlation
History**, with who did it, when, and the method — `deterministic` for an
accepted suggestion, `manual` for anything typed and picked by hand.
Correlation always requires a person to click confirm; Verity never
auto-merges accounts on its own, no matter how confident a suggestion is.

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

**CSV Import connectors actually run.** Add one with connector type "CSV
Import," then use the **Import CSV** form that appears under it to upload
a file. Expected columns: `external_account_id` (required), `username`,
`account_type` (`standard`/`privileged`/`service`/`shared`), `status`
(`enabled`/`disabled`), and `entitlements` (pipe-separated names — created
automatically if they don't already exist for this application). A result
banner shows right away (accounts imported, entitlements assigned, rows
that failed), and every run is kept in that connector's **Sync History**
with the specific reason any row failed. This import only ever adds or
updates — running it again with a smaller file never disables an account
or removes an entitlement that an earlier file granted; to remove access,
use the Access Matrix or Unmatched Accounts workspace directly.

## Certification Campaigns

*(Requires "Campaigns" in your nav — `campaign.view` or `campaign.manage`.)*
An access-review campaign. The list shows every campaign's status, item
count, how many items are decided, and how many were revoked.

If you have `campaign.manage`, you can **Launch Campaign** from the list
page:

- **Scope** — one application, all privileged access, or the entire
  enterprise. Whatever is in scope **at the moment you launch** is what
  gets reviewed — the scope is a frozen snapshot, not a live query, so a
  campaign is never a moving target mid-review. Anything added to the
  system after launch is simply not part of that campaign.
- **Reviewer assignment** — either each account holder's own manager (an
  unmatched account, or a person with no manager, falls back to the
  default reviewer you pick), or one named reviewer for every item.
- A **default / fallback reviewer** is always required, for exactly that
  fallback case.

Click a campaign to see its progress (total/approved/revoked/pending) and
every item's current decision, reviewer, and who decided it. If you have
`campaign.manage`, you can **Complete Campaign** (marks it done; any
still-pending items stay recorded as pending) or **Cancel Campaign**.

**A "Revoked" decision does not remove the access by itself.** It records
that a reviewer decided the access should not continue — Verity has no
live connector that can push a revocation back to a source system, so
deleting its own inventory record of the access would claim a removal
that didn't actually happen anywhere. Instead, revoking an item
automatically opens a [Remediation Task](#remediation-tasks) so it's
tracked — actually removing the access is still a manual step, through
the Access Matrix or the account's own detail page, but it's no longer
something you have to remember to do separately.

## My Reviews

*(Requires `campaign.review` — supervisors, system owners, and security
admins have this by default.)* Entitlement assignments a campaign has
assigned to *you* specifically for review, across every active campaign.
Switch between "Pending only" and "All (including decided)."

For each pending item, add an optional note and click **Approve** (this
updates the item's `last_certified_at` — the "last reviewed" record for
that specific access) or **Revoke** (you'll be asked to confirm — see the
note above about what Revoke does and doesn't do). Once decided, an item
shows its outcome instead of the action buttons; decisions cannot be
undone from this page.

## Remediation Tasks

*(Requires "Remediation" in your nav — `remediation.view` or
`remediation.manage`.)* A tracked to-do for an access finding — this
workspace lists every task, open or closed, with who it's for and what's
needed.

Tasks come from two places:
- **Automatically**, when a certification campaign reviewer revokes an
  item (see above) — no action needed to create these, they just appear.
- **Manually**, from any account's detail page: click **Flag for
  Remediation**, pick a task type (Remove access / Disable account /
  Investigate), optionally point it at one specific entitlement rather
  than the whole account, and add a description.

If you have `remediation.manage`, each open task has **Resolve** (with an
optional note — use this once you've actually made the change, wherever
you made it) and **Dismiss** (for a false positive or a finding that
turns out not to need action). Both require a reason-worthy note when it
matters, and both are final — a resolved or dismissed task stays that way.
**Resolving a task does not itself change any access** — exactly like
revoking a campaign item, this app only tracks that something needs doing
(or was done); it never executes the removal for you.

## Access Requests

*(Requires "Access Requests" in your nav — `accessrequest.create`,
`accessrequest.view`, `accessrequest.approve.owned`, or
`accessrequest.manage`.)* Ask for a specific entitlement to be added to a
specific account, and have the application's owner (or an admin) decide.

What you see in the list depends on your role: an admin or anyone with
`accessrequest.view` sees every request; a system owner without that
broader permission sees their own requests plus any request for an
application they own; everyone else sees just their own.

**New Request**: pick an **Application**, then an **Account** (the
dropdown fills in once you've picked the application), then an
**Entitlement** (fills in once you've picked the account — only
entitlements that account doesn't already hold are offered), then write a
**Justification**. Note that you're always requesting access for an
account that's *already on record* — Verity doesn't create new accounts,
so if the person has no account in an application yet, there's nothing to
select there.

If you have decision authority over a pending request (you own the
application, or you're an admin), you'll see **Approve** and **Deny**
buttons with an optional note field. **Approving actually grants the
access** — it creates the entitlement assignment in Verity's inventory
right away, unlike a campaign revoke or a remediation task, which only
ever record a decision. Denying does not. If it's your own still-pending
request, you'll see **Cancel** instead, to withdraw it.

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
| **Security / Compliance Admin** | Broad read access, governance configuration (applications, connectors, dynamic fields, settings), account correlation, user/permission management, launching/managing certification campaigns and remediation tasks, approving/denying any access request, and acting as a reviewer — a strong admin role without the unconditional wildcard |
| **Supervisor** | Their own reporting chain only — identities, accounts, entitlements, and the Supervisor Access Matrix view, scoped automatically — plus acting as a reviewer on campaign items assigned to them (typically their own direct reports' access) and submitting access requests |
| **System Owner** | Applications where they're recorded as the system owner — that application's access matrix view and entitlement visibility, plus approving or denying access requests for those applications specifically — plus acting as a reviewer on campaign items assigned to them and submitting access requests |
| **Auditor** | Enterprise-wide read access to identities, accounts, entitlements, the matrix, the audit log, campaign progress, remediation tasks, and access requests — no write access anywhere, including campaigns, remediation, and access requests (view only) |

An administrator can also layer explicit grants or denials on top of any
role for an individual user — see [Access & Security](#access--security-admin-iam)
above.
