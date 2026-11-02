# ADR-0014: Local username/password accounts alongside Entra ID

- **Status:** Accepted (2026-10-26). **Amends [ADR-0004](ADR-0004-authentication-bff.md)** (Entra ID remains the organisational SSO; it is no longer the only way to sign in)
- **Date:** 2026-10-26

## Context
ADR-0004 and owner decision D18 made Entra ID (OIDC via the BFF) the only sign-in method. That blocks two needs raised by the owner: a hosted demo/evaluation deployment (the Render Blueprint, decision 22) where no Entra tenant is connected, and any installation that must work before, or without, an Entra app registration. It also left no way to sign in at all in a fully disconnected enclave ([AIRGAPPED.md §4.1](../../deployments/AIRGAPPED.md)).

## Decision
PAL supports **local accounts** (username + password) **in addition to** Entra ID. Both kinds of user share one identity table, one role model and one session model.

- **Identity.** `app_user` holds exactly one identity: an Entra `(idp_tenant_id, idp_object_id)` pair **or** a local `username` (`ck_app_user_identity`, migration `Version20261026000003`). Usernames are lowercase, 3–64 characters, `^[a-z0-9][a-z0-9._-]{2,63}$`, unique.
- **Credentials.** `local_credential` stores an **argon2id** hash (`password_hash()` with `PASSWORD_ARGON2ID`, bcrypt fallback only if argon2id is unavailable), `must_change`, `failed_attempts`, `locked_until`. Hashes are upgraded on the next successful sign-in when parameters change.
- **Sign-in.** `POST /auth/password` (JSON) handled by `LocalPasswordAuthenticator` in the same Symfony firewall as the OIDC callback. It requires a login CSRF token from `GET /auth/methods`. On success the session ID is rotated and the authentication time recorded, exactly as for Entra.
- **Roles.** Local users get roles from explicit per-user grants (`user_role`, managed under Administration › Users, `admin.users`). Entra users can hold explicit grants too; `RoleResolver` unions group-mapped and explicit roles. `READER` stays implicit.
- **Bootstrap.** The first local System Administrator + PAL Administrator is created by `pal:users:bootstrap-admin`, which the container's `migrate` step runs, from `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` / `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD`, **only if no local account exists**. Its password is marked temporary.
- **Other providers.** Okta, Google Workspace and SAML 2.0 are listed by `/auth/methods` and shown on the sign-in page as "Not connected". They are **not implemented**; no code path signs anyone in through them.

## Security controls
| Control | Implementation |
|---|---|
| Password policy | 12–128 characters, no composition rules, must not contain the username, small common-password deny list (`PasswordPolicy`, aligned with ASVS 4 V2.1 / NIST SP 800-63B). A breached-password corpus check is **not** implemented |
| Lockout | 5 consecutive failures lock the account for 15 minutes (`LocalAccountService::MAX_FAILED_ATTEMPTS`, `LOCKOUT_MINUTES`); a locked account is refused even with the correct password; audited `USER_LOCKED_OUT` |
| Throttling | Per client IP (`auth_login`, 20/min, shared with `/auth/login` and `/auth/callback`) and per username (`login_username`, 10 per 15 min) |
| Enumeration resistance | Every failure returns the same 401 `invalid_credentials`; unknown usernames still run a password verification against a dummy argon2id hash to equalise timing |
| Temporary passwords | Bootstrap and admin resets set `must_change`. Until the user changes it, every `/api` call except `/api/me`, `/api/me/password`, `/api/session/logout` and `/api/branding` returns 403 `password_change_required` (`PasswordChangeRequiredSubscriber`), independent of the UI guard |
| Login CSRF | Validated **before** the password is verified, so a refused request can never record a successful sign-in |
| Separation of duties | An administrator cannot grant themselves a role with document authority (403 `self_grant_forbidden`) or deactivate themselves (409 `cannot_deactivate_self`). Because creating an account or resetting a password hands the administrator a working password, conferring a document-authority role (`PAL_ADMINISTRATOR`, `PUBLISHER`, `AUTHOR`), or resetting the password of an account that holds one, requires the caller to hold `PAL_ADMINISTRATOR` (403 `role_escalation_forbidden`); a System Administrator alone cannot do it |
| Availability | A change that would leave no active System Administrator is refused and rolled back (409 `last_administrator`). A locked-out administrator can be recovered from the server with `pal:users:unlock <username>` (audited `USER_UNLOCKED`) |
| Audit | `USER_SIGNED_IN` / `USER_SIGN_IN_FAILED` (method `password`), `USER_LOCKED_OUT`, `LOCAL_USER_CREATED`, `USER_PASSWORD_CHANGED`, `USER_PASSWORD_RESET`, `USER_ROLES_CHANGED`, `USER_ACTIVATED` / `USER_DEACTIVATED`, in the same transaction |
| Configuration exposure | `/auth/methods` returns only booleans and labels, never tenant or client IDs |

## Consequences
- **Positive:** demos and evaluations work without an Entra tenant; an installation can be administered before SSO is connected; a disconnected enclave can sign users in (without group sync).
- **Negative:** PAL now holds password hashes and must protect them (database backups contain them). Local accounts get none of the organisation's IdP controls: no MFA, no Conditional Access, no central offboarding. Disabling a person in Entra does not disable their local PAL account; administrators must deactivate it in PAL.
- **Known limitation:** the bootstrap local administrator holds both `SYSTEM_ADMINISTRATOR` and `PAL_ADMINISTRATOR`, so one person can confer document authority until D20 (the second control) is decided. The `role_escalation_forbidden` rule covers the users API; group role mappings (`/api/admin/role-mappings`, `pal:roles:map`) remain governed by the self-grant refusal only.
- **Operational:** bootstrap variables are secrets and must be removed after first sign-in. The temporary passwords shown to administrators (create/reset) are displayed once in the UI and must be transmitted out of band.
- **Follow-up:** MFA for local accounts, a breached-password check, and the Okta/Google/SAML connectors are open items ([OPEN_ITEMS.md](../../OPEN_ITEMS.md)). Production installations with Entra available should keep local accounts to break-glass administrators; whether to allow local accounts in production at all is an owner decision.
