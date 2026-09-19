# REDOUBT — Open Items / Production-Readiness Register

Honest status as of 2026-09-18. This is a **discovery-phase** deliverable; the
list below separates what is **done** from what is **outstanding**, grouped by
theme, each with impact + suggested action.

## Done (this drop)

- Product & Architecture Discovery Package (served at `/`).
- Deployable PHP scaffold: front controller, strict security headers + CSP nonce, `/health`.
- Docker (multi-stage, non-root, healthcheck) + Render blueprint (non-CUI).
- Initial idempotent `database/schema.sql` (design; pending decisions).
- Core docs: Architecture, Deployment, Disaster Recovery, Security; `deployments/LOCAL_DEVELOPMENT.md`.

## Blocking decisions (must resolve before build)

**Decided:** CUI and ITAR/export-controlled data are **in scope**. **Runtime is
Kubernetes**, portable across three authorized boundaries — **on-prem**, **Azure
Government (AKS)**, and **AWS GovCloud (EKS)** — with identity + documents always
in **Microsoft 365 / Azure GCC High**. **Customer/COR access is contractually
authorized** (Customer/COR library is in MVP). **Export-control gates are a build
requirement.** The blockers below flow from these.

| Item | Impact | Suggested action |
|------|--------|------------------|
| Which boundary(s) first + app-tier ATO | Each of on-prem/Azure Gov/AWS GovCloud must meet 800-171/CMMC (network, FIPS, boundary) | Cyber + Enterprise Systems select + assess before go-live |
| Export-control gate definition | Authoritative US-person source + which zones are ITAR/EAR + license scoping | Cyber + Export/Empowered Official |
| GCC High tenant + Entra app registration | Document SoR + identity for controlled data | Enterprise Systems provision tenant + app reg |
| Egress path app → GCC High | Graph/auth connectivity per target (ExpressRoute / Gov egress / cross-cloud) | Network/Cyber decide + allowlist `.us` endpoints |
| COR onboarding terms | Flow-downs + customer-approved document set | Contracts confirm terms |
| Entra + B2B licensed/approved (GCC High) | External identity design | Enterprise Systems confirm |

## Outstanding — Documentation & deployment set (per repo standard)

| Item | Impact | Suggested action |
|------|--------|------------------|
| `deployments/KUBERNETES.md` | Primary runtime (on-prem / AKS / EKS) | **Done** |
| `deployments/AZURE.md` | M365 GCC High back end (all targets) + AKS hosting | **Done** |
| `deployments/AWS.md` | AWS GovCloud (EKS) hosting → M365 GCC High | **Done** |
| `deployments/SINGLE_LINUX_SERVER.md` | Fallback / small footprint | **Done** |
| `deployments/AIRGAPPED.md` | Fully offline enclave + self-hosted LLM (Ollama) | Tracked — author if a disconnected variant is needed |

## Outstanding — Application (Phase 1 / MVP)

| Item | Impact | Suggested action |
|------|--------|------------------|
| Entra OIDC SSO + MFA | No auth yet | Implement `app/Support/Auth` + app registration |
| AuthZ policy engine (program×company×role×zone) | Core security control | Implement + negative tests |
| Microsoft Graph client (SharePoint docs) | Document module | Implement with app-only + delegated flows |
| Content Administration console | The critical business requirement | Build after RBAC |
| Announcements / Task Orders / Jobs / Directory / Search | MVP modules | Per §20 of the package |
| Audit logging (append-only) | Compliance | Implement early, log everything |
| CSRF + input validation across writes | Security baseline | Mirror AEGIS `Security::` conventions |
| CI/CD, dev/test/prod, secrets management | Ops | DevSecOps |

## Notes / known limitations

- Discovery microsite loads Mermaid from a CDN for two diagrams; it degrades to
  showing diagram source if blocked (air-gap friendly). Phase 1 should vendor or
  pre-render diagrams for a fully self-contained enclave build.
- `render.yaml` is scoped to a non-CUI pilot boundary by design.
