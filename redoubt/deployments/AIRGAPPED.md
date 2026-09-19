# REDOUBT — Air-Gapped Enclave

Operator guide for running REDOUBT in a **fully disconnected (air-gapped) CUI
enclave** with no path to the public internet or to Microsoft 365 / Azure GCC
High. This is materially different from the cloud-connected targets
(`KUBERNETES.md`, `AZURE.md`, `AWS.md`) and changes the identity and document
system-of-record.

> Read first: in an air gap there is **no reachable cloud**. Any design element
> that assumes `login.microsoftonline.us` / `graph.microsoft.us` /
> `*.sharepoint.us` must be replaced with an **in-enclave** equivalent.

## 1. Architecture differences vs the connected targets

| Concern | Connected (GCC High) | Air-gapped (in-enclave) |
|---------|----------------------|--------------------------|
| Identity / SSO | Entra ID GCC High (OIDC) | **On-prem OIDC IdP** — AD FS or Keycloak fronting on-prem Active Directory |
| Documents (SoR) | SharePoint Online GCC High via Graph | **SharePoint Server** on-prem, or an in-enclave S3-compatible store (e.g., MinIO) with portal-managed metadata |
| MFA | Entra + Conditional Access | IdP-enforced MFA (smart card / PIV/CAC, TOTP) |
| AI assistant (Phase 5) | (declined in cloud unless in-boundary) | **Self-hosted LLM via Ollama** in-enclave — no hosted API |
| Updates / feeds | pulled online | **imported via data diode / removable media** |

REDOUBT's app tier is unchanged: the **same container image** runs; only the
identity provider and document-source configuration differ. `Oidc`/`Auth` point at
the on-prem IdP's issuer; the document module targets the on-prem store instead of
Graph. (The connector abstraction keeps this a configuration change, not a fork.)

## 2. Topology

```
        Air-gapped CUI enclave (no internet)
  ┌──────────────────────────────────────────────────────┐
  │ [Ingress TLS] ─> [REDOUBT pods] ─> [PostgreSQL]       │
  │        │                 │                            │
  │        │                 ├─> On-prem OIDC IdP (AD FS/ │
  │        │                 │    Keycloak + AD)          │
  │        │                 ├─> SharePoint Server / MinIO│
  │        │                 └─> Ollama (LLM, Phase 5)    │
  │ [Private registry] [Vault/HSM] [Offline CVE DB]       │
  └───────────────▲──────────────────────────────────────┘
                  │ one-way data diode / sneakernet (images, feeds, patches)
```

## 3. Prerequisites

- Kubernetes (or a hardened host) inside the enclave, FIPS-enabled.
- An in-enclave **private container registry** (e.g., Harbor) holding the REDOUBT
  image and all base images.
- An on-prem **OIDC IdP** (AD FS or Keycloak) integrated with Active Directory.
- On-prem document store: **SharePoint Server** or **MinIO** (S3-compatible).
- In-enclave secret store: **HashiCorp Vault** or an **HSM**.
- Offline vulnerability database (e.g., Trivy DB) for image scanning.

## 4. Identity & credentials

- Configure REDOUBT to use the on-prem IdP's OIDC discovery/JWKS URLs (internal
  hostnames) in place of the GCC High authority.
- Enforce MFA at the IdP (PIV/CAC preferred for DoD).
- Secrets (OIDC client secret, DB creds, MinIO keys) come from Vault/HSM — never
  in images or ConfigMaps.
- The **US-person export gate** still applies: US-person status is sourced from an
  authoritative in-enclave HR/AD attribute and enforced server-side.

## 5. Environment variables (air-gapped overrides)

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | Runtime mode |
| `MS_CLOUD` | `OnPrem` | Signals non-cloud identity/document mode |
| `ENTRA_AUTHORITY_HOST` | `https://adfs.enclave.local` | On-prem OIDC issuer base |
| `ENTRA_TENANT_ID` | `adfs` (or realm) | IdP realm/tenant path |
| `ENTRA_CLIENT_ID` / `ENTRA_CLIENT_SECRET` | (from Vault) | OIDC client |
| `ENTRA_REDIRECT_URI` | `https://portal.enclave.local/auth/callback` | Callback |
| `DOC_SOURCE` | `sharepoint-server` \| `minio` | Document backend selector |
| `DATABASE_URL` | `postgres://…@pg.enclave.local:5432/redoubt` | Portal DB |

> The variable names are reused for portability; in the air gap they point at
> in-enclave hosts, not the `.us` cloud endpoints.

## 6. Offline supply chain (registry, bundles, feeds)

- **Images:** build/pull on a connected staging host, scan, sign (cosign), export
  as an OCI bundle, transfer via the data diode/removable media, import into the
  in-enclave registry. Deploy only signed images.
- **Dependencies:** vendor Composer packages (`composer install --no-dev` on
  staging) and ship `vendor/` inside the image so no online fetch is needed.
- **OS packages:** mirror required apt packages into an internal mirror.
- **CVE feeds:** import the offline vulnerability DB on a schedule via the diode;
  scan images in-enclave before promotion.

## 7. Self-hosted LLM (Ollama) — Phase 5

The permission-aware assistant, if enabled, runs a **local model via Ollama**
inside the enclave (GPU-accelerated where available). No prompt or document ever
leaves the enclave; every retrieval is permission-trimmed by the same Authorize
engine before it reaches the model.

## 8. Verification

- `curl -fsS https://portal.enclave.local/health` → `ok`.
- Sign in via the on-prem IdP (MFA enforced); confirm US-person gating on an
  ITAR/EAR document.
- Confirm no egress: network policy denies all outbound; the app functions fully.
- Confirm images are signed and scanned against the offline CVE DB.

## 9. Day-2 / troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Sign-in fails | Wrong IdP issuer/JWKS | Point `ENTRA_AUTHORITY_HOST` at the on-prem IdP; import its signing certs |
| Documents unavailable | Cloud endpoint still configured | Set `DOC_SOURCE` to the in-enclave store; remove `.us` hosts |
| Image won't deploy | Unsigned/unscanned | Sign (cosign) + scan before promotion |
| Feed/patch stale | Diode import overdue | Re-run the offline import runbook |
