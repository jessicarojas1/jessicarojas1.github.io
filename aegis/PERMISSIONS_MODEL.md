# AEGIS — Permissions Model (RBAC)

AEGIS uses **granular `module.action` permissions**. Roles provide a default set
of permissions; per-user **explicit grants** in the `user_permissions` table
extend those defaults. Authorization is always enforced **server-side**.

## Permission strings

A permission is `module.action`, e.g.:

```
risk.view  risk.create  risk.edit  risk.delete  risk.accept  risk.review
risk.treatment  risk.scenarios  risk.bowtie  risk.export
compliance.view  compliance.assess  compliance.import  compliance.test  compliance.gap
audit.view  audit.create  audit.findings  audit.close
policy.view  policy.create  policy.edit  policy.publish  policy.attest
incident.* vendor.* issue.* change.* threat.* asset.* kri.* bcp.* ssp.*
awareness.* automation.* approval.* report.view
```

This granularity lets you grant, say, "record a KRI reading" (`kri.record`)
without granting "manage KRI thresholds" (`kri.manage`), or "accept a risk"
(`risk.accept`) without "delete a risk" (`risk.delete`).

## Roles

The canonical role list lives in `Auth::ROLES` (role → label) and the default
permission maps in `Auth::$roleDefaults`. `Auth::roles()` and
`Auth::isValidRole()` are the single source of truth for the admin user form, the
SSO role-mapping screen, and server-side role validation — never hard-code role
lists in controllers or views.

| Role | Intent | Shape of access |
|------|--------|-----------------|
| `admin` | Platform administrator | **Bypass** — `can()` returns `true` for everything (see below). |
| `manager` | Module owner / team lead | Broad create/edit/delete plus lifecycle actions (`accept`, `publish`, `close`, `approve`). |
| `auditor` | Internal/external auditor | Broad **read** across all modules + full ownership of audits and findings (`audit.create/edit/findings/close`, `compliance.test/gap`). |
| `control_owner` | Implements & evidences controls | `compliance.assess/test`, `policy.attest`, `ssp.edit`, `kri.record`, `risk.treatment`. |
| `risk_owner` | Owns the risk lifecycle | Full risk actions incl. `risk.accept`, `kri.manage/record`, `approval.approve`. |
| `analyst` | Day-to-day practitioner | Create/edit/assess/record; **no** delete, publish, or close. |
| `executive` | Leadership / board | Read everything, run reports, and `approval.approve`. |
| `viewer` | Read-only stakeholder | `view` across modules (+ `policy.attest`). |

The default maps are the source of truth — consult `$roleDefaults` for the exact
action list per module per role. `Auth::roleDefaultPermissions($role)` returns the
flattened `module.action` list a role grants (pure, no DB; `['*']` for admin) and
is covered by `tests/test_rbac.php`.

## Resolution algorithm (`Auth::can($permission)`)

1. If the role is `admin`, return `true` (explicit, audited bypass).
2. Flatten the role's default `module.action` set into `$granted`.
3. Merge the user's explicit `user_permissions` rows (cached per request).
4. If `$permission` is an **alias** key, return `true` when **any** of its
   expanded permissions is granted; otherwise check membership directly.

`Auth::requirePermission('module.action')` calls `requireAuth()` first, then
renders `views/errors/403.php` and exits if `can()` is false.

## Backward-compatibility aliases

Older coarse strings map to arrays of granular permissions via `$aliases`, so
existing controller/view checks keep working as the model gets more granular:

```
risk.write       → risk.create, risk.edit, risk.delete, risk.accept,
                   risk.review, risk.treatment, risk.scenarios
compliance.write → compliance.create, compliance.assess, compliance.import,
                   compliance.test, compliance.gap
audit.write      → audit.create, audit.edit, audit.findings, audit.close
policy.write     → policy.create, policy.edit, policy.publish, policy.attest
…  (see $aliases in src/Auth.php for the full table)
```

A `*.write` check passes if the user holds **any** of the expanded permissions.

## Explicit per-user grants

`user_permissions(user_id, module, permission)` rows are merged on top of role
defaults. Use them to grant a single extra capability to an individual without
changing their role (e.g. give one analyst `audit.close`).

## Rules for contributors

- **Every** protected controller method must call `Auth::requireAuth()` or
  `Auth::requirePermission('module.action')` with the **specific** granular string.
- **Every** `Auth::can()` call in a view must use the specific granular string.
- Never rely on hiding a button in the UI as the only access control — the
  controller must re-check.
- When fetching a record by ID, add an **object-level** check (ownership / scope)
  to prevent IDOR, in addition to the `module.action` check.
- The `admin` bypass is intentional and must remain explicit and audited; do not
  scatter ad-hoc `role === 'admin'` checks through controllers — use permissions.

## API permissions

API keys carry a `permissions` scope array (default `["read"]`). JWT-authenticated
API callers act with their user's role. API authorization should map to the same
`module.action` vocabulary as the web app.
