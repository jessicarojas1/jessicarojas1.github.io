# Administrator Guide

This guide covers administration of Sentinel QMS: user and role management, system configuration, record
numbering, data retention, and backups. Administrative functions require the **Admin** role; the Admin
role is intentionally separated from the Quality Manager (which holds all *quality* permissions but **not**
`user:manage`), supporting separation of duties.

> All administrative actions are audited. Where an action changes a controlled record's state, an
> electronic signature may be captured.

---

## 1. User Management

### 1.1 Creating users
- Create a user with email and display name. Set the **authentication source**:
  - `local` — password login (bcrypt-hashed); enforce complexity and rotation.
  - `oidc` / `saml` — federated SSO via the enterprise IdP.
  - `cac_piv` — DoD CAC/PIV smartcard.
- For ITAR/export-controlled deployments, set the **U.S.-person / export-eligibility attribute**; access
  to ITAR-flagged data requires it.

### 1.2 Lifecycle
| Event | Action |
|-------|--------|
| Onboard | Create account, assign role(s), assign org/tenant scope |
| Role change | Update role assignment (effective immediately) |
| Leave/transfer | Deactivate account; tokens are revoked (`jti`); CAC/PIV revoked at IdP |
| Offboard | Deactivate (retain for audit); do not delete records authored by the user |

### 1.3 Access reviews
Run a **quarterly access review**: confirm each account's role is still appropriate, deactivate stale
accounts, and verify separation of duties (no single account both authors and approves where prohibited).
The audit log and RBAC matrix support this review.

---

## 2. Role Management

The seven roles and their permissions are defined centrally (see the
[RBAC matrix](../architecture/security-architecture.md#32-rbac-permission-matrix)):

- **Admin** — all permissions (user/role management, configuration).
- **Quality Manager** — all quality permissions except user management.
- **Quality Engineer** — write/disposition/close across most quality modules.
- **Auditor** — read across modules + audit write.
- **Supplier Quality** — supplier write + scoped NCR/CAPA; external suppliers data-scoped to themselves.
- **Operator** — NCR and inspection write.
- **Read-Only** — read access only.

Assign the **least-privileged** role that lets a user do their job. A user may hold multiple roles; the
effective permission set is the union.

---

## 3. System Configuration

| Area | What to configure |
|------|-------------------|
| Organization / tenant | Name, CUI/ITAR flags (drive RLS scope and export gating) |
| Identity / SSO | `OIDC_ISSUER`, client id/secret; SAML metadata; CAC/PIV trust |
| Storage | `STORAGE_BACKEND` (`s3`/`azure_blob`), bucket/container, region (keep in-region) |
| CORS | `CORS_ORIGINS` set to the production SPA origin(s) |
| Uploads | `MAX_UPLOAD_BYTES`; MIME/extension allowlist |
| Notifications | Email relay (SES Gov / Azure Communication Services) |
| Token policy | Access/refresh lifetimes; signing algorithm/key |

All settings are environment-driven; see [../deployment/configuration-reference.md](../deployment/configuration-reference.md).
Secrets come from Secrets Manager / Key Vault.

---

## 4. Record Numbering

Controlled records use the format `<PREFIX>-<YYYY>-<sequence>` (e.g., `NCR-2026-000147`). Administrators
can:

- Configure the **prefix** per record type (defaults in
  [../architecture/data-model.md](../architecture/data-model.md#5-record-numbering-scheme)).
- Choose **calendar vs. fiscal-year** rollover.
- Set **sequence padding** width.

Sequences are allocated per organization and per type via a transactional counter with row locking,
guaranteeing **gap-free, unique** numbers under concurrency. Numbers, once issued, are immutable.

---

## 5. Data Retention

| Data | Default retention | Notes |
|------|-------------------|-------|
| Quality records (NCR, CAPA, FAI, audits, etc.) | Life-of-program + organizational policy | Often years post-program for aerospace/DoD |
| Audit log | ≥ 1 year (regulatory minimum); typically longer | Never purged below the minimum |
| Documents/revisions | Retained through obsolescence + policy window | Superseded revisions kept |
| Attachments | Tied to their parent record's retention | Object versioning enabled |
| Incident logs/media | ≥ 90 days from report (DFARS) | For forensics |

Configure retention windows per organizational and contractual requirements. A scheduled **retention
sweep** applies disposition only to records past their window and never to data still under regulatory or
contractual hold. Retention actions are audited.

---

## 6. Backups & Recovery (Admin View)

Day-to-day backup/DR is operated per the [operations runbook](../deployment/operations-runbook.md).
Administrator responsibilities:

- Confirm automated DB snapshots + PITR and object versioning are enabled.
- Take a **manual snapshot before** any configuration migration or bulk change.
- Participate in the **annual DR exercise** and quarterly **test restores**.
- Validate that backups remain **in-region** (residency) and **CMK-encrypted**.

---

## 7. Security Administration

| Task | Guidance |
|------|----------|
| Secrets rotation | Rotate DB/JWT/OIDC secrets via Secrets Manager / Key Vault; note that rotating the JWT key forces global re-login |
| Key management | Ensure KMS/Key Vault CMKs have rotation enabled and scoped policies |
| Audit-log integrity | Verify append-only protections (UPDATE/DELETE blocked) during reviews |
| Federation health | Test SSO/CAC-PIV login after IdP changes |
| Monitoring | Confirm alerts route to on-call; review auth-failure and audit-pipeline alerts |

---

## 8. Bootstrap & First-Run

1. On first deploy with `ADMIN_AUTO_CREATE=true`, a single admin is created from `ADMIN_EMAIL` /
   `ADMIN_PASSWORD`.
2. Log in, **rotate the admin password**, and create real administrator and quality accounts.
3. Set `ADMIN_AUTO_CREATE=false` and redeploy so no bootstrap admin is recreated.
4. Configure org/tenant, SSO, storage, numbering, and retention.
5. Seed reference data (roles, KPI definitions) — idempotent.

---

## 9. Administrator Checklist

- ☐ Real admin/quality accounts created; bootstrap admin rotated
- ☐ `ADMIN_AUTO_CREATE=false`
- ☐ SSO/CAC-PIV federation tested; MFA enforced
- ☐ Roles assigned least-privilege; separation of duties verified
- ☐ Numbering prefixes/rollover configured
- ☐ Retention windows set to policy/contract
- ☐ Storage region in-boundary; CMK encryption on
- ☐ Backups/PITR/versioning confirmed; test restore scheduled
- ☐ Monitoring/alerts confirmed
- ☐ Quarterly access review scheduled
