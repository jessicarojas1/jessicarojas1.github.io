# Permissions Model

How authorization works in Sentinel QMS: roles, permission levels, the three
composition layers (page-level, granular action, per-record), and how role
defaults combine with explicit per-user grants and denies.

> Authoritative implementation: `backend/app/core/rbac.py`,
> `backend/app/core/pages.py`, `backend/app/core/iam.py`,
> `backend/app/core/permissions.py`, `backend/app/core/entity_access.py`,
> enforced via dependencies in `backend/app/api/deps.py`.

---

## 1. Roles

Eight built-in roles (`app/core/rbac.py` `Role`):

| Role | Intent |
|------|--------|
| **Admin** | Full system access, including user/role/permission administration. |
| **Quality Manager** | All quality modules; everything except user management. |
| **Quality Engineer** | Read all modules; create/edit/disposition on quality modules; approve documents. |
| **Auditor** | Read all modules; conduct/close audits; read the audit trail. |
| **Supplier Quality** | Read all; manage suppliers (ASL/SCAR/ratings); write NCRs & inspections. |
| **Operator** | Read core modules; create/edit NCRs and inspections; record results. |
| **Read-Only** | Read-only visibility across all modules (`*.view` only). |
| **Customer** | **No standing module access.** Sees only records explicitly shared with them. |

A user may hold multiple roles; effective access is the union of their roles plus
any explicit per-user grants, minus explicit denies.

## 2. Permission levels

Page-level access uses an ordered ladder (`app/core/permissions.py`):

| Level | Rank | Grants |
|-------|------|--------|
| `none` | 0 | No access. |
| `view` | 1 | Read-only. |
| `edit` | 2 | Read + create/update + state transitions. |

`level_at_least(actual, required)` compares ranks, so an `edit` user always
satisfies a `view` requirement.

## 3. Three composition layers

Authorization is enforced in layers; a request must pass **every** applicable
layer.

### 3.1 Page-level (coarse)
`require_page(page_key, level)` resolves the user's effective level for a page.
Pages and their default read/write permissions live in `app/core/pages.py`
(`PAGES`, `PAGE_DEFAULT_PERMS`). Resolution order (`effective_levels`):

1. Start from the role-derived level (role defaults in `ROLE_PERMISSIONS`).
2. Apply DB overrides: `RolePagePermission` (per-role) then `UserPagePermission`
   (per-user) — a user-specific level **replaces** the role-derived level.

### 3.2 Granular action (fine)
`require_perm("<module>.<action>")` enforces a specific action permission
(e.g. `nonconformances.disposition`, `capa.close`, `suppliers.scar`,
`changes.approve`). Effective granular permissions (`app/core/iam.py`,
`has_permission`) are:

```
(role default permissions  ∪  explicit UserPermissionGrant rows)  −  explicit deny rows
```

Admins always pass granular checks. This is an **additive** layer over the
page-level baseline.

### 3.3 Per-record (entity)
`require_entity_view` (`app/core/entity_access.py`) gates record-satellite data —
attachments, comments, e-signatures, audit-trail reads — by mapping the
`entity_type` to its owning page and requiring the user can view that page.
Unmapped entity types **fail closed**.

## 4. Role defaults vs. explicit grants

| Concept | Stored where | Behavior |
|---------|--------------|----------|
| Role defaults | Code (`ROLE_PERMISSIONS`) | Inherited by everyone holding the role. |
| Per-role page override | `RolePagePermission` | Adjusts a role's level for a page without code changes. |
| Per-user page override | `UserPagePermission` | Replaces the resolved level for one user. |
| Per-user granular grant | `UserPermissionGrant` (`deny=false`) | Adds a specific action to one user. |
| Per-user granular deny | `UserPermissionGrant` (`deny=true`) | Removes a specific action from one user, even if a role grants it. |

Denies always win, so an explicit deny is the authoritative way to revoke a
single capability from a user without changing their roles.

## 5. The Customer role

`Customer` is deliberately empty: `ROLE_PERMISSIONS[Role.CUSTOMER]` and its
granular defaults are both `set()`, and `default_level_for(Role.CUSTOMER, …)`
always returns `none`. Customers therefore have **no module access at all**.
They can only see individual records deliberately shared with them through the
"Shared with Me" record-share inbox, which delegates the *sharer's* read access
to one specific record (see `app/api/routers/record_shares.py`). This is what
keeps internal/program data invisible to external customer accounts.

## 6. Programmatic access (API tokens)

A Personal Access Token (see [api-reference.md](api-reference.md) §1.3) **acts as
its owning user** — every layer above still applies — and additionally carries a
coarse `read` / `write` scope enforced by HTTP method. A token can never exceed
its owner's permissions, and a read-only token cannot perform any mutation.

## 7. Where each layer is enforced

| Layer | Dependency | Module |
|-------|-----------|--------|
| Page-level | `require_page` | `app/api/deps.py` |
| Granular | `require_perm` | `app/api/deps.py` → `app/core/iam.py` |
| Per-record | `require_entity_view` | `app/core/entity_access.py` |
| Token scope | `resolve_current_user` | `app/api/deps.py` |

Authorization is enforced **server-side on every protected route** — the SPA only
hides controls it knows the user cannot use; it is never the security boundary.
