# Security Guide — CMMC 2.0 Level 2 Compliance Agent

This app is a compliance *tooling* helper, not a system of record for CUI. It is
designed **local-first** and ships with deliberately minimal security surface. This
guide documents what exists today (honestly, including gaps) and how to harden the app
before exposing it beyond a single trusted user.

> **Read this first:** The app has **no authentication, no authorization, no CSRF
> token, and no audit log** on any endpoint. These are documented boundaries, not
> oversights — but they mean the app must be fronted with an authenticating reverse
> proxy before any multi-user or exposed deployment.

---

## 1. Identity & Authentication

**The app currently has NO authentication on any endpoint.** `GET /`, `POST /api/chat`,
`GET /api/dashboard`, `POST /api/mark`, and `GET/POST /api/settings` are all open.

- This is intentional for **local-first, single-user** operation.
- **Recommendation:** if exposed beyond localhost, front the app with an
  **authenticating reverse proxy** (nginx/oauth2-proxy, Caddy, cloud LB with auth) or
  **SSO** (OIDC/SAML). The app itself performs no identity checks.

---

## 2. Authorization

**No RBAC today.** There are no roles, no per-user scoping, and no permission checks —
any caller who can reach an endpoint can mark controls and change settings.

- **Recommendation (gap):** implement authorization at the reverse proxy, or add
  application-level RBAC if the app grows into a shared, multi-tenant tool.

---

## 3. Data Protection

| Data | Location | Sensitivity |
|------|----------|-------------|
| Control status + notes | `status.json` | Notes may reference **evidence locations** or internal details — treat as **sensitive**. |
| Branding | `settings.json` | Low (appName, logoUrl, accent). |
| `ANTHROPIC_API_KEY` | Platform secret store | **Secret** — the sole secret. |

| Control | Approach |
|---------|----------|
| **Encryption at rest** | Rely on **disk / volume encryption** (LUKS, EBS/KMS, Azure disk encryption). The app does not encrypt the JSON files itself. |
| **Encryption in transit** | **TLS via a reverse proxy.** `server.py` serves plain HTTP (Flask dev server) — terminate TLS in front of it. |
| **Key management** | Keep `ANTHROPIC_API_KEY` in a **secret manager** (Secrets Manager / Key Vault / K8s Secret), injected as an env var. Never commit it; only `.env.example` is committed. |

**Input handling:** the front-end escapes user strings via `textContent` / `escHtml`;
`logoUrl` is sanitized (allow only `http(s)://` or `data:image/...`) on both server and
client; `accent` is length/charset validated.

---

## 4. Auditability

**No audit log today.** The app does not record who marked a control, changed a status,
or edited settings. Flask stdout request logs are the only trail.

- **Recommendation (gap):** capture access logs at the reverse proxy, and/or add an
  application audit log if the tool becomes shared. For CMMC assessment integrity you
  will typically want an immutable record of status changes — plan to add this.

---

## 5. Classification & DLP (CUI Handling)

- The app stores **compliance metadata and notes**, **not CUI itself**. However, notes
  in `status.json` **could contain sensitive information** (e.g. evidence paths, system
  details). **Treat `status.json` as sensitive.**
- **Egress boundary:** with the hosted Anthropic backend, **chat content leaves your
  boundary** (sent to `api.anthropic.com`). Do **not** paste CUI into the chat.
- **For CUI environments,** use the **self-hosted / airgapped path** so no chat content
  egresses: run inference on-prem via Ollama. See
  [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) and the Ollama section of
  [`DEPLOYMENT.md`](DEPLOYMENT.md#6-ollama-configuration-self-hosted-llm).
- Apply DLP at the egress proxy (restrict egress to only the intended AI endpoint, or
  to nothing external when on-prem).

---

## 6. FIPS Readiness

- The app does not implement its own cryptography beyond what the HTTP client and TLS
  layer provide. For FIPS posture:
  - **Terminate TLS at a FIPS-validated reverse proxy** (FIPS 140-2/3 validated module).
  - The **hosted Anthropic API is not FIPS-controlled by this app** — for FIPS/CUI
    workloads use **on-prem inference** (Ollama) so cryptographic boundaries and data
    residency stay under your control.
- Run on a FIPS-mode host/OS and FIPS-validated container base where required.

---

## 7. Operator Responsibilities

- [ ] **Rotate `ANTHROPIC_API_KEY`** on schedule and on suspected exposure.
- [ ] **Protect `status.json`** — file permissions, volume encryption, restricted
      access; treat as sensitive.
- [ ] **Front the app with authentication** (reverse proxy / SSO) before any exposure.
- [ ] **Restrict egress** to only `api.anthropic.com` (hosted) or to nothing external
      (on-prem Ollama).
- [ ] Run the provided **non-root** container; keep FS read-only except the state
      volume.
- [ ] Terminate **TLS** in front of the Flask dev server; never expose it directly.

---

## 8. Secrets Rotation

`ANTHROPIC_API_KEY` is the only secret.

```
1. Issue a new key in the Anthropic console.
2. Update the value in your secret manager (Secrets Manager / Key Vault / K8s Secret).
3. Restart / redeploy the app so python-dotenv / the platform re-injects the new value.
4. Verify: POST /api/chat succeeds (does NOT return 500 {"error":"ANTHROPIC_API_KEY not set"}).
5. Revoke the old key.
```

---

## 9. Reporting

- **Vulnerability disclosure contact:** `security@YOUR-ORG.example` *(placeholder —
  replace with your real security contact / channel).*
- **SLA guidance:** acknowledge reports within **1 business day**; triage and assign
  severity within **3 business days**; remediate critical issues on an expedited basis
  consistent with your organization's vulnerability management policy.
- Please report privately; do not open public issues for security-sensitive findings.

---

## See Also

- [`ARCHITECTURE.md`](ARCHITECTURE.md)
- [`DEPLOYMENT.md`](DEPLOYMENT.md)
- [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)
- Airgapped / on-prem inference: [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md)
