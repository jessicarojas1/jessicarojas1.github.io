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

**Decided:** CUI and ITAR/export-controlled data are **in scope**. **Hosting is
hybrid** — the app is **self-hosted on-prem** in GMRE's CUI boundary; identity +
documents are in **Microsoft 365 / Azure GCC High**. The blockers below flow from
that.

| Item | Impact | Suggested action |
|------|--------|------------------|
| On-prem app-tier hardening & ATO | Customer-owned CUI boundary must meet 800-171/CMMC (physical, network, FIPS, boundary) | Cyber + Enterprise Systems assess before go-live |
| Export-control gate definition | Authoritative US-person source + which zones are ITAR/EAR + license scoping | Cyber + Export/Empowered Official |
| GCC High tenant + Entra app registration | Document SoR + identity for controlled data | Enterprise Systems provision tenant + app reg |
| Network path on-prem → GCC High | Graph/auth connectivity (ExpressRoute vs Gov internet egress) | Network/Cyber decide + allowlist `.us` endpoints |
| Customer/COR access allowed? | Whether customer zone ships | Contracts ruling |
| Entra + B2B licensed/approved (GCC High) | External identity design | Enterprise Systems confirm |

## Outstanding — Documentation & deployment set (per repo standard)

| Item | Impact | Suggested action |
|------|--------|------------------|
| `deployments/SINGLE_LINUX_SERVER.md` | On-prem production path | **Done** (this drop) |
| `deployments/KUBERNETES.md` | On-prem production, HA | **Done** (this drop) |
| `deployments/AZURE.md` (M365 GCC High config) | Cloud SoR + identity | **Done** (this drop) |
| `deployments/AIRGAPPED.md` | Fully offline enclave + self-hosted LLM (Ollama) | Tracked — author if a disconnected variant is needed |
| `deployments/AWS.md` | Not applicable — hosting is on-prem + Azure GCC High | Dropped unless requirements change |

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
