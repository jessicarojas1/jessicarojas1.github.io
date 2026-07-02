# Security — Business Insight Dashboard

> Canonical security guide. Honest about what the app does and does **not** provide.
> Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)
> Operator guides: [../deployments/](../deployments/)

---

## 1. Identity & Authentication

**There is no authentication built into the application.** Streamlit ships none, and this app adds none. Anyone who can reach the port can use it.

**You must front the app with reverse-proxy authentication before exposing it beyond a trusted network.** Concrete options:

| Environment | Recommended fronting auth |
|-------------|---------------------------|
| Single Linux server / self-managed | **nginx + [oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/)** against your IdP (Google/Okta/Entra ID) |
| AWS | **ALB with OIDC** or **Cognito** authentication action in front of the target group |
| Azure | **App Service Easy Auth** (Entra ID) or **Application Gateway** + Entra ID |
| Kubernetes | Ingress-level oauth2-proxy / IdP integration (e.g. `nginx-ingress` auth-url annotations) |
| Enterprise | Corporate **SSO** gateway |

The auth proxy must also handle **WebSocket upgrade** and **session affinity** (Streamlit requirement) — otherwise authenticated sessions break. See [DEPLOYMENT.md](DEPLOYMENT.md) §9.2.

---

## 2. Authorization

**None / all-or-nothing.** The app has no roles, permissions, or per-feature access control. Authorization is whatever the fronting proxy enforces: a user who passes proxy auth gets the full app. If you need tiered access (e.g. some users may not view certain data), enforce it at the proxy (group-based routing) or run separate instances behind separate auth policies.

---

## 3. Data Protection

This is the app's strongest security property.

- **Uploaded data stays in memory.** The CSV is parsed and analyzed with pandas entirely in server memory. It is **never written to disk and never transmitted** anywhere. The footer states: *"Data processed locally — nothing is stored or transmitted."* When the session ends, the data is gone.
- **In transit:** TLS terminates at the reverse proxy/LB. Always require HTTPS to clients; never expose the raw `:8501` port publicly.
- **At rest:** the only file at rest is `branding.json` (`{logo, name, accent}`) — no business data, no secrets. Protect it with normal filesystem/volume controls.

| Data | State | Protection |
|------|-------|-----------|
| Uploaded CSV | In memory only | Never persisted/transmitted; TLS to proxy; memory scope = session |
| `branding.json` | On disk | Volume/FS permissions; no sensitive content |
| Secrets | Proxy only | Platform secret manager; not in the app |

---

## 4. Input Sanitization

| Input | Control |
|-------|---------|
| Logo URL (`branding.py`) | **Allowlist**: only `http(s)://` or `data:image/...` accepted; anything else rejected |
| Accent color (`branding.py`) | **Hex validation**: must match `#RGB` or `#RRGGBB` before use as a CSS custom property |
| Display name (`branding.py`) | **HTML-escaped** wherever injected into markup |
| Uploaded logo file | Stored inline as a base64 `data:` URL (still allowlist-checked) |
| CSV upload | **Max upload size** cap (`STREAMLIT_SERVER_MAX_UPLOAD_SIZE`) bounds memory/DoS; malformed CSV fails soft via `st.error` |
| Forms / uploads | **Streamlit XSRF protection** enabled (`server.enableXsrfProtection`) |

A broken/malicious logo URL degrades to the default mark rather than executing or erroring.

---

## 5. Auditability

**The application produces no audit log.** Rely on the **reverse proxy / platform access logs** for who-accessed-what:

- Proxy auth logs (oauth2-proxy, ALB access logs, App Service logs) record authenticated identity + request.
- Streamlit stdout/stderr (container logs) record application-level events and errors.
- There is no per-user in-app activity trail because there is no in-app identity.

If audit requirements are strict, capture and retain proxy access logs in a tamper-evident store (e.g. CloudWatch/Log Analytics with retention + immutability).

---

## 6. Classification & DLP

Uploaded CSVs **may contain sensitive, proprietary, or CUI business data**. Because that data is **ephemeral** — processed in memory and never written to disk or transmitted — the classification boundary is effectively the **transport and process memory**, not storage.

Guidance:
- Treat the deployment as handling the **highest classification** of any CSV a user might upload.
- Place the app on a **private network** and **require authentication** (§1) for any non-public data.
- Enforce **TLS** end-to-end; the data's only exposure is in flight to the server and within process memory.
- No server-side DLP scanning is needed for data-at-rest (there is none), but network egress controls and auth are the real controls here.

---

## 7. FIPS Readiness

**The application performs no cryptography of its own** — it does no hashing, signing, or encryption. FIPS posture is therefore inherited from the platform:

- Rely on the platform/OpenSSL **FIPS-validated** modules for TLS at the proxy/LB.
- In GovCloud/Government targets, use **FIPS regional endpoints** and the correct partition (`aws-us-gov`) / Azure Government endpoints — see [../deployments/AWS.md](../deployments/AWS.md) and [../deployments/AZURE.md](../deployments/AZURE.md).
- No application changes are required for FIPS; the app has no crypto surface to validate.

---

## 8. Operator Responsibilities

- [ ] Never expose `:8501` directly — always front with TLS + auth (§1).
- [ ] Keep **XSRF protection** enabled and **max upload size** capped (§4).
- [ ] Run the container **non-root** with a **read-only filesystem** except the `branding.json` path (see [DEPLOYMENT.md](DEPLOYMENT.md) §9.3).
- [ ] Retain proxy/platform **access logs** for auditability (§5).
- [ ] Keep dependencies patched (`streamlit`, `pandas`, `plotly`, `numpy`) and rebuild the image on CVE advisories.
- [ ] Protect and back up `branding.json` (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).

---

## 9. Secrets Rotation

The app holds **no secrets**. The only secret in the system is the **reverse-proxy OIDC/OAuth client secret** (§1):

- Store it in the platform secret manager (AWS Secrets Manager / Azure Key Vault / Kubernetes Secret / Render env group).
- Rotate on your IdP's schedule (e.g. every 90 days) and on any suspected compromise.
- Prefer **workload identity / managed identity / IRSA** over static credentials wherever the platform supports it, to minimize long-lived secrets.

---

## 10. Reporting

Report suspected vulnerabilities to the security contact:

- **Contact:** `security@<your-org>` *(placeholder — replace with your organization's security disclosure address)*
- **Target acknowledgement SLA:** within **2 business days**.
- **Target triage/assessment SLA:** within **5 business days** of acknowledgement.

Please include reproduction steps, affected version/commit, and impact. Do not file sensitive disclosures in public issue trackers.
