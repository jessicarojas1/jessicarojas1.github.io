# PALADIN — Permissions Model

Authorization in PALADIN is **enforced server-side** on every request. The UI
hides actions a user cannot perform, but the controller is the source of truth —
no action is permitted on the strength of a client-side check alone.

## Layers

Access is the **conjunction** of these layers (all must pass):

1. **Authentication** — a valid session (`Auth::requireAuth`).
2. **Global permission** — a granular `module.action` string checked with
   `Auth::requirePermission('document.publish')` in controllers and
   `Auth::can('document.publish')` in views.
3. **Space scope** — for content in a space, private-space membership is required
   (`SpaceAccess::canView` / `canContribute` / `canManage`).
4. **Object-level access** — for individual objects:
   - pages honour view/edit **restrictions with ancestor inheritance**
     (`PageAccess::canView` / `canEdit`);
   - attachments re-check their parent entity's access
     (`AttachmentController::canAccessParent`).

A global permission is therefore **necessary but not sufficient**: holding
`document.view` does not grant access to a document in a private space the user
is not a member of (this is the anti-IDOR property — see `SECURITY.md` §7).

## Granular permission strings

Permissions are fine-grained `module.action` strings rather than coarse
read/write flags, e.g.:

```
page.view  page.create  page.edit  page.publish  page.delete  page.comment
document.view  document.create  document.edit  document.publish  document.acknowledge
approval.view  approval.approve
space.view  space.edit
report.view  report.export
```

**Backward-compatible aliases** map older coarse strings to arrays of granular
ones (e.g. `document.read → [document.view]`) so legacy checks keep working.

## Roles

Global roles bundle default permissions. The role set includes:

`admin`, `pal_admin`, `compliance_admin`, `space_owner`, `contributor`,
`reviewer`, `approver`, `auditor`, `viewer`
(`Auth::allRoleOptions()` / `Auth::roleLabel()`).

- **Role defaults** are inherited from the role.
- **Explicit grants** are stored per-user and override role defaults.
- `admin` is privileged and bypasses space/object gates (it is never locked out
  of administration). Page owners/creators retain access to their own pages to
  prevent self-lockout (`PageAccess::privileged`).

## Space roles

Within a space, membership carries a space role (`space_members.role`):
manager-class roles can administer membership/settings (`SpaceAccess::canManage`);
contributor-class roles can add/edit content (`SpaceAccess::canContribute`);
others may view. Open (non-private) spaces fall back to the global content
permission.

## Electronic-signature authority

Applying an electronic signature additionally requires the signer to
**re-authenticate** at signing time when `require_esignature` is enabled — see
`SECURITY.md` §8 and `QMS_WORKFLOW.md`.

## Adding or changing permissions

- Add the new `module.action` string and wire it into role defaults.
- Enforce it in **every** controller method that performs the action
  (`Auth::requirePermission`).
- Gate the corresponding UI affordance with `Auth::can` using the **same**
  string.
- If the action touches a specific object, also apply the relevant object-level
  check (space/page/attachment) — do not rely on the global permission alone.
