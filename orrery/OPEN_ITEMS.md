# ORRERY — Open Items / Production-Readiness Register

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

| Item | Impact | Suggested action |
|------|--------|------------------|
| CUI/ITAR/export scope | Drives tenant boundary (GCC High?) & hosting; everything downstream | Cyber + Contracts ruling before Phase 1 |
| Authorized hosting boundary | Render vs Gov cloud/enclave | Confirm ATO/boundary for the data class |
| Customer/COR access allowed? | Whether customer zone ships | Contracts ruling |
| Entra + B2B licensed/approved | Identity design | Enterprise Systems confirm |

## Outstanding — Documentation & deployment set (per repo standard)

| Item | Impact | Suggested action |
|------|--------|------------------|
| `deployments/SINGLE_LINUX_SERVER.md` | Operator coverage | Author after runtime firms up |
| `deployments/KUBERNETES.md` | Enclave/prod path | Author with Helm/manifests |
| `deployments/AZURE.md` (Commercial + Gov) | GCC High path | Author with IaC refs |
| `deployments/AWS.md` (Commercial + GovCloud) | Alt cloud | Author with IaC refs |
| `deployments/AIRGAPPED.md` | Offline + self-hosted LLM (Ollama) | Author for enclave |

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
