# PAL — Open Items

Honest readiness register. **Nothing is implemented. Phase 0 (architecture) is in draft.**

## Decisions (blocking Phase 1)
| Item | Impact | Action |
|---|---|---|
| D0 build vs. SharePoint | Determines whether PAL is built at all | Owner confirms that integrity controls are audit requirements |
| D1 team language | Backend framework (ADR-0002) | Owner names the maintaining team and its skills |
| D2 first target | Deployment design, cost | Owner picks on-prem / Azure Commercial / Azure Gov |
| D3 authoring model | Large scope swing | Owner confirms upload-only for v1 |
| D5 visibility | Search and authorization design | Owner confirms all-employee vs. restricted audiences |
| D10 RPO/RTO/performance | Backup, HA, cost | Owner confirms targets |
| D12 repo visibility | Confidentiality | Decide public portfolio vs. private repo |

## Standard doc & deployment set (deferred to Phase 1)
| Item | Impact | Action |
|---|---|---|
| `deployments/` ×6, `docs/` ARCHITECTURE/DEPLOYMENT/DISASTER_RECOVERY/SECURITY | Required by repo standard | Write in Phase 1 against real code |
| `Dockerfile`, `render.yaml`, `database/schema.sql` | Required by repo standard | Phase 1 |

## Risks
| Risk | Impact | Action |
|---|---|---|
| Quasar accessibility gaps | WCAG 2.2 AA at risk | Phase 1 a11y spike (ADR-0003) |
| Migration of existing documents unscoped | Day-one adoption | Inventory current sources and volume |
| Audit tamper-evidence is not tamper-proof against a DBA | Auditor expectations | External anchoring; state the limitation openly |
| Azure cost figures are estimates | Budget | Validate in the Azure Pricing Calculator |
