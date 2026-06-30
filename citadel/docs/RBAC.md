# CITADEL — Role-Based Access Control

CITADEL enforces access control **on the backend** (the SPA only hides controls
as a UX convenience). When the deep-scan backend is present, every request is
authenticated with a short-lived JWT and authorized per-route; without a backend
the static SPA falls back to a per-browser local store.

## Enforcement model

- **`enforce` off (default for a fresh/open instance):** `requirePerm()` routes
  are open; admin-only routes (`requireAdmin`) are still protected. A loud
  startup warning fires on a production-looking deploy unless
  `CITADEL_ALLOW_OPEN=1` is set.
- **`enforce` on:** every `requirePerm('<page>')` route requires the caller's
  role/grants to include that page; `requireAdmin` routes require `role==='admin'`.

Authorization is checked in two middlewares in `server/server.js`:

| Middleware | Guards |
|---|---|
| `requireAuth` | a valid, non-revoked access token (set on every request) |
| `requirePerm('<page>')` | the caller has the page permission (or enforce is off) |
| `requireAdmin` | `role === 'admin'` (never bypassed by enforce) |

Project-keyed resources additionally enforce **ownership** (`ownsProject`) so a
non-admin cannot read or modify another user's project data (no IDOR).

## Roles

Defined in `js/auth.js` (`ROLES`). Each role is a set of page permissions; the
permission ids are the `PAGES` list (Analyzer actions + report tabs + admin).

| Role | Label | Can do |
|---|---|---|
| `admin` | Administrator | Everything — all analyzer actions, all report tabs, **user management, permissions, access settings, audit log, sessions, branding** |
| `analyst` | Security Analyst | Run scans (`analyze`), deep scan / scan-by-URL (`deepscan`), all report tabs, docs. **No** admin surfaces |
| `auditor` | Auditor | Read-only: Report, Findings, Compliance, SBOM, Export, History, docs. **No** scan execution, **no** admin |
| `viewer` | Viewer | Read-only: Report, Findings, Compliance, Export, docs |

### Permission ids (`PAGES`)

- **Analyzer:** `analyze`, `deepscan`
- **Reports:** `tab-report`, `tab-overview`, `tab-findings`, `tab-compliance`,
  `tab-sbom`, `tab-binary`, `tab-quality`, `tab-deploy`, `tab-aifix`,
  `tab-history`, `tab-export`
- **Docs:** `docs`
- **Admin:** `admin-users`, `admin-perms`, `admin-settings`

Admins can grant individual pages per user (a checkbox matrix in the admin
console), so a user can be given a custom subset beyond their base role.

## SSO / OIDC role mapping

When OIDC is configured, users are provisioned just-in-time on first login:

- `OIDC_ADMIN_EMAILS` — comma-separated emails that map to the `admin` role.
- `OIDC_DEFAULT_ROLE` — role assigned to everyone else (default `viewer`).
- `OIDC_ALLOWED_DOMAINS` — restrict sign-in to these email domains.

## Adding a role or permission

1. Add the permission id to `PAGES` in `js/auth.js` (and a label/group).
2. Add it to the relevant role(s) in `ROLES`.
3. Guard the corresponding route(s) with `requirePerm('<id>')` (or
   `requireAdmin` for admin-only) in `server/server.js`.
4. Gate the UI control with `CITADEL.auth.can('<id>')` in the view.

Never rely on the UI gate alone — the backend check is authoritative.
